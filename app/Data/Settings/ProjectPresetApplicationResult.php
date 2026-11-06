<?php

namespace App\Data\Settings;

final readonly class ProjectPresetApplicationResult
{
    public function __construct(
        public string $presetKey,
        public int $categories,
        public int $deactivatedCategories,
        public int $ratingGroups,
        public int $ratingOptions,
        public int $tags,
        public int $removedTags,
    ) {}
}
