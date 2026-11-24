<?php

namespace App\Enums;

/** Why a call to an ad network's API failed, as the admin says it — never the network's own words. */
enum TrafficConnectorFailure: string
{
    case Auth = 'auth';
    case TwoFactor = 'two_factor';
    case Currency = 'currency';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case Unavailable = 'unavailable';
    case UnexpectedResponse = 'unexpected_response';
    case Rejected = 'rejected';
    case Unsupported = 'unsupported';
    case LimitReached = 'limit_reached';
    case CredentialsUnreadable = 'credentials_unreadable';
    case SharedList = 'shared_list';

    public function message(): string
    {
        return match ($this) {
            self::Auth => 'The network refused the key. Enter a new one with Reconnect.',
            self::TwoFactor => 'The network asks for a second sign-in step this key can’t give. Make an API token without it.',
            self::Currency => 'The network account isn’t in US dollars. RateGuru counts spend in dollars only.',
            self::RateLimited => 'The network asked RateGuru to slow down. It tries again later by itself.',
            self::Timeout => 'The network didn’t answer in time. RateGuru tries again later by itself.',
            self::Unavailable => 'The network’s API is down. RateGuru tries again later by itself.',
            self::UnexpectedResponse => 'The network answered in a way RateGuru doesn’t understand.',
            self::Rejected => 'The network refused the change.',
            self::Unsupported => 'The network can’t do this for this campaign (it lists sites to allow, not to block).',
            self::LimitReached => 'The campaign’s block list is full.',
            self::CredentialsUnreadable => 'The saved key can’t be read any more (the app key changed). Enter it again with Reconnect.',
            self::SharedList => 'The campaign blocks the sites of a list shared with other campaigns, and it would be left empty. Take the last sites off it in the network’s panel.',
        };
    }

    /**
     * Whether the network only passed through it — slow, down or busy — rather than refusing: a call that failed so may have
     * done what it asked all the same, and another call now would most likely fail the same way.
     */
    public function passes(): bool
    {
        return in_array($this, [self::Timeout, self::Unavailable, self::RateLimited], true);
    }

    /** Whether the connection needs the administrator before anything else is tried. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Auth, self::TwoFactor, self::Currency, self::CredentialsUnreadable], true);
    }
}
