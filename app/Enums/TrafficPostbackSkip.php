<?php

namespace App\Enums;

/** Why a conversion sent no postback. */
enum TrafficPostbackSkip: string
{
    case KnownVisitor = 'known_visitor';
    case SourceInactive = 'source_inactive';
    case NoClickId = 'no_click_id';
    case WindowClosed = 'window_closed';
    case ClickIdExpired = 'click_id_expired';
    case NoRule = 'no_rule';

    public function label(): string
    {
        return match ($this) {
            self::KnownVisitor => 'A known visitor: worth nothing new',
            self::SourceInactive => 'The source is paused or ended',
            self::NoClickId => 'No click ID to report',
            self::WindowClosed => 'After the source’s window',
            self::ClickIdExpired => 'The network no longer keeps the click ID',
            self::NoRule => 'No postback for this event',
        };
    }
}
