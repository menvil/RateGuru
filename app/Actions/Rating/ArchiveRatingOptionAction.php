<?php

namespace App\Actions\Rating;

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Services\Rating\RatingGroupMutationLock;
use Illuminate\Auth\Access\AuthorizationException;

final class ArchiveRatingOptionAction
{
    public function __construct(
        private readonly RatingGroupMutationLock $mutationLock,
        private readonly ValidateRatingOptionTransitionAction $validateTransition,
    ) {}

    public function handle(User $admin, RatingOption $option): void
    {
        if (! $admin->can('update', $option)) {
            throw new AuthorizationException('User is not allowed to archive rating options.');
        }

        $this->mutationLock->run($option->rating_group_id, function (RatingGroup $group) use ($option): void {
            $locked = $group->options()->lockForUpdate()->find($option->getKey());

            if ($locked === null) {
                return;
            }

            if ($locked->is_active) {
                $this->validateTransition->handle($group, true, false);
            }

            $locked->update([
                'is_active' => false,
                'archived_at' => $locked->archived_at ?? now(),
            ]);
        });
    }
}
