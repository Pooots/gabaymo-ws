<?php

namespace App\Services\Billing;

use App\Models\AccessKey;
use App\Models\AccessKeyRedemption;
use App\Models\AuditLog;
use App\Models\ReferralCode;
use App\Models\ReferralRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Access keys (staff-issued) and referral codes (shared at sign-up). */
class CodeRedemption
{
    public function __construct(private readonly TokenMeter $meter, private readonly SubscriptionService $subscriptions) {}

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** GM-XXXX-XXXX-XXXX from an unambiguous alphabet. */
    public static function generate(string $prefix = 'GM'): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }

        return $prefix.'-'.implode('-', $groups);
    }

    /** @return array{key: AccessKey, user: User} */
    public function redeemAccessKey(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code) {
            $key = AccessKey::query()->with('plan')->where('code', self::normalize($code))->lockForUpdate()->first();
            if (! $key || ! $key->usable()) {
                throw ValidationException::withMessages(['code' => 'This access key is not valid or has already been used.']);
            }
            if (AccessKeyRedemption::query()->where('access_key_id', $key->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'You already redeemed this access key.']);
            }

            if ($key->plan) {
                $locked = $this->meter->lock($user->id);
                $from = ((int) $locked->subscription_plan_id === (int) $key->plan->id && $locked->current_period_end?->isFuture())
                    ? $locked->current_period_end->copy()
                    : now();
                $end = $key->duration_days ? $from->addDays($key->duration_days) : SubscriptionService::periodEndFrom($key->plan, $from);
                $this->subscriptions->assign($locked, $key->plan, $end, 'access_key');
            }

            if ($key->extra_usage_percent) {
                $fresh = $user->fresh('subscriptionPlan');
                $base = (int) ($fresh->token_allowance ?: $fresh->subscriptionPlan?->token_allowance ?: 0);
                if ($base > 0) {
                    $this->meter->grantExtra($fresh, (int) round($base * $key->extra_usage_percent / 100), 'Access key '.$key->code);
                }
            }

            AccessKeyRedemption::query()->create(['access_key_id' => $key->id, 'user_id' => $user->id]);
            $key->forceFill(['uses_count' => $key->uses_count + 1])->save();
            AuditLog::record('access_key.redeem', $user->name.' redeemed access key '.$key->code, $user);

            return ['key' => $key, 'user' => $user->fresh('subscriptionPlan')];
        });
    }

    public function findUsableReferral(string $code): ?ReferralCode
    {
        $referral = ReferralCode::query()->where('code', self::normalize($code))->first();

        return $referral && $referral->usable() ? $referral : null;
    }

    /** Sign-up with a referral code: extra usage for the new member and for the code's owner. */
    public function applyReferral(User $user, ReferralCode $referral): void
    {
        DB::transaction(function () use ($user, $referral) {
            $locked = ReferralCode::query()->whereKey($referral->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->usable() || (int) $locked->owner_user_id === (int) $user->id) {
                return;
            }
            ReferralRedemption::query()->create(['referral_code_id' => $locked->id, 'user_id' => $user->id]);
            $locked->forceFill(['uses_count' => $locked->uses_count + 1])->save();

            foreach (array_filter([$user->fresh('subscriptionPlan'), $locked->owner()->with('subscriptionPlan')->first()]) as $member) {
                if (! $member->isCustomer()) {
                    continue;
                }
                $base = (int) ($member->token_allowance ?: $member->subscriptionPlan?->token_allowance ?: 0);
                if ($base > 0) {
                    $this->meter->grantExtra($member, (int) round($base * $locked->reward_extra_usage_percent / 100), 'Referral '.$locked->code);
                }
            }
            AuditLog::record('referral.redeem', $user->name.' signed up with referral code '.$locked->code, $user);
        });
    }

    public static function uniqueCode(string $model, ?string $wanted = null): string
    {
        if ($wanted !== null && $wanted !== '') {
            return self::normalize($wanted);
        }
        do {
            $code = self::generate($model === ReferralCode::class ? 'REF' : 'GM');
        } while ($model::query()->where('code', $code)->exists());

        return $code;
    }
}
