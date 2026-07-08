<?php

return [

    // -------------------------------------------------------------------------
    // Generic fallback
    // -------------------------------------------------------------------------

    'generic' => [
        'label' => 'Generic rating',
        'settings' => [
            'site_name'            => ['en' => 'RateGuru',    'ru' => 'RateGuru',    'bg' => 'RateGuru'],
            'site_tagline'         => ['en' => 'Rate anything', 'ru' => 'Оценивай всё', 'bg' => 'Оценявай всичко'],
            'site_description'     => ['en' => null,           'ru' => null,           'bg' => null],
            'object_singular_name' => ['en' => 'post',         'ru' => 'пост',         'bg' => 'пост'],
            'object_plural_name'   => ['en' => 'posts',        'ru' => 'посты',        'bg' => 'постове'],
            'upload_cta_label'     => ['en' => 'Upload post',  'ru' => 'Добавить пост','bg' => 'Добави пост'],
            'feed_title'           => ['en' => 'Latest posts', 'ru' => 'Последние посты', 'bg' => 'Последни постове'],
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts'    => false,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => null,
        'tags'          => null,
    ],

    // -------------------------------------------------------------------------
    // Food (all locales)
    // -------------------------------------------------------------------------

    'food' => [
        'label' => 'Food rating',
        'settings' => [
            'site_name'            => ['en' => 'FoodGuru',         'ru' => 'ФудГуру',              'bg' => 'ФудГуру'],
            'site_tagline'         => ['en' => 'Rate every dish',  'ru' => 'Оценивай каждое блюдо','bg' => 'Оценявай всяко ястие'],
            'site_description'     => [
                'en' => 'Community-powered food and dish ratings.',
                'ru' => 'Народные оценки блюд и еды.',
                'bg' => 'Общностни оценки на ястия и храна.',
            ],
            'object_singular_name' => ['en' => 'dish',         'ru' => 'блюдо',        'bg' => 'ястие'],
            'object_plural_name'   => ['en' => 'dishes',       'ru' => 'блюда',        'bg' => 'ястия'],
            'upload_cta_label'     => ['en' => 'Upload dish',  'ru' => 'Добавить блюдо','bg' => 'Добави ястие'],
            'feed_title'           => ['en' => 'Latest dishes', 'ru' => 'Последние блюда', 'bg' => 'Последни ястия'],
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => ['en' => 'Where was this dish made?',         'ru' => 'Где приготовлено блюдо?',           'bg' => 'Откъде е ястието?'],
                'description' => ['en' => 'Was it prepared at a restaurant or at home?', 'ru' => 'В ресторане или дома?', 'bg' => 'Приготвено в ресторант или у дома?'],
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'restaurant', 'label' => ['en' => 'Restaurant', 'ru' => 'Ресторан', 'bg' => 'Ресторант'], 'sort_order' => 10],
                    ['key' => 'homemade',   'label' => ['en' => 'Homemade',   'ru' => 'Домашнее', 'bg' => 'Домашно'],   'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => ['en' => 'What type of cuisine is this?',       'ru' => 'Какой тип кухни?',                 'bg' => 'Какъв вид кухня е това?'],
                'description' => ['en' => 'Choose the cuisine that best describes this dish.', 'ru' => 'Выберите кухню, которая лучше всего описывает блюдо.', 'bg' => 'Изберете кухнята, която най-добре описва ястието.'],
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'italian',  'label' => ['en' => 'Italian',  'ru' => 'Итальянская', 'bg' => 'Италианска'], 'sort_order' => 10],
                    ['key' => 'asian',    'label' => ['en' => 'Asian',    'ru' => 'Азиатская',   'bg' => 'Азиатска'],   'sort_order' => 20],
                    ['key' => 'american', 'label' => ['en' => 'American', 'ru' => 'Американская','bg' => 'Американска'],'sort_order' => 30],
                    ['key' => 'mexican',  'label' => ['en' => 'Mexican',  'ru' => 'Мексиканская','bg' => 'Мексиканска'],'sort_order' => 40],
                    ['key' => 'french',   'label' => ['en' => 'French',   'ru' => 'Французская', 'bg' => 'Френска'],    'sort_order' => 50],
                    ['key' => 'other',    'label' => ['en' => 'Other',    'ru' => 'Другое',      'bg' => 'Друго'],      'sort_order' => 60],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Pizza',        'ru' => 'Пицца',        'bg' => 'Пица'],
            ['en' => 'Pasta',        'ru' => 'Паста',        'bg' => 'Паста'],
            ['en' => 'Sushi',        'ru' => 'Суши',         'bg' => 'Суши'],
            ['en' => 'Burger',       'ru' => 'Бургер',       'bg' => 'Бургер'],
            ['en' => 'Tacos',        'ru' => 'Тако',         'bg' => 'Тако'],
            ['en' => 'Salad',        'ru' => 'Салат',        'bg' => 'Салата'],
            ['en' => 'Soup',         'ru' => 'Суп',          'bg' => 'Супа'],
            ['en' => 'Steak',        'ru' => 'Стейк',        'bg' => 'Стек'],
            ['en' => 'Seafood',      'ru' => 'Морепродукты', 'bg' => 'Морска храна'],
            ['en' => 'Dessert',      'ru' => 'Десерт',       'bg' => 'Десерт'],
            ['en' => 'Breakfast',    'ru' => 'Завтрак',      'bg' => 'Закуска'],
            ['en' => 'Vegan',        'ru' => 'Веганское',    'bg' => 'Веганско'],
            ['en' => 'Grilled',      'ru' => 'На гриле',     'bg' => 'На грил'],
            ['en' => 'Fried',        'ru' => 'Жареное',      'bg' => 'Пържено'],
            ['en' => 'Baked',        'ru' => 'Запечённое',   'bg' => 'Печено'],
            ['en' => 'Street food',  'ru' => 'Уличная еда',  'bg' => 'Улична храна'],
            ['en' => 'Fine dining',  'ru' => 'Высокая кухня','bg' => 'Фина кухня'],
            ['en' => 'Comfort food', 'ru' => 'Домашняя еда', 'bg' => 'Домашна храна'],
        ],
    ],

    // -------------------------------------------------------------------------
    // Nature / Travel Photography (all locales)
    // -------------------------------------------------------------------------

    'nature' => [
        'label' => 'Nature & travel photography',
        'settings' => [
            'site_name'            => ['en' => 'NatureGuru',              'ru' => 'НейчерГуру',             'bg' => 'НейчърГуру'],
            'site_tagline'         => ['en' => 'Rate stunning nature photos', 'ru' => 'Оценивай природные фото', 'bg' => 'Оценявай природни снимки'],
            'site_description'     => [
                'en' => 'Community-powered ratings for nature and travel photography.',
                'ru' => 'Народные оценки фотографий природы и путешествий.',
                'bg' => 'Общностни оценки на природна и пътническа фотография.',
            ],
            'object_singular_name' => ['en' => 'photo',         'ru' => 'фото',          'bg' => 'снимка'],
            'object_plural_name'   => ['en' => 'photos',        'ru' => 'фото',          'bg' => 'снимки'],
            'upload_cta_label'     => ['en' => 'Upload photo',  'ru' => 'Добавить фото', 'bg' => 'Добави снимка'],
            'feed_title'           => ['en' => 'Latest photos', 'ru' => 'Последние фото','bg' => 'Последни снимки'],
            'default_locale'       => 'en',
            'default_theme'        => 'dark',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => ['en' => 'How was this photo taken?',     'ru' => 'Как сделано фото?',          'bg' => 'Как е направена снимката?'],
                'description' => ['en' => 'Was it shot professionally or by an amateur?', 'ru' => 'Профессионально или любительски?', 'bg' => 'Професионално или аматьорски?'],
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'professional', 'label' => ['en' => 'Professional', 'ru' => 'Профессионально', 'bg' => 'Професионално'], 'sort_order' => 10],
                    ['key' => 'amateur',      'label' => ['en' => 'Amateur',      'ru' => 'Любительски',     'bg' => 'Аматьорски'],    'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => ['en' => 'What type of shot is this?',    'ru' => 'Тип снимка?',               'bg' => 'Какъв вид снимка е това?'],
                'description' => ['en' => 'Choose the category that best describes the scene.', 'ru' => 'Выберите категорию, которая лучше всего описывает сцену.', 'bg' => 'Изберете категорията, която най-добре описва сцената.'],
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'landscape', 'label' => ['en' => 'Landscape', 'ru' => 'Пейзаж',     'bg' => 'Пейзаж'],    'sort_order' => 10],
                    ['key' => 'wildlife',  'label' => ['en' => 'Wildlife',  'ru' => 'Дикая природа','bg' => 'Дива природа'],'sort_order' => 20],
                    ['key' => 'seascape',  'label' => ['en' => 'Seascape',  'ru' => 'Морской вид', 'bg' => 'Морски пейзаж'],'sort_order' => 30],
                    ['key' => 'mountains', 'label' => ['en' => 'Mountains', 'ru' => 'Горы',        'bg' => 'Планини'],   'sort_order' => 40],
                    ['key' => 'forest',    'label' => ['en' => 'Forest',    'ru' => 'Лес',         'bg' => 'Гора'],      'sort_order' => 50],
                    ['key' => 'urban',     'label' => ['en' => 'Urban',     'ru' => 'Городской',   'bg' => 'Градски'],   'sort_order' => 60],
                    ['key' => 'aerial',    'label' => ['en' => 'Aerial',    'ru' => 'С воздуха',   'bg' => 'Въздушен'],  'sort_order' => 70],
                    ['key' => 'macro',     'label' => ['en' => 'Macro',     'ru' => 'Макро',       'bg' => 'Макро'],     'sort_order' => 80],
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
            'site_name'            => ['en' => 'AIGuru',                   'ru' => 'АйГуру',                     'bg' => 'АйГуру'],
            'site_tagline'         => ['en' => 'Rate AI-generated images', 'ru' => 'Оценивай изображения от ИИ', 'bg' => 'Оценявай изображения от ИИ'],
            'site_description'     => [
                'en' => 'Community ratings for AI-generated artwork.',
                'ru' => 'Народные оценки изображений, созданных ИИ.',
                'bg' => 'Общностни оценки на изображения, създадени от ИИ.',
            ],
            'object_singular_name' => ['en' => 'image',         'ru' => 'изображение',      'bg' => 'изображение'],
            'object_plural_name'   => ['en' => 'images',        'ru' => 'изображения',      'bg' => 'изображения'],
            'upload_cta_label'     => ['en' => 'Upload image',  'ru' => 'Добавить изображение', 'bg' => 'Добави изображение'],
            'feed_title'           => ['en' => 'Latest images', 'ru' => 'Последние изображения','bg' => 'Последни изображения'],
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts'    => false,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => ['en' => 'Which AI model generated this?', 'ru' => 'Какая модель ИИ создала это?', 'bg' => 'Кой ИИ модел го е създал?'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null],
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'midjourney', 'label' => ['en' => 'Midjourney',      'ru' => 'Midjourney',      'bg' => 'Midjourney'],      'sort_order' => 10],
                    ['key' => 'dalle',      'label' => ['en' => 'DALL·E',          'ru' => 'DALL·E',          'bg' => 'DALL·E'],          'sort_order' => 20],
                    ['key' => 'stable',     'label' => ['en' => 'Stable Diff.',    'ru' => 'Stable Diff.',    'bg' => 'Stable Diff.'],    'sort_order' => 30],
                    ['key' => 'other',      'label' => ['en' => 'Other / Unknown', 'ru' => 'Другое / Неизвестно', 'bg' => 'Друго / Неизвестно'], 'sort_order' => 40],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => ['en' => 'What style is this image?', 'ru' => 'Стиль изображения?', 'bg' => 'Какъв стил е това изображение?'],
                'description' => ['en' => 'Choose the visual style.', 'ru' => 'Выберите визуальный стиль.', 'bg' => 'Изберете визуалния стил.'],
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'photorealistic', 'label' => ['en' => 'Photorealistic', 'ru' => 'Фотореалистичное', 'bg' => 'Фотореалистично'], 'sort_order' => 10],
                    ['key' => 'illustration',   'label' => ['en' => 'Illustration',   'ru' => 'Иллюстрация',     'bg' => 'Илюстрация'],     'sort_order' => 20],
                    ['key' => 'concept_art',    'label' => ['en' => 'Concept art',    'ru' => 'Концепт-арт',     'bg' => 'Концепт арт'],    'sort_order' => 30],
                    ['key' => 'pixel_art',      'label' => ['en' => 'Pixel art',      'ru' => 'Пиксель-арт',     'bg' => 'Пиксел арт'],     'sort_order' => 40],
                    ['key' => 'abstract',       'label' => ['en' => 'Abstract',       'ru' => 'Абстракция',      'bg' => 'Абстрактно'],     'sort_order' => 50],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Portrait',     'ru' => 'Портрет',      'bg' => 'Портрет'],
            ['en' => 'Landscape',    'ru' => 'Пейзаж',       'bg' => 'Пейзаж'],
            ['en' => 'Fantasy',      'ru' => 'Фэнтези',      'bg' => 'Фентъзи'],
            ['en' => 'Sci-fi',       'ru' => 'Фантастика',   'bg' => 'Фантастика'],
            ['en' => 'Anime',        'ru' => 'Аниме',        'bg' => 'Аниме'],
            ['en' => 'Dark',         'ru' => 'Тёмное',       'bg' => 'Тъмно'],
            ['en' => 'Colorful',     'ru' => 'Красочное',    'bg' => 'Цветно'],
            ['en' => 'Minimalist',   'ru' => 'Минимализм',   'bg' => 'Минималистично'],
            ['en' => 'Surreal',      'ru' => 'Сюрреализм',   'bg' => 'Сюреалистично'],
            ['en' => 'Architecture', 'ru' => 'Архитектура',  'bg' => 'Архитектура'],
            ['en' => 'Character',    'ru' => 'Персонаж',     'bg' => 'Персонаж'],
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
            'site_name'            => ['en' => 'BreastGuru',              'ru' => 'BreastGuru',                   'bg' => 'BreastGuru'],
            'site_tagline'         => ['en' => 'Rate every pair',         'ru' => 'Оценивай каждую пару',         'bg' => 'Оценявай всяка двойка'],
            'site_description'     => [
                'en' => 'Community-powered breast ratings.',
                'ru' => 'Народные оценки женской груди.',
                'bg' => 'Общностни оценки на женски гърди.',
            ],
            'object_singular_name' => ['en' => 'photo',        'ru' => 'фото',         'bg' => 'снимка'],
            'object_plural_name'   => ['en' => 'photos',       'ru' => 'фото',         'bg' => 'снимки'],
            'upload_cta_label'     => ['en' => 'Upload photo', 'ru' => 'Добавить фото','bg' => 'Добави снимка'],
            'feed_title'           => ['en' => 'Latest photos','ru' => 'Последние фото','bg' => 'Последни снимки'],
            'default_locale'       => 'en',
            'default_theme'        => 'system',
            'default_sort'         => 'hot',
        ],
        'feature_flags' => [
            'show_comments'       => true,
            'show_share_buttons'  => true,
            'show_vote_breakdown' => true,
            'show_follow_buttons' => true,
            'post_detail_overlay_mode' => false,
            'show_saved_posts'    => true,
            'allow_user_uploads'  => true,
            'allow_guest_viewing' => true,
        ],
        'rating_groups' => [
            [
                'key'         => 'source',
                'label'       => ['en' => 'Is it fake or real?',          'ru' => 'Натуральная или силиконовая?', 'bg' => 'Естествена или силиконова?'],
                'description' => ['en' => 'Are these natural or enhanced?','ru' => 'Натуральная или увеличенная?', 'bg' => 'Естествена или уголемена?'],
                'sort_order'  => 10,
                'options'     => [
                    ['key' => 'natural',  'label' => ['en' => 'Natural',  'ru' => 'Натуральная', 'bg' => 'Естествена'], 'sort_order' => 10],
                    ['key' => 'silicone', 'label' => ['en' => 'Silicone', 'ru' => 'Силикон',     'bg' => 'Силикон'],    'sort_order' => 20],
                ],
            ],
            [
                'key'         => 'category',
                'label'       => ['en' => 'What cup size is it?',         'ru' => 'Какой размер чашки?',          'bg' => 'Какъв е размерът на чашката?'],
                'description' => ['en' => 'Choose the closest cup size.', 'ru' => 'Выберите ближайший размер чашки.', 'bg' => 'Изберете най-близкия размер на чашката.'],
                'sort_order'  => 20,
                'options'     => [
                    ['key' => 'aa',   'label' => ['en' => 'AA', 'ru' => 'AA', 'bg' => 'AA'], 'sort_order' => 10],
                    ['key' => 'a',    'label' => ['en' => 'A',  'ru' => 'A',  'bg' => 'A'],  'sort_order' => 20],
                    ['key' => 'b',    'label' => ['en' => 'B',  'ru' => 'B',  'bg' => 'B'],  'sort_order' => 30],
                    ['key' => 'c',    'label' => ['en' => 'C',  'ru' => 'C',  'bg' => 'C'],  'sort_order' => 40],
                    ['key' => 'd',    'label' => ['en' => 'D',  'ru' => 'D',  'bg' => 'D'],  'sort_order' => 50],
                    ['key' => 'dd',   'label' => ['en' => 'DD', 'ru' => 'DD', 'bg' => 'DD'], 'sort_order' => 60],
                    ['key' => 'ddd',  'label' => ['en' => 'DDD','ru' => 'DDD','bg' => 'DDD'],'sort_order' => 70],
                    ['key' => 'g',    'label' => ['en' => 'G',  'ru' => 'G',  'bg' => 'G'],  'sort_order' => 80],
                    ['key' => 'h',    'label' => ['en' => 'H',  'ru' => 'H',  'bg' => 'H'],  'sort_order' => 90],
                    ['key' => 'i',    'label' => ['en' => 'I',  'ru' => 'I',  'bg' => 'I'],  'sort_order' => 100],
                    ['key' => 'j_plus', 'label' => ['en' => 'J+','ru' => 'J+','bg' => 'J+'], 'sort_order' => 110],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Babes',          'ru' => 'Бейбс',           'bg' => 'Бейбс'],
            ['en' => 'Glamour shots',  'ru' => 'Гламурные фото',   'bg' => 'Гламурни снимки'],
            ['en' => 'Silicone',       'ru' => 'Силикон',          'bg' => 'Силикон'],
            ['en' => 'HD',             'ru' => 'HD',               'bg' => 'HD'],
            ['en' => 'Natural',        'ru' => 'Натуральная',      'bg' => 'Естествена'],
            ['en' => 'Small',          'ru' => 'Маленькая',        'bg' => 'Малка'],
            ['en' => 'Big',            'ru' => 'Большая',          'bg' => 'Голяма'],
            ['en' => 'Celebrities',    'ru' => 'Знаменитости',     'bg' => 'Знаменитости'],
            ['en' => 'NSFW',           'ru' => 'NSFW',             'bg' => 'NSFW'],
            ['en' => 'Adult',          'ru' => 'Взрослые',         'bg' => 'За възрастни'],
            ['en' => 'Topless',        'ru' => 'Топлес',           'bg' => 'Топлес'],
            ['en' => 'Lingerie',       'ru' => 'Нижнее бельё',     'bg' => 'Бельо'],
            ['en' => 'Bikini',         'ru' => 'Бикини',           'bg' => 'Бикини'],
            ['en' => 'Cosplay',        'ru' => 'Косплей',          'bg' => 'Косплей'],
            ['en' => 'Amateur',        'ru' => 'Любительское',     'bg' => 'Аматьорско'],
        ],
    ],

];
