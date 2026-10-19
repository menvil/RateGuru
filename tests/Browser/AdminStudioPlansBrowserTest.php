<?php

use App\Enums\StudioPlanPublicationMode;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * Content Studio's Publications & plans in a real browser: the list and the
 * editor at each width, every step of the editor, creating an empty draft
 * from the top bar, the unsaved count, Discard and Save, opening a draft
 * from the list, and deleting one.
 *
 * No photo is chosen from a file here: the browser test server hands the
 * application no uploaded files, so a Livewire upload never arrives. Photos
 * are covered through Livewire in StudioPlanImageUiTest and
 * CreateStudioPlanPageTest, and on their own in SetStudioPlanImageActionTest.
 *
 * One kind of page per test: the test server reads the top bar's actions
 * from the first page it drew.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create(['username' => 'daria_k']));

    $this->persona = User::factory()->persona()->create(['username' => 'night_owl', 'name' => 'Night Owl', 'display_name' => 'Night Owl']);
    $this->plan = StudioPlan::factory()->authoredBy($this->persona)->create([
        'title' => 'A quiet harbour at dawn, with the boats still asleep',
        'description' => 'Boats at dawn.',
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse('2019-05-06 07:08:00'),
    ]);
});

it('draws the list in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/studio-plans', $width);

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
        'headings' => ['Publications & plans'],
        'columns' => ['Publication', 'Author', 'Image', 'Publication date', 'State', 'Last updated', 'Actions'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
    ]);
})->with([1440, 1280, 1024, 390]);

it('draws every step of the editor at every width without scrolling the page sideways', function (int $width) {
    $page = visitAdminEditor("/admin/studio-plans/{$this->plan->id}/edit", $width);

    $steps = [];

    foreach (['Image', 'Post', 'Discussion', 'Publication'] as $index => $label) {
        $page->script("[...document.querySelectorAll('.rg-admin-studio__step')][{$index}].click()");
        waitForScript($page, "document.querySelector('.rg-admin-studio__position')?.textContent.trim()", 'Step '.($index + 1).' of 4 · '.$label);

        $steps[$label] = $page->script(<<<'JS'
            (() => ({
                current: document.querySelector('.rg-admin-studio__step[aria-current="step"] .rg-admin-studio__step-label')?.textContent.trim(),
                fieldsFit: [...document.querySelectorAll('.rg-admin-studio__step-body input:not([type="file"]), .rg-admin-studio__step-body textarea, .rg-admin-studio__step-body button')]
                    .every((field) => field.getBoundingClientRect().right <= window.innerWidth + 0.5),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                saveReachable: (() => {
                    const save = document.querySelector('.rg-admin-topbar__editor .rg-admin-button--primary')
                    const box = save?.getBoundingClientRect()

                    return !! box && box.right <= window.innerWidth + 0.5 && box.width > 0
                })(),
            }))()
        JS);
    }

    expect($steps)->toBe([
        'Image' => ['current' => 'Image', 'fieldsFit' => true, 'overflow' => false, 'saveReachable' => true],
        'Post' => ['current' => 'Post', 'fieldsFit' => true, 'overflow' => false, 'saveReachable' => true],
        'Discussion' => ['current' => 'Discussion', 'fieldsFit' => true, 'overflow' => false, 'saveReachable' => true],
        'Publication' => ['current' => 'Publication', 'fieldsFit' => true, 'overflow' => false, 'saveReachable' => true],
    ]);
})->with([1440, 1280, 1024, 390]);

it('creates an empty draft from the top bar without anything typed, and opens its editor', function () {
    $page = visitAdminEditor('/admin/studio-plans/create');

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => true]);

    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');

    $plan = null;
    eventually(function () use (&$plan): void {
        $plan = StudioPlan::query()->whereKeyNot($this->plan->id)->first();
        expect($plan)->not->toBeNull();
    });

    waitForScript($page, 'location.pathname', "/admin/studio-plans/{$plan->id}/edit");
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", "Untitled draft #{$plan->id} created");

    expect($plan->title)->toBeNull()->and($plan->author_user_id)->toBeNull();
});

it('keeps what was typed across the four steps, counts it, puts it back on Discard, and saves it from the top bar', function () {
    $page = visitAdminEditor("/admin/studio-plans/{$this->plan->id}/edit");

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    $page->click('.rg-admin-studio__footer .rg-admin-button--primary');
    waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim()", 'Step 2 of 4 · Post');

    $page->script("(() => { const field = document.getElementById('rg-admin-studio-title'); field.value = 'Harbour, renamed'; field.dispatchEvent(new Event('input', { bubbles: true })) })()");
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    // Around every step and back: the typed title stays, and moving is no change.
    foreach ([3, 4, 1, 2] as $step) {
        $page->script('[...document.querySelectorAll(".rg-admin-studio__step")]['.($step - 1).'].click()');
        waitForScript($page, "document.querySelector('.rg-admin-studio__position').textContent.trim().startsWith('Step {$step} of 4')");
    }

    waitForScript($page, "document.getElementById('rg-admin-studio-title').value", 'Harbour, renamed');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    $page->click('.rg-admin-topbar__editor button:first-of-type');
    waitForScript($page, "document.getElementById('rg-admin-studio-title').value", 'A quiet harbour at dawn, with the boats still asleep');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    $page->script("(() => { const field = document.getElementById('rg-admin-studio-title'); field.value = 'Harbour, saved'; field.dispatchEvent(new Event('input', { bubbles: true })) })()");
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Draft saved');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect($this->plan->fresh())->title->toBe('Harbour, saved')->lock_version->toBe(2);
});

it('opens a draft\'s editor from the list', function () {
    $page = visitAdminEditor('/admin/studio-plans');

    $page->click('.rg-admin-table__name--link');

    waitForScript($page, 'location.pathname', "/admin/studio-plans/{$this->plan->id}/edit");
    waitForScript($page, "document.querySelector('h1')?.textContent.trim()", 'A quiet harbour at dawn, with the boats still asleep');
});

it('deletes a draft from its editor once confirmed, and lands on the list without it', function () {
    $page = visitAdminEditor("/admin/studio-plans/{$this->plan->id}/edit");

    $page->click('.rg-admin-page-header .rg-admin-button');
    waitForScript($page, "document.getElementById('rg-admin-studio-plan-delete-title')?.textContent.trim()", 'Delete this draft?');
    waitForScript($page, 'document.activeElement?.textContent.trim()', 'Cancel');

    $page->click('#rg-admin-studio-plan-delete .rg-admin-button--danger');

    waitForScript($page, 'location.pathname', '/admin/studio-plans');
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'A quiet harbour at dawn, with the boats still asleep deleted');

    expect(StudioPlan::query()->find($this->plan->id))->toBeNull()
        ->and($page->script("document.querySelector('.rg-admin-empty-state__title')?.textContent.trim()"))->toBe('No publication plans yet');
});

it('counts a link as unsaved only while the link is the source', function () {
    $page = visitAdminEditor("/admin/studio-plans/{$this->plan->id}/edit");

    $page->script("document.querySelector('.rg-admin-studio__segmented [role=\"radio\"]:last-child').click()");
    waitForScript($page, "!! document.getElementById('rg-admin-studio-image-url')");

    $page->script("(() => { const field = document.getElementById('rg-admin-studio-image-url'); field.value = 'https://example.com/photo.jpg'; field.dispatchEvent(new Event('input', { bubbles: true })) })()");
    waitForScript($page, "document.querySelector('.rg-admin-topbar__unsaved').textContent.trim()", '1 unsaved change');

    // Back to the computer: the link is dropped, and nothing is left unsaved.
    $page->script("document.querySelector('.rg-admin-studio__segmented [role=\"radio\"]:first-child').click()");
    waitForScript($page, "!! document.querySelector('.rg-admin-studio__dropzone')");
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect(adminEditorTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);
});
