<?php

return [
    'failed' => 'Disse loginoplysninger matcher ikke vores registreringer.',
    'password' => 'Den angivne adgangskode er forkert.',
    'throttle' => 'For mange loginforsøg. Prøv igen om :seconds sekunder.',
    'username_unavailable' => 'Der kunne ikke oprettes et unikt brugernavn. Prøv et andet navn.',
    'session_ended' => 'Din session er afsluttet, fordi loginoplysningerne for denne konto er blevet ændret. Log ind igen.',

    'prompts' => [
        'no_account' => 'Har du ikke en konto?',
        'have_account' => 'Har du allerede en konto?',
    ],

    'fields' => [
        'name' => 'Navn',
        'username' => 'Brugernavn',
        'email' => 'E-mail',
        'password' => 'Adgangskode',
        'password_confirmation' => 'Bekræft adgangskode',
    ],

    'login' => [
        'title' => 'Log ind',
        'action' => 'Log ind',
        'remember' => 'Husk mig',
        'forgot_password' => 'Har du glemt din adgangskode?',
    ],

    'register' => [
        'title' => 'Opret konto',
        'action' => 'Opret konto',
    ],

    'divider' => 'eller',

    'forgot_password' => [
        'intro' => 'Har du glemt din adgangskode? Intet problem. Oplys blot din e-mailadresse, så sender vi dig et link til nulstilling af adgangskoden, hvor du kan vælge en ny.',
        'action' => 'Send link til nulstilling af adgangskode',
    ],

    'reset_password' => [
        'action' => 'Nulstil adgangskode',
    ],

    'confirm_password' => [
        'intro' => 'Dette er et sikret område af applikationen. Bekræft din adgangskode, før du fortsætter.',
        'action' => 'Bekræft',
    ],

    'verify_email' => [
        'intro' => 'Tak, fordi du har oprettet en konto! Før du går i gang, vil du så bekræfte din e-mailadresse ved at klikke på linket, vi lige har sendt til dig? Hvis du ikke har modtaget e-mailen, sender vi gerne en ny.',
        'link_sent' => 'Et nyt bekræftelseslink er blevet sendt til den e-mailadresse, du angav ved registreringen.',
        'resend' => 'Send bekræftelsesmail igen',
    ],

    'social' => [
        'log_in_with' => 'Log ind med :provider',
        'unavailable' => 'Login med :provider er ikke tilgængeligt i øjeblikket.',
        'unavailable_notice' => 'Login med :provider er slået fra. Hvis du tidligere loggede ind med :provider, så opret en adgangskode til din konto — vi sender dig et link på e-mail.',
        'unavailable_set_password' => 'Opret en adgangskode',
        'cancelled' => 'Login med :provider blev annulleret. Prøv igen.',
        'failed' => 'Vi kunne ikke logge dig ind med :provider. Prøv igen.',
        'expired' => 'Dit login med :provider udløb, før det blev gennemført. Start forfra.',
        'email_missing' => 'Din :provider-konto delte ikke en e-mailadresse, så den kan ikke bruges til at logge ind.',
        'email_taken' => 'E-mailadressen for denne :provider-konto tilhører en anden RateGuru-konto. Log ind på den konto for at forbinde :provider der.',
        'link_expired' => 'Sessionen for forbindelse af din konto er udløbet eller er blevet ændret. Start forbindelsen af kontoen forfra.',
        'already_signed_in' => 'Du er allerede logget ind. Hvis du også vil logge ind med :provider, så forbind det under Forbundne konti.',
        'already_linked' => 'Denne :provider-konto er allerede forbundet med en anden konto.',
        'provider_already_linked' => 'Din konto er allerede forbundet med en anden :provider-konto.',
        'pending_link' => 'Der findes allerede en konto med denne e-mailadresse. Log ind, som du plejer — med din adgangskode eller med en anden social konto — så bliver din :provider-konto forbundet automatisk.',
        'password_removed' => 'Din e-mail er bekræftet via :provider. En adgangskode, der var oprettet for denne e-mailadresse, er blevet fjernet — du kan oprette en ny på din profil.',
    ],
];
