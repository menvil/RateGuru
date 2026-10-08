<?php

return [
    'greeting' => 'Hej, :name!',
    'salutation' => 'Venlig hilsen :app',
    'fallback_greeting' => 'Hej!',
    'error_greeting' => 'Hovsa!',
    'rights_reserved' => 'Alle rettigheder forbeholdes.',
    'action_fallback' => 'Hvis du har problemer med at klikke på knappen „:action“, så kopiér URL’en herunder, og indsæt den i din webbrowser:',

    'verify' => [
        'subject' => 'Bekræft din e-mailadresse',
        'line' => 'Bekræft din e-mailadresse for at gøre oprettelsen af din konto færdig.',
        'action' => 'Bekræft e-mailadresse',
        'ignore' => 'Hvis du ikke har oprettet en konto, behøver du ikke gøre noget.',
    ],

    'reset' => [
        'subject' => 'Nulstil din adgangskode',
        'line' => 'Du modtager denne e-mail, fordi vi har modtaget en anmodning om nulstilling af adgangskoden til din konto.',
        'action' => 'Nulstil adgangskode',
        'expire' => 'Dette link til nulstilling af adgangskoden udløber om :count minutter.',
        'ignore' => 'Hvis du ikke har bedt om at nulstille din adgangskode, behøver du ikke gøre noget.',
    ],

    'contact' => [
        'subject' => 'Ny kontaktbesked: :subject',
        'heading' => 'Ny kontaktbesked',
        'name' => 'Navn',
        'email' => 'E-mail',
        'message_subject' => 'Emne',
        'body' => 'Besked',
    ],

    'social' => [
        'account' => ':provider-konto: :email',
        'action' => 'Gennemgå forbundne konti',
        'connected' => [
            'subject' => ':provider er blevet forbundet med din konto',
            'line' => 'En :provider-konto er blevet forbundet med din RateGuru-konto. Den kan nu bruges til at logge ind.',
            'not_you' => 'Hvis det ikke var dig, så åbn din profil, og afbryd forbindelsen med det samme.',
        ],
        'disconnected' => [
            'subject' => 'Forbindelsen mellem :provider og din konto er blevet afbrudt',
            'line' => 'Forbindelsen mellem en :provider-konto og din RateGuru-konto er blevet afbrudt. Den kan ikke længere bruges til at logge ind.',
            'not_you' => 'Hvis det ikke var dig, så log ind, og tjek dine forbundne konti og din adgangskode.',
        ],
    ],
];
