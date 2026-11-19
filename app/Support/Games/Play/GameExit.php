<?php

namespace App\Support\Games\Play;

/** Where leaving a game sends the visitor, and whether in a tab of its own. */
final readonly class GameExit
{
    public function __construct(public string $url, public bool $newTab = false) {}

    public static function feed(): self
    {
        return new self(route('feed'));
    }
}
