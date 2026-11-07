<?php

namespace App\Actions\Rating;

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Services\Rating\RatingGroupMutationLock;
use Illuminate\Auth\Access\AuthorizationException;

final class UnarchiveRatingOptionAction
{
    public function __construct(private readonly RatingGroupMutationLock $mutationLock) {}

    /**
     * Takes an option out of the archive. It comes back inactive, so the
     * group's count of active options does not change: activating it is a
     * step of its own, held to the group's maximum like any other.
     *
     * Only an archived option is touched — one that is not, or no longer, is
     * left as it is rather than switched off past the group's minimum.
     *
     * @return bool whether the option was taken out of the archive
     */
    public function handle(User $admin, RatingOption $option): bool
    {
        if (! $admin->can('update', $option)) {
            throw new AuthorizationException('User is not allowed to unarchive rating options.');
        }

        return $this->mutationLock->run($option->rating_group_id, function (RatingGroup $group) use ($option): bool {
            $locked = $group->options()->lockForUpdate()->find($option->getKey());

            if ($locked === null || $locked->archived_at === null) {
                return false;
            }

            return $locked->update(['archived_at' => null, 'is_active' => false]);
        });
    }
}
