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
 * off failed; else why a campaign refused it — never a list it is on its
 * way to, nor one it was taken off in the panel. A block found in the
 * network's own panel is blocked there while any list holds it — RateGuru
 * never sent it to the others — with why taking it off failed, if it did;
 * once none does it goes, which is the caller's to do. A pure function, so
 * applying blocks and reading the lists judge alike.
 */
final class BlockStanding
{
    /** A block sent to no campaign at all: the source had none to apply it to. */
    public const string NO_CAMPAIGN = 'no_campaign';

    /**
     * @param  iterable<TrafficBlockListing>  $listings
     * @param  bool  $panel  whether the block was found in the network's panel
     * @return array{state: TrafficBlockNetworkState, error: string|null}
     */
    public static function of(iterable $listings, bool $panel = false): array
    {
        $on = [];
        $off = [];

        foreach ($listings as $listing) {
            $listing->listed ? $on[] = $listing->error : $off[] = $listing->error;
        }

        $stuck = array_values(array_filter($on, fn (?string $error): bool => $error !== null))[0] ?? null;
        $refused = array_values(array_filter($off, fn (?string $error): bool => ! in_array($error, [null, TrafficBlockListing::TAKEN_OFF, TrafficBlockListing::PENDING], true)))[0] ?? null;

        return match (true) {
            $panel => ['state' => TrafficBlockNetworkState::Applied, 'error' => $stuck],
            $on === [] && $off === [] => ['state' => TrafficBlockNetworkState::Failed, 'error' => self::NO_CAMPAIGN],
            $off === [] => ['state' => TrafficBlockNetworkState::Applied, 'error' => $stuck],
            $on !== [] => ['state' => TrafficBlockNetworkState::Partial, 'error' => $stuck ?? $refused],
            in_array(TrafficBlockListing::TAKEN_OFF, $off, true) => ['state' => TrafficBlockNetworkState::TakenOff, 'error' => null],
            default => ['state' => TrafficBlockNetworkState::Failed, 'error' => $refused],
        };
    }
}
