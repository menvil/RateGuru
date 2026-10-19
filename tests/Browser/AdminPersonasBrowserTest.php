<?php

use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Models\PersonaProfile;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Content Studio's Personas in a real browser: the list and the editor at
 * each width, creating a persona from the top bar, and the editor's unsaved
 * count, Discard and Save.
 *
 * No photo is chosen here: the browser test server hands the application no
 * uploaded files, so a Livewire upload never arrives. The photo is covered
 * through Livewire in PersonaResourceTest and on its own in
 * SetPersonaAvatarActionTest.
 *
 * One kind of page per test: the test server reads the top bar's actions
 * from the first page it drew.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create(['username' => 'daria_k']));

    $this->persona = PersonaProfile::factory()
        ->for(User::factory()->persona()->state(['username' => 'night_owl', 'name' => 'Night Owl', 'display_name' => 'Night Owl']), 'user')
        ->create(['short_description' => 'Insomniac with opinions about cats and the people who keep them', 'character' => 'Dry and patient'])
        ->user;
});

it('draws the list in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/personas', $width);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')
            const grid = scroll.querySelector('[role="table"]')

            return {
                headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
                columns: [...document.querySelectorAll('[role="columnheader"]')].map((cell) => cell.textContent.trim()),
                scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe([
        'headings' => ['Personas'],
        'columns' => ['Persona', 'Character', 'Status', 'Engagement', 'Max a day', 'Actions'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
    ]);
})->with([1440, 1280, 1024, 390]);

it('draws the editor at every width without scrolling the page sideways', function (int $width) {
    $page = visitAdminEditor("/admin/personas/{$this->persona->id}/edit", $width);

    expect($page->script(<<<'JS'
        (() => ({
            heading: document.querySelector('h1')?.textContent.trim(),
            cards: [...document.querySelectorAll('.rg-admin-persona__main .rg-admin-card__title')].map((title) => title.textContent.trim()),
            fieldsFit: [...document.querySelectorAll('.rg-admin-persona__main input:not([type="file"]), .rg-admin-persona__main textarea')]
                .every((field) => field.getBoundingClientRect().right <= window.innerWidth + 0.5),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'heading' => 'Night Owl',
        'cards' => ['Profile', 'Character', 'Activity'],
        'fieldsFit' => true,
        'overflow' => false,
    ]);
})->with([1440, 1280, 1024, 390]);

it('creates a persona from the top bar, its username made from its name, and opens its editor', function () {
    $page = visitAdminEditor('/admin/personas/create');

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    $page->type('#rg-admin-persona-display-name', 'Grumpy Max');
    $page->type('#rg-admin-persona-character', 'Complains, then helps.');
    waitForScript($page, "! document.querySelector('.rg-admin-topbar__editor .rg-admin-button--primary').disabled");
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');

    $persona = null;
    eventually(function () use (&$persona): void {
        $persona = User::query()->where('username', 'grumpy_max')->first();
        expect($persona)->not->toBeNull();
    });

    waitForScript($page, 'location.pathname', "/admin/personas/{$persona->id}/edit");
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Grumpy Max created');

    expect($persona->role)->toBe(UserRole::Persona)
        ->and($persona->personaProfile->character)->toBe('Complains, then helps.');
});

it('counts the unsaved changes, puts them back on Discard, and saves from the top bar', function () {
    $page = visitAdminEditor("/admin/personas/{$this->persona->id}/edit");

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    $page->type('#rg-admin-persona-character', 'Warmer now');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    $page->click('.rg-admin-topbar__editor button:first-of-type');
    waitForScript($page, "document.getElementById('rg-admin-persona-character').value", 'Dry and patient');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    // Engagement: High, the last of four.
    $page->click('.rg-admin-persona__segmented[aria-label="Engagement"] [role="radio"]:last-child');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Changes to Night Owl saved');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect($this->persona->fresh()->personaProfile->engagement)->toBe(PersonaEngagement::High);
});
