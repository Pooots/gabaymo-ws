<?php

namespace Database\Seeders;

use App\Models\AccountInvitation;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\AccountInvitations;
use Illuminate\Database\Seeder;

/**
 * Exactly one administrator (system "admin" role, every module) and one
 * partner. No member accounts. Runs after RoleSeeder.
 *
 * Emails / names: SEED_ADMIN_EMAIL, SEED_ADMIN_NAME, SEED_PARTNER_EMAIL,
 * SEED_PARTNER_NAME (config/services.php "seed").
 *
 * Without SEED_*_PASSWORD the account has no password: it is "invited", a
 * set-up link is emailed and printed here (handy with MAIL_MAILER=log).
 * With a password it is active straight away and no link is sent.
 *
 * Safe to run again: existing accounts are found by email. An account that
 * is still invited gets a fresh link (the previous one stops working); a set
 * up account is left alone.
 */
class AccountSeeder extends Seeder
{
    public function run(AccountInvitations $invitations): void
    {
        $seed = (array) config('services.seed');
        $role = Role::query()->where('slug', 'admin')->firstOrFail();

        $admin = AdminUser::query()->firstOrCreate(
            ['email' => (string) ($seed['admin_email'] ?? 'admin@gabaymo.local')],
            ['name' => (string) ($seed['admin_name'] ?? 'GabayMo Admin'), 'role_id' => $role->id],
        );
        if ($admin->wasRecentlyCreated) {
            $admin->forceFill(['account_status' => AccountInvitation::STATUS_INVITED])->save();
        }
        $this->prepare($invitations, $admin->load('role'), $seed['admin_password'] ?? null, 'Admin');

        $partner = User::query()->withTrashed()->firstOrCreate(
            ['email' => (string) ($seed['partner_email'] ?? 'partner@gabaymo.local')],
            ['name' => (string) ($seed['partner_name'] ?? 'GabayMo Partner')],
        );
        if ($partner->wasRecentlyCreated) {
            $partner->forceFill([
                'account_type' => User::PARTNER,
                'account_status' => AccountInvitation::STATUS_INVITED,
                'partner_status' => 'active',
                'practice_areas' => [],
            ])->save();
        }
        $this->prepare($invitations, $partner, $seed['partner_password'] ?? null, 'Partner');
    }

    private function prepare(AccountInvitations $invitations, User|AdminUser $account, mixed $password, string $label): void
    {
        if (! $account->isInvited()) {
            $this->command?->line("{$label} {$account->email} is already set up; left unchanged.");

            return;
        }

        if (is_string($password) && $password !== '') {
            $account->forceFill([
                'password' => $password,
                'account_status' => AccountInvitation::STATUS_ACTIVE,
                ...($account instanceof User ? ['email_verified_at' => now()] : []),
            ])->save();
            $invitations->revokeOpen(AccountInvitations::typeOf($account), (int) $account->id);
            $this->command?->info("{$label} {$account->email} is active with the password from SEED_".strtoupper($label).'_PASSWORD.');

            return;
        }

        $url = $invitations->issue($account)['url'];
        $this->command?->info("{$label} {$account->email} is invited. Set-up link (expires in ".AccountInvitations::expiresHours().'h):');
        $this->command?->line('  '.$url);
    }
}
