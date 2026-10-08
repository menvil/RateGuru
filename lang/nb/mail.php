<?php

return [
    'greeting' => 'Hei, :name!',
    'salutation' => 'Vennlig hilsen :app',
    'fallback_greeting' => 'Hei!',
    'error_greeting' => 'Oi da!',
    'rights_reserved' => 'Alle rettigheter forbeholdt.',
    'action_fallback' => 'Hvis du har problemer med å klikke på knappen «:action», kopierer du URL-en nedenfor og limer den inn i nettleseren din:',

    'verify' => [
        'subject' => 'Bekreft e-postadressen din',
        'line' => 'Bekreft e-postadressen din for å fullføre oppsettet av kontoen.',
        'action' => 'Bekreft e-postadressen',
        'ignore' => 'Hvis du ikke har opprettet en konto, trenger du ikke gjøre noe.',
    ],

    'reset' => [
        'subject' => 'Tilbakestill passordet ditt',
        'line' => 'Du mottar denne e-posten fordi vi har fått en forespørsel om å tilbakestille passordet for kontoen din.',
        'action' => 'Tilbakestill passord',
        'expire' => 'Denne lenken for tilbakestilling av passord utløper om :count minutter.',
        'ignore' => 'Hvis du ikke har bedt om å tilbakestille passordet, trenger du ikke gjøre noe.',
    ],

    'contact' => [
        'subject' => 'Ny kontaktmelding: :subject',
        'heading' => 'Ny kontaktmelding',
        'name' => 'Navn',
        'email' => 'E-post',
        'message_subject' => 'Emne',
        'body' => 'Melding',
    ],

    'social' => [
        'account' => ':provider-konto: :email',
        'action' => 'Se gjennom tilkoblede kontoer',
        'connected' => [
            'subject' => ':provider ble koblet til kontoen din',
            'line' => 'En :provider-konto ble koblet til RateGuru-kontoen din. Den kan nå brukes til å logge inn.',
            'not_you' => 'Hvis dette ikke var deg, åpner du profilen din og kobler den fra med en gang.',
        ],
        'disconnected' => [
            'subject' => ':provider ble koblet fra kontoen din',
            'line' => 'En :provider-konto ble koblet fra RateGuru-kontoen din. Den kan ikke lenger brukes til å logge inn.',
            'not_you' => 'Hvis dette ikke var deg, logger du inn og kontrollerer de tilkoblede kontoene og passordet ditt.',
        ],
    ],
];
