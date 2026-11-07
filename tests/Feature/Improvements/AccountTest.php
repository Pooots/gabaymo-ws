<?php

namespace Tests\Feature\Improvements;

use App\Models\Reminder;
use App\Models\SubscriptionPayment;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\PhilippineRegions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Registration consent, email verification, password reset, export and deletion. */
class AccountTest extends ImprovementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gabaymo_ai.url' => 'http://ai.test',
            'services.gabaymo_ai.internal_key' => 'ws-to-ai-secret-for-tests-0123456789',
            'services.gabaymo_ai.retries' => 0,
            'services.frontend_url' => 'https://app.test',
        ]);
    }

    private function registration(array $overrides = []): array
    {
        return [
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'mobile' => '09171234567', 'region' => PhilippineRegions::NAMES[0],
            'email' => 'ana@test.ph', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'accept_terms' => true,
            ...$overrides,
        ];
    }

    public function test_register_requires_consent_records_it_and_sends_a_verification_email(): void
    {
        Notification::fake();
        $this->freePlan();

        $this->send('POST', '/api/v1/register', $this->registration(['accept_terms' => false]))->assertStatus(422)->assertJsonValidationErrors('accept_terms');

        $this->send('POST', '/api/v1/register', $this->registration())->assertCreated()
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.subscription.status', 'trial');
        $user = User::query()->where('email', 'ana@test.ph')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_consent_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        // Verification link (signed) marks the email verified and lands on the SPA.
        $url = VerifyEmailNotification::url($user);
        $this->get($url)->assertRedirect('https://app.test/account?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get($url.'tampered')->assertRedirect('https://app.test/account?verified=0');

        $this->send('POST', '/api/v1/email/verification-notification')->assertStatus(202);
    }

    public function test_password_reset_round_trip_without_enumeration(): void
    {
        Notification::fake();
        $user = $this->member(['email' => 'reset@test.ph']);

        $this->send('POST', '/api/v1/password/forgot', ['email' => 'nobody@test.ph'])->assertStatus(202);
        $this->send('POST', '/api/v1/password/forgot', ['email' => 'reset@test.ph'])->assertStatus(202);

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$token, $user) {
            $token = $n->token;

            return str_starts_with($n->url($user), 'https://app.test/reset-password?token=') && str_contains($n->url($user), 'email=reset%40test.ph');
        });

        $this->send('POST', '/api/v1/password/reset', ['token' => 'bad', 'email' => 'reset@test.ph', 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])->assertStatus(422);
        $this->send('POST', '/api/v1/password/reset', ['token' => $token, 'email' => 'reset@test.ph', 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])->assertOk();
        $this->send('POST', '/api/v1/login', ['email' => 'reset@test.ph', 'password' => 'new-secret-1'])->assertOk();
        $this->assertSame(1, $user->fresh()->session_version, 'Existing sessions are signed out.');
    }

    public function test_profile_update_can_turn_off_issuance_alerts(): void
    {
        $user = $this->member();
        $this->actingAs($user, 'web')->putJson('/api/v1/me', ['issuance_alerts' => false])->assertOk()->assertJsonPath('user.issuance_alerts', false);
        $this->assertFalse($user->fresh()->issuance_alerts);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.profile', 'user_id' => $user->id]);
    }

    public function test_export_includes_local_data_and_ai_conversations(): void
    {
        Http::fake([
            'ai.test/api/v1/ai/conversations?*' => Http::response(['data' => [['id' => 'c0000000-0000-4000-8000-000000000001', 'title' => 'MOOE']], 'meta' => ['last_page' => 1]]),
            'ai.test/api/v1/ai/conversations/*/messages*' => Http::response(['data' => [['role' => 'user', 'content' => 'Q']]]),
            'ai.test/api/v1/ai/memory' => Http::response(['data' => ['enabled' => true, 'items' => []]]),
        ]);
        $user = $this->member();
        Reminder::query()->create(['user_id' => $user->id, 'title' => 'Submit SF2', 'due_at' => now()->addDays(3)]);

        $response = $this->actingAs($user, 'web')->get('/api/v1/me/export', ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $response->assertJsonPath('profile.email', $user->email)
            ->assertJsonPath('reminders.0.title', 'Submit SF2')
            ->assertJsonPath('ai.conversations.0.title', 'MOOE')
            ->assertJsonPath('ai.conversations.0.messages.0.content', 'Q');
    }

    public function test_delete_account_anonymises_and_erases_ai_data(): void
    {
        Http::fake([
            'ai.test/api/v1/ai/conversations?*' => Http::response(['data' => [['id' => 'c0000000-0000-4000-8000-000000000001']], 'meta' => ['last_page' => 1]]),
            'ai.test/*' => Http::response(null, 204),
        ]);
        $plan = $this->paidPlan();
        $user = $this->member(['email' => 'bye@test.ph'], $plan);
        $payment = SubscriptionPayment::query()->create(['user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'reference' => 'GMDEL', 'amount' => 499, 'currency' => 'PHP', 'status' => 'paid']);
        $ticket = SupportTicket::query()->create(['user_id' => $user->id, 'subject' => 'My private matter', 'body' => 'Details', 'status' => 'open']);
        $ticket->messages()->create(['user_id' => $user->id, 'body' => 'Sensitive']);
        UserNotification::query()->create(['user_id' => $user->id, 'type' => 'x', 'title' => 'Hi']);

        $this->actingAs($user, 'web')->deleteJson('/api/v1/me', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user->fresh(), 'web')->deleteJson('/api/v1/me', ['password' => 'secret123'])->assertNoContent();

        $deleted = User::withTrashed()->find($user->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame('Deleted member', $deleted->name);
        $this->assertStringEndsWith('@deleted.gabaymo.invalid', $deleted->email);
        $this->assertNull($deleted->mobile);
        $this->assertSame('[deleted]', $ticket->fresh()->subject);
        $this->assertSame('[deleted]', $ticket->messages()->value('body'));
        $this->assertSame(0, UserNotification::query()->where('user_id', $user->id)->count());
        $this->assertNotNull($payment->fresh(), 'Payments stay for accounting, linked to the anonymised row.');
        $this->assertNull(User::query()->where('email', 'bye@test.ph')->first());

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->all();
        $this->assertContains('DELETE /api/v1/ai/memory', $sent);
        $this->assertContains('DELETE /api/v1/ai/conversations/c0000000-0000-4000-8000-000000000001', $sent);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-GabayMo-Signature'));

        // The email can be used again.
        $this->freePlan();
        $this->send('POST', '/api/v1/register', $this->registration(['email' => 'bye@test.ph']))->assertCreated();
    }
}
