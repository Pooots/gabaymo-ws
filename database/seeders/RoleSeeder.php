<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::query()->updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Administrator',
                'description' => 'Full access for the platform admin account.',
                'is_system' => true,
                // Every module, including payments, tickets, access-keys, referral-codes,
                // organizations, feedback and analytics (see AdminPermissions::MODULES).
                'permissions' => AdminPermissions::keys(),
            ],
        );

        // Optional starter roles for common staff jobs; edit them in Roles & permissions.
        Role::query()->firstOrCreate(
            ['slug' => 'billing'],
            [
                'name' => 'Billing',
                'description' => 'Payments, plans, subscriptions, access keys, referral codes and group plans.',
                'is_system' => false,
                'permissions' => ['overview', 'members', 'payments', 'plans', 'subscriptions', 'access-keys', 'referral-codes', 'organizations'],
            ],
        );
        Role::query()->firstOrCreate(
            ['slug' => 'support-desk'],
            [
                'name' => 'Support desk',
                'description' => 'Members, partners and tickets.',
                'is_system' => false,
                'permissions' => ['overview', 'members', 'partners', 'tickets', 'feedback'],
            ],
        );
    }
}
