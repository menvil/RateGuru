<?php

namespace Database\Seeders;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->users() as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'username' => $user['username'],
                    'avatar_url' => null,
                    'role' => UserRole::User,
                    'status' => $user['status'],
                    'trust_level' => $user['trust_level'],
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                ],
            );
        }
    }

    /**
     * @return list<array{name: string, username: string, email: string, status: UserStatus, trust_level: int}>
     */
    private function users(): array
    {
        return [
            [
                'name' => 'Alice Demo',
                'username' => 'alice',
                'email' => 'alice@rateguru.test',
                'status' => UserStatus::Active,
                'trust_level' => 0,
            ],
            [
                'name' => 'Bob Demo',
                'username' => 'bob',
                'email' => 'bob@rateguru.test',
                'status' => UserStatus::Active,
                'trust_level' => 0,
            ],
            [
                'name' => 'Carla Demo',
                'username' => 'carla',
                'email' => 'carla@rateguru.test',
                'status' => UserStatus::Active,
                'trust_level' => 0,
            ],
            [
                'name' => 'Trusted Demo',
                'username' => 'trusted',
                'email' => 'trusted@rateguru.test',
                'status' => UserStatus::Active,
                'trust_level' => MarkUserTrustedAction::TRUSTED_LEVEL,
            ],
            [
                'name' => 'Banned Demo',
                'username' => 'banned',
                'email' => 'banned@rateguru.test',
                'status' => UserStatus::Banned,
                'trust_level' => 0,
            ],
            [
                'name' => 'Shadow Demo',
                'username' => 'shadow',
                'email' => 'shadow@rateguru.test',
                'status' => UserStatus::Shadowbanned,
                'trust_level' => 0,
            ],
        ];
    }
}
