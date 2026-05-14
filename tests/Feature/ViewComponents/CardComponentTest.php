<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI card with slot content', function () {
    $html = Blade::render('<x-ui.card>Card content</x-ui.card>');

    expect($html)->toContain('Card content');
});

it('renders supported card options', function () {
    $html = Blade::render('<x-ui.card variant="interactive" padding="lg">Content</x-ui.card>');

    expect($html)
        ->toContain('Content')
        ->toContain('rounded-rgCard')
        ->toContain('border-rg-border')
        ->toContain('hover:bg-rg-cardHover')
        ->toContain('p-6');
});
