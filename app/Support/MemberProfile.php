<?php

namespace App\Support;

use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TokenMeter;

/** The `user` payload of /login, /session and /me for members and partners. */
class MemberProfile
{
    public static function present(User $user): array
    {
        $user->loadMissing('subscriptionPlan', 'organization');

        return [
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'mobile' => $user->mobile,
            'region' => $user->region,
            'specialty' => $user->specialty,
            'account_type' => $user->account_type,
            'response_hours' => $user->response_hours,
            'practice_areas' => $user->isPartner() ? array_values((array) ($user->practice_areas ?? [])) : null,
            'title' => $user->isPartner() ? $user->title : null,
            'bio' => $user->isPartner() ? $user->bio : null,
            'photo_url' => $user->isPartner() ? $user->photo_url : null,
            'issuance_alerts' => (bool) ($user->issuance_alerts ?? true),
            'plan' => $user->subscriptionPlan?->name ?? 'Free Trial',
            'plan_price' => (int) ($user->subscriptionPlan?->price ?? 0),
            'plan_cadence' => $user->subscriptionPlan?->cadence,
            'subscription' => $user->isCustomer() ? app(SubscriptionService::class)->present($user) : null,
            'usage' => $user->isCustomer() ? app(TokenMeter::class)->snapshot($user) : null,
        ];
    }

    /** "ana@school.ph" -> "a***@school.ph", for audit lines about unknown accounts. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public static function mobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '63')) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }
}
