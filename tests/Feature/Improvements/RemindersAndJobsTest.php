<?php

namespace Tests\Feature\Improvements;

use App\Models\Reminder;
use App\Models\TokenUsageEvent;
use App\Models\UserNotification;
use App\Notifications\IssuanceDigestNotification;
use App\Notifications\ReminderDueNotification;
use App\Services\Billing\TokenMeter;
use App\Services\GabayMoAi\ServiceSignature as Sig;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Reminders, the stale-hold sweeper, issuance alerts and the new AI forwarding routes. */
class RemindersAndJobsTest extends ImprovementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gabaymo_ai.url' => 'http://ai.test',
            'services.gabaymo_ai.internal_key' => 'ws-to-ai-secret-for-tests-0123456789',
            'services.gabaymo_ai.retries' => 0,
            'services.usage.reserve_tokens' => 15_000,
        ]);
    }

    public function test_reminder_crud_is_scoped_to_the_member(): void
    {
        $member = $this->member();
        $other = $this->member();
        $id = $this->actingAs($member, 'web')->postJson('/api/v1/reminders', ['title' => 'Submit SF2', 'due_at' => now()->addDays(3)->toIso8601String(), 'notes' => 'Division office'])
            ->assertCreated()->assertJsonPath('data.title', 'Submit SF2')->json('data.id');
        $this->actingAs($member, 'web')->postJson('/api/v1/reminders', ['title' => 'Past', 'due_at' => now()->subDay()->toIso8601String()])->assertStatus(422);

        $this->actingAs($member, 'web')->getJson('/api/v1/reminders')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($other, 'web')->patchJson("/api/v1/reminders/{$id}", ['title' => 'Hacked'])->assertNotFound();
        $this->actingAs($member, 'web')->patchJson("/api/v1/reminders/{$id}", ['title' => 'Submit SF2 (final)'])->assertOk()->assertJsonPath('data.title', 'Submit SF2 (final)');
        $this->actingAs($other, 'web')->deleteJson("/api/v1/reminders/{$id}")->assertNotFound();
        $this->actingAs($member, 'web')->deleteJson("/api/v1/reminders/{$id}")->assertNoContent();
    }

    public function test_reminders_are_sent_the_day_before_and_on_the_due_date(): void
    {
        Notification::fake();
        $this->travelTo(now()->timezone(config('app.timezone'))->setTime(8, 0));
        $member = $this->member();
        $reminder = Reminder::query()->create(['user_id' => $member->id, 'title' => 'Submit SF2', 'due_at' => now()->addDay()->setTime(17, 0)]);

        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();
        Notification::assertSentToTimes($member, ReminderDueNotification::class, 1);
        $this->assertNotNull($reminder->fresh()->notified_day_before_at);

        $this->travel(1)->days();
        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();
        Notification::assertSentToTimes($member, ReminderDueNotification::class, 2);
        $this->assertNotNull($reminder->fresh()->notified_due_at);
        $this->assertSame(2, UserNotification::query()->where('user_id', $member->id)->where('type', UserNotification::REMINDER_DUE)->count());
    }

    public function test_sweeper_releases_stale_holds_and_a_late_finalize_still_charges_once(): void
    {
        $user = $this->member([], $this->paidPlan());
        $meter = app(TokenMeter::class);
        $stale = $meter->reserve($user, 'stale')['reservation'];
        $this->travel(20)->minutes();
        $fresh = $meter->reserve($user->fresh(), 'fresh')['reservation'];

        $this->artisan('credits:sweep')->assertSuccessful();
        $this->assertSame('released', $stale->fresh()->status);
        $this->assertSame('reserved', $fresh->fresh()->status);
        $this->assertSame(15_000, $user->fresh()->tokens_reserved);

        // gabaymo-ai finally reports the answer: charged once, never double.
        $usage = ['input_tokens' => 5000, 'cached_input_tokens' => 1000, 'output_tokens' => 600];
        $meter->finalize($stale->id, '9b2f7a3e-1111-4c4c-8888-00000000000a', $usage);
        $meter->finalize($stale->id, '9b2f7a3e-1111-4c4c-8888-00000000000a', $usage);
        $this->assertSame('finalized', $stale->fresh()->status);
        $this->assertSame(6_900, $user->fresh()->tokens_used);
        $this->assertSame(15_000, $user->fresh()->tokens_reserved);
        $this->assertSame(1, TokenUsageEvent::query()->where('type', 'usage')->count());
    }

    public function test_issuance_alerts_use_a_system_actor_and_respect_the_opt_out(): void
    {
        Notification::fake();
        Http::fake(['ai.test/*' => Http::response(['data' => [[
            'document_id' => 'd1', 'title' => 'Revised leave guidelines', 'issuance_number' => 'DO 12, s. 2026',
            'published_at' => now()->toIso8601String(), 'supersedes' => ['title' => 'Old', 'issuance_number' => 'DO 3, s. 2020'], 'regions' => ['NCR'],
        ]]])]);
        $on = $this->member();
        $off = $this->member(['issuance_alerts' => false]);

        $this->artisan('issuances:alert')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            $context = Sig::decodeContext($request->header(Sig::H_CONTEXT)[0]);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/api/v1/ai/knowledge/updates')
                && $context['actor_type'] === 'system' && $context['scopes'] === ['chat.read']
                && $query['region'] === 'National Capital Region (NCR)' && isset($query['since']);
        });
        $this->assertDatabaseHas('user_notifications', ['user_id' => $on->id, 'type' => UserNotification::ISSUANCE_UPDATE]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $off->id]);
        Notification::assertSentTo($on, IssuanceDigestNotification::class);
        Notification::assertNotSentTo($off, IssuanceDigestNotification::class);
    }

    public function test_member_conversation_routes_are_forwarded(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => ['ok' => true]])]);
        $member = $this->member();
        $id = '9b2f7a3e-1111-4c4c-8888-000000000001';
        $messageId = 4821;

        $this->actingAs($member, 'web')->patchJson("/api/v1/ai/conversations/{$id}", ['title' => 'Leave questions'])->assertOk();
        $this->actingAs($member, 'web')->patchJson("/api/v1/ai/conversations/{$id}", ['title' => ''])->assertStatus(422);
        $this->actingAs($member, 'web')->deleteJson("/api/v1/ai/conversations/{$id}")->assertOk();
        $this->actingAs($member, 'web')->postJson("/api/v1/ai/messages/{$messageId}/feedback", ['rating' => 'down', 'reason' => 'Wrong order'])->assertOk();
        $this->actingAs($member, 'web')->postJson("/api/v1/ai/messages/{$messageId}/feedback", ['rating' => 'meh'])->assertStatus(422);
        $this->actingAs($member, 'web')->getJson('/api/v1/ai/conversations?search=leave&page=2&evil=1')->assertOk();

        $sent = Http::recorded()->map(fn ($pair) => [$pair[0]->method(), parse_url($pair[0]->url(), PHP_URL_PATH).'?'.parse_url($pair[0]->url(), PHP_URL_QUERY), $pair[0]->body()])->all();
        $this->assertSame([
            ['PATCH', "/api/v1/ai/conversations/{$id}?", '{"title":"Leave questions"}'],
            ['DELETE', "/api/v1/ai/conversations/{$id}?", ''],
            ['POST', "/api/v1/ai/messages/{$messageId}/feedback?", '{"rating":"down","reason":"Wrong order"}'],
            ['GET', '/api/v1/ai/conversations?search=leave&page=2', ''],
        ], $sent);
    }

    public function test_admin_feedback_and_analytics_and_partner_patch_are_allow_listed_but_not_put(): void
    {
        Http::fake(['ai.test/*' => Http::response(['data' => []])]);
        $analyst = $this->staff(['analytics', 'feedback']);
        $other = $this->staff(['members']);

        $this->actingAs($analyst, 'admin')->getJson('/api/v1/admin/ai/feedback?rating=down')->assertOk();
        $this->actingAs($analyst, 'admin')->getJson('/api/v1/admin/ai/analytics?days=30')->assertOk();
        $this->actingAs($other, 'admin')->getJson('/api/v1/admin/ai/analytics')->assertForbidden();
        Http::assertSent(fn (HttpRequest $r) => in_array('answers.review', Sig::decodeContext($r->header(Sig::H_CONTEXT)[0])['scopes'], true));

        $partner = $this->partner();
        $this->actingAs($partner, 'web')->patchJson('/api/v1/partner/ai/training/voice', ['tone' => 'warm'])->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/v1/ai/training/voice'));

        // gabaymo-ai only accepts PATCH for these, so PUT is not forwarded.
        foreach (['instructions/5', 'training/answers/'.str_repeat('a', 8).'-aaaa-aaaa-aaaa-'.str_repeat('a', 12), 'training/voice'] as $path) {
            $this->actingAs($partner, 'web')->putJson('/api/v1/partner/ai/'.$path, ['tone' => 'warm'])->assertNotFound();
        }
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT');
    }
}
