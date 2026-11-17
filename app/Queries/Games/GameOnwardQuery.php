<?php

namespace App\Queries\Games;

use App\Enums\GameOnwardKind;
use App\Enums\GamePostTarget;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\Post;
use App\Models\TrafficVisitDetail;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\GameFlowValidator;
use App\Support\Games\Snapshot\GameVersionSnapshot;

/**
 * The post a flow sends the visitor to: the one the administrator linked;
 * the post of the option they picked in the last round with a post (an
 * uploaded picture has none, so an earlier round's pick stands in); or the
 * newest published post outside the game that is not theirs and that they
 * have not voted on. Only a published post is ever given.
 */
final class GameOnwardQuery
{
    public function post(FlowStepDraft $step, GameVersionSnapshot $snapshot, ?GameAttempt $attempt, ?int $userId): ?Post
    {
        // Once the play has gone on to a post, it is that post every time — a vote on it is what counts — while it is still published.
        if ($attempt !== null && $attempt->onward_kind === GameOnwardKind::Post && $attempt->onward_post_id !== null) {
            return $this->published($attempt->onward_post_id);
        }

        return match ($step->postTarget()) {
            GamePostTarget::Chosen => $this->published($step->setting('post_id')),
            GamePostTarget::LastPick => $this->lastPick($snapshot, $attempt) ?? $this->fresh($snapshot, $userId),
            GamePostTarget::Fresh => $this->fresh($snapshot, $userId),
        };
    }

    /** Another site's address a Go to Page step leads to, its placeholders filled in and each encoded as a value. */
    public function externalAddress(string $url, Game $game, ?GameAttempt $attempt): ?string
    {
        if (! GameFlowValidator::isSiteAddress($url)) {
            return null;
        }

        $clickId = $attempt?->traffic_visit_id === null ? '' : (string) TrafficVisitDetail::query()->whereKey($attempt->traffic_visit_id)->value('click_id');

        return strtr($url, [
            '{click_id}' => rawurlencode($clickId),
            '{game}' => rawurlencode($game->slug),
            '{result}' => rawurlencode((string) $attempt?->profile_key),
        ]);
    }

    private function lastPick(GameVersionSnapshot $snapshot, ?GameAttempt $attempt): ?Post
    {
        if ($attempt === null) {
            return null;
        }

        foreach ($attempt->answers()->reorder()->orderByDesc('round_index')->get(['round_index', 'option_position']) as $answer) {
            $postId = $snapshot->round((int) $answer->round_index)?->option((int) $answer->option_position)?->postId;
            $post = $this->published($postId);

            if ($post !== null) {
                return $post;
            }
        }

        return null;
    }

    private function fresh(GameVersionSnapshot $snapshot, ?int $userId): ?Post
    {
        return Post::query()
            ->published()
            ->whereNotIn('id', $snapshot->postIds())
            ->when($userId !== null, fn ($query) => $query
                ->where('user_id', '!=', $userId)
                ->whereDoesntHave('postVotes', fn ($votes) => $votes->where('user_id', $userId)))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }

    private function published(mixed $postId): ?Post
    {
        return is_int($postId) && $postId > 0 ? Post::query()->published()->find($postId) : null;
    }
}
