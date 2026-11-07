<?php

namespace Tests\Feature\Improvements;

use App\Mail\AccountInvitationMail;
use App\Models\AccountInvitation;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/** Partner / staff accounts created by email invitation (no admin-typed passwords). */
class InvitationTest extends ImprovementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.frontend_url' => 'https://app.test', 'services.partner_url' => 'https://partner.test']);
    }

    /** @return array{token: string, email: string, url: string} the newest invitation link mailed */
    private function lastLink(): array
    {
        $mail = Mail::queued(AccountInvitationMail::class)->last();
        $this->assertNotNull($mail, 'No invitation mail was queued.');
        parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

        return ['token' => (string) $query['token'], 'email' => (string) $query['email'], 'url' => $mail->url];
    }

    private function invitePartner(string $email = 'atty@test.ph'): User
    {
        $admin = $this->staff(['partners'], false, ['name' => 'Ana Admin']);
        $this->actingAs($admin, 'admin')->postJson('/api/v1/admin/partners', [
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'email' => $email,
            'specialty' => 'Education law',
            'practice_areas' => ['leave'],
            // Ignored: staff never choose another person's password.
            'password' => 'should-be-ignored',
        ])->assertCreated()
            ->assertJsonMissingPath('temporary_password')
            ->assertJsonMissingPath('partner.password')
            ->assertJsonPath('partner.status', 'invited');

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function inviteStaff(string $email = 'staff@test.ph'): AdminUser
    {
        $admin = $this->staff(['roles'], false, ['name' => 'Ana Admin']);
        $role = Role::query()->create(['name' => 'Support desk', 'slug' => 'support-desk', 'is_system' => false, 'permissions' => ['overview']]);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/roles/{$role->id}/accounts", [
            'name' => 'Sofia Staff',
            'email' => $email,
            'password' => 'should-be-ignored',
        ])->assertCreated()
            ->assertJsonMissingPath('temporary_password')
            ->assertJsonPath('account.status', 'invited');

        return AdminUser::query()->where('email', $email)->firstOrFail();
    }

    public function test_creating_a_partner_mails_a_set_up_link_and_stores_no_password(): void
    {
        $partner = $this->invitePartner();

        $this->assertNull($partner->password);
        $this->assertTrue($partner->isInvited());
        $link = $this->lastLink();
        $this->assertStringStartsWith('https://partner.test/setup-account?token=', $link['url']);
        $this->assertSame('atty@test.ph', $link['email']);
        Mail::assertQueued(AccountInvitationMail::class, fn (AccountInvitationMail $m) => $m->hasTo('atty@test.ph')
            && $m->invitedBy === 'Ana Admin' && $m->role === 'Partner lawyer' && $m->expiresAt->between(now()->addHours(71), now()->addHours(73)));
        // Only the hash is stored.
        $invitation = AccountInvitation::query()->sole();
        $this->assertSame(hash('sha256', $link['token']), $invitation->token_hash);
        $this->assertSame('partner', $invitation->account_type);

        $rendered = (new AccountInvitationMail('Jose', $link['url'], 'Partner lawyer', 'Ana Admin', now()->addHours(72)))->render();
        $this->assertStringContainsString('Set up your account', $rendered);
        $this->assertStringContainsString('Ana Admin', $rendered);
    }

    public function test_creating_staff_mails_a_set_up_link_on_the_frontend_url(): void
    {
        $staff = $this->inviteStaff();

        $this->assertNull($staff->password);
        $link = $this->lastLink();
        $this->assertStringStartsWith('https://app.test/setup-account?token=', $link['url']);
        Mail::assertQueued(AccountInvitationMail::class, fn (AccountInvitationMail $m) => $m->hasTo('staff@test.ph') && $m->role === 'Support desk');
    }

    public function test_invited_accounts_cannot_sign_in_or_request_a_reset(): void
    {
        Notification::fake();
        $this->invitePartner('atty@test.ph');
        $this->inviteStaff('staff@test.ph');

        $this->send('POST', '/api/v1/login', ['email' => 'atty@test.ph', 'password' => 'anything1'])->assertStatus(422);
        $this->send('POST', '/api/v1/admin/login', ['email' => 'staff@test.ph', 'password' => 'anything1'])->assertStatus(422);
        $this->send('POST', '/api/v1/password/forgot', ['email' => 'atty@test.ph'])->assertStatus(202);
        Notification::assertNothingSent();
    }

    public function test_show_returns_the_invitation_and_410_for_bad_links(): void
    {
        $this->invitePartner();
        $link = $this->lastLink();

        $this->send('GET', '/api/v1/invitations/'.$link['token'].'?email='.urlencode('ATTY@test.ph'))->assertOk()
            ->assertJsonPath('email', 'atty@test.ph')
            ->assertJsonPath('name', 'Jose Rizal')
            ->assertJsonPath('account_type', 'partner')
            ->assertJsonPath('purpose', 'setup')
            ->assertJsonPath('invited_by', 'Ana Admin')
            ->assertJsonPath('fields_required', ['name', 'password', 'practice_areas'])
            ->assertJsonPath('fields_optional', ['phone', 'title', 'bio'])
            ->assertJsonPath('prefill.practice_areas', ['leave'])
            ->assertJsonStructure(['expires_at', 'practice_area_options']);

        $this->send('GET', '/api/v1/invitations/'.$link['token'].'?email=other@test.ph')->assertStatus(410)->assertJsonPath('error', 'invitation_invalid');
        $this->send('GET', '/api/v1/invitations/'.$link['token'])->assertStatus(410);
        $this->send('GET', '/api/v1/invitations/not-a-token?email=atty@test.ph')->assertStatus(410);

        $this->travel(73)->hours();
        $this->send('GET', '/api/v1/invitations/'.$link['token'].'?email=atty@test.ph')->assertStatus(410)->assertJsonPath('error', 'invitation_invalid');
    }

    public function test_used_links_are_410(): void
    {
        $this->invitePartner();
        $link = $this->lastLink();
        $body = ['token' => $link['token'], 'email' => $link['email'], 'name' => 'Jose Rizal', 'password' => 'partner-pass', 'password_confirmation' => 'partner-pass', 'practice_areas' => ['leave']];
        $this->send('POST', '/api/v1/invitations/accept', $body)->assertOk();

        $this->send('GET', '/api/v1/invitations/'.$link['token'].'?email='.$link['email'])->assertStatus(410);
        $this->send('POST', '/api/v1/invitations/accept', $body)->assertStatus(410)->assertJsonPath('error', 'invitation_invalid');
    }

    public function test_partner_accept_sets_password_activates_and_signs_in(): void
    {
        $partner = $this->invitePartner();
        $link = $this->lastLink();

        $this->send('POST', '/api/v1/invitations/accept', [
            'token' => $link['token'],
            'email' => $link['email'],
            'name' => 'Atty. Jose Rizal',
            'password' => 'partner-pass',
            'password_confirmation' => 'partner-pass',
            'practice_areas' => ['leave', 'discipline'],
            'phone' => '+639171234567',
            'title' => 'Senior counsel',
            'bio' => 'Education law since 2001.',
        ])->assertOk()
            ->assertJsonPath('signed_in', true)
            ->assertJsonPath('account_type', 'partner')
            ->assertJsonPath('portal', 'partner')
            ->assertJsonPath('user.practice_areas', ['leave', 'discipline'])
            ->assertJsonPath('user.title', 'Senior counsel');

        $partner->refresh();
        $this->assertSame('active', $partner->account_status);
        $this->assertNotNull($partner->email_verified_at);
        $this->assertSame('09171234567', $partner->mobile);
        $this->assertTrue(Hash::check('partner-pass', $partner->password));
        $this->assertNotNull(AccountInvitation::query()->sole()->accepted_at);
        $this->assertTrue(AuditLog::query()->where('action', 'partner.invitation_accepted')->exists());

        $this->send('GET', '/api/v1/partner/me')->assertOk();
        $this->send('POST', '/api/v1/partner/logout')->assertOk();
        $this->send('POST', '/api/v1/login', ['email' => 'atty@test.ph', 'password' => 'partner-pass'])->assertOk()->assertJsonPath('portal', 'partner');
    }

    public function test_partner_accept_validates_practice_areas(): void
    {
        $this->invitePartner();
        $link = $this->lastLink();
        $body = ['token' => $link['token'], 'email' => $link['email'], 'name' => 'Jose Rizal', 'password' => 'partner-pass', 'password_confirmation' => 'partner-pass'];

        $this->send('POST', '/api/v1/invitations/accept', $body)->assertStatus(422)->assertJsonValidationErrors('practice_areas');
        $this->send('POST', '/api/v1/invitations/accept', $body + ['practice_areas' => []])->assertStatus(422)->assertJsonValidationErrors('practice_areas');
        $this->send('POST', '/api/v1/invitations/accept', $body + ['practice_areas' => ['taxes']])->assertStatus(422)->assertJsonValidationErrors('practice_areas.0');
        $this->assertTrue(User::query()->where('email', 'atty@test.ph')->firstOrFail()->isInvited());
    }

    public function test_staff_accept_signs_in_to_the_admin_portal(): void
    {
        $staff = $this->inviteStaff();
        $link = $this->lastLink();

        $this->send('GET', '/api/v1/invitations/'.$link['token'].'?email='.$link['email'])->assertOk()
            ->assertJsonPath('account_type', 'admin')
            ->assertJsonPath('role.name', 'Support desk')
            ->assertJsonPath('fields_required', ['name', 'password'])
            ->assertJsonPath('prefill', null);
        // Staff passwords need 10+ characters.
        $this->send('POST', '/api/v1/invitations/accept', ['token' => $link['token'], 'email' => $link['email'], 'name' => 'Sofia', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->send('POST', '/api/v1/invitations/accept', ['token' => $link['token'], 'email' => $link['email'], 'name' => 'Sofia S.', 'password' => 'a-long-staff-pass', 'password_confirmation' => 'a-long-staff-pass'])
            ->assertOk()->assertJsonPath('signed_in', true)->assertJsonPath('user.role', 'support-desk');

        $this->assertSame('Sofia S.', $staff->fresh()->name);
        $this->send('GET', '/api/v1/admin/me')->assertOk()->assertJsonPath('user.email', 'staff@test.ph');
        $this->send('POST', '/api/v1/admin/logout')->assertOk();
        $this->send('POST', '/api/v1/admin/login', ['email' => 'staff@test.ph', 'password' => 'a-long-staff-pass'])->assertOk();
    }

    public function test_resend_invalidates_the_old_link(): void
    {
        $partner = $this->invitePartner();
        $old = $this->lastLink();
        $admin = $this->staff(['partners']);

        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/resend-invite")->assertStatus(202)
            ->assertJsonPath('partner.status', 'invited')
            ->assertJsonStructure(['partner' => ['invitation_expires_at']]);
        $new = $this->lastLink();
        $this->assertNotSame($old['token'], $new['token']);

        $this->send('GET', '/api/v1/invitations/'.$old['token'].'?email='.$old['email'])->assertStatus(410);
        $this->send('GET', '/api/v1/invitations/'.$new['token'].'?email='.$new['email'])->assertOk();

        $staff = $this->inviteStaff();
        $oldStaff = $this->lastLink();
        $roles = $this->staff(['roles']);
        $this->actingAs($roles, 'admin')->postJson("/api/v1/admin/roles/{$staff->role_id}/accounts/{$staff->id}/resend-invite")->assertStatus(202)
            ->assertJsonPath('account.status', 'invited');
        $this->send('GET', '/api/v1/invitations/'.$oldStaff['token'].'?email='.$oldStaff['email'])->assertStatus(410);

        // An account that already set up gets "reset password" instead.
        $active = $this->partner();
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/partners/{$active->id}/resend-invite")->assertStatus(422);
    }

    public function test_reset_password_mails_a_password_link_that_replaces_the_old_password(): void
    {
        $partner = $this->partner(['email' => 'reset@test.ph']);
        $admin = $this->staff(['partners']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/reset-password")
            ->assertStatus(202)->assertJsonMissingPath('temporary_password')->assertJsonStructure(['message', 'expires_at']);
        $link = $this->lastLink();
        Mail::assertQueued(AccountInvitationMail::class, fn (AccountInvitationMail $m) => $m->purpose === 'reset');

        $this->send('GET', '/api/v1/invitations/'.$link['token'].'?email='.$link['email'])->assertOk()
            ->assertJsonPath('purpose', 'reset')->assertJsonPath('fields_required', ['password']);
        $this->send('POST', '/api/v1/invitations/accept', ['token' => $link['token'], 'email' => $link['email'], 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertOk()->assertJsonPath('signed_in', true);
        $this->send('POST', '/api/v1/partner/logout')->assertOk();

        $this->send('POST', '/api/v1/login', ['email' => 'reset@test.ph', 'password' => 'secret123'])->assertStatus(422);
        $this->send('POST', '/api/v1/login', ['email' => 'reset@test.ph', 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_staff_reset_link_does_not_skip_two_factor(): void
    {
        $target = $this->staff(['overview'], false, ['email' => 'twofa@test.ph', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        $admin = $this->staff(['roles']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/roles/{$target->role_id}/accounts/{$target->id}/reset-password")->assertStatus(202);
        $link = $this->lastLink();

        $this->send('POST', '/api/v1/invitations/accept', ['token' => $link['token'], 'email' => $link['email'], 'password' => 'a-long-new-password', 'password_confirmation' => 'a-long-new-password'])
            ->assertOk()->assertExactJson(['signed_in' => false, 'account_type' => 'admin']);
        $this->send('GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_accept_is_throttled(): void
    {
        $body = ['token' => 'wrong', 'email' => 'atty@test.ph', 'password' => 'x', 'password_confirmation' => 'x'];
        for ($i = 0; $i < 5; $i++) {
            $this->send('POST', '/api/v1/invitations/accept', $body)->assertStatus(410);
        }
        $this->send('POST', '/api/v1/invitations/accept', $body)->assertStatus(429);
    }

    public function test_new_admin_routes_need_the_module_permission(): void
    {
        $partner = $this->invitePartner();
        $staff = $this->inviteStaff();
        $outsider = $this->staff(['overview']);

        $this->actingAs($outsider, 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/resend-invite")->assertForbidden();
        $this->actingAs($outsider, 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/reset-password")->assertForbidden();
        $this->actingAs($outsider, 'admin')->postJson('/api/v1/admin/partners', ['name' => 'X', 'email' => 'x@test.ph'])->assertForbidden();
        $this->actingAs($outsider, 'admin')->postJson("/api/v1/admin/roles/{$staff->role_id}/accounts/{$staff->id}/resend-invite")->assertForbidden();
        $this->actingAs($outsider, 'admin')->postJson("/api/v1/admin/roles/{$staff->role_id}/accounts", ['name' => 'X', 'email' => 'x@test.ph'])->assertForbidden();

        // The partners module does not grant staff invitations, and vice versa.
        $this->actingAs($this->staff(['partners']), 'admin')->postJson("/api/v1/admin/roles/{$staff->role_id}/accounts/{$staff->id}/resend-invite")->assertForbidden();
        $this->actingAs($this->staff(['roles']), 'admin')->postJson("/api/v1/admin/partners/{$partner->id}/resend-invite")->assertForbidden();
    }

    public function test_lists_show_status_and_invitation_expiry(): void
    {
        $partner = $this->invitePartner();
        $this->partner(['email' => 'active@test.ph']);
        $this->partner(['email' => 'sus@test.ph', 'suspended_at' => now()]);
        $admin = $this->staff(['partners', 'roles']);

        $rows = collect($this->actingAs($admin, 'admin')->getJson('/api/v1/admin/partners')->assertOk()->json('data'))->keyBy('email');
        $this->assertSame('invited', $rows['atty@test.ph']['status']);
        $this->assertNotNull($rows['atty@test.ph']['invitation_expires_at']);
        $this->assertSame('active', $rows['active@test.ph']['status']);
        $this->assertNull($rows['active@test.ph']['invitation_expires_at']);
        $this->assertSame('suspended', $rows['sus@test.ph']['status']);

        $staff = $this->inviteStaff();
        $role = collect($this->actingAs($admin, 'admin')->getJson('/api/v1/admin/roles')->assertOk()->json('roles'))->firstWhere('id', $staff->role_id);
        $this->assertSame('invited', $role['accounts'][0]['status']);
        $this->assertNotNull($role['accounts'][0]['invitation_expires_at']);
    }
}
