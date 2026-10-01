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

it('shows the static pages as the project stores them, empty where it has no text', function () {
    // The form is not filled in from config/static-pages.php: a language the
    // project has no text for shows as empty, the same as a visitor sees it.
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    unset($pages['about'][$target]);
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->assertSet('data.static_pages.about.en.title', $pages['about']['en']['title'])
        ->assertSet("data.static_pages.about.{$target}.title", null)
        ->assertSet("data.static_pages.about.{$target}.content", null);
});

it('requires the English text of a page, and leaves every other language optional', function () {
    // English is what a language without its own text falls back to, and
    // nothing falls back for English.
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['static_pages' => config('static-pages.defaults')]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->set('data.static_pages.privacy.en.content', '')
        ->set("data.static_pages.privacy.{$target}.content", '')
        ->call('save')
        ->assertHasErrors(['data.static_pages.privacy.en.content'])
        ->assertHasNoErrors(["data.static_pages.privacy.{$target}.content"]);

    expect(ProjectSettings::findOrFail(1)->static_pages['privacy']['en']['content'])
        ->toBe(config('static-pages.defaults.privacy.en.content'));
});
