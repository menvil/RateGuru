<?php

use Illuminate\Support\Facades\Blade;

it('renders copy link button with share-copy-link test id', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)->toContain('data-testid="share-copy-link"');
});

it('copy link button uses navigator clipboard', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)->toContain('navigator.clipboard');
});

it('copy link button has fallback for non-secure contexts', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)->toContain('execCommand');
});
