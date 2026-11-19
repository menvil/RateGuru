<?php

namespace App\Queries\Analytics;

use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the game pages of Analytics → Landings showed, beside the visits
 * counted there (AnalyticsVisitsQuery): the games — their title, slug and
 * where they stand — the versions with the flow each one plays, and the
 * traffic periods that gave those games' versions their shares at some
 * moment of the days read, every audience and scope, a period hidden from
 * Experiments too: its visitors were sent all the same. A query each, for
 * every row of the tab at once. Read-only.
 */
final class LandingExperiencesQuery
{
    /** The most periods read for the games of one page: far more than a game applies in the longest range read at a time. */
    public const int MOST_PERIODS = 200;

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Game> by id
     */
    public function games(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Game::query()->whereKey($ids)->get(['id', 'slug', 'title', 'status', 'status_changed_at'])->keyBy('id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, GameVersion> by id, each with its flow's name
     */
    public function versions(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return GameVersion::query()->whereKey($ids)->with('flow:id,name')->get(['id', 'game_id', 'number', 'flow_id'])->keyBy('id');
    }

    /**
     * The traffic periods of the games given open at some moment between
     * the two given — started before the second, not ended by the first —
     * each with its versions and their shares, the latest started first.
     *
     * @param  list<int>  $gameIds
     * @return Collection<int, GameTrafficPeriod>
     */
    public function periods(array $gameIds, CarbonImmutable $from, CarbonImmutable $before): Collection
    {
        if ($gameIds === []) {
            return new Collection;
        }

        return GameTrafficPeriod::query()
            ->whereIn('game_id', $gameIds)
            ->where('started_at', '<', $before)
            ->where(fn (Builder $open) => $open->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->has('versions')
            ->with('versions')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::MOST_PERIODS)
            ->get();
    }
}
