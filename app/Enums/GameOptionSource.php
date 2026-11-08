<?php

namespace App\Enums;

/** Where a round option's picture comes from: a published post, or an upload. */
enum GameOptionSource: string
{
    case Post = 'post';
    case Upload = 'upload';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Post',
            self::Upload => 'Upload photo',
        };
    }
}
