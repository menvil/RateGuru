<?php

use App\Enums\PersonaEngagement;
use App\Enums\UserRole;
use App\Models\PersonaProfile;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Content Studio's Personas in a real browser: the list, and the editor in
 * the drawer over it, at each width; creating a persona from the top bar; the
 * drawer's unsaved count, Cancel and Save.
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

it('draws the editor in the drawer over the list at every width, never wider than the screen', function (int $width) {
    $page = visitAdminEditor("/admin/personas?persona={$this->persona->id}", $width);

    waitForScript($page, "!! document.getElementById('rg-admin-persona-editor')", true, 10.0);

    expect($page->script(<<<'JS'
        (() => {
            const drawer = document.getElementById('rg-admin-persona-editor')
            const box = drawer.getBoundingClientRect()

            return {
                title: drawer.querySelector('.rg-admin-drawer__title')?.textContent.trim(),
                sections: [...drawer.querySelectorAll('.rg-admin-editor-drawer__section .rg-admin-section-label__text')].map((label) => label.textContent.trim()),
                width: Math.round(box.width) === Math.min(680, window.innerWidth),
                atTheRight: Math.abs(box.right - window.innerWidth) < 1,
                fieldsFit: [...drawer.querySelectorAll('input:not([type="file"]), textarea')]
                    .every((field) => field.getBoundingClientRect().right <= box.right + 0.5),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                list: document.querySelector('h1')?.textContent.trim(),
            }
        })()
    JS))->toBe([
        'title' => 'Edit Night Owl',
        'sections' => ['Profile', 'Character', 'Activity', 'Account'],
        'width' => true,
        'atTheRight' => true,
        'fieldsFit' => true,
        'overflow' => false,
        'list' => 'Personas',
    ]);
})->with([1440, 1280, 1024, 390]);

it('creates a persona in the drawer from the top bar, its username made from its name, and shows it on the list', function () {
    $page = visitAdminEditor('/admin/personas');

    $page->click('.rg-admin-topbar .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('#rg-admin-persona-editor .rg-admin-drawer__title')?.textContent.trim()", 'New persona', 10.0);
    waitForScript($page, 'location.search', '?persona=new');

    $page->type('#rg-admin-persona-display-name', 'Grumpy Max');
    $page->type('#rg-admin-persona-character', 'Complains, then helps.');
    $page->click('#rg-admin-persona-editor .rg-admin-drawer__footer .rg-admin-button--primary');

    $persona = null;
    eventually(function () use (&$persona): void {
        $persona = User::query()->where('username', 'grumpy_max')->first();
        expect($persona)->not->toBeNull();
    });

    waitForScript($page, "document.getElementById('rg-admin-persona-editor') === null");
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Grumpy Max created');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-table__name')].some((name) => name.textContent.trim() === 'Grumpy Max')");

    expect($page->script('location.pathname + location.search'))->toBe('/admin/personas')
        ->and($persona->role)->toBe(UserRole::Persona)
        ->and($persona->personaProfile->character)->toBe('Complains, then helps.');
});

it('counts the unsaved changes in the drawer, keeps nothing on Cancel, and saves from its footer', function () {
    $page = visitAdminEditor('/admin/personas');

    $page->click("#rg-admin-persona-{$this->persona->id} .rg-admin-table__name");
    waitForScript($page, "document.querySelector('#rg-admin-persona-editor .rg-admin-drawer__title')?.textContent.trim()", 'Edit Night Owl', 10.0);
    waitForScript($page, "document.querySelector('#rg-admin-persona-editor .rg-admin-drawer__footer .rg-admin-button--primary').disabled");

    $page->type('#rg-admin-persona-character', 'Warmer now');
    waitForScript($page, "document.querySelector('.rg-admin-editor-drawer__unsaved')?.textContent.trim()", '1 unsaved change');

    // Cancel: closed, nothing kept.
    $page->click('#rg-admin-persona-editor .rg-admin-drawer__footer .rg-admin-button--secondary');
    waitForScript($page, "document.getElementById('rg-admin-persona-editor') === null");
    expect($this->persona->fresh()->personaProfile->character)->toBe('Dry and patient');

    $page->click("#rg-admin-persona-{$this->persona->id} .rg-admin-table__name");
    waitForScript($page, "document.getElementById('rg-admin-persona-character')?.value", 'Dry and patient');

    // Engagement: High, the last of four.
    $page->click('.rg-admin-persona__segmented[aria-label="Engagement"] [role="radio"]:last-child');
    waitForScript($page, "document.querySelector('.rg-admin-editor-drawer__unsaved')?.textContent.trim()", '1 unsaved change');
    $page->click('#rg-admin-persona-editor .rg-admin-drawer__footer .rg-admin-button--primary');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Changes to Night Owl saved');
    waitForScript($page, "document.getElementById('rg-admin-persona-editor') === null");

    expect($this->persona->fresh()->personaProfile->engagement)->toBe(PersonaEngagement::High);
});
