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
 * unpaid visit to the same game, and the latest unpaid visit, whose session
 * a new arrival may continue. Reads only; an action locks what it found and
 * asks again.
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

    public function recentUnpaid(string $browserHash, ?int $gameId, CarbonImmutable $since): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNull('source_id')
            ->when($gameId !== null, fn ($query) => $query->where('game_id', $gameId), fn ($query) => $query->whereNull('game_id'))
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
     * The browser's unpaid visit seen last since the given moment — the one a
     * session continues from, which a reload of an earlier page may have made
     * the most recent though a later visit has the higher id.
     */
    public function lastSeenUnpaid(string $browserHash, CarbonImmutable $since): ?TrafficVisit
    {
        return TrafficVisit::query()
            ->where('visitor_hash', $browserHash)
            ->whereNull('source_id')
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

    /** Whether an account has signed up or signed in with this browser before. */
    public function isKnownBrowser(string $browserHash): bool
    {
        return AccountBrowser::query()->where('visitor_hash', $browserHash)->exists();
    }
}
