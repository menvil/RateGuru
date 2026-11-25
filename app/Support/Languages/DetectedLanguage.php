<?php

namespace App\Support\Languages;

/**
 * A text's language as a detector named it, and how much that counts, 0 to 1.
 */
final readonly class DetectedLanguage
{
    public function __construct(
        public string $language,
        public float $weight,
    ) {}
}
