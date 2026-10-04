<?php

use Illuminate\Support\Facades\Blade;

it('renders binary choice state through data attributes without stale static state classes', function () {
    $html = Blade::render('<x-ui.binary-choice selected="option_a" />');

    preg_match('/<button\s+([^>]*)>\s*Option A/s', $html, $firstOptionMatches);
    preg_match('/<button\s+([^>]*)>\s*Option B/s', $html, $secondOptionMatches);
    preg_match('/class="([^"]*)"/', $firstOptionMatches[1], $classMatches);
    $classTokens = preg_split('/\s+/', $classMatches[1]);

    expect($firstOptionMatches[1])
        ->toContain('data-state="active"')
        ->toContain('aria-pressed="true"')
        ->toContain('x-bind:data-state')
        ->toContain('x-bind:aria-pressed')
        ->not->toContain('x-bind:class')
        ->and($secondOptionMatches[1])
        ->toContain('data-state="inactive"');

    expect($classTokens)
        ->not->toContain('bg-rg-goodSoft')
        ->not->toContain('bg-rg-accentSoft')
        ->and($classMatches[1])
        ->toContain('data-[state=active]:bg-rg-goodSoft')
        ->toContain('data-[state=inactive]:bg-transparent');
});
