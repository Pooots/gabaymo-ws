<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data plus exactly one admin and one partner (no members).
     * Every seeder is safe to run again.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SubscriptionPlanSeeder::class,
            ServiceInstructionSeeder::class,
            AccountSeeder::class,
        ]);
    }
}
