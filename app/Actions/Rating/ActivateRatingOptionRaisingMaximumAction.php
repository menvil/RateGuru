<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Services\Rating\RatingGroupMutationLock;
use Illuminate\Auth\Access\AuthorizationException;

final class ActivateRatingOptionRaisingMaximumAction
{
    public function __construct(private readonly RatingGroupMutationLock $mutationLock) {}

    /**
     * Activates an option of a group already at its maximum, raising the
     * maximum by the one it needs — together, under the group's lock, so the
     * range never stops holding the active options. Refused for an option
     * that is active or archived, and once the maximum would pass the most
     * any group allows.
     *
     * @return int the group's maximum now
     */
    public function handle(User $admin, RatingOption $option): int
    {
        if (! $admin->can('update', $option)) {
            throw new AuthorizationException('User is not allowed to activate rating options.');
        }

        return $this->mutationLock->run($option->rating_group_id, function (RatingGroup $group) use ($admin, $option): int {
            if (! $admin->can('update', $group)) {
                throw new AuthorizationException('User is not allowed to change rating groups.');
            }

            $locked = $group->options()->lockForUpdate()->find($option->getKey());

            if ($locked === null || $locked->is_active || $locked->archived_at !== null) {
                throw new InvalidRatingGroupConfigurationException('Only an inactive option that is not archived can be activated.');
            }

            $maximum = $group->options()->active()->count() + 1;

            if ($maximum > RatingGroup::MOST_OPTIONS) {
                throw new InvalidRatingGroupConfigurationException('A rating group allows at most '.RatingGroup::MOST_OPTIONS.' active options.');
            }

            if ($maximum > $group->max_options) {
                $group->update(['max_options' => $maximum]);
            }

            $locked->update(['is_active' => true]);

            return $group->max_options;
        });
    }
}
