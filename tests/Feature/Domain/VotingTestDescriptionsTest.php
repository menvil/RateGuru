<?php

use Symfony\Component\Finder\Finder;

it('uses generic voting behavior wording in test descriptions', function () {
    $paths = [
        base_path('tests/Feature/Livewire'),
        base_path('tests/Feature/ViewComponents'),
    ];

    $files = collect(Finder::create()->files()->in($paths)->name('*Voting*Test.php'))
        ->push(new SplFileInfo(base_path('tests/Feature/Livewire/PostDrawerTest.php')))
        ->push(new SplFileInfo(base_path('tests/Feature/ViewComponents/PostCardComponentTest.php')));

    $descriptions = $files
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all("/it\\('([^']+)'/", file_get_contents($file->getRealPath()), $matches);

            return $matches[1] ?? [];
        })
        ->map(fn (string $description): string => strtolower($description))
        ->implode("\n");

    foreach ([
        'origin voting',
        'origin vote',
        'origin distribution',
        'origin controls',
        'origin badges',
        'cuisine voting',
        'cuisine vote',
        'cuisine distribution',
        'cuisine chips',
        'cuisine controls',
        'homemade button',
        'restaurant button',
        'italian button',
    ] as $legacyDescriptionTerm) {
        expect($descriptions)->not->toContain($legacyDescriptionTerm);
    }
});
