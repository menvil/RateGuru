<?php

use Symfony\Component\Finder\Finder;

it('does not use common raw color classes in active public ui views', function () {
    $forbidden = [
        'bg-black',
        'bg-white',
        'text-white',
        'text-black',
        'bg-gray-950',
        'bg-zinc-950',
        'bg-gray-900',
        'bg-zinc-900',
        'border-zinc-800',
        'border-gray-800',
    ];

    $allowlistedFiles = [
        'components/ui/avatar.blade.php',
        'components/ui/dish-placeholder.blade.php',
    ];

    $finder = Finder::create()
        ->files()
        ->in(resource_path('views'))
        ->name('*.blade.php')
        ->notPath('layouts/navigation.blade.php')
        ->notPath('components/secondary-button.blade.php')
        ->notPath('components/dropdown.blade.php')
        ->notPath('components/danger-button.blade.php')
        ->notPath('dashboard.blade.php')
        ->notPath('welcome.blade.php');

    $violations = [];

    foreach ($finder as $file) {
        $relativePath = $file->getRelativePathname();

        if (in_array($relativePath, $allowlistedFiles)) {
            continue;
        }

        $content = file_get_contents($file->getRealPath());

        foreach ($forbidden as $pattern) {
            if (str_contains($content, $pattern)) {
                $violations[] = "{$relativePath}: contains '{$pattern}'";
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Raw color violations found:\n" . implode("\n", $violations)
    );
});
