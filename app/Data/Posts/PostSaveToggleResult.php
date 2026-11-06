<?php

namespace App\Data\Posts;

final readonly class PostSaveToggleResult
{
    public function __construct(
        public bool $saved,
        public ?string $message,
    ) {}
}
