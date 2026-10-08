<?php

return [
    'failed' => 'Ovi podaci za prijavu ne odgovaraju našim zapisima.',
    'password' => 'Unesena lozinka nije ispravna.',
    'throttle' => 'Previše pokušaja prijave. Pokušaj ponovno za :seconds s.',
    'username_unavailable' => 'Nije moguće stvoriti jedinstveno korisničko ime. Pokušaj s drugim imenom.',
    'session_ended' => 'Tvoja je sesija završila jer su se podaci za prijavu u ovaj račun promijenili. Prijavi se ponovno.',

    'prompts' => [
        'no_account' => 'Nemaš račun?',
        'have_account' => 'Već imaš račun?',
    ],

    'fields' => [
        'name' => 'Ime',
        'username' => 'Korisničko ime',
        'email' => 'E-pošta',
        'password' => 'Lozinka',
        'password_confirmation' => 'Potvrdi lozinku',
    ],

    'login' => [
        'title' => 'Prijava',
        'action' => 'Prijavi se',
        'remember' => 'Zapamti me',
        'forgot_password' => 'Zaboravljena lozinka?',
    ],

    'register' => [
        'title' => 'Registracija',
        'action' => 'Registriraj se',
    ],

    'divider' => 'ili',

    'forgot_password' => [
        'intro' => 'Zaboravljena lozinka? Nema problema. Upiši svoju adresu e-pošte i poslat ćemo ti poveznicu za ponovno postavljanje lozinke kojom možeš odabrati novu.',
        'action' => 'Pošalji poveznicu za ponovno postavljanje lozinke',
    ],

    'reset_password' => [
        'action' => 'Ponovno postavi lozinku',
    ],

    'confirm_password' => [
        'intro' => 'Ovo je zaštićeni dio aplikacije. Prije nastavka potvrdi svoju lozinku.',
        'action' => 'Potvrdi',
    ],

    'verify_email' => [
        'intro' => 'Hvala na registraciji! Prije početka potvrdi svoju adresu e-pošte klikom na poveznicu koju smo ti upravo poslali. Ako e-poruku nisi primio, rado ćemo ti poslati novu.',
        'link_sent' => 'Nova poveznica za potvrdu poslana je na adresu e-pošte koju si naveo pri registraciji.',
        'resend' => 'Ponovno pošalji e-poruku za potvrdu',
    ],

    'social' => [
        'log_in_with' => 'Prijavi se putem usluge :provider',
        'unavailable' => 'Prijava putem usluge :provider trenutačno nije dostupna.',
        'unavailable_notice' => 'Prijava putem usluge :provider isključena je. Ako si se prije prijavljivao putem usluge :provider, postavi lozinku za svoj račun — poslat ćemo ti poveznicu e-poštom.',
        'unavailable_set_password' => 'Postavi lozinku',
        'cancelled' => 'Prijava putem usluge :provider je otkazana. Pokušaj ponovno.',
        'failed' => 'Nismo te uspjeli prijaviti putem usluge :provider. Pokušaj ponovno.',
        'expired' => 'Prijava putem usluge :provider istekla je prije nego što je dovršena. Počni ispočetka.',
        'email_missing' => 'Tvoj račun na usluzi :provider nije podijelio adresu e-pošte pa se ne može koristiti za prijavu.',
        'email_taken' => 'Adresa e-pošte ovog računa na usluzi :provider pripada drugom RateGuru računu. Prijavi se u taj račun i ondje poveži :provider.',
        'link_expired' => 'Sesija povezivanja računa istekla je ili se promijenila. Ponovno započni povezivanje računa.',
        'already_signed_in' => 'Već si prijavljen. Ako se želiš prijavljivati i putem usluge :provider, poveži je u odjeljku „Povezani računi“.',
        'already_linked' => 'Ovaj račun na usluzi :provider već je povezan s drugim računom.',
        'provider_already_linked' => 'Tvoj je račun već povezan s drugim računom na usluzi :provider.',
        'pending_link' => 'Za ovu adresu e-pošte već postoji račun. Prijavi se kao i prije — lozinkom ili drugim društvenim računom — i tvoj račun na usluzi :provider bit će automatski povezan.',
        'password_removed' => 'Tvoja je adresa e-pošte potvrđena putem usluge :provider. Lozinka koja je bila postavljena za ovu adresu e-pošte uklonjena je — novu možeš postaviti u svom profilu.',
    ],
];
