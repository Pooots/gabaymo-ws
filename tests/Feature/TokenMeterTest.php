<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\TokenUsageEvent;
use App\Models\User;
use App\Services\Billing\TokenMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Metered token usage: holds, true-up, overrun, extra usage and monthly refresh. */
class TokenMeterTest extends TestCase
{
    use RefreshDatabase;

    private TokenMeter $meter;

    protected function beforeRefreshingDatabase()
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'session.driver' => 'array']);
        DB::purge();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.usage.reserve_tokens' => 15_000, 'services.usage.weights' => ['input' => 1, 'cached_input' => 0.5, 'output' => 4]]);
        $this->meter = app(TokenMeter::class);
    }

    private function plan(int $allowance, string $period = 'month'): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create([
            'name' => 'Plan '.uniqid(), 'price' => 499, 'cadence' => $period === 'once' ? 'forever' : 'month',
            'token_allowance' => $allowance, 'allowance_period' => $period, 'summary' => 's', 'specs' => ['x'],
        ]);
    }

    private function member(?SubscriptionPlan $plan): User
    {
        $user = User::query()->forceCreate(['name' => 'Ana Cruz', 'email' => 'ana'.uniqid().'@test.ph', 'password' => 'secret123', 'account_type' => 'customer']);

        return $this->meter->startPlan($user, $plan);
    }

    /** 6,900 weighted tokens. */
    private function usage(): array
    {
        return ['input_tokens' => 5000, 'cached_input_tokens' => 1000, 'output_tokens' => 600];
    }

    public function test_starting_a_plan_grants_the_allowance_for_a_month(): void
    {
        $user = $this->member($this->plan(3_000_000));

        $this->assertSame([3_000_000, 0], [$user->token_allowance, $user->tokens_used]);
        $this->assertTrue($user->usage_period_ends_at->isSameDay(now()->addMonthNoOverflow()));
        $this->assertSame('period_start', TokenUsageEvent::query()->value('type'));
        $this->assertSame(['ok', 0.0, 100.0], [
            $this->meter->snapshot($user)['status'], $this->meter->snapshot($user)['percent_used'], $this->meter->snapshot($user)['percent_remaining'],
        ]);
    }

    public function test_parallel_questions_cannot_spend_more_than_is_left(): void
    {
        $user = $this->member($this->plan(20_000));

        $first = $this->meter->reserve($user, 'a');
        $second = $this->meter->reserve($user, 'b');
        $third = $this->meter->reserve($user, 'c');

        $this->assertSame([15_000, 5_000], [$first['reservation']->amount, $second['reservation']->amount]);
        $this->assertFalse($third['ok']);
        $this->assertSame('usage_limit_reached', $third['reason']);
    }

    public function test_last_question_overrun_is_absorbed_not_charged_below_zero(): void
    {
        $plan = $this->plan(10_000);
        $user = $this->member($plan);
        $user->forceFill(['tokens_used' => 9_000])->save();

        $reservation = $this->meter->reserve($user, 'a')['reservation'];
        $this->assertSame(1_000, $reservation->amount);
        $this->meter->finalize($reservation->id, null, $this->usage());

        $fresh = $user->fresh('subscriptionPlan');
        $this->assertSame([10_000, 0], [$fresh->tokens_used, $fresh->tokens_reserved]);
        $this->assertSame(1_000, $reservation->fresh()->charged_tokens);
        $this->assertSame(5_900, TokenUsageEvent::query()->where('type', 'usage')->value('meta')['absorbed_tokens']);
        $snapshot = $this->meter->snapshot($fresh);
        $this->assertSame([100.0, 'exhausted'], [$snapshot['percent_used'], $snapshot['status']]);
    }

    public function test_extra_usage_is_spent_after_the_plan_allowance(): void
    {
        $user = $this->member($this->plan(10_000));
        $user->forceFill(['tokens_used' => 8_000])->save();
        $this->meter->grantExtra($user, 5_000);

        $reservation = $this->meter->reserve($user->fresh(), 'a')['reservation'];
        $this->meter->finalize($reservation->id, null, $this->usage());

        $fresh = $user->fresh();
        $this->assertSame([10_000, 100], [$fresh->tokens_used, $fresh->bonus_tokens]);
    }

    public function test_answers_without_a_model_call_cost_nothing_and_unreported_usage_costs_the_hold(): void
    {
        $user = $this->member($this->plan(100_000));

        $free = $this->meter->reserve($user, 'a')['reservation'];
        $this->meter->finalize($free->id, null, ['input_tokens' => 0, 'output_tokens' => 0]);
        $this->assertSame(0, $user->fresh()->tokens_used);

        $legacy = $this->meter->reserve($user->fresh(), 'b')['reservation'];
        $this->meter->finalize($legacy->id, null, []);
        $this->assertSame(15_000, $user->fresh()->tokens_used);
    }

    public function test_monthly_plan_refreshes_on_its_anniversary_and_trial_does_not(): void
    {
        $monthly = $this->member($this->plan(50_000));
        $monthly->forceFill(['tokens_used' => 50_000, 'usage_period_ends_at' => now()->subDay()])->save();
        $trial = $this->member($this->plan(50_000, 'once'));
        $trial->forceFill(['tokens_used' => 50_000])->save();

        // The meter already shows the new period before any job runs.
        $this->assertSame(0.0, $this->meter->snapshot($monthly->fresh('subscriptionPlan'))['percent_used']);

        $this->assertTrue($this->meter->reserve($monthly->fresh(), 'a')['ok']);
        $this->assertFalse($this->meter->reserve($trial->fresh(), 'b')['ok']);

        $fresh = $monthly->fresh();
        $this->assertSame(0, $fresh->tokens_used);
        $this->assertTrue($fresh->usage_period_ends_at->isFuture());
        $this->assertNull($trial->fresh()->usage_period_ends_at);
    }

    public function test_scheduled_refresh_rolls_over_due_members(): void
    {
        $user = $this->member($this->plan(50_000));
        $user->forceFill(['tokens_used' => 20_000, 'usage_period_ends_at' => now()->subHour()])->save();

        $this->assertSame(1, $this->meter->rollOverDue());
        $this->assertSame(0, $user->fresh()->tokens_used);
        $this->assertSame(0, $this->meter->rollOverDue());
    }

    public function test_staff_grant_extra_usage_as_a_percent_and_see_raw_numbers(): void
    {
        $user = $this->member($this->plan(200_000));
        $role = Role::query()->create(['name' => 'Admin', 'slug' => 'admin-'.uniqid(), 'is_system' => true, 'permissions' => ['*']]);
        $admin = AdminUser::query()->forceCreate(['name' => 'Staff', 'email' => 's'.uniqid().'@test.ph', 'role_id' => $role->id, 'password' => 'secret123']);

        $this->actingAs($admin, 'admin')->postJson("/api/v1/admin/members/{$user->id}/tokens", ['percent' => 25])
            ->assertOk()
            ->assertJsonPath('member.usage.extra_percent', 25)
            ->assertJsonPath('member.usage.raw.extra', 50_000);

        $this->actingAs($admin, 'admin')->getJson("/api/v1/admin/members/{$user->id}/usage")
            ->assertOk()->assertJsonPath('events.0.type', 'extra_grant')->assertJsonPath('events.0.tokens', 50_000);
    }

    public function test_members_see_percentages_only(): void
    {
        $user = $this->member($this->plan(100_000));
        $reservation = $this->meter->reserve($user, 'a')['reservation'];
        $this->meter->finalize($reservation->id, '9b2f7a3e-1111-4c4c-8888-000000000009', $this->usage());

        $this->actingAs($user->fresh(), 'web')->getJson('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('usage.percent_used', 6.9)
            ->assertJsonPath('usage.percent_remaining', 93.1)
            ->assertJsonMissingPath('usage.raw')
            ->assertJsonPath('events.0.type', 'usage')
            ->assertJsonPath('events.0.percent', 6.9)
            ->assertJsonMissingPath('events.0.tokens');
    }
}
