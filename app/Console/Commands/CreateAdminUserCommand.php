<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $envPassword = getenv('ADMIN_PASSWORD');
        $password = is_string($envPassword) && $envPassword !== ''
            ? $envPassword
            : $this->secret('Password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $confirmation = is_string($envPassword) && $envPassword !== ''
            ? $password
            : $this->secret('Confirm password');

        if ($password !== $confirmation) {
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
        } catch (UniqueConstraintViolationException) {
            $this->error('A user with this email or username already exists.');

            return self::FAILURE;
        }

        $this->info('Admin user created.');

        return self::SUCCESS;
    }
}
