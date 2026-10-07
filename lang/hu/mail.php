<?php

return [
    'greeting' => 'Szia, :name!',
    'salutation' => 'Üdvözlettel: :app',
    'fallback_greeting' => 'Szia!',
    'error_greeting' => 'Hoppá!',
    'rights_reserved' => 'Minden jog fenntartva.',
    'action_fallback' => 'Ha a gomb („:action”) nem működik, másold ki az alábbi URL-t, és illeszd be a böngésződbe:',

    'verify' => [
        'subject' => 'Erősítsd meg az e-mail-címed',
        'line' => 'Erősítsd meg az e-mail-címed, hogy befejezd a fiókod beállítását.',
        'action' => 'E-mail-cím megerősítése',
        'ignore' => 'Ha nem te hoztál létre fiókot, nincs további teendőd.',
    ],

    'reset' => [
        'subject' => 'Állítsd vissza a jelszavad',
        'line' => 'Azért kaptad ezt az e-mailt, mert jelszó-visszaállítási kérelmet kaptunk a fiókodhoz.',
        'action' => 'Jelszó visszaállítása',
        'expire' => 'Ez a jelszó-visszaállító link :count perc múlva lejár.',
        'ignore' => 'Ha nem te kérted a jelszó visszaállítását, nincs további teendőd.',
    ],

    'contact' => [
        'subject' => 'Új kapcsolatfelvételi üzenet: :subject',
        'heading' => 'Új kapcsolatfelvételi üzenet',
        'name' => 'Név',
        'email' => 'E-mail-cím',
        'message_subject' => 'Tárgy',
        'body' => 'Üzenet',
    ],

    'social' => [
        'account' => ':provider-fiók: :email',
        'action' => 'Kapcsolt fiókok áttekintése',
        'connected' => [
            'subject' => ':provider-fiók kapcsolódott a fiókodhoz',
            'line' => 'Egy :provider-fiókot kapcsoltak a RateGuru-fiókodhoz. Mostantól bejelentkezésre is használható.',
            'not_you' => 'Ha nem te voltál, nyisd meg a profilodat, és azonnal bontsd a kapcsolatot.',
        ],
        'disconnected' => [
            'subject' => ':provider-fiók leválasztva a fiókodról',
            'line' => 'Egy :provider-fiókot leválasztottak a RateGuru-fiókodról. Bejelentkezésre már nem használható.',
            'not_you' => 'Ha nem te voltál, jelentkezz be, és ellenőrizd a kapcsolt fiókjaidat és a jelszavadat.',
        ],
    ],
];
