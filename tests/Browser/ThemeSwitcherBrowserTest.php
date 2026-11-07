<?php

it('theme switcher is visible on the feed page', function () {
    visit(route('feed'))
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-switcher"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-system"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-light"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-dark"]');
});

it('can switch to light theme via theme switcher', function () {
    // The headless browser prefers light, so the page already starts light:
    // the test goes dark first, so that light is something the click did.
    $page = visit(route('feed'))
        ->click('[data-testid="desktop-header-theme"] [data-testid="theme-option-dark"]')
        ->assertAttributeContains('html[lang]', 'data-theme', 'dark');

    $page->click('[data-testid="desktop-header-theme"] [data-testid="theme-option-light"]')
        ->assertAttributeContains('html[lang]', 'data-theme', 'light')
        ->assertAttributeContains('html[lang]', 'data-theme-preference', 'light');
});

it('shows a guest the theme they chose, after a reload too, in every switcher on the page', function () {
    $page = visit(route('feed'));
    $pressed = fn (string $option): string => "[...document.querySelectorAll('[data-testid=\"theme-option-{$option}\"]')].map((button) => button.getAttribute('aria-pressed')).join(',')";

    $page->script("[...document.querySelectorAll('[data-testid=\"theme-option-dark\"]')].find((button) => button.offsetParent !== null).click()");
    waitForScript($page, 'document.documentElement.dataset.themePreference', 'dark');

    $page->navigate(route('feed'));

    waitForScript($page, $pressed('dark'), 'true,true');

    expect($page->script($pressed('system')))->toBe('false,false');
});
