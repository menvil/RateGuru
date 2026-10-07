<?php

return [
    'greeting' => 'Hei :name!',
    'salutation' => 'Terveisin :app',
    'fallback_greeting' => 'Hei!',
    'error_greeting' => 'Hups!',
    'rights_reserved' => 'Kaikki oikeudet pidätetään.',
    'action_fallback' => 'Jos painike ”:action” ei toimi, kopioi alla oleva URL-osoite ja liitä se selaimeesi:',

    'verify' => [
        'subject' => 'Vahvista sähköpostiosoitteesi',
        'line' => 'Vahvista sähköpostiosoitteesi, niin tilisi käyttöönotto viimeistellään.',
        'action' => 'Vahvista sähköpostiosoite',
        'ignore' => 'Jos et luonut tiliä, sinun ei tarvitse tehdä mitään.',
    ],

    'reset' => [
        'subject' => 'Palauta salasanasi',
        'line' => 'Saat tämän viestin, koska saimme tiliäsi koskevan salasanan palautuspyynnön.',
        'action' => 'Palauta salasana',
        'expire' => 'Tämä salasanan palautuslinkki vanhenee :count minuutin kuluttua.',
        'ignore' => 'Jos et pyytänyt salasanan palautusta, sinun ei tarvitse tehdä mitään.',
    ],

    'contact' => [
        'subject' => 'Uusi yhteydenotto: :subject',
        'heading' => 'Uusi yhteydenotto',
        'name' => 'Nimi',
        'email' => 'Sähköposti',
        'message_subject' => 'Aihe',
        'body' => 'Viesti',
    ],

    'social' => [
        'account' => ':provider-tili: :email',
        'action' => 'Tarkista yhdistetyt tilit',
        'connected' => [
            'subject' => ':provider-tili yhdistettiin tiliisi',
            'line' => ':provider-tili yhdistettiin RateGuru-tiliisi. Sillä voi nyt kirjautua sisään.',
            'not_you' => 'Jos et tehnyt tätä itse, avaa profiilisi ja poista yhteys heti.',
        ],
        'disconnected' => [
            'subject' => ':provider-tilin yhteys tiliisi poistettiin',
            'line' => ':provider-tilin yhteys RateGuru-tiliisi poistettiin. Sillä ei voi enää kirjautua sisään.',
            'not_you' => 'Jos et tehnyt tätä itse, kirjaudu sisään ja tarkista yhdistetyt tilisi ja salasanasi.',
        ],
    ],
];
