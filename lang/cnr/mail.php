<?php

return [
    'greeting' => 'Zdravo, :name!',
    'salutation' => 'Pozdrav, :app',
    'fallback_greeting' => 'Zdravo!',
    'error_greeting' => 'Ups!',
    'rights_reserved' => 'Sva prava zadržana.',
    'action_fallback' => 'Ako ne možeš da klikneš na dugme „:action“, kopiraj URL ispod i nalijepi ga u svoj veb-pregledač:',

    'verify' => [
        'subject' => 'Potvrdi svoju e-mail adresu',
        'line' => 'Potvrdi svoju e-mail adresu da završiš podešavanje naloga.',
        'action' => 'Potvrdi e-mail adresu',
        'ignore' => 'Ako nalog nije napravljen na tvoj zahtjev, ne moraš ništa da preduzimaš.',
    ],

    'reset' => [
        'subject' => 'Resetuj lozinku',
        'line' => 'Dobijaš ovaj e-mail jer smo primili zahtjev za resetovanje lozinke za tvoj nalog.',
        'action' => 'Resetuj lozinku',
        'expire' => 'Ovaj link za resetovanje lozinke ističe za :count min.',
        'ignore' => 'Ako zahtjev za resetovanje lozinke nije od tebe, ne moraš ništa da preduzimaš.',
    ],

    'contact' => [
        'subject' => 'Nova poruka preko kontakt forme: :subject',
        'heading' => 'Nova poruka preko kontakt forme',
        'name' => 'Ime',
        'email' => 'E-mail',
        'message_subject' => 'Tema',
        'body' => 'Poruka',
    ],

    'social' => [
        'account' => ':provider nalog: :email',
        'action' => 'Pregledaj povezane naloge',
        'connected' => [
            'subject' => ':provider je povezan s tvojim nalogom',
            'line' => ':provider nalog je povezan s tvojim RateGuru nalogom. Od sada se može koristiti za prijavu.',
            'not_you' => 'Ako to nijesi ti, otvori svoj profil i odmah ga isključi.',
        ],
        'disconnected' => [
            'subject' => ':provider je isključen s tvog naloga',
            'line' => ':provider nalog je isključen s tvog RateGuru naloga. Više se ne može koristiti za prijavu.',
            'not_you' => 'Ako to nijesi ti, prijavi se i provjeri svoje povezane naloge i lozinku.',
        ],
    ],
];
