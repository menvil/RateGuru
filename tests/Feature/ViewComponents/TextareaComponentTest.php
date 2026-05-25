<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI textarea with name placeholder and slot content', function () {
    $html = Blade::render('<x-ui.textarea name="description" placeholder="Describe the dish">Tasty pasta</x-ui.textarea>');

    expect($html)
        ->toContain('name="description"')
        ->toContain('placeholder="Describe the dish"')
        ->toContain('Tasty pasta');
});

it('renders supported textarea states', function () {
    $html = Blade::render(
        '<x-ui.textarea name="comment" rows="4" disabled error placeholder="Leave a comment">Needs more salt</x-ui.textarea>',
    );

    expect($html)
        ->toContain('name="comment"')
        ->toContain('rows="4"')
        ->toContain('placeholder="Leave a comment"')
        ->toContain('Needs more salt')
        ->toMatch('/\sdisabled(?:\s|>)/')
        ->toContain('aria-invalid="true"')
        ->toContain('border-[rgba(239,68,68,0.65)]')
        ->toContain('focus-visible:ring-2')
        ->toContain('focus-visible:ring-rg-accent/25')
        ->toContain('bg-rg-card2')
        ->toContain('text-rg-text');
});
