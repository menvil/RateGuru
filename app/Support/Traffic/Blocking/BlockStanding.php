<?php

namespace App\Support\Traffic\Blocking;

use App\Enums\TrafficBlockNetworkState;
use App\Models\TrafficBlockListing;

/**
 * Where a block RateGuru sent to a network stands, from where it stands on
 * each campaign's list (docs/architecture/traffic.md, "Blocking"): on every
 * one, blocked; on some, partly blocked; on none, taken off in the network's
 * panel where a list held it before, else not blocked on the network. With
 * the reason that goes with it: on a list still holding it, why taking it
 * off failed; else why a campaign refused it. A pure function, so applying
 * blocks and reading the lists judge alike.
 */
final class BlockStanding
{
    /** A block sent to no campaign at all: the source had none to apply it to. */
    public const string NO_CAMPAIGN = 'no_campaign';

    /**
     * @param  iterable<TrafficBlockListing>  $listings
     * @return array{state: TrafficBlockNetworkState, error: string|null}
     */
    public static function of(iterable $listings): array
    {
        $on = [];
        $off = [];

        foreach ($listings as $listing) {
            $listing->listed ? $on[] = $listing->error : $off[] = $listing->error;
        }

        $stuck = array_values(array_filter($on, fn (?string $error): bool => $error !== null))[0] ?? null;
        $refused = array_values(array_filter($off, fn (?string $error): bool => $error !== null && $error !== TrafficBlockListing::TAKEN_OFF))[0] ?? null;

        return match (true) {
            $on === [] && $off === [] => ['state' => TrafficBlockNetworkState::Failed, 'error' => self::NO_CAMPAIGN],
            $off === [] => ['state' => TrafficBlockNetworkState::Applied, 'error' => $stuck],
            $on !== [] => ['state' => TrafficBlockNetworkState::Partial, 'error' => $stuck ?? $refused],
            in_array(TrafficBlockListing::TAKEN_OFF, $off, true) => ['state' => TrafficBlockNetworkState::TakenOff, 'error' => null],
            default => ['state' => TrafficBlockNetworkState::Failed, 'error' => $refused],
        };
    }
}
