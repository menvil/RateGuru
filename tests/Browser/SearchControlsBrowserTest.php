<?php

use App\Models\User;

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

it('keeps the desktop header distributed across its full width', function () {
    $page = visit(route('feed'))
        ->resize(1440, 900)
        ->wait(0.2)
        ->assertVisible('[data-testid="site-brand"]')
        ->assertVisible('[data-testid="app-header-search"]')
        ->assertVisible('[data-testid="desktop-header-theme"]');

    $geometry = $page->script(<<<'JS'
        (() => {
            const brand = document.querySelector('[data-testid="site-brand"]').getBoundingClientRect();
            const search = document.querySelector('[data-testid="app-header-search"]').getBoundingClientRect();
            const rightmostAction = document.querySelector('[data-testid="desktop-header-theme"]').getBoundingClientRect();

            return {
                brandLeft: Math.round(brand.left),
                searchLeft: Math.round(search.left),
                searchRight: Math.round(search.right),
                rightmostActionLeft: Math.round(rightmostAction.left),
                rightmostActionRight: Math.round(rightmostAction.right),
                viewport: window.innerWidth,
            };
        })()
    JS);

    expect($geometry['brandLeft'])->toBeLessThanOrEqual(24)
        ->and($geometry['searchLeft'])->toBeGreaterThanOrEqual(400)
        ->and($geometry['rightmostActionLeft'])->toBeGreaterThan($geometry['searchRight'])
        ->and($geometry['rightmostActionRight'])->toBeGreaterThanOrEqual($geometry['viewport'] - 24);
});

it('keeps authenticated desktop header actions aligned to the right', function () {
    User::factory()->create([
        'email' => 'desktop-header@rateguru.test',
    ]);

    $page = visit(route('login'))
        ->type('[data-testid="login-email"]', 'desktop-header@rateguru.test')
        ->type('[data-testid="login-password"]', 'password')
        ->click('[data-testid="login-submit"]')
        ->resize(1440, 900)
        ->wait(0.2)
        ->assertVisible('[data-testid="site-brand"]')
        ->assertVisible('[data-testid="app-header-search"]')
        ->assertVisible('[data-testid="header-user-menu-trigger"]');

    $geometry = $page->script(<<<'JS'
        (() => {
            const search = document.querySelector('[data-testid="app-header-search"]').getBoundingClientRect();
            const userMenu = document.querySelector('[data-testid="header-user-menu-trigger"]').getBoundingClientRect();

            return {
                searchLeft: Math.round(search.left),
                searchRight: Math.round(search.right),
                userMenuLeft: Math.round(userMenu.left),
                userMenuRight: Math.round(userMenu.right),
                viewport: window.innerWidth,
            };
        })()
    JS);

    expect($geometry['searchLeft'])->toBeGreaterThanOrEqual(400)
        ->and($geometry['userMenuLeft'])->toBeGreaterThan($geometry['searchRight'])
        ->and($geometry['userMenuRight'])->toBeGreaterThanOrEqual($geometry['viewport'] - 36);
});

it('keeps mobile search beside authenticated actions and opens an eighty five percent menu', function () {
    User::factory()->create([
        'email' => 'mobile-header@rateguru.test',
    ]);

    $page = visit(route('login'))
        ->type('[data-testid="login-email"]', 'mobile-header@rateguru.test')
        ->type('[data-testid="login-password"]', 'password')
        ->click('[data-testid="login-submit"]')
        ->resize(844, 390)
        ->wait(0.2);

    $headerGap = $page->script(<<<'JS'
        (() => {
            const search = document.querySelector('[data-testid="mobile-search-trigger"]').getBoundingClientRect();
            const actions = document.querySelector('[data-testid="header-auth-actions"]').getBoundingClientRect();

            return Math.round(actions.left - search.right);
        })()
    JS);

    expect($headerGap)->toBeGreaterThanOrEqual(0)
        ->and($headerGap)->toBeLessThanOrEqual(8);

    $page
        ->resize(390, 844)
        ->click('[data-testid="mobile-nav-trigger"]')
        ->assertVisible('[data-testid="mobile-nav-panel"]')
        ->assertVisible('[data-testid="mobile-nav-theme"] [data-testid="theme-option-system"]')
        ->assertVisible('[data-testid="mobile-nav-theme"] [data-testid="theme-option-light"]')
        ->assertVisible('[data-testid="mobile-nav-theme"] [data-testid="theme-option-dark"]');

    $drawerGeometry = $page->script(<<<'JS'
        (() => {
            const drawer = document.querySelector('[data-testid="mobile-nav-panel"]').getBoundingClientRect();

            return {
                width: Math.round(drawer.width),
                expected: Math.round(window.innerWidth * 0.85),
            };
        })()
    JS);

    expect(abs($drawerGeometry['width'] - $drawerGeometry['expected']))->toBeLessThanOrEqual(1);
});
