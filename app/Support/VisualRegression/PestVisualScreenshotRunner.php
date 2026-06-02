<?php

namespace App\Support\VisualRegression;

use Symfony\Component\Process\Process;

final class PestVisualScreenshotRunner implements VisualScreenshotRunner
{
    public function capture(VisualScreenshotTarget $target, bool $baseline = false): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('vendor/bin/pest'),
            base_path('tests/Browser/VisualScreenshotBrowserTest.php'),
            '--filter',
            'captures requested visual screenshot target',
        ], base_path(), [
            'VISUAL_SCREENSHOT_TARGET' => $target->name,
            'VISUAL_SCREENSHOT_OUTPUT' => $target->outputPath($baseline),
        ]);

        $process->setTimeout(120);
        $process->mustRun();
    }
}
