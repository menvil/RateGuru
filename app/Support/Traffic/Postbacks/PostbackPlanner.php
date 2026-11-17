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
 * the network no longer keeps, or an event no rule is on for. Otherwise one
 * postback per rule on for the event, tried for a day at most and never
 * past the click ID's life. A step worth nothing is still told.
 */
final class PostbackPlanner
{
    public const int TRY_FOR_HOURS = 24;

    /**
     * @param  list<int>  $ruleIds  the rules on for this event
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
    ): array {
        $skip = match (true) {
            $known => TrafficPostbackSkip::KnownVisitor,
            ! $sourceActive => TrafficPostbackSkip::SourceInactive,
            ! $hasClickId => TrafficPostbackSkip::NoClickId,
            $holdsUntil === null || $occurredAt->greaterThan($event === TrafficEvent::Account ? $holdsUntil->addDays($accountGraceDays) : $holdsUntil) => TrafficPostbackSkip::WindowClosed,
            $clickIdTtlHours !== null && $occurredAt->greaterThan($arrivedAt->addHours($clickIdTtlHours)) => TrafficPostbackSkip::ClickIdExpired,
            $ruleIds === [] => TrafficPostbackSkip::NoRule,
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
