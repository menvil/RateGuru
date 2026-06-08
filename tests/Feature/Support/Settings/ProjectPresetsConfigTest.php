<?php

it('has project presets config', function () {
    expect(config('project_presets.generic'))->not->toBeNull();
    expect(config('project_presets.food'))->not->toBeNull();
    expect(config('project_presets.cats'))->not->toBeNull();
    expect(config('project_presets.ai_images'))->not->toBeNull();
});

it('project presets have required shape', function () {
    foreach (config('project_presets') as $preset) {
        expect($preset)->toHaveKeys(['label', 'settings', 'feature_flags']);
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
            'show_saved_posts',
            'allow_user_uploads',
            'allow_guest_viewing',
        ]);
    }
});
