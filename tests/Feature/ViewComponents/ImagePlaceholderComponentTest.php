<?php

use Illuminate\Support\Facades\Blade;

it('renders a labeled square placeholder by default', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.image-placeholder label="Spicy ramen" />
    BLADE);

    expect($html)
        ->toContain('Spicy ramen')
        ->toContain('aspect-square')
        ->toContain('data-ratio="square"')
        ->toContain('role="img"')
        ->not->toContain('<img');
});

it('supports ratio variants', function (string $ratio, string $expectedClass) {
    $html = Blade::render(<<<BLADE
        <x-ui.image-placeholder label="Chef plate" ratio="$ratio" />
    BLADE);

    expect($html)
        ->toContain('Chef plate')
        ->toContain($expectedClass)
        ->toContain('data-ratio="'.$ratio.'"');
})->with([
    'square' => ['square', 'aspect-square'],
    'video' => ['video', 'aspect-video'],
    'portrait' => ['portrait', 'aspect-[3/4]'],
]);
