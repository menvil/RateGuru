<?php

use Illuminate\Support\Str;

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
            'categories',
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

        foreach ($preset['categories'] as $category) {
            expect($category)->toHaveKeys([
                'slug',
                'name',
                'sort_order',
            ]);
            expect($category['name'])->toHaveKeys(['en', 'ru', 'bg']);
        }

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

it('keeps post categories separate from rating group terminology', function () {
    expect(array_column(config('project_presets.generic.rating_groups'), 'key'))
        ->toBe(['type', 'attribute'])
        ->and(array_column(config('project_presets.nature.rating_groups'), 'key'))
        ->toBe(['photographer_type', 'shot_type'])
        ->and(array_column(config('project_presets.ai_images.rating_groups'), 'key'))
        ->toBe(['model', 'style'])
        ->and(array_column(config('project_presets.breasts.rating_groups'), 'key'))
        ->toBe(['type', 'cup_size']);
});

it('does not duplicate preset categories as tags', function () {
    foreach (config('project_presets') as $preset) {
        $categorySlugs = array_column($preset['categories'], 'slug');
        $tagSlugs = collect($preset['tags'] ?? [])
            ->map(fn (array $tag): string => Str::slug($tag['en']))
            ->all();

        expect(array_intersect($categorySlugs, $tagSlugs))->toBeEmpty();
    }
});
