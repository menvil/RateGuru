<?php

namespace Database\Seeders;

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Database\Seeder;

class DefaultProjectSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (ProjectSettings::query()->whereNotNull('preset_applied_at')->exists()) {
            return;
        }

        $settings = ProjectSettings::query()->firstOrNew(['id' => 1]);
        $isNew = ! $settings->exists;

        $settings->fill([
            'site_name' => 'RateGuru',
            'site_tagline' => 'Rate anything',
            'site_description' => null,
            'object_singular_name' => 'post',
            'object_plural_name' => 'posts',
            'upload_cta_label' => 'Upload post',
            'feed_title' => 'Latest posts',
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
        ]);

        if ($isNew) {
            $settings->static_pages = config('static-pages.defaults');
            // The language policy of an existing project — which languages it
            // offers and its default — is never reseeded; only a new row gets
            // the defaults, and it offers every installed language.
            $settings->default_locale = app(ProjectSettingsManager::class)->defaults()['default_locale'];
        }

        $settings->save();
    }
}
