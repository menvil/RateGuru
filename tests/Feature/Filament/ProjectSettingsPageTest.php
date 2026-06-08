<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\User;

it('allows admin to access project settings page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertOk();
});

it('does not allow normal user to access project settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/project-settings')
        ->assertForbidden();
});

it('does not allow moderator to access project settings page', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get('/admin/project-settings')
        ->assertForbidden();
});

it('project settings page is accessible', function () {
    expect(class_exists(ProjectSettingsPage::class))->toBeTrue();
});
