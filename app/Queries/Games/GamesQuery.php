<?php

namespace App\Queries\Games;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\GameMechanic;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The games of Growth → Games, as the library reads them: searched over the
 * public title, the internal name and the slug, narrowed by status and
 * mechanic, and sorted by when they were last saved or by name. Archived games
 * are listed only when asked for by their status.
 */
final class GamesQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    public const PER_PAGE = 25;

    public const SORTS = ['updated', 'name'];

    /**
     * A page of games, each with its newest version and its cover.
     *
     * @return LengthAwarePaginator<int, Game>
     */
    public function paginate(string $search, ?GameStatus $status, ?GameMechanic $mechanic, string $sort, int $page): LengthAwarePaginator
    {
        $query = $this->narrowed($search, $status, $mechanic)
            ->with(['versions' => fn ($versions) => $versions->limit(1), 'coverAsset.variants']);

        $query = $sort === 'name'
            ? $query->orderBy('games.title')
            : $query->orderByDesc('games.updated_at');

        // Games saved at the same moment, or of the same title, keep their place across pages.
        return $query->orderByDesc('games.id')
            ->paginate(self::PER_PAGE, ['games.*'], 'page', max(1, $page));
    }

    /**
     * The figures the library's header shows: the games not archived, those
     * live, those in draft, those paused.
     *
     * @return array{total: int, live: int, drafts: int, paused: int}
     */
    public function stats(): array
    {
        return [
            'total' => Game::query()->where('status', '!=', GameStatus::Archived)->count(),
            'live' => Game::query()->where('status', GameStatus::Live)->count(),
            'drafts' => Game::query()->where('status', GameStatus::Draft)->count(),
            'paused' => Game::query()->where('status', GameStatus::Paused)->count(),
        ];
    }

    /** @return Builder<Game> */
    private function narrowed(string $search, ?GameStatus $status, ?GameMechanic $mechanic): Builder
    {
        $query = Game::query();
        $search = trim($search);

        $status !== null
            ? $query->where('games.status', $status)
            : $query->where('games.status', '!=', GameStatus::Archived);

        if ($mechanic !== null) {
            $query->where('games.mechanic', $mechanic->value);
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
