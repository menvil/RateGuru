<?php

return [
    'greeting' => 'Ahoj, :name!',
    'salutation' => 'S pozdravem :app',
    'fallback_greeting' => 'Ahoj!',
    'error_greeting' => 'Jejda!',
    'rights_reserved' => 'Všechna práva vyhrazena.',
    'action_fallback' => 'Pokud ti nefunguje tlačítko „:action“, zkopíruj níže uvedenou adresu URL a vlož ji do webového prohlížeče:',

    'verify' => [
        'subject' => 'Potvrď svou e-mailovou adresu',
        'line' => 'Potvrď prosím svou e-mailovou adresu a dokonči nastavení účtu.',
        'action' => 'Potvrdit e-mailovou adresu',
        'ignore' => 'Pokud o vytvoření účtu nevíš, nemusíš nic dělat.',
    ],

    'reset' => [
        'subject' => 'Obnovení hesla',
        'line' => 'Tento e-mail ti posíláme, protože jsme obdrželi žádost o obnovení hesla k tvému účtu.',
        'action' => 'Obnovit heslo',
        'expire' => 'Platnost odkazu pro obnovení hesla vyprší za :count minut.',
        'ignore' => 'Pokud o obnovení hesla nevíš, nemusíš nic dělat.',
    ],

    'contact' => [
        'subject' => 'Nová zpráva z kontaktního formuláře: :subject',
        'heading' => 'Nová zpráva z kontaktního formuláře',
        'name' => 'Jméno',
        'email' => 'E-mail',
        'message_subject' => 'Předmět',
        'body' => 'Zpráva',
    ],

    'social' => [
        'account' => 'Účet :provider: :email',
        'action' => 'Zkontrolovat propojené účty',
        'connected' => [
            'subject' => 'Účet :provider byl propojen s tvým účtem',
            'line' => 'S tvým účtem RateGuru byl propojen účet :provider. Nyní ho lze použít k přihlášení.',
            'not_you' => 'Pokud o tom nevíš, otevři svůj profil a hned ho odpoj.',
        ],
        'disconnected' => [
            'subject' => 'Účet :provider byl odpojen od tvého účtu',
            'line' => 'Od tvého účtu RateGuru byl odpojen účet :provider. Už ho nelze použít k přihlášení.',
            'not_you' => 'Pokud o tom nevíš, přihlas se a zkontroluj své propojené účty a heslo.',
        ],
    ],
];
