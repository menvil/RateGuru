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
        ->toContain('rounded-full')
        ->toContain('bg-zinc-800/70')
        ->toContain('bg-emerald-500/15')
        ->toContain('bg-amber-500/15')
        ->toContain('bg-rose-500/15')
        ->toContain('bg-sky-500/15')
        ->toContain('px-2 py-0.5 text-xs')
        ->toContain('px-2.5 py-1 text-sm');
});
