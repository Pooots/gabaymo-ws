<?php

namespace Tests\Feature\Improvements;

use App\Mail\AccountInvitationMail;
use App\Models\AuditLog;
use App\Models\TokenUsageEvent;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/** Throttling, enumeration, CORS, health, admin default-deny, suspension, emailed password links. */
class SecurityTest extends ImprovementTestCase
{
    public function test_login_is_throttled_per_email_and_ip(): void
    {
        $this->member(['email' => 'ana@test.ph']);
        for ($i = 0; $i < 5; $i++) {
            $this->send('POST', '/api/v1/login', ['email' => 'ana@test.ph', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->send('POST', '/api/v1/login', ['email' => 'ana@test.ph', 'password' => 'secret123'])->assertStatus(429);
        $this->assertSame(5, AuditLog::query()->where('action', 'auth.login_failed')->count());
    }

    public function test_password_forgot_and_admin_login_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->send('POST', '/api/v1/password/forgot', ['email' => 'x@test.ph'])->assertStatus(202);
        }
        $this->send('POST', '/api/v1/password/forgot', ['email' => 'x@test.ph'])->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $this->send('POST', '/api/v1/admin/login', ['email' => 'nobody@test.ph', 'password' => 'x'])->assertStatus(422);
        }
        $this->send('POST', '/api/v1/admin/login', ['email' => 'nobody@test.ph', 'password' => 'x'])->assertStatus(429);
    }

    public function test_login_does_not_reveal_inactive_partners_without_the_password(): void
    {
        $this->partner(['email' => 'inactive@test.ph', 'partner_status' => 'inactive']);

        $unknown = $this->send('POST', '/api/v1/login', ['email' => 'nobody@test.ph', 'password' => 'guess1234']);
        $inactiveWrong = $this->send('POST', '/api/v1/login', ['email' => 'inactive@test.ph', 'password' => 'guess1234']);
        $inactiveRight = $this->send('POST', '/api/v1/login', ['email' => 'inactive@test.ph', 'password' => 'secret123']);

        foreach ([$unknown, $inactiveWrong, $inactiveRight] as $response) {
            $response->assertStatus(422)->assertExactJson(['message' => 'These credentials do not match our records.']);
        }
    }

    public function test_cors_has_no_wildcard_preview_domains(): void
    {
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertNotContains('*', config('cors.allowed_origins'));

        $evil = $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://attacker.vercel.app',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNotSame('https://attacker.vercel.app', $evil->headers->get('Access-Control-Allow-Origin'));

        $ok = $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertSame('http://localhost:3000', $ok->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_health_does_not_expose_environment_details(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonMissingPath('env')->assertJsonMissingPath('php')->assertJsonMissingPath('app');
    }

    public function test_admin_routes_are_default_deny_and_mapped_to_permissions(): void
    {
        Route::middleware(['api', 'admin'])->get('api/v1/admin/unmapped-module', fn () => response()->json(['ok' => true]));
        $root = $this->staff([], true);
        $this->actingAs($root, 'admin')->getJson('/api/v1/admin/unmapped-module')->assertForbidden();

        $support = $this->staff(['members']);
        $this->actingAs($support, 'admin')->getJson('/api/v1/admin/members')->assertOk();
        foreach (['payments', 'payments/summary', 'tickets', 'access-keys', 'referral-codes', 'organizations', 'audit'] as $path) {
            $this->actingAs($support, 'admin')->getJson('/api/v1/admin/'.$path)->assertForbidden();
        }

        $billing = $this->staff(['payments', 'access-keys', 'referral-codes', 'organizations', 'tickets']);
        foreach (['payments', 'payments/summary', 'tickets', 'access-keys', 'referral-codes', 'organizations'] as $path) {
            $this->actingAs($billing, 'admin')->getJson('/api/v1/admin/'.$path)->assertOk();
        }
        $this->actingAs($billing, 'admin')->getJson('/api/v1/admin/me')->assertOk();

        foreach (['payments', 'tickets', 'access-keys', 'referral-codes', 'organizations', 'feedback', 'analytics'] as $key) {
            $this->assertContains($key, AdminPermissions::keys());
        }
    }

    public function test_admin_lists_are_paginated(): void
    {
        foreach (range(1, 3) as $_) {
            $this->member();
        }
        $this->member(['name' => 'Findable Person', 'email' => 'findme@test.ph']);
        $admin = $this->staff(['members', 'audit-log']);

        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/members?per_page=2&page=2')->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/members?search=findme')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'findme@test.ph');
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/audit?per_page=5')->assertOk()->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_suspended_member_cannot_sign_in_and_existing_sessions_are_rejected(): void
    {
        $member = $this->member(['email' => 'sus@test.ph']);
        $this->send('POST', '/api/v1/login', ['email' => 'sus@test.ph', 'password' => 'secret123'])->assertOk();
        $this->send('GET', '/api/v1/me')->assertOk();

        $admin = $this->staff(['members']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/members/{$member->id}/suspend", ['reason' => 'Chargeback'])
            ->assertOk()->assertJsonPath('member.suspended', true);
        $this->assertTrue(AuditLog::query()->where('action', 'member.suspend')->exists());

        $this->send('GET', '/api/v1/me')->assertUnauthorized();
        $this->send('POST', '/api/v1/login', ['email' => 'sus@test.ph', 'password' => 'secret123'])->assertForbidden()->assertJsonPath('error', 'account_suspended');

        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/members/{$member->id}/unsuspend")->assertOk();
        $this->send('POST', '/api/v1/login', ['email' => 'sus@test.ph', 'password' => 'secret123'])->assertOk();
    }

    public function test_suspended_partner_is_rejected(): void
    {
        $partner = $this->partner();
        $admin = $this->staff(['partners']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/suspend", ['reason' => 'Leave'])->assertOk();

        // Existing sessions are signed out; a new sign-in is refused.
        $this->actingAs($partner->fresh(), 'web')->getJson('/api/v1/partner/me')->assertUnauthorized();
        $this->send('POST', '/api/v1/login', ['email' => $partner->email, 'password' => 'secret123'])->assertForbidden()->assertJsonPath('error', 'account_suspended');
    }

    public function test_partner_reset_password_emails_a_link_instead_of_a_temporary_password(): void
    {
        Mail::fake();
        $partner = $this->partner(['email' => 'reset@test.ph']);
        $admin = $this->staff(['partners']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/reset-password")
            ->assertStatus(202)->assertJsonMissingPath('temporary_password');
        Mail::assertQueued(AccountInvitationMail::class, fn ($mail) => $mail->hasTo('reset@test.ph') && $mail->purpose === 'reset');

        // The current password keeps working until a new one is set; no forced-change gate.
        $this->send('POST', '/api/v1/login', ['email' => 'reset@test.ph', 'password' => 'secret123'])->assertOk()->assertJsonMissingPath('user.must_change_password');
        $this->send('GET', '/api/v1/partner/tickets')->assertOk();
    }

    public function test_staff_reset_password_emails_a_link(): void
    {
        Mail::fake();
        $admin = $this->staff(['roles']);
        $target = $this->staff(['overview'], false, ['email' => 'target@test.ph']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/roles/{$target->role_id}/accounts/{$target->id}/reset-password")
            ->assertStatus(202)->assertJsonMissingPath('temporary_password');
        Mail::assertQueued(AccountInvitationMail::class, fn ($mail) => $mail->hasTo('target@test.ph'));

        $this->send('POST', '/api/v1/admin/login', ['email' => 'target@test.ph', 'password' => 'secret123'])->assertOk();
        $this->send('GET', '/api/v1/admin/overview')->assertOk();
    }

    public function test_logout_all_and_password_change_end_other_sessions(): void
    {
        $user = $this->member(['email' => 'multi@test.ph']);
        $this->send('POST', '/api/v1/login', ['email' => 'multi@test.ph', 'password' => 'secret123'])->assertOk();
        // A second device (a session that signed in before the change).
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/me')->assertOk();

        $this->send('PUT', '/api/v1/me/password', ['current_password' => 'secret123', 'password' => 'another-pass', 'password_confirmation' => 'another-pass'])->assertOk();
        $this->send('GET', '/api/v1/me')->assertOk();
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertTrue(AuditLog::query()->where('action', 'member.password')->exists());

        $this->send('POST', '/api/v1/logout-all')->assertOk();
        $this->send('GET', '/api/v1/me')->assertUnauthorized();
        $this->send('POST', '/api/v1/login', ['email' => 'multi@test.ph', 'password' => 'another-pass'])->assertOk();
        $this->send('GET', '/api/v1/me')->assertOk();
    }

    public function test_mass_assignment_cannot_set_account_type_or_plan(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@x.ph', 'password' => 'p', 'account_type' => 'partner', 'subscription_plan_id' => 9, 'partner_status' => 'active']);
        $this->assertNull($user->account_type);
        $this->assertNull($user->subscription_plan_id);
        $this->assertFalse((new TokenUsageEvent)->isFillable('id'));
    }
}
