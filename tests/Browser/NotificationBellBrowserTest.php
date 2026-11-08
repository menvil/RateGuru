<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * The header's notifications: a dropdown that says whether it is open and
 * goes away as the other header menus do.
 */

it('closes the notifications on a click elsewhere and says whether they are open', function () {
    actingAs(User::factory()->create());

    $page = visit(route('feed'))->resize(1440, 900);
    $bell = '[data-testid="notification-bell"] > button';
    $shown = "document.querySelector('[data-testid=\"notifications-dropdown\"]').checkVisibility()";

    $page->assertAttribute($bell, 'aria-expanded', 'false');
    // And which list it opens: the one dropdown with that id.
    $page->assertAttribute($bell, 'aria-controls', 'notifications-dropdown');
    $page->assertScript("document.querySelectorAll('#notifications-dropdown').length === 1 && document.getElementById('notifications-dropdown').dataset.testid === 'notifications-dropdown'", true);

    $page->click($bell);
    waitForScript($page, $shown);
    $page->assertAttribute($bell, 'aria-expanded', 'true');

    $page->click('[data-testid="feed-content-shell"]');
    waitForScript($page, $shown, false);
    $page->assertAttribute($bell, 'aria-expanded', 'false');
});
