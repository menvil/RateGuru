<?php

return [

    // -------------------------------------------------------------------------
    // Generic fallback
    // -------------------------------------------------------------------------

    'generic' => [
        'label' => 'Generic rating',
        'settings' => [
            'site_name' => ['en' => 'RateGuru',    'ru' => 'RateGuru',    'bg' => 'RateGuru', 'de' => 'RateGuru'],
            'site_tagline' => ['en' => 'Rate anything', 'ru' => 'Оценивай всё', 'bg' => 'Оценявай всичко', 'de' => 'Bewerte alles'],
            'site_description' => ['en' => null,           'ru' => null,           'bg' => null, 'de' => null],
            'object_singular_name' => ['en' => 'post',         'ru' => 'пост',         'bg' => 'пост', 'de' => 'Beitrag'],
            'object_plural_name' => ['en' => 'posts',        'ru' => 'посты',        'bg' => 'постове', 'de' => 'Beiträge'],
            'upload_cta_label' => ['en' => 'Upload post',  'ru' => 'Добавить пост', 'bg' => 'Добави пост', 'de' => 'Beitrag hochladen'],
            'feed_title' => ['en' => 'Latest posts', 'ru' => 'Последние посты', 'bg' => 'Последни постове', 'de' => 'Neueste Beiträge'],
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
            ['slug' => 'general', 'name' => ['en' => 'General', 'ru' => 'Общее', 'bg' => 'Общи', 'de' => 'Allgemein'], 'sort_order' => 10],
            ['slug' => 'showcase', 'name' => ['en' => 'Showcase', 'ru' => 'Витрина', 'bg' => 'Витрина', 'de' => 'Schaufenster'], 'sort_order' => 20],
            ['slug' => 'other', 'name' => ['en' => 'Other', 'ru' => 'Другое', 'bg' => 'Друго', 'de' => 'Sonstiges'], 'sort_order' => 30],
        ],
        'rating_groups' => [
            [
                'key' => 'type',
                'label' => ['en' => 'Type', 'ru' => 'Тип', 'bg' => 'Тип', 'de' => 'Typ'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null, 'de' => null],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'type_a', 'label' => ['en' => 'Type A', 'ru' => 'Тип A', 'bg' => 'Тип A', 'de' => 'Typ A'], 'sort_order' => 10],
                    ['key' => 'type_b', 'label' => ['en' => 'Type B', 'ru' => 'Тип B', 'bg' => 'Тип B', 'de' => 'Typ B'], 'sort_order' => 20],
                ],
            ],
            [
                'key' => 'attribute',
                'label' => ['en' => 'Attribute', 'ru' => 'Признак', 'bg' => 'Признак', 'de' => 'Merkmal'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null, 'de' => null],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'attribute_a', 'label' => ['en' => 'Attribute A', 'ru' => 'Признак A', 'bg' => 'Признак A', 'de' => 'Merkmal A'], 'sort_order' => 10],
                    ['key' => 'attribute_b', 'label' => ['en' => 'Attribute B', 'ru' => 'Признак B', 'bg' => 'Признак B', 'de' => 'Merkmal B'], 'sort_order' => 20],
                    ['key' => 'attribute_c', 'label' => ['en' => 'Attribute C', 'ru' => 'Признак C', 'bg' => 'Признак C', 'de' => 'Merkmal C'], 'sort_order' => 30],
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
            'site_name' => ['en' => 'NatureGuru',              'ru' => 'НейчерГуру',             'bg' => 'НейчърГуру', 'de' => 'NatureGuru'],
            'site_tagline' => ['en' => 'Rate stunning nature photos', 'ru' => 'Оценивай природные фото', 'bg' => 'Оценявай природни снимки', 'de' => 'Bewerte atemberaubende Naturfotos'],
            'site_description' => [
                'en' => 'Community-powered ratings for nature and travel photography.',
                'ru' => 'Народные оценки фотографий природы и путешествий.',
                'bg' => 'Общностни оценки на природна и пътническа фотография.',
                'de' => 'Bewertungen aus der Community für Natur- und Reisefotografie.',
            ],
            'object_singular_name' => ['en' => 'photo',         'ru' => 'фото',          'bg' => 'снимка', 'de' => 'Foto'],
            'object_plural_name' => ['en' => 'photos',        'ru' => 'фото',          'bg' => 'снимки', 'de' => 'Fotos'],
            'upload_cta_label' => ['en' => 'Upload photo',  'ru' => 'Добавить фото', 'bg' => 'Добави снимка', 'de' => 'Foto hochladen'],
            'feed_title' => ['en' => 'Latest photos', 'ru' => 'Последние фото', 'bg' => 'Последни снимки', 'de' => 'Neueste Fotos'],
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
            ['slug' => 'landscape', 'name' => ['en' => 'Landscape', 'ru' => 'Пейзаж', 'bg' => 'Пейзаж', 'de' => 'Landschaft'], 'sort_order' => 10],
            ['slug' => 'wildlife', 'name' => ['en' => 'Wildlife', 'ru' => 'Дикая природа', 'bg' => 'Дива природа', 'de' => 'Wildtiere'], 'sort_order' => 20],
            ['slug' => 'macro', 'name' => ['en' => 'Macro', 'ru' => 'Макро', 'bg' => 'Макро', 'de' => 'Makro'], 'sort_order' => 30],
            ['slug' => 'urban', 'name' => ['en' => 'Urban', 'ru' => 'Город', 'bg' => 'Град', 'de' => 'Stadt'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'photographer_type',
                'label' => ['en' => 'How was this photo taken?',     'ru' => 'Как сделано фото?',          'bg' => 'Как е направена снимката?', 'de' => 'Wie wurde dieses Foto aufgenommen?'],
                'description' => ['en' => 'Was it shot professionally or by an amateur?', 'ru' => 'Профессионально или любительски?', 'bg' => 'Професионално или аматьорски?', 'de' => 'Wurde es professionell oder von einem Amateur aufgenommen?'],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'professional', 'label' => ['en' => 'Professional', 'ru' => 'Профессионально', 'bg' => 'Професионално', 'de' => 'Professionell'], 'sort_order' => 10],
                    ['key' => 'amateur',      'label' => ['en' => 'Amateur',      'ru' => 'Любительски',     'bg' => 'Аматьорски', 'de' => 'Amateur'],    'sort_order' => 20],
                ],
            ],
            [
                'key' => 'shot_type',
                'label' => ['en' => 'What type of shot is this?',    'ru' => 'Тип снимка?',               'bg' => 'Какъв вид снимка е това?', 'de' => 'Was für eine Aufnahme ist das?'],
                'description' => ['en' => 'Choose the technique that best describes the shot.', 'ru' => 'Выберите технику, которая лучше всего описывает снимок.', 'bg' => 'Изберете техниката, която най-добре описва снимката.', 'de' => 'Wähle die Technik, die die Aufnahme am besten beschreibt.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'wide', 'label' => ['en' => 'Wide shot', 'ru' => 'Общий план', 'bg' => 'Общ план', 'de' => 'Totale'], 'sort_order' => 10],
                    ['key' => 'close_up', 'label' => ['en' => 'Close-up', 'ru' => 'Крупный план', 'bg' => 'Близък план', 'de' => 'Nahaufnahme'], 'sort_order' => 20],
                    ['key' => 'aerial', 'label' => ['en' => 'Aerial', 'ru' => 'С воздуха', 'bg' => 'Въздушен', 'de' => 'Luftaufnahme'], 'sort_order' => 30],
                    ['key' => 'long_exposure', 'label' => ['en' => 'Long exposure', 'ru' => 'Длинная выдержка', 'bg' => 'Дълга експозиция', 'de' => 'Langzeitbelichtung'], 'sort_order' => 40],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Sunrise',       'ru' => 'Рассвет',      'bg' => 'Изгрев', 'de' => 'Sonnenaufgang'],
            ['en' => 'Sunset',        'ru' => 'Закат',        'bg' => 'Залез', 'de' => 'Sonnenuntergang'],
            ['en' => 'Golden hour',   'ru' => 'Золотой час',  'bg' => 'Златен час', 'de' => 'Goldene Stunde'],
            ['en' => 'Long exposure', 'ru' => 'Длинная выдержка', 'bg' => 'Дълга експозиция', 'de' => 'Langzeitbelichtung'],
            ['en' => 'Milky way',     'ru' => 'Млечный путь', 'bg' => 'Млечен път', 'de' => 'Milchstraße'],
            ['en' => 'Waterfall',     'ru' => 'Водопад',      'bg' => 'Водопад', 'de' => 'Wasserfall'],
            ['en' => 'Mountains',     'ru' => 'Горы',         'bg' => 'Планини', 'de' => 'Berge'],
            ['en' => 'Beach',         'ru' => 'Пляж',         'bg' => 'Плаж', 'de' => 'Strand'],
            ['en' => 'Forest',        'ru' => 'Лес',          'bg' => 'Гора', 'de' => 'Wald'],
            ['en' => 'Desert',        'ru' => 'Пустыня',      'bg' => 'Пустиня', 'de' => 'Wüste'],
            ['en' => 'Snow',          'ru' => 'Снег',         'bg' => 'Сняг', 'de' => 'Schnee'],
            ['en' => 'Birds',         'ru' => 'Птицы',        'bg' => 'Птици', 'de' => 'Vögel'],
            ['en' => 'Flowers',       'ru' => 'Цветы',        'bg' => 'Цветя', 'de' => 'Blumen'],
            ['en' => 'Fog',           'ru' => 'Туман',        'bg' => 'Мъгла', 'de' => 'Nebel'],
            ['en' => 'Storm',         'ru' => 'Шторм',        'bg' => 'Буря', 'de' => 'Sturm'],
            ['en' => 'Rainbow',       'ru' => 'Радуга',       'bg' => 'Дъга', 'de' => 'Regenbogen'],
            ['en' => 'Reflection',    'ru' => 'Отражение',    'bg' => 'Отражение', 'de' => 'Spiegelung'],
            ['en' => 'Night sky',     'ru' => 'Ночное небо',  'bg' => 'Нощно небе', 'de' => 'Nachthimmel'],
            ['en' => 'Tropical',      'ru' => 'Тропики',      'bg' => 'Тропически', 'de' => 'Tropen'],
            ['en' => 'Arctic',        'ru' => 'Арктика',      'bg' => 'Арктически', 'de' => 'Arktis'],
        ],
    ],

    // -------------------------------------------------------------------------
    // AI image rating (all locales)
    // -------------------------------------------------------------------------

    'ai_images' => [
        'label' => 'AI image rating',
        'settings' => [
            'site_name' => ['en' => 'AIGuru',                   'ru' => 'АйГуру',                     'bg' => 'АйГуру', 'de' => 'AIGuru'],
            'site_tagline' => ['en' => 'Rate AI-generated images', 'ru' => 'Оценивай изображения от ИИ', 'bg' => 'Оценявай изображения от ИИ', 'de' => 'Bewerte KI-generierte Bilder'],
            'site_description' => [
                'en' => 'Community ratings for AI-generated artwork.',
                'ru' => 'Народные оценки изображений, созданных ИИ.',
                'bg' => 'Общностни оценки на изображения, създадени от ИИ.',
                'de' => 'Bewertungen aus der Community für KI-generierte Kunstwerke.',
            ],
            'object_singular_name' => ['en' => 'image',         'ru' => 'изображение',      'bg' => 'изображение', 'de' => 'Bild'],
            'object_plural_name' => ['en' => 'images',        'ru' => 'изображения',      'bg' => 'изображения', 'de' => 'Bilder'],
            'upload_cta_label' => ['en' => 'Upload image',  'ru' => 'Добавить изображение', 'bg' => 'Добави изображение', 'de' => 'Bild hochladen'],
            'feed_title' => ['en' => 'Latest images', 'ru' => 'Последние изображения', 'bg' => 'Последни изображения', 'de' => 'Neueste Bilder'],
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
            ['slug' => 'portrait', 'name' => ['en' => 'Portrait', 'ru' => 'Портрет', 'bg' => 'Портрет', 'de' => 'Porträt'], 'sort_order' => 10],
            ['slug' => 'landscape', 'name' => ['en' => 'Landscape', 'ru' => 'Пейзаж', 'bg' => 'Пейзаж', 'de' => 'Landschaft'], 'sort_order' => 20],
            ['slug' => 'character', 'name' => ['en' => 'Character', 'ru' => 'Персонаж', 'bg' => 'Персонаж', 'de' => 'Figur'], 'sort_order' => 30],
            ['slug' => 'architecture', 'name' => ['en' => 'Architecture', 'ru' => 'Архитектура', 'bg' => 'Архитектура', 'de' => 'Architektur'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'model',
                'label' => ['en' => 'Which AI model generated this?', 'ru' => 'Какая модель ИИ создала это?', 'bg' => 'Кой ИИ модел го е създал?', 'de' => 'Welches KI-Modell hat das erzeugt?'],
                'description' => ['en' => null, 'ru' => null, 'bg' => null, 'de' => null],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'midjourney', 'label' => ['en' => 'Midjourney',      'ru' => 'Midjourney',      'bg' => 'Midjourney', 'de' => 'Midjourney'],      'sort_order' => 10],
                    ['key' => 'dalle',      'label' => ['en' => 'DALL·E',          'ru' => 'DALL·E',          'bg' => 'DALL·E', 'de' => 'DALL·E'],          'sort_order' => 20],
                    ['key' => 'stable',     'label' => ['en' => 'Stable Diff.',    'ru' => 'Stable Diff.',    'bg' => 'Stable Diff.', 'de' => 'Stable Diff.'],    'sort_order' => 30],
                    ['key' => 'other',      'label' => ['en' => 'Other / Unknown', 'ru' => 'Другое / Неизвестно', 'bg' => 'Друго / Неизвестно', 'de' => 'Andere / Unbekannt'], 'sort_order' => 40],
                ],
            ],
            [
                'key' => 'style',
                'label' => ['en' => 'What style is this image?', 'ru' => 'Стиль изображения?', 'bg' => 'Какъв стил е това изображение?', 'de' => 'Welchen Stil hat dieses Bild?'],
                'description' => ['en' => 'Choose the visual style.', 'ru' => 'Выберите визуальный стиль.', 'bg' => 'Изберете визуалния стил.', 'de' => 'Wähle den visuellen Stil.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'photorealistic', 'label' => ['en' => 'Photorealistic', 'ru' => 'Фотореалистичное', 'bg' => 'Фотореалистично', 'de' => 'Fotorealistisch'], 'sort_order' => 10],
                    ['key' => 'illustration',   'label' => ['en' => 'Illustration',   'ru' => 'Иллюстрация',     'bg' => 'Илюстрация', 'de' => 'Illustration'],     'sort_order' => 20],
                    ['key' => 'concept_art',    'label' => ['en' => 'Concept art',    'ru' => 'Концепт-арт',     'bg' => 'Концепт арт', 'de' => 'Concept-Art'],    'sort_order' => 30],
                    ['key' => 'pixel_art',      'label' => ['en' => 'Pixel art',      'ru' => 'Пиксель-арт',     'bg' => 'Пиксел арт', 'de' => 'Pixel-Art'],     'sort_order' => 40],
                    ['key' => 'abstract',       'label' => ['en' => 'Abstract',       'ru' => 'Абстракция',      'bg' => 'Абстрактно', 'de' => 'Abstrakt'],     'sort_order' => 50],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Fantasy',      'ru' => 'Фэнтези',      'bg' => 'Фентъзи', 'de' => 'Fantasy'],
            ['en' => 'Sci-fi',       'ru' => 'Фантастика',   'bg' => 'Фантастика', 'de' => 'Science-Fiction'],
            ['en' => 'Anime',        'ru' => 'Аниме',        'bg' => 'Аниме', 'de' => 'Anime'],
            ['en' => 'Dark',         'ru' => 'Тёмное',       'bg' => 'Тъмно', 'de' => 'Düster'],
            ['en' => 'Colorful',     'ru' => 'Красочное',    'bg' => 'Цветно', 'de' => 'Farbenfroh'],
            ['en' => 'Minimalist',   'ru' => 'Минимализм',   'bg' => 'Минималистично', 'de' => 'Minimalistisch'],
            ['en' => 'Surreal',      'ru' => 'Сюрреализм',   'bg' => 'Сюреалистично', 'de' => 'Surreal'],
            ['en' => 'Nature',       'ru' => 'Природа',      'bg' => 'Природа', 'de' => 'Natur'],
            ['en' => 'Space',        'ru' => 'Космос',       'bg' => 'Космос', 'de' => 'Weltraum'],
            ['en' => 'Cyberpunk',    'ru' => 'Киберпанк',    'bg' => 'Киберпънк', 'de' => 'Cyberpunk'],
            ['en' => 'Steampunk',    'ru' => 'Стимпанк',     'bg' => 'Стийм пънк', 'de' => 'Steampunk'],
        ],
    ],

    // -------------------------------------------------------------------------
    // Breast rating (all locales)
    // -------------------------------------------------------------------------

    'breasts' => [
        'label' => 'Breast rating',
        'settings' => [
            'site_name' => ['en' => 'BreastGuru',              'ru' => 'BreastGuru',                   'bg' => 'BreastGuru', 'de' => 'BreastGuru'],
            'site_tagline' => ['en' => 'Rate every pair',         'ru' => 'Оценивай каждую пару',         'bg' => 'Оценявай всяка двойка', 'de' => 'Bewerte jedes Paar'],
            'site_description' => [
                'en' => 'Community-powered breast ratings.',
                'ru' => 'Народные оценки женской груди.',
                'bg' => 'Общностни оценки на женски гърди.',
                'de' => 'Brustbewertungen aus der Community.',
            ],
            'object_singular_name' => ['en' => 'photo',        'ru' => 'фото',         'bg' => 'снимка', 'de' => 'Foto'],
            'object_plural_name' => ['en' => 'photos',       'ru' => 'фото',         'bg' => 'снимки', 'de' => 'Fotos'],
            'upload_cta_label' => ['en' => 'Upload photo', 'ru' => 'Добавить фото', 'bg' => 'Добави снимка', 'de' => 'Foto hochladen'],
            'feed_title' => ['en' => 'Latest photos', 'ru' => 'Последние фото', 'bg' => 'Последни снимки', 'de' => 'Neueste Fotos'],
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
            ['slug' => 'small', 'name' => ['en' => 'Small', 'ru' => 'Маленькая', 'bg' => 'Малка', 'de' => 'Klein'], 'sort_order' => 10],
            ['slug' => 'medium', 'name' => ['en' => 'Medium', 'ru' => 'Средняя', 'bg' => 'Средна', 'de' => 'Mittel'], 'sort_order' => 20],
            ['slug' => 'big', 'name' => ['en' => 'Big', 'ru' => 'Большая', 'bg' => 'Голяма', 'de' => 'Groß'], 'sort_order' => 30],
            ['slug' => 'nsfw', 'name' => ['en' => 'NSFW', 'ru' => 'NSFW', 'bg' => 'NSFW', 'de' => 'NSFW'], 'sort_order' => 40],
        ],
        'rating_groups' => [
            [
                'key' => 'type',
                'label' => ['en' => 'Is it fake or real?',          'ru' => 'Натуральная или силиконовая?', 'bg' => 'Естествена или силиконова?', 'de' => 'Natürlich oder vergrößert?'],
                'description' => ['en' => 'Are these natural or enhanced?', 'ru' => 'Натуральная или увеличенная?', 'bg' => 'Естествена или уголемена?', 'de' => 'Sind sie natürlich oder vergrößert?'],
                'sort_order' => 10,
                'options' => [
                    ['key' => 'natural',  'label' => ['en' => 'Natural',  'ru' => 'Натуральная', 'bg' => 'Естествена', 'de' => 'Natürlich'], 'sort_order' => 10],
                    ['key' => 'silicone', 'label' => ['en' => 'Silicone', 'ru' => 'Силикон',     'bg' => 'Силикон', 'de' => 'Silikon'],    'sort_order' => 20],
                ],
            ],
            [
                'key' => 'cup_size',
                'label' => ['en' => 'What cup size is it?',         'ru' => 'Какой размер чашки?',          'bg' => 'Какъв е размерът на чашката?', 'de' => 'Welche Körbchengröße ist es?'],
                'description' => ['en' => 'Choose the closest cup size.', 'ru' => 'Выберите ближайший размер чашки.', 'bg' => 'Изберете най-близкия размер на чашката.', 'de' => 'Wähle die nächstliegende Körbchengröße.'],
                'sort_order' => 20,
                'options' => [
                    ['key' => 'aa',   'label' => ['en' => 'AA', 'ru' => 'AA', 'bg' => 'AA', 'de' => 'AA'], 'sort_order' => 10],
                    ['key' => 'a',    'label' => ['en' => 'A',  'ru' => 'A',  'bg' => 'A', 'de' => 'A'],  'sort_order' => 20],
                    ['key' => 'b',    'label' => ['en' => 'B',  'ru' => 'B',  'bg' => 'B', 'de' => 'B'],  'sort_order' => 30],
                    ['key' => 'c',    'label' => ['en' => 'C',  'ru' => 'C',  'bg' => 'C', 'de' => 'C'],  'sort_order' => 40],
                    ['key' => 'd',    'label' => ['en' => 'D',  'ru' => 'D',  'bg' => 'D', 'de' => 'D'],  'sort_order' => 50],
                    ['key' => 'dd',   'label' => ['en' => 'DD', 'ru' => 'DD', 'bg' => 'DD', 'de' => 'DD'], 'sort_order' => 60],
                    ['key' => 'ddd',  'label' => ['en' => 'DDD', 'ru' => 'DDD', 'bg' => 'DDD', 'de' => 'DDD'], 'sort_order' => 70],
                    ['key' => 'g',    'label' => ['en' => 'G',  'ru' => 'G',  'bg' => 'G', 'de' => 'G'],  'sort_order' => 80],
                    ['key' => 'h',    'label' => ['en' => 'H',  'ru' => 'H',  'bg' => 'H', 'de' => 'H'],  'sort_order' => 90],
                    ['key' => 'i',    'label' => ['en' => 'I',  'ru' => 'I',  'bg' => 'I', 'de' => 'I'],  'sort_order' => 100],
                    ['key' => 'j_plus', 'label' => ['en' => 'J+', 'ru' => 'J+', 'bg' => 'J+', 'de' => 'J+'], 'sort_order' => 110],
                ],
            ],
        ],
        'tags' => [
            ['en' => 'Babes',          'ru' => 'Бейбс',           'bg' => 'Бейбс', 'de' => 'Babes'],
            ['en' => 'Glamour shots',  'ru' => 'Гламурные фото',   'bg' => 'Гламурни снимки', 'de' => 'Glamour-Fotos'],
            ['en' => 'Silicone',       'ru' => 'Силикон',          'bg' => 'Силикон', 'de' => 'Silikon'],
            ['en' => 'HD',             'ru' => 'HD',               'bg' => 'HD', 'de' => 'HD'],
            ['en' => 'Natural',        'ru' => 'Натуральная',      'bg' => 'Естествена', 'de' => 'Natürlich'],
            ['en' => 'Celebrities',    'ru' => 'Знаменитости',     'bg' => 'Знаменитости', 'de' => 'Promis'],
            ['en' => 'Adult',          'ru' => 'Взрослые',         'bg' => 'За възрастни', 'de' => 'Ab 18'],
            ['en' => 'Topless',        'ru' => 'Топлес',           'bg' => 'Топлес', 'de' => 'Oben ohne'],
            ['en' => 'Lingerie',       'ru' => 'Нижнее бельё',     'bg' => 'Бельо', 'de' => 'Dessous'],
            ['en' => 'Bikini',         'ru' => 'Бикини',           'bg' => 'Бикини', 'de' => 'Bikini'],
            ['en' => 'Cosplay',        'ru' => 'Косплей',          'bg' => 'Косплей', 'de' => 'Cosplay'],
            ['en' => 'Amateur',        'ru' => 'Любительское',     'bg' => 'Аматьорско', 'de' => 'Amateur'],
        ],
    ],

];
