<?php

return [
    'greeting' => 'Përshëndetje, :name!',
    'salutation' => 'Me respekt, :app',
    'fallback_greeting' => 'Përshëndetje!',
    'error_greeting' => 'Ups!',
    'rights_reserved' => 'Të gjitha të drejtat të rezervuara.',
    'action_fallback' => 'Nëse ke vështirësi të klikosh butonin „:action“, kopjo URL-në më poshtë dhe ngjite në shfletuesin tënd:',

    'verify' => [
        'subject' => 'Konfirmo adresën tënde të email-it',
        'line' => 'Konfirmo adresën tënde të email-it për të përfunduar konfigurimin e llogarisë.',
        'action' => 'Konfirmo adresën e email-it',
        'ignore' => 'Nëse nuk ke krijuar llogari, nuk nevojitet asnjë veprim tjetër.',
    ],

    'reset' => [
        'subject' => 'Rivendos fjalëkalimin',
        'line' => 'Po e merr këtë email sepse morëm një kërkesë për rivendosjen e fjalëkalimit të llogarisë sate.',
        'action' => 'Rivendos fjalëkalimin',
        'expire' => 'Kjo lidhje për rivendosjen e fjalëkalimit do të skadojë pas :count minutash.',
        'ignore' => 'Nëse nuk ke kërkuar rivendosjen e fjalëkalimit, nuk nevojitet asnjë veprim tjetër.',
    ],

    'contact' => [
        'subject' => 'Mesazh i ri kontakti: :subject',
        'heading' => 'Mesazh i ri kontakti',
        'name' => 'Emri',
        'email' => 'Email-i',
        'message_subject' => 'Subjekti',
        'body' => 'Mesazhi',
    ],

    'social' => [
        'account' => 'Llogaria :provider: :email',
        'action' => 'Shiko llogaritë e lidhura',
        'connected' => [
            'subject' => ':provider u lidh me llogarinë tënde',
            'line' => 'Një llogari :provider u lidh me llogarinë tënde RateGuru. Tani mund të përdoret për hyrje.',
            'not_you' => 'Nëse nuk ishe ti, hap profilin tënd dhe shkëpute menjëherë.',
        ],
        'disconnected' => [
            'subject' => ':provider u shkëput nga llogaria jote',
            'line' => 'Një llogari :provider u shkëput nga llogaria jote RateGuru. Nuk mund të përdoret më për hyrje.',
            'not_you' => 'Nëse nuk ishe ti, hyr dhe kontrollo llogaritë e lidhura dhe fjalëkalimin.',
        ],
    ],
];
