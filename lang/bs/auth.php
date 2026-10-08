<?php

return [
    'failed' => 'Ovi podaci za prijavu ne odgovaraju našim zapisima.',
    'password' => 'Unesena lozinka nije tačna.',
    'throttle' => 'Previše pokušaja prijave. Pokušaj ponovo za :seconds s.',
    'username_unavailable' => 'Nije moguće kreirati jedinstveno korisničko ime. Pokušaj s drugim imenom.',
    'session_ended' => 'Tvoja sesija je završena jer su se podaci za prijavu na ovaj račun promijenili. Prijavi se ponovo.',

    'prompts' => [
        'no_account' => 'Nemaš račun?',
        'have_account' => 'Već imaš račun?',
    ],

    'fields' => [
        'name' => 'Ime',
        'username' => 'Korisničko ime',
        'email' => 'E-mail',
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
        'action' => 'Registruj se',
    ],

    'divider' => 'ili',

    'forgot_password' => [
        'intro' => 'Zaboravljena lozinka? Nema problema. Upiši svoju e-mail adresu i poslat ćemo ti link za poništavanje lozinke pomoću kojeg možeš odabrati novu.',
        'action' => 'Pošalji link za poništavanje lozinke',
    ],

    'reset_password' => [
        'action' => 'Poništi lozinku',
    ],

    'confirm_password' => [
        'intro' => 'Ovo je zaštićeni dio aplikacije. Prije nastavka potvrdi svoju lozinku.',
        'action' => 'Potvrdi',
    ],

    'verify_email' => [
        'intro' => 'Hvala na registraciji! Prije nego što počneš, potvrdi svoju e-mail adresu klikom na link koji smo ti upravo poslali. Ako e-mail nije stigao, rado ćemo ti poslati novi.',
        'link_sent' => 'Novi link za potvrdu poslan je na e-mail adresu navedenu prilikom registracije.',
        'resend' => 'Ponovo pošalji e-mail za potvrdu',
    ],

    'social' => [
        'log_in_with' => 'Prijavi se putem :provider',
        'unavailable' => 'Prijava putem :provider trenutno nije dostupna.',
        'unavailable_notice' => 'Prijava putem :provider je isključena. Ako ti je :provider ranije služio za prijavu, postavi lozinku za svoj račun — poslat ćemo ti link e-mailom.',
        'unavailable_set_password' => 'Postavi lozinku',
        'cancelled' => 'Prijava putem :provider je otkazana. Pokušaj ponovo.',
        'failed' => 'Nismo te uspjeli prijaviti putem :provider. Pokušaj ponovo.',
        'expired' => 'Tvoja prijava putem :provider je istekla prije završetka. Počni ispočetka.',
        'email_missing' => 'Tvoj :provider račun nije podijelio e-mail adresu, pa se ne može koristiti za prijavu.',
        'email_taken' => 'E-mail adresa ovog :provider računa pripada drugom RateGuru računu. Prijavi se na taj račun i tamo poveži :provider.',
        'link_expired' => 'Sesija povezivanja računa je istekla ili se promijenila. Ponovo pokreni povezivanje računa.',
        'already_signed_in' => 'Već imaš aktivnu prijavu. Ako se želiš prijavljivati i putem :provider, poveži ga u odjeljku „Povezani računi“.',
        'already_linked' => 'Ovaj :provider račun je već povezan s drugim računom.',
        'provider_already_linked' => 'Tvoj račun je već povezan s drugim :provider računom.',
        'pending_link' => 'Za ovu e-mail adresu već postoji račun. Prijavi se kao i ranije — lozinkom ili drugim društvenim računom — i tvoj :provider račun bit će automatski povezan.',
        'password_removed' => 'Tvoj e-mail je potvrđen putem :provider. Lozinka koja je bila postavljena za ovu e-mail adresu je uklonjena — novu možeš postaviti u svom profilu.',
    ],
];
