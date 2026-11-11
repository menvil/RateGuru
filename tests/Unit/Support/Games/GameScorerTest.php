<?php

use App\Enums\GameAnswerOutcome;
use App\Support\Games\Scoring\GameScorer;
use App\Support\Games\Snapshot\GameVersionSnapshot;

/** A snapshot of three rounds — two scored, one unscored — and two profiles. */
function scoringSnapshot(int $points = 2): GameVersionSnapshot
{
    $option = fn (int $position): array => ['position' => $position, 'source' => 'post', 'post_id' => 10 + $position, 'asset_id' => 20 + $position, 'label' => [], 'upvotes' => 90, 'downvotes' => 10];
    $round = fn (string $key, bool $scored, ?int $answer): array => [
        'key' => $key, 'question' => ['en' => 'Which?'], 'mode' => 'crowd', 'scored' => $scored, 'show_crowd' => true,
        'answer' => $answer, 'options' => [$option(0), $option(1)],
    ];

    return GameVersionSnapshot::fromArray([
        'schema' => 1,
        'game' => ['id' => 1, 'slug' => 'taste', 'mechanic' => 'versus', 'title' => ['en' => 'Taste'], 'description' => [], 'cover_asset_id' => null],
        'scoring' => ['points_per_round' => $points, 'crowd_min_votes' => 100, 'crowd_min_gap' => 5],
        'rounds' => [$round('r1', true, 0), $round('r2', false, null), $round('r3', true, 1)],
        'profiles' => [
            ['key' => 'low', 'name' => ['en' => 'Low'], 'min' => 0, 'max' => 1, 'icon' => 'compass'],
            ['key' => 'high', 'name' => ['en' => 'High'], 'min' => 2, 'max' => 2, 'icon' => 'sparkles'],
        ],
        'flow' => ['id' => 1, 'name' => 'Result First', 'steps' => []],
        'locales' => ['en'],
    ]);
}

it('judges a pick against the published answer only', function () {
    $snapshot = scoringSnapshot();

    expect(GameScorer::judge($snapshot->round(0), 0, 2)->outcome)->toBe(GameAnswerOutcome::Correct)
        ->and(GameScorer::judge($snapshot->round(0), 0, 2)->points)->toBe(2)
        ->and(GameScorer::judge($snapshot->round(0), 1, 2)->outcome)->toBe(GameAnswerOutcome::Incorrect)
        ->and(GameScorer::judge($snapshot->round(1), 1, 2)->outcome)->toBe(GameAnswerOutcome::Neutral);
});

it('refuses a pick of an option the round does not have', function () {
    GameScorer::judge(scoringSnapshot()->round(0), 2, 1);
})->throws(InvalidArgumentException::class);

it('works out matches, score and profile from the answers', function () {
    $snapshot = scoringSnapshot();
    $result = GameScorer::result($snapshot, [GameAnswerOutcome::Correct, GameAnswerOutcome::Neutral, GameAnswerOutcome::Correct]);

    expect($snapshot->scoredRoundCount())->toBe(2)
        ->and($result->matches)->toBe(2)
        ->and($result->score)->toBe(4)
        ->and($result->maxScore)->toBe(4)
        ->and($result->profile?->key)->toBe('high');
});

it('keeps a snapshot exactly as it was published', function () {
    $snapshot = scoringSnapshot();

    expect(GameVersionSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('refuses a snapshot of a shape it does not know', function () {
    GameVersionSnapshot::fromArray(['schema' => 99]);
})->throws(InvalidArgumentException::class);
