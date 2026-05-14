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
        ->toContain('bg-rg-card2')
        ->toContain('text-rg-text');
});
