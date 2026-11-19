<?php

namespace App\Enums;

/** Where on the site an account signed up. */
enum AccountSignupPlace: string
{
    case Game = 'game';
    case Post = 'post';
    case Feed = 'feed';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Game => 'In the game',
            self::Post => 'On a post',
            self::Feed => 'In the feed',
            self::Other => 'Elsewhere on the site',
        };
    }
}
