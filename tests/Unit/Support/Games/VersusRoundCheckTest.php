<?php

use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;
use App\Support\Games\Versus\ImageState;
use App\Support\Games\Versus\VersusOptionDraft;
use App\Support\Games\Versus\VersusRoundCheck;
use App\Support\Games\Versus\VersusRoundDraft;

function postOption(int $postId, int $up, int $down, array $overrides = []): VersusOptionDraft
{
    return new VersusOptionDraft(...array_merge([
        'source' => GameOptionSource::Post,
        'postId' => $postId,
        'upvotes' => $up,
        'downvotes' => $down,
        'postPublic' => true,
        'assetId' => $postId + 1000,
        'image' => ImageState::Ready,
    ], $overrides));
}

function uploadOption(?int $assetId, bool $correct = false, ImageState $image = ImageState::Ready): VersusOptionDraft
{
    return new VersusOptionDraft(source: GameOptionSource::Upload, assetId: $assetId, image: $image, isCorrect: $correct);
}

function versusRound(array $options, GameRoundMode $mode = GameRoundMode::Crowd, bool $unscored = false, ?string $question = 'Which one?'): VersusRoundDraft
{
    return new VersusRoundDraft($question, $mode, $unscored, $options);
}

it('publishes a crowd round with a clear pick and scores by it', function () {
    $check = VersusRoundCheck::of(versusRound([postOption(1, 920, 80), postOption(2, 780, 220)]), 100, 5);

    expect($check->issues)->toBe([])
        ->and($check->mode)->toBe(GameRoundMode::Crowd)
        ->and($check->scored)->toBeTrue()
        ->and($check->answer)->toBe(0);
});

it('lists what an editor has to fix, in order', function () {
    $check = VersusRoundCheck::of(versusRound([
        new VersusOptionDraft(source: GameOptionSource::Post),
        postOption(2, 10, 0),
    ], question: ' '), 100, 5);

    expect($check->issues)->toBe(['Question missing', 'Option A: choose a post'])
        ->and($check->answer)->toBeNull();
});

it('explains a crowd round the votes cannot decide', function (array $a, array $b, string $issue) {
    $check = VersusRoundCheck::of(versusRound([postOption(1, ...$a), postOption(2, ...$b)]), 100, 5);

    expect($check->issues)->toBe([$issue]);
})->with([
    'a tie' => [[83, 17], [166, 34], 'Same upvote share · no crowd pick'],
    'too close' => [[83, 17], [81, 19], 'Too close to call: 83.0% vs 81.0% upvotes, the crowd pick needs a gap of 5 points'],
    'too few votes' => [[90, 10], [12, 0], 'Option B: only 12 of 100 site votes'],
]);

it('says a post that left the site, or a picture that is not ready, blocks the round', function () {
    $check = VersusRoundCheck::of(versusRound([
        postOption(1, 900, 100, ['postPublic' => false]),
        postOption(2, 100, 900, ['image' => ImageState::Processing]),
    ]), 100, 5);

    expect($check->issues)->toBe(['Option A: the post is no longer public', 'Option B image still processing']);
});

it('says an upload without its photo, or with a picture that is gone, blocks the round', function () {
    $check = VersusRoundCheck::of(versusRound([uploadOption(null), uploadOption(11, true, ImageState::Unavailable)], GameRoundMode::Objective), 100, 5);

    expect($check->issues)->toBe(['Option A photo missing', 'Option B image unavailable']);
});

it('refuses the same post on both sides', function () {
    $check = VersusRoundCheck::of(versusRound([postOption(1, 900, 100), postOption(1, 900, 100)]), 100, 5);

    expect($check->issues)->toBe(['Both options show the same post']);
});

it('decides a round with an upload by its correct answer, whatever mode was chosen', function () {
    $without = VersusRoundCheck::of(versusRound([uploadOption(10), postOption(2, 900, 100)]), 100, 5);
    $with = VersusRoundCheck::of(versusRound([uploadOption(10), uploadOption(11, correct: true)]), 100, 5);

    expect($without->mode)->toBe(GameRoundMode::Objective)
        ->and($without->issues)->toBe(['Correct answer not set'])
        ->and($with->issues)->toBe([])
        ->and($with->answer)->toBe(1);
});

it('asks for exactly one correct answer', function () {
    $check = VersusRoundCheck::of(versusRound([uploadOption(10, true), uploadOption(11, true)], GameRoundMode::Objective), 100, 5);

    expect($check->issues)->toBe(['Mark only one correct answer']);
});

it('publishes an unscored round without a decided point', function () {
    $check = VersusRoundCheck::of(versusRound([postOption(1, 83, 17), postOption(2, 166, 34)], unscored: true), 100, 5);

    expect($check->issues)->toBe([])
        ->and($check->scored)->toBeFalse()
        ->and($check->answer)->toBeNull();
});
