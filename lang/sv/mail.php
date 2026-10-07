<?php

return [
    'greeting' => 'Hej :name!',
    'salutation' => 'Vänliga hälsningar, :app',
    'fallback_greeting' => 'Hej!',
    'error_greeting' => 'Hoppsan!',
    'rights_reserved' => 'Alla rättigheter förbehållna.',
    'action_fallback' => 'Om knappen ”:action” inte fungerar kan du kopiera länken nedan och klistra in den i din webbläsare:',

    'verify' => [
        'subject' => 'Bekräfta din e-postadress',
        'line' => 'Bekräfta din e-postadress för att slutföra konfigurationen av ditt konto.',
        'action' => 'Bekräfta e-postadress',
        'ignore' => 'Om du inte har skapat något konto behöver du inte göra något.',
    ],

    'reset' => [
        'subject' => 'Återställ ditt lösenord',
        'line' => 'Du får det här mejlet eftersom vi har tagit emot en begäran om att återställa lösenordet för ditt konto.',
        'action' => 'Återställ lösenord',
        'expire' => 'Länken för att återställa lösenordet slutar gälla om :count minuter.',
        'ignore' => 'Om du inte har begärt att återställa ditt lösenord behöver du inte göra något.',
    ],

    'contact' => [
        'subject' => 'Nytt kontaktmeddelande: :subject',
        'heading' => 'Nytt kontaktmeddelande',
        'name' => 'Namn',
        'email' => 'E-post',
        'message_subject' => 'Ämne',
        'body' => 'Meddelande',
    ],

    'social' => [
        'account' => ':provider-konto: :email',
        'action' => 'Granska kopplade konton',
        'connected' => [
            'subject' => ':provider har kopplats till ditt konto',
            'line' => 'Ett :provider-konto har kopplats till ditt RateGuru-konto. Det kan nu användas för att logga in.',
            'not_you' => 'Om det inte var du, öppna din profil och koppla bort det direkt.',
        ],
        'disconnected' => [
            'subject' => ':provider har kopplats bort från ditt konto',
            'line' => 'Ett :provider-konto har kopplats bort från ditt RateGuru-konto. Det kan inte längre användas för att logga in.',
            'not_you' => 'Om det inte var du, logga in och kontrollera dina kopplade konton och ditt lösenord.',
        ],
    ],
];
