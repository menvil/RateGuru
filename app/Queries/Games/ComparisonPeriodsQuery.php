<?php

namespace App\Queries\Games;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\GameStatus;
use App\Models\GameTrafficPeriod;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The traffic periods of Growth → Experiments: every period of a game not
 * archived, both audiences, that gave its visitors to at least one version
 * and was not hidden from the list — searched over the game's public title,
 * internal name and slug, narrowed to comparisons (two versions or more) and
 * to current or ended periods, the latest started first across every game.
 * Also the open comparisons the navigation counts, a period's page and the
 * periods its switcher offers, and the comparisons Analytics → Experiments
 * offers and shows. Read-only.
 */
final class ComparisonPeriodsQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const int PER_PAGE = 50;

    /** Show: every period, or only those comparing two versions or more. */
    public const array SHOWS = ['all', 'comparisons'];

    /** Status: every period, those still open, or those ended. */
    public const array STATUSES = ['all', 'current', 'ended'];

    /**
     * A page of periods, each with its game and its versions — every one
     * with its share, in version order.
     *
     * @return LengthAwarePaginator<int, GameTrafficPeriod>
     */
    public function paginate(string $search, string $show, string $status, int $page): LengthAwarePaginator
    {
        return $this->listed($search, $show, $status)
            ->with(['game', 'versions.flow', 'versions.contentSet'])
            ->orderByDesc('game_traffic_periods.started_at')
            // Periods started at the same moment keep their place across pages.
            ->orderByDesc('game_traffic_periods.id')
            ->paginate(self::PER_PAGE, ['game_traffic_periods.*'], 'page', max(1, $page));
    }

    /** Whether any period at all is listed, whatever the filters: what tells an empty list from one filtered empty. */
    public function anyListed(): bool
    {
        return $this->listed('', 'all', 'all')->exists();
    }

    /**
     * The comparisons running now, as the navigation counts them: open
     * periods, not hidden, of games not archived, that share their visitors
     * between two versions or more. One count, through the index on a game's
     * periods by audience and end.
     */
    public function openComparisons(): int
    {
        return GameTrafficPeriod::query()
            ->join('games', 'games.id', '=', 'game_traffic_periods.game_id')
            ->where('games.status', '!=', GameStatus::Archived->value)
            ->whereNull('game_traffic_periods.ended_at')
            ->whereNull('game_traffic_periods.hidden_at')
            ->has('versions', '>=', 2)
            ->count();
    }

    /**
     * One period with its game and its versions, hidden or not, of any game:
     * what a period's page shows. None when it doesn't exist or never gave
     * its visitors to a version.
     */
    public function find(int $periodId): ?GameTrafficPeriod
    {
        return GameTrafficPeriod::query()
            ->whereKey($periodId)
            ->has('versions')
            ->with(['game', 'versions.flow', 'versions.contentSet'])
            ->first();
    }

    /**
     * The comparisons Analytics → Experiments offers: the latest started
     * periods listed in Experiments that share their visitors between two
     * versions or more, each with its game.
     *
     * @return Collection<int, GameTrafficPeriod>
     */
    public function latestComparisons(int $limit): Collection
    {
        return $this->listed('', 'comparisons', 'all')
            ->with('game')
            ->orderByDesc('game_traffic_periods.started_at')
            ->orderByDesc('game_traffic_periods.id')
            ->limit($limit)
            ->get(['game_traffic_periods.*']);
    }

    /**
     * One comparison listed in Experiments, with its game and its versions —
     * every one with its share, flow and content set: what Analytics →
     * Experiments shows. None for a period that isn't one: hidden, of an
     * archived game, or with a single version.
     */
    public function comparison(int $periodId): ?GameTrafficPeriod
    {
        return $this->listed('', 'comparisons', 'all')
            ->where('game_traffic_periods.id', $periodId)
            ->with(['game', 'versions.flow', 'versions.contentSet'])
            ->first(['game_traffic_periods.*']);
    }

    /** Whether a period is a comparison listed in Experiments: what Analytics → Experiments' address may name. */
    public function isComparison(int $periodId): bool
    {
        return $this->listed('', 'comparisons', 'all')->where('game_traffic_periods.id', $periodId)->exists();
    }

    /**
     * The periods of one game and audience that a period's page can switch
     * to: those with shares and not hidden, the latest started first.
     *
     * @return Collection<int, GameTrafficPeriod>
     */
    public function siblings(GameTrafficPeriod $period): Collection
    {
        return GameTrafficPeriod::query()
            ->where('game_id', $period->game_id)
            ->where('audience', $period->audience->value)
            ->whereNull('hidden_at')
            ->has('versions')
            ->with('versions')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Builder<GameTrafficPeriod> */
    private function listed(string $search, string $show, string $status): Builder
    {
        $query = GameTrafficPeriod::query()
            ->join('games', 'games.id', '=', 'game_traffic_periods.game_id')
            ->where('games.status', '!=', GameStatus::Archived->value)
            ->whereNull('game_traffic_periods.hidden_at')
            ->has('versions', '>=', $show === 'comparisons' ? 2 : 1);
        $search = trim($search);

        if ($status === 'current') {
            $query->whereNull('game_traffic_periods.ended_at');
        } elseif ($status === 'ended') {
            $query->whereNotNull('game_traffic_periods.ended_at');
        }

        if ($search !== '') {
            $pattern = LikePattern::containing($search);

            $query->where(fn (Builder $matching) => $matching
                ->whereRaw("LOWER(games.title) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(games.internal_name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(games.slug) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }

        return $query;
    }
}
