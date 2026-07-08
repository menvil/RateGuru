<?php

namespace App\Support\Settings;

class PresetSettingsBuilder
{
    private const TRANSLATABLE = [
        'site_name',
        'site_tagline',
        'site_description',
        'object_singular_name',
        'object_plural_name',
        'upload_cta_label',
        'feed_title',
    ];

    /**
     * Build a flat settings array from a preset config, splitting translatable
     * fields into `field` (en fallback) + `field_translations` (full array).
     *
     * @param  array<string, mixed>  $presetSettings
     * @return array<string, mixed>
     */
    public static function build(array $presetSettings): array
    {
        $result = [];

        foreach ($presetSettings as $field => $value) {
            if (in_array($field, self::TRANSLATABLE, true)) {
                $translations = is_array($value) ? $value : [];
                $result[$field] = $translations['en'] ?? null;
                $result["{$field}_translations"] = $translations ?: null;
            } else {
                $result[$field] = $value;
            }
        }

        return $result;
    }
}
