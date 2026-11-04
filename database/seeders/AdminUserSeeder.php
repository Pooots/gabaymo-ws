<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');
        if (! is_string($password) || $password === '') {
            $password = '123Gabaymo$$$';
        }

        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@gabaymo.com')],
            [
                'name' => env('ADMIN_NAME', 'GabayMo Admin'),
                'role' => 'admin',
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );
    }
}
