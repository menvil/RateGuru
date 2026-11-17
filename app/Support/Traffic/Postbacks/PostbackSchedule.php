<?php

namespace App\Support\Traffic\Postbacks;

use App\Enums\TrafficPostbackOutcome;
use App\Enums\TrafficPostbackStatus;
use Carbon\CarbonImmutable;

/**
 * What follows an attempt to send a postback: delivered when the network
 * took it (or had it already), failed at once when it refused it, else
 * tried again after 1, 5 and 30 minutes, 2 and 9 hours — six attempts in
 * about 12 hours — failed after the sixth, and expired when the next try
 * would come after the postback stops being worth sending.
 */
final class PostbackSchedule
{
    /** How long a postback claimed for sending is left alone before a send run takes it as abandoned; a resend holds it as long. */
    public const int CLAIM_SECONDS = 120;

    /** @var list<int> seconds before the next attempt, after attempt 1, 2, … */
    public const array RETRY_AFTER = [60, 300, 1800, 7200, 32400];

    /** @return array{status: TrafficPostbackStatus, run_after: CarbonImmutable|null} */
    public static function after(TrafficPostbackOutcome $outcome, int $attempts, ?CarbonImmutable $expiresAt, CarbonImmutable $now): array
    {
        if (in_array($outcome, [TrafficPostbackOutcome::Ok, TrafficPostbackOutcome::Duplicate], true)) {
            return ['status' => TrafficPostbackStatus::Delivered, 'run_after' => null];
        }

        if ($outcome === TrafficPostbackOutcome::Rejected || $attempts > count(self::RETRY_AFTER)) {
            return ['status' => TrafficPostbackStatus::Failed, 'run_after' => null];
        }

        $next = $now->addSeconds(self::RETRY_AFTER[max(1, $attempts) - 1]);

        if ($expiresAt !== null && $next->greaterThan($expiresAt)) {
            return ['status' => TrafficPostbackStatus::Expired, 'run_after' => null];
        }

        return ['status' => TrafficPostbackStatus::Pending, 'run_after' => $next];
    }
}
