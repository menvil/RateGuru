<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI button with slot content', function () {
    $html = Blade::render('<x-ui.button>Upload</x-ui.button>');

    expect($html)->toContain('Upload');
});

it('renders supported button options', function () {
    $html = Blade::render('<x-ui.button variant="danger" size="lg" type="submit" disabled full-width>Delete</x-ui.button>');

    expect($html)
        ->toContain('Delete')
        ->toContain('type="submit"')
        ->toMatch('/\sdisabled(?=[\s>])/')
        ->toContain('bg-rg-danger')
        ->toContain('w-full');
});
