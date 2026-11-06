<?php

namespace App\Services\Feed;

final readonly class FeedPostDeletionResult
{
    public function __construct(
        public bool $deleted,
        public ?string $error = null,
    ) {}

    public static function deleted(): self
    {
        return new self(deleted: true);
    }

    public static function skipped(): self
    {
        return new self(deleted: false);
    }

    public static function failed(string $error): self
    {
        return new self(deleted: false, error: $error);
    }
}
