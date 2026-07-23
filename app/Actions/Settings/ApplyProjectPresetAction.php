<?php

namespace App\Actions\Settings;

use App\Data\Settings\ProjectPresetApplicationResult;
use App\Exceptions\Settings\ProjectPresetAlreadyAppliedException;
use App\Exceptions\Settings\ProjectPresetHasContentException;
use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplyProjectPresetAction
{
    public function __construct(private readonly ProjectSettingsManager $manager) {}

    public function handle(string $presetKey, bool $force = false): ProjectPresetApplicationResult
    {
        $presets = config('project_presets');

        if (! is_array($presets) || ! array_key_exists($presetKey, $presets)) {
            throw UnknownProjectPresetException::for($presetKey);
        }

        $preset = $presets[$presetKey];

        if (! is_array($preset['settings'] ?? null) || ! is_array($preset['feature_flags'] ?? null)) {
            throw UnknownProjectPresetException::for($presetKey);
        }

        $result = DB::transaction(function () use ($force, $presetKey, $preset): ProjectPresetApplicationResult {
            $settings = PresetSettingsBuilder::build($preset['settings']);
            $appliedSettings = array_merge($settings, [
                'feature_flags' => $preset['feature_flags'],
                'active_preset_key' => $presetKey,
                'preset_applied_at' => now(),
            ]);

            $initialSettings = ProjectSettings::unguarded(
                fn (): ProjectSettings => ProjectSettings::query()->firstOrCreate(
                    ['id' => 1],
                    $appliedSettings,
                ),
            );

            $existingSettings = ProjectSettings::query()->lockForUpdate()->findOrFail(1);

            if (! $force && ! $initialSettings->wasRecentlyCreated && $existingSettings->preset_applied_at !== null) {
                throw ProjectPresetAlreadyAppliedException::make();
            }

            if (! $force && Post::query()->exists()) {
                throw ProjectPresetHasContentException::make();
            }

            $existingSettings->fill($appliedSettings)->save();

            [$categories, $deactivatedCategories] = $this->applyCategories($preset['categories'] ?? null);
            [$ratingGroups, $ratingOptions] = $this->applyRatingGroups($preset['rating_groups'] ?? null);
            [$tags, $removedTags] = $this->applyTags($preset['tags'] ?? null);

            return new ProjectPresetApplicationResult(
                presetKey: $presetKey,
                categories: $categories,
                deactivatedCategories: $deactivatedCategories,
                ratingGroups: $ratingGroups,
                ratingOptions: $ratingOptions,
                tags: $tags,
                removedTags: $removedTags,
            );
        });

        $this->manager->flush();
        Cache::forget('sidebar-nav-categories');

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $categories
     * @return array{0: int, 1: int}
     */
    private function applyCategories(?array $categories): array
    {
        if ($categories === null) {
            return [0, 0];
        }

        $keptSlugs = array_column($categories, 'slug');
        $deactivatedCategories = Category::query()
            ->active()
            ->whereNotIn('slug', $keptSlugs)
            ->update(['is_active' => false]);

        foreach ($categories as $categoryData) {
            [$name, $nameTranslations] = $this->splitTranslatable($categoryData['name'] ?? null);

            Category::query()->updateOrCreate(
                ['slug' => $categoryData['slug']],
                [
                    'name' => $name,
                    'name_translations' => $nameTranslations,
                    'sort_order' => $categoryData['sort_order'] ?? 10,
                    'is_active' => true,
                ],
            );
        }

        return [count($categories), $deactivatedCategories];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $groups
     * @return array{0: int, 1: int}
     */
    private function applyRatingGroups(?array $groups): array
    {
        if ($groups === null) {
            return [0, 0];
        }

        RatingGroup::query()->update(['is_active' => false]);
        RatingOption::query()->update(['is_active' => false, 'archived_at' => now()]);

        $optionCount = 0;

        foreach ($groups as $groupData) {
            [$label, $labelTranslations] = $this->splitTranslatable($groupData['label'] ?? null);
            [$description, $descriptionTranslations] = $this->splitTranslatable($groupData['description'] ?? null);

            $group = RatingGroup::query()->updateOrCreate(
                ['key' => $groupData['key']],
                [
                    'label' => $label,
                    'label_translations' => $labelTranslations,
                    'description' => $description,
                    'description_translations' => $descriptionTranslations,
                    'sort_order' => $groupData['sort_order'] ?? 10,
                    'is_active' => true,
                    'min_options' => 2,
                    'max_options' => 10,
                ],
            );

            foreach ($groupData['options'] as $optionData) {
                [$optionLabel, $optionLabelTranslations] = $this->splitTranslatable($optionData['label'] ?? null);
                [$optionDescription, $optionDescriptionTranslations] = $this->splitTranslatable($optionData['description'] ?? null);

                $group->options()->updateOrCreate(
                    ['key' => $optionData['key']],
                    [
                        'label' => $optionLabel,
                        'label_translations' => $optionLabelTranslations,
                        'description' => $optionDescription,
                        'description_translations' => $optionDescriptionTranslations,
                        'sort_order' => $optionData['sort_order'] ?? 10,
                        'is_active' => true,
                        'archived_at' => null,
                    ],
                );

                $optionCount++;
            }
        }

        return [count($groups), $optionCount];
    }

    /**
     * @param  array<int, array<string, string|null>|string>|null  $tags
     * @return array{0: int, 1: int}
     */
    private function applyTags(?array $tags): array
    {
        if ($tags === null || $tags === []) {
            return [0, 0];
        }

        $keptSlugs = [];

        foreach ($tags as $tagData) {
            [$name, $nameTranslations] = $this->splitTranslatable($tagData);
            $slug = Str::slug((string) $name);
            $keptSlugs[] = $slug;

            Tag::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'name_translations' => $nameTranslations,
                ],
            );
        }

        $removedTags = Tag::query()->whereNotIn('slug', $keptSlugs)->delete();

        return [count($tags), $removedTags];
    }

    /**
     * @return array{0: string|null, 1: array<string, string|null>|null}
     */
    private function splitTranslatable(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        if (is_string($value)) {
            return [$value, null];
        }

        return [$value['en'] ?? null, $value];
    }
}
