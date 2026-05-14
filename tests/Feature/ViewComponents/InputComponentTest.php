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
        ->toContain('border-rose-400/70')
        ->toContain('focus-visible:ring-2')
        ->toContain('bg-zinc-950/80')
        ->toContain('text-zinc-100');
});
