<?php

use App\Actions\Rating\ActivateRatingOptionRaisingMaximumAction;
use App\Actions\Rating\CreateRatingGroupAction;
use App\Actions\Rating\UnarchiveRatingOptionAction;
use App\Actions\Rating\UpdateRatingGroupAction;
use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** A group with this many active options. */
function rangedGroup(int $active, int $min = 2, int $max = 4, bool $isActive = true): RatingGroup
{
    $group = RatingGroup::factory()->create(['min_options' => $min, 'max_options' => $max, 'is_active' => $isActive]);
    RatingOption::factory()->count($active)->for($group, 'group')->create(['is_active' => true]);

    return $group;
}

it('creates a group inactive, refusing to start it active', function () {
    $admin = User::factory()->admin()->create();
    $data = ['key' => 'real_or_fake', 'label' => 'Real or fake', 'min_options' => 2, 'max_options' => 4, 'sort_order' => 1];

    expect(fn () => app(CreateRatingGroupAction::class)->handle($admin, [...$data, 'is_active' => true]))
        ->toThrow(InvalidRatingGroupConfigurationException::class);

    expect(app(CreateRatingGroupAction::class)->handle($admin, $data))->is_active->toBeFalse()->key->toBe('real_or_fake');
});

it('authorizes creating and changing groups inside the actions', function () {
    $moderator = User::factory()->moderator()->create();
    $group = rangedGroup(2);

    expect(fn () => app(CreateRatingGroupAction::class)->handle($moderator, ['key' => 'blocked', 'label' => 'Blocked', 'min_options' => 2, 'max_options' => 4]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateRatingGroupAction::class)->handle($moderator, $group, ['label' => 'Blocked']))
        ->toThrow(AuthorizationException::class);

    expect(RatingGroup::query()->where('key', 'blocked')->exists())->toBeFalse()
        ->and($group->fresh()->label)->not->toBe('Blocked');
});

it('refuses a range that leaves an active group\'s options outside it, and keeps the group as it was', function (array $change) {
    $group = rangedGroup(3, min: 2, max: 4);

    expect(fn () => app(UpdateRatingGroupAction::class)->handle(User::factory()->admin()->create(), $group, $change))
        ->toThrow(InvalidRatingGroupConfigurationException::class);

    expect($group->fresh())->min_options->toBe(2)->max_options->toBe(4);
})->with([
    'a minimum above the active options' => [['min_options' => 4]],
    'a maximum below them' => [['max_options' => 2]],
    'a minimum above the maximum' => [['min_options' => 4, 'max_options' => 3]],
]);

it('lets an inactive group sit outside its range, and refuses to activate it there', function () {
    $admin = User::factory()->admin()->create();
    $group = rangedGroup(1, isActive: false);

    app(UpdateRatingGroupAction::class)->handle($admin, $group, ['min_options' => 3, 'label' => 'Tricks']);

    expect($group->fresh())->min_options->toBe(3)->label->toBe('Tricks');

    expect(fn () => app(UpdateRatingGroupAction::class)->handle($admin, $group, ['is_active' => true]))
        ->toThrow(InvalidRatingGroupConfigurationException::class);

    expect($group->fresh()->is_active)->toBeFalse();
});

it('never changes a group\'s key', function () {
    $group = rangedGroup(2);
    $key = $group->key;

    app(UpdateRatingGroupAction::class)->handle(User::factory()->admin()->create(), $group, ['key' => 'forged', 'label' => 'Renamed']);

    expect($group->fresh())->key->toBe($key)->label->toBe('Renamed');
});

it('unarchives an option inactive, leaving the group\'s active options as they were', function () {
    $group = rangedGroup(2);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => false, 'archived_at' => now()]);

    app(UnarchiveRatingOptionAction::class)->handle(User::factory()->admin()->create(), $option);

    expect($option->fresh())->archived_at->toBeNull()->is_active->toBeFalse()
        ->and($group->options()->active()->count())->toBe(2);
});

it('raises a full group\'s maximum by one to activate an option, together', function () {
    $group = rangedGroup(4, max: 4);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => false]);

    expect(app(ActivateRatingOptionRaisingMaximumAction::class)->handle(User::factory()->admin()->create(), $option))->toBe(5)
        ->and($group->fresh()->max_options)->toBe(5)
        ->and($option->fresh()->is_active)->toBeTrue();
});

it('refuses to raise the maximum for an active or archived option, or past the most a group allows', function (Closure $option) {
    $group = rangedGroup(RatingGroup::MOST_OPTIONS - 1, max: RatingGroup::MOST_OPTIONS - 1);
    $target = $option($group);
    $maximum = $group->fresh()->max_options;

    expect(fn () => app(ActivateRatingOptionRaisingMaximumAction::class)->handle(User::factory()->admin()->create(), $target))
        ->toThrow(InvalidRatingGroupConfigurationException::class);

    expect($group->fresh()->max_options)->toBe($maximum)
        ->and($target->fresh()->is_active)->toBe($target->is_active);
})->with([
    'an active option' => [fn (RatingGroup $group): RatingOption => $group->options()->firstOrFail()],
    'an archived option' => [fn (RatingGroup $group): RatingOption => RatingOption::factory()->for($group, 'group')->create(['is_active' => false, 'archived_at' => now()])],
    'past the most' => [function (RatingGroup $group): RatingOption {
        RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
        $group->update(['max_options' => RatingGroup::MOST_OPTIONS]);

        return RatingOption::factory()->for($group, 'group')->create(['is_active' => false]);
    }],
]);
