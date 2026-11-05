<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One sign-in per account type, for local development only.
 *
 * Every account uses the password "password".
 *
 *   Customer portal (/login)
 *     customer@gabaymo.test    member on the Free Trial
 *     subscriber@gabaymo.test  member on the Monthly Membership
 *   Partner portal (/login → /partner)
 *     partner@gabaymo.test     active partner lawyer
 *   Admin portal (/admin/login), admin_users table
 *     admin@gabaymo.test       Administrator (all modules)
 *     support@gabaymo.test     Support staff (limited modules)
 *
 * Refuses to run outside APP_ENV=local or testing.
 */
class LocalUserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalUserSeeder skipped: only runs when APP_ENV is local or testing.');

            return;
        }

        $region = 'National Capital Region (NCR)';
        $free = SubscriptionPlan::query()->where('price', 0)->orderBy('sort_order')->first();
        $monthly = SubscriptionPlan::query()->where('cadence', 'month')->orderBy('sort_order')->first();

        $this->user('customer@gabaymo.test', [
            'name' => 'Carla Customer',
            'first_name' => 'Carla',
            'last_name' => 'Customer',
            'school_name' => 'Rizal Elementary School',
            'mobile' => '09170000001',
            'region' => $region,
            'account_type' => User::CUSTOMER,
            'subscription_plan_id' => $free?->id,
            'tokens_remaining' => $free?->tokens ?? 0,
        ]);

        $this->user('subscriber@gabaymo.test', [
            'name' => 'Sam Subscriber',
            'first_name' => 'Sam',
            'last_name' => 'Subscriber',
            'school_name' => 'Mabini National High School',
            'mobile' => '09170000002',
            'region' => $region,
            'account_type' => User::CUSTOMER,
            'subscription_plan_id' => $monthly?->id,
            'tokens_remaining' => $monthly?->tokens ?? 0,
        ]);

        $this->user('partner@gabaymo.test', [
            'name' => 'Atty. Paolo Partner',
            'first_name' => 'Paolo',
            'last_name' => 'Partner',
            'mobile' => '09170000003',
            'region' => $region,
            'specialty' => 'Education law',
            'response_hours' => 24,
            'partner_status' => 'active',
            'account_type' => User::PARTNER,
        ]);

        // Administrator role comes from RoleSeeder.
        $this->staff('admin@gabaymo.test', 'Local Admin', Role::query()->where('slug', 'admin')->firstOrFail());

        $support = Role::query()->updateOrCreate(
            ['slug' => 'support'],
            [
                'name' => 'Support',
                'description' => 'Local test role with a limited set of modules.',
                'is_system' => false,
                'permissions' => ['overview', 'members', 'partners', 'tickets', 'knowledge-library'],
            ],
        );

        $this->staff('support@gabaymo.test', 'Sofia Support', $support);

        $this->command?->info('Local test users ready. Password for all: '.self::PASSWORD);
    }

    private function user(string $email, array $attributes): void
    {
        User::query()->updateOrCreate(
            ['email' => $email],
            [...$attributes, 'password' => self::PASSWORD, 'email_verified_at' => now()],
        );
    }

    private function staff(string $email, string $name, Role $role): void
    {
        AdminUser::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'role_id' => $role->id, 'password' => self::PASSWORD],
        );
    }
}
