<?php

namespace App\Actions\Rating;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use App\Models\User;
use App\Services\Rating\RatingGroupMutationLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;

final class UpdateRatingGroupAction
{
    /** What a saved group's editor may change. The key is fixed once the group exists. */
    private const FIELDS = ['label', 'description', 'min_options', 'max_options', 'is_active', 'sort_order'];

    public function __construct(private readonly RatingGroupMutationLock $mutationLock) {}

    /**
     * A group's English text, its range of active options, its place on the
     * post page and whether voters see it. Its translations are Translation
     * Center's and are never touched here.
     *
     * Under the group's lock, so the options counted are the options it has:
     * an active group must keep between its minimum and maximum of active
     * options, as the option actions keep it, and a change to the range or to
     * whether the group is active is refused when it would leave it outside.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $admin, RatingGroup $group, array $data): RatingGroup
    {
        if (! $admin->can('update', $group)) {
            throw new AuthorizationException('User is not allowed to update rating groups.');
        }

        return $this->mutationLock->run($group->getKey(), function (RatingGroup $locked) use ($data): RatingGroup {
            $locked->fill(Arr::only($data, self::FIELDS));

            if ($locked->min_options > $locked->max_options) {
                throw new InvalidRatingGroupConfigurationException('A rating group\'s minimum of active options cannot be higher than its maximum.');
            }

            if ($locked->is_active) {
                $active = $locked->options()->active()->count();

                if ($active < $locked->min_options || $active > $locked->max_options) {
                    throw new InvalidRatingGroupConfigurationException(
                        "Rating group [{$locked->key}] has {$active} active options; "
                        ."an active group needs between {$locked->min_options} and {$locked->max_options}.",
                    );
                }
            }

            $locked->save();

            return $locked;
        });
    }
}
