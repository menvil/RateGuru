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
        'default_locale',
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

it('leaves the offered languages unset on a row written before they existed', function () {
    // Every installation that predates the column keeps offering every
    // installed language, with no data migration.
    DB::table('project_settings')->insert([
        'id' => 1,
        'site_name' => 'RateGuru',
        'object_singular_name' => 'post',
        'object_plural_name' => 'posts',
        'upload_cta_label' => 'Upload post',
        'feed_title' => 'Latest posts',
    ]);

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull()
        ->and(app(LocaleManager::class)->enabledCodes())->toBe(supportedLocales());
});
