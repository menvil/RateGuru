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
