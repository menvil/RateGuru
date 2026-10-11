<?php

use Illuminate\Support\Facades\Schema;

it('has project settings translation columns', function () {
    expect(Schema::hasColumns('project_settings', [
        'site_name_translations',
        'site_tagline_translations',
        'site_description_translations',
        'object_singular_name_translations',
        'object_plural_name_translations',
        'upload_cta_label_translations',
        'feed_title_translations',
    ]))->toBeTrue();
});
