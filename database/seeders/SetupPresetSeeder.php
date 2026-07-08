<?php

namespace Database\Seeders;

use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Base seeder for applying a full site preset:
 *   - Project settings (name, labels, locale, theme, feature flags) with translations
 *   - Rating group questions + options with translations
 *   - Tags with translations
 *
 * Subclass and set $preset to one of the keys in config/project_presets.php.
 *
 * Usage:
 *   php artisan db:seed --class=SetupFoodSeeder
 *   php artisan db:seed --class=SetupNatureSeeder
 *   php artisan db:seed --class=SetupAiImagesSeeder
 */
abstract class SetupPresetSeeder extends Seeder
{
    /** Key from config/project_presets.php */
    abstract protected function presetKey(): string;

    public function run(): void
    {
        $key    = $this->presetKey();
        $preset = config("project_presets.{$key}");

        if ($preset === null) {
            $this->command->error("Unknown preset key: [{$key}]");

            return;
        }

        $this->command->info("Applying preset [{$key}]: {$preset['label']}");

        $this->applySettings($preset);
        $this->applyRatingGroups($preset);
        $this->applyTags($preset);

        app(ProjectSettingsManager::class)->flush();

        $this->command->info('Preset applied.');
    }

    private function applySettings(array $preset): void
    {
        $settings = PresetSettingsBuilder::build($preset['settings']);
        $flags    = $preset['feature_flags'] ?? [];

        ProjectSettings::updateOrCreate(
            ['id' => 1],
            array_merge($settings, ['active_preset_key' => $this->presetKey(), 'feature_flags' => $flags]),
        );

        $this->command->line('  Settings saved.');
    }

    private function applyRatingGroups(array $preset): void
    {
        $groups = $preset['rating_groups'] ?? null;

        if ($groups === null) {
            $this->command->line('  Rating groups: kept as-is (null in preset).');

            return;
        }

        RatingOption::query()->update(['is_active' => false, 'archived_at' => now()]);

        foreach ($groups as $groupData) {
            [$label, $labelTranslations]       = $this->splitTranslatable($groupData['label'] ?? null);
            [$description, $descTranslations]  = $this->splitTranslatable($groupData['description'] ?? null);

            $group = RatingGroup::query()->updateOrCreate(
                ['key' => $groupData['key']],
                [
                    'label'                  => $label,
                    'label_translations'     => $labelTranslations,
                    'description'            => $description,
                    'description_translations' => $descTranslations,
                    'sort_order'             => $groupData['sort_order'] ?? 10,
                    'is_active'              => true,
                    'min_options'            => 2,
                    'max_options'            => 10,
                ],
            );

            foreach ($groupData['options'] as $optionData) {
                [$optLabel, $optLabelTranslations]      = $this->splitTranslatable($optionData['label'] ?? null);
                [$optDesc, $optDescTranslations]        = $this->splitTranslatable($optionData['description'] ?? null);

                $group->options()->updateOrCreate(
                    ['key' => $optionData['key']],
                    [
                        'label'                    => $optLabel,
                        'label_translations'       => $optLabelTranslations,
                        'description'              => $optDesc,
                        'description_translations' => $optDescTranslations,
                        'sort_order'               => $optionData['sort_order'] ?? 10,
                        'is_active'                => true,
                        'archived_at'              => null,
                    ],
                );
            }

            $activeCount = $group->options()->where('is_active', true)->count();
            $this->command->line("  Group [{$group->key}]: \"{$group->label}\" ({$activeCount} active options)");
        }
    }

    private function applyTags(array $preset): void
    {
        $tags = $preset['tags'] ?? null;

        if ($tags === null || $tags === []) {
            $this->command->line('  Tags: kept as-is.');

            return;
        }

        Tag::query()->delete();

        $count = 0;
        foreach ($tags as $tagData) {
            [$name, $nameTranslations] = $this->splitTranslatable($tagData);
            $slug = Str::slug($name);

            Tag::query()->create([
                'name'               => $name,
                'name_translations'  => $nameTranslations,
                'slug'               => $slug,
            ]);
            $count++;
        }

        $this->command->line("  Tags: {$count} created (previous tags cleared.");
    }

    /**
     * Split a translatable value into [fallback, translations].
     * Accepts either a plain string or ['en' => ..., 'ru' => ..., 'bg' => ...].
     *
     * @return array{0: string|null, 1: array<string,string|null>|null}
     */
    private function splitTranslatable(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        if (is_string($value)) {
            return [$value, null];
        }

        $fallback = $value['en'] ?? null;

        return [$fallback, $value];
    }
}
