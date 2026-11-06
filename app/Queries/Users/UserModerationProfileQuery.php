<?php

namespace App\Queries\Users;

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * What a person's admin page tells about them beyond their account: the
 * moderation of the person and of their posts and comments, newest first,
 * the decision behind their current status, how they sign in, and their
 * posts by status.
 */
final class UserModerationProfileQuery
{
    /** The actions that move a person's lifecycle status. */
    public const STATUS_ACTIONS = [
        ModerationActionType::BanUser,
        ModerationActionType::LimitUser,
        ModerationActionType::ShadowbanUser,
        ModerationActionType::RestoreUserAccess,
        ModerationActionType::UnbanUser,
    ];

    /**
     * The latest moderation of the person and of their posts and comments —
     * their deleted ones included — with who did it.
     *
     * @return Collection<int, ModerationLog>
     */
    public function history(User $person, int $limit = 8): Collection
    {
        return ModerationLog::query()
            ->with('moderator')
            ->where(fn (Builder $about) => $about
                ->where(fn (Builder $self) => $self->where('target_type', User::class)->where('target_id', $person->id))
                ->orWhere(fn (Builder $posts) => $posts->where('target_type', Post::class)->whereIn('target_id', Post::query()->withoutGlobalScope(SoftDeletingScope::class)->select('id')->where('user_id', $person->id)))
                ->orWhere(fn (Builder $comments) => $comments->where('target_type', Comment::class)->whereIn('target_id', Comment::query()->withoutGlobalScope(SoftDeletingScope::class)->select('id')->where('user_id', $person->id))))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** The decision that put the person in their current status, or null when nothing has moved it. */
    public function statusDecision(User $person): ?ModerationLog
    {
        return ModerationLog::query()
            ->with('moderator')
            ->where('target_type', User::class)
            ->where('target_id', $person->id)
            ->whereIn('action', self::STATUS_ACTIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The sign-in providers the person has connected, by their value.
     *
     * @return list<string>
     */
    public function providers(User $person): array
    {
        return SocialAccount::query()->where('user_id', $person->id)->orderBy('id')->get()
            ->map(fn (SocialAccount $account): string => $account->provider->value)
            ->values()
            ->all();
    }

    /**
     * How many of the person's live posts are in each status.
     *
     * @return array<string, int>
     */
    public function postsByStatus(User $person): array
    {
        $counts = [];

        foreach ([PostStatus::Published, PostStatus::Pending, PostStatus::Hidden, PostStatus::Rejected] as $status) {
            $counts[$status->value] = Post::query()->where('user_id', $person->id)->where('status', $status)->count();
        }

        return $counts;
    }

    public function comments(User $person): int
    {
        return Comment::query()->where('user_id', $person->id)->count();
    }
}
