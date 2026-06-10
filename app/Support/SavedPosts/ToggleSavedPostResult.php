<?php

namespace App\Support\SavedPosts;

final readonly class ToggleSavedPostResult
{
    public function __construct(public readonly bool $isSaved) {}
}
