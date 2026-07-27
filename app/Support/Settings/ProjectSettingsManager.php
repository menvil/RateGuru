<?php

namespace App\Support\Settings;

use App\Models\ProjectSettings;

class ProjectSettingsManager
{
    private const DEFAULTS = [
        'site_name' => 'RateGuru',
        'site_name_translations' => null,
        'site_tagline' => 'Rate anything',
        'site_tagline_translations' => null,
        'site_description' => null,
        'site_description_translations' => null,
        'object_singular_name' => 'post',
        'object_singular_name_translations' => null,
        'object_plural_name' => 'posts',
        'object_plural_name_translations' => null,
        'upload_cta_label' => 'Upload post',
        'upload_cta_label_translations' => null,
        'feed_title' => 'Latest posts',
        'feed_title_translations' => null,
        'default_locale' => 'en',
        'default_theme' => 'system',
        'default_sort' => 'hot',
        'active_preset_key' => 'generic',
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts' => false,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
            'allow_url_imports' => true,
        ],
    ];

    private ?ResolvedProjectSettings $resolved = null;

    public function current(): ResolvedProjectSettings
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $defaults = array_merge(self::DEFAULTS, [
            'static_pages' => config('static-pages.defaults', []),
        ]);
        $row = ProjectSettings::find(1);

        $data = $row
            ? array_merge($defaults, $row->toArray(), [
                'feature_flags' => array_merge(
                    self::DEFAULTS['feature_flags'],
                    $row->feature_flags ?? []
                ),
                'static_pages' => $this->mergeStaticPages(
                    $defaults['static_pages'],
                    $row->static_pages ?? [],
                ),
            ])
            : $defaults;

        return $this->resolved = new ResolvedProjectSettings($data);
    }

    public function featureEnabled(string $key): bool
    {
        return $this->current()->featureFlag($key);
    }

    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * Keep default pages and locales, but preserve missing localized fields so
     * ResolvedProjectSettings can fall them back to English independently.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeStaticPages(array $defaults, array $overrides): array
    {
        foreach ($overrides as $pageKey => $locales) {
            if (! is_array($locales) || ! isset($defaults[$pageKey])) {
                continue;
            }

            foreach ($locales as $locale => $localized) {
                if (is_array($localized)) {
                    $defaults[$pageKey][$locale] = $localized;
                }
            }
        }

        return $defaults;
    }
}
