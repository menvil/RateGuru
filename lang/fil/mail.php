<?php

return [
    'greeting' => 'Kumusta, :name!',
    'salutation' => 'Lubos na gumagalang, :app',
    'fallback_greeting' => 'Kumusta!',
    'error_greeting' => 'Naku!',
    'rights_reserved' => 'Nakalaan ang lahat ng karapatan.',
    'action_fallback' => 'Kung nahihirapan kang i-click ang button na “:action”, kopyahin at i-paste ang URL sa ibaba sa iyong web browser:',

    'verify' => [
        'subject' => 'Kumpirmahin ang iyong email address',
        'line' => 'Pakikumpirma ang iyong email address para matapos ang pag-set up ng iyong account.',
        'action' => 'Kumpirmahin ang email address',
        'ignore' => 'Kung hindi ka gumawa ng account, wala ka nang kailangang gawin.',
    ],

    'reset' => [
        'subject' => 'I-reset ang iyong password',
        'line' => 'Natatanggap mo ang email na ito dahil nakatanggap kami ng request na i-reset ang password ng iyong account.',
        'action' => 'I-reset ang password',
        'expire' => 'Mag-e-expire ang link na ito para i-reset ang password sa loob ng :count minuto.',
        'ignore' => 'Kung hindi ka humiling na i-reset ang password, wala ka nang kailangang gawin.',
    ],

    'contact' => [
        'subject' => 'Bagong mensahe sa pakikipag-ugnayan: :subject',
        'heading' => 'Bagong mensahe sa pakikipag-ugnayan',
        'name' => 'Pangalan',
        'email' => 'Email',
        'message_subject' => 'Paksa',
        'body' => 'Mensahe',
    ],

    'social' => [
        'account' => 'Account sa :provider: :email',
        'action' => 'Suriin ang mga nakakonektang account',
        'connected' => [
            'subject' => 'Naikonekta ang :provider sa iyong account',
            'line' => 'Isang :provider account ang naikonekta sa iyong RateGuru account. Magagamit na ito ngayon para mag-sign in.',
            'not_you' => 'Kung hindi ikaw ito, buksan ang iyong profile at idiskonekta ito kaagad.',
        ],
        'disconnected' => [
            'subject' => 'Nadiskonekta ang :provider sa iyong account',
            'line' => 'Isang :provider account ang nadiskonekta sa iyong RateGuru account. Hindi na ito magagamit para mag-sign in.',
            'not_you' => 'Kung hindi ikaw ito, mag-sign in at suriin ang iyong mga nakakonektang account at ang iyong password.',
        ],
    ],
];
