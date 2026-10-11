<?php

use App\Support\VisualRegression\VisualScreenshotTargets;

it('has an approved, non-empty baseline committed for every screenshot target', function () {
    $targets = app(VisualScreenshotTargets::class)->all();

    expect($targets)->not->toBeEmpty();

    foreach ($targets as $name => $target) {
        $path = $target->outputPath(baseline: true);

        expect(is_file($path))->toBeTrue("No approved baseline for {$name}")
            ->and(filesize($path))->toBeGreaterThan(0, "Empty approved baseline for {$name}");
    }
});
