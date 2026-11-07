<?php

namespace Tests\Feature\Improvements;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Role;
use App\Services\Billing\SubscriptionService;
use App\Support\PhilippineRegions;

/** Organization delete, partner profile fields, staff suspension and admin partner paging (contract section E). */
class AdminGapsTest extends ImprovementTestCase
{
    public function test_deleting_an_organization_detaches_members_onto_their_own_or_free_plan(): void
    {
        $this->freePlan();
        $group = $this->paidPlan('year', 20000, 'School');
        $own = $this->paidPlan();
        $admin = $this->staff(['organizations']);
        $org = Organization::query()->create([
            'name' => 'Rizal ES', 'type' => 'school', 'region' => PhilippineRegions::NAMES[0], 'seats' => 5,
            'subscription_plan_id' => $group->id, 'period_end' => now()->addYear(),
        ]);
        $groupMember = $this->member();
        $paying = $this->member([], $own);
        $subscriptions = app(SubscriptionService::class);
        $subscriptions->joinOrganization($groupMember, $org);
        $subscriptions->joinOrganization($paying, $org);
        OrganizationInvite::query()->create(['organization_id' => $org->id, 'email' => 'pending@test.ph']);
        $this->assertSame('organization', $groupMember->fresh()->plan_source);

        $this->actingAs($this->staff(['members']), 'admin')->deleteJson("/api/v1/admin/organizations/{$org->id}")->assertForbidden();

        $this->actingAs($admin, 'admin')->deleteJson("/api/v1/admin/organizations/{$org->id}")
            ->assertOk()->assertJsonPath('members_detached', 2);

        $this->assertNull(Organization::query()->find($org->id));
        $this->assertSame(0, OrganizationInvite::query()->count());
        $this->assertNull($groupMember->fresh()->organization_id);
        $this->assertNotSame($group->id, $groupMember->fresh()->subscription_plan_id);
        $this->assertNotSame('organization', $groupMember->fresh()->plan_source);
        // Their own paid period keeps running.
        $this->assertSame($own->id, $paying->fresh()->subscription_plan_id);
        $this->assertNull($paying->fresh()->organization_id);
        $this->assertTrue(AuditLog::query()->where('action', 'organization.delete')->where('admin_user_id', $admin->id)->exists());
    }

    public function test_partner_profile_accepts_title_bio_and_photo_url(): void
    {
        $partner = $this->partner(['title' => 'Old title', 'bio' => 'Old bio']);
        $base = ['first_name' => 'Jose', 'last_name' => 'Reyes', 'mobile' => '09171234567', 'specialty' => 'Labor', 'response_hours' => 24];

        // Optional: leaving them out keeps the stored values.
        $this->actingAs($partner, 'web')->putJson('/api/v1/partner/profile', $base)
            ->assertOk()->assertJsonPath('partner.title', 'Old title')->assertJsonPath('partner.bio', 'Old bio')->assertJsonPath('partner.photo_url', null);

        $this->actingAs($partner, 'web')->putJson('/api/v1/partner/profile', $base + [
            'title' => 'Labor lawyer', 'bio' => 'Twenty years with DepEd cases.', 'photo_url' => 'https://cdn.test/p.jpg',
        ])->assertOk()
            ->assertJsonPath('partner.title', 'Labor lawyer')
            ->assertJsonPath('partner.bio', 'Twenty years with DepEd cases.')
            ->assertJsonPath('partner.photo_url', 'https://cdn.test/p.jpg');

        $this->actingAs($partner, 'web')->getJson('/api/v1/partner/me')->assertOk()->assertJsonFragment(['photo_url' => 'https://cdn.test/p.jpg']);
        $this->actingAs($this->staff(['partners']), 'admin')->getJson('/api/v1/admin/partners')
            ->assertOk()->assertJsonPath('data.0.photo_url', 'https://cdn.test/p.jpg');

        $this->actingAs($partner, 'web')->putJson('/api/v1/partner/profile', $base + ['photo_url' => 'not a url'])
            ->assertStatus(422)->assertJsonValidationErrors('photo_url');
        $this->actingAs($partner, 'web')->putJson('/api/v1/partner/profile', $base + ['photo_url' => 'https://cdn.test/'.str_repeat('a', 2050)])
            ->assertStatus(422)->assertJsonValidationErrors('photo_url');
        $this->actingAs($partner, 'web')->putJson('/api/v1/partner/profile', $base + ['bio' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('bio');

        // Clearing works.
        $this->actingAs($partner, 'web')->putJson('/api/v1/partner/profile', $base + ['photo_url' => null, 'title' => ''])
            ->assertOk()->assertJsonPath('partner.photo_url', null)->assertJsonPath('partner.title', null);
    }

    public function test_staff_suspension_blocks_sign_in_and_existing_sessions(): void
    {
        $boss = $this->staff([], true);
        $role = Role::query()->create(['name' => 'Desk', 'slug' => 'desk', 'is_system' => false, 'permissions' => ['tickets']]);
        $desk = AdminUser::query()->forceCreate(['name' => 'Desk One', 'email' => 'desk@test.ph', 'password' => 'secret123', 'role_id' => $role->id]);

        // The desk account has a live session.
        $this->send('POST', '/api/v1/admin/login', ['email' => 'desk@test.ph', 'password' => 'secret123'])->assertOk();
        $this->send('GET', '/api/v1/admin/me')->assertOk();

        $this->actingAs($boss, 'admin')->postJson("/api/v1/admin/roles/{$role->id}/accounts/{$desk->id}/suspend")
            ->assertOk()->assertJsonPath('account.status', 'suspended');
        $this->assertTrue(AuditLog::query()->where('action', 'role.account_suspend')->exists());

        $this->send('GET', '/api/v1/admin/me')->assertUnauthorized();
        $this->send('POST', '/api/v1/admin/login', ['email' => 'desk@test.ph', 'password' => 'secret123'])
            ->assertForbidden()->assertJsonPath('error', 'account_suspended');
        // Wrong password still gets the generic answer.
        $this->send('POST', '/api/v1/admin/login', ['email' => 'desk@test.ph', 'password' => 'wrong-pass'])->assertStatus(422);

        $roles = $this->actingAs($boss, 'admin')->getJson('/api/v1/admin/roles')->assertOk()->json('roles');
        $row = collect($roles)->firstWhere('id', $role->id)['accounts'][0];
        $this->assertSame('suspended', $row['status']);
        $this->assertNotNull($row['suspended_at']);

        $this->actingAs($boss, 'admin')->postJson("/api/v1/admin/roles/{$role->id}/accounts/{$desk->id}/unsuspend")
            ->assertOk()->assertJsonPath('account.status', 'active');
        $this->assertTrue(AuditLog::query()->where('action', 'role.account_unsuspend')->exists());
        $this->send('POST', '/api/v1/admin/login', ['email' => 'desk@test.ph', 'password' => 'secret123'])->assertOk();
    }

    public function test_staff_cannot_suspend_themselves_or_the_last_super_admin(): void
    {
        $boss = $this->staff([], true);
        $bossRole = $boss->role;
        $manager = $this->staff(['roles']);

        $this->actingAs($boss, 'admin')->postJson("/api/v1/admin/roles/{$bossRole->id}/accounts/{$boss->id}/suspend")
            ->assertStatus(422)->assertJsonPath('error', 'cannot_suspend_self');
        $this->actingAs($manager, 'admin')->postJson("/api/v1/admin/roles/{$bossRole->id}/accounts/{$boss->id}/suspend")
            ->assertStatus(422)->assertJsonPath('error', 'last_super_admin');
        // Wrong role in the URL.
        $this->actingAs($boss, 'admin')->postJson("/api/v1/admin/roles/{$bossRole->id}/accounts/{$manager->id}/suspend")->assertNotFound();
        // Needs the roles permission.
        $this->actingAs($this->staff(['members']), 'admin')->postJson("/api/v1/admin/roles/{$manager->role_id}/accounts/{$manager->id}/suspend")->assertForbidden();

        // With a second active administrator, one of them can be suspended.
        $second = AdminUser::query()->forceCreate(['name' => 'Second', 'email' => 'second@test.ph', 'password' => 'secret123', 'role_id' => $bossRole->id]);
        $this->actingAs($manager, 'admin')->postJson("/api/v1/admin/roles/{$bossRole->id}/accounts/{$second->id}/suspend")->assertOk();
        $this->actingAs($manager, 'admin')->postJson("/api/v1/admin/roles/{$bossRole->id}/accounts/{$boss->id}/suspend")
            ->assertStatus(422)->assertJsonPath('error', 'last_super_admin');
    }

    public function test_admin_partner_list_honours_per_page_capped_at_100(): void
    {
        $admin = $this->staff(['partners']);
        foreach (range(1, 105) as $i) {
            $this->partner(['email' => "p{$i}@test.ph"]);
        }

        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/partners?per_page=5')
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.per_page', 5)->assertJsonPath('meta.total', 105);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/partners?per_page=100')
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/partners?per_page=500')
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100)->assertJsonPath('meta.last_page', 2);
    }
}
