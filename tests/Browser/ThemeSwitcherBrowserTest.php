<?php

it('theme switcher is visible on the feed page', function () {
    visit(route('feed'))
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-switcher"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-system"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-light"]')
        ->assertVisible('[data-testid="desktop-header-theme"] [data-testid="theme-option-dark"]');
});

it('can switch to light theme via theme switcher', function () {
    visit(route('feed'))
        ->click('[data-testid="desktop-header-theme"] [data-testid="theme-option-light"]')
        ->assertAttributeContains('html[lang]', 'data-theme', 'light');
});

it('can switch to dark theme via theme switcher', function () {
    visit(route('feed'))
        ->click('[data-testid="desktop-header-theme"] [data-testid="theme-option-dark"]')
        ->assertAttributeContains('html[lang]', 'data-theme', 'dark');
});
