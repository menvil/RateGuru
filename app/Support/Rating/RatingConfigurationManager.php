<?php

namespace App\Support\Rating;

use App\Exceptions\Rating\InvalidRatingGroupConfigurationException;
use App\Models\RatingGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class RatingConfigurationManager
{
    /**
     * @return Collection<int, RatingGroup>
     */
    public function activeGroups(): Collection
    {
        return RatingGroup::query()
            ->active()
            ->with(['options' => $this->activeOptions(...)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function activeGroupByKey(string $key): ?RatingGroup
    {
        return RatingGroup::query()
            ->active()
            ->where('key', $key)
            ->with(['options' => $this->activeOptions(...)])
            ->first();
    }

    /**
     * @param  Collection<int, RatingGroup>|null  $groups
     * @return list<int>
     */
    public function sidebarGroupOptionIds(?Collection $groups = null): array
    {
        return ($groups ?? $this->activeGroups())
            ->first()
            ?->options
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all() ?? [];
    }

    /**
     * @param  Collection<int, RatingGroup>|null  $groups
     * @return list<int>
     */
    public function allActiveOptionIds(?Collection $groups = null): array
    {
        return ($groups ?? $this->activeGroups())
            ->flatMap(fn (RatingGroup $group) => $group->options->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function validateGroupHasAllowedOptionCount(RatingGroup $group): void
    {
        $activeOptionCount = $group->options()->active()->count();

        if ($activeOptionCount < $group->min_options || $activeOptionCount > $group->max_options) {
            throw new InvalidRatingGroupConfigurationException(
                "Rating group [{$group->key}] has {$activeOptionCount} active options; "
                ."expected between {$group->min_options} and {$group->max_options}.",
            );
        }
    }

    private function activeOptions(Relation $query): void
    {
        $query->getQuery()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
