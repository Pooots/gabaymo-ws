<?php

namespace Tests\Feature\Improvements;

use App\Models\Organization;
use App\Models\User;
use App\Support\PhilippineRegions;
use Illuminate\Support\Facades\Notification;

/** Access keys, referral codes and organizations (group plans). */
class CodesAndOrganizationsTest extends ImprovementTestCase
{
    public function test_admin_creates_an_access_key_and_a_member_redeems_it_once(): void
    {
        $plan = $this->paidPlan();
        $admin = $this->staff(['access-keys']);
        $key = $this->actingAs($admin, 'admin')->postJson('/api/v1/admin/access-keys', ['plan_id' => $plan->id, 'duration_days' => 30, 'extra_usage_percent' => 10, 'max_uses' => 1, 'note' => 'Pilot'])
            ->assertCreated()->json('access_key');
        $this->assertMatchesRegularExpression('/^GM-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key['code']);
        $this->actingAs($admin, 'admin')->postJson('/api/v1/admin/access-keys', ['max_uses' => 1])->assertStatus(422);

        $member = $this->member();
        $this->actingAs($member, 'web')->postJson('/api/v1/redeem', ['code' => strtolower($key['code'])])->assertOk()
            ->assertJsonPath('user.subscription.source', 'access_key')
            ->assertJsonPath('user.subscription.status', 'active');
        $fresh = $member->fresh();
        $this->assertSame($plan->id, $fresh->subscription_plan_id);
        $this->assertTrue($fresh->current_period_end->isSameDay(now()->addDays(30)));
        $this->assertSame(300_000, $fresh->bonus_tokens);

        $this->actingAs($this->member(), 'web')->postJson('/api/v1/redeem', ['code' => $key['code']])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/access-keys')->assertOk()->assertJsonPath('data.0.uses_count', 1);

        $revoked = $this->actingAs($admin, 'admin')->postJson('/api/v1/admin/access-keys', ['extra_usage_percent' => 5, 'max_uses' => 10])->json('access_key');
        $this->actingAs($admin, 'admin')->deleteJson('/api/v1/admin/access-keys/'.$revoked['id'])->assertOk()->assertJsonPath('access_key.status', 'revoked');
        $this->actingAs($this->member(), 'web')->postJson('/api/v1/redeem', ['code' => $revoked['code']])->assertStatus(422);
    }

    public function test_referral_code_at_registration_rewards_both_sides(): void
    {
        Notification::fake();
        $this->freePlan();
        $owner = $this->member();
        $admin = $this->staff(['referral-codes']);
        $this->actingAs($admin, 'admin')->postJson('/api/v1/admin/referral-codes', ['code' => 'teachers-2026', 'owner_user_id' => $owner->id, 'reward_extra_usage_percent' => 25, 'max_uses' => 5])
            ->assertCreated()->assertJsonPath('referral_code.code', 'TEACHERS-2026');

        $form = ['first_name' => 'Ana', 'last_name' => 'Cruz', 'mobile' => '09171234567', 'region' => PhilippineRegions::NAMES[0],
            'email' => 'ref@test.ph', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'accept_terms' => true];
        $this->send('POST', '/api/v1/register', [...$form, 'referral_code' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('referral_code');
        $this->send('POST', '/api/v1/register', [...$form, 'referral_code' => 'teachers-2026'])->assertCreated();

        $new = User::query()->where('email', 'ref@test.ph')->firstOrFail();
        $this->assertSame(50_000, $new->bonus_tokens);
        $this->assertSame(50_000, $owner->fresh()->bonus_tokens);
        $this->actingAs($admin, 'admin')->getJson('/api/v1/admin/referral-codes')->assertOk()->assertJsonPath('data.0.uses_count', 1);
    }

    public function test_organization_seats_invites_and_removal(): void
    {
        Notification::fake();
        $this->freePlan();
        $plan = $this->paidPlan('year', 20000, 'School');
        $admin = $this->staff(['organizations']);
        $org = $this->actingAs($admin, 'admin')->postJson('/api/v1/admin/organizations', [
            'name' => 'Rizal ES', 'type' => 'school', 'region' => PhilippineRegions::NAMES[0], 'seats' => 2,
            'subscription_plan_id' => $plan->id, 'period_end' => now()->addYear()->toIso8601String(),
        ])->assertCreated()->json('organization');

        $member = $this->member(['email' => 'teacher@test.ph']);
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/organizations/{$org['id']}/members", ['email' => 'teacher@test.ph'])
            ->assertCreated()->assertJsonPath('status', 'attached');
        $this->assertSame($plan->id, $member->fresh()->subscription_plan_id);
        $this->assertSame('organization', $member->fresh()->plan_source);

        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/organizations/{$org['id']}/members", ['email' => 'newhire@test.ph'])
            ->assertCreated()->assertJsonPath('status', 'invited');
        // Two seats: one member + one invite.
        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/organizations/{$org['id']}/members", ['email' => 'third@test.ph'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $form = ['first_name' => 'New', 'last_name' => 'Hire', 'mobile' => '09171234567', 'region' => PhilippineRegions::NAMES[0],
            'email' => 'newhire@test.ph', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'accept_terms' => true];
        $this->send('POST', '/api/v1/register', $form)->assertCreated()->assertJsonPath('user.subscription.source', 'organization')
            ->assertJsonPath('user.subscription.organization.name', 'Rizal ES');
        $this->assertSame(2, Organization::query()->find($org['id'])->seatsUsed());

        $this->actingAs($admin, 'admin')->deleteJson("/api/v1/admin/organizations/{$org['id']}/members/{$member->id}")->assertOk();
        $this->assertSame('expired', $member->fresh()->subscription_status);
        $this->assertNull($member->fresh()->organization_id);

        // Ending the group plan moves its members off it.
        $this->actingAs($admin, 'admin')->putJson("/api/v1/admin/organizations/{$org['id']}", ['period_end' => now()->addMinute()->toIso8601String()])->assertOk();
        $this->travel(2)->minutes();
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('expired', User::query()->where('email', 'newhire@test.ph')->first()->subscription_status);
    }
}
