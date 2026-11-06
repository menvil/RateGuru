<?php

namespace App\Support\VisualRegression;

interface VisualScreenshotRunner
{
    public function capture(VisualScreenshotTarget $target, bool $baseline = false): void;
}
