<?php

use Illuminate\Support\Facades\Blade;

it('renders copy link button with url', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('https://rateguru.test/posts/1')
        ->toContain('data-testid="copy-link-button"');
});

it('renders copy link button with custom label', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" label="Share" />');

    expect($html)->toContain('Share');
});

it('renders alpine copy to clipboard behavior', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('x-data')
        ->toContain('copyToClipboard')
        ->toContain('navigator.clipboard')
        ->toContain('Copied');
});

it('url input is always visible by default', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('data-testid="copy-link-fallback-input"')
        ->not->toContain("'sr-only': ! manualCopy");
});

it('renders copy icon button inside the input field', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('absolute right-1 top-1')
        ->toContain('data-testid="share-copy-link"');
});

it('renders copy button hover affordance', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('hover:bg-rg-cardHover')
        ->toContain('hover:text-rg-text')
        ->toContain('focus-visible:ring-rg-accent');
});
