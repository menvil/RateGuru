<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Js;

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
        // The fallback for contexts without the async clipboard API.
        ->toContain('execCommand');
});

it('shows check icon inside button when copied, not a paragraph below', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('x-show="copied"')
        ->toContain('data-testid="copy-check"')
        ->not->toContain('<p x-show="copied"');
});

it('url input is always visible by default', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" />');

    expect($html)
        ->toContain('data-testid="copy-link-fallback-input"')
        ->not->toMatch('/data-testid="copy-link-fallback-input"[^>]*sr-only/');
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

it('hands its labels to Alpine as JavaScript strings, so an apostrophe in a translation stays text', function () {
    $html = Blade::render('<x-share.copy-link-button url="https://rateguru.test/posts/1" label="Copier l\'URL" copied-label="C\'est copié" />');

    $copier = Js::from("Copier l'URL");
    $copied = Js::from("C'est copié");

    expect($html)
        ->toContain(':aria-label="copied ? '.$copied.' : '.$copier.'"')
        ->toContain(':aria-label="'.$copied.'"')
        // A label written into a quoted literal by hand: the browser decodes
        // the entity back to an apostrophe that ends the string early.
        ->not->toContain("'Copier l&#039;URL'");
});
