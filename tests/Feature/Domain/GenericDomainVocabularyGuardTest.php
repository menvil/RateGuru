<?php

use Symfony\Component\Finder\Finder;

it('keeps active code free of retired domain identifiers', function () {
    $files = Finder::create()
        ->files()
        ->in([
            app_path(),
            base_path('config'),
            database_path(),
            resource_path('views'),
            base_path('routes'),
        ])
        ->name(['*.php', '*.blade.php']);

    $forbiddenPatterns = [
        '/\bcuisines?\b/i',
        '/cuisine[_-]|[_-]cuisine|Cuisine/',
        '/\b(?:homemade|restaurant)\b/i',
        '/Origin(?:Type|Vote|Voting)/',
        '/origin_(?:truth|votes)|(?:vote|votes)_origin/',
        '/dish-placeholder|DishPlaceholder/',
        '/cuisine-chip/',
        '/category_option_id|categoryOptionId|categoryOption\s*\(/',
        '/sidebarGroupOptionIds/',
    ];
    $violations = [];

    foreach ($files as $file) {
        $content = file_get_contents($file->getRealPath());

        foreach ($forbiddenPatterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $violations[] = $file->getRelativePathname().' matches '.$pattern;
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Retired domain vocabulary found:\n".implode("\n", $violations),
    );
});
