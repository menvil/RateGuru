<?php

namespace App\Support\Settings;

use App\Models\ProjectSettings;

class ProjectSettingsManager
{
    private const DEFAULTS = [
        'site_name' => 'RateGuru',
        'site_tagline' => 'Rate anything',
        'site_description' => null,
        'object_singular_name' => 'post',
        'object_plural_name' => 'posts',
        'upload_cta_label' => 'Upload post',
        'feed_title' => 'Latest posts',
        'default_locale' => 'en',
        'default_theme' => 'system',
        'default_sort' => 'hot',
        'active_preset_key' => 'generic',
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts' => false,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
        ],
    ];

    private ?ResolvedProjectSettings $resolved = null;

    public function current(): ResolvedProjectSettings
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $row = ProjectSettings::first();

        $data = $row
            ? array_merge(self::DEFAULTS, $row->toArray(), [
                'feature_flags' => array_merge(
                    self::DEFAULTS['feature_flags'],
                    $row->feature_flags ?? []
                ),
            ])
            : self::DEFAULTS;

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
}
