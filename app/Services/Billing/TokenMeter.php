<?php

namespace App\Services\Billing;

use App\Models\AiCreditReservation;
use App\Models\SubscriptionPlan;
use App\Models\TokenUsageEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Metered token usage, modelled on how AI providers bill subscriptions:
 *
 *  - Each plan grants a weighted-token allowance per period (monthly for paid
 *    plans, yearly ones included; once for the free trial). Weighted tokens
 *    price input, cached input and output differently, like the provider does.
 *  - Asking places a hold (pre-authorization) on the allowance so parallel
 *    questions cannot overspend; when gabaymo-ai settles, the hold is replaced
 *    by what the answer really used (finalize) or dropped (release).
 *  - Members see percentages, never raw tokens. The last question may cost
 *    more than what is left; GabayMo absorbs the overrun instead of going negative.
 *  - Extra usage granted by staff is spent after the plan allowance and
 *    carries over between periods.
 *
 *  - Paid allowances only refresh while the paid period (users.current_period_end)
 *    is running; at its end SubscriptionService downgrades the member.
 *
 * Every transition happens under a row lock on the user and is idempotent on
 * the reservation, so retries never double-charge.
 */
class TokenMeter
{
    /** Release reason used by the stale-hold sweeper. */
    public const SWEPT = 'expired_hold';

    /** @return array{ok: bool, reservation: ?AiCreditReservation, reason: ?string} */
    public function reserve(User $user, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $idempotencyKey) {
            $existing = AiCreditReservation::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['ok' => true, 'reservation' => $existing, 'reason' => null];
            }

            $locked = $this->lock($user->id);
            if (app(SubscriptionService::class)->expireIfDue($locked)) {
                $locked->load('subscriptionPlan');
            }
            $plan = $locked->subscriptionPlan;
            if ($plan && ! $plan->active) {
                return ['ok' => false, 'reservation' => null, 'reason' => 'plan_inactive'];
            }
            $this->rollOver($locked);

            $available = $this->available($locked);
            if ($available <= 0) {
                return ['ok' => false, 'reservation' => null, 'reason' => 'usage_limit_reached'];
            }

            $hold = min($available, max(1, (int) config('services.usage.reserve_tokens', 15000)));
            $locked->forceFill(['tokens_reserved' => $locked->tokens_reserved + $hold])->save();
            $reservation = AiCreditReservation::query()->create([
                'id' => 'res_'.Str::lower((string) Str::ulid()),
                'user_id' => $locked->id,
                'amount' => $hold,
                'status' => 'reserved',
                'idempotency_key' => $idempotencyKey,
            ]);

            return ['ok' => true, 'reservation' => $reservation, 'reason' => null];
        });
    }

    /**
     * Replace the hold with the real usage of the answered question.
     *
     * @param  array<string, mixed>  $usage  input_tokens, cached_input_tokens, output_tokens (+ analytics fields)
     */
    public function finalize(string $reservationId, ?string $questionId, array $usage = []): AiCreditReservation
    {
        return DB::transaction(function () use ($reservationId, $questionId, $usage) {
            $reservation = AiCreditReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            // A hold the sweeper dropped (gabaymo-ai was late) is still charged once for
            // the real answer; anything else that is already settled stays as it is.
            $late = $reservation->status === 'released' && $reservation->reason === self::SWEPT;
            if ($reservation->status !== 'reserved' && ! $late) {
                return $reservation;
            }

            $user = $this->lock($reservation->user_id);
            $this->rollOver($user);
            if (! $late) {
                $this->dropHold($user, $reservation->amount);
            }

            $counts = $this->counts($usage);
            // Answers served without a model call report zero and cost nothing; a caller
            // that reports no counts at all is charged the hold.
            $reported = array_key_exists('input_tokens', $usage) || array_key_exists('prompt_tokens', $usage);
            $cost = $reported ? $this->weigh($counts) : (int) $reservation->amount;
            $charged = $this->charge($user, $cost);
            $user->save();

            $reservation->forceFill([
                'status' => 'finalized',
                'charged_tokens' => $charged,
                'ai_question_id' => $questionId,
                'usage' => $usage,
                'settled_at' => now(),
            ])->save();

            $this->log($user, 'usage', -$charged, [
                'input_tokens' => $counts['input'],
                'cached_input_tokens' => $counts['cached_input'],
                'output_tokens' => $counts['output'],
                'reservation_id' => $reservation->id,
                'ai_question_id' => $questionId,
                'meta' => $cost > $charged ? ['absorbed_tokens' => $cost - $charged] : null,
            ]);

            return $reservation;
        });
    }

    /** Drop the hold: the question produced no billable answer. */
    public function release(string $reservationId, ?string $questionId, string $reason): AiCreditReservation
    {
        return DB::transaction(function () use ($reservationId, $questionId, $reason) {
            $reservation = AiCreditReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== 'reserved') {
                return $reservation;
            }

            $user = $this->lock($reservation->user_id);
            $this->dropHold($user, $reservation->amount);
            $user->save();

            $reservation->forceFill([
                'status' => 'released',
                'charged_tokens' => 0,
                'ai_question_id' => $questionId ?? $reservation->ai_question_id,
                'reason' => mb_substr($reason, 0, 120),
                'settled_at' => now(),
            ])->save();

            return $reservation;
        });
    }

    /**
     * Start a fresh period on a plan (sign-up, paid upgrade, group plan, staff change).
     * Unused allowance does not carry over. Paid plans run until $periodEnd
     * (default: one billing interval from now).
     */
    public function startPlan(User $user, ?SubscriptionPlan $plan, ?CarbonInterface $periodEnd = null, ?string $source = null): User
    {
        $start = now();
        $paid = $plan && (int) $plan->price > 0;
        $periodEnd ??= $paid ? SubscriptionService::periodEndFrom($plan, $start) : null;
        $user->forceFill([
            'subscription_plan_id' => $plan?->id,
            'token_allowance' => (int) ($plan?->token_allowance ?? 0),
            'tokens_used' => 0,
            'usage_period_started_at' => $start,
            'usage_period_ends_at' => $plan?->refreshesMonthly() ? $start->copy()->addMonthNoOverflow() : null,
            'subscription_status' => $periodEnd ? 'active' : 'trial',
            'plan_source' => $periodEnd ? ($source ?? 'personal') : 'trial',
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'renewal_reminded_7d_at' => null,
            'renewal_reminded_1d_at' => null,
        ])->save();
        $this->log($user, 'period_start', (int) $user->token_allowance, ['meta' => ['plan' => $plan?->name]]);

        return $user;
    }

    /**
     * The paid period ended without a renewal: back on the free plan, whose
     * one-time trial allowance counts as already used. Extra usage stays.
     * Caller holds the row lock.
     */
    public function endPaidPeriod(User $user, ?SubscriptionPlan $free): User
    {
        $allowance = (int) ($free?->token_allowance ?? 0);
        $user->forceFill([
            'subscription_plan_id' => $free?->id,
            'token_allowance' => $allowance,
            'tokens_used' => $allowance,
            'usage_period_started_at' => now(),
            'usage_period_ends_at' => null,
            'subscription_status' => 'expired',
            'plan_source' => 'trial',
            'current_period_end' => null,
            'cancel_at_period_end' => false,
            'renewal_reminded_7d_at' => null,
            'renewal_reminded_1d_at' => null,
        ])->save();
        $user->setRelation('subscriptionPlan', $free);
        $this->log($user, 'period_start', 0, ['meta' => ['plan' => $free?->name, 'reason' => 'paid_period_ended']]);

        return $user;
    }

    /** Release holds gabaymo-ai never settled (crash, lost callback). Returns how many. */
    public function sweepStale(int $olderThanMinutes): int
    {
        $count = 0;
        AiCreditReservation::query()
            ->where('status', 'reserved')
            ->where('created_at', '<=', now()->subMinutes(max(1, $olderThanMinutes)))
            ->select('id')
            ->chunkById(200, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $count += $this->release($row->id, null, self::SWEPT)->reason === self::SWEPT ? 1 : 0;
                }
            }, 'id');

        return $count;
    }

    /** Staff grant of extra usage, as a percent of the member's plan allowance. */
    public function grantExtra(User $user, int $tokens, ?string $note = null): User
    {
        return DB::transaction(function () use ($user, $tokens, $note) {
            $locked = $this->lock($user->id);
            $locked->forceFill(['bonus_tokens' => $locked->bonus_tokens + $tokens])->save();
            $this->log($locked, 'extra_grant', $tokens, ['meta' => array_filter(['note' => $note])]);

            return $locked;
        });
    }

    /** Refresh the allowance for members whose monthly period ended. Safe to run any time. */
    public function rollOverDue(): int
    {
        $count = 0;
        User::query()->customers()->whereNotNull('usage_period_ends_at')->where('usage_period_ends_at', '<=', now())
            ->select('id')->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, &$count) {
                        $count += $this->rollOver($this->lock($user->id)) ? 1 : 0;
                    });
                }
            });

        return $count;
    }

    /**
     * What the member sees: percentages, status and the reset date. Staff also get raw numbers.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user, bool $withRaw = false): array
    {
        $allowance = (int) $user->token_allowance;
        $used = min((int) $user->tokens_used, $allowance);
        $pending = $this->periodEnded($user) && $this->renews($user);
        if ($pending) {
            $allowance = (int) ($user->subscriptionPlan?->token_allowance ?? $allowance);
            $used = 0;
        }
        $base = max(1, $allowance);
        $percentUsed = $allowance > 0 ? round($used / $base * 100, 1) : 100.0;
        // In-flight holds count as used for the meter, so it never shows room that is not there.
        $percentHeld = $allowance > 0 ? round(min((int) $user->tokens_reserved, max(0, $allowance - $used)) / $base * 100, 1) : 0.0;
        $extraPercent = $allowance > 0 ? round((int) $user->bonus_tokens / $base * 100, 1) : 0.0;
        $exhausted = $this->available($user, $pending) <= 0;

        $status = match (true) {
            $exhausted => 'exhausted',
            $percentUsed >= (float) config('services.usage.critical_percent', 90) => 'critical',
            $percentUsed >= (float) config('services.usage.warning_percent', 75) => 'warning',
            default => 'ok',
        };

        $endsAt = $pending ? $this->nextEnd($user->usage_period_ends_at) : $user->usage_period_ends_at;
        $snapshot = [
            'percent_used' => min(100.0, $percentUsed),
            'percent_remaining' => max(0.0, round(100 - $percentUsed, 1)),
            'percent_in_progress' => $percentHeld,
            'extra_percent' => $extraPercent,
            'status' => $status,
            'refreshes' => $endsAt !== null,
            'resets_at' => $endsAt?->toIso8601String(),
            'period_started_at' => ($pending ? $user->usage_period_ends_at : $user->usage_period_started_at)?->toIso8601String(),
        ];

        if ($withRaw) {
            $snapshot['raw'] = [
                'allowance' => $allowance,
                'used' => $used,
                'reserved' => (int) $user->tokens_reserved,
                'extra' => (int) $user->bonus_tokens,
                'available' => max(0, $this->available($user, $pending)),
            ];
        }

        return $snapshot;
    }

    /** Weighted tokens for raw counts, using the configured provider price ratios. */
    public function weigh(array $counts): int
    {
        $w = (array) config('services.usage.weights');
        $uncached = max(0, $counts['input'] - $counts['cached_input']);

        return (int) ceil($uncached * (float) ($w['input'] ?? 1)
            + $counts['cached_input'] * (float) ($w['cached_input'] ?? 0.5)
            + $counts['output'] * (float) ($w['output'] ?? 4));
    }

    /** Percent of a plan allowance a number of weighted tokens represents. */
    public function percentOf(int $tokens, int $allowance): float
    {
        return $allowance > 0 ? round($tokens / $allowance * 100, 3) : 0.0;
    }

    /** The user row, locked for the current transaction, with its plan. */
    public function lock(int $userId): User
    {
        return User::query()->whereKey($userId)->lockForUpdate()->with('subscriptionPlan')->firstOrFail();
    }

    private function available(User $user, bool $periodEnded = false): int
    {
        $allowance = $periodEnded ? (int) ($user->subscriptionPlan?->token_allowance ?? $user->token_allowance) : (int) $user->token_allowance;
        $used = $periodEnded ? 0 : (int) $user->tokens_used;

        return max(0, $allowance - $used) + (int) $user->bonus_tokens - (int) $user->tokens_reserved;
    }

    /** Take from the period allowance first, then from extra usage. Returns what was charged. */
    private function charge(User $user, int $cost): int
    {
        $fromPeriod = min($cost, max(0, (int) $user->token_allowance - (int) $user->tokens_used));
        $fromExtra = min($cost - $fromPeriod, (int) $user->bonus_tokens);
        $user->forceFill([
            'tokens_used' => (int) $user->tokens_used + $fromPeriod,
            'bonus_tokens' => (int) $user->bonus_tokens - $fromExtra,
        ]);

        return $fromPeriod + $fromExtra;
    }

    private function dropHold(User $user, int $amount): void
    {
        $user->forceFill(['tokens_reserved' => max(0, (int) $user->tokens_reserved - $amount)]);
    }

    private function periodEnded(User $user): bool
    {
        return $user->usage_period_ends_at !== null && $user->usage_period_ends_at->lte(now());
    }

    /**
     * Paid allowances refresh only inside the paid period: the next usage
     * period must start before current_period_end. Free monthly plans always do.
     */
    private function renews(User $user): bool
    {
        $plan = $user->subscriptionPlan;
        if (! $plan || (int) $plan->price <= 0) {
            return true;
        }
        $end = $user->current_period_end;

        return $end !== null && $user->usage_period_ends_at !== null && $end->gt($user->usage_period_ends_at);
    }

    /** Monthly refresh, anchored to the day the member subscribed. Caller holds the row lock. */
    private function rollOver(User $user): bool
    {
        if (! $this->periodEnded($user) || ! $this->renews($user)) {
            return false;
        }
        $plan = $user->subscriptionPlan;
        $start = $user->usage_period_ends_at;
        $end = $this->nextEnd($start);
        $user->forceFill([
            'token_allowance' => (int) ($plan?->token_allowance ?? $user->token_allowance),
            'tokens_used' => 0,
            'usage_period_started_at' => $end->copy()->subMonthNoOverflow(),
            'usage_period_ends_at' => $plan && ! $plan->refreshesMonthly() ? null : $end,
        ])->save();
        $this->log($user, 'period_start', (int) $user->token_allowance, ['meta' => ['plan' => $plan?->name, 'previous_end' => $start->toIso8601String()]]);

        return true;
    }

    private function nextEnd(?CarbonInterface $end): ?CarbonInterface
    {
        if ($end === null) {
            return null;
        }
        $next = $end->copy();
        while ($next->lte(now())) {
            $next = $next->addMonthNoOverflow();
        }

        return $next;
    }

    /** @return array{input: int, cached_input: int, output: int} */
    private function counts(array $usage): array
    {
        $input = max(0, (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0));

        return [
            'input' => $input,
            'cached_input' => min($input, max(0, (int) ($usage['cached_input_tokens'] ?? 0))),
            'output' => max(0, (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0)),
        ];
    }

    private function log(User $user, string $type, int $tokens, array $attributes = []): void
    {
        $base = (int) ($user->token_allowance ?: $user->subscriptionPlan?->token_allowance ?: 0);
        TokenUsageEvent::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'tokens' => $tokens,
            'percent' => $this->percentOf(abs($tokens), $base),
            ...array_filter($attributes, fn ($v) => $v !== null),
        ]);
    }
}
