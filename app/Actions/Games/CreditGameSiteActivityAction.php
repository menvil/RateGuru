<?php

namespace App\Actions\Games;

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameMilestone;
use App\Enums\GameOnwardKind;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingVote;
use App\Models\User;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Play\GameVisitor;
use Illuminate\Support\Facades\DB;

/**
 * What a visitor does on the site after a game sent them there, credited to
 * that play within 30 minutes of leaving it (docs/architecture/games.md, "Ways
 * on"): a vote on the post the flow sent them to, an answer to a rating group
 * it asked, three votes after leaving for the feed. Called by the voting
 * components once a vote is in; what counts is read back from the database,
 * never taken from the page — a vote taken back is no vote.
 */
final class CreditGameSiteActivityAction
{
    private const int WINDOW_MINUTES = 30;

    private const int FEED_VOTES = 3;

    public function __construct(
        private readonly GameEventRecorder $events,
        private readonly GameMilestoneRecorder $milestones,
    ) {}

    public function postVoteChanged(User $user, int $postId, ?string $browserHash): void
    {
        $attempt = $this->sender($user, $browserHash);

        if ($attempt === null) {
            return;
        }

        DB::transaction(function () use ($user, $postId, $attempt): void {
            $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $visitor = $this->visitorOf($locked, $user);

            if ($locked->onward_kind === GameOnwardKind::Post && (int) $locked->onward_post_id === $postId
                && PostVote::query()->where('post_id', $postId)->where('user_id', $user->id)->exists()) {
                $this->credit($locked, $visitor, GameEventType::PostVoted, GameMilestone::PostVoted);
            }

            if ($locked->onward_kind === GameOnwardKind::Feed
                && PostVote::query()->where('user_id', $user->id)->where('created_at', '>=', $locked->onward_at)->count() >= self::FEED_VOTES) {
                $this->credit($locked, $visitor, GameEventType::FeedVoted, GameMilestone::FeedVoted);
            }
        });
    }

    public function ratingAnswered(User $user, int $postId, string $groupKey, ?string $browserHash): void
    {
        $attempt = $this->sender($user, $browserHash);

        if ($attempt === null || $attempt->onward_kind !== GameOnwardKind::Post || (int) $attempt->onward_post_id !== $postId) {
            return;
        }

        DB::transaction(function () use ($user, $postId, $groupKey, $attempt): void {
            $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            $asked = $locked === null ? [] : array_merge(...array_map(
                fn (FlowStepDraft $step): array => $step->sendsToPost() ? $step->askGroups() : [],
                $locked->version->read()->flowSteps(),
            ) ?: [[]]);
            $groupId = RatingGroup::query()->where('key', $groupKey)->value('id');

            if ($locked === null || ! in_array($groupKey, $asked, true) || $groupId === null
                || ! RatingVote::query()->where('post_id', $postId)->where('user_id', $user->id)->where('rating_group_id', $groupId)->exists()) {
                return;
            }

            $this->credit($locked, $this->visitorOf($locked, $user), GameEventType::PostRated, GameMilestone::PostRated);
        });
    }

    /** The play that sent this visitor onto the site within the window: theirs, or their browser's before they signed in. */
    private function sender(User $user, ?string $browserHash): ?GameAttempt
    {
        return GameAttempt::query()
            ->whereIn('onward_kind', [GameOnwardKind::Post, GameOnwardKind::Feed])
            ->where('onward_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->where(fn ($query) => $query
                ->where('user_id', $user->id)
                ->when($browserHash !== null, fn ($query) => $query->orWhere(fn ($guest) => $guest->where('visitor_hash', $browserHash)->whereNull('user_id'))))
            ->orderByDesc('onward_at')
            ->first();
    }

    private function credit(GameAttempt $attempt, GameVisitor $visitor, GameEventType $type, GameMilestone $milestone): void
    {
        if (GameEvent::query()->where('attempt_id', $attempt->id)->where('type', $type)->exists()) {
            return;
        }

        $this->events->record($type, (int) $attempt->game_id, $visitor, attempt: $attempt);
        $this->milestones->reach($milestone, $attempt, $visitor);
    }

    private function visitorOf(GameAttempt $attempt, User $user): GameVisitor
    {
        return new GameVisitor(GameAudience::Authenticated, (int) $user->id, null, $attempt->visitor_hash);
    }
}
