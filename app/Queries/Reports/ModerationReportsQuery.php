<?php

namespace App\Queries\Reports;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\Database\LikePattern;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

/**
 * The reports moderation lists: each report points to one post, comment or
 * person. A list is one of the tabs below, narrowed to a kind of target and
 * by a search over the reported content, the reporter, the reason, the
 * reporter's message and the report's id. Open reports wait oldest first;
 * the closed ones read newest first.
 *
 * A post or comment its author deleted is still the report's target, so the
 * moderator sees what was reported.
 */
final class ModerationReportsQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** The lists, in the order the tabs show them. */
    public const TABS = ['open', 'resolved', 'ignored', 'all'];

    /** The kinds of target a list can be narrowed to, by their morph class. */
    public const TYPES = ['post' => Post::class, 'comment' => Comment::class, 'user' => User::class];

    public const PER_PAGE = 25;

    /**
     * A page of one list, with each report's reporter, the moderator who
     * closed it, its target and the target's author, and how many open
     * reports point to the same target.
     *
     * @return LengthAwarePaginator<int, Report>
     */
    public function paginate(string $tab, string $search, ?string $type, int $page): LengthAwarePaginator
    {
        $query = $this->withDetails($this->narrowed($tab, $search, $type));

        // Every order ends on the id, so reports that came in together keep
        // their place from one page to the next.
        $tab === 'open'
            ? $query->orderBy('reports.created_at')->orderBy('reports.id')
            : $query->orderByDesc('reports.created_at')->orderByDesc('reports.id');

        return $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /** The report with this id, with what its details show, or null when there is none. */
    public function find(int $id): ?Report
    {
        return $this->withDetails(Report::query())->find($id);
    }

    /**
     * The report before or after this one in a list, as the list orders
     * them, or null at either end. The report itself need not be on the list
     * any more: after closing an open report, the next open one is the one
     * after where it stood.
     */
    public function adjacent(string $tab, string $search, ?string $type, Report $from, int $direction): ?int
    {
        // Open reports run oldest first, the others newest first.
        $forward = ($tab === 'open') === ($direction > 0);
        $at = $from->created_at;
        $query = $this->narrowed($tab, $search, $type)->whereKeyNot($from->id);

        $query->where(fn (Builder $beyond) => $beyond
            ->where('reports.created_at', $forward ? '>' : '<', $at)
            ->orWhere(fn (Builder $tied) => $tied->where('reports.created_at', $at)->where('reports.id', $forward ? '>' : '<', $from->id)));

        $forward
            ? $query->orderBy('reports.created_at')->orderBy('reports.id')
            : $query->orderByDesc('reports.created_at')->orderByDesc('reports.id');

        $id = $query->value('reports.id');

        return $id !== null ? (int) $id : null;
    }

    /** The first report of a list, as the list orders them. */
    public function first(string $tab, string $search, ?string $type): ?int
    {
        $query = $this->narrowed($tab, $search, $type);

        $tab === 'open'
            ? $query->orderBy('reports.created_at')->orderBy('reports.id')
            : $query->orderByDesc('reports.created_at')->orderByDesc('reports.id');

        $id = $query->value('reports.id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * How many reports each tab lists, before any search.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->onTab(Report::query(), $tab)->count();
        }

        return $counts;
    }

    /** How many posts, comments and people the open reports point to. */
    public function openTargets(): int
    {
        $targets = $this->onTab(Report::query(), 'open')->select(['target_type', 'target_id'])->distinct();

        return Report::query()->getQuery()->newQuery()->fromSub($targets->toBase(), 'targets')->count();
    }

    /** When the report that has waited longest came in. */
    public function oldestOpenAt(): ?CarbonInterface
    {
        $oldest = $this->onTab(Report::query(), 'open')->min('created_at');

        return $oldest !== null ? Carbon::parse($oldest) : null;
    }

    /**
     * How many reports there are on a person's posts and comments.
     */
    public function reportsOnContentOf(User $author): int
    {
        return Report::query()
            ->where(fn (Builder $targets) => $targets
                ->whereHasMorph('target', [Post::class], fn (Builder $post) => $post->withoutGlobalScope(SoftDeletingScope::class)->where('user_id', $author->id))
                ->orWhereHasMorph('target', [Comment::class], fn (Builder $comment) => $comment->withoutGlobalScope(SoftDeletingScope::class)->where('user_id', $author->id)))
            ->count();
    }

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    private function withDetails(Builder $query): Builder
    {
        return $query
            ->with([
                'reporter',
                'resolver',
                'target' => function (MorphTo $target): void {
                    $target->constrain([
                        Post::class => fn (Builder $post) => $post->withoutGlobalScope(SoftDeletingScope::class)->with(['user', 'category']),
                        Comment::class => fn (Builder $comment) => $comment->withoutGlobalScope(SoftDeletingScope::class)->with(['user', 'post' => fn ($post) => $post->withoutGlobalScope(SoftDeletingScope::class)]),
                    ]);
                },
            ])
            ->addSelect(['reports.*', 'open_on_target' => Report::query()
                ->from('reports as siblings')
                ->selectRaw('count(*)')
                ->whereColumn('siblings.target_type', 'reports.target_type')
                ->whereColumn('siblings.target_id', 'reports.target_id')
                ->where('siblings.status', ReportStatus::Open)]);
    }

    /** @return Builder<Report> */
    private function narrowed(string $tab, string $search, ?string $type): Builder
    {
        $query = $this->onTab(Report::query(), $tab);
        $search = trim($search);

        if ($type !== null && array_key_exists($type, self::TYPES)) {
            $query->where('reports.target_type', self::TYPES[$type]);
        }

        if ($search !== '') {
            $pattern = LikePattern::containing($search);

            $query->where(function (Builder $matching) use ($search, $pattern): void {
                // “#2284” or “2284” finds the report with that id as well.
                if (preg_match('/^#?(\d{1,18})$/', $search, $id) === 1) {
                    $matching->orWhere('reports.id', (int) $id[1]);
                }

                $matching
                    ->orWhereRaw("LOWER(reports.reason) LIKE LOWER(?) ESCAPE '!'", [LikePattern::containing(str_replace(' ', '_', $search))])
                    ->orWhereRaw("LOWER(reports.message) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereHas('reporter', fn (Builder $reporter) => $reporter->whereRaw("LOWER(username) LIKE LOWER(?) ESCAPE '!'", [$pattern]))
                    ->orWhereHasMorph('target', [Post::class], fn (Builder $post) => $post->withoutGlobalScope(SoftDeletingScope::class)->whereRaw("LOWER(title) LIKE LOWER(?) ESCAPE '!'", [$pattern]))
                    ->orWhereHasMorph('target', [Comment::class], fn (Builder $comment) => $comment->withoutGlobalScope(SoftDeletingScope::class)->whereRaw("LOWER(body) LIKE LOWER(?) ESCAPE '!'", [$pattern]))
                    ->orWhereHasMorph('target', [User::class], fn (Builder $person) => $person->whereRaw("LOWER(username) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    private function onTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'open' => $query->where('reports.status', ReportStatus::Open),
            'resolved' => $query->where('reports.status', ReportStatus::Resolved),
            'ignored' => $query->where('reports.status', ReportStatus::Ignored),
            default => $query,
        };
    }
}
