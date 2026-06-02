<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

afterEach(function () {
    putenv('ADMIN_PASSWORD');
});

it('has admin user creation command', function () {
    putenv('ADMIN_PASSWORD=secret-password');

    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
    ])->assertExitCode(0);
});

it('creates admin user from command options', function () {
    putenv('ADMIN_PASSWORD=secret-password');

    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
    ])
        ->expectsOutput('Admin user created.')
        ->assertExitCode(0);

    $admin = User::where('email', 'admin@example.test')->firstOrFail();

    expect($admin->username)->toBe('admin');
    expect($admin->name)->toBe('Admin User');
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->status)->toBe(UserStatus::Active);
    expect(Hash::check('secret-password', $admin->password))->toBeTrue();
    expect($admin->password)->not->toBe('secret-password');
});

it('fails when admin email already exists', function () {
    putenv('ADMIN_PASSWORD=secret-password');

    User::factory()->create([
        'email' => 'admin@example.test',
    ]);

    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin2',
        '--name' => 'Admin Two',
    ])
        ->expectsOutput('A user with this email or username already exists.')
        ->assertExitCode(1);

    expect(User::where('email', 'admin@example.test')->count())->toBe(1);
});

it('fails when admin username already exists', function () {
    putenv('ADMIN_PASSWORD=secret-password');

    User::factory()->create([
        'username' => 'admin',
    ]);

    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
    ])
        ->expectsOutput('A user with this email or username already exists.')
        ->assertExitCode(1);
});

it('fails with invalid email', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'not-an-email',
        '--username' => 'admin',
        '--name' => 'Admin User',
    ])
        ->expectsOutput('A valid --email is required.')
        ->assertExitCode(1);
});

it('requires username', function () {
    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--name' => 'Admin User',
    ])
        ->expectsOutput('--username is required.')
        ->assertExitCode(1);
});

it('requires sufficiently long password', function () {
    putenv('ADMIN_PASSWORD=short');

    $this->artisan('rateguru:admin:create', [
        '--email' => 'admin@example.test',
        '--username' => 'admin',
        '--name' => 'Admin User',
    ])
        ->expectsOutput('Password must be at least 12 characters.')
        ->assertExitCode(1);

    expect(User::where('email', 'admin@example.test')->exists())->toBeFalse();
});
