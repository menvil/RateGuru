<?php

return [
    'greeting' => 'Sveiki, :name!',
    'salutation' => 'Ar cieņu, :app',
    'fallback_greeting' => 'Sveiki!',
    'error_greeting' => 'Ak vai!',
    'rights_reserved' => 'Visas tiesības aizsargātas.',
    'action_fallback' => 'Ja neizdodas noklikšķināt uz pogas „:action”, nokopējiet tālāk norādīto URL un ielīmējiet to savā tīmekļa pārlūkprogrammā:',

    'verify' => [
        'subject' => 'Apstipriniet savu e-pasta adresi',
        'line' => 'Lūdzu, apstipriniet savu e-pasta adresi, lai pabeigtu konta iestatīšanu.',
        'action' => 'Apstiprināt e-pasta adresi',
        'ignore' => 'Ja kontu neizveidojāt jūs, nekas nav jādara.',
    ],

    'reset' => [
        'subject' => 'Paroles atiestatīšana',
        'line' => 'Jūs saņemat šo e-pastu, jo saņēmām paroles atiestatīšanas pieprasījumu jūsu kontam.',
        'action' => 'Atiestatīt paroli',
        'expire' => 'Šī paroles atiestatīšanas saite zaudēs derīgumu pēc :count min.',
        'ignore' => 'Ja paroles atiestatīšanu nepieprasījāt, nekas nav jādara.',
    ],

    'contact' => [
        'subject' => 'Jauna ziņa no saziņas formas: :subject',
        'heading' => 'Jauna ziņa no saziņas formas',
        'name' => 'Vārds',
        'email' => 'E-pasts',
        'message_subject' => 'Temats',
        'body' => 'Ziņa',
    ],

    'social' => [
        'account' => ':provider konts: :email',
        'action' => 'Pārskatīt savienotos kontus',
        'connected' => [
            'subject' => ':provider konts ir savienots ar jūsu kontu',
            'line' => 'Ar jūsu RateGuru kontu tika savienots :provider konts. Tagad to var izmantot, lai pieteiktos.',
            'not_you' => 'Ja tas nebijāt jūs, nekavējoties atveriet savu profilu un atvienojiet to.',
        ],
        'disconnected' => [
            'subject' => ':provider konts ir atvienots no jūsu konta',
            'line' => 'No jūsu RateGuru konta tika atvienots :provider konts. To vairs nevar izmantot, lai pieteiktos.',
            'not_you' => 'Ja tas nebijāt jūs, piesakieties un pārbaudiet savus savienotos kontus un paroli.',
        ],
    ],
];
