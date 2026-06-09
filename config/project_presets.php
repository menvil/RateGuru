<?php

return [

    // -------------------------------------------------------------------------
    // Generic
    // -------------------------------------------------------------------------

    'generic' => [
        'label' => 'Generic rating',
        'settings' => [
            'site_name'            => 'RateGuru',
            'site_tagline'         => 'Rate anything',
            'site_description'     => null,
            'object_singular_name' => 'post',
            'object_plural_name'   => 'posts',
            'upload_cta_label'     => 'Upload post',
            'feed_title'           => 'Latest posts',
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => false,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => null, // keep existing
        'tags'          => null, // keep existing
    ],

    // -------------------------------------------------------------------------
    // Food — General (EN)
    // -------------------------------------------------------------------------

    'food' => [
        'label' => 'Food rating (general)',
        'settings' => [
            'site_name'            => 'FoodGuru',
            'site_tagline'         => 'Rate every dish',
            'site_description'     => 'Community-powered food and dish ratings.',
            'object_singular_name' => 'dish',
            'object_plural_name'   => 'dishes',
            'upload_cta_label'     => 'Upload dish',
            'feed_title'           => 'Latest dishes',
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'Where was this dish made?',
                'description' => 'Was it prepared at a restaurant or at home?',
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'restaurant', 'label' => 'Restaurant', 'sort_order' => 10],
                    ['key' => 'homemade',   'label' => 'Homemade',   'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'What type of cuisine is this?',
                'description' => 'Choose the cuisine that best describes this dish.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'italian',   'label' => 'Italian',   'sort_order' => 10],
                    ['key' => 'asian',     'label' => 'Asian',     'sort_order' => 20],
                    ['key' => 'american',  'label' => 'American',  'sort_order' => 30],
                    ['key' => 'mexican',   'label' => 'Mexican',   'sort_order' => 40],
                    ['key' => 'french',    'label' => 'French',    'sort_order' => 50],
                    ['key' => 'other',     'label' => 'Other',     'sort_order' => 60],
                ],
            ],
        ],
        'tags' => [
            'Pizza', 'Pasta', 'Sushi', 'Burger', 'Tacos', 'Salad', 'Soup',
            'Steak', 'Seafood', 'Dessert', 'Breakfast', 'Vegan', 'Grilled',
            'Fried', 'Baked', 'Street food', 'Fine dining', 'Comfort food',
        ],
    ],

    // -------------------------------------------------------------------------
    // Food — Bulgarian (BG/EN)
    // -------------------------------------------------------------------------

    'food_bg' => [
        'label' => 'Food rating (Bulgarian)',
        'settings' => [
            'site_name'            => 'ВкусноБГ',
            'site_tagline'         => 'Оцени всяко ястие',
            'site_description'     => 'Общностни оценки на български и световни ястия.',
            'object_singular_name' => 'ястие',
            'object_plural_name'   => 'ястия',
            'upload_cta_label'     => 'Добави ястие',
            'feed_title'           => 'Последни ястия',
            'default_locale'       => 'bg',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'Откъде е ястието?',
                'description' => 'Приготвено в ресторант или у дома?',
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'restaurant', 'label' => 'Ресторант', 'sort_order' => 10],
                    ['key' => 'homemade',   'label' => 'Домашно',   'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'Какъв вид ястие е това?',
                'description' => 'Изберете категорията, която най-добре описва ястието.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'traditional', 'label' => 'Традиционно', 'sort_order' => 10],
                    ['key' => 'grilled',     'label' => 'Грил',        'sort_order' => 20],
                    ['key' => 'soup',        'label' => 'Супа',        'sort_order' => 30],
                    ['key' => 'salad',       'label' => 'Салата',      'sort_order' => 40],
                    ['key' => 'baked',       'label' => 'Печено',      'sort_order' => 50],
                    ['key' => 'seafood',     'label' => 'Морска храна','sort_order' => 60],
                    ['key' => 'dessert',     'label' => 'Десерт',      'sort_order' => 70],
                ],
            ],
        ],
        'tags' => [
            'Кебапчета', 'Баница', 'Таратор', 'Мусака', 'Шопска салата',
            'Боб', 'Козунак', 'Гювеч', 'Сарми', 'Каварма', 'Айран',
            'Катък', 'Лютеница', 'Бяла наденица', 'Шкембе чорба',
            'Трилейк', 'Мекица', 'Тиквеник',
        ],
    ],

    // -------------------------------------------------------------------------
    // Food — Italian (EN)
    // -------------------------------------------------------------------------

    'food_it' => [
        'label' => 'Food rating (Italian)',
        'settings' => [
            'site_name'            => 'GustoIT',
            'site_tagline'         => 'Rate authentic Italian food',
            'site_description'     => 'Community ratings for Italian dishes and restaurants.',
            'object_singular_name' => 'piatto',
            'object_plural_name'   => 'piatti',
            'upload_cta_label'     => 'Add piatto',
            'feed_title'           => 'Latest piatti',
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'Where was this dish prepared?',
                'description' => 'Ristorante or home kitchen?',
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'ristorante', 'label' => 'Ristorante', 'sort_order' => 10],
                    ['key' => 'homemade',   'label' => 'Fatto in casa', 'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'What type of Italian dish is this?',
                'description' => 'Choose the course or category.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'pasta',    'label' => 'Pasta',    'sort_order' => 10],
                    ['key' => 'pizza',    'label' => 'Pizza',    'sort_order' => 20],
                    ['key' => 'risotto',  'label' => 'Risotto',  'sort_order' => 30],
                    ['key' => 'meat',     'label' => 'Carne',    'sort_order' => 40],
                    ['key' => 'seafood',  'label' => 'Pesce',    'sort_order' => 50],
                    ['key' => 'antipasto','label' => 'Antipasto','sort_order' => 60],
                    ['key' => 'dolce',    'label' => 'Dolce',    'sort_order' => 70],
                ],
            ],
        ],
        'tags' => [
            'Carbonara', 'Margherita', 'Quattro stagioni', 'Bolognese', 'Amatriciana',
            'Cacio e pepe', 'Risotto ai funghi', 'Ossobuco', 'Tiramisu', 'Gelato',
            'Panna cotta', 'Bruschetta', 'Focaccia', 'Prosciutto', 'Truffle',
            'Arancini', 'Cannoli', 'Sfogliatella',
        ],
    ],

    // -------------------------------------------------------------------------
    // Food — Russian / Eastern European (EN)
    // -------------------------------------------------------------------------

    'food_ru' => [
        'label' => 'Food rating (Russian / Eastern European)',
        'settings' => [
            'site_name'            => 'ВкусноРУ',
            'site_tagline'         => 'Rate Eastern European food',
            'site_description'     => 'Community ratings for Russian and Eastern European dishes.',
            'object_singular_name' => 'dish',
            'object_plural_name'   => 'dishes',
            'upload_cta_label'     => 'Add dish',
            'feed_title'           => 'Latest dishes',
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'Where was this dish made?',
                'description' => 'Restaurant or home cooking?',
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'restaurant', 'label' => 'Restaurant', 'sort_order' => 10],
                    ['key' => 'homemade',   'label' => 'Homemade',   'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'What type of dish is this?',
                'description' => 'Choose the category that best fits.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'soups',     'label' => 'Soups',     'sort_order' => 10],
                    ['key' => 'meat',      'label' => 'Meat',      'sort_order' => 20],
                    ['key' => 'salads',    'label' => 'Salads',    'sort_order' => 30],
                    ['key' => 'baked',     'label' => 'Baked',     'sort_order' => 40],
                    ['key' => 'dumplings', 'label' => 'Dumplings', 'sort_order' => 50],
                    ['key' => 'drinks',    'label' => 'Drinks',    'sort_order' => 60],
                    ['key' => 'dessert',   'label' => 'Dessert',   'sort_order' => 70],
                ],
            ],
        ],
        'tags' => [
            'Borscht', 'Pelmeni', 'Bliny', 'Olivier salad', 'Shashlik',
            'Syrniki', 'Okroshka', 'Solyanka', 'Pirozhki', 'Vareniki',
            'Beef stroganoff', 'Kotlety', 'Kasha', 'Kvass', 'Medovik',
            'Napoleon cake', 'Holodets', 'Ukha',
        ],
    ],

    // -------------------------------------------------------------------------
    // Nature / Travel Photography
    // -------------------------------------------------------------------------

    'nature' => [
        'label' => 'Nature & travel photography',
        'settings' => [
            'site_name'            => 'NatureGuru',
            'site_tagline'         => 'Rate stunning nature photos',
            'site_description'     => 'Community-powered ratings for nature and travel photography.',
            'object_singular_name' => 'photo',
            'object_plural_name'   => 'photos',
            'upload_cta_label'     => 'Upload photo',
            'feed_title'           => 'Latest photos',
            'default_locale'       => 'en',
            'default_theme'        => 'dark',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'How was this photo taken?',
                'description' => 'Was it shot professionally or by an amateur?',
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'professional', 'label' => 'Professional', 'sort_order' => 10],
                    ['key' => 'amateur',      'label' => 'Amateur',      'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'What type of shot is this?',
                'description' => 'Choose the category that best describes the scene.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'landscape',  'label' => 'Landscape',  'sort_order' => 10],
                    ['key' => 'wildlife',   'label' => 'Wildlife',   'sort_order' => 20],
                    ['key' => 'seascape',   'label' => 'Seascape',   'sort_order' => 30],
                    ['key' => 'mountains',  'label' => 'Mountains',  'sort_order' => 40],
                    ['key' => 'forest',     'label' => 'Forest',     'sort_order' => 50],
                    ['key' => 'urban',      'label' => 'Urban',      'sort_order' => 60],
                    ['key' => 'aerial',     'label' => 'Aerial',     'sort_order' => 70],
                    ['key' => 'macro',      'label' => 'Macro',      'sort_order' => 80],
                ],
            ],
        ],
        'tags' => [
            'Sunrise', 'Sunset', 'Golden hour', 'Long exposure', 'Milky way',
            'Waterfall', 'Mountains', 'Beach', 'Forest', 'Desert', 'Snow',
            'Birds', 'Flowers', 'Fog', 'Storm', 'Rainbow', 'Reflection',
            'Night sky', 'Tropical', 'Arctic',
        ],
    ],

    // -------------------------------------------------------------------------
    // Cat photos (kept for compatibility)
    // -------------------------------------------------------------------------

    'cats' => [
        'label' => 'Cat photos rating',
        'settings' => [
            'site_name'            => 'CatGuru',
            'site_tagline'         => 'Rate every cat',
            'site_description'     => 'Community-powered cat photo ratings.',
            'object_singular_name' => 'cat',
            'object_plural_name'   => 'cats',
            'upload_cta_label'     => 'Upload cat',
            'feed_title'           => 'Latest cats',
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => false,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'Is this cat indoors or outdoors?',
                'description' => null,
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'indoor',  'label' => 'Indoor cat',  'sort_order' => 10],
                    ['key' => 'outdoor', 'label' => 'Outdoor cat', 'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'What breed is this cat?',
                'description' => 'Choose the closest breed or type.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'persian',   'label' => 'Persian',     'sort_order' => 10],
                    ['key' => 'siamese',   'label' => 'Siamese',     'sort_order' => 20],
                    ['key' => 'british',   'label' => 'British SH',  'sort_order' => 30],
                    ['key' => 'maine',     'label' => 'Maine Coon',  'sort_order' => 40],
                    ['key' => 'bengal',    'label' => 'Bengal',      'sort_order' => 50],
                    ['key' => 'mixed',     'label' => 'Mixed',       'sort_order' => 60],
                ],
            ],
        ],
        'tags' => [
            'Fluffy', 'Tiny', 'Grumpy', 'Sleepy', 'Playful', 'Kitten',
            'Orange', 'Black', 'White', 'Tabby', 'Calico', 'Tuxedo',
            'Chonky', 'Derpy',
        ],
    ],

    // -------------------------------------------------------------------------
    // AI image rating (kept for compatibility)
    // -------------------------------------------------------------------------

    'ai_images' => [
        'label' => 'AI image rating',
        'settings' => [
            'site_name'            => 'AIGuru',
            'site_tagline'         => 'Rate AI-generated images',
            'site_description'     => 'Community ratings for AI-generated artwork.',
            'object_singular_name' => 'image',
            'object_plural_name'   => 'images',
            'upload_cta_label'     => 'Upload image',
            'feed_title'           => 'Latest images',
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => false,
            'show_saved_posts'    => false,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => 'Which AI model generated this?',
                'description' => null,
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'midjourney',  'label' => 'Midjourney',   'sort_order' => 10],
                    ['key' => 'dalle',       'label' => 'DALL·E',       'sort_order' => 20],
                    ['key' => 'stable',      'label' => 'Stable Diff.', 'sort_order' => 30],
                    ['key' => 'other',       'label' => 'Other / Unknown', 'sort_order' => 40],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => 'What style is this image?',
                'description' => 'Choose the visual style.',
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'photorealistic', 'label' => 'Photorealistic', 'sort_order' => 10],
                    ['key' => 'illustration',   'label' => 'Illustration',   'sort_order' => 20],
                    ['key' => 'concept_art',    'label' => 'Concept art',    'sort_order' => 30],
                    ['key' => 'pixel_art',      'label' => 'Pixel art',      'sort_order' => 40],
                    ['key' => 'abstract',       'label' => 'Abstract',       'sort_order' => 50],
                ],
            ],
        ],
        'tags' => [
            'Portrait', 'Landscape', 'Fantasy', 'Sci-fi', 'Anime', 'Dark',
            'Colorful', 'Minimalist', 'Surreal', 'Architecture', 'Character',
            'Nature', 'Space', 'Cyberpunk', 'Steampunk',
        ],
    ],

];
