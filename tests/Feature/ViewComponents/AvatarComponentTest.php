<?php

use Illuminate\Support\Facades\Blade;

it('renders an image when src is provided', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.avatar
            src="https://example.com/avatar.jpg"
            name="Ada Lovelace"
            size="md"
        />
    BLADE);

    expect($html)
        ->toContain('src="https://example.com/avatar.jpg"')
        ->toContain('alt="Ada Lovelace"')
        ->not->toContain('AL');
});

it('renders readable fallback initials when src is missing', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.avatar name="Grace Hopper" size="md" />
    BLADE);

    expect($html)
        ->toContain('GH')
        ->toContain('bg-zinc-800')
        ->toContain('text-zinc-100')
        ->not->toContain('<img');
});

it('supports sm md and lg sizes', function (string $size, string $expectedClass) {
    $html = Blade::render(<<<BLADE
        <x-ui.avatar name="Katherine Johnson" size="$size" />
    BLADE);

    expect($html)->toContain($expectedClass);
})->with([
    'sm' => ['sm', 'size-8'],
    'md' => ['md', 'size-10'],
    'lg' => ['lg', 'size-12'],
]);
