<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('renders editable localized static page fields in project settings', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->assertSee('Static pages')
        ->assertSee('About')
        ->assertSee('Privacy')
        ->assertSee('Terms')
        ->assertSee('Contact');
});

it('lets an admin update a localized static page', function () {
    ProjectSettings::factory()->create([
        'static_pages' => config('static-pages.defaults'),
    ]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.static_pages.contact.bg.title', 'Връзка с екипа')
        ->set('data.static_pages.contact.bg.content', 'Ново съдържание за контакт.')
        ->call('save')
        ->assertHasNoErrors();

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->static_pages['contact']['bg'])
        ->title->toBe('Връзка с екипа')
        ->content->toBe('Ново съдържание за контакт.');
});
