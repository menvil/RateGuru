<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_name',
        'site_name_translations',
        'site_tagline',
        'site_tagline_translations',
        'site_description',
        'site_description_translations',
        'object_singular_name',
        'object_singular_name_translations',
        'object_plural_name',
        'object_plural_name_translations',
        'upload_cta_label',
        'upload_cta_label_translations',
        'feed_title',
        'feed_title_translations',
        'static_pages',
        'default_locale',
        'enabled_locales',
        'default_theme',
        'default_sort',
        'active_preset_key',
        'preset_applied_at',
        'feature_flags',
        'sign_in_providers',
    ];

    protected $casts = [
        'feature_flags' => 'array',
        'sign_in_providers' => 'array',
        'site_name_translations' => 'array',
        'site_tagline_translations' => 'array',
        'site_description_translations' => 'array',
        'object_singular_name_translations' => 'array',
        'object_plural_name_translations' => 'array',
        'upload_cta_label_translations' => 'array',
        'feed_title_translations' => 'array',
        'static_pages' => 'array',
        'enabled_locales' => 'array',
        'preset_applied_at' => 'datetime',
    ];
}
