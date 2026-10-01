<?php

namespace Database\Seeders;

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Database\Seeder;

class DefaultProjectSettingsSeeder extends Seeder
{
    /**
     * Reseeded on an existing row that never had a preset applied; everything
     * else a row holds is its own once it exists.
     */
    private const RESEEDED = [
        'site_name',
        'site_tagline',
        'site_description',
        'object_singular_name',
        'object_plural_name',
        'upload_cta_label',
        'feed_title',
        'default_theme',
        'default_sort',
        'active_preset_key',
        'feature_flags',
    ];

    public function run(): void
    {
        if (ProjectSettings::query()->whereNotNull('preset_applied_at')->exists()) {
            return;
        }

        // The one bootstrap every writer that creates the row uses.
        $defaults = app(ProjectSettingsManager::class)->defaults();
        $settings = ProjectSettings::query()->find(1);

        if ($settings === null) {
            // A new row gets all of it: the static pages it will own from now
            // on, and no chosen languages — the ones enabled by default.
            ProjectSettings::unguarded(fn (): ProjectSettings => ProjectSettings::query()->create(['id' => 1, ...$defaults]));

            return;
        }

        // The pages and the language policy of an existing project are never
        // reseeded.
        $settings->fill(array_intersect_key($defaults, array_flip(self::RESEEDED)))->save();
    }
}
