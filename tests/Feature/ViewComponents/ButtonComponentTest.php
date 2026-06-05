<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI button with slot content', function () {
    $html = Blade::render('<x-ui.button>Upload</x-ui.button>');

    expect($html)
        ->toContain('Upload')
        ->toContain('bg-rg-accent')
        ->toContain('text-rg-onAccent');
});

it('renders supported button options', function () {
    $html = Blade::render('<x-ui.button variant="danger" size="lg" type="submit" disabled full-width>Delete</x-ui.button>');

    expect($html)
        ->toContain('Delete')
        ->toContain('type="submit"')
        ->toMatch('/\sdisabled(?=[\s>])/')
        ->toContain('bg-[rgba(239,68,68,0.12)]')
        ->toContain('w-full');
});

it('renders the danger button with pointer cursor', function () {
    $html = Blade::render('<x-danger-button>Delete Account</x-danger-button>');

    expect($html)
        ->toContain('Delete Account')
        ->toContain('cursor-pointer');
});
