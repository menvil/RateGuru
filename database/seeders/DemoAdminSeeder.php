<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => 'admin@rateguru.test'],
            [
                'name' => 'Demo Admin',
                'username' => 'admin',
                'avatar_url' => null,
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
                'trust_level' => 0,
                'email_verified_at' => now(),
                'password' => Hash::make(env('DEMO_ADMIN_PASSWORD', 'password')),
            ],
        );
    }
}
