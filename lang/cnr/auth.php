<?php

return [
    'failed' => 'Ovi podaci za prijavu se ne poklapaju s našim podacima.',
    'password' => 'Unijeta lozinka nije tačna.',
    'throttle' => 'Previše pokušaja prijave. Pokušaj ponovo za :seconds sek.',
    'username_unavailable' => 'Nije moguće napraviti jedinstveno korisničko ime. Pokušaj s drugim imenom.',
    'session_ended' => 'Tvoja sesija je završena jer su promijenjeni podaci za prijavu na ovaj nalog. Prijavi se ponovo.',

    'prompts' => [
        'no_account' => 'Nemaš nalog?',
        'have_account' => 'Već imaš nalog?',
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
        'intro' => 'Zaboravljena lozinka? Nema problema. Unesi svoju e-mail adresu i poslaćemo ti link za resetovanje lozinke pomoću kojeg ćeš izabrati novu.',
        'action' => 'Pošalji link za resetovanje lozinke',
    ],

    'reset_password' => [
        'action' => 'Resetuj lozinku',
    ],

    'confirm_password' => [
        'intro' => 'Ovo je zaštićeni dio aplikacije. Prije nego što nastaviš, potvrdi svoju lozinku.',
        'action' => 'Potvrdi',
    ],

    'verify_email' => [
        'intro' => 'Hvala na registraciji! Prije nego što počneš, potvrdi svoju e-mail adresu klikom na link koji smo ti upravo poslali. Ako e-mail nije stigao, rado ćemo ti poslati novi.',
        'link_sent' => 'Novi link za potvrdu je poslat na e-mail adresu navedenu pri registraciji.',
        'resend' => 'Ponovo pošalji e-mail za potvrdu',
    ],

    'social' => [
        'log_in_with' => 'Prijavi se preko :provider',
        'unavailable' => 'Prijava preko :provider trenutno nije dostupna.',
        'unavailable_notice' => 'Prijava preko :provider je isključena. Ako ti je :provider ranije služio za prijavu, postavi lozinku za svoj nalog — poslaćemo ti link e-mailom.',
        'unavailable_set_password' => 'Postavi lozinku',
        'cancelled' => 'Prijava preko :provider je otkazana. Pokušaj ponovo.',
        'failed' => 'Nijesmo uspjeli da te prijavimo preko :provider. Pokušaj ponovo.',
        'expired' => 'Prijava preko :provider je istekla prije nego što je završena. Počni ponovo.',
        'email_missing' => 'Tvoj :provider nalog nije podijelio e-mail adresu, pa se ne može koristiti za prijavu.',
        'email_taken' => 'E-mail adresa ovog :provider naloga pripada drugom RateGuru nalogu. Prijavi se na taj nalog i tamo poveži :provider.',
        'link_expired' => 'Sesija povezivanja naloga je istekla ili je promijenjena. Počni povezivanje naloga ponovo.',
        'already_signed_in' => 'Već imaš aktivnu prijavu. Ako želiš da se prijavljuješ i preko :provider, poveži ga u odjeljku „Povezani nalozi“.',
        'already_linked' => 'Ovaj :provider nalog je već povezan s drugim nalogom.',
        'provider_already_linked' => 'Tvoj nalog je već povezan s drugim :provider nalogom.',
        'pending_link' => 'Za ovu e-mail adresu već postoji nalog. Prijavi se kao i ranije — lozinkom ili drugim društvenim nalogom — i tvoj :provider nalog biće automatski povezan.',
        'password_removed' => 'Tvoj e-mail je potvrđen preko :provider. Lozinka koja je bila postavljena za ovu e-mail adresu je uklonjena — novu možeš da postaviš u svom profilu.',
    ],
];
