<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI input with name placeholder and value', function () {
    $html = Blade::render('<x-ui.input name="title" placeholder="Dish title" value="Pizza" />');

    expect($html)
        ->toContain('name="title"')
        ->toContain('placeholder="Dish title"')
        ->toContain('value="Pizza"');
});

it('renders supported input states', function () {
    $html = Blade::render(
        '<x-ui.input name="email" type="email" disabled error placeholder="Email" value="chef@example.com" />',
    );

    expect($html)
        ->toContain('name="email"')
        ->toContain('type="email"')
        ->toContain('placeholder="Email"')
        ->toContain('value="chef@example.com"')
        ->toContain('disabled')
        ->toContain('aria-invalid="true"')
        ->toContain('border-[rgba(239,68,68,0.65)]')
        ->toContain('focus-visible:ring-2')
        ->toContain('focus-visible:ring-rg-accent/25')
        ->toContain('bg-rg-card2')
        ->toContain('disabled:bg-rg-surface')
        ->toContain('disabled:text-rg-muted')
        ->toContain('text-rg-text');
});

it('reads a URL field left to right in every language, and leaves every other field to the page', function () {
    // An address keeps its "://" and a closing "/" where they belong on a page read right to left.
    expect(Blade::render('<x-ui.input name="website" type="url" placeholder="https://" />'))->toContain('dir="ltr"')
        ->and(Blade::render('<x-ui.input name="title" />'))->not->toContain('dir=')
        ->and(Blade::render('<x-ui.input name="email" type="email" />'))->not->toContain('dir=')
        // A direction the caller gives is the one it gets, once.
        ->and(substr_count(Blade::render('<x-ui.input name="website" type="url" dir="auto" />'), 'dir='))->toBe(1);
});
