<?php

it('core ui components use theme token classes', function (string $path) {
    expect(file_exists(resource_path($path)))->toBeTrue();
    expect(file_get_contents(resource_path($path)))->toContain('rg-');
})->with([
    'views/components/ui/button.blade.php',
    'views/components/ui/card.blade.php',
    'views/components/ui/input.blade.php',
    'views/components/ui/textarea.blade.php',
    'views/components/ui/modal.blade.php',
    'views/components/ui/drawer.blade.php',
    'views/components/ui/skeleton.blade.php',
    'views/components/ui/empty-state.blade.php',
    'views/components/ui/error-message.blade.php',
    'views/components/ui/badge.blade.php',
]);

it('core button component does not use raw background colors', function () {
    $content = file_get_contents(resource_path('views/components/ui/button.blade.php'));

    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-black');
    expect($content)->not->toContain('bg-gray-');
    expect($content)->not->toContain('bg-zinc-');
});

it('core card component does not use raw background colors', function () {
    $content = file_get_contents(resource_path('views/components/ui/card.blade.php'));

    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-black');
    expect($content)->not->toContain('bg-gray-');
    expect($content)->not->toContain('bg-zinc-');
});

it('core input component does not use raw background colors', function () {
    $content = file_get_contents(resource_path('views/components/ui/input.blade.php'));

    expect($content)->not->toContain('bg-white');
    expect($content)->not->toContain('bg-black');
});
