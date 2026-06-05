<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingOption;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ArchiveRatingOptionAction
{
    public function handle(User $admin, RatingOption $option): void
    {
        if (! $admin->can('update', $option)) {
            throw new AuthorizationException('User is not allowed to archive rating options.');
        }

        DB::transaction(function () use ($option): void {
            $locked = RatingOption::query()
                ->lockForUpdate()
                ->find($option->getKey());

            if ($locked === null) {
                return;
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

            $locked->update([
                'is_active' => false,
                'archived_at' => $locked->archived_at ?? now(),
            ]);
        });
    }
}
