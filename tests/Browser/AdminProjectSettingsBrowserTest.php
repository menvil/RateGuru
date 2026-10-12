<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Project settings in a real browser: the screen at each width, what the top
 * bar counts as unsaved across the tabs, Save and Discard, switching uploads
 * off only after asking, and the draft compared with the installation preset
 * as it is typed — measured from the page the browser built.
 */
beforeEach(function () {
    ProjectSettings::factory()->create(['site_name' => 'Pawrank', 'site_tagline' => 'Rate the pets of the internet']);
    actingAs(User::factory()->admin()->create());
});

/** The note in the top bar while anything is unsaved, or null while nothing is. */
function settingsUnsavedNote(mixed $page): ?string
{
    return $page->script(<<<'JS'
        (() => {
            const note = document.querySelector('.rg-admin-topbar__unsaved')

            return getComputedStyle(note).display === 'none' ? null : note.textContent.trim()
        })()
    JS);
}

it('draws the screen in Admin v2 at every width, the tabs scrolling inside their strip and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/project-settings?tab=pages', $width);

    expect($page->script(<<<'JS'
        (() => ({
            headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
            tabs: [...document.querySelectorAll('.rg-admin-tab')].map((tab) => tab.textContent.trim()),
            selected: document.querySelector('.rg-admin-tab[aria-current="page"]')?.textContent.trim(),
            pages: [...document.querySelectorAll('.rg-admin-settings__page-title')].map((name) => name.textContent.trim()),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'headings' => ['Project settings'],
        'tabs' => ['Site identity', 'Object labels', 'Defaults', 'Feature flags', 'Sign-in providers', 'Static pages'],
        'selected' => 'Static pages',
        'pages' => ['About', 'Privacy', 'Terms', 'Contact'],
        'overflow' => false,
    ]);
})->with([1440, 1024, 768, 390]);

it('counts the unsaved changes in the top bar, and puts them back on Discard', function () {
    $page = visitAdminEditor('/admin/project-settings');

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    $page->type('#rg-admin-settings-site_name', 'Pawrank!');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');
    $page->type('#rg-admin-settings-site_tagline', 'Rate every pet');
    // x-show draws the note on the next frame.
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display !== 'none'");

    expect(settingsUnsavedNote($page))->toBe('2 unsaved changes')
        ->and(adminEditorTopBar($page))->toBe(['unsaved' => true, 'discard' => true, 'save' => true]);

    $page->click('.rg-admin-topbar__editor button:first-of-type');
    waitForScript($page, "document.getElementById('rg-admin-settings-site_name').value", 'Pawrank');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false])
        ->and($page->script("document.getElementById('rg-admin-settings-site_tagline').value"))->toBe('Rate the pets of the internet');
});

it('keeps what was typed on one tab while another is open, and saves both from the top bar', function () {
    $page = visitAdminEditor('/admin/project-settings');

    $page->type('#rg-admin-settings-site_name', 'Pawrank!');
    $page->click('.rg-admin-tab[href$="?tab=features"]');
    waitForScript($page, "document.querySelector('.rg-admin-tab[aria-current=\"page\"]')?.textContent.trim()", 'Feature flags');
    $page->click('#rg-admin-settings-flag-show_comments');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '2 unsaved changes');

    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Project settings saved. Visitors see the changes on their next page load.');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    $settings = ProjectSettings::query()->sole();

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false])
        ->and($settings->site_name)->toBe('Pawrank!')
        ->and($settings->feature_flags['show_comments'])->toBeFalse();
});

it('asks before uploads are paused, and pauses them in the draft only', function () {
    $page = visitAdminEditor('/admin/project-settings?tab=features');
    $switch = '#rg-admin-settings-flag-allow_user_uploads';

    $page->click($switch);
    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Pause all uploads?');

    expect($page->script("document.querySelector('{$switch}').getAttribute('aria-checked')"))->toBe('true');

    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-dialog') === null");
    waitForScript($page, "document.querySelector('{$switch}').getAttribute('aria-checked')", 'false');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    expect(ProjectSettings::query()->sole()->feature_flags['allow_user_uploads'] ?? true)->toBeTrue();
});

it('compares the draft with the installation preset as it is typed, and reverts a setting to it', function () {
    app(ApplyProjectPresetAction::class)->handle('nature', force: true);
    $singular = config('project_presets.nature.settings.object_singular_name.en');
    $page = visitAdminEditor('/admin/project-settings?tab=labels');
    $field = '#rg-admin-settings-object_singular_name';
    $badge = "document.querySelector('.rg-admin-settings__preset-trigger').innerText.replace(/\\s+/g, ' ')";

    waitForScript($page, "{$badge}.includes('Matches preset')");

    $page->type($field, 'Critter');
    waitForScript($page, "{$badge}.includes('Customized · 1')");
    waitForScript($page, "getComputedStyle(document.querySelector('{$field}').closest('.rg-admin-settings__field').querySelector('.rg-admin-settings__differs')).display !== 'none'");

    $page->click('.rg-admin-settings__preset-trigger');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-settings__preset-item-label')].map((label) => label.textContent.trim()).join()", 'Singular name');
    $page->click('.rg-admin-settings__preset-item .rg-admin-button');

    waitForScript($page, "document.querySelector('{$field}').value", $singular);
    waitForScript($page, "{$badge}.includes('Matches preset')");
});
