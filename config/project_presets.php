<?php

return [

    // -------------------------------------------------------------------------
    // Generic fallback
    // -------------------------------------------------------------------------

    'generic' => [
        'label' => 'Generic rating',
        'settings' => [
            'site_name' => ['en' => 'RateGuru',    'ru' => 'RateGuru',    'bg' => 'RateGuru'],
            'site_tagline' => ['en' => 'Rate anything', 'ru' => 'Оценивай всё', 'bg' => 'Оценявай всичко'],
            'site_description' => ['en' => null,           'ru' => null,           'bg' => null],
            'object_singular_name' => ['en' => 'post',         'ru' => 'пост',         'bg' => 'пост'],
            'object_plural_name' => ['en' => 'posts',        'ru' => 'посты',        'bg' => 'постове'],
            'upload_cta_label' => ['en' => 'Upload post',  'ru' => 'Добавить пост', 'bg' => 'Добави пост'],
            'feed_title' => ['en' => 'Latest posts', 'ru' => 'Последние посты', 'bg' => 'Последни постове'],
            'default_locale' => 'en',
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
            ['slug' => 'general', 'name' => ['en' => 'General', 'ru' => 'Общее', 'bg' => 'Общи'], 'sort_order' => 10],
            ['slug' => 'showcase', 'name' => ['en' => 'Showcase', 'ru' => 'Витрина', 'bg' => 'Витрина'], 'sort_order' => 20],
            ['slug' => 'other', 'name' => ['en' => 'Other', 'ru' => 'Другое', 'bg' => 'Друго'], 'sort_order' => 30],
        ],
        'rating_groups' => [
            [
                'key' => 'type',
                'label' => ['en' => 'Type', 'ru' => 'Тип', 'bg' => 'Тип'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'type_a', 'label' => ['en' => 'Type A', 'ru' => 'Тип A', 'bg' => 'Тип A'], 'sort_order' => 10],
                    ['key' => 'type_b', 'label' => ['en' => 'Type B', 'ru' => 'Тип B', 'bg' => 'Тип B'], 'sort_order' => 20],
                ],
            ],
            [
                'key' => 'attribute',
                'label' => ['en' => 'Attribute', 'ru' => 'Признак', 'bg' => 'Признак'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'attribute_a', 'label' => ['en' => 'Attribute A', 'ru' => 'Признак A', 'bg' => 'Признак A'], 'sort_order' => 10],
                    ['key' => 'attribute_b', 'label' => ['en' => 'Attribute B', 'ru' => 'Признак B', 'bg' => 'Признак B'], 'sort_order' => 20],
                    ['key' => 'attribute_c', 'label' => ['en' => 'Attribute C', 'ru' => 'Признак C', 'bg' => 'Признак C'], 'sort_order' => 30],
                ],
            ],
        ],
        'tags' => null,
    ],

    // -------------------------------------------------------------------------
    // Nature / Travel Photography (all locales)
    // -------------------------------------------------------------------------

    'nature' => [
        'label' => 'Nature & travel photography',
        'settings' => [
            'site_name' => ['en' => 'NatureGuru',              'ru' => 'НейчерГуру',             'bg' => 'НейчърГуру'],
            'site_tagline' => ['en' => 'Rate stunning nature photos', 'ru' => 'Оценивай природные фото', 'bg' => 'Оценявай природни снимки'],
            'site_description' => [
                'en' => 'Community-powered ratings for nature and travel photography.',
                'ru' => 'Народные оценки фотографий природы и путешествий.',
                'bg' => 'Общностни оценки на природна и пътническа фотография.',
            ],
            'object_singular_name' => ['en' => 'photo',         'ru' => 'фото',          'bg' => 'снимка'],
            'object_plural_name' => ['en' => 'photos',        'ru' => 'фото',          'bg' => 'снимки'],
            'upload_cta_label' => ['en' => 'Upload photo',  'ru' => 'Добавить фото', 'bg' => 'Добави снимка'],
            'feed_title' => ['en' => 'Latest photos', 'ru' => 'Последние фото', 'bg' => 'Последни снимки'],
            'default_locale' => 'en',
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
            ['slug' => 'landscape', 'name' => ['en' => 'Landscape', 'ru' => 'Пейзаж', 'bg' => 'Пейзаж'], 'sort_order' => 10],
            ['slug' => 'wildlife', 'name' => ['en' => 'Wildlife', 'ru' => 'Дикая природа', 'bg' => 'Дива природа'], 'sort_order' => 20],
            ['slug' => 'macro', 'name' => ['en' => 'Macro', 'ru' => 'Макро', 'bg' => 'Макро'], 'sort_order' => 30],
            ['slug' => 'urban', 'name' => ['en' => 'Urban', 'ru' => 'Город', 'bg' => 'Град'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'photographer_type',
                'label' => ['en' => 'How was this photo taken?',     'ru' => 'Как сделано фото?',          'bg' => 'Как е направена снимката?'],
                'description' => ['en' => 'Was it shot professionally or by an amateur?', 'ru' => 'Профессионально или любительски?', 'bg' => 'Професионално или аматьорски?'],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'professional', 'label' => ['en' => 'Professional', 'ru' => 'Профессионально', 'bg' => 'Професионално'], 'sort_order' => 10],
                    ['key' => 'amateur',      'label' => ['en' => 'Amateur',      'ru' => 'Любительски',     'bg' => 'Аматьорски'],    'sort_order' => 20],
                ],
            ],
            [
                'key' => 'shot_type',
                'label' => ['en' => 'What type of shot is this?',    'ru' => 'Тип снимка?',               'bg' => 'Какъв вид снимка е това?'],
                'description' => ['en' => 'Choose the technique that best describes the shot.', 'ru' => 'Выберите технику, которая лучше всего описывает снимок.', 'bg' => 'Изберете техниката, която най-добре описва снимката.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'wide', 'label' => ['en' => 'Wide shot', 'ru' => 'Общий план', 'bg' => 'Общ план'], 'sort_order' => 10],
                    ['key' => 'close_up', 'label' => ['en' => 'Close-up', 'ru' => 'Крупный план', 'bg' => 'Близък план'], 'sort_order' => 20],
                    ['key' => 'aerial', 'label' => ['en' => 'Aerial', 'ru' => 'С воздуха', 'bg' => 'Въздушен'], 'sort_order' => 30],
                    ['key' => 'long_exposure', 'label' => ['en' => 'Long exposure', 'ru' => 'Длинная выдержка', 'bg' => 'Дълга експозиция'], 'sort_order' => 40],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Sunrise',       'ru' => 'Рассвет',      'bg' => 'Изгрев'],
            ['en' => 'Sunset',        'ru' => 'Закат',        'bg' => 'Залез'],
            ['en' => 'Golden hour',   'ru' => 'Золотой час',  'bg' => 'Златен час'],
            ['en' => 'Long exposure', 'ru' => 'Длинная выдержка', 'bg' => 'Дълга експозиция'],
            ['en' => 'Milky way',     'ru' => 'Млечный путь', 'bg' => 'Млечен път'],
            ['en' => 'Waterfall',     'ru' => 'Водопад',      'bg' => 'Водопад'],
            ['en' => 'Mountains',     'ru' => 'Горы',         'bg' => 'Планини'],
            ['en' => 'Beach',         'ru' => 'Пляж',         'bg' => 'Плаж'],
            ['en' => 'Forest',        'ru' => 'Лес',          'bg' => 'Гора'],
            ['en' => 'Desert',        'ru' => 'Пустыня',      'bg' => 'Пустиня'],
            ['en' => 'Snow',          'ru' => 'Снег',         'bg' => 'Сняг'],
            ['en' => 'Birds',         'ru' => 'Птицы',        'bg' => 'Птици'],
            ['en' => 'Flowers',       'ru' => 'Цветы',        'bg' => 'Цветя'],
            ['en' => 'Fog',           'ru' => 'Туман',        'bg' => 'Мъгла'],
            ['en' => 'Storm',         'ru' => 'Шторм',        'bg' => 'Буря'],
            ['en' => 'Rainbow',       'ru' => 'Радуга',       'bg' => 'Дъга'],
            ['en' => 'Reflection',    'ru' => 'Отражение',    'bg' => 'Отражение'],
            ['en' => 'Night sky',     'ru' => 'Ночное небо',  'bg' => 'Нощно небе'],
            ['en' => 'Tropical',      'ru' => 'Тропики',      'bg' => 'Тропически'],
            ['en' => 'Arctic',        'ru' => 'Арктика',      'bg' => 'Арктически'],
        ],
    ],

    // -------------------------------------------------------------------------
    // AI image rating (all locales)
    // -------------------------------------------------------------------------

    'ai_images' => [
        'label' => 'AI image rating',
        'settings' => [
            'site_name' => ['en' => 'AIGuru',                   'ru' => 'АйГуру',                     'bg' => 'АйГуру'],
            'site_tagline' => ['en' => 'Rate AI-generated images', 'ru' => 'Оценивай изображения от ИИ', 'bg' => 'Оценявай изображения от ИИ'],
            'site_description' => [
                'en' => 'Community ratings for AI-generated artwork.',
                'ru' => 'Народные оценки изображений, созданных ИИ.',
                'bg' => 'Общностни оценки на изображения, създадени от ИИ.',
            ],
            'object_singular_name' => ['en' => 'image',         'ru' => 'изображение',      'bg' => 'изображение'],
            'object_plural_name' => ['en' => 'images',        'ru' => 'изображения',      'bg' => 'изображения'],
            'upload_cta_label' => ['en' => 'Upload image',  'ru' => 'Добавить изображение', 'bg' => 'Добави изображение'],
            'feed_title' => ['en' => 'Latest images', 'ru' => 'Последние изображения', 'bg' => 'Последни изображения'],
            'default_locale' => 'en',
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
            ['slug' => 'portrait', 'name' => ['en' => 'Portrait', 'ru' => 'Портрет', 'bg' => 'Портрет'], 'sort_order' => 10],
            ['slug' => 'landscape', 'name' => ['en' => 'Landscape', 'ru' => 'Пейзаж', 'bg' => 'Пейзаж'], 'sort_order' => 20],
            ['slug' => 'character', 'name' => ['en' => 'Character', 'ru' => 'Персонаж', 'bg' => 'Персонаж'], 'sort_order' => 30],
            ['slug' => 'architecture', 'name' => ['en' => 'Architecture', 'ru' => 'Архитектура', 'bg' => 'Архитектура'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'model',
                'label' => ['en' => 'Which AI model generated this?', 'ru' => 'Какая модель ИИ создала это?', 'bg' => 'Кой ИИ модел го е създал?'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'midjourney', 'label' => ['en' => 'Midjourney',      'ru' => 'Midjourney',      'bg' => 'Midjourney'],      'sort_order' => 10],
                    ['key' => 'dalle',      'label' => ['en' => 'DALL·E',          'ru' => 'DALL·E',          'bg' => 'DALL·E'],          'sort_order' => 20],
                    ['key' => 'stable',     'label' => ['en' => 'Stable Diff.',    'ru' => 'Stable Diff.',    'bg' => 'Stable Diff.'],    'sort_order' => 30],
                    ['key' => 'other',      'label' => ['en' => 'Other / Unknown', 'ru' => 'Другое / Неизвестно', 'bg' => 'Друго / Неизвестно'], 'sort_order' => 40],
                ],
            ],
            [
                'key' => 'style',
                'label' => ['en' => 'What style is this image?', 'ru' => 'Стиль изображения?', 'bg' => 'Какъв стил е това изображение?'],
                'description' => ['en' => 'Choose the visual style.', 'ru' => 'Выберите визуальный стиль.', 'bg' => 'Изберете визуалния стил.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'photorealistic', 'label' => ['en' => 'Photorealistic', 'ru' => 'Фотореалистичное', 'bg' => 'Фотореалистично'], 'sort_order' => 10],
                    ['key' => 'illustration',   'label' => ['en' => 'Illustration',   'ru' => 'Иллюстрация',     'bg' => 'Илюстрация'],     'sort_order' => 20],
                    ['key' => 'concept_art',    'label' => ['en' => 'Concept art',    'ru' => 'Концепт-арт',     'bg' => 'Концепт арт'],    'sort_order' => 30],
                    ['key' => 'pixel_art',      'label' => ['en' => 'Pixel art',      'ru' => 'Пиксель-арт',     'bg' => 'Пиксел арт'],     'sort_order' => 40],
                    ['key' => 'abstract',       'label' => ['en' => 'Abstract',       'ru' => 'Абстракция',      'bg' => 'Абстрактно'],     'sort_order' => 50],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Fantasy',      'ru' => 'Фэнтези',      'bg' => 'Фентъзи'],
            ['en' => 'Sci-fi',       'ru' => 'Фантастика',   'bg' => 'Фантастика'],
            ['en' => 'Anime',        'ru' => 'Аниме',        'bg' => 'Аниме'],
            ['en' => 'Dark',         'ru' => 'Тёмное',       'bg' => 'Тъмно'],
            ['en' => 'Colorful',     'ru' => 'Красочное',    'bg' => 'Цветно'],
            ['en' => 'Minimalist',   'ru' => 'Минимализм',   'bg' => 'Минималистично'],
            ['en' => 'Surreal',      'ru' => 'Сюрреализм',   'bg' => 'Сюреалистично'],
            ['en' => 'Nature',       'ru' => 'Природа',      'bg' => 'Природа'],
            ['en' => 'Space',        'ru' => 'Космос',       'bg' => 'Космос'],
            ['en' => 'Cyberpunk',    'ru' => 'Киберпанк',    'bg' => 'Киберпънк'],
            ['en' => 'Steampunk',    'ru' => 'Стимпанк',     'bg' => 'Стийм пънк'],
        ],
    ],

    // -------------------------------------------------------------------------
    // Breast rating (all locales)
    // -------------------------------------------------------------------------

    'breasts' => [
        'label' => 'Breast rating',
        'settings' => [
            'site_name' => ['en' => 'BreastGuru',              'ru' => 'BreastGuru',                   'bg' => 'BreastGuru'],
            'site_tagline' => ['en' => 'Rate every pair',         'ru' => 'Оценивай каждую пару',         'bg' => 'Оценявай всяка двойка'],
            'site_description' => [
                'en' => 'Community-powered breast ratings.',
                'ru' => 'Народные оценки женской груди.',
                'bg' => 'Общностни оценки на женски гърди.',
            ],
            'object_singular_name' => ['en' => 'photo',        'ru' => 'фото',         'bg' => 'снимка'],
            'object_plural_name' => ['en' => 'photos',       'ru' => 'фото',         'bg' => 'снимки'],
            'upload_cta_label' => ['en' => 'Upload photo', 'ru' => 'Добавить фото', 'bg' => 'Добави снимка'],
            'feed_title' => ['en' => 'Latest photos', 'ru' => 'Последние фото', 'bg' => 'Последни снимки'],
            'default_locale' => 'en',
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
            ['slug' => 'small', 'name' => ['en' => 'Small', 'ru' => 'Маленькая', 'bg' => 'Малка'], 'sort_order' => 10],
            ['slug' => 'medium', 'name' => ['en' => 'Medium', 'ru' => 'Средняя', 'bg' => 'Средна'], 'sort_order' => 20],
            ['slug' => 'big', 'name' => ['en' => 'Big', 'ru' => 'Большая', 'bg' => 'Голяма'], 'sort_order' => 30],
            ['slug' => 'nsfw', 'name' => ['en' => 'NSFW', 'ru' => 'NSFW', 'bg' => 'NSFW'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'type',
                'label' => ['en' => 'Is it fake or real?',          'ru' => 'Натуральная или силиконовая?', 'bg' => 'Естествена или силиконова?'],
                'description' => ['en' => 'Are these natural or enhanced?', 'ru' => 'Натуральная или увеличенная?', 'bg' => 'Естествена или уголемена?'],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'natural',  'label' => ['en' => 'Natural',  'ru' => 'Натуральная', 'bg' => 'Естествена'], 'sort_order' => 10],
                    ['key' => 'silicone', 'label' => ['en' => 'Silicone', 'ru' => 'Силикон',     'bg' => 'Силикон'],    'sort_order' => 20],
                ],
            ],
            [
                'key' => 'cup_size',
                'label' => ['en' => 'What cup size is it?',         'ru' => 'Какой размер чашки?',          'bg' => 'Какъв е размерът на чашката?'],
                'description' => ['en' => 'Choose the closest cup size.', 'ru' => 'Выберите ближайший размер чашки.', 'bg' => 'Изберете най-близкия размер на чашката.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'aa',   'label' => ['en' => 'AA', 'ru' => 'AA', 'bg' => 'AA'], 'sort_order' => 10],
                    ['key' => 'a',    'label' => ['en' => 'A',  'ru' => 'A',  'bg' => 'A'],  'sort_order' => 20],
                    ['key' => 'b',    'label' => ['en' => 'B',  'ru' => 'B',  'bg' => 'B'],  'sort_order' => 30],
                    ['key' => 'c',    'label' => ['en' => 'C',  'ru' => 'C',  'bg' => 'C'],  'sort_order' => 40],
                    ['key' => 'd',    'label' => ['en' => 'D',  'ru' => 'D',  'bg' => 'D'],  'sort_order' => 50],
                    ['key' => 'dd',   'label' => ['en' => 'DD', 'ru' => 'DD', 'bg' => 'DD'], 'sort_order' => 60],
                    ['key' => 'ddd',  'label' => ['en' => 'DDD', 'ru' => 'DDD', 'bg' => 'DDD'], 'sort_order' => 70],
                    ['key' => 'g',    'label' => ['en' => 'G',  'ru' => 'G',  'bg' => 'G'],  'sort_order' => 80],
                    ['key' => 'h',    'label' => ['en' => 'H',  'ru' => 'H',  'bg' => 'H'],  'sort_order' => 90],
                    ['key' => 'i',    'label' => ['en' => 'I',  'ru' => 'I',  'bg' => 'I'],  'sort_order' => 100],
                    ['key' => 'j_plus', 'label' => ['en' => 'J+', 'ru' => 'J+', 'bg' => 'J+'], 'sort_order' => 110],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Babes',          'ru' => 'Бейбс',           'bg' => 'Бейбс'],
            ['en' => 'Glamour shots',  'ru' => 'Гламурные фото',   'bg' => 'Гламурни снимки'],
            ['en' => 'Silicone',       'ru' => 'Силикон',          'bg' => 'Силикон'],
            ['en' => 'HD',             'ru' => 'HD',               'bg' => 'HD'],
            ['en' => 'Natural',        'ru' => 'Натуральная',      'bg' => 'Естествена'],
            ['en' => 'Celebrities',    'ru' => 'Знаменитости',     'bg' => 'Знаменитости'],
            ['en' => 'Adult',          'ru' => 'Взрослые',         'bg' => 'За възрастни'],
            ['en' => 'Topless',        'ru' => 'Топлес',           'bg' => 'Топлес'],
            ['en' => 'Lingerie',       'ru' => 'Нижнее бельё',     'bg' => 'Бельо'],
            ['en' => 'Bikini',         'ru' => 'Бикини',           'bg' => 'Бикини'],
            ['en' => 'Cosplay',        'ru' => 'Косплей',          'bg' => 'Косплей'],
            ['en' => 'Amateur',        'ru' => 'Любительское',     'bg' => 'Аматьорско'],
        ],
    ],

];
