<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Paid periods on top of the token meter.
 *
 * PayMongo checkout is a one-time payment, so a membership is a paid period
 * (users.current_period_end) that each payment extends by the plan's billing
 * interval. When the period ends without a new payment the member moves to
 * their organization's plan (if it is active) or back to the free plan.
 * "Cancel" only means: do not remind me and let it end.
 *
 * Plan sources: personal (paid), organization (group plan), admin (staff
 * change), access_key (redeemed code), trial (free plan).
 */
class SubscriptionService
{
    public function __construct(private readonly TokenMeter $meter) {}

    public static function periodEndFrom(SubscriptionPlan $plan, CarbonInterface $from): CarbonInterface
    {
        return $plan->cadence === 'year' ? $from->copy()->addYearNoOverflow() : $from->copy()->addMonthNoOverflow();
    }

    public function freePlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('active', true)->where('price', 0)->orderBy('sort_order')->orderBy('id')->first()
            ?? SubscriptionPlan::query()->where('price', 0)->orderBy('sort_order')->orderBy('id')->first();
    }

    /** Lock the member, end the period if it is over, and return the fresh row. */
    public function refresh(User $user): User
    {
        if (! $user->isCustomer() || $user->current_period_end === null || $user->current_period_end->isFuture()) {
            return $user;
        }

        return DB::transaction(function () use ($user) {
            $locked = $this->meter->lock($user->id);
            $this->expireIfDue($locked);

            return $locked->fresh('subscriptionPlan');
        });
    }

    /** Caller holds the row lock. True when the member was moved off the ended plan. */
    public function expireIfDue(User $locked): bool
    {
        if (! $locked->isCustomer() || $locked->current_period_end === null || $locked->current_period_end->isFuture()) {
            return false;
        }
        $this->downgrade($locked, 'period_ended');

        return true;
    }

    /** End every paid period that is over. Safe to run any time. */
    public function expireDue(): int
    {
        $count = 0;
        User::query()->customers()->whereNotNull('current_period_end')->where('current_period_end', '<=', now())
            ->select('id')->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, &$count) {
                        $count += $this->expireIfDue($this->meter->lock($user->id)) ? 1 : 0;
                    });
                }
            });

        return $count;
    }

    /** Caller holds the row lock. Organization plan if there is an active one, otherwise the free plan. */
    public function downgrade(User $locked, string $reason): User
    {
        $previous = $locked->subscriptionPlan?->name ?? 'Free Trial';
        $organization = $locked->organization_id ? Organization::query()->with('plan')->find($locked->organization_id) : null;

        if ($organization && $organization->isActive() && $organization->plan) {
            $this->meter->startPlan($locked, $organization->plan, $organization->period_end, 'organization');
            AuditLog::record('subscription.organization', $locked->name.' moved to the '.$organization->name.' group plan ('.$reason.')', $locked);

            return $locked;
        }

        $this->meter->endPaidPeriod($locked, $this->freePlan());
        AuditLog::record('subscription.expired', $locked->name.'\'s '.$previous.' ended ('.$reason.')', $locked);
        app(NotificationService::class)->send(
            $locked,
            UserNotification::SUBSCRIPTION_EXPIRED,
            'Your '.$previous.' has ended',
            'Renew your membership to keep asking GabayMo and to open partner tickets.',
            ['reason' => $reason],
        );

        return $locked;
    }

    /**
     * A confirmed payment. Paying again for the current plan extends the paid
     * period from its end; another plan starts a fresh period now.
     * Caller holds the row lock.
     *
     * @return array{before: ?CarbonInterface, after: CarbonInterface, changed_plan: bool}
     */
    public function applyPayment(User $locked, SubscriptionPlan $plan): array
    {
        $before = $locked->current_period_end;
        $extends = (int) $locked->subscription_plan_id === (int) $plan->id
            && $before !== null && $before->isFuture()
            && $locked->plan_source !== 'organization';

        if ($extends) {
            $after = self::periodEndFrom($plan, $before);
            $locked->forceFill([
                'current_period_end' => $after,
                'subscription_status' => 'active',
                'plan_source' => 'personal',
                'cancel_at_period_end' => false,
                'renewal_reminded_7d_at' => null,
                'renewal_reminded_1d_at' => null,
            ])->save();

            return ['before' => $before, 'after' => $after, 'changed_plan' => false];
        }

        $after = self::periodEndFrom($plan, now());
        $this->meter->startPlan($locked, $plan, $after, 'personal');

        return ['before' => $before, 'after' => $after, 'changed_plan' => true];
    }

    /** Take back the period a refunded payment bought. */
    public function revokePayment(SubscriptionPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            if (! $payment->user_id || ! $payment->period_end_after) {
                return;
            }
            $locked = $this->meter->lock($payment->user_id);
            if ((int) $locked->subscription_plan_id !== (int) $payment->subscription_plan_id || ! $locked->current_period_end) {
                return;
            }

            if ($payment->changed_plan || ! $payment->period_end_before) {
                $end = now()->subSecond();
            } else {
                $bought = $payment->period_end_before->diffInSeconds($payment->period_end_after, true);
                $end = $locked->current_period_end->copy()->subSeconds((int) $bought);
            }
            $locked->forceFill(['current_period_end' => $end])->save();
            $this->expireIfDue($locked);
        });
    }

    /** Staff plan change or an access key. A free plan puts the member on the trial. */
    public function assign(User $user, ?SubscriptionPlan $plan, ?CarbonInterface $periodEnd, string $source): User
    {
        return DB::transaction(function () use ($user, $plan, $periodEnd, $source) {
            $locked = $this->meter->lock($user->id);
            if (! $plan || (int) $plan->price <= 0) {
                return $this->meter->startPlan($locked, $plan ?? $this->freePlan());
            }
            $periodEnd ??= self::periodEndFrom($plan, now());
            $same = (int) $locked->subscription_plan_id === (int) $plan->id && $locked->current_period_end?->isFuture();
            if ($same) {
                $locked->forceFill([
                    'current_period_end' => $periodEnd,
                    'subscription_status' => 'active',
                    'plan_source' => $source,
                    'cancel_at_period_end' => false,
                    'renewal_reminded_7d_at' => null,
                    'renewal_reminded_1d_at' => null,
                ])->save();

                return $locked;
            }

            return $this->meter->startPlan($locked, $plan, $periodEnd, $source);
        });
    }

    public function cancel(User $user): User
    {
        $user->forceFill(['cancel_at_period_end' => true, 'subscription_status' => 'cancelled'])->save();

        return $user;
    }

    public function resume(User $user): User
    {
        $user->forceFill(['cancel_at_period_end' => false, 'subscription_status' => 'active'])->save();

        return $user;
    }

    public function canCancel(User $user): bool
    {
        return $user->current_period_end?->isFuture() === true
            && in_array($user->plan_source, ['personal', 'admin', 'access_key'], true)
            && (int) ($user->subscriptionPlan?->price ?? 0) > 0;
    }

    /** Put a member on an organization's plan (unless their own paid period is still running). */
    public function joinOrganization(User $user, Organization $organization): User
    {
        return DB::transaction(function () use ($user, $organization) {
            $locked = $this->meter->lock($user->id);
            $locked->forceFill(['organization_id' => $organization->id])->save();
            $this->applyOrganization($locked, $organization);

            return $locked;
        });
    }

    public function leaveOrganization(User $user): User
    {
        return DB::transaction(function () use ($user) {
            $locked = $this->meter->lock($user->id);
            $wasGroup = $locked->plan_source === 'organization';
            $locked->forceFill(['organization_id' => null])->save();
            if ($wasGroup) {
                $this->downgrade($locked, 'left_organization');
            }

            return $locked;
        });
    }

    /** After staff change an organization's plan, seats or period. */
    public function syncOrganization(Organization $organization): void
    {
        $organization->loadMissing('plan');
        $organization->members()->select('id')->chunkById(200, function ($members) use ($organization) {
            foreach ($members as $member) {
                DB::transaction(fn () => $this->applyOrganization($this->meter->lock($member->id), $organization));
            }
        });
    }

    /** Registration: claim any pending organization invite for this email. */
    public function claimInvites(User $user): void
    {
        $invite = OrganizationInvite::query()->with('organization')->where('email', mb_strtolower($user->email))->oldest('id')->first();
        if (! $invite || ! $invite->organization) {
            return;
        }
        $organization = $invite->organization;
        OrganizationInvite::query()->where('email', mb_strtolower($user->email))->delete();
        $this->joinOrganization($user, $organization);
        AuditLog::record('organization.join', $user->name.' joined '.$organization->name.' from an invite', $user);
    }

    /** Caller holds the row lock. */
    private function applyOrganization(User $locked, Organization $organization): void
    {
        $plan = $organization->plan;
        if ($locked->plan_source === 'organization') {
            if (! $organization->isActive() || ! $plan) {
                $locked->forceFill(['current_period_end' => now()->subSecond()])->save();
                $this->downgrade($locked, 'organization_ended');

                return;
            }
            if ((int) $locked->subscription_plan_id !== (int) $plan->id) {
                $this->meter->startPlan($locked, $plan, $organization->period_end, 'organization');
            } else {
                $locked->forceFill(['current_period_end' => $organization->period_end, 'subscription_status' => 'active'])->save();
            }

            return;
        }

        // A member's own running paid period comes first; the group plan takes over when it ends.
        $ownActive = $locked->current_period_end?->isFuture() === true && (int) ($locked->subscriptionPlan?->price ?? 0) > 0;
        if (! $ownActive && $organization->isActive() && $plan) {
            $this->meter->startPlan($locked, $plan, $organization->period_end, 'organization');
        }
    }

    /** The `subscription` block of the member profile. */
    public function present(User $user): array
    {
        $plan = $user->subscriptionPlan;

        return [
            'plan' => $plan?->name ?? 'Free Trial',
            'plan_id' => $plan?->id,
            'status' => $user->subscription_status ?: 'trial',
            'current_period_end' => $user->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $user->cancel_at_period_end,
            'source' => $user->plan_source ?: 'trial',
            'organization' => $user->organization_id && $user->organization
                ? ['id' => $user->organization->id, 'name' => $user->organization->name]
                : null,
        ];
    }
}
