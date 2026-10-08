<?php

return [
    'greeting' => 'Ahoj, :name!',
    'salutation' => 'S pozdravom :app',
    'fallback_greeting' => 'Ahoj!',
    'error_greeting' => 'Ups!',
    'rights_reserved' => 'Všetky práva vyhradené.',
    'action_fallback' => 'Ak ti nefunguje tlačidlo „:action“, skopíruj nasledujúcu URL adresu a vlož ju do webového prehliadača:',

    'verify' => [
        'subject' => 'Potvrď svoju e-mailovú adresu',
        'line' => 'Potvrď svoju e-mailovú adresu a dokonči nastavenie účtu.',
        'action' => 'Potvrdiť e-mailovú adresu',
        'ignore' => 'Ak o vytvorení účtu nič nevieš, nemusíš nič robiť.',
    ],

    'reset' => [
        'subject' => 'Obnovenie hesla',
        'line' => 'Tento e-mail ti prišiel, pretože sme pre tvoj účet dostali žiadosť o obnovenie hesla.',
        'action' => 'Obnoviť heslo',
        'expire' => 'Platnosť tohto odkazu na obnovenie hesla vyprší o :count min.',
        'ignore' => 'Ak o obnovení hesla nič nevieš, nemusíš nič robiť.',
    ],

    'contact' => [
        'subject' => 'Nová správa z kontaktného formulára: :subject',
        'heading' => 'Nová správa z kontaktného formulára',
        'name' => 'Meno',
        'email' => 'E-mail',
        'message_subject' => 'Predmet',
        'body' => 'Správa',
    ],

    'social' => [
        'account' => 'Účet :provider: :email',
        'action' => 'Skontrolovať prepojené účty',
        'connected' => [
            'subject' => 'Účet :provider bol prepojený s tvojím účtom',
            'line' => 'Účet :provider bol prepojený s tvojím účtom RateGuru. Odteraz ho môžeš použiť na prihlásenie.',
            'not_you' => 'Ak o tom nevieš, otvor svoj profil a ihneď ho odpoj.',
        ],
        'disconnected' => [
            'subject' => 'Účet :provider bol odpojený od tvojho účtu',
            'line' => 'Účet :provider bol odpojený od tvojho účtu RateGuru. Odteraz ho už nemožno použiť na prihlásenie.',
            'not_you' => 'Ak o tom nevieš, prihlás sa a skontroluj svoje prepojené účty a heslo.',
        ],
    ],
];
