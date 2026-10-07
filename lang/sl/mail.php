<?php

return [
    'greeting' => 'Živjo, :name!',
    'salutation' => 'Lep pozdrav, :app',
    'fallback_greeting' => 'Živjo!',
    'error_greeting' => 'Ojoj!',
    'rights_reserved' => 'Vse pravice pridržane.',
    'action_fallback' => 'Če gumb „:action“ ne deluje, kopiraj spodnji URL in ga prilepi v spletni brskalnik:',

    'verify' => [
        'subject' => 'Potrdi svoj e-poštni naslov',
        'line' => 'Potrdi svoj e-poštni naslov, da dokončaš nastavitev računa.',
        'action' => 'Potrdi e-poštni naslov',
        'ignore' => 'Če tega računa ne poznaš, ti ni treba storiti ničesar.',
    ],

    'reset' => [
        'subject' => 'Ponastavi geslo',
        'line' => 'To e-pošto prejemaš, ker smo za tvoj račun prejeli zahtevo za ponastavitev gesla.',
        'action' => 'Ponastavi geslo',
        'expire' => 'Ta povezava za ponastavitev gesla poteče čez :count min.',
        'ignore' => 'Če zahteve za ponastavitev gesla ne prepoznaš, ti ni treba storiti ničesar.',
    ],

    'contact' => [
        'subject' => 'Novo sporočilo s kontaktnega obrazca: :subject',
        'heading' => 'Novo sporočilo s kontaktnega obrazca',
        'name' => 'Ime',
        'email' => 'E-pošta',
        'message_subject' => 'Zadeva',
        'body' => 'Sporočilo',
    ],

    'social' => [
        'account' => 'Račun :provider: :email',
        'action' => 'Preglej povezane račune',
        'connected' => [
            'subject' => 'Storitev :provider je bila povezana s tvojim računom',
            'line' => 'Račun :provider je bil povezan s tvojim računom RateGuru. Zdaj ga lahko uporabljaš za prijavo.',
            'not_you' => 'Če za to ne veš, odpri svoj profil in povezavo takoj prekini.',
        ],
        'disconnected' => [
            'subject' => 'Povezava tvojega računa s storitvijo :provider je bila prekinjena',
            'line' => 'Račun :provider ni več povezan s tvojim računom RateGuru in ga ni več mogoče uporabiti za prijavo.',
            'not_you' => 'Če za to ne veš, se prijavi ter preveri povezane račune in svoje geslo.',
        ],
    ],
];
