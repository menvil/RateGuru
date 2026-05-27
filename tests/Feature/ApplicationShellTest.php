<?php

it('renders the RateGuru shell', function () {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $matches);

    expect(strip_tags($matches[1] ?? ''))->toContain('RateGuru');
});

it('uses RateGuru dark background tokens in the app shell', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('bg-rg-bg')
        ->toContain('text-rg-text')
        ->toContain('bg-rg-topbar')
        ->toContain('border-rg-border');
});

it('renders app brand with hover affordance', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('hover:bg-rg-card')
        ->toContain('hover:text-rg-text')
        ->toContain('focus-visible:ring-rg-accent');
});

it('renders the app header like the PlateRate reference topbar', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('h-[60px]')
        ->toContain('max-w-[1440px]')
        ->toContain('md:grid-cols-[minmax(0,1fr)_minmax(280px,520px)_minmax(0,1fr)]')
        ->toContain('Search tags, users, dishes')
        ->toContain('data-testid="app-header-search"')
        ->toContain('text-[22px]')
        ->toContain('text-rg-accent2');
});

it('renders the PlateRate reference sidebar shell', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('data-testid="app-sidebar"')
        ->toContain('lg:grid-cols-[240px_minmax(0,1fr)]')
        ->toContain('CATEGORIES')
        ->toContain('TOP TAGS')
        ->toContain('About')
        ->toContain('Privacy');
});

it('keeps app layout route/profile decisions in the composer layer', function () {
    $view = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($view)
        ->not->toContain('$isFeedRoute = request()')
        ->not->toContain('$profileHref =')
        ->toContain('$isFeedRoute')
        ->toContain('$profileHref');
});

it('keeps sidebar data preparation out of the sidebar partial', function () {
    $view = file_get_contents(resource_path('views/layouts/partials/app-sidebar.blade.php'));

    expect($view)
        ->not->toContain('Tag::query')
        ->not->toContain('request(')
        ->not->toContain('route(')
        ->not->toContain('str(');
});
