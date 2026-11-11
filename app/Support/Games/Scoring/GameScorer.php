<?php

namespace App\Support\Games\Scoring;

use App\Enums\GameAnswerOutcome;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Games\Snapshot\SnapshotRound;
use InvalidArgumentException;

/**
 * The server's scoring. A pick is judged only against the version's
 * snapshot — the crowd's pick as published, or the correct option — never
 * against anything the browser says, and never against today's votes.
 */
final class GameScorer
{
    public static function judge(SnapshotRound $round, int $position, int $pointsPerRound): AnswerOutcome
    {
        if ($round->option($position) === null) {
            throw new InvalidArgumentException('The round has no option at that position.');
        }

        if (! $round->scored || $round->answer === null) {
            return new AnswerOutcome(GameAnswerOutcome::Neutral, 0, null);
        }

        return $position === $round->answer
            ? new AnswerOutcome(GameAnswerOutcome::Correct, $pointsPerRound, $round->answer)
            : new AnswerOutcome(GameAnswerOutcome::Incorrect, 0, $round->answer);
    }

    /** @param  list<GameAnswerOutcome>  $outcomes */
    public static function result(GameVersionSnapshot $snapshot, array $outcomes): GameResult
    {
        $matches = count(array_filter($outcomes, fn (GameAnswerOutcome $outcome): bool => $outcome === GameAnswerOutcome::Correct));

        return new GameResult(
            matches: $matches,
            scored: $snapshot->scoredRoundCount(),
            score: $matches * $snapshot->pointsPerRound,
            maxScore: $snapshot->maxScore(),
            profile: $snapshot->profileFor($matches),
        );
    }
}
