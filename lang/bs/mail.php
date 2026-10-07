<?php

return [
    'greeting' => 'Zdravo, :name!',
    'salutation' => 'Srdačan pozdrav, :app',
    'fallback_greeting' => 'Zdravo!',
    'error_greeting' => 'Ups!',
    'rights_reserved' => 'Sva prava zadržana.',
    'action_fallback' => 'Ako ne možeš kliknuti na dugme „:action“, kopiraj URL ispod i zalijepi ga u svoj web-preglednik:',

    'verify' => [
        'subject' => 'Potvrdi svoju e-mail adresu',
        'line' => 'Potvrdi svoju e-mail adresu da završiš postavljanje računa.',
        'action' => 'Potvrdi e-mail adresu',
        'ignore' => 'Ako račun nije kreiran na tvoj zahtjev, ne moraš ništa poduzimati.',
    ],

    'reset' => [
        'subject' => 'Poništi svoju lozinku',
        'line' => 'Primaš ovaj e-mail jer smo primili zahtjev za poništavanje lozinke za tvoj račun.',
        'action' => 'Poništi lozinku',
        'expire' => 'Ovaj link za poništavanje lozinke ističe za :count min.',
        'ignore' => 'Ako zahtjev za poništavanje lozinke nije od tebe, ne moraš ništa poduzimati.',
    ],

    'contact' => [
        'subject' => 'Nova poruka s kontakt-forme: :subject',
        'heading' => 'Nova poruka s kontakt-forme',
        'name' => 'Ime',
        'email' => 'E-mail',
        'message_subject' => 'Naslov',
        'body' => 'Poruka',
    ],

    'social' => [
        'account' => ':provider račun: :email',
        'action' => 'Pregledaj povezane račune',
        'connected' => [
            'subject' => ':provider je povezan s tvojim računom',
            'line' => ':provider račun je povezan s tvojim RateGuru računom. Od sada se njime možeš prijaviti.',
            'not_you' => 'Ako to nisi ti, otvori svoj profil i odmah ga odspoji.',
        ],
        'disconnected' => [
            'subject' => ':provider je odspojen od tvog računa',
            'line' => ':provider račun je odspojen od tvog RateGuru računa. Njime se više ne možeš prijaviti.',
            'not_you' => 'Ako to nisi ti, prijavi se i provjeri svoje povezane račune i lozinku.',
        ],
    ],
];
