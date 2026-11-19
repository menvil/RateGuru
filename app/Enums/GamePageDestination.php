<?php

namespace App\Enums;

/** Where a Go to Page step leads: a category or a tag of the feed, another page of the site, or another site. */
enum GamePageDestination: string
{
    case Category = 'category';
    case Tag = 'tag';
    case Page = 'page';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'A category',
            self::Tag => 'A tag',
            self::Page => 'Another page on this site',
            self::Url => 'Another site',
        };
    }
}
