<?php

namespace App\Support\VisualRegression;

final readonly class VisualScreenshotTarget
{
    public function __construct(
        public string $name,
        public string $routeName,
        public string $waitSelector,
        public int $viewportWidth,
        public int $viewportHeight,
        public string $outputFile,
    ) {}

    public function outputPath(bool $baseline = false): string
    {
        $directory = $baseline ? 'baselines' : 'current';

        return base_path("tests/Visual/{$directory}/{$this->outputFile}");
    }
}
