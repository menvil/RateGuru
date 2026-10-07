<?php

return [
    'greeting' => 'Bok, :name!',
    'salutation' => 'Lijep pozdrav, :app',
    'fallback_greeting' => 'Bok!',
    'error_greeting' => 'Ups!',
    'rights_reserved' => 'Sva prava pridržana.',
    'action_fallback' => 'Ako ne možeš kliknuti gumb „:action“, kopiraj donji URL i zalijepi ga u svoj web-preglednik:',

    'verify' => [
        'subject' => 'Potvrdi svoju adresu e-pošte',
        'line' => 'Potvrdi svoju adresu e-pošte kako bi dovršio postavljanje računa.',
        'action' => 'Potvrdi adresu e-pošte',
        'ignore' => 'Ako nisi stvorio račun, ne trebaš ništa poduzeti.',
    ],

    'reset' => [
        'subject' => 'Ponovno postavi lozinku',
        'line' => 'Primaš ovu e-poruku jer smo zaprimili zahtjev za ponovno postavljanje lozinke za tvoj račun.',
        'action' => 'Ponovno postavi lozinku',
        'expire' => 'Ova poveznica za ponovno postavljanje lozinke istječe za :count min.',
        'ignore' => 'Ako nisi zatražio ponovno postavljanje lozinke, ne trebaš ništa poduzeti.',
    ],

    'contact' => [
        'subject' => 'Nova poruka s kontakt obrasca: :subject',
        'heading' => 'Nova poruka s kontakt obrasca',
        'name' => 'Ime',
        'email' => 'E-pošta',
        'message_subject' => 'Predmet',
        'body' => 'Poruka',
    ],

    'social' => [
        'account' => 'Račun na usluzi :provider: :email',
        'action' => 'Pregledaj povezane račune',
        'connected' => [
            'subject' => ':provider povezan je s tvojim računom',
            'line' => 'Račun na usluzi :provider povezan je s tvojim RateGuru računom. Sada se njime možeš prijaviti.',
            'not_you' => 'Ako to nisi bio ti, otvori svoj profil i odmah ga odspoji.',
        ],
        'disconnected' => [
            'subject' => ':provider odspojen je od tvojeg računa',
            'line' => 'Račun na usluzi :provider odspojen je od tvojeg RateGuru računa. Više se njime ne možeš prijaviti.',
            'not_you' => 'Ako to nisi bio ti, prijavi se i provjeri povezane račune i svoju lozinku.',
        ],
    ],
];
