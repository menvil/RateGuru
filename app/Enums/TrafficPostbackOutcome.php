<?php

namespace App\Enums;

/** What one try of a postback came to. */
enum TrafficPostbackOutcome: string
{
    case Ok = 'ok';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
    case HttpError = 'http_error';
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case BlockedUrl = 'blocked_url';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Accepted',
            self::Duplicate => 'Already counted',
            self::Rejected => 'Refused',
            self::HttpError => 'HTTP error',
            self::Timeout => 'Timed out',
            self::ConnectionError => 'No connection',
            self::BlockedUrl => 'Address refused',
        };
    }
}
