<?php

/*
 * The header's language switcher: a list of languages opened and closed by
 * its button, which says whether it is open.
 */

it('says whether its list is open, through opening it and closing it again', function (string $close) {
    $page = visit(route('feed'))->resize(1440, 900);
    // The switcher the desktop header shows; the phone's menu has its own.
    $switcher = "[...document.querySelectorAll('[data-testid=\"language-switcher\"]')].find((switcher) => switcher.checkVisibility())";
    $shown = "{$switcher}.querySelector('[data-testid=\"locale-switcher-menu\"]').checkVisibility()";
    $expanded = "{$switcher}.querySelector('[data-testid=\"locale-switcher-trigger\"]').getAttribute('aria-expanded')";

    $page->assertScript($expanded, 'false');

    $page->script("{$switcher}.querySelector('[data-testid=\"locale-switcher-trigger\"]').click()");
    waitForScript($page, $shown);
    $page->assertScript($expanded, 'true');

    match ($close) {
        'Escape' => $page->keys(':root', 'Escape'),
        'a click elsewhere' => $page->click('[data-testid="feed-content-shell"]'),
    };

    waitForScript($page, $shown, false);
    $page->assertScript($expanded, 'false');
})->with(['Escape', 'a click elsewhere']);
