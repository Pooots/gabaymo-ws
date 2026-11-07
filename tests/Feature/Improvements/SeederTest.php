<?php

namespace Tests\Feature\Improvements;

use App\Models\AccountInvitation;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** DatabaseSeeder: reference data, one admin, one partner, no members. */
class SeederTest extends ImprovementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Pin every seed value so a developer's .env (SEED_*) can't change the outcome.
        config(['services.seed' => [
            'admin_email' => 'admin@gabaymo.local', 'admin_name' => 'GabayMo Admin', 'admin_password' => null,
            'partner_email' => 'partner@gabaymo.local', 'partner_name' => 'GabayMo Partner', 'partner_password' => null,
        ]]);
    }

    private function counts(): array
    {
        return [
            'admins' => AdminUser::query()->count(),
            'partners' => User::query()->withTrashed()->partners()->count(),
            'members' => User::query()->withTrashed()->customers()->count(),
        ];
    }

    public function test_seed_creates_one_admin_one_partner_no_members_and_is_idempotent(): void
    {
        $this->artisan('db:seed', ['--force' => true])
            ->expectsOutputToContain('/setup-account?token=')
            ->assertSuccessful();

        $this->assertSame(['admins' => 1, 'partners' => 1, 'members' => 0], $this->counts());
        $admin = AdminUser::query()->sole();
        $this->assertSame('admin@gabaymo.local', $admin->email);
        $this->assertSame('admin', $admin->role->slug);
        $this->assertTrue($admin->role->is_system);
        $this->assertTrue($admin->isInvited());
        $this->assertNull($admin->password);
        $this->assertSame('partner@gabaymo.local', User::query()->partners()->sole()->email);
        $this->assertGreaterThan(0, SubscriptionPlan::query()->count());
        Mail::assertQueuedCount(2);

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertSame(['admins' => 1, 'partners' => 1, 'members' => 0], $this->counts());
        $this->assertSame(3, Role::query()->count());
        // A re-run replaces the open links: one usable link per account.
        $this->assertSame(2, AccountInvitation::query()->whereNull('revoked_at')->count());
    }

    public function test_seed_uses_password_env_and_sends_no_invite(): void
    {
        config([
            'services.seed.admin_email' => 'boss@test.ph',
            'services.seed.admin_password' => 'seeded-admin-pass',
            'services.seed.partner_password' => 'seeded-partner-pass',
        ]);

        $this->artisan('db:seed', ['--force' => true])
            ->expectsOutputToContain('SEED_ADMIN_PASSWORD')
            ->doesntExpectOutputToContain('/setup-account?token=')
            ->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, AccountInvitation::query()->count());
        $this->send('POST', '/api/v1/admin/login', ['email' => 'boss@test.ph', 'password' => 'seeded-admin-pass'])->assertOk();
        $this->send('POST', '/api/v1/login', ['email' => 'partner@gabaymo.local', 'password' => 'seeded-partner-pass'])->assertOk()->assertJsonPath('portal', 'partner');
    }
}
