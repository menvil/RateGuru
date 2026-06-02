<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUserCommand extends Command
{
    protected $signature = 'rateguru:admin:create
        {--email= : Admin email}
        {--username= : Admin username}
        {--name= : Admin display name}';

    protected $description = 'Create a production admin user.';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        $username = trim((string) $this->option('username'));
        $name = trim((string) $this->option('name')) ?: $username;

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email is required.');

            return self::FAILURE;
        }

        if ($username === '') {
            $this->error('--username is required.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->error('A user with this username already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        if ($this->secret('Confirm password') !== $password) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        try {
            User::create([
                'email' => $email,
                'username' => $username,
                'name' => $name,
                'password' => Hash::make($password),
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->error('A user with this email or username already exists.');

            return self::FAILURE;
        }

        $this->info('Admin user created.');

        return self::SUCCESS;
    }
}
