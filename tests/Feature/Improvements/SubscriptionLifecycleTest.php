<?php

namespace Tests\Feature\Improvements;

use App\Mail\PaymentReceipt;
use App\Models\Organization;
use App\Models\SubscriptionPayment;
use App\Notifications\RenewalReminderNotification;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TokenMeter;
use Illuminate\Support\Facades\Notification;

/** Paid periods: expiry, downgrade, organization fallback, cancel/resume, reminders. */
class SubscriptionLifecycleTest extends ImprovementTestCase
{
    public function test_paid_usage_stops_refreshing_and_the_member_is_downgraded_at_period_end(): void
    {
        $free = $this->freePlan();
        $plan = $this->paidPlan();
        $user = $this->member([], $plan);
        $this->assertSame('active', $user->subscription_status);

        // One month later, no new payment.
        $this->travel(32)->days();
        $this->assertSame(0, app(TokenMeter::class)->rollOverDue(), 'A paid allowance must not refresh after the paid period.');
        $this->artisan('usage:refresh')->assertSuccessful();

        $user->refresh();
        $this->assertSame($free->id, $user->subscription_plan_id);
        $this->assertSame('expired', $user->subscription_status);
        $this->assertNull($user->current_period_end);
        // The trial counts as used: no fresh trial allowance on downgrade.
        $this->assertFalse(app(TokenMeter::class)->reserve($user, 'k')['ok']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'type' => 'subscription.expired']);
    }

    public function test_yearly_plan_refreshes_monthly_inside_the_paid_year(): void
    {
        $user = $this->member([], $this->paidPlan('year', 4990));
        $user->forceFill(['tokens_used' => 3_000_000])->save();
        $this->travel(32)->days();

        $this->assertSame(1, app(TokenMeter::class)->rollOverDue());
        $this->assertSame(0, $user->fresh()->tokens_used);
        $this->assertSame('active', $user->fresh()->subscription_status);
    }

    public function test_asking_after_the_period_ended_downgrades_first(): void
    {
        $this->freePlan();
        $user = $this->member([], $this->paidPlan());
        $user->forceFill(['current_period_end' => now()->subMinute(), 'usage_period_ends_at' => now()->subMinute()])->save();

        $this->assertFalse(app(TokenMeter::class)->reserve($user, 'k1')['ok']);
        $this->assertSame('expired', $user->fresh()->subscription_status);
    }

    public function test_member_of_an_active_organization_falls_back_to_its_plan(): void
    {
        $orgPlan = $this->paidPlan('year', 9999, 'School');
        $org = Organization::query()->create(['name' => 'Rizal ES', 'type' => 'school', 'seats' => 5, 'subscription_plan_id' => $orgPlan->id, 'period_end' => now()->addYear()]);
        $user = $this->member([], $this->paidPlan());
        app(SubscriptionService::class)->joinOrganization($user, $org);
        $this->assertSame('personal', $user->fresh()->plan_source, 'A running personal period comes first.');

        $user->forceFill(['current_period_end' => now()->subMinute()])->save();
        $this->artisan('subscriptions:expire')->assertSuccessful();

        $user->refresh();
        $this->assertSame($orgPlan->id, $user->subscription_plan_id);
        $this->assertSame('organization', $user->plan_source);
        $this->assertSame('active', $user->subscription_status);
    }

    public function test_cancel_and_resume(): void
    {
        $user = $this->member([], $this->paidPlan());

        $this->actingAs($user, 'web')->postJson('/api/v1/subscription/cancel')->assertOk()
            ->assertJsonPath('subscription.status', 'cancelled')->assertJsonPath('subscription.cancel_at_period_end', true);
        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('user.subscription.status', 'cancelled')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonMissingPath('user.must_change_password');
        $this->actingAs($user->fresh(), 'web')->postJson('/api/v1/subscription/resume')->assertOk()->assertJsonPath('subscription.status', 'active');

        $trial = $this->member();
        $this->actingAs($trial, 'web')->postJson('/api/v1/subscription/cancel')->assertStatus(422);
    }

    public function test_cancelled_membership_ends_without_renewal_reminders(): void
    {
        Notification::fake();
        $this->freePlan();
        $active = $this->member([], $this->paidPlan());
        $cancelled = $this->member([], $this->paidPlan());
        app(SubscriptionService::class)->cancel($cancelled);
        foreach ([$active, $cancelled] as $u) {
            $u->forceFill(['current_period_end' => now()->addDays(6)])->save();
        }

        $this->artisan('subscriptions:remind')->assertSuccessful();
        $this->artisan('subscriptions:remind')->assertSuccessful();
        Notification::assertSentToTimes($active, RenewalReminderNotification::class, 1);
        Notification::assertNotSentTo($cancelled, RenewalReminderNotification::class);

        $active->forceFill(['current_period_end' => now()->addHours(20)])->save();
        $this->artisan('subscriptions:remind')->assertSuccessful();
        Notification::assertSentToTimes($active, RenewalReminderNotification::class, 2);

        $this->travel(7)->days();
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('expired', $cancelled->fresh()->subscription_status);
    }

    public function test_admin_plan_change_sets_the_period(): void
    {
        $plan = $this->paidPlan();
        $user = $this->member();
        $admin = $this->staff(['members']);
        $end = now()->addDays(45)->startOfDay();

        $this->actingAs($admin, 'admin')->putJson("/api/v1/admin/members/{$user->id}/plan", ['subscription_plan_id' => $plan->id, 'period_end' => $end->toIso8601String()])
            ->assertOk()->assertJsonPath('member.subscription.source', 'admin');
        $this->assertTrue($user->fresh()->current_period_end->equalTo($end));
    }

    public function test_receipt_email_renders(): void
    {
        $plan = $this->paidPlan();
        $user = $this->member([], $plan);
        $payment = SubscriptionPayment::query()->create(['user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'reference' => 'GMX1', 'amount' => 499, 'currency' => 'PHP', 'status' => 'paid', 'paid_at' => now()]);
        $payment->forceFill(['receipt_number' => 'GM-OR-202611-000001', 'period_end_after' => now()->addMonth()])->save();

        $html = (new PaymentReceipt($payment))->render();
        $this->assertStringContainsString('GM-OR-202611-000001', $html);
        $this->assertStringContainsString('₱499.00', $html);
    }
}
