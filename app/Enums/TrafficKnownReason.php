<?php

namespace App\Enums;

/**
 * Why a visitor is already known — never a new user and never reported to a
 * network. One known as they came is worth nothing; one who signs in during
 * the visit is worth the price of a sign-in.
 */
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
