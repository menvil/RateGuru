<?php

namespace App\Enums;

/** Why a visitor is already known — worth nothing new and never reported to a network. */
enum TrafficKnownReason: string
{
    case SignedIn = 'signed_in';
    case Browser = 'browser';
    case Login = 'login';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in',
            self::Browser => 'Signed in on this browser before',
            self::Login => 'Signed in during the visit',
        };
    }
}
