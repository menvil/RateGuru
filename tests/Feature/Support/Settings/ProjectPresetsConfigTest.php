<?php

use App\Models\RatingGroup;
use Illuminate\Support\Str;

it('project presets have required shape', function () {
    $presets = [
        ...config('project_presets'),
        'without_categories' => [
            ...config('project_presets.generic'),
            'categories' => null,
        ],
    ];

    foreach ($presets as $preset) {
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
            'default_theme',
            'default_sort',
        ]);
        // Language policy is not a preset's: no preset names a default.
        expect($preset['settings'])->not->toHaveKey('default_locale');
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

        if ($preset['categories'] !== null) {
            foreach ($preset['categories'] as $category) {
                expect($category)->toHaveKeys([
                    'slug',
                    'name',
                    'sort_order',
                ]);
                // English alone: a project translates its content itself, in Translation Center.
                expect(array_keys($category['name']))->toBe(['en']);
            }
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

                // A preset's group is made with the widest range a group may
                // have, so its options must fit in it.
                expect(count($group['options']))->toBeGreaterThanOrEqual(RatingGroup::FEWEST_OPTIONS)
                    ->toBeLessThanOrEqual(RatingGroup::MOST_OPTIONS);
            }
        }

        if ($preset['tags'] !== null) {
            foreach ($preset['tags'] as $tag) {
                expect(array_keys($tag))->toBe(['en']);
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
    $presets = [
        ...config('project_presets'),
        'without_categories' => [
            ...config('project_presets.generic'),
            'categories' => null,
        ],
    ];

    foreach ($presets as $preset) {
        if ($preset['categories'] === null) {
            continue;
        }

        $categorySlugs = array_column($preset['categories'], 'slug');
        $tagSlugs = collect($preset['tags'] ?? [])
            ->map(fn (array $tag): string => Str::slug($tag['en']))
            ->all();

        expect(array_intersect($categorySlugs, $tagSlugs))->toBeEmpty();
    }
});
