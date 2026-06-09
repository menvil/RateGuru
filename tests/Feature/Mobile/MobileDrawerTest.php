<?php

use Illuminate\Support\Facades\Blade;

it('renders drawer shell with mobile full screen classes', function () {
    $html = Blade::render('<x-ui.drawer title="Post details">Content</x-ui.drawer>');

    expect($html)
        ->toContain('data-testid="drawer-shell"')
        ->toContain('w-full')
        ->toContain('bottom-0')
        ->toContain('max-h-[90vh]');
});

it('drawer uses full-screen sheet on mobile and side panel on desktop', function () {
    $html = Blade::render('<x-ui.drawer title="Post details">Content</x-ui.drawer>');

    expect($html)
        ->toContain('inset-x-0 bottom-0')
        ->toContain('md:inset-y-0')
        ->toContain('md:h-dvh');
});

it('close button exists and is accessible on mobile drawer', function () {
    $html = Blade::render('<x-ui.drawer title="Post details">Content</x-ui.drawer>');

    expect($html)
        ->toContain('data-testid="post-drawer-close"')
        ->toContain('aria-label="Close drawer"');
});
