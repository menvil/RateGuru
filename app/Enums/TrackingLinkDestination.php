<?php

namespace App\Enums;

/** Where a tracking link leads: a game's page, one of the site's own pages, or a path of the administrator's own. */
enum TrackingLinkDestination: string
{
    case Game = 'game';
    case Page = 'page';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Game => 'Game landing',
            self::Page => 'Site page',
            self::Custom => 'Custom destination',
        };
    }
}
