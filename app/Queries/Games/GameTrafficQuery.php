<?php

namespace App\Queries\Games;

use App\Enums\GameAudience;
use App\Enums\GameVersionStatus;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\GameVersionPost;
use App\Models\TrafficVisit;
use App\Support\Games\Traffic\TrafficScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where a game's traffic stands: each audience's open periods with their
 * shares — everyone's, and the splits for a source or campaign — and which
 * versions can still be played — a version stops being playable the moment a
 * post it shows leaves the site.
 */
final class GameTrafficQuery
{
    /** The open period of an audience for one scope — everyone's unless one is named — with its versions and shares. */
    public function currentPeriod(int $gameId, GameAudience $audience, ?TrafficScope $scope = null): ?GameTrafficPeriod
    {
        $scope ??= TrafficScope::everyone();

        return GameTrafficPeriod::query()
            ->where('game_id', $gameId)
            ->where('audience', $audience)
            ->whereNull('ended_at')
            ->when($scope->sourceId === null, fn (Builder $query) => $query->whereNull('source_id'), fn (Builder $query) => $query->where('source_id', $scope->sourceId))
            ->when($scope->campaignId === null, fn (Builder $query) => $query->whereNull('campaign_id'), fn (Builder $query) => $query->where('campaign_id', $scope->campaignId))
            ->with('versions')
            ->latest('id')
            ->first();
    }

    /**
     * The open periods of an audience, one for each scope — the latest, where
     * more than one were ever left open — everyone's first, then the splits
     * for a source or campaign in the order they started, each with its
     * versions and shares.
     *
     * @return Collection<int, GameTrafficPeriod>
     */
    public function currentPeriods(int $gameId, GameAudience $audience): Collection
    {
        return GameTrafficPeriod::query()
            ->where('game_id', $gameId)
            ->where('audience', $audience)
            ->whereNull('ended_at')
            ->with('versions')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (GameTrafficPeriod $period): string => $period->scope()->key())
            ->sortBy(fn (GameTrafficPeriod $period): array => [$period->source_id === null ? 0 : 1, (int) $period->id])
            ->values();
    }

    /**
     * The splits for a source or campaign open now in an audience, with their
     * versions, source and campaign, in the order they started.
     *
     * @return Collection<int, GameTrafficPeriod>
     */
    public function currentSplits(int $gameId, GameAudience $audience): Collection
    {
        return GameTrafficPeriod::query()
            ->where('game_id', $gameId)
            ->where('audience', $audience)
            ->whereNull('ended_at')
            ->whereNotNull('source_id')
            ->with(['versions', 'source:id,name', 'campaign:id,source_id,external_id,name'])
            ->withCount('assignments')
            ->orderBy('id')
            ->get()
            ->unique(fn (GameTrafficPeriod $period): string => $period->scope()->key())
            ->values();
    }

    /**
     * The scope of a game page's arrival from the paid click its arrival
     * token names, read for the browser the click was recorded for: the
     * click's campaign, else its source, when it brought the visitor to this
     * game; everyone's otherwise.
     */
    public function arrivalScope(int $gameId, int $visitId, string $browserHash): TrafficScope
    {
        $visit = TrafficVisit::query()->whereKey($visitId)->where('visitor_hash', $browserHash)->first(['id', 'game_id', 'source_id', 'campaign_id']);

        return $visit === null ? TrafficScope::everyone() : TrafficScope::ofVisit(
            $gameId,
            $visit->game_id === null ? null : (int) $visit->game_id,
            $visit->source_id === null ? null : (int) $visit->source_id,
            $visit->campaign_id === null ? null : (int) $visit->campaign_id,
        );
    }

    /**
     * The versions among these that show a post no longer on the site.
     *
     * @param  list<int>  $versionIds
     * @return list<int>
     */
    public function unplayableVersionIds(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        return GameVersionPost::query()
            ->whereIn('version_id', $versionIds)
            // The relation leaves deleted posts out, so a deleted or purged
            // post has no public post either.
            ->whereDoesntHave('post', fn (Builder $post) => $post->published())
            ->distinct()
            ->pluck('version_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The shares an audience is given now, among versions that are active and
     * playable, version id to share, in version order. Empty means nobody of
     * the audience can be given a version.
     *
     * @return array<int, int>
     */
    public function playableShares(int $gameId, GameAudience $audience): array
    {
        return $this->allocation($gameId, $audience)['shares'];
    }

    /**
     * The open period of an audience — everyone's — and its playable shares.
     *
     * @return array{period: ?GameTrafficPeriod, shares: array<int, int>}
     */
    public function allocation(int $gameId, GameAudience $audience): array
    {
        $period = $this->currentPeriod($gameId, $audience);

        if ($period === null) {
            return ['period' => null, 'shares' => []];
        }

        return ['period' => $period, 'shares' => self::playable($period, $this->unplayableVersionIds(self::activeIds([$period])))];
    }

    /**
     * What a visitor of an audience is given now, by the scope they arrived
     * with — a signed-in player's and an unpaid arrival's is everyone's: the
     * open period that covers the arrival most closely and has a playable
     * share — its campaign's split, else its source's, else everyone's — with
     * its playable shares; and every version with a playable share in any
     * open period of the audience, which a visitor already given one keeps.
     * Without splits, everyone's period and its shares alone.
     *
     * @return array{period: ?GameTrafficPeriod, shares: array<int, int>, kept: list<int>}
     */
    public function assignable(int $gameId, GameAudience $audience, TrafficScope $arrival): array
    {
        $periods = $this->currentPeriods($gameId, $audience);
        $unplayable = $this->unplayableVersionIds(self::activeIds($periods->all()));
        $kept = [];

        foreach ($periods as $period) {
            $kept = [...$kept, ...array_keys(self::playable($period, $unplayable))];
        }

        $kept = array_values(array_unique($kept));

        foreach ($arrival->fallbacks() as $scope) {
            $period = $periods->first(fn (GameTrafficPeriod $each): bool => $each->scope()->equals($scope));

            if ($period === null) {
                continue;
            }

            $shares = self::playable($period, $unplayable);

            // A split none of whose versions can be played gives its visitors to the next one that covers them.
            if ($shares !== [] || $scope->isEveryone()) {
                return ['period' => $period, 'shares' => $shares, 'kept' => $kept];
            }
        }

        return ['period' => null, 'shares' => [], 'kept' => $kept];
    }

    /**
     * A period's shares among versions active and playable, version id to
     * share, in version order.
     *
     * @param  list<int>  $unplayable
     * @return array<int, int>
     */
    private static function playable(GameTrafficPeriod $period, array $unplayable): array
    {
        $shares = [];

        foreach ($period->versions as $version) {
            if ($version->status === GameVersionStatus::Active && ! in_array((int) $version->id, $unplayable, true)) {
                $shares[(int) $version->id] = (int) $version->getRelation('pivot')->getAttribute('share_percent');
            }
        }

        return $shares;
    }

    /**
     * The active versions with a share in any of these periods.
     *
     * @param  list<GameTrafficPeriod>  $periods
     * @return list<int>
     */
    private static function activeIds(array $periods): array
    {
        $ids = [];

        foreach ($periods as $period) {
            foreach ($period->versions as $version) {
                if ($version->status === GameVersionStatus::Active) {
                    $ids[] = (int) $version->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The versions each of these games gives traffic to now, in any audience,
     * among those active and playable: game id to version ids. A few queries
     * for a whole page of games.
     *
     * @param  list<int>  $gameIds
     * @return array<int, list<int>>
     */
    public function versionsInTraffic(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        $periods = GameTrafficPeriod::query()
            ->whereIn('game_id', $gameIds)
            ->whereNull('ended_at')
            ->with('versions')
            ->get();

        $active = $periods->flatMap(fn (GameTrafficPeriod $period) => $period->versions)
            ->filter(fn (GameVersion $version): bool => $version->status === GameVersionStatus::Active)
            ->map(fn (GameVersion $version): int => (int) $version->id)
            ->values()
            ->all();
        $unplayable = $this->unplayableVersionIds(array_values(array_unique($active)));
        $byGame = array_fill_keys($gameIds, []);

        foreach ($periods as $period) {
            foreach (array_keys($period->shares()) as $versionId) {
                if (in_array($versionId, $active, true) && ! in_array($versionId, $unplayable, true)) {
                    $byGame[(int) $period->game_id][] = $versionId;
                }
            }
        }

        return array_map(fn (array $ids): array => array_values(array_unique($ids)), $byGame);
    }

    /** Whether any audience can be given a playable version now. */
    public function hasPlayableTraffic(int $gameId): bool
    {
        foreach (GameAudience::cases() as $audience) {
            if ($this->playableShares($gameId, $audience) !== []) {
                return true;
            }
        }

        return false;
    }
}
