<?php

use Illuminate\Support\Facades\Blade;

it('renders trigger and content slots with Alpine dropdown shell behavior', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.dropdown>
            <x-slot:trigger>
                <button type="button">Open user menu</button>
            </x-slot:trigger>

            <x-slot:content>
                <a href="/profile">Profile settings</a>
            </x-slot:content>
        </x-ui.dropdown>
    BLADE);

    expect($html)
        ->toContain('Open user menu')
        ->toContain('Profile settings')
        ->toContain('x-data="{ open: false }"')
        ->toContain('@click.outside="open = false"')
        ->toContain('bg-zinc-950')
        ->not->toContain('wire:');
});
