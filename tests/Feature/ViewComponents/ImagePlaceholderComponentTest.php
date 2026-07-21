<?php

use Illuminate\Support\Facades\Blade;

it('renders a labeled square placeholder by default', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.image-placeholder label="Post preview" />
    BLADE);

    expect($html)
        ->toContain('Post preview')
        ->toContain('data-ui="image-placeholder"')
        ->toContain('aspect-square')
        ->toContain('data-ratio="square"')
        ->toContain('role="img"')
        ->not->toContain('<img');
});

it('supports ratio variants', function (string $ratio, string $expectedClass) {
    $html = Blade::render(<<<BLADE
        <x-ui.image-placeholder label="Image preview" ratio="$ratio" />
    BLADE);

    expect($html)
        ->toContain('Image preview')
        ->toContain($expectedClass)
        ->toContain('data-ratio="'.$ratio.'"');
})->with([
    'square' => ['square', 'aspect-square'],
    'video' => ['video', 'aspect-video'],
    'portrait' => ['portrait', 'aspect-[3/4]'],
]);
