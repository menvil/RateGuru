<?php

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has project settings table', function () {
    expect(Schema::hasTable('project_settings'))->toBeTrue();

    expect(Schema::hasColumns('project_settings', [
        'id',
        'site_name',
        'site_tagline',
        'site_description',
        'object_singular_name',
        'object_plural_name',
        'upload_cta_label',
        'feed_title',
        'enabled_locales',
        'default_theme',
        'default_sort',
        'active_preset_key',
        'preset_applied_at',
        'feature_flags',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('has no per-project default language', function () {
    // English is the default by system policy (config/locales.php).
    expect(Schema::hasColumn('project_settings', 'default_locale'))->toBeFalse();
});

it('brings the per-project default language back as English when the migration is rolled back', function () {
    $migration = require database_path('migrations/2026_10_01_100000_drop_default_locale_from_project_settings_table.php');
    ProjectSettings::factory()->create();

    try {
        $migration->down();

        expect(Schema::hasColumn('project_settings', 'default_locale'))->toBeTrue()
            ->and(DB::table('project_settings')->where('id', 1)->value('default_locale'))->toBe('en');
    } finally {
        $migration->up();

        // MariaDB commits implicitly on ALTER TABLE, which ends the test
        // transaction that would otherwise discard this row.
        DB::table('project_settings')->where('id', 1)->delete();
    }

    expect(Schema::hasColumn('project_settings', 'default_locale'))->toBeFalse();
});

it('leaves the offered languages unset on a row written before they existed', function () {
    // An installation that predates the column keeps offering the languages
    // enabled by default — every language it offered before the column
    // existed — with no data migration.
    DB::table('project_settings')->insert([
        'id' => 1,
        'site_name' => 'RateGuru',
        'object_singular_name' => 'post',
        'object_plural_name' => 'posts',
        'upload_cta_label' => 'Upload post',
        'feed_title' => 'Latest posts',
    ]);

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull()
        ->and(app(LocaleManager::class)->enabledCodes())->toBe(app(LocaleManager::class)->enabledByDefault());
});
