<?php

use Illuminate\Support\Facades\Blade;

it('renders a checkbox that keeps the attributes it is given', function () {
    $html = Blade::render('<x-ui.checkbox id="remember" name="remember" x-model="remember" data-testid="remember-box" class="mt-0.5" checked />');

    expect($html)
        ->toContain('type="checkbox"')
        ->toContain('id="remember"')
        ->toContain('name="remember"')
        ->toContain('x-model="remember"')
        ->toContain('data-testid="remember-box"')
        ->toContain('checked="checked"')
        ->toContain('mt-0.5');
});

it('leaves a box it is not told to check unchecked', function () {
    expect(Blade::render('<x-ui.checkbox name="remember" />'))->not->toContain('checked="checked"');
});

it('fills a checked box with the accent so the white check shows, and draws no focus ring', function () {
    $html = Blade::render('<x-ui.checkbox name="remember" />');

    expect($html)
        ->toContain('bg-rg-card')
        ->toContain('checked:bg-rg-accent')
        ->toContain('checked:border-rg-accent')
        ->toContain('focus:ring-0')
        ->toContain('focus:ring-offset-0')
        ->toContain('focus:outline-none')
        ->not->toContain('focus:ring-2')
        ->not->toContain('focus:ring-rg-accent');
});
