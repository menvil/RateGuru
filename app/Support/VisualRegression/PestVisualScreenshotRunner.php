<?php

namespace App\Support\VisualRegression;

use Dotenv\Dotenv;
use Symfony\Component\Process\Process;

final class PestVisualScreenshotRunner implements VisualScreenshotRunner
{
    public function capture(VisualScreenshotTarget $target, bool $baseline = false): void
    {
        $this->process($target, $baseline)->mustRun();
    }

    /** The screenshot suite's run, built but not started. */
    public function process(VisualScreenshotTarget $target, bool $baseline): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('vendor/bin/pest'),
            base_path('tests/Browser/VisualScreenshotBrowserTest.php'),
        ], base_path(), $this->environment($target, $baseline));

        $process->setTimeout(120);

        return $process;
    }

    /**
     * The child's environment: the target, and none of what .env set.
     *
     * Artisan has loaded .env into this process's environment, and a child
     * inherits it; phpunit.xml only sets a test value for a variable that is
     * not set already. So the child would run against .env's database — the
     * developer's own — and the browser suite refreshes its database. Every
     * variable .env names is removed, as `php artisan test` does, and the
     * test database and environment from phpunit.xml apply.
     *
     * @return array<string, string|false>
     */
    private function environment(VisualScreenshotTarget $target, bool $baseline): array
    {
        $file = app()->environmentFilePath();
        $loaded = is_file($file) ? array_keys(Dotenv::parse((string) file_get_contents($file))) : [];

        return [
            ...array_fill_keys($loaded, false),
            'VISUAL_SCREENSHOT_TARGET' => $target->name,
            'VISUAL_SCREENSHOT_OUTPUT' => $target->outputPath($baseline),
        ];
    }
}
