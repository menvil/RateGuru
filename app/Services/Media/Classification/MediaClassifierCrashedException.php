<?php

namespace App\Services\Media\Classification;

use RuntimeException;
use Throwable;

/**
 * Reported — never thrown — when a classifier fails other than by saying it
 * has no answer: a bug in it. It names the classifier, the exception's class
 * and where in the code it was thrown, never the exception's message, which
 * may carry what a provider was sent — a request's address with its key, a
 * file's path. Reported, it reaches Sentry and, where it runs, Nightwatch.
 */
final class MediaClassifierCrashedException extends RuntimeException
{
    public static function from(string $classifier, Throwable $exception): self
    {
        $where = str_starts_with($exception->getFile(), base_path().'/')
            ? substr($exception->getFile(), strlen(base_path()) + 1)
            : basename($exception->getFile());

        return new self(sprintf('The %s classifier threw %s at %s:%d.', $classifier, MediaClassificationRecorder::reasonFor($exception), $where, $exception->getLine()));
    }
}
