<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

it('renders profile header with display name and bio', function () {
    $user = User::factory()->create([
        'display_name' => 'Ivan Moroz',
        'bio' => 'Profile bio text',
    ]);

    $view = Blade::render('<x-profile.header :user="$user" />', ['user' => $user]);

    expect($view)->toContain('Ivan Moroz');
    expect($view)->toContain('Profile bio text');
    expect($view)->toContain('data-testid="profile-header"');
});

it('shows website link when present', function () {
    $user = User::factory()->create([
        'display_name' => 'Ivan',
        'profile_website_url' => 'https://example.com',
    ]);

    $view = Blade::render('<x-profile.header :user="$user" />', ['user' => $user]);

    expect($view)->toContain('https://example.com');
    expect($view)->toContain('data-testid="profile-website"');
});

it('does not render website when absent', function () {
    $user = User::factory()->create(['profile_website_url' => null]);

    $view = Blade::render('<x-profile.header :user="$user" />', ['user' => $user]);

    expect($view)->not->toContain('data-testid="profile-website"');
});

it('shows username', function () {
    $user = User::factory()->create(['username' => 'test_user', 'display_name' => 'Test User']);

    $view = Blade::render('<x-profile.header :user="$user" />', ['user' => $user]);

    expect($view)->toContain('@test_user');
});
