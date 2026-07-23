<?php

it('has project presets config', function () {
    expect(config('project_presets.generic'))->not->toBeNull();
    expect(config('project_presets.nature'))->not->toBeNull();
    expect(config('project_presets.ai_images'))->not->toBeNull();
    expect(config('project_presets.breasts'))->not->toBeNull();
});

it('project presets have required shape', function () {
    foreach (config('project_presets') as $preset) {
        expect($preset)->toHaveKeys([
            'label',
            'settings',
            'feature_flags',
            'rating_groups',
            'tags',
        ]);
        expect($preset['settings'])->toHaveKeys([
            'site_name',
            'site_tagline',
            'object_singular_name',
            'object_plural_name',
            'upload_cta_label',
            'feed_title',
            'default_locale',
            'default_theme',
            'default_sort',
        ]);
        expect($preset['feature_flags'])->toHaveKeys([
            'show_comments',
            'show_share_buttons',
            'show_vote_breakdown',
            'show_follow_buttons',
            'post_detail_overlay_mode',
            'show_saved_posts',
            'allow_user_uploads',
            'allow_guest_viewing',
        ]);

        if ($preset['rating_groups'] !== null) {
            foreach ($preset['rating_groups'] as $group) {
                expect($group)->toHaveKeys([
                    'key',
                    'label',
                    'description',
                    'sort_order',
                    'options',
                ]);

                foreach ($group['options'] as $option) {
                    expect($option)->toHaveKeys(['key', 'label', 'sort_order']);
                }
            }
        }

        if ($preset['tags'] !== null) {
            foreach ($preset['tags'] as $tag) {
                expect($tag)->toHaveKeys(['en', 'ru', 'bg']);
            }
        }
    }
});
