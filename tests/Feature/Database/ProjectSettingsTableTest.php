<?php

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
        'default_theme',
        'default_sort',
        'active_preset_key',
        'preset_applied_at',
        'feature_flags',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
