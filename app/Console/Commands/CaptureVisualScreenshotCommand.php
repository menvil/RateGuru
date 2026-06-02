<?php

namespace App\Console\Commands;

use App\Support\VisualRegression\VisualScreenshotRunner;
use App\Support\VisualRegression\VisualScreenshotTargets;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CaptureVisualScreenshotCommand extends Command
{
    protected $signature = 'visual:screenshot
        {target=all : feed-desktop|feed-mobile|upload-modal|post-drawer|post-show|all}
        {--baseline : Save into tests/Visual/baselines instead of current}
        {--fresh : Run migrate:fresh --seed before screenshots}';

    protected $description = 'Capture visual regression screenshots.';

    public function handle(VisualScreenshotTargets $targets, VisualScreenshotRunner $runner): int
    {
        $targetName = (string) $this->argument('target');
        $baseline = (bool) $this->option('baseline');

        try {
            $resolvedTargets = $targets->resolve($targetName);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());
            $this->line('Available targets: '.implode(', ', ['all', ...$targets->names()]));

            return self::FAILURE;
        }

        if ((bool) $this->option('fresh')) {
            $this->call('migrate:fresh', ['--seed' => true]);
        }

        foreach ($resolvedTargets as $target) {
            $this->components->info("Capturing [{$target->name}]...");

            $runner->capture($target, $baseline);

            $this->components->info('Saved '.$target->outputPath($baseline));
        }

        return self::SUCCESS;
    }
}
