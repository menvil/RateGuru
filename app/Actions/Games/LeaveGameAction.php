<?php

namespace App\Actions\Games;

use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameMilestone;
use App\Enums\GameOnward;
use App\Enums\GameOnwardKind;
use App\Enums\GamePageDestination;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Queries\Games\GameFlowFactsQuery;
use App\Queries\Games\GameOnwardQuery;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GameExit;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The visitor follows the way out of the step they stand on — the step as the
 * attempt records it, never as a page says — to a post, a page or the feed:
 * where it leads, that the play went there and when (what a vote on that post
 * or in the feed within 30 minutes is credited by), and the play's moment of
 * going (docs/architecture/games.md, "Ways on"). A post that is not
 * published sends the visitor to the feed instead, and counts for nothing.
 */
final class LeaveGameAction
{
    public function __construct(
        private readonly GameOnwardQuery $onward,
        private readonly GameFlowFactsQuery $facts,
        private readonly GameEventRecorder $events,
        private readonly GameMilestoneRecorder $milestones,
    ) {}

    public function handle(Game $game, GameVisitor $visitor, ?GameAttempt $attempt): GameExit
    {
        if ($attempt === null) {
            return GameExit::feed();
        }

        return DB::transaction(function () use ($game, $visitor, $attempt): GameExit {
            $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();
            $snapshot = $locked?->version->read();
            $step = $locked !== null && $locked->current_step_key !== null ? GameFlowRunner::find($snapshot->flowSteps(), $locked->current_step_key) : null;

            if ($locked === null || $step === null) {
                return GameExit::feed();
            }

            [$exit, $kind, $postId, $milestone] = $this->destination($game, $step, $snapshot, $locked, $visitor);
            $first = $locked->onward_at === null;

            if ($kind !== null && $first) {
                $locked->forceFill(['onward_kind' => $kind, 'onward_post_id' => $postId, 'onward_at' => now(), 'last_activity_at' => now()])->save();
            }

            $this->events->record(GameEventType::Exit, (int) $game->id, $visitor, attempt: $locked, detail: ['step_type' => $kind->value ?? 'feed']);

            if ($milestone !== null && $first) {
                if ($milestone === GameMilestone::PostOpened) {
                    $this->events->record(GameEventType::PostOpened, (int) $game->id, $visitor, attempt: $locked);
                }

                $this->milestones->reach($milestone, $locked, $visitor);
            }

            $attempt->setRawAttributes($locked->getAttributes(), true);

            return $exit;
        });
    }

    /**
     * The visitor follows Next Game to the game it suggests: the play went
     * there, and a start of that game within 30 minutes is its "next game
     * started".
     */
    public function toNextGame(Game $game, GameVisitor $visitor, ?GameAttempt $attempt, Game $next): GameExit
    {
        if ($attempt !== null) {
            DB::transaction(function () use ($game, $visitor, $attempt): void {
                $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

                if ($locked === null) {
                    return;
                }

                if ($locked->onward_at === null) {
                    $locked->forceFill(['onward_kind' => GameOnwardKind::NextGame, 'onward_at' => now(), 'last_activity_at' => now()])->save();
                }

                $this->events->record(GameEventType::Exit, (int) $game->id, $visitor, attempt: $locked, detail: ['step_type' => GameOnwardKind::NextGame->value]);
            });
        }

        return new GameExit(route('games.play', ['slug' => $next->slug]));
    }

    /** @return array{0: GameExit, 1: GameOnwardKind|null, 2: int|null, 3: GameMilestone|null} */
    private function destination(Game $game, FlowStepDraft $step, GameVersionSnapshot $snapshot, GameAttempt $attempt, GameVisitor $visitor): array
    {
        $toPost = $step->type === GameFlowStepType::GoToPost || ($step->type === GameFlowStepType::FullResult && $step->onward() === GameOnward::Post);
        $toFeed = $step->type === GameFlowStepType::Feed || ($step->type === GameFlowStepType::FullResult && $step->onward() === GameOnward::Feed);

        if ($toPost) {
            $post = $this->onward->post($step, $snapshot, $attempt, $visitor->userId);

            if ($post === null) {
                return [GameExit::feed(), null, null, null];
            }

            $query = array_filter([
                'vote' => (bool) $step->setting('ask_vote') ? 1 : null,
                'ask' => $step->askGroups() === [] ? null : implode(',', $step->askGroups()),
            ]);

            return [new GameExit(route('posts.show', ['post' => $post->id, ...$query])), GameOnwardKind::Post, (int) $post->id, GameMilestone::PostOpened];
        }

        if ($toFeed) {
            $sort = (string) $step->setting('feed_sort');

            return [new GameExit(route('feed', $sort !== '' ? ['sort' => $sort] : [])), GameOnwardKind::Feed, null, GameMilestone::Feed];
        }

        if ($step->sendsToPage()) {
            $url = match ($step->destination()) {
                GamePageDestination::Category => route('feed', ['category' => [(string) $step->setting('category')]]),
                GamePageDestination::Tag => route('feed', ['tag' => (string) $step->setting('tag')]),
                GamePageDestination::Page => $this->facts->isPage((string) $step->setting('path')) ? url((string) $step->setting('path')) : null,
                GamePageDestination::Url => $this->onward->externalAddress((string) $step->setting('url'), $game, $attempt),
            };

            return $url === null
                ? [GameExit::feed(), null, null, null]
                : [new GameExit($url, $step->destination() === GamePageDestination::Url && (bool) $step->setting('new_tab')), GameOnwardKind::Page, null, GameMilestone::Page];
        }

        return [GameExit::feed(), null, null, null];
    }
}
