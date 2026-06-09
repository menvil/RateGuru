<?php

it('renders feed with theme token classes', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('bg-rg-bg', false);
});

it('does not use raw dark background classes in app layout', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->not->toContain('bg-black');
    expect($layout)->not->toContain('bg-gray-950');
    expect($layout)->not->toContain('bg-zinc-950');
});

it('does not use raw dark background classes in guest layout', function () {
    $layout = file_get_contents(resource_path('views/layouts/guest.blade.php'));

    expect($layout)->not->toContain('bg-black');
    expect($layout)->not->toContain('bg-gray-950');
    expect($layout)->not->toContain('bg-zinc-950');
});

it('app layout header uses rg token classes', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('bg-rg-topbar');
    expect($layout)->toContain('border-rg-border');
    expect($layout)->toContain('bg-rg-bg');
});

it('drawer uses rg overlay token instead of raw black', function () {
    $drawer = file_get_contents(resource_path('views/components/ui/drawer.blade.php'));

    expect($drawer)->not->toContain('bg-black/70');
    expect($drawer)->toContain('bg-rg-overlay');
});
