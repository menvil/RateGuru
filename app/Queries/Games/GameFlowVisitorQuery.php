<?php

namespace App\Queries\Games;

use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEmailCapture;
use App\Support\Games\Flow\FlowVisitor;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a version's flow needs to know about a visitor to decide which steps
 * they see: their audience, whether their email is known — signed in, or left
 * in this game before — and whether the Next Game step's game is there for
 * them: live, and giving their audience a version to play.
 */
final class GameFlowVisitorQuery
{
    public function __construct(private readonly GameTrafficQuery $traffic) {}

    public function for(GameVisitor $visitor, GameVersionSnapshot $snapshot, GameAudience $audience): FlowVisitor
    {
        $emailKnown = $visitor->userId !== null || ($visitor->visitorHash !== null && GameEmailCapture::query()
            ->where('game_id', $snapshot->gameId)
            ->whereHas('attempt', fn (Builder $attempt) => $attempt->where('visitor_hash', $visitor->visitorHash))
            ->exists());

        return new FlowVisitor($audience, $emailKnown, $this->nextGame($snapshot, $audience) !== null);
    }

    /**
     * The game the version's Next Game step sends a visitor of this audience
     * on to — while it is live and gives the audience a version, so the
     * visitor never lands on a game they cannot play.
     */
    public function nextGame(GameVersionSnapshot $snapshot, GameAudience $audience): ?Game
    {
        foreach ($snapshot->steps as $step) {
            if ($step->type === GameFlowStepType::NextGame && is_numeric($step->setting('next_game_id'))) {
                $game = Game::query()->whereKey((int) $step->setting('next_game_id'))->where('status', GameStatus::Live)->first();

                return $game !== null && $this->traffic->playableShares((int) $game->id, $audience) !== [] ? $game : null;
            }
        }

        return null;
    }
}
