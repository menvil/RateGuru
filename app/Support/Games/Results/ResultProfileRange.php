<?php

namespace App\Support\Games\Results;

/** A result profile's name and the numbers of matches it stands for. */
final readonly class ResultProfileRange
{
    public function __construct(
        public string $name,
        public int $min,
        public int $max,
    ) {}
}
