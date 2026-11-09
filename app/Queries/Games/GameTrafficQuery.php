<?php

namespace App\Queries\Games;

use App\Enums\GameAudience;
use App\Enums\GameVersionStatus;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\GameVersionPost;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a game's traffic stands: each audience's open period with its shares,
 * and which versions can still be played — a version stops being playable the
 * moment a post it shows leaves the site.
 */
final class GameTrafficQuery
{
    /** The open period of an audience, with its versions and shares. */
    public function currentPeriod(int $gameId, GameAudience $audience): ?GameTrafficPeriod
    {
        return GameTrafficPeriod::query()
            ->where('game_id', $gameId)
            ->where('audience', $audience)
            ->whereNull('ended_at')
            ->with('versions')
            ->latest('id')
            ->first();
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
     * The open period of an audience and its playable shares.
     *
     * @return array{period: ?GameTrafficPeriod, shares: array<int, int>}
     */
    public function allocation(int $gameId, GameAudience $audience): array
    {
        $period = $this->currentPeriod($gameId, $audience);

        if ($period === null) {
            return ['period' => null, 'shares' => []];
        }

        $active = $period->versions->filter(fn (GameVersion $version): bool => $version->status === GameVersionStatus::Active);
        $unplayable = $this->unplayableVersionIds($active->modelKeys());
        $shares = [];

        foreach ($period->shares() as $versionId => $share) {
            if ($active->contains('id', $versionId) && ! in_array($versionId, $unplayable, true)) {
                $shares[$versionId] = $share;
            }
        }

        return ['period' => $period, 'shares' => $shares];
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
