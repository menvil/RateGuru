<?php

namespace Database\Seeders;

use App\Models\ProjectSettings;
use Illuminate\Database\Seeder;

class DefaultProjectSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (ProjectSettings::query()->whereNotNull('preset_applied_at')->exists()) {
            return;
        }

        ProjectSettings::updateOrCreate(
            ['id' => 1],
            [
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
                    'show_follow_buttons' => true,
                    'post_detail_overlay_mode' => false,
                    'show_saved_posts' => false,
                    'allow_user_uploads' => true,
                    'allow_guest_viewing' => true,
                ],
            ]
        );
    }
}
