<?php

namespace App\Support\Analytics;

use App\Enums\TrafficChannel;
use Carbon\CarbonImmutable;

/**
 * Whether an unpaid arrival belongs to the session the browser is in — the
 * visits since the one it entered by, each carrying where that one came from
 * (`traffic_visits.entry_visit_id`). A move inside the site, or a page opened
 * with no referrer, continues the session while the browser's last unpaid
 * visit was seen less than the session's minutes ago; a game reached from
 * one of the site's own result pages is such a move too. Anything from
 * outside starts a session of its own: another site's referrer, a search, a
 * result page reached from outside the site or with no referrer (a shared
 * result), campaign tags of its own other than the session's — a link inside
 * the site carries none. A paid click is always the visit its session began
 * with.
 *
 * The same page reached again from the same place, by the same tags, within
 * those minutes — a reload, the same link followed twice — is the same
 * visit, never a session of its own. Pure: the minutes come from
 * config('analytics.session_minutes').
 */
final class SessionRule
{
    /**
     * @param  bool  $onResultPage  whether the arrival lands on a game's result page
     * @param  bool  $tagsDiffer  whether the arrival came by campaign tags of its own other than the session's (UtmTags::differFrom())
     * @param  CarbonImmutable|null  $lastSeenAt  when the browser's last unpaid visit was last seen, if it has one
     */
    public static function continues(TrafficChannel $channel, bool $onResultPage, bool $tagsDiffer, ?CarbonImmutable $lastSeenAt, CarbonImmutable $now, int $sessionMinutes): bool
    {
        if ($channel->isPaid() || $tagsDiffer || ! self::within($lastSeenAt, $now, $sessionMinutes)) {
            return false;
        }

        return match ($channel) {
            TrafficChannel::Internal, TrafficChannel::Direct => true,
            // Shared lands on a result page only from outside; off one, it is a game reached from the site's own result page.
            TrafficChannel::Shared => ! $onResultPage,
            default => false,
        };
    }

    /**
     * Whether an arrival is the same as the browser's last unpaid visit to
     * the same page: from the same place, by the same tags, within the
     * session's minutes.
     *
     * @param  bool  $tagsDiffer  whether the arrival's campaign tags are not exactly the visit's — none where it has some differ too
     */
    public static function repeats(string $sourceKey, ?string $visitSourceKey, bool $tagsDiffer, ?CarbonImmutable $lastSeenAt, CarbonImmutable $now, int $sessionMinutes): bool
    {
        return $sourceKey === $visitSourceKey && ! $tagsDiffer && self::within($lastSeenAt, $now, $sessionMinutes);
    }

    /** Seen less than the session's minutes ago: at exactly that many, the session is over. */
    private static function within(?CarbonImmutable $lastSeenAt, CarbonImmutable $now, int $sessionMinutes): bool
    {
        return $lastSeenAt !== null && $lastSeenAt->greaterThan($now->subMinutes($sessionMinutes));
    }
}
