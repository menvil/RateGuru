<?php

namespace App\Queries\Users;

use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonInterface;
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

    /** How many votes the person has cast on posts, up and down. */
    public function postVotes(User $person): int
    {
        return PostVote::query()->where('user_id', $person->id)->count();
    }

    /** How many votes the person has cast on comments, up and down. */
    public function commentVotes(User $person): int
    {
        return CommentVote::query()->where('user_id', $person->id)->count();
    }

    /**
     * Where the person signed up from and where their latest visit started, as their account keeps them; for an
     * account made before it kept them, as the visits recorded show — the visit the sign-up was credited to, and
     * the latest visit of a browser of theirs. Each with how many other accounts share its address.
     *
     * @return array{signup: ?array{country: ?string, city: ?string, ip: ?string, hash: ?string, at: ?CarbonInterface, fromVisits: bool, others: int}, visit: ?array{country: ?string, city: ?string, ip: ?string, hash: ?string, at: ?CarbonInterface, fromVisits: bool, others: int}}
     */
    public function places(User $person): array
    {
        $signup = $person->signup_ip_hash !== null || $person->signup_country !== null
            ? ['country' => $person->signup_country, 'city' => $person->signup_city, 'ip' => $person->signup_ip, 'hash' => $person->signup_ip_hash, 'at' => $person->created_at, 'fromVisits' => false]
            : $this->visitPlace(TrafficVisit::query()->whereKey(AccountAttribution::query()->where('user_id', $person->id)->value('credited_visit_id')));
        $visit = $person->visit_started_at !== null
            ? ['country' => $person->visit_country, 'city' => $person->visit_city, 'ip' => $person->visit_ip, 'hash' => $person->visit_ip_hash, 'at' => $person->visit_started_at, 'fromVisits' => false]
            : $this->visitPlace(TrafficVisit::query()
                ->whereIn('visitor_hash', AccountBrowser::query()->where('user_id', $person->id)->select('visitor_hash'))
                ->orderByDesc('arrived_at')
                ->orderByDesc('id'));

        return [
            'signup' => $signup === null ? null : [...$signup, 'others' => $this->sharing($person, $signup['hash'])],
            'visit' => $visit === null ? null : [...$visit, 'others' => $this->sharing($person, $visit['hash'])],
        ];
    }

    /**
     * The first placed visit a query finds, as a place known from the visits: no address of it is kept.
     *
     * @param  Builder<TrafficVisit>  $visits
     * @return ?array{country: ?string, city: ?string, ip: null, hash: null, at: ?CarbonInterface, fromVisits: true}
     */
    private function visitPlace(Builder $visits): ?array
    {
        $visit = $visits->whereNotNull('country')->first(['id', 'country', 'city', 'arrived_at']);

        return $visit === null ? null : ['country' => $visit->country, 'city' => $visit->city, 'ip' => null, 'hash' => null, 'at' => $visit->arrived_at, 'fromVisits' => true];
    }

    /** How many other accounts signed up or last visited from the address of a hash. */
    private function sharing(User $person, ?string $hash): int
    {
        return $hash === null ? 0 : User::query()
            ->whereKeyNot($person->id)
            ->where(fn (Builder $shared) => $shared->where('signup_ip_hash', $hash)->orWhere('visit_ip_hash', $hash))
            ->count();
    }

    /** How many reports the person has filed on others' posts and comments, whatever became of them. */
    public function reportsFiled(User $person): int
    {
        return Report::query()->where('reporter_id', $person->id)->count();
    }
}
