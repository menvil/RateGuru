<?php

use Illuminate\Support\Facades\Blade;

it('renders binary choice state through Alpine without stale static state classes', function () {
    $html = Blade::render('<x-ui.binary-choice selected="homemade" />');

    preg_match('/<button\s+([^>]*)>\s*Homemade/s', $html, $homemadeMatches);
    preg_match('/class="([^"]*)"/', $homemadeMatches[1], $classMatches);

    expect($homemadeMatches[1])
        ->toContain('aria-pressed="true"')
        ->toContain('x-bind:aria-pressed')
        ->toContain('x-bind:class');

    expect($classMatches[1])
        ->not->toContain('bg-rg-goodSoft')
        ->not->toContain('bg-rg-accentSoft');
});
