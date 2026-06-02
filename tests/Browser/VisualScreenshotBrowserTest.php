<?php

use App\Models\Post;
use App\Models\User;
use App\Support\VisualRegression\VisualScreenshotTargets;
use Pest\Browser\Support\Screenshot;

use function Pest\Laravel\actingAs;

it('captures requested visual screenshot target', function () {
    $targetName = env('VISUAL_SCREENSHOT_TARGET', 'feed-desktop');
    $outputPath = env('VISUAL_SCREENSHOT_OUTPUT', base_path('tests/Visual/current/feed-desktop.png'));

    expect($targetName)->toBeString()->not->toBeEmpty();
    expect($outputPath)->toBeString()->not->toBeEmpty();

    $target = app(VisualScreenshotTargets::class)->get($targetName);

    $user = User::factory()->create([
        'name' => 'Visual Demo Chef',
        'username' => 'visual_demo',
        'email' => 'visual-demo@example.com',
    ]);

    if ($target->authenticated) {
        actingAs($user);
    }

    $post = Post::factory()
        ->for($user)
        ->published()
        ->create([
            'title' => 'Visual Baseline Feed Post',
            'description' => 'Stable screenshot content for RateGuru visual regression baselines.',
            'published_at' => now()->subMinute(),
        ]);

    $browserScreenshot = 'visual/'.$target->name;
    $browserScreenshotDirectory = dirname(Screenshot::path($browserScreenshot));

    if (! is_dir($browserScreenshotDirectory)) {
        mkdir($browserScreenshotDirectory, 0755, true);
    }

    $url = $target->routeModel === 'post'
        ? route($target->routeName, $post)
        : route($target->routeName);

    $page = visit($url)
        ->resize($target->viewportWidth, $target->viewportHeight)
        ->assertPresent($target->waitSelector)
        ->assertSee('Visual Baseline Feed Post');

    if ($target->clickSelector !== null) {
        $page = $page->click($target->clickSelector);
    }

    if ($target->afterClickWaitSelector !== null) {
        $page = $page
            ->assertVisible($target->afterClickWaitSelector)
            ->wait(0.2);
    }

    $page->screenshot(false, $browserScreenshot);

    $sourcePath = Screenshot::path($browserScreenshot);

    if (! is_dir(dirname($outputPath))) {
        mkdir(dirname($outputPath), 0755, true);
    }

    copy($sourcePath, $outputPath);

    expect(file_exists($outputPath))->toBeTrue()
        ->and(filesize($outputPath))->toBeGreaterThan(0);
});
