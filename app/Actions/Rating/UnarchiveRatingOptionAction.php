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
     */
    public function handle(User $admin, RatingOption $option): void
    {
        if (! $admin->can('update', $option)) {
            throw new AuthorizationException('User is not allowed to unarchive rating options.');
        }

        $this->mutationLock->run($option->rating_group_id, function (RatingGroup $group) use ($option): void {
            $locked = $group->options()->lockForUpdate()->find($option->getKey());

            $locked?->update(['archived_at' => null]);
        });
    }
}
