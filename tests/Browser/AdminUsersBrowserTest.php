<?php

use App\Enums\UserStatus;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * People in a real browser: the list at each width, a sanction from the row
 * menu with the keyboard and Undo, and a person's editor in the drawer over
 * the list — its unsaved count, Cancel, a change of role saved after
 * confirming it, and a sanction from it with Undo.
 *
 * One kind of page per test: the test server reads the top bar's actions
 * from the first page it drew.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'daria_k']);
    actingAs($this->admin);

    $this->member = User::factory()->create(['username' => 'grumpy_max', 'name' => 'Max Wagner', 'trust_level' => 0, 'created_at' => now()->subDay()]);
});

it('draws the list in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitAdminEditor('/admin/users', $width);

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
        'headings' => ['Users'],
        'columns' => ['Username', 'Email', 'Role', 'Status', 'Trust', 'Posts', 'Reports', 'Actions'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
    ]);
})->with([1440, 1280, 1024, 768, 390]);

it('limits someone from the row menu with the keyboard, and Undo restores access', function () {
    $page = visitAdminEditor('/admin/users');
    $trigger = "#rg-admin-user-{$this->member->id}-actions";

    $page->keys($trigger, 'ArrowDown');
    waitForScript($page, "document.activeElement?.closest('{$trigger}-menu') !== null");

    // Down to Limit: Edit user, View posts, Mark trusted, Limit.
    foreach (range(1, 3) as $step) {
        $page->keys(':focus', 'ArrowDown');
    }
    waitForScript($page, "document.activeElement?.querySelector('.rg-admin-menu__label')?.textContent.trim()", 'Limit');
    $page->keys(':focus', 'Enter');

    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Limit grumpy_max?');
    confirmAdminDialog($page);
    waitForScript($page, "document.querySelector('#rg-admin-user-{$this->member->id} .rg-admin-badge')?.textContent.trim()", 'Limited');

    expect($this->member->fresh()->status)->toBe(UserStatus::Limited);

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, "document.querySelector('#rg-admin-user-{$this->member->id} .rg-admin-badge')?.textContent.trim()", 'Active');

    expect($this->member->fresh()->status)->toBe(UserStatus::Active);
});

it('counts the unsaved changes in a person\'s drawer, and keeps nothing on Cancel', function () {
    $page = visitAdminEditor('/admin/users');

    $page->click("#rg-admin-user-{$this->member->id} .rg-admin-table__name");
    waitForScript($page, "document.querySelector('#rg-admin-user-editor .rg-admin-drawer__title')?.textContent.trim()", 'grumpy_max', 10.0);
    waitForScript($page, 'location.search', "?user={$this->member->id}");

    $page->type('#rg-admin-user-name', 'Max W.');
    waitForScript($page, "document.querySelector('.rg-admin-editor-drawer__unsaved')?.textContent.trim()", '1 unsaved change');
    $page->click('.rg-admin-radio-card:last-child');
    waitForScript($page, "document.querySelector('.rg-admin-editor-drawer__unsaved')?.textContent.trim()", '2 unsaved changes');

    $page->click('#rg-admin-user-editor .rg-admin-drawer__footer .rg-admin-button--secondary');
    waitForScript($page, "document.getElementById('rg-admin-user-editor') === null");

    $page->click("#rg-admin-user-{$this->member->id} .rg-admin-table__name");
    waitForScript($page, "document.getElementById('rg-admin-user-name')?.value", 'Max Wagner');

    expect($this->member->fresh()->name)->toBe('Max Wagner');
});

it('saves a change of role from the drawer once it is confirmed over it', function () {
    $page = visitAdminEditor("/admin/users?user={$this->member->id}");

    waitForScript($page, "!! document.getElementById('rg-admin-user-editor')", true, 10.0);
    $page->click('.rg-admin-user__role [role="radio"]:nth-child(2)');
    waitForScript($page, "! document.querySelector('#rg-admin-user-editor .rg-admin-drawer__footer .rg-admin-button--primary').disabled");
    $page->click('#rg-admin-user-editor .rg-admin-drawer__footer .rg-admin-button--primary');

    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Change role to Moderator?');
    confirmAdminDialog($page);
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].at(-1)?.textContent.trim() ?? ''", 'Changes to grumpy_max saved');
    waitForScript($page, "document.getElementById('rg-admin-user-editor') === null");

    expect($this->member->fresh()->role->value)->toBe('moderator');
});

it('limits someone from their drawer, the drawer and the row both showing it, and Undo restores access', function () {
    $page = visitAdminEditor("/admin/users?user={$this->member->id}");

    waitForScript($page, "!! document.getElementById('rg-admin-user-editor')", true, 10.0);
    $page->script("[...document.querySelectorAll('#rg-admin-user-editor .rg-admin-reports__actions .rg-admin-button')].find((button) => button.textContent.trim() === 'Limit').click()");

    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Limit grumpy_max?');
    confirmAdminDialog($page);

    // The drawer stays open, over a list that shows the change too.
    waitForScript($page, "document.querySelector('#rg-admin-user-editor .rg-admin-user__status .rg-admin-badge')?.textContent.trim()", 'Limited');
    waitForScript($page, "document.querySelector('#rg-admin-user-{$this->member->id} .rg-admin-badge')?.textContent.trim()", 'Limited');

    expect($this->member->fresh()->status)->toBe(UserStatus::Limited);

    $page->click('.rg-admin-toast .rg-admin-toast__action');
    waitForScript($page, "document.querySelector('#rg-admin-user-{$this->member->id} .rg-admin-badge')?.textContent.trim()", 'Active');
    waitForScript($page, "document.querySelector('#rg-admin-user-editor .rg-admin-user__status .rg-admin-badge')?.textContent.trim()", 'Active');

    expect($this->member->fresh()->status)->toBe(UserStatus::Active);
});
