<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('renders the static pages in project settings', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->withQueryParams(['tab' => 'pages'])
        ->test(ProjectSettingsPage::class)
        ->assertSee('Static pages')
        ->assertSee('About')
        ->assertSee('Privacy')
        ->assertSee('Terms')
        ->assertSee('Contact');
});

it('lets an admin update the English text of a static page, keeping its translations', function () {
    $pages = config('static-pages.defaults');
    $locale = translatedLocales()[0];
    $pages['contact'][$locale] = ['title' => 'Contact (translated)', 'content' => 'Write to us (translated).'];
    ProjectSettings::factory()->create(['static_pages' => $pages]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->set('data.static_pages.contact.en.title', 'Contact the team')
        ->set('data.static_pages.contact.en.content', 'New contact text.')
        ->call('save')
        ->assertHasNoErrors();

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->static_pages['contact']['en'])
        ->title->toBe('Contact the team')
        ->content->toBe('New contact text.')
        ->and($settings->static_pages['contact'][$locale])->toBe(['title' => 'Contact (translated)', 'content' => 'Write to us (translated).']);
});

it('shows the English text of each page as the project stores it, empty where it has none', function () {
    // The form is not filled in from config/static-pages.php: a page the
    // project has no English text for shows as empty.
    $pages = config('static-pages.defaults');
    unset($pages['about']['en']);
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->assertSet('data.static_pages.privacy.en.title', $pages['privacy']['en']['title'])
        ->assertSet('data.static_pages.about.en.title', null)
        ->assertSet('data.static_pages.about.en.content', null);
});

it('requires the English text of a page', function () {
    // English is what every other language falls back to, and nothing falls
    // back for English.
    ProjectSettings::factory()->create(['static_pages' => config('static-pages.defaults')]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->set('data.static_pages.privacy.en.content', '')
        ->call('save')
        ->assertHasErrors(['data.static_pages.privacy.en.content']);

    expect(ProjectSettings::findOrFail(1)->static_pages['privacy']['en']['content'])
        ->toBe(config('static-pages.defaults.privacy.en.content'));
});
