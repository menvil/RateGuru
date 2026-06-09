<?php

namespace Database\Seeders;

use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Base seeder for applying a full site preset:
 *   - Project settings (name, labels, locale, theme, feature flags)
 *   - Rating group questions + options (Source, Category)
 *   - Tags
 *
 * Subclass and set $preset to one of the keys in config/project_presets.php.
 *
 * Usage:
 *   php artisan db:seed --class=SetupFoodGeneralSeeder
 *   php artisan db:seed --class=SetupFoodBgSeeder
 *   php artisan db:seed --class=SetupFoodItSeeder
 *   php artisan db:seed --class=SetupFoodRuSeeder
 *   php artisan db:seed --class=SetupNatureSeeder
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
        $this->command->table(
            ['Setting', 'Value'],
            collect($preset['settings'])->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : (string) $v])->values()->all(),
        );
    }

    private function applySettings(array $preset): void
    {
        $settings = array_merge($preset['settings'], ['active_preset_key' => $this->presetKey()]);
        $flags    = $preset['feature_flags'] ?? [];

        ProjectSettings::updateOrCreate(
            ['id' => 1],
            array_merge($settings, ['feature_flags' => $flags]),
        );

        $this->command->line("  Settings saved.");
    }

    private function applyRatingGroups(array $preset): void
    {
        $groups = $preset['rating_groups'] ?? null;

        if ($groups === null) {
            $this->command->line("  Rating groups: kept as-is (null in preset).");

            return;
        }

        // Deactivate all existing options before applying the new preset config
        RatingOption::query()->update(['is_active' => false, 'archived_at' => now()]);

        foreach ($groups as $groupData) {
            $options = $groupData['options'];

            $group = RatingGroup::query()->updateOrCreate(
                ['key' => $groupData['key']],
                [
                    'label'       => $groupData['label'],
                    'description' => $groupData['description'] ?? null,
                    'sort_order'  => $groupData['sort_order'] ?? 10,
                    'is_active'   => true,
                    'min_options' => 2,
                    'max_options' => 10,
                ],
            );

            // Restore/update only the options in this preset
            foreach ($options as $optionData) {
                $group->options()->updateOrCreate(
                    ['key' => $optionData['key']],
                    [
                        'label'       => $optionData['label'],
                        'description' => $optionData['description'] ?? null,
                        'sort_order'  => $optionData['sort_order'] ?? 10,
                        'is_active'   => true,
                        'archived_at' => null,
                    ],
                );
            }

            $this->command->line("  Group [{$group->key}]: \"{$group->label}\" ({$group->options()->where('is_active', true)->count()} active options)");
        }
    }

    private function applyTags(array $preset): void
    {
        $tags = $preset['tags'] ?? null;

        if ($tags === null || $tags === []) {
            $this->command->line("  Tags: kept as-is.");

            return;
        }

        $count = 0;
        foreach ($tags as $tagName) {
            $slug = Str::slug($tagName);
            Tag::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $tagName],
            );
            $count++;
        }

        $this->command->line("  Tags: {$count} upserted.");
    }
}
