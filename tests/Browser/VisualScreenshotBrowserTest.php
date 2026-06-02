<?php

use App\Models\Post;
use App\Support\VisualRegression\VisualScreenshotTargets;
use Pest\Browser\Support\Screenshot;

it('captures requested visual screenshot target', function () {
    $targetName = env('VISUAL_SCREENSHOT_TARGET');
    $outputPath = env('VISUAL_SCREENSHOT_OUTPUT');

    expect($targetName)->toBeString()->not->toBeEmpty();
    expect($outputPath)->toBeString()->not->toBeEmpty();

    $target = app(VisualScreenshotTargets::class)->get($targetName);

    Post::factory()->published()->create([
        'title' => 'Visual Baseline Feed Desktop Post',
    ]);

    $browserScreenshot = 'visual/'.$target->name;
    $browserScreenshotDirectory = dirname(Screenshot::path($browserScreenshot));

    if (! is_dir($browserScreenshotDirectory)) {
        mkdir($browserScreenshotDirectory, 0755, true);
    }

    visit(route($target->routeName))
        ->resize($target->viewportWidth, $target->viewportHeight)
        ->assertPresent($target->waitSelector)
        ->assertSee('Visual Baseline Feed Desktop Post')
        ->screenshot(false, $browserScreenshot);

    $sourcePath = Screenshot::path($browserScreenshot);

    if (! is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0755, true);
    }

    copy($sourcePath, $outputPath);

    expect(file_exists($outputPath))->toBeTrue()
        ->and(filesize($outputPath))->toBeGreaterThan(0);
});
