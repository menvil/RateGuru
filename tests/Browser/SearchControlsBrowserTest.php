<?php

it('keeps the logo and toggle search in landscape mobile', function () {
    $page = visit(route('feed'))
        ->resize(844, 390)
        ->wait(0.2)
        ->assertVisible('[data-testid="site-brand"]')
        ->assertVisible('[data-testid="mobile-search-trigger"]')
        ->assertMissing('[data-testid="app-header-search"]')
        ->click('[data-testid="mobile-search-trigger"]')
        ->assertVisible('[data-testid="mobile-search-row"]')
        ->assertVisible('[data-testid="mobile-search-submit"]')
        ->type('[data-testid="mobile-search-row"] input[name="search"]', 'landscape query')
        ->click('[data-testid="mobile-search-submit"]')
        ->assertQueryStringHas('search', 'landscape query')
        ->assertVisible('[data-testid="mobile-search-clear"]');

    $page
        ->click('[data-testid="mobile-search-clear"]')
        ->assertQueryStringMissing('search');
});

it('clears an active search from the desktop search field', function () {
    visit(route('feed', ['search' => 'desktop query']))
        ->resize(1280, 800)
        ->wait(0.2)
        ->assertVisible('[data-testid="app-header-search"]')
        ->assertVisible('[data-testid="desktop-search-clear"]')
        ->click('[data-testid="desktop-search-clear"]')
        ->assertQueryStringMissing('search');
});
