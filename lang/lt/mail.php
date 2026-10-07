<?php

return [
    'greeting' => 'Sveiki, :name!',
    'salutation' => 'Pagarbiai, :app',
    'fallback_greeting' => 'Sveiki!',
    'error_greeting' => 'Oi!',
    'rights_reserved' => 'Visos teisės saugomos.',
    'action_fallback' => 'Jei nepavyksta paspausti mygtuko „:action“, nukopijuokite toliau pateiktą URL ir įklijuokite jį į savo naršyklę:',

    'verify' => [
        'subject' => 'Patvirtinkite savo el. pašto adresą',
        'line' => 'Patvirtinkite savo el. pašto adresą, kad užbaigtumėte paskyros kūrimą.',
        'action' => 'Patvirtinti el. pašto adresą',
        'ignore' => 'Jei paskyros nekūrėte, nieko daryti nereikia.',
    ],

    'reset' => [
        'subject' => 'Slaptažodžio atkūrimas',
        'line' => 'Gavote šį laišką, nes gavome jūsų paskyros slaptažodžio atkūrimo užklausą.',
        'action' => 'Atkurti slaptažodį',
        'expire' => 'Ši slaptažodžio atkūrimo nuoroda nustos galioti po :count min.',
        'ignore' => 'Jei slaptažodžio atkūrimo neprašėte, nieko daryti nereikia.',
    ],

    'contact' => [
        'subject' => 'Nauja žinutė per kontaktų formą: :subject',
        'heading' => 'Nauja žinutė per kontaktų formą',
        'name' => 'Vardas',
        'email' => 'El. paštas',
        'message_subject' => 'Tema',
        'body' => 'Žinutė',
    ],

    'social' => [
        'account' => ':provider paskyra: :email',
        'action' => 'Peržiūrėti susietas paskyras',
        'connected' => [
            'subject' => ':provider paskyra susieta su jūsų paskyra',
            'line' => 'Prie jūsų RateGuru paskyros susieta :provider paskyra. Dabar ją galima naudoti prisijungimui.',
            'not_you' => 'Jei tai buvote ne jūs, atidarykite savo profilį ir nedelsdami ją atsiekite.',
        ],
        'disconnected' => [
            'subject' => ':provider paskyra atsieta nuo jūsų paskyros',
            'line' => ':provider paskyra atsieta nuo jūsų RateGuru paskyros. Jos nebegalima naudoti prisijungimui.',
            'not_you' => 'Jei tai buvote ne jūs, prisijunkite ir patikrinkite savo susietas paskyras bei slaptažodį.',
        ],
    ],
];
