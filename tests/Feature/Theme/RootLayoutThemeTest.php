<?php

use App\Models\ProjectSettings;
use App\Models\User;

it('renders data theme attribute in root layout', function () {
    ProjectSettings::factory()->create([
        'default_theme' => 'dark',
    ]);

    $this->get(route('feed'))
        ->assertSee('data-theme="dark"', false);
});

it('renders user theme preference in root layout', function () {
    $user = User::factory()->create([
        'theme_preference' => 'light',
    ]);

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertSee('data-theme="light"', false);
});

it('renders data theme preference attribute in root layout', function () {
    $user = User::factory()->create([
        'theme_preference' => 'light',
    ]);

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertSee('data-theme-preference="light"', false);
});

it('uses system preference when no user and no project settings', function () {
    $this->get(route('feed'))
        ->assertSee('data-theme="dark"', false);
});
