<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ProjectSettings;
use Illuminate\Database\Seeder;

class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (ProjectSettings::query()->whereNotNull('preset_applied_at')->exists()) {
            return;
        }

        foreach (config('project_presets.generic.categories', []) as $categoryData) {
            $translations = $categoryData['name'];

            Category::query()->updateOrCreate(
                ['slug' => $categoryData['slug']],
                [
                    'name' => $translations['en'],
                    'name_translations' => $translations,
                    'sort_order' => $categoryData['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }
}
