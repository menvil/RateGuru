<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;

final class ValidateRatingOptionTransitionAction
{
    public function handle(RatingGroup $group, bool $currentlyActive, bool $willBeActive): void
    {
        if ($currentlyActive === $willBeActive) {
            return;
        }

        $active = $group->options()->active()->count();

        $allowed = $willBeActive ? $group->hasRoomForActiveOption($active) : $group->canSpareActiveOption($active);

        if (! $allowed) {
            throw new InvalidRatingGroupConfigurationException(
                "Rating group [{$group->key}] must keep between "
                ."{$group->min_options} and {$group->max_options} active options.",
            );
        }
    }
}
