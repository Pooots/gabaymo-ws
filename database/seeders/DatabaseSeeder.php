<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            SubscriptionPlanSeeder::class,
            ServiceInstructionSeeder::class,
        ]);

        // Test accounts for every portal. Never seeded in production.
        if (app()->environment(['local', 'testing'])) {
            $this->call(LocalUserSeeder::class);
        }
    }
}
