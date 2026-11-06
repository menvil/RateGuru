<?php

namespace App\Queries\Users;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\Database\LikePattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The people moderation lists, a tab at a time: by status, or the trusted
 * ones, narrowed to a role, to the suspicious — the dashboard's Suspicious
 * users: shadowbanned, or with reported posts or comments — and by a search
 * over the username, the name and the email address. Newest accounts first.
 */
final class ModerationUsersQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** The lists, in the order the tabs show them. */
    public const TABS = ['all', 'active', 'limited', 'banned', 'shadowbanned', 'deleted', 'trusted'];

    public const PER_PAGE = 25;

    /**
     * A page of one list, with each person's posts and the reports on their
     * posts and comments.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(string $tab, string $search, ?UserRole $role, bool $suspicious, int $page): LengthAwarePaginator
    {
        return $this->narrowed($tab, $search, $role, $suspicious)
            ->withCount('posts')
            ->addSelect(['users.*', 'reports_on_content' => $this->reportsOnContent()])
            // Accounts made at the same moment keep their place across pages.
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * How many people each tab lists, before any search.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->onTab(User::query(), $tab)->count();
        }

        return $counts;
    }

    /** People under a living sanction: limited, banned or shadowbanned. */
    public function restricted(): int
    {
        return User::query()->whereIn('status', [UserStatus::Limited, UserStatus::Banned, UserStatus::Shadowbanned])->count();
    }

    /** Moderators and administrators. */
    public function staff(): int
    {
        return User::query()->whereIn('role', [UserRole::Moderator, UserRole::Admin])->count();
    }

    /** How many reports there are on this person's posts and comments. */
    public function reportsOnContentOf(User $person): int
    {
        return (int) User::query()->whereKey($person->id)->addSelect(['reports_on_content' => $this->reportsOnContent()])->value('reports_on_content');
    }

    /**
     * The reports on the posts and comments of the person in the outer row,
     * their authors' deleted ones included.
     *
     * @return Builder<Report>
     */
    private function reportsOnContent(): Builder
    {
        return Report::query()
            ->selectRaw('count(*)')
            ->where(fn (Builder $targets) => $targets
                ->where(fn (Builder $posts) => $posts
                    ->where('reports.target_type', Post::class)
                    ->whereIn('reports.target_id', Post::query()->withoutGlobalScope(SoftDeletingScope::class)->select('posts.id')->whereColumn('posts.user_id', 'users.id')))
                ->orWhere(fn (Builder $comments) => $comments
                    ->where('reports.target_type', Comment::class)
                    ->whereIn('reports.target_id', Comment::query()->withoutGlobalScope(SoftDeletingScope::class)->select('comments.id')->whereColumn('comments.user_id', 'users.id'))));
    }

    /** @return Builder<User> */
    private function narrowed(string $tab, string $search, ?UserRole $role, bool $suspicious): Builder
    {
        $query = $this->onTab(User::query(), $tab);
        $search = trim($search);

        if ($role !== null) {
            $query->where('users.role', $role);
        }

        if ($suspicious) {
            $query->where(fn (Builder $suspect) => $suspect
                ->where('users.status', UserStatus::Shadowbanned)
                ->orWhereHas('posts', fn (Builder $posts) => $posts->where('reports_count', '>', 0))
                ->orWhereHas('comments', fn (Builder $comments) => $comments->where('reports_count', '>', 0)));
        }

        if ($search !== '') {
            $pattern = LikePattern::containing($search);

            $query->where(fn (Builder $matching) => $matching
                ->whereRaw("LOWER(users.username) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(users.name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(users.email) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }

        return $query;
    }

    /**
     * Trusted is the active people at or above the trusted level, as the
     * mark-trusted action and post publishing read it.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function onTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'active' => $query->where('users.status', UserStatus::Active),
            'limited' => $query->where('users.status', UserStatus::Limited),
            'banned' => $query->where('users.status', UserStatus::Banned),
            'shadowbanned' => $query->where('users.status', UserStatus::Shadowbanned),
            'deleted' => $query->where('users.status', UserStatus::Deleted),
            'trusted' => $query->where('users.status', UserStatus::Active)->where('users.trust_level', '>=', MarkUserTrustedAction::TRUSTED_LEVEL),
            default => $query,
        };
    }
}
