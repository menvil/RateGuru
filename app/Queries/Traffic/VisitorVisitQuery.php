<?php

namespace App\Queries\Traffic;

use App\Models\AccountBrowser;
use App\Models\TrafficVisit;
use Carbon\CarbonImmutable;

/**
 * A browser's visits, as the tracker credits them: the paid click that holds
 * it — the latest one whose window is still open, so a later click of a
 * network with a short window that has already closed never hides an earlier
 * one still open — the latest paid click whose window has closed, a recent
 * unpaid visit to the same game, the latest unpaid visit to a game, the
 * latest unpaid visit, whose session a new arrival may continue, and the
 * visit what the browser does now belongs to. Reads only;
 * an action locks what it found and asks again.
 */
final class VisitorVisitQuery
{
    public function holding(string $browserHash, CarbonImmutable $now): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNotNull('holds_until')
            ->where('holds_until', '>=', $now)
            ->orderByDesc('id')
            ->first();
    }

    public function latestExpiredPaid(string $browserHash, CarbonImmutable $now): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNotNull('holds_until')
            ->where('holds_until', '<', $now)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Given the first visit of a session, only that session's visits: one of
     * another session seen since never hides the session's own.
     */
    public function recentUnpaid(string $browserHash, ?int $gameId, CarbonImmutable $since, ?int $entryVisitId = null): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNull('source_id')
            ->when($gameId !== null, fn ($query) => $query->where('game_id', $gameId), fn ($query) => $query->whereNull('game_id'))
            // The first visit itself is its session's, whether or not it names itself.
            ->when($entryVisitId !== null, fn ($query) => $query->where(fn ($session) => $session->where('entry_visit_id', $entryVisitId)->orWhere('id', $entryVisitId)))
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('id')
            ->first();
    }

    /** The browser's latest unpaid visit seen since the given moment, on whatever page or game. */
    public function latestUnpaid(string $browserHash, CarbonImmutable $since): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNull('source_id')
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The browser's latest unpaid visit to a game seen since the given moment:
     * what a new account is credited to before a visit to the rest of the site.
     */
    public function latestUnpaidGame(string $browserHash, CarbonImmutable $since): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNull('source_id')
            ->whereNotNull('game_id')
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The browser's unpaid visit seen last since the given moment — the one a
     * session continues from, which a reload of an earlier page may have made
     * the most recent though a later visit has the higher id; with games only,
     * the one of its visits to a game.
     */
    public function lastSeenUnpaid(string $browserHash, CarbonImmutable $since, bool $gamesOnly = false): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNull('source_id')
            ->when($gamesOnly, fn ($query) => $query->whereNotNull('game_id'))
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The visit what the browser does now belongs to: the paid click holding
     * it, else the visit it was seen on last since the given moment, of any
     * kind — where a post it opens, shares, votes on or comments on is put.
     */
    public function current(string $browserHash, CarbonImmutable $now, CarbonImmutable $since): ?TrafficVisit
    {
        return $this->holding($browserHash, $now) ?? TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->first();
    }

    /** The browser's latest visit of any kind since the given moment. */
    public function latest(string $browserHash, CarbonImmutable $since): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->where('arrived_at', '>=', $since)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The browser's latest visit since the given moment that the tracker
     * counts — a paid click or a game visit (TrafficVisit::scopeTracked()) —
     * else its latest visit of any kind: a visit to the rest of the site
     * never takes a step from the visit Traffic sources counts it on.
     */
    public function latestTrackedFirst(string $browserHash, CarbonImmutable $since): ?TrafficVisit
    {
        return TrafficVisit::query()->tracked()
            ->where('visitor_hash', $browserHash)
            ->where('arrived_at', '>=', $since)
            ->orderByDesc('id')
            ->first() ?? $this->latest($browserHash, $since);
    }

    /** Whether an account has signed up or signed in with this browser before. */
    public function isKnownBrowser(string $browserHash): bool
    {
        return AccountBrowser::query()->where('visitor_hash', $browserHash)->exists();
    }
}
