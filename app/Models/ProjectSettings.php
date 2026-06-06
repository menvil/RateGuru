<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectSettings extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'feature_flags',
    ];

    protected $casts = [
        'feature_flags' => 'array',
    ];
}
