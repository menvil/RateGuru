<?php

use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\GameSignupMode;
use App\Support\Games\Flow\FlowGoalRules;

$resultFirst = [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult];

it('finds the step that reaches each goal', function (array $types, array $settings, GameFlowGoal $goal, ?int $step) {
    expect(FlowGoalRules::stepFor(flowOf($types, $settings), $goal))->toBe($step);
})->with([
    'account on Full Result sign-up' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], [], GameFlowGoal::Account, 1],
    'account on Registration' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration], ['full_result' => ['signup' => GameSignupMode::Step->value]], GameFlowGoal::NewAccount, 2],
    'account on the site after a post' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], ['full_result' => ['signup' => GameSignupMode::None->value]], GameFlowGoal::Account, 2],
    'account when Full Result sends on' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['onward' => 'feed']], GameFlowGoal::Account, 1],
    'email' => [[GameFlowStepType::Game, GameFlowStepType::EmailCapture, GameFlowStepType::FullResult], [], GameFlowGoal::Email, 1],
    'no email step' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], [], GameFlowGoal::Email, null],
    'shared on Full Result' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], [], GameFlowGoal::Shared, 1],
    'shared on Full Result that sends on' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['onward' => 'post']], GameFlowGoal::Shared, 1],
    'no sharing once its buttons are off' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['onward' => 'post', 'share_enabled' => false]], GameFlowGoal::Shared, null],
    'post voted via Full Result' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['onward' => 'post']], GameFlowGoal::PostVoted, 1],
    'post rated needs a group asked' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], [], GameFlowGoal::PostRated, null],
    'post rated with a group' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], ['go_to_post' => ['ask_groups' => 'cuteness']], GameFlowGoal::PostRated, 2],
    'feed via Full Result' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['onward' => 'feed']], GameFlowGoal::Feed, 1],
    'feed voted on Go to Feed' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Feed], [], GameFlowGoal::FeedVoted, 2],
    'page' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPage], [], GameFlowGoal::Page, 2],
    'next game' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::NextGame], [], GameFlowGoal::NextGame, 2],
    'completed on the game' => [[GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], [], GameFlowGoal::Completed, 1],
    'replayed from the result' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], [], GameFlowGoal::Replayed, 1],
]);

it('reaches the game finished and played again in every flow', function () use ($resultFirst) {
    expect(FlowGoalRules::reaches(flowOf($resultFirst), GameFlowGoal::Completed))->toBeTrue()
        ->and(FlowGoalRules::reaches(flowOf($resultFirst), GameFlowGoal::Replayed))->toBeTrue()
        ->and(FlowGoalRules::reaches(flowOf($resultFirst), GameFlowGoal::NextGame))->toBeFalse();
});

it('tells an account reached on the site from one asked for in the flow', function () {
    $onSite = flowOf([GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], ['full_result' => ['signup' => GameSignupMode::None->value]]);
    $inFlow = flowOf([GameFlowStepType::Game, GameFlowStepType::FullResult]);

    expect(FlowGoalRules::viaSite($onSite, GameFlowGoal::Account))->toBeTrue()
        ->and(FlowGoalRules::viaSite($inFlow, GameFlowGoal::Account))->toBeFalse()
        ->and(FlowGoalRules::viaSite($onSite, GameFlowGoal::PostVoted))->toBeFalse();
});

it('gives a flow with no goal the goal its steps are there for', function (array $types, array $settings, GameFlowGoal $goal) {
    expect(FlowGoalRules::defaultFor(flowOf($types, $settings)))->toBe($goal);
})->with([
    'result first: an account' => [[GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], [], GameFlowGoal::Account],
    'email before result: an account' => [[GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::ResultPreview, GameFlowStepType::EmailCapture, GameFlowStepType::FullResult, GameFlowStepType::Registration], ['full_result' => ['signup' => GameSignupMode::Step->value]], GameFlowGoal::Account],
    'direct play: a share' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Share, GameFlowStepType::Feed], [], GameFlowGoal::Shared],
    'a post with the vote asked' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], [], GameFlowGoal::PostVoted],
    'a page' => [[GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPage], [], GameFlowGoal::Page],
    'nothing after the result' => [[GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['signup' => GameSignupMode::None->value, 'share_enabled' => false]], GameFlowGoal::Completed],
]);
