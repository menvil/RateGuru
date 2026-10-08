<?php

return [
    'greeting' => 'Cześć, :name!',
    'salutation' => 'Pozdrawiamy, :app',
    'fallback_greeting' => 'Cześć!',
    'error_greeting' => 'Ups!',
    'rights_reserved' => 'Wszelkie prawa zastrzeżone.',
    'action_fallback' => 'Jeśli masz problem z kliknięciem przycisku „:action”, skopiuj poniższy adres URL i wklej go w przeglądarce:',

    'verify' => [
        'subject' => 'Potwierdź swój adres e-mail',
        'line' => 'Potwierdź swój adres e-mail, aby dokończyć konfigurację konta.',
        'action' => 'Potwierdź adres e-mail',
        'ignore' => 'Jeśli to nie Ty zakładasz konto, nie musisz nic robić.',
    ],

    'reset' => [
        'subject' => 'Zresetuj hasło',
        'line' => 'Otrzymujesz tę wiadomość, ponieważ otrzymaliśmy prośbę o zresetowanie hasła do Twojego konta.',
        'action' => 'Zresetuj hasło',
        'expire' => 'Ten link do resetowania hasła wygaśnie za :count min.',
        'ignore' => 'Jeśli prośba o zresetowanie hasła nie pochodzi od Ciebie, nie musisz nic robić.',
    ],

    'contact' => [
        'subject' => 'Nowa wiadomość kontaktowa: :subject',
        'heading' => 'Nowa wiadomość kontaktowa',
        'name' => 'Imię',
        'email' => 'E-mail',
        'message_subject' => 'Temat',
        'body' => 'Wiadomość',
    ],

    'social' => [
        'account' => 'Konto :provider: :email',
        'action' => 'Sprawdź połączone konta',
        'connected' => [
            'subject' => 'Z Twoim kontem połączono :provider',
            'line' => 'Z Twoim kontem RateGuru połączono konto :provider. Można go teraz używać do logowania.',
            'not_you' => 'Jeśli to nie Ty, otwórz swój profil i natychmiast je odłącz.',
        ],
        'disconnected' => [
            'subject' => 'Od Twojego konta odłączono :provider',
            'line' => 'Od Twojego konta RateGuru odłączono konto :provider. Nie można go już używać do logowania.',
            'not_you' => 'Jeśli to nie Ty, zaloguj się i sprawdź połączone konta oraz swoje hasło.',
        ],
    ],
];
