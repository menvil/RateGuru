<?php

use App\Models\ProjectSettings;
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

it('casts project settings translation fields to arrays', function () {
    $settings = ProjectSettings::factory()->create([
        'site_name_translations' => ['ru' => 'КотоГуру'],
    ]);

    expect($settings->site_name_translations['ru'])->toBe('КотоГуру');
});

it('allows null translation fields', function () {
    $settings = ProjectSettings::factory()->create([
        'site_name_translations' => null,
    ]);

    expect($settings->site_name_translations)->toBeNull();
});
