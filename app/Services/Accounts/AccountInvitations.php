<?php

namespace App\Services\Accounts;

use App\Mail\AccountInvitationMail;
use App\Models\AccountInvitation;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emailed links that let a partner or staff member set their own password:
 * the first set-up of an account staff created, or a password link staff
 * sent later ("reset password"). Issuing a link revokes the older ones.
 */
class AccountInvitations
{
    /**
     * @return array{invitation: AccountInvitation, token: string, url: string}
     */
    public function issue(User|AdminUser $account, ?AdminUser $invitedBy = null, string $purpose = AccountInvitation::SETUP): array
    {
        $type = self::typeOf($account);
        // An account that never finished set-up always gets the full set-up form.
        if ($account->isInvited()) {
            $purpose = AccountInvitation::SETUP;
        }

        $this->revokeOpen($type, (int) $account->id);

        $token = Str::random(64);
        $invitation = AccountInvitation::query()->create([
            'token_hash' => AccountInvitation::hashToken($token),
            'account_type' => $type,
            'account_id' => $account->id,
            'purpose' => $purpose,
            'expires_at' => now()->addHours(self::expiresHours()),
            'invited_by' => $invitedBy?->id,
        ]);

        $url = $this->url($type, $token, (string) $account->email);

        try {
            Mail::to($account->email)->queue(new AccountInvitationMail(
                name: (string) $account->name,
                url: $url,
                role: self::roleLabel($account),
                invitedBy: $invitedBy?->name,
                expiresAt: $invitation->expires_at->copy(),
                purpose: $purpose,
            ));
        } catch (Throwable $e) {
            // The link still exists; staff can resend it once mail works.
            report($e);
        }

        return ['invitation' => $invitation, 'token' => $token, 'url' => $url];
    }

    /** A usable invitation for this token and email, or null (unknown, expired, used, revoked or wrong email). */
    public function find(string $token, ?string $email): ?AccountInvitation
    {
        if ($token === '' || ! is_string($email) || $email === '') {
            return null;
        }
        $invitation = AccountInvitation::query()->where('token_hash', AccountInvitation::hashToken($token))->first();
        if (! $invitation || ! $invitation->isUsable()) {
            return null;
        }
        $account = $invitation->account();
        if (! $account || ! hash_equals(mb_strtolower((string) $account->email), mb_strtolower(trim($email)))) {
            return null;
        }
        $invitation->setRelation('resolvedAccount', $account);

        return $invitation;
    }

    public function revokeOpen(string $type, int $accountId): void
    {
        AccountInvitation::query()
            ->where('account_type', $type)
            ->where('account_id', $accountId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** {FRONTEND_URL|PARTNER_URL}/setup-account?token=&email= */
    public function url(string $type, string $token, string $email): string
    {
        $base = $type === AccountInvitation::PARTNER ? config('services.partner_url') : config('services.frontend_url');

        return rtrim((string) $base, '/').'/setup-account?'.http_build_query(['token' => $token, 'email' => $email]);
    }

    public static function typeOf(User|AdminUser $account): string
    {
        return $account instanceof AdminUser ? AccountInvitation::ADMIN : AccountInvitation::PARTNER;
    }

    public static function roleLabel(User|AdminUser $account): string
    {
        return $account instanceof AdminUser ? ($account->role?->name ?? 'Staff') : 'Partner lawyer';
    }

    public static function expiresHours(): int
    {
        return max(1, (int) config('services.invitations.expires_hours', 72));
    }
}
