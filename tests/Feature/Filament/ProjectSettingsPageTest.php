<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Support\TranslationSourceEditor;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Studio\StudioVerifiedAdults;
use App\Support\Translations\ProjectTranslationCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Project settings in Admin v2: one draft over seven tabs, saved together from
 * the top bar; the installation preset shown and compared, never applied;
 * switching off what locks people out asked first; each card saying which
 * offered languages miss what.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

// One request per test: Filament reads the page's scope, the top bar's actions
// included, from the request the page was first rendered for.
it('refuses the page to anyone but an administrator', function (Closure $user) {
    $this->actingAs($user())->get(ProjectSettingsPage::getUrl())->assertForbidden();
})->with([
    'a member' => [fn (): User => User::factory()->create()],
    'a moderator' => [fn (): User => User::factory()->moderator()->create()],
]);

it('draws the screen in Admin v2, saving from the top bar', function () {
    ProjectSettings::factory()->create(['site_name' => 'Pawrank']);

    expect($this->get(ProjectSettingsPage::getUrl())->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-settings-title" class="rg-admin-page-header__title">Project settings</h1>')
        ->toContain('English is the reference language')
        ->toContain("\$dispatch('save-project-settings')")
        ->toContain('Save changes')
        ->not->toContain('fi-section');
});

it('opens the tab the address names, and the first for anything else', function (string $tab, string $opened) {
    ProjectSettings::factory()->create();

    Livewire::withQueryParams(['tab' => $tab])->test(ProjectSettingsPage::class)->assertSet('tab', $opened);
})->with([
    'a tab' => ['features', 'features'],
    'no such tab' => ['nonsense', 'identity'],
]);

it('saves every tab together, and has nothing unsaved after', function () {
    ProjectSettings::factory()->create();

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.site_name', '  Pawrank ')
        ->set('data.default_sort', 'new')
        ->set('data.feature_flags.show_comments', false)
        ->set('data.static_pages.about.en.title', 'About Pawrank')
        ->dispatch('save-project-settings')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Project settings saved. Visitors see the changes on their next page load.')
        ->assertSet('saved.default_sort', 'new')
        ->assertSet('data.site_name', 'Pawrank')
        ->assertSet('saved.site_name', 'Pawrank');

    $settings = ProjectSettings::query()->sole();

    expect($settings)->site_name->toBe('Pawrank')->default_sort->toBe('new')
        ->and($settings->feature_flags['show_comments'])->toBeFalse()
        ->and($settings->static_pages['about']['en']['title'])->toBe('About Pawrank');
});

it('keeps a sign-in provider switched off when this page switches another on', function () {
    $settings = ProjectSettings::factory()->create(['sign_in_providers' => ['google' => false, 'facebook' => false]]);

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.sign_in_providers.facebook', true)
        ->dispatch('save-project-settings')
        ->assertHasNoErrors();

    expect($settings->fresh()->sign_in_providers)->toMatchArray(['google' => false, 'facebook' => true]);
});

it('writes only what this page changed, never putting back what another administrator saved since', function () {
    $settings = ProjectSettings::factory()->create([
        'sign_in_providers' => ['google' => true, 'facebook' => true],
        'feature_flags' => ['allow_user_uploads' => true],
    ]);

    $page = Livewire::test(ProjectSettingsPage::class);

    // Saved from another tab after this one was read.
    $static = $settings->static_pages;
    $static['privacy']['en']['content'] = 'Changed meanwhile.';
    $settings->forceFill([
        'sign_in_providers' => ['google' => false, 'facebook' => true],
        'feature_flags' => [...$settings->feature_flags, 'allow_user_uploads' => false],
        'static_pages' => $static,
    ])->save();

    $page->set('data.site_tagline', 'Rate every dog')
        ->set('data.static_pages.privacy.en.title', 'Privacy, retitled')
        ->dispatch('save-project-settings')
        ->assertHasNoErrors()
        // And the page shows what is saved now, the other tab's values included.
        ->assertSet('data.sign_in_providers.google', false)
        ->assertSet('saved.feature_flags.allow_user_uploads', false)
        ->assertSet('data.site_tagline', 'Rate every dog');

    $fresh = $settings->fresh();

    expect($fresh->site_tagline)->toBe('Rate every dog')
        ->and($fresh->sign_in_providers['google'])->toBeFalse()
        ->and($fresh->feature_flags['allow_user_uploads'])->toBeFalse()
        ->and($fresh->static_pages['privacy']['en']['title'])->toBe('Privacy, retitled')
        ->and($fresh->static_pages['privacy']['en']['content'])->toBe('Changed meanwhile.');
});

it('switches imports from a link off, the one switch they have', function () {
    $settings = ProjectSettings::factory()->create(['feature_flags' => ['allow_url_imports' => true]]);

    Livewire::withQueryParams(['tab' => 'features'])->test(ProjectSettingsPage::class)
        ->assertSeeHtml(__('admin.fields.allow_url_imports'))
        ->set('data.feature_flags.allow_url_imports', false)
        ->dispatch('save-project-settings')
        ->assertHasNoErrors();

    expect($settings->fresh()->feature_flags['allow_url_imports'])->toBeFalse()
        ->and(app(ProjectSettingsManager::class)->featureEnabled('allow_url_imports'))->toBeFalse();
});

it('switches a guest’s vote, rating or comment vote to open sign-up, off until an administrator saves it on', function () {
    $settings = ProjectSettings::factory()->create();

    expect(app(ProjectSettingsManager::class)->featureEnabled('guest_actions_open_sign_up'))->toBeFalse();

    Livewire::withQueryParams(['tab' => 'features'])->test(ProjectSettingsPage::class)
        ->assertSeeHtml(__('admin.fields.guest_actions_open_sign_up'))
        ->set('data.feature_flags.guest_actions_open_sign_up', true)
        ->dispatch('save-project-settings')
        ->assertHasNoErrors();

    expect($settings->fresh()->feature_flags['guest_actions_open_sign_up'])->toBeTrue();
});

it('saves only the flags and providers the project has, leaving out whatever a request adds', function () {
    ProjectSettings::factory()->create();

    $page = Livewire::test(ProjectSettingsPage::class)
        ->set('data.feature_flags.forged_flag', true)
        ->set('data.sign_in_providers.forged_provider', true)
        ->set('data.forged_setting', 'Forged')
        ->dispatch('save-project-settings')
        ->assertHasNoErrors();

    $settings = ProjectSettings::query()->sole();

    // Only the flags the page shows are ever written — and of those only the
    // ones it changed; a flag left alone keeps its stored or default value.
    expect(array_diff(array_keys($settings->feature_flags), array_keys(ProjectSettingsPage::FLAGS), ['content_studio_execution_enabled']))->toBe([])
        ->and($settings->feature_flags)->not->toHaveKey('forged_flag')
        ->and($settings->sign_in_providers)->not->toHaveKey('forged_provider')
        ->and($page->get('saved'))->not->toHaveKey('forged_setting')
        ->and($page->get('saved.feature_flags'))->not->toHaveKey('forged_flag')
        ->and($page->get('data'))->not->toHaveKey('forged_setting')
        ->and($page->get('data.sign_in_providers'))->not->toHaveKey('forged_provider');
});

it('offers the confirmation that Content Studio’s photos show verified adults, off until an administrator saves it on', function () {
    ProjectSettings::factory()->create();
    $confirmed = app(StudioVerifiedAdults::class);

    expect($confirmed->confirmed())->toBeFalse();

    Livewire::withQueryParams(['tab' => 'features'])->test(ProjectSettingsPage::class)
        ->assertSee('Content Studio photos show verified adults')
        ->assertSee('Everyone shown in the photos Content Studio uses is a verified adult (18+) who agreed to their publication.')
        ->assertSet('data.feature_flags.content_studio_verified_adults', false)
        ->set('data.feature_flags.content_studio_verified_adults', true)
        ->dispatch('save-project-settings')
        ->assertHasNoErrors();

    expect($confirmed->confirmed())->toBeTrue()
        ->and(ProjectSettings::query()->sole()->feature_flags)->toMatchArray(['content_studio_verified_adults' => true, 'show_comments' => true]);
});

it('puts the saved settings back on Discard', function () {
    ProjectSettings::factory()->create(['site_name' => 'Pawrank']);

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.site_name', 'Something else')
        ->dispatch('discard-project-settings')
        ->assertSet('data.site_name', 'Pawrank');
});

it('says on which tabs a refused field is', function () {
    ProjectSettings::factory()->create();

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.feed_title', '')
        ->set('data.static_pages.terms.en.content', '')
        ->dispatch('save-project-settings')
        ->assertHasErrors(['data.feed_title' => 'required', 'data.static_pages.terms.en.content' => 'required'])
        ->assertSeeHtml('Some settings can’t be saved yet: Object labels and Static pages.');
});

it('asks before uploads or guest viewing are switched off, and switches them off in the draft', function (string $flag, string $title) {
    ProjectSettings::factory()->create();

    Livewire::withQueryParams(['tab' => 'features'])
        ->test(ProjectSettingsPage::class)
        ->set("data.feature_flags.{$flag}", false)
        ->assertSet('confirming', "flag:{$flag}")
        ->assertSet("data.feature_flags.{$flag}", true)
        ->assertSeeHtml($title)
        ->assertSeeHtml('Applies after you save the settings.')
        ->call('confirmSwitchOff')
        ->assertSet("data.feature_flags.{$flag}", false)
        ->assertSet('confirming', null);

    // Nothing is saved until Save.
    expect(ProjectSettings::query()->sole()->feature_flags[$flag] ?? true)->toBeTrue();
})->with([
    'uploads' => ['allow_user_uploads', 'Pause all uploads?'],
    'guest viewing' => ['allow_guest_viewing', 'Require sign-in for everyone?'],
]);

it('keeps a guarded flag on when the dialog is cancelled, and switches others off at once', function () {
    ProjectSettings::factory()->create();

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.feature_flags.allow_user_uploads', false)
        ->call('closeConfirmation')
        ->assertSet('data.feature_flags.allow_user_uploads', true)
        ->set('data.feature_flags.show_comments', false)
        ->assertSet('confirming', null)
        ->assertSet('data.feature_flags.show_comments', false);
});

it('keeps what is saved and the open confirmation on the server', function (string $property, mixed $value) {
    ProjectSettings::factory()->create();

    Livewire::test(ProjectSettingsPage::class)->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)
    ->with([
        'what is saved' => ['saved', ['site_name' => 'Forged']],
        'the confirmation' => ['confirming', 'flag:allow_user_uploads'],
    ]);

it('compares the draft with the installation preset, without a way to apply it', function () {
    ProjectSettings::factory()->create(['active_preset_key' => 'nature', 'preset_applied_at' => now()]);
    $nature = config('project_presets.nature');

    $page = Livewire::test(ProjectSettingsPage::class);
    $preset = $page->viewData('preset');

    expect($preset)->toMatchArray(['applied' => true, 'label' => 'Nature & travel photography'])
        ->and($preset['values']['object_singular_name'])->toBe($nature['settings']['object_singular_name']['en'])
        ->and($preset['values']['default_theme'])->toBe($nature['settings']['default_theme'])
        ->and($preset['values']['feature_flags.show_comments'])->toBe($nature['feature_flags']['show_comments'])
        // The project's own identity is not the preset's to compare.
        ->and($preset['values'])->not->toHaveKey('site_name');

    $page->assertSeeHtml('Installation preset')->assertDontSeeHtml('applyPreset');
});

it('says no preset was applied, and how one is', function () {
    ProjectSettings::factory()->create(['preset_applied_at' => null]);

    Livewire::test(ProjectSettingsPage::class)
        ->assertSeeHtml('Not applied')
        ->assertSeeHtml('No installation preset has been applied.')
        ->assertSeeHtml('php artisan rateguru:setup');
});

it('says which offered languages miss which settings, and leads to Translation Center', function () {
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$first, $second]);
    ProjectSettings::query()->sole()->update([
        'site_name' => 'Pawrank', 'site_tagline' => 'Rate pets', 'site_description' => 'About pets',
        'site_name_translations' => [$first => 'A', $second => 'B'],
        'site_tagline_translations' => [$first => 'C', $second => 'D'],
        'site_description_translations' => [$first => 'E'],
    ]);

    $translations = Livewire::test(ProjectSettingsPage::class)->viewData('translations')['identity'];
    $second = config("locales.supported.{$second}");

    expect($translations['text'])->toBe('Translations: '.config("locales.supported.{$first}.native").' complete · '.$second['native'].' missing the description.')
        ->and($translations['url'])->toBe(TranslationCenterPage::getUrl(['locale' => twoTranslatedLocales()[1], 'section' => 'project_settings', 'unit' => 'project_settings:site_description']));
});

it('says which offered languages have a setting to update since its English changed, and leads to it', function () {
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$first, $second]);
    ProjectSettings::query()->sole()->update(['site_name' => 'Pawrank', 'site_tagline' => 'Rate pets', 'site_description' => 'About pets']);
    $admin = User::query()->whereKey(auth()->id())->firstOrFail();

    foreach (['site_name', 'site_tagline', 'site_description'] as $field) {
        foreach ([$first, $second] as $locale) {
            app(UpdateProjectTranslationAction::class)->handle($admin, "project_settings:{$field}", $locale, "{$field} in {$locale}");
        }
    }

    // The English of the tagline changes after both translations were made.
    ProjectSettings::query()->sole()->update(['site_tagline' => 'Rate every pet']);
    app(ProjectSettingsManager::class)->flush();

    $translations = Livewire::test(ProjectSettingsPage::class)->viewData('translations')['identity'];
    $native = fn (string $code): string => config("locales.supported.{$code}.native");

    expect($translations['text'])->toBe("Translations: to update in {$native($first)} and {$native($second)}.")
        ->and($translations['update'])->toBe(TranslationCenterPage::getUrl(['locale' => $first, 'section' => 'project_settings', 'mode' => 'outdated', 'unit' => 'project_settings:site_tagline']));
});

it('keeps the summary to one line however many languages are offered, naming three and counting the rest', function () {
    $locales = array_slice(translatedLocales(), 0, 5);
    offerLocales($locales);
    ProjectSettings::query()->sole()->update(['site_name' => 'Pawrank', 'site_tagline' => null, 'site_description' => null, 'site_name_translations' => null]);
    app(ProjectSettingsManager::class)->flush();

    $offered = array_values(array_filter(array_keys(app(LocaleManager::class)->enabled()), fn (string $code): bool => $code !== 'en'));
    $named = array_map(fn (string $code): string => config("locales.supported.{$code}.native"), array_slice($offered, 0, 3));

    expect(Livewire::test(ProjectSettingsPage::class)->viewData('translations')['identity']['text'])
        ->toBe('Translations: missing in '.implode(', ', $named).' and '.(count($offered) - 3).' more.');
});

it('says a static page has translations to update once its English changed', function () {
    [$first] = twoTranslatedLocales();
    offerLocales([$first]);
    $admin = User::query()->whereKey(auth()->id())->firstOrFail();
    app(UpdateProjectTranslationAction::class)->handle($admin, 'static_pages:privacy:title', $first, 'Privacy (translated)');
    app(UpdateProjectTranslationAction::class)->handle($admin, 'static_pages:privacy:content', $first, 'We keep little (translated).');
    $pages = ProjectSettings::query()->sole()->static_pages;
    $pages['privacy']['en']['content'] = 'We now keep the IP address you sign up from.';
    ProjectSettings::query()->sole()->update(['static_pages' => $pages]);
    app(ProjectSettingsManager::class)->flush();

    $privacy = Livewire::withQueryParams(['tab' => 'pages', 'page' => 'privacy'])->test(ProjectSettingsPage::class)
        ->assertSeeHtml('data-testid="settings-translations-update"')
        // The list of pages says it too, so a page to update is found without opening each.
        ->assertSee('1 to update')
        ->viewData('pages')['privacy'];

    expect($privacy['translations'])->toBe('Translations: to update in '.config("locales.supported.{$first}.native").'.')
        ->and($privacy['outdated'])->toBe(1)
        ->and($privacy['update'])->toBe(TranslationCenterPage::getUrl(['locale' => $first, 'section' => 'static_pages', 'mode' => 'outdated', 'unit' => 'static_pages:privacy:content']));
});

it('reads which settings and static pages need an update in a few queries, whatever the tab', function () {
    $locales = array_slice(translatedLocales(), 0, 5);
    offerLocales($locales);
    $page = Livewire::test(ProjectSettingsPage::class);

    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        if (str_contains($query->sql, 'project_translation_sources')) {
            $count++;
        }
    });
    $page->call('$refresh');

    // One read of what is recorded for every setting and page, not one per group or page.
    expect($count)->toBe(1);
});

it('lists the static pages with their translations, and edits the one chosen', function () {
    [$first] = twoTranslatedLocales();
    offerLocales([$first]);
    $pages = config('static-pages.defaults');
    $pages['contact'][$first] = ['title' => 'Contact (translated)', 'content' => 'Write to us (translated).'];
    ProjectSettings::query()->sole()->update(['static_pages' => $pages]);

    $page = Livewire::withQueryParams(['tab' => 'pages', 'page' => 'contact'])->test(ProjectSettingsPage::class);
    $listed = $page->viewData('pages');

    expect($listed['contact']['missing'])->toBe(0)
        ->and($listed['about']['missing'])->toBe(1);

    $page->assertSet('page', 'contact')
        ->assertSeeHtml('wire:model="data.static_pages.contact.en.title"')
        ->assertSeeHtml('href="'.e(route('pages.contact')).'"')
        ->set('page', 'nonsense')
        ->assertSet('page', 'about');
});

it('shows each sign-in provider as configured or not', function () {
    ProjectSettings::factory()->create();
    config()->set('services.facebook.client_id', null);

    Livewire::withQueryParams(['tab' => 'providers'])
        ->test(ProjectSettingsPage::class)
        ->assertSeeHtml('Configured')
        ->assertSeeHtml('Not configured')
        ->assertSeeHtml('FACEBOOK_CLIENT_ID, FACEBOOK_CLIENT_SECRET');
});

it('holds each text to the limit its translations are held to', function () {
    ProjectSettings::factory()->create();
    [$limit] = ProjectTranslationCatalog::FIELDS['project_settings']['feed_title'];

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.feed_title', str_repeat('f', $limit + 1))
        ->dispatch('save-project-settings')
        ->assertHasErrors(['data.feed_title' => 'max']);
});

it('opens a setting or a static page from Translation Center on its tab', function () {
    $units = collect(app(ProjectTranslationCatalog::class)->units());

    expect(TranslationSourceEditor::url($units->firstWhere('id', 'project_settings:site_name')))->toBe(ProjectSettingsPage::getUrl())
        ->and(TranslationSourceEditor::url($units->firstWhere('id', 'project_settings:feed_title')))->toBe(ProjectSettingsPage::getUrl(['tab' => 'labels']))
        ->and(TranslationSourceEditor::url($units->firstWhere('id', 'static_pages:terms:title')))->toBe(ProjectSettingsPage::getUrl(['tab' => 'pages', 'page' => 'terms']));
});

it('names every flag it shows, never by its translation key', function () {
    $this->actingAs(User::factory()->admin()->create());

    $unnamed = array_values(array_filter(array_keys(ProjectSettingsPage::FLAGS), fn (string $flag): bool => __("admin.fields.{$flag}") === "admin.fields.{$flag}"));

    expect($unnamed)->toBe([]);
    Livewire::test(ProjectSettingsPage::class)->assertDontSeeHtml('admin.fields.');
});
