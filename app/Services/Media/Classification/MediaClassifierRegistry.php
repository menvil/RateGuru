<?php

namespace App\Services\Media\Classification;

use App\Enums\ClassificationMode;
use InvalidArgumentException;

/**
 * The classifiers that run on every new post's media, from
 * media.classification.classifiers: each a container binding of a
 * MediaContentClassifier and the mode it runs in — shadow (recorded,
 * deciding nothing) or active. None by default: nothing is classified, and
 * nothing is queued, until one is named there.
 */
final class MediaClassifierRegistry
{
    /** @return list<array{classifier: MediaContentClassifier, mode: ClassificationMode}> */
    public function configured(): array
    {
        $configured = [];

        foreach ((array) config('media.classification.classifiers', []) as $entry) {
            $binding = is_array($entry) ? ($entry['classifier'] ?? null) : null;
            $mode = is_array($entry) ? ClassificationMode::tryFrom((string) ($entry['mode'] ?? '')) : null;

            if (! is_string($binding) || $mode === null || $mode === ClassificationMode::Benchmark) {
                throw new InvalidArgumentException('Each media.classification.classifiers entry names a classifier and a mode, shadow or active.');
            }

            $classifier = app($binding);

            if (! $classifier instanceof MediaContentClassifier) {
                throw new InvalidArgumentException("{$binding} is not a media content classifier.");
            }

            $configured[] = ['classifier' => $classifier, 'mode' => $mode];
        }

        return $configured;
    }

    public function any(): bool
    {
        return (array) config('media.classification.classifiers', []) !== [];
    }
}
