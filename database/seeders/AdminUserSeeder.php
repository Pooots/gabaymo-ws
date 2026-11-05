<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The platform administrator (admin_users table). Runs after RoleSeeder.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');
        if (! is_string($password) || $password === '') {
            $password = '123Gabaymo$$$';
        }

        $role = Role::query()->where('slug', 'admin')->firstOrFail();

        AdminUser::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@gabaymo.com')],
            [
                'name' => env('ADMIN_NAME', 'GabayMo Admin'),
                'role_id' => $role->id,
                'password' => $password,
            ],
        );
    }
}
