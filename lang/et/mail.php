<?php

return [
    'greeting' => 'Tere, :name!',
    'salutation' => 'Parimate soovidega, :app',
    'fallback_greeting' => 'Tere!',
    'error_greeting' => 'Oih!',
    'rights_reserved' => 'Kõik õigused kaitstud.',
    'action_fallback' => 'Kui nupule „:action“ klõpsamine ei õnnestu, kopeeri allolev URL ja kleebi see oma veebibrauserisse:',

    'verify' => [
        'subject' => 'Kinnita oma e-posti aadress',
        'line' => 'Konto seadistamise lõpetamiseks kinnita oma e-posti aadress.',
        'action' => 'Kinnita e-posti aadress',
        'ignore' => 'Kui sa kontot ei loonud, ei pea sa midagi tegema.',
    ],

    'reset' => [
        'subject' => 'Lähtesta oma parool',
        'line' => 'Said selle e-kirja, sest meile saabus sinu konto parooli lähtestamise taotlus.',
        'action' => 'Lähtesta parool',
        'expire' => 'See parooli lähtestamise link aegub :count minuti pärast.',
        'ignore' => 'Kui sa parooli lähtestamist ei taotlenud, ei pea sa midagi tegema.',
    ],

    'contact' => [
        'subject' => 'Uus kontaktisõnum: :subject',
        'heading' => 'Uus kontaktisõnum',
        'name' => 'Nimi',
        'email' => 'E-post',
        'message_subject' => 'Teema',
        'body' => 'Sõnum',
    ],

    'social' => [
        'account' => ':provider konto: :email',
        'action' => 'Vaata ühendatud kontosid',
        'connected' => [
            'subject' => ':provider ühendati sinu kontoga',
            'line' => 'Sinu RateGuru kontoga ühendati :provider konto. Nüüd saab sellega sisse logida.',
            'not_you' => 'Kui see polnud sina, ava kohe oma profiil ja katkesta ühendus.',
        ],
        'disconnected' => [
            'subject' => ':provider ühendus sinu kontoga katkestati',
            'line' => 'Sinu RateGuru konto ja :provider konto ühendus katkestati. Sellega ei saa enam sisse logida.',
            'not_you' => 'Kui see polnud sina, logi sisse ning kontrolli oma ühendatud kontosid ja parooli.',
        ],
    ],
];
