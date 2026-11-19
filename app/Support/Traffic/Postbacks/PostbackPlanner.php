<?php

namespace App\Support\Traffic\Postbacks;

use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackSkip;
use Carbon\CarbonImmutable;

/**
 * Whether a conversion of a paid click is told to its network, and when that
 * stops being worth trying: not for a known visitor, a source that isn't
 * active, a click with no ID, a step after the click's window (an account
 * may be confirmed up to 7 days after a sign-up in the window), a click ID
 * the network no longer keeps, an event no rule is on for, or a settled
 * visit that didn't get as far as its rules ask. Otherwise one postback per
 * rule on for the event, tried for a day at most and never past the click
 * ID's life. A step worth nothing is still told.
 */
final class PostbackPlanner
{
    public const int TRY_FOR_HOURS = 24;

    /**
     * @param  list<int>  $ruleIds  the rules on for this event that the visit got far enough for
     * @param  bool  $tooShallow  whether rules are on for it, but the visit got as far as none of them asks
     * @return array{skip: TrafficPostbackSkip|null, rules: list<int>, expires_at: CarbonImmutable|null}
     */
    public static function plan(
        TrafficEvent $event,
        bool $known,
        bool $sourceActive,
        bool $hasClickId,
        CarbonImmutable $arrivedAt,
        ?CarbonImmutable $holdsUntil,
        ?int $clickIdTtlHours,
        int $accountGraceDays,
        array $ruleIds,
        CarbonImmutable $occurredAt,
        CarbonImmutable $now,
        bool $accountStep = false,
        bool $tooShallow = false,
    ): array {
        // An account may be confirmed up to the grace after its click's window: so may a flow goal that is the account.
        $closes = $holdsUntil === null ? null : ($event === TrafficEvent::Account || $accountStep ? $holdsUntil->addDays($accountGraceDays) : $holdsUntil);

        $skip = match (true) {
            $known => TrafficPostbackSkip::KnownVisitor,
            ! $sourceActive => TrafficPostbackSkip::SourceInactive,
            ! $hasClickId => TrafficPostbackSkip::NoClickId,
            $closes === null || $occurredAt->greaterThan($closes) => TrafficPostbackSkip::WindowClosed,
            $clickIdTtlHours !== null && $occurredAt->greaterThan($arrivedAt->addHours($clickIdTtlHours)) => TrafficPostbackSkip::ClickIdExpired,
            $ruleIds === [] => $tooShallow ? TrafficPostbackSkip::TooShallow : TrafficPostbackSkip::NoRule,
            default => null,
        };

        if ($skip !== null) {
            return ['skip' => $skip, 'rules' => [], 'expires_at' => null];
        }

        $expires = $now->addHours(self::TRY_FOR_HOURS);

        if ($clickIdTtlHours !== null && $arrivedAt->addHours($clickIdTtlHours)->lessThan($expires)) {
            $expires = $arrivedAt->addHours($clickIdTtlHours);
        }

        return ['skip' => null, 'rules' => $ruleIds, 'expires_at' => $expires];
    }
}
