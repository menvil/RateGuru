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

        $projectedCount = $group->options()->active()->count() + ($willBeActive ? 1 : -1);

        $violatesMaximum = $willBeActive && $projectedCount > $group->max_options;
        $violatesMinimum = ! $willBeActive && $projectedCount < $group->min_options;

        if ($violatesMaximum || $violatesMinimum) {
            throw new InvalidRatingGroupConfigurationException(
                "Rating group [{$group->key}] must keep between "
                ."{$group->min_options} and {$group->max_options} active options.",
            );
        }
    }
}
