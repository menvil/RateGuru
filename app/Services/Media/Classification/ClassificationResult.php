<?php

namespace App\Services\Media\Classification;

use App\Enums\ContentCategory;

/**
 * A classifier's answer: its score (0..1) per content category for the whole
 * asset, the same per frame for a video, its own answer trimmed for the
 * record, and what it took.
 */
final readonly class ClassificationResult
{
    /**
     * @param  array<string, float>  $scores  keyed by ContentCategory value
     * @param  list<array{at_ms: int|null, scores: array<string, float>}>  $frameScores
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $scores,
        public array $frameScores = [],
        public array $raw = [],
        public ?int $latencyMs = null,
        public ?int $costMicroUsd = null,
    ) {}

    public function score(ContentCategory $category): float
    {
        return (float) ($this->scores[$category->value] ?? 0.0);
    }
}
