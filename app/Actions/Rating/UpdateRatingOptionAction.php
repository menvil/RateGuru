<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\RatingOptionIsArchivedException;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Services\Rating\RatingGroupMutationLock;
use Illuminate\Auth\Access\AuthorizationException;

final class UpdateRatingOptionAction
{
    public function __construct(
        private readonly RatingGroupMutationLock $mutationLock,
        private readonly ValidateRatingOptionTransitionAction $validateTransition,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $admin, RatingOption $option, array $data): RatingOption
    {
        if (! $admin->can('update', $option)) {
            throw new AuthorizationException('User is not allowed to update rating options.');
        }

        return $this->mutationLock->run(
            $option->rating_group_id,
            function (RatingGroup $group) use ($option, $data): RatingOption {
                $lockedOption = $group->options()->lockForUpdate()->findOrFail($option->getKey());
                $willBeActive = (bool) ($data['is_active'] ?? $lockedOption->is_active);

                // Read under the lock: the editor that sent this may have been
                // drawn before someone archived the option.
                if ($willBeActive && $lockedOption->archived_at !== null) {
                    throw new RatingOptionIsArchivedException('An archived option cannot be active; unarchive it first.');
                }

                $this->validateTransition->handle($group, $lockedOption->is_active, $willBeActive);
                $lockedOption->update($data);

                return $lockedOption;
            },
        );
    }
}
