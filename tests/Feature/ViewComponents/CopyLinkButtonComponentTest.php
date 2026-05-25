<?php

use Illuminate\Support\Facades\Blade;

it('renders copy link button with url', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('Copy link')
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

it('renders manual copy fallback input', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('data-testid="copy-link-fallback-input"')
        ->toContain('const success = document.execCommand(\'copy\')')
        ->toContain('manualCopy')
        ->toContain('Could not copy automatically. Copy the selected link manually.');
});

it('renders copy button hover affordance', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('hover:border-rg-border2')
        ->toContain('hover:bg-rg-cardHover')
        ->toContain('hover:text-rg-text')
        ->toContain('focus-visible:ring-rg-accent');
});
