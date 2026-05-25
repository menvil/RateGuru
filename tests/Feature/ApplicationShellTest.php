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
        ->toContain('Search tags, users, dishes')
        ->toContain('data-testid="app-header-search"')
        ->toContain('text-[22px]')
        ->toContain('text-rg-accent2');
});
