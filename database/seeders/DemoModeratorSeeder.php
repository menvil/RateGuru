<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoModeratorSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => 'moderator@rateguru.test'],
            [
                'name' => 'Demo Moderator',
                'username' => 'moderator',
                'avatar_url' => null,
                'role' => UserRole::Moderator,
                'status' => UserStatus::Active,
                'trust_level' => 0,
                'email_verified_at' => now(),
                'password' => Hash::make(env('DEMO_MODERATOR_PASSWORD', 'password')),
            ],
        );
    }
}
