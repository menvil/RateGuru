<?php

use Illuminate\Support\Facades\Blade;

it('renders a UI drawer shell with title content and footer slots', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.drawer title="Post details">
            Fresh pasta review

            <x-slot:footer>
                Close actions
            </x-slot:footer>
        </x-ui.drawer>
    BLADE);

    expect($html)
        ->toContain('Post details')
        ->toContain('Fresh pasta review')
        ->toContain('Close actions');
});

it('supports right side desktop behavior and mobile safe layout', function () {
    $html = Blade::render('<x-ui.drawer title="Post details" side="right" size="lg">Drawer content</x-ui.drawer>');

    expect($html)
        ->toContain('x-show')
        ->toContain('@click.outside')
        ->toContain('x-on:keydown.escape.window')
        ->toContain('fixed inset-0')
        ->toContain('bg-black/70')
        ->toContain('bg-zinc-950')
        ->toContain('text-zinc-100')
        ->toContain('inset-y-0 right-0')
        ->toContain('w-full')
        ->toContain('sm:max-w-lg');
});
