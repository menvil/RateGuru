<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('admin can save project settings translations', function () {
    $admin = User::factory()->admin()->create();

    ProjectSettings::factory()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.site_name_translations.ru', 'РейтГуру')
        ->set('data.feed_title_translations.bg', 'Последни публикации')
        ->call('save')
        ->assertHasNoErrors();

    $settings = ProjectSettings::first();

    expect($settings->site_name_translations['ru'])->toBe('РейтГуру');
    expect($settings->feed_title_translations['bg'])->toBe('Последни публикации');
});
