<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Support\Translations\TranslatableField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (ProjectSettings::query()->whereNotNull('preset_applied_at')->exists()) {
            return;
        }

        DB::transaction(function (): void {
            foreach (config('project_presets.generic.categories', []) as $categoryData) {
                $translations = $categoryData['name'];

                Category::query()->updateOrCreate(
                    ['slug' => $categoryData['slug']],
                    [
                        'name' => $translations[TranslatableField::REFERENCE_LOCALE],
                        'name_translations' => TranslatableField::withoutReference($translations),
                        'sort_order' => $categoryData['sort_order'],
                        'is_active' => true,
                    ],
                );
            }
        });

        // Category clears this itself on a save, but DatabaseSeeder runs its
        // seeders without model events: the sidebar would list the old
        // categories until the cache ran out.
        Cache::forget('sidebar-nav-categories');
    }
}
