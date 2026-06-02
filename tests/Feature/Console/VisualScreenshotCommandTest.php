<?php

use App\Support\VisualRegression\VisualScreenshotRunner;
use App\Support\VisualRegression\VisualScreenshotTarget;
use App\Support\VisualRegression\VisualScreenshotTargets;

it('resolves feed desktop screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('feed-desktop');

    expect($target->name)->toBe('feed-desktop')
        ->and($target->routeName)->toBe('feed')
        ->and($target->waitSelector)->toBe('[data-testid="feed-page"]')
        ->and($target->viewportWidth)->toBe(1440)
        ->and($target->viewportHeight)->toBe(1000)
        ->and($target->outputPath())->toEndWith('tests/Visual/current/feed-desktop.png')
        ->and($target->outputPath(baseline: true))->toEndWith('tests/Visual/baselines/feed-desktop.png');
});

it('resolves feed mobile screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('feed-mobile');

    expect($target->name)->toBe('feed-mobile')
        ->and($target->routeName)->toBe('feed')
        ->and($target->waitSelector)->toBe('[data-testid="feed-page"]')
        ->and($target->viewportWidth)->toBe(390)
        ->and($target->viewportHeight)->toBe(844)
        ->and($target->outputPath())->toEndWith('tests/Visual/current/feed-mobile.png')
        ->and($target->outputPath(baseline: true))->toEndWith('tests/Visual/baselines/feed-mobile.png');
});

it('captures feed desktop screenshot through the command runner', function () {
    app()->bind(VisualScreenshotRunner::class, FakeVisualScreenshotRunner::class);

    $this->artisan('visual:screenshot', ['target' => 'feed-desktop'])
        ->assertExitCode(0);

    $path = base_path('tests/Visual/current/feed-desktop.png');

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(0);

    unlink($path);
});

it('fails clearly for an unknown visual screenshot target', function () {
    app()->bind(VisualScreenshotRunner::class, FakeVisualScreenshotRunner::class);

    $this->artisan('visual:screenshot', ['target' => 'missing-target'])
        ->expectsOutputToContain('Unknown visual screenshot target [missing-target].')
        ->expectsOutputToContain('Available targets: all, feed-desktop, feed-mobile')
        ->assertExitCode(1);
});

final class FakeVisualScreenshotRunner implements VisualScreenshotRunner
{
    public function capture(VisualScreenshotTarget $target, bool $baseline = false): void
    {
        $path = $target->outputPath($baseline);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, 'fake png');
    }
}
