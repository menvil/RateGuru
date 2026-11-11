<?php

namespace App\Support\Games\Publishing;

/** One line of the publish report: what was checked, and whether it passed. */
final readonly class ReadinessLine
{
    public function __construct(
        public string $area,
        public bool $ok,
        public string $text,
    ) {}
}
