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

    $page->click($bell);
    waitForScript($page, $shown);
    $page->assertAttribute($bell, 'aria-expanded', 'true');

    $page->click('[data-testid="feed-content-shell"]');
    waitForScript($page, $shown, false);
    $page->assertAttribute($bell, 'aria-expanded', 'false');
});
