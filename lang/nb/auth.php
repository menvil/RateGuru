<?php

return [
    'failed' => 'Påloggingsopplysningene stemmer ikke med våre registre.',
    'password' => 'Passordet du oppga er feil.',
    'throttle' => 'For mange påloggingsforsøk. Prøv igjen om :seconds sekunder.',
    'username_unavailable' => 'Kunne ikke opprette et unikt brukernavn. Prøv et annet navn.',
    'session_ended' => 'Økten din er avsluttet fordi påloggingsopplysningene for denne kontoen er endret. Logg inn på nytt.',

    'prompts' => [
        'no_account' => 'Har du ikke en konto?',
        'have_account' => 'Har du allerede en konto?',
    ],

    'fields' => [
        'name' => 'Navn',
        'username' => 'Brukernavn',
        'email' => 'E-post',
        'password' => 'Passord',
        'password_confirmation' => 'Bekreft passord',
    ],

    'login' => [
        'title' => 'Logg inn',
        'action' => 'Logg inn',
        'remember' => 'Husk meg',
        'forgot_password' => 'Glemt passordet?',
    ],

    'register' => [
        'title' => 'Registrer deg',
        'action' => 'Registrer deg',
    ],

    'divider' => 'eller',

    'forgot_password' => [
        'intro' => 'Glemt passordet? Ikke noe problem. Oppgi e-postadressen din, så sender vi deg en lenke der du kan velge et nytt passord.',
        'action' => 'Send lenke for tilbakestilling av passord',
    ],

    'reset_password' => [
        'action' => 'Tilbakestill passord',
    ],

    'confirm_password' => [
        'intro' => 'Dette er et sikret område av applikasjonen. Bekreft passordet ditt før du fortsetter.',
        'action' => 'Bekreft',
    ],

    'verify_email' => [
        'intro' => 'Takk for at du registrerte deg! Før du begynner, kan du bekrefte e-postadressen din ved å klikke på lenken vi nettopp sendte deg? Hvis du ikke har mottatt e-posten, sender vi deg gjerne en ny.',
        'link_sent' => 'En ny bekreftelseslenke er sendt til e-postadressen du oppga ved registreringen.',
        'resend' => 'Send bekreftelses-e-post på nytt',
    ],

    'social' => [
        'log_in_with' => 'Logg inn med :provider',
        'unavailable' => 'Innlogging med :provider er ikke tilgjengelig for øyeblikket.',
        'unavailable_notice' => 'Innlogging med :provider er slått av. Hvis du tidligere logget inn med :provider, kan du angi et passord for kontoen din – vi sender deg en lenke på e-post.',
        'unavailable_set_password' => 'Angi et passord',
        'cancelled' => 'Innloggingen med :provider ble avbrutt. Prøv igjen.',
        'failed' => 'Vi kunne ikke logge deg inn med :provider. Prøv igjen.',
        'expired' => 'Innloggingen med :provider utløp før den var fullført. Start på nytt.',
        'email_missing' => ':provider-kontoen din delte ingen e-postadresse, så den kan ikke brukes til å logge inn.',
        'email_taken' => 'E-postadressen til denne :provider-kontoen tilhører en annen RateGuru-konto. Logg inn på den kontoen for å koble til :provider der.',
        'link_expired' => 'Økten for kobling av kontoen utløp eller ble endret. Start koblingen av kontoen på nytt.',
        'already_signed_in' => 'Du er allerede logget inn. For også å kunne logge inn med :provider, koble den til under Tilkoblede kontoer.',
        'already_linked' => 'Denne :provider-kontoen er allerede koblet til en annen konto.',
        'provider_already_linked' => 'Kontoen din er allerede koblet til en annen :provider-konto.',
        'pending_link' => 'Det finnes allerede en konto med denne e-postadressen. Logg inn slik du gjorde før – med passordet ditt eller med en annen sosial konto – så kobles :provider-kontoen din til automatisk.',
        'password_removed' => 'E-postadressen din er bekreftet via :provider. Et passord som var angitt for denne e-postadressen, er fjernet – du kan angi et nytt i profilen din.',
    ],
];
