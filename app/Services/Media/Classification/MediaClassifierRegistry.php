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
    /** The most a classifier's name, or its model version, may be: what their columns hold. */
    public const int IDENTITY_LENGTH = 64;

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

            // Its answers are found by name and model version as their columns
            // hold them: one longer would never match its own answer again.
            if (mb_strlen($classifier->name()) > self::IDENTITY_LENGTH || mb_strlen((string) $classifier->modelVersion()) > self::IDENTITY_LENGTH) {
                throw new InvalidArgumentException("{$binding} names itself, or its model version, in more than ".self::IDENTITY_LENGTH.' characters.');
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
