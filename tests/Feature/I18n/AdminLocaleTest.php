<?php

use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\TagResource;
use App\Models\User;
use Livewire\Livewire;

/**
 * The admin panel is English whatever language its user reads the site in,
 * and working in it never changes that language.
 *
 * Each case runs for every translated language, so a newly declared one is
 * held to the same boundary without anyone adding it here. That is also why
 * nothing here asks what Filament would have shown in that language: Filament
 * ships its own translations for some languages and not others, and the
 * contract is the locale the panel renders in, which is English either way.
 */
function panelAdmin(string $locale): User
{
    return User::factory()->admin()->create(['locale' => $locale]);
}

/**
 * A deployment whose own default language (APP_LOCALE) is not English — the
 * language the panel would otherwise inherit, since its routes never run
 * SetLocale.
 */
function siteDefaultsTo(string $locale): void
{
    config(['app.locale' => $locale]);
    app()->setLocale($locale);
}

/**
 * The page component's snapshot from a rendered admin page, as the browser
 * would send it back in a Livewire update.
 */
function adminPageSnapshot(string $html, string $componentName): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $attribute) {
        $snapshot = html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $componentName) {
            return $snapshot;
        }
    }

    throw new RuntimeException("the page rendered no [{$componentName}] component");
}

it('renders the public site in the language on the account', function (string $locale) {
    offerEveryInstalledLocale();

    $this->actingAs(panelAdmin($locale))
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('lang="'.$locale.'"', false)
        ->assertSee(__('ui.nav.home', [], $locale));
})->with(representativeTranslatedLocales());

it('renders the admin panel in English for any account or site language', function (string $locale) {
    siteDefaultsTo($locale);

    $this->actingAs(panelAdmin($locale))
        ->get(TagResource::getUrl('create'))
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee(__('filament-panels::layout.actions.logout.label', [], 'en'));

    expect(app()->getLocale())->toBe('en');
})->with(representativeTranslatedLocales());

it('keeps English for actions taken inside the panel', function (string $locale) {
    // A button in the panel is a Livewire update, which arrives through the
    // `web` group — where SetLocale has already picked the account's language —
    // and not through the panel's own middleware.
    siteDefaultsTo($locale);
    $this->actingAs(panelAdmin($locale));

    $html = $this->get(TagResource::getUrl('create'))->assertOk()->getContent();

    $response = $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => adminPageSnapshot($html, CreateTag::class),
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ])
        ->assertOk();

    // The locale Livewire rendered the update in travels back in the snapshot.
    $snapshot = json_decode($response->json('components.0.snapshot'), true);

    expect($snapshot['memo']['locale'])->toBe('en')
        ->and(app()->getLocale())->toBe('en')
        ->and($response->json('components.0.effects.html'))->toBeString()
        ->toContain(__('filament-panels::resources/pages/create-record.form.actions.create.label', [], 'en'));
})->with(representativeTranslatedLocales());

it('renders the admin sign-in page in English for a visitor browsing in another language', function (string $locale) {
    siteDefaultsTo($locale);

    $this->withSession(['locale' => $locale])
        ->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee(__('filament-panels::auth/pages/login.heading', [], 'en'));

    expect(app()->getLocale())->toBe('en')
        ->and(session('locale'))->toBe($locale);
})->with(representativeTranslatedLocales());

it('leaves the public language untouched after working in the panel', function (string $locale) {
    offerEveryInstalledLocale();
    $admin = panelAdmin($locale);

    $this->actingAs($admin)
        ->withSession(['locale' => $locale])
        ->get(route('feed'))
        ->assertSee('lang="'.$locale.'"', false);

    $this->get(TagResource::getUrl('create'))
        ->assertOk()
        ->assertSee('lang="en"', false);

    expect($admin->fresh()->locale)->toBe($locale)
        ->and(session('locale'))->toBe($locale);

    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('lang="'.$locale.'"', false)
        ->assertSee(__('ui.nav.home', [], $locale));
})->with(representativeTranslatedLocales());
