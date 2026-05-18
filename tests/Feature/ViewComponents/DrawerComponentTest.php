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
        ->toContain('bg-rg-card')
        ->toContain('text-rg-text')
        ->toContain('bottom-0')
        ->toContain('md:inset-y-0')
        ->toContain('md:right-0')
        ->toContain('w-full')
        ->toContain('md:max-w-lg');
});

it('keeps the root event host visible while only animated drawer nodes use x-show', function () {
    $html = Blade::render('<x-ui.drawer title="Post details">Drawer content</x-ui.drawer>');

    preg_match('/<div\s+([^>]*)>/', $html, $rootDivMatches);

    expect($rootDivMatches[1])
        ->toContain('x-data')
        ->toContain('x-cloak')
        ->toContain('x-on:open-drawer.window')
        ->toContain('x-on:close-drawer.window')
        ->toContain('x-on:keydown.escape.window')
        ->not->toContain('x-show')
        ->not->toContain('display: none;')
        ->and(substr_count($html, 'x-show="open"'))
        ->toBe(2);
});

it('targets open and close drawer events to the matching drawer id', function () {
    $html = Blade::render('<x-ui.drawer id="post-detail-drawer" title="Post details">Drawer content</x-ui.drawer>');

    expect($html)
        ->toContain('data-drawer-id="post-detail-drawer"')
        ->toContain('drawerId: \'post-detail-drawer\'')
        ->toContain('x-on:open-drawer.window="if ($event.detail?.id === drawerId) open = true"')
        ->toContain('x-on:close-drawer.window="if ($event.detail?.id === drawerId) open = false"');
});

it('renders a unique labelledby title id for each drawer instance', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.drawer title="First drawer">First content</x-ui.drawer>
        <x-ui.drawer title="Second drawer">Second content</x-ui.drawer>
    BLADE);

    preg_match_all('/aria-labelledby="([^"]+)"/', $html, $labelledByMatches);
    preg_match_all('/<h2 id="([^"]+)"/', $html, $titleIdMatches);

    expect($labelledByMatches[1])
        ->toHaveCount(2)
        ->sequence(
            fn ($id) => $id->toStartWith('drawer-title-'),
            fn ($id) => $id->toStartWith('drawer-title-'),
        )
        ->and($titleIdMatches[1])
        ->toBe($labelledByMatches[1])
        ->and(array_unique($labelledByMatches[1]))
        ->toHaveCount(2);
});
