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
                'permissions' => AdminPermissions::keys(),
            ],
        );
    }
}
