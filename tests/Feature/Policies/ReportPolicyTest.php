<?php

use App\Models\Report;
use App\Models\User;

dataset('report abilities', ['resolve', 'ignore']);

it('allows moderator to process a report', function (string $ability) {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create();

    expect($moderator->can($ability, $report))->toBeTrue();
})->with('report abilities');

it('allows admin to process a report', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create();

    expect($admin->can($ability, $report))->toBeTrue();
})->with('report abilities');

it('does not allow normal user to process a report', function (string $ability) {
    $user = User::factory()->create();
    $report = Report::factory()->create();

    expect($user->can($ability, $report))->toBeFalse();
})->with('report abilities');
