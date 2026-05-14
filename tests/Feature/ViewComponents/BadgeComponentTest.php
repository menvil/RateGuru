<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI badge with slot content', function () {
    $html = Blade::render('<x-ui.badge>Italian</x-ui.badge>');

    expect($html)->toContain('Italian');
});

it('renders supported badge variants and sizes', function () {
    $html = Blade::render(
        '<x-ui.badge variant="neutral">Italian</x-ui.badge>
        <x-ui.badge variant="success" size="sm">Open</x-ui.badge>
        <x-ui.badge variant="warning" size="md">Pending</x-ui.badge>
        <x-ui.badge variant="danger">Closed</x-ui.badge>
        <x-ui.badge variant="accent">Popular</x-ui.badge>',
    );

    expect($html)
        ->toContain('Italian')
        ->toContain('Open')
        ->toContain('Pending')
        ->toContain('Closed')
        ->toContain('Popular')
        ->toContain('rounded-rgPill')
        ->toContain('bg-rg-card2')
        ->toContain('bg-rg-goodSoft')
        ->toContain('bg-[rgba(245,158,11,0.12)]')
        ->toContain('bg-[rgba(239,68,68,0.12)]')
        ->toContain('bg-rg-accentSoft')
        ->toContain('px-2 py-0.5 text-[11px]')
        ->toContain('px-2.5 py-1 text-xs');
});
