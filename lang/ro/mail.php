<?php

return [
    'greeting' => 'Salut, :name!',
    'salutation' => 'Cu stimă, :app',
    'fallback_greeting' => 'Salut!',
    'error_greeting' => 'Hopa!',
    'rights_reserved' => 'Toate drepturile rezervate.',
    'action_fallback' => 'Dacă nu poți apăsa butonul „:action”, copiază URL-ul de mai jos și lipește-l în browser:',

    'verify' => [
        'subject' => 'Confirmă-ți adresa de e-mail',
        'line' => 'Confirmă-ți adresa de e-mail pentru a finaliza configurarea contului.',
        'action' => 'Confirmă adresa de e-mail',
        'ignore' => 'Dacă nu ai creat un cont, nu trebuie să faci nimic.',
    ],

    'reset' => [
        'subject' => 'Resetează-ți parola',
        'line' => 'Primești acest e-mail deoarece am primit o cerere de resetare a parolei pentru contul tău.',
        'action' => 'Resetează parola',
        'expire' => 'Acest link de resetare a parolei va expira în :count minute.',
        'ignore' => 'Dacă nu ai cerut resetarea parolei, nu trebuie să faci nimic.',
    ],

    'contact' => [
        'subject' => 'Mesaj nou de contact: :subject',
        'heading' => 'Mesaj nou de contact',
        'name' => 'Nume',
        'email' => 'E-mail',
        'message_subject' => 'Subiect',
        'body' => 'Mesaj',
    ],

    'social' => [
        'account' => 'Cont :provider: :email',
        'action' => 'Verifică conturile conectate',
        'connected' => [
            'subject' => ':provider a fost conectat la contul tău',
            'line' => 'Un cont :provider a fost conectat la contul tău RateGuru. Acum poate fi folosit pentru autentificare.',
            'not_you' => 'Dacă nu ai fost tu, deschide-ți profilul și deconectează-l imediat.',
        ],
        'disconnected' => [
            'subject' => ':provider a fost deconectat de la contul tău',
            'line' => 'Un cont :provider a fost deconectat de la contul tău RateGuru. Nu mai poate fi folosit pentru autentificare.',
            'not_you' => 'Dacă nu ai fost tu, autentifică-te și verifică-ți conturile conectate și parola.',
        ],
    ],
];
