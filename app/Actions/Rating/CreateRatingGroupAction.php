<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;

final class CreateRatingGroupAction
{
    /**
     * A rating group in English, with nothing translated yet. It starts with
     * no options, so it starts inactive: an active group has to offer voters
     * between its minimum and maximum of active options, and an empty one
     * offers none. Its range stays within what any group allows.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $admin, array $data): RatingGroup
    {
        if (! $admin->can('create', RatingGroup::class)) {
            throw new AuthorizationException('User is not allowed to create rating groups.');
        }

        if (($data['is_active'] ?? false) === true) {
            throw new InvalidRatingGroupConfigurationException('A new rating group has no options yet, so it cannot start active.');
        }

        $min = (int) ($data['min_options'] ?? 0);
        $max = (int) ($data['max_options'] ?? 0);

        if ($min < RatingGroup::FEWEST_OPTIONS || $max > RatingGroup::MOST_OPTIONS) {
            throw new InvalidRatingGroupConfigurationException('A rating group\'s range of active options must lie between '.RatingGroup::FEWEST_OPTIONS.' and '.RatingGroup::MOST_OPTIONS.'.');
        }

        if ($min > $max) {
            throw new InvalidRatingGroupConfigurationException('A rating group\'s minimum of active options cannot be higher than its maximum.');
        }

        return RatingGroup::query()->create([
            ...Arr::only($data, ['key', 'label', 'description', 'min_options', 'max_options', 'sort_order']),
            'is_active' => false,
        ]);
    }
}
