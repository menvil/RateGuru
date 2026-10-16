<?php

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Posts in a real browser: the list at each width, approving from a row,
 * hiding with a chosen reason and taking it back with Undo, approving a
 * selection, Refresh, and the original image opened from the row menu with
 * the keyboard — measured from the page the browser built.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create());

    $dogs = Category::factory()->create(['name' => 'Dogs', 'slug' => 'dogs']);
    $this->luna = Post::factory()->pending()->withImage()->for(User::factory()->create(['username' => 'luna.the.husky']))->create(['title' => 'Luna howling along', 'category_id' => $dogs->id, 'created_at' => now()->subHours(3)]);
    $this->pepper = Post::factory()->pending()->for(User::factory()->create(['username' => 'birdbrain_pepper']))->create(['title' => 'Pepper learned the doorbell', 'created_at' => now()->subHour()]);
    $this->rex = Post::factory()->published()->withImage()->create(['title' => 'Rex at the vet', 'reports_count' => 3]);
});

/** The titles of the rows on screen, in order. */
function postsOnScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[role="table"] [role="row"].rg-admin-posts__row')].map((row) => row.querySelector('.rg-admin-posts__title-text, .rg-admin-table__primary').textContent.trim())
    JS);
}

/** The text of the last toast on screen. */
function postsToast(): string
{
    return "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''";
}

it('draws the list in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/posts?status=all', $width);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll:not([aria-busy])')
            const grid = scroll.querySelector('[role="table"]')

            return {
                headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
                columns: [...document.querySelectorAll('[role="columnheader"]')].map((cell) => cell.textContent.trim()),
                scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe([
        'headings' => ['Posts'],
        'columns' => ['Select every post on this page', 'Image', 'Title', 'Author', 'Status', 'Reports', 'Created', 'Actions'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
    ])->and(postsOnScreen($page))->toBe(['Rex at the vet', 'Pepper learned the doorbell', 'Luna howling along']);
})->with([1440, 1280, 1024, 768, 390]);

it('approves a pending post from its row, which then leaves the queue', function () {
    $page = visitAdminEditor('/admin/posts');

    expect(postsOnScreen($page))->toBe(['Luna howling along', 'Pepper learned the doorbell']);

    $page->click("#rg-admin-post-{$this->luna->id} button[wire\\:click=\"approve({$this->luna->id})\"]");
    waitForScript($page, postsToast(), '“Luna howling along” approved and published');
    waitForScript($page, "document.querySelectorAll('.rg-admin-posts__row').length", 1);

    expect($this->luna->fresh()->status)->toBe(PostStatus::Published);
});

it('hides a post with a chosen reason only once one is given, and Undo publishes it again', function () {
    $page = visitAdminEditor('/admin/posts?status=published');

    $page->click("#rg-admin-post-{$this->rex->id} button[wire\\:click=\"askToHide({$this->rex->id})\"]");
    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Hide this post?');
    waitForScript($page, "document.querySelector('.rg-admin-dialog .rg-admin-button--primary').disabled");

    $page->click('.rg-admin-dialog .rg-admin-preset:first-child');
    waitForScript($page, "document.getElementById('rg-admin-moderation-reason').value", 'Spam');
    waitForScript($page, "! document.querySelector('.rg-admin-dialog .rg-admin-button--primary').disabled && document.querySelector('.rg-admin-dialog .rg-admin-preset').getAttribute('aria-pressed') === 'true'");

    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForScript($page, postsToast(), '“Rex at the vet” hidden');

    expect($this->rex->fresh()->status)->toBe(PostStatus::Hidden);

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, postsToast(), '“Rex at the vet” is visible again');

    expect($this->rex->fresh()->status)->toBe(PostStatus::Published);
});

it('approves the selected posts from the bulk bar', function () {
    $page = visitAdminEditor('/admin/posts');

    $page->click("#rg-admin-post-{$this->pepper->id}-select");
    waitForScript($page, "document.querySelector('.rg-admin-bulk-bar__count')?.textContent.trim()", '1 selected');
    waitForScript($page, "document.getElementById('rg-admin-posts-select-page').indeterminate");

    $page->click('.rg-admin-bulk-bar .rg-admin-button--primary');
    waitForScript($page, postsToast(), '1 post approved and published');
    waitForScript($page, "document.querySelector('.rg-admin-bulk-bar') === null");

    expect($this->pepper->fresh()->status)->toBe(PostStatus::Published)
        ->and($this->luna->fresh()->status)->toBe(PostStatus::Pending);
});

it('reads the list again on Refresh, with what was submitted since', function () {
    $page = visitAdminEditor('/admin/posts');

    Post::factory()->pending()->create(['title' => 'Submitted meanwhile', 'created_at' => now()]);

    $page->click('.rg-admin-topbar__meta-actions button');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-posts__row')].some((row) => row.textContent.includes('Submitted meanwhile'))");

    expect(postsOnScreen($page))->toBe(['Luna howling along', 'Pepper learned the doorbell', 'Submitted meanwhile']);
});

it('opens the original image from the row menu with the keyboard, and Escape closes it back to the menu button', function () {
    $page = visitAdminEditor('/admin/posts');
    $trigger = "#rg-admin-post-{$this->luna->id}-actions";

    $page->keys($trigger, 'ArrowDown');
    waitForScript($page, "document.activeElement?.closest('{$trigger}-menu') !== null");
    waitForScript($page, "document.activeElement?.querySelector('.rg-admin-menu__label')?.textContent.trim()", 'Open original image');
    $page->keys(':focus', 'Enter');

    waitForScript($page, "document.getElementById('rg-admin-posts-viewer-title')?.textContent.trim()", 'Luna howling along');
    // The viewer has taken the focus, so it knows where to give it back.
    waitForScript($page, "document.activeElement?.closest('.rg-admin-viewer') !== null");

    $page->keys(':focus', 'Escape');
    waitForScript($page, "document.querySelector('.rg-admin-viewer') === null");
    waitForScript($page, 'document.activeElement?.id', ltrim($trigger, '#'));
});
