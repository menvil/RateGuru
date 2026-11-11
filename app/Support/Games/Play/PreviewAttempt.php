<?php

namespace App\Support\Games\Play;

use App\Enums\GameAnswerOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Support\Games\Scoring\GameScorer;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use Illuminate\Support\Collection;

/**
 * The picks of an admin preview as an attempt the game page can draw, scored
 * the way the game scores them — built in memory and never saved.
 */
final class PreviewAttempt
{
    /**
     * @param  array<int, array{position: int, outcome: string, points: int}>  $answers  by round
     */
    public static function of(int $gameId, GameAudience $audience, GameVersionSnapshot $snapshot, array $answers): ?GameAttempt
    {
        if ($answers === []) {
            return null;
        }

        $rows = new Collection;

        foreach ($answers as $index => $answer) {
            $row = new GameAnswer;
            $row->forceFill(['round_index' => $index, 'option_position' => $answer['position'], 'outcome' => GameAnswerOutcome::from($answer['outcome']), 'points' => $answer['points']]);
            $rows->push($row);
        }

        $completed = count($answers) >= $snapshot->roundCount();
        $result = GameScorer::result($snapshot, $rows->pluck('outcome')->all());

        $attempt = new GameAttempt;
        $attempt->forceFill([
            'game_id' => $gameId,
            'audience' => $audience,
            'status' => $completed ? GameAttemptStatus::Completed : GameAttemptStatus::Started,
            'rounds_answered' => count($answers),
            'matches' => $completed ? $result->matches : null,
            'score' => $completed ? $result->score : null,
            'max_score' => $result->maxScore,
            'profile_key' => $completed ? $result->profile?->key : null,
        ]);
        $attempt->setRelation('answers', $rows);

        return $attempt;
    }
}
