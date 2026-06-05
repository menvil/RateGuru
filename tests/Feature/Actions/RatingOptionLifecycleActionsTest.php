<?php

use App\Actions\Rating\ArchiveRatingOptionAction;
use App\Actions\Rating\CreateRatingOptionAction;
use App\Actions\Rating\DeleteRatingOptionAction;
use App\Actions\Rating\UpdateRatingOptionAction;
use App\Exceptions\Rating\CannotDeleteVotedRatingOptionException;
use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;

it('does not allow deleting a rating option with votes', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->for($group, 'group')->create();

    RatingVote::factory()
        ->for($group, 'group')
        ->for($option, 'option')
        ->create();

    app(DeleteRatingOptionAction::class)->handle($admin, $option);
})->throws(CannotDeleteVotedRatingOptionException::class);

it('allows archiving a voted rating option when the minimum remains satisfied', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['min_options' => 2]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);

    RatingVote::factory()
        ->for($group, 'group')
        ->for($option, 'option')
        ->create();

    app(ArchiveRatingOptionAction::class)->handle($admin, $option);

    expect($option->fresh()->is_active)->toBeFalse()
        ->and($option->fresh()->archived_at)->not->toBeNull();
});

it('allows deleting an unvoted rating option when the minimum remains satisfied', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['min_options' => 2]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);

    app(DeleteRatingOptionAction::class)->handle($admin, $option);

    $this->assertDatabaseMissing('rating_options', ['id' => $option->id]);
});

it('does not allow archiving below the group minimum', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['min_options' => 2]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);

    app(ArchiveRatingOptionAction::class)->handle($admin, $option);
})->throws(InvalidRatingGroupConfigurationException::class);

it('does not allow deleting an active option below the group minimum', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['min_options' => 2]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);

    app(DeleteRatingOptionAction::class)->handle($admin, $option);
})->throws(InvalidRatingGroupConfigurationException::class);

it('enforces the group maximum inside the create rating option action', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['max_options' => 2]);
    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);

    app(CreateRatingOptionAction::class)->handle($admin, $group, [
        'key' => 'too_many',
        'label' => 'Too Many',
        'is_active' => true,
        'sort_order' => 30,
    ]);
})->throws(InvalidRatingGroupConfigurationException::class);

it('enforces the group minimum inside the update rating option action', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['min_options' => 2]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);

    app(UpdateRatingOptionAction::class)->handle($admin, $option, [
        'is_active' => false,
    ]);
})->throws(InvalidRatingGroupConfigurationException::class);
