<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AiCreditReservation;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\TokenUsageEvent;
use App\Models\User;
use App\Services\Billing\TokenMeter;
use App\Services\GabayMoAi\ServiceSignature as Sig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The ws <-> ai bridge: signing, permissions, credit reservations and internal endpoints. */
class GabayMoAiBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const AI_KEY = 'ws-to-ai-secret-for-tests-0123456789';

    private const WS_KEY = 'ai-to-ws-secret-for-tests-0123456789';

    protected function beforeRefreshingDatabase()
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'session.driver' => 'array']);
        DB::purge();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gabaymo_ai.url' => 'http://ai.test',
            'services.gabaymo_ai.internal_key' => self::AI_KEY,
            'services.gabaymo_ai.inbound_keys' => [self::WS_KEY],
            'services.gabaymo_ai.retries' => 0,
            'cache.default' => 'array',
        ]);
    }

    private function member(int $allowance = 100_000, int $used = 0): User
    {
        $user = User::query()->forceCreate(['name' => 'Ana Cruz', 'email' => 'ana'.uniqid().'@test.ph', 'password' => 'secret123', 'account_type' => 'customer', 'region' => 'National Capital Region (NCR)']);
        $user->forceFill(['token_allowance' => $allowance, 'tokens_used' => $used])->save();

        return $user;
    }

    private function admin(array $permissions, bool $system = false): AdminUser
    {
        $role = Role::query()->create(['name' => 'R'.uniqid(), 'slug' => 'r'.uniqid(), 'is_system' => $system, 'permissions' => $permissions]);

        return AdminUser::query()->forceCreate(['name' => 'Staff', 'email' => 's'.uniqid().'@test.ph', 'role_id' => $role->id, 'password' => 'secret123']);
    }

    private function verifySigned(HttpRequest $request): array
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $canonical = Sig::canonical($request->method(), $path, $query, $request->header(Sig::H_SERVICE)[0], $request->header(Sig::H_AUDIENCE)[0], $request->header(Sig::H_TIMESTAMP)[0], $request->header(Sig::H_NONCE)[0], hash('sha256', $request->body()), $request->header(Sig::H_CONTEXT)[0]);
        $this->assertTrue(hash_equals(Sig::sign($canonical, self::AI_KEY), $request->header(Sig::H_SIGNATURE)[0]), 'Request must be signed with the ws->ai key.');

        return Sig::decodeContext($request->header(Sig::H_CONTEXT)[0]);
    }

    public function test_member_ask_holds_part_of_the_allowance_and_sends_trusted_context(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => ['id' => '9b2f7a3e-1111-4c4c-8888-000000000001', 'answer_state' => 'in_review']], 202)]);
        config(['services.usage.reserve_tokens' => 15_000]);
        $user = $this->member(100_000);

        $this->actingAs($user, 'web')->postJson('/api/v1/ai/ask', ['message' => 'Can MOOE pay for internet?', 'region' => 'Region VIII', 'partner_id' => 7], ['Idempotency-Key' => 'ask-key-0001'])
            ->assertStatus(202)
            ->assertJsonPath('usage.percent_used', 0)
            ->assertJsonPath('usage.percent_in_progress', 15)
            ->assertJsonMissingPath('usage.raw');

        Http::assertSent(function (HttpRequest $request) use ($user) {
            $context = $this->verifySigned($request);
            $this->assertSame('member', $context['actor_type']);
            $this->assertSame($user->id, $context['user_id']);
            $this->assertSame('National Capital Region (NCR)', $context['geo']['region_label'], 'Region comes from the account, not the request.');
            $this->assertArrayNotHasKey('partner_id', $context);
            $this->assertStringStartsWith('res_', $context['credit_reservation_id']);
            $this->assertSame(['message' => 'Can MOOE pay for internet?'], $request->data());

            return true;
        });
        $this->assertSame('reserved', AiCreditReservation::query()->value('status'));

        // A retry with the same key reuses the reservation (no second charge).
        $this->actingAs($user, 'web')->postJson('/api/v1/ai/ask', ['message' => 'Can MOOE pay for internet?'], ['Idempotency-Key' => 'ask-key-0001'])->assertStatus(202);
        $this->assertSame(15_000, $user->fresh()->tokens_reserved);
        $this->assertSame(1, AiCreditReservation::query()->count());
    }

    public function test_member_at_100_percent_is_refused_before_calling_ai(): void
    {
        Http::fake();
        $this->actingAs($this->member(100_000, 100_000), 'web')->postJson('/api/v1/ai/ask', ['message' => 'Can MOOE pay for internet?'])
            ->assertStatus(402)->assertJsonPath('error', 'usage_limit_reached')->assertJsonPath('usage.percent_used', 100)->assertJsonPath('usage.status', 'exhausted');
        Http::assertNothingSent();
    }

    public function test_hold_is_released_when_ai_is_unavailable(): void
    {
        Http::fake(['ai.test/*' => Http::response(['message' => 'down'], 503)]);
        $user = $this->member(100_000, 30_000);
        $this->actingAs($user, 'web')->postJson('/api/v1/ai/ask', ['message' => 'Can MOOE pay for internet?'])->assertStatus(503);
        $this->assertSame(30_000, $user->fresh()->tokens_used);
        $this->assertSame(0, $user->fresh()->tokens_reserved);
        $this->assertSame('released', AiCreditReservation::query()->value('status'));
    }

    public function test_member_memory_routes_are_forwarded_for_the_signed_in_member_only(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => ['enabled' => true, 'items' => []]], 200)]);
        $user = $this->member();

        $this->actingAs($user, 'web')->getJson('/api/v1/ai/memory')->assertOk()->assertJsonPath('data.enabled', true);
        $this->actingAs($user, 'web')->putJson('/api/v1/ai/memory/settings', ['enabled' => false, 'user_id' => 999])->assertOk();
        $this->actingAs($user, 'web')->deleteJson('/api/v1/ai/memory/9b2f7a3e-1111-4c4c-8888-000000000001')->assertOk();
        $this->actingAs($user, 'web')->deleteJson('/api/v1/ai/memory')->assertOk();
        $this->actingAs($user, 'web')->deleteJson('/api/v1/ai/memory/not-a-uuid')->assertStatus(405);

        $sent = Http::recorded()->map(fn ($pair) => [$pair[0]->method(), parse_url($pair[0]->url(), PHP_URL_PATH), $pair[0]->body()])->all();
        $this->assertSame([
            ['GET', '/api/v1/ai/memory', ''],
            ['PUT', '/api/v1/ai/memory/settings', '{"enabled":false}'],
            ['DELETE', '/api/v1/ai/memory/9b2f7a3e-1111-4c4c-8888-000000000001', ''],
            ['DELETE', '/api/v1/ai/memory', ''],
        ], $sent);
        Http::assertSent(fn (HttpRequest $r) => $r->method() !== 'GET' && $r->hasHeader('Idempotency-Key'));
    }

    public function test_ai_401_never_logs_the_browser_out(): void
    {
        Http::fake(['ai.test/*' => Http::response(['error' => 'invalid_signature'], 401)]);
        $this->actingAs($this->member(), 'web')->getJson('/api/v1/ai/conversations')->assertStatus(502)->assertJsonPath('error', 'ai_service_auth');
    }

    public function test_admin_scopes_follow_role_permissions(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => []], 200)]);
        $searchOnly = $this->admin(['knowledge-search']);

        $this->actingAs($searchOnly, 'admin')->getJson('/api/v1/admin/ai/knowledge/search-history')->assertOk();
        $this->actingAs($searchOnly, 'admin')->postJson('/api/v1/admin/ai/knowledge/reviews/9b2f7a3e-1111-4c4c-8888-000000000001/approve', [])->assertForbidden();
        $this->actingAs($searchOnly, 'admin')->getJson('/api/v1/admin/ai/not-a-route')->assertNotFound();

        Http::assertSent(function (HttpRequest $request) {
            $context = $this->verifySigned($request);
            $this->assertContains('knowledge.search', $context['scopes']);
            $this->assertNotContains('knowledge.review', $context['scopes']);
            $this->assertNotContains('knowledge.self_approval_exception', $context['scopes']);

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/api/v1/knowledge/search-history');
        });
        Http::assertSentCount(1);
    }

    public function test_partner_context_comes_from_session(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => ['id' => 1]], 201)]);
        $partner = User::query()->forceCreate(['name' => 'Atty. Reyes', 'email' => 'p'.uniqid().'@test.ph', 'password' => 'secret123', 'account_type' => 'partner', 'partner_status' => 'active']);

        $this->actingAs($partner, 'web')->postJson('/api/v1/partner/ai/instructions', ['scope' => 'global', 'partner_id' => 999, 'is_mandatory' => true, 'title' => 'Tone', 'instruction_body' => 'Be warm.'])->assertCreated();

        Http::assertSent(function (HttpRequest $request) use ($partner) {
            $context = $this->verifySigned($request);
            $this->assertSame('partner', $context['actor_type']);
            $this->assertSame($partner->id, $context['partner_id']);
            $this->assertSame('partner', $request['scope']);
            $this->assertArrayNotHasKey('partner_id', $request->data());
            $this->assertArrayNotHasKey('is_mandatory', $request->data());

            return true;
        });
    }

    public function test_partner_training_routes_are_forwarded_with_the_training_scope(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => ['id' => 'q1', 'question' => 'Q?', 'draft' => 'A.']], 201)]);
        $partner = User::query()->forceCreate(['name' => 'Atty. Cruz', 'email' => 'p'.uniqid().'@test.ph', 'password' => 'secret123', 'account_type' => 'partner', 'partner_status' => 'active']);

        $this->actingAs($partner, 'web')->postJson('/api/v1/partner/ai/training/questions', [], ['Idempotency-Key' => 'train-key-0001'])->assertCreated()->assertJsonPath('data.question', 'Q?');
        // Only allow-listed training paths are forwarded.
        $this->actingAs($partner, 'web')->postJson('/api/v1/partner/ai/training/prompts/delete-all', [])->assertNotFound();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($partner) {
            $context = $this->verifySigned($request);
            $this->assertStringEndsWith('/api/v1/ai/training/questions', parse_url($request->url(), PHP_URL_PATH));
            $this->assertSame('partner', $context['actor_type']);
            $this->assertSame($partner->id, $context['partner_id']);
            $this->assertContains('partner.training', $context['scopes']);

            return true;
        });
    }

    private function signedInternal(string $uri, array $body, array $scopes, ?string $nonce = null, ?int $ts = null, string $secret = self::WS_KEY)
    {
        $json = json_encode($body);
        $context = Sig::encodeContext(['actor_type' => 'system', 'scopes' => $scopes]);
        $nonce ??= bin2hex(random_bytes(16));
        $ts = (string) ($ts ?? time());
        $canonical = Sig::canonical('POST', $uri, [], 'gabaymo-ai', 'gabaymo-ws', $ts, $nonce, hash('sha256', $json), $context);
        $server = [];
        foreach ([Sig::H_SERVICE => 'gabaymo-ai', Sig::H_AUDIENCE => 'gabaymo-ws', Sig::H_TIMESTAMP => $ts, Sig::H_NONCE => $nonce, Sig::H_CONTEXT => $context, Sig::H_SIGNATURE => Sig::sign($canonical, $secret)] as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $json);
    }

    public function test_internal_settlement_is_signed_idempotent_and_replay_safe(): void
    {
        $user = $this->member();
        $reservation = app(TokenMeter::class)->reserve($user, 'k1')['reservation'];
        $uri = "/api/v1/internal/ai/credits/{$reservation->id}/release";
        $body = ['question_id' => '9b2f7a3e-1111-4c4c-8888-000000000001', 'reason' => 'no_evidence'];

        $this->postJson($uri, $body)->assertStatus(401);
        $this->signedInternal($uri, $body, ['credits.settle'], null, null, 'wrong-secret')->assertStatus(401);
        $this->signedInternal($uri, $body, ['tickets.create'])->assertStatus(403);
        $this->signedInternal($uri, $body, ['credits.settle'], null, time() - 3600)->assertStatus(401);

        $nonce = bin2hex(random_bytes(16));
        $this->signedInternal($uri, $body, ['credits.settle'], $nonce)->assertOk()->assertJsonPath('status', 'released');
        $this->signedInternal($uri, $body, ['credits.settle'], $nonce)->assertStatus(401)->assertJsonPath('error', 'replayed_request');
        $this->assertSame([0, 0], [$user->fresh()->tokens_used, $user->fresh()->tokens_reserved]);

        // Releasing again or finalizing after release changes nothing.
        $this->signedInternal($uri, $body, ['credits.settle'])->assertOk();
        $this->signedInternal("/api/v1/internal/ai/credits/{$reservation->id}/finalize", ['question_id' => $body['question_id']], ['credits.settle'])->assertOk()->assertJsonPath('status', 'released');
        $this->assertSame([0, 0], [$user->fresh()->tokens_used, $user->fresh()->tokens_reserved]);
    }

    public function test_finalize_charges_the_real_weighted_usage_exactly_once(): void
    {
        $user = $this->member(100_000);
        $reservation = app(TokenMeter::class)->reserve($user, 'k2')['reservation'];
        // 4,000 uncached in x1 + 1,000 cached in x0.5 + 600 out x4 = 6,900 weighted tokens.
        $usage = ['input_tokens' => 5000, 'cached_input_tokens' => 1000, 'output_tokens' => 600];
        foreach ([1, 2] as $_) {
            $this->signedInternal("/api/v1/internal/ai/credits/{$reservation->id}/finalize", ['question_id' => '9b2f7a3e-1111-4c4c-8888-000000000002', 'usage' => $usage], ['credits.settle'])->assertOk();
        }
        $fresh = $user->fresh();
        $this->assertSame([6_900, 0], [$fresh->tokens_used, $fresh->tokens_reserved]);
        $this->assertSame(6_900, $reservation->fresh()->charged_tokens);
        $this->assertSame('finalized', $reservation->fresh()->status);
        $this->assertSame(1, TokenUsageEvent::query()->where('type', 'usage')->count());
        $this->assertEqualsWithDelta(6.9, TokenUsageEvent::query()->where('type', 'usage')->value('percent'), 0.001);
    }

    public function test_handoff_ticket_is_created_once_per_question(): void
    {
        $user = $this->member();
        $body = ['user_id' => $user->id, 'question_id' => '9b2f7a3e-1111-4c4c-8888-000000000003', 'subject' => 'GabayMo question for a partner', 'body' => 'Question text'];
        $this->signedInternal('/api/v1/internal/ai/handoff-tickets', $body, ['tickets.create'])->assertCreated();
        $this->signedInternal('/api/v1/internal/ai/handoff-tickets', $body, ['tickets.create'])->assertOk();
        $this->assertSame(1, SupportTicket::query()->where('ai_question_id', $body['question_id'])->count());
    }
}
