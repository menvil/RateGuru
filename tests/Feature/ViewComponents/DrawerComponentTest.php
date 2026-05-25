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
        ->toContain('motion-safe:transition-opacity')
        ->toContain('motion-safe:transform-gpu')
        ->toContain('fixed inset-0')
        ->toContain('bg-black/70')
        ->toContain('bg-rg-card')
        ->toContain('text-rg-text')
        ->toContain('bottom-0')
        ->toContain('md:inset-y-0')
        ->toContain('md:right-0')
        ->toContain('w-full')
        ->toContain('md:max-w-xl')
        ->toContain('lg:max-w-2xl');
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

it('honors side prop for desktop placement', function () {
    $right = Blade::render('<x-ui.drawer title="R" side="right">content</x-ui.drawer>');
    $left  = Blade::render('<x-ui.drawer title="L" side="left">content</x-ui.drawer>');

    expect($right)
        ->toContain('md:right-0')
        ->toContain('md:left-auto')
        ->toContain('md:border-l')
        ->not->toContain('md:left-0');

    expect($left)
        ->toContain('md:left-0')
        ->toContain('md:right-auto')
        ->toContain('md:border-r')
        ->not->toContain('md:right-0');
});

it('dispatches drawer-closed on all close paths', function () {
    $html = Blade::render('<x-ui.drawer title="T">content</x-ui.drawer>');

    // close button
    expect($html)->toContain('x-on:click="open = false; $dispatch(\'drawer-closed\', { id: drawerId })"');
    // escape key
    expect($html)->toContain('x-on:keydown.escape.window="open = false; $dispatch(\'drawer-closed\', { id: drawerId })"');
    // backdrop click — same handler string as close button, so exactly 2 x-on:click occurrences total
    expect(substr_count($html, "x-on:click=\"open = false; \$dispatch('drawer-closed'"))->toBe(2);
    // click.outside on aside
    expect($html)->toContain('@click.outside="open = false; $dispatch(\'drawer-closed\', { id: drawerId })"');
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
