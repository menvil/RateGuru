<?php

return [

    // -------------------------------------------------------------------------
    // Generic fallback
    // -------------------------------------------------------------------------

    'generic' => [
        'label' => 'Generic rating',
        'settings' => [
            'site_name' => ['en' => 'RateGuru'],
            'site_tagline' => ['en' => 'Rate anything'],
            'site_description' => ['en' => null],
            'object_singular_name' => ['en' => 'post'],
            'object_plural_name' => ['en' => 'posts'],
            'upload_cta_label' => ['en' => 'Upload post'],
            'feed_title' => ['en' => 'Latest posts'],
            'default_theme' => 'system',
            'default_sort' => 'hot',
        ],
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
        'categories' => [
            ['slug' => 'general', 'name' => ['en' => 'General'], 'sort_order' => 10],
            ['slug' => 'showcase', 'name' => ['en' => 'Showcase'], 'sort_order' => 20],
            ['slug' => 'other', 'name' => ['en' => 'Other'], 'sort_order' => 30],
        ],
        'rating_groups' => [
            [
                'key' => 'type',
                'label' => ['en' => 'Type'],
                'description' => ['en' => null],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'type_a', 'label' => ['en' => 'Type A'], 'sort_order' => 10],
                    ['key' => 'type_b', 'label' => ['en' => 'Type B'], 'sort_order' => 20],
                ],
            ],
            [
                'key' => 'attribute',
                'label' => ['en' => 'Attribute'],
                'description' => ['en' => null],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'attribute_a', 'label' => ['en' => 'Attribute A'], 'sort_order' => 10],
                    ['key' => 'attribute_b', 'label' => ['en' => 'Attribute B'], 'sort_order' => 20],
                    ['key' => 'attribute_c', 'label' => ['en' => 'Attribute C'], 'sort_order' => 30],
                ],
            ],
        ],
        'tags' => null,
    ],

    // -------------------------------------------------------------------------
    // Nature / Travel Photography
    // -------------------------------------------------------------------------

    'nature' => [
        'label' => 'Nature & travel photography',
        'settings' => [
            'site_name' => ['en' => 'NatureGuru'],
            'site_tagline' => ['en' => 'Rate stunning nature photos'],
            'site_description' => [
                'en' => 'Community-powered ratings for nature and travel photography.',
            ],
            'object_singular_name' => ['en' => 'photo'],
            'object_plural_name' => ['en' => 'photos'],
            'upload_cta_label' => ['en' => 'Upload photo'],
            'feed_title' => ['en' => 'Latest photos'],
            'default_theme' => 'dark',
            'default_sort' => 'hot',
        ],
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts' => true,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
        ],
        'categories' => [
            ['slug' => 'landscape', 'name' => ['en' => 'Landscape'], 'sort_order' => 10],
            ['slug' => 'wildlife', 'name' => ['en' => 'Wildlife'], 'sort_order' => 20],
            ['slug' => 'macro', 'name' => ['en' => 'Macro'], 'sort_order' => 30],
            ['slug' => 'urban', 'name' => ['en' => 'Urban'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'photographer_type',
                'label' => ['en' => 'How was this photo taken?'],
                'description' => ['en' => 'Was it shot professionally or by an amateur?'],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'professional', 'label' => ['en' => 'Professional'], 'sort_order' => 10],
                    ['key' => 'amateur',      'label' => ['en' => 'Amateur'],    'sort_order' => 20],
                ],
            ],
            [
                'key' => 'shot_type',
                'label' => ['en' => 'What type of shot is this?'],
                'description' => ['en' => 'Choose the technique that best describes the shot.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'wide', 'label' => ['en' => 'Wide shot'], 'sort_order' => 10],
                    ['key' => 'close_up', 'label' => ['en' => 'Close-up'], 'sort_order' => 20],
                    ['key' => 'aerial', 'label' => ['en' => 'Aerial'], 'sort_order' => 30],
                    ['key' => 'long_exposure', 'label' => ['en' => 'Long exposure'], 'sort_order' => 40],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Sunrise'],
            ['en' => 'Sunset'],
            ['en' => 'Golden hour'],
            ['en' => 'Long exposure'],
            ['en' => 'Milky way'],
            ['en' => 'Waterfall'],
            ['en' => 'Mountains'],
            ['en' => 'Beach'],
            ['en' => 'Forest'],
            ['en' => 'Desert'],
            ['en' => 'Snow'],
            ['en' => 'Birds'],
            ['en' => 'Flowers'],
            ['en' => 'Fog'],
            ['en' => 'Storm'],
            ['en' => 'Rainbow'],
            ['en' => 'Reflection'],
            ['en' => 'Night sky'],
            ['en' => 'Tropical'],
            ['en' => 'Arctic'],
        ],
    ],

    // -------------------------------------------------------------------------
    // AI image rating
    // -------------------------------------------------------------------------

    'ai_images' => [
        'label' => 'AI image rating',
        'settings' => [
            'site_name' => ['en' => 'AIGuru'],
            'site_tagline' => ['en' => 'Rate AI-generated images'],
            'site_description' => [
                'en' => 'Community ratings for AI-generated artwork.',
            ],
            'object_singular_name' => ['en' => 'image'],
            'object_plural_name' => ['en' => 'images'],
            'upload_cta_label' => ['en' => 'Upload image'],
            'feed_title' => ['en' => 'Latest images'],
            'default_theme' => 'system',
            'default_sort' => 'hot',
        ],
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
        'categories' => [
            ['slug' => 'portrait', 'name' => ['en' => 'Portrait'], 'sort_order' => 10],
            ['slug' => 'landscape', 'name' => ['en' => 'Landscape'], 'sort_order' => 20],
            ['slug' => 'character', 'name' => ['en' => 'Character'], 'sort_order' => 30],
            ['slug' => 'architecture', 'name' => ['en' => 'Architecture'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'model',
                'label' => ['en' => 'Which AI model generated this?'],
                'description' => ['en' => null],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'midjourney', 'label' => ['en' => 'Midjourney'],      'sort_order' => 10],
                    ['key' => 'dalle',      'label' => ['en' => 'DALL·E'],          'sort_order' => 20],
                    ['key' => 'stable',     'label' => ['en' => 'Stable Diff.'],    'sort_order' => 30],
                    ['key' => 'other',      'label' => ['en' => 'Other / Unknown'], 'sort_order' => 40],
                ],
            ],
            [
                'key' => 'style',
                'label' => ['en' => 'What style is this image?'],
                'description' => ['en' => 'Choose the visual style.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'photorealistic', 'label' => ['en' => 'Photorealistic'], 'sort_order' => 10],
                    ['key' => 'illustration',   'label' => ['en' => 'Illustration'],     'sort_order' => 20],
                    ['key' => 'concept_art',    'label' => ['en' => 'Concept art'],    'sort_order' => 30],
                    ['key' => 'pixel_art',      'label' => ['en' => 'Pixel art'],     'sort_order' => 40],
                    ['key' => 'abstract',       'label' => ['en' => 'Abstract'],     'sort_order' => 50],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Fantasy'],
            ['en' => 'Sci-fi'],
            ['en' => 'Anime'],
            ['en' => 'Dark'],
            ['en' => 'Colorful'],
            ['en' => 'Minimalist'],
            ['en' => 'Surreal'],
            ['en' => 'Nature'],
            ['en' => 'Space'],
            ['en' => 'Cyberpunk'],
            ['en' => 'Steampunk'],
        ],
    ],

    // -------------------------------------------------------------------------
    // Breast rating
    // -------------------------------------------------------------------------

    'breasts' => [
        'label' => 'Breast rating',
        'settings' => [
            'site_name' => ['en' => 'BreastGuru'],
            'site_tagline' => ['en' => 'Rate every pair'],
            'site_description' => [
                'en' => 'Community-powered breast ratings.',
            ],
            'object_singular_name' => ['en' => 'photo'],
            'object_plural_name' => ['en' => 'photos'],
            'upload_cta_label' => ['en' => 'Upload photo'],
            'feed_title' => ['en' => 'Latest photos'],
            'default_theme' => 'system',
            'default_sort' => 'hot',
        ],
        'feature_flags' => [
            'show_comments' => true,
            'show_share_buttons' => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts' => true,
            'allow_user_uploads' => true,
            'allow_guest_viewing' => true,
        ],
        'categories' => [
            ['slug' => 'small', 'name' => ['en' => 'Small'], 'sort_order' => 10],
            ['slug' => 'medium', 'name' => ['en' => 'Medium'], 'sort_order' => 20],
            ['slug' => 'big', 'name' => ['en' => 'Big'], 'sort_order' => 30],
            ['slug' => 'nsfw', 'name' => ['en' => 'NSFW'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'type',
                'label' => ['en' => 'Is it fake or real?'],
                'description' => ['en' => 'Are these natural or enhanced?'],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'natural',  'label' => ['en' => 'Natural'], 'sort_order' => 10],
                    ['key' => 'silicone', 'label' => ['en' => 'Silicone'],    'sort_order' => 20],
                ],
            ],
            [
                'key' => 'cup_size',
                'label' => ['en' => 'What cup size is it?'],
                'description' => ['en' => 'Choose the closest cup size.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'aa',   'label' => ['en' => 'AA'], 'sort_order' => 10],
                    ['key' => 'a',    'label' => ['en' => 'A'],  'sort_order' => 20],
                    ['key' => 'b',    'label' => ['en' => 'B'],  'sort_order' => 30],
                    ['key' => 'c',    'label' => ['en' => 'C'],  'sort_order' => 40],
                    ['key' => 'd',    'label' => ['en' => 'D'],  'sort_order' => 50],
                    ['key' => 'dd',   'label' => ['en' => 'DD'], 'sort_order' => 60],
                    ['key' => 'ddd',  'label' => ['en' => 'DDD'], 'sort_order' => 70],
                    ['key' => 'g',    'label' => ['en' => 'G'],  'sort_order' => 80],
                    ['key' => 'h',    'label' => ['en' => 'H'],  'sort_order' => 90],
                    ['key' => 'i',    'label' => ['en' => 'I'],  'sort_order' => 100],
                    ['key' => 'j_plus', 'label' => ['en' => 'J+'], 'sort_order' => 110],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Babes'],
            ['en' => 'Glamour shots'],
            ['en' => 'Silicone'],
            ['en' => 'HD'],
            ['en' => 'Natural'],
            ['en' => 'Celebrities'],
            ['en' => 'Adult'],
            ['en' => 'Topless'],
            ['en' => 'Lingerie'],
            ['en' => 'Bikini'],
            ['en' => 'Cosplay'],
            ['en' => 'Amateur'],
        ],
    ],

];
