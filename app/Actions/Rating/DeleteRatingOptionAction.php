<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\CannotDeleteVotedRatingOptionException;
use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingOption;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeleteRatingOptionAction
{
    public function handle(User $admin, RatingOption $option): void
    {
        if (! $admin->can('delete', $option)) {
            throw new AuthorizationException('User is not allowed to delete rating options.');
        }

        DB::transaction(function () use ($option): void {
            $locked = RatingOption::query()
                ->lockForUpdate()
                ->find($option->getKey());

            if ($locked === null) {
                return;
            }

            if ($locked->votes()->exists()) {
                throw new CannotDeleteVotedRatingOptionException(
                    'Rating options with votes cannot be deleted. Archive the option instead.',
                );
            }

            if ($locked->is_active) {
                $group = $locked->group()->firstOrFail();
                $activeCount = $group->options()->active()->count();

                if ($activeCount <= $group->min_options) {
                    throw new InvalidRatingGroupConfigurationException(
                        "Rating group [{$group->key}] must keep at least {$group->min_options} active options.",
                    );
                }
            }

            $locked->delete();
        });
    }
}
