<?php

use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\GameSignupMode;
use App\Enums\GameStepAudience;
use App\Support\Games\Flow\FlowIssue;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Flow\FlowVisitor;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Games\Flow\GameFlowValidator;

/**
 * Steps of the given types, each fresh — default texts and settings — keyed
 * by its place, with settings overridden by type.
 *
 * @param  list<GameFlowStepType>  $types
 * @param  array<string, array<string, mixed>>  $settings
 * @return list<FlowStepDraft>
 */
function flowOf(array $types, array $settings = []): array
{
    return array_map(function (GameFlowStepType $type, int $index) use ($settings): FlowStepDraft {
        $fresh = GameFlowValidator::fresh($type, 's'.$index);

        return new FlowStepDraft($fresh->key, $type, $fresh->audience, [...$fresh->settings, ...($settings[$type->value] ?? [])], $fresh->texts);
    }, $types, array_keys($types));
}

/** @param  list<FlowIssue>  $issues */
function flowMessages(array $issues): array
{
    return array_map(fn (FlowIssue $issue): string => ($issue->index >= 0 ? ($issue->index + 1).': ' : '').$issue->message, $issues);
}

it('accepts the three starting flows', function (array $types, array $settings) {
    expect(GameFlowValidator::issues(flowOf($types, $settings)))->toBe([]);
})->with([
    'result first' => [[GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], []],
    'email before result' => [[
        GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::ResultPreview,
        GameFlowStepType::EmailCapture, GameFlowStepType::FullResult, GameFlowStepType::Registration,
    ], ['full_result' => ['signup' => GameSignupMode::Step->value]]],
    'direct play' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Share, GameFlowStepType::Feed], []],
]);

it('needs the game exactly once and a result after it', function () {
    expect(flowMessages(GameFlowValidator::issues(flowOf([GameFlowStepType::Landing]))))->toBe([
        'Missing Game step. Every flow plays the game exactly once.',
        'Missing Full Result. Players never see their result; add a Full Result after the game.',
    ]);

    expect(flowMessages(GameFlowValidator::issues(flowOf([GameFlowStepType::Game, GameFlowStepType::Game, GameFlowStepType::FullResult]))))
        ->toBe(['2: Second Game step. A flow plays the game once; remove this one.']);
});

it('keeps every step in its place around the game', function () {
    $issues = flowMessages(GameFlowValidator::issues(flowOf([
        GameFlowStepType::FullResult, GameFlowStepType::Game, GameFlowStepType::Intro, GameFlowStepType::Feed, GameFlowStepType::Share,
    ])));

    expect($issues)->toBe([
        '1: Full Result comes before the Game step. Results can only follow gameplay.',
        '3: Intro comes after the Game step. Move it before the game.',
        '4: Go to Feed ends the flow, so it must be the last step.',
    ]);
});

it('orders the result steps', function () {
    $issues = flowMessages(GameFlowValidator::issues(flowOf([
        GameFlowStepType::Game, GameFlowStepType::Share, GameFlowStepType::FullResult, GameFlowStepType::ResultPreview,
    ])));

    expect($issues)->toBe([
        '2: Share comes before the result. Players can only share a result they have seen.',
        '4: Result Preview comes after the Full Result. A preview has to come first.',
    ]);
});

it('allows one exit and no step twice', function () {
    $issues = flowMessages(GameFlowValidator::issues(flowOf([
        GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Share, GameFlowStepType::Share, GameFlowStepType::NextGame, GameFlowStepType::Feed,
    ], ['next_game' => ['next_game_id' => 9]])));

    expect($issues)->toBe([
        '4: Duplicate Share step. Step 3 already does this.',
        '5: Next Game ends the flow, so it must be the last step.',
        '6: Use one exit step: Next Game or Go to Feed.',
    ]);
});

it('ties the registration step to how the result invites sign-up', function () {
    $stepWithout = GameFlowValidator::issues(flowOf([GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['signup' => 'step']]));
    $inlineWith = GameFlowValidator::issues(flowOf([GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration]));

    expect(flowMessages($stepWithout))->toBe(['2: Full Result sends sign-up to a Registration step, but the flow has none. Add Registration or show sign-up on the result.'])
        ->and(flowMessages($inlineWith))->toBe(['3: Sign-up is already on Full Result. Remove this step, or set Full Result to a separate Registration step.']);
});

it('asks for the texts each step needs, as it is set', function () {
    $steps = flowOf([GameFlowStepType::Game, GameFlowStepType::EmailCapture, GameFlowStepType::FullResult]);
    $email = $steps[1];
    $steps[1] = new FlowStepDraft($email->key, $email->type, $email->audience, $email->settings, ['title' => '', 'secondary_label' => '', 'consent_label' => '']);

    expect(flowMessages(GameFlowValidator::issues($steps)))
        ->toBe(['2: Missing settings: Title, Email field label, Submit button, Skip link label, Privacy notice, Consent label.']);
});

it('asks for the teaser only while it stands in for the player\'s numbers', function () {
    $teaserless = function (bool $partial): FlowStepDraft {
        $preview = GameFlowValidator::fresh(GameFlowStepType::ResultPreview, 'p');

        return new FlowStepDraft($preview->key, $preview->type, $preview->audience, ['show_partial' => $partial], [...$preview->texts, 'body' => '']);
    };

    expect($teaserless(true)->missingTexts())->toBe([])
        ->and($teaserless(false)->requiredTexts())->toBe(['title', 'body', 'button_label'])
        ->and($teaserless(false)->missingTexts())->toBe(['Teaser message']);
});

it('never shows an email or sign-up step to signed-in players', function () {
    $steps = flowOf([GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration], ['full_result' => ['signup' => 'step']]);
    $registration = $steps[2];
    $steps[2] = new FlowStepDraft($registration->key, $registration->type, GameStepAudience::All, $registration->settings, $registration->texts);

    expect(flowMessages(GameFlowValidator::issues($steps)))
        ->toBe(['3: Registration CTA is shown to anonymous visitors only. A signed-in player already has an account and an email.']);
});

it('offers only the steps that fit where an editor adds one', function () {
    $steps = flowOf([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult]);

    expect(GameFlowValidator::insertableAt($steps, 0))->toContain(GameFlowStepType::Intro)
        ->not->toContain(GameFlowStepType::Share)
        ->and(GameFlowValidator::insertableAt($steps, 3))->toContain(GameFlowStepType::Share, GameFlowStepType::Feed)
        ->not->toContain(GameFlowStepType::Intro)
        // Full Result invites sign-up on itself, so a Registration step would be a second invitation.
        ->not->toContain(GameFlowStepType::Registration)
        ->and(GameFlowValidator::defaultPosition($steps, GameFlowStepType::ResultPreview))->toBe(2);
});

it('never offers a step that settles one problem by causing another', function () {
    $gameless = flowOf([GameFlowStepType::Landing, GameFlowStepType::FullResult]);
    $resultless = flowOf([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::Share]);

    expect(GameFlowValidator::insertableAt($gameless, 2))->not->toContain(GameFlowStepType::Game)
        ->and(GameFlowValidator::defaultPosition($gameless, GameFlowStepType::Game))->toBe(1)
        ->and(GameFlowValidator::insertableAt($resultless, 3))->not->toContain(GameFlowStepType::FullResult)
        ->and(GameFlowValidator::defaultPosition($resultless, GameFlowStepType::FullResult))->toBe(2);
});

it('still offers a step before a duplicate, whose step number it only moves', function () {
    $steps = flowOf([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Share, GameFlowStepType::Share]);

    expect(GameFlowValidator::insertableAt($steps, 0))->toContain(GameFlowStepType::Intro);
});

it('keeps only the settings a type takes, within their bounds', function () {
    expect(GameFlowStepSettings::normalize(GameFlowStepType::Game, ['advance' => 'later', 'advance_seconds' => 30, 'evil' => 1]))
        ->toBe(['advance' => 'auto', 'advance_seconds' => 8.0])
        ->and(GameFlowStepSettings::normalize(GameFlowStepType::Game, ['advance' => 'button', 'advance_seconds' => '2.3']))
        ->toBe(['advance' => 'button', 'advance_seconds' => 2.5])
        ->and(GameFlowStepSettings::normalize(GameFlowStepType::EmailCapture, ['required' => '1']))
        ->toBe(['required' => true, 'skip_known' => true, 'consent_enabled' => true]);
});

it('skips the steps a visitor should not see', function () {
    $steps = flowOf([
        GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::EmailCapture,
        GameFlowStepType::FullResult, GameFlowStepType::Registration, GameFlowStepType::NextGame,
    ], ['full_result' => ['signup' => 'step'], 'next_game' => ['next_game_id' => 4]]);
    $types = fn (array $visible): array => array_map(fn (FlowStepDraft $step): string => $step->type->value, $visible);

    expect($types(GameFlowRunner::visible($steps, new FlowVisitor(GameAudience::Anonymous))))
        ->toBe(['landing', 'game', 'email_capture', 'full_result', 'registration', 'next_game'])
        ->and($types(GameFlowRunner::visible($steps, new FlowVisitor(GameAudience::Anonymous, emailKnown: true, nextGameAvailable: false))))
        ->toBe(['landing', 'game', 'full_result', 'registration'])
        ->and($types(GameFlowRunner::visible($steps, new FlowVisitor(GameAudience::Authenticated, emailKnown: true))))
        ->toBe(['landing', 'game', 'full_result', 'next_game']);
});

it('moves on from a step that has just become invisible', function () {
    $steps = flowOf([GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration, GameFlowStepType::Feed], ['full_result' => ['signup' => 'step']]);

    // A guest registered on step s2 and is now signed in.
    expect(GameFlowRunner::after($steps, new FlowVisitor(GameAudience::Authenticated), 's2')?->type)->toBe(GameFlowStepType::Feed)
        ->and(GameFlowRunner::after($steps, new FlowVisitor(GameAudience::Authenticated), 's3'))->toBeNull();
});
