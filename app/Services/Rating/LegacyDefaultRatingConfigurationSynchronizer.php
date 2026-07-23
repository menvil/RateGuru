<?php

namespace App\Services\Rating;

use App\Models\RatingGroup;

final class LegacyDefaultRatingConfigurationSynchronizer
{
    public function synchronize(): void
    {
        $legacyDefaults = [
            'source' => ['source_a', 'source_b'],
            'category' => ['category_a', 'category_b', 'category_c'],
        ];

        $groups = RatingGroup::query()
            ->active()
            ->whereIn('key', array_keys($legacyDefaults))
            ->with('options')
            ->get();

        foreach ($groups as $group) {
            $optionKeys = $group->options
                ->pluck('key')
                ->sort()
                ->values()
                ->all();

            if ($optionKeys !== $legacyDefaults[$group->key]) {
                continue;
            }

            $group->options()->update([
                'is_active' => false,
                'archived_at' => now(),
            ]);
            $group->update(['is_active' => false]);
        }
    }
}
