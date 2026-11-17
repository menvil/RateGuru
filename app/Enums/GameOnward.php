<?php

namespace App\Enums;

/**
 * Where Full Result sends the visitor on: to the flow's next step, or into the
 * site — to a post or the feed. Sent into the site, the steps after Full
 * Result are never shown, and Full Result is the way in.
 */
enum GameOnward: string
{
    case Next = 'next';
    case Post = 'post';
    case Feed = 'feed';

    public function label(): string
    {
        return match ($this) {
            self::Next => 'Next step',
            self::Post => 'Go to a post',
            self::Feed => 'Go to the feed',
        };
    }
}
