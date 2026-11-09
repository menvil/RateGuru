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
 * in this game before — and whether the Next Game step's game is live.
 */
final class GameFlowVisitorQuery
{
    public function for(GameVisitor $visitor, GameVersionSnapshot $snapshot, GameAudience $audience): FlowVisitor
    {
        $emailKnown = $visitor->userId !== null || ($visitor->visitorHash !== null && GameEmailCapture::query()
            ->where('game_id', $snapshot->gameId)
            ->whereHas('attempt', fn (Builder $attempt) => $attempt->where('visitor_hash', $visitor->visitorHash))
            ->exists());

        $next = null;

        foreach ($snapshot->steps as $step) {
            if ($step->type === GameFlowStepType::NextGame) {
                $next = $step->setting('next_game_id');
            }
        }

        $nextLive = is_numeric($next) && Game::query()->whereKey((int) $next)->where('status', GameStatus::Live)->exists();

        return new FlowVisitor($audience, $emailKnown, $nextLive);
    }
}
