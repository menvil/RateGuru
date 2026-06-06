<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\User;
use Livewire\Livewire;

it('requires site name in project settings page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.site_name', '')
        ->call('save')
        ->assertHasErrors(['data.site_name']);
});

it('requires object singular name in project settings page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.object_singular_name', '')
        ->call('save')
        ->assertHasErrors(['data.object_singular_name']);
});

it('requires upload cta label in project settings page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.upload_cta_label', '')
        ->call('save')
        ->assertHasErrors(['data.upload_cta_label']);
});

it('rejects invalid default theme', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.default_theme', 'neon')
        ->call('save')
        ->assertHasErrors(['data.default_theme']);
});

it('rejects invalid default sort', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.default_sort', 'random')
        ->call('save')
        ->assertHasErrors(['data.default_sort']);
});
