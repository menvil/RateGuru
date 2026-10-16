<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Comments in a real browser: the list at each width, hiding a comment with
 * a chosen reason and taking it back with Undo, restoring one, and the search
 * read on the server — measured from the page the browser built.
 */
beforeEach(function () {
    actingAs(User::factory()->admin()->create());

    $rex = Post::factory()->published()->create(['title' => 'Rex at the vet']);
    $this->spam = Comment::factory()->for($rex)->create(['body' => 'Visit pet-deals24.top for cheap meds', 'reports_count' => 5, 'created_at' => now()->subMinutes(3)]);
    $this->hidden = Comment::factory()->for($rex)->create(['body' => 'People who dress pets in costumes', 'status' => CommentStatus::Hidden, 'created_at' => now()->subMinutes(2)]);
    $this->kind = Comment::factory()->for($rex)->create(['body' => 'Get well soon, Rex', 'created_at' => now()->subMinute()]);
});

/** The texts of the rows on screen, in order. */
function commentsOnScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[role="table"] .rg-admin-comments__row .rg-admin-comments__text')].map((text) => text.textContent.trim())
    JS);
}

it('draws the list in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/comments', $width);

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
        'headings' => ['Comments'],
        'columns' => ['Comment', 'Author', 'Post', 'Reports', 'State', 'Created', 'Actions'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
    ])->and(commentsOnScreen($page))->toBe(['Get well soon, Rex', 'People who dress pets in costumes', 'Visit pet-deals24.top for cheap meds']);
})->with([1440, 1280, 1024, 768, 390]);

it('hides a comment with a chosen reason, and Undo shows it again', function () {
    $page = visitAdminEditor('/admin/comments');

    $page->click("#rg-admin-comment-{$this->spam->id} button[wire\\:click=\"askToHide({$this->spam->id})\"]");
    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Hide this comment?');
    $page->click('.rg-admin-dialog .rg-admin-preset:first-child');
    waitForScript($page, "document.getElementById('rg-admin-moderation-reason').value", 'Spam');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');

    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", "Comment #{$this->spam->id} hidden");
    waitForScript($page, "document.querySelector('#rg-admin-comment-{$this->spam->id} .rg-admin-badge-note .rg-admin-badge')?.textContent.trim()", 'Hidden by moderation');

    expect($this->spam->fresh()->status)->toBe(CommentStatus::Hidden);

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, "document.querySelector('#rg-admin-comment-{$this->spam->id} .rg-admin-badge-note .rg-admin-badge')?.textContent.trim()", 'Visible');

    expect($this->spam->fresh()->status)->toBe(CommentStatus::Visible);
});

it('restores a hidden comment at once', function () {
    $page = visitAdminEditor('/admin/comments?status=hidden');

    $page->click("#rg-admin-comment-{$this->hidden->id} button[wire\\:click=\"restore({$this->hidden->id})\"]");
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", "Comment #{$this->hidden->id} restored");

    expect($this->hidden->fresh()->status)->toBe(CommentStatus::Visible);
});

it('searches on the server as it is typed', function () {
    $page = visitAdminEditor('/admin/comments');

    $page->type('#rg-admin-comments-search', 'well soon');
    waitForScript($page, 'new URLSearchParams(location.search).get("q")', 'well soon');
    waitForScript($page, "document.querySelectorAll('.rg-admin-comments__row').length", 1);

    expect(commentsOnScreen($page))->toBe(['Get well soon, Rex']);
});
