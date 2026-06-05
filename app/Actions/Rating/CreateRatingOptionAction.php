<?php

namespace App\Actions\Rating;

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Services\Rating\RatingGroupMutationLock;
use Illuminate\Auth\Access\AuthorizationException;

final class CreateRatingOptionAction
{
    public function __construct(
        private readonly RatingGroupMutationLock $mutationLock,
        private readonly ValidateRatingOptionTransitionAction $validateTransition,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $admin, RatingGroup $group, array $data): RatingOption
    {
        if (! $admin->can('create', RatingOption::class)) {
            throw new AuthorizationException('User is not allowed to create rating options.');
        }

        return $this->mutationLock->run($group->getKey(), function (RatingGroup $lockedGroup) use ($data): RatingOption {
            $isActive = (bool) ($data['is_active'] ?? true);

            $this->validateTransition->handle($lockedGroup, false, $isActive);

            return $lockedGroup->options()->create($data);
        });
    }
}
