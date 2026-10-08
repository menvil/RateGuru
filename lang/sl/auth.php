<?php

return [
    'failed' => 'Ti podatki za prijavo se ne ujemajo z našimi zapisi.',
    'password' => 'Vneseno geslo je napačno.',
    'throttle' => 'Preveč poskusov prijave. Poskusi znova čez :seconds s.',
    'username_unavailable' => 'Edinstvenega uporabniškega imena ni bilo mogoče ustvariti. Poskusi z drugim imenom.',
    'session_ended' => 'Tvoja seja se je končala, ker so se podatki za prijavo v ta račun spremenili. Prijavi se znova.',

    'prompts' => [
        'no_account' => 'Še nimaš računa?',
        'have_account' => 'Že imaš račun?',
    ],

    'fields' => [
        'name' => 'Ime',
        'username' => 'Uporabniško ime',
        'email' => 'E-pošta',
        'password' => 'Geslo',
        'password_confirmation' => 'Potrdi geslo',
    ],

    'login' => [
        'title' => 'Prijava',
        'action' => 'Prijavi se',
        'remember' => 'Zapomni si me',
        'forgot_password' => 'Pozabljeno geslo?',
    ],

    'register' => [
        'title' => 'Registracija',
        'action' => 'Registriraj se',
    ],

    'divider' => 'ali',

    'forgot_password' => [
        'intro' => 'Pozabljeno geslo? Ni težav. Vpiši svoj e-poštni naslov in poslali ti bomo povezavo za ponastavitev, s katero lahko nastaviš novo geslo.',
        'action' => 'Pošlji povezavo za ponastavitev gesla',
    ],

    'reset_password' => [
        'action' => 'Ponastavi geslo',
    ],

    'confirm_password' => [
        'intro' => 'To je zaščiteno območje aplikacije. Pred nadaljevanjem potrdi svoje geslo.',
        'action' => 'Potrdi',
    ],

    'verify_email' => [
        'intro' => 'Hvala za registracijo! Preden začneš, potrdi svoj e-poštni naslov s klikom na povezavo, ki smo ti jo pravkar poslali. Če e-pošta ni prispela, ti bomo z veseljem poslali novo.',
        'link_sent' => 'Nova povezava za potrditev je bila poslana na e-poštni naslov, naveden ob registraciji.',
        'resend' => 'Znova pošlji potrditveno e-pošto',
    ],

    'social' => [
        'log_in_with' => 'Prijava s storitvijo :provider',
        'unavailable' => 'Prijava s storitvijo :provider trenutno ni na voljo.',
        'unavailable_notice' => 'Prijava s storitvijo :provider je izklopljena. Če se prijavljaš s storitvijo :provider, nastavi geslo za svoj račun — povezavo ti bomo poslali po e-pošti.',
        'unavailable_set_password' => 'Nastavi geslo',
        'cancelled' => 'Prijava s storitvijo :provider je bila preklicana. Poskusi znova.',
        'failed' => 'Prijava s storitvijo :provider ni uspela. Poskusi znova.',
        'expired' => 'Prijava s storitvijo :provider je potekla, preden se je zaključila. Začni znova.',
        'email_missing' => 'Tvoj račun :provider ni delil e-poštnega naslova, zato ga ni mogoče uporabiti za prijavo.',
        'email_taken' => 'E-poštni naslov tega računa :provider pripada drugemu računu RateGuru. Prijavi se v tisti račun in tam poveži storitev :provider.',
        'link_expired' => 'Seja za povezovanje računa je potekla ali se je spremenila. Znova začni povezovati račun.',
        'already_signed_in' => 'Prijava je že aktivna. Če se želiš prijavljati tudi s storitvijo :provider, jo poveži v razdelku Povezani računi.',
        'already_linked' => 'Ta račun :provider je že povezan z drugim računom.',
        'provider_already_linked' => 'Tvoj račun je že povezan z drugim računom :provider.',
        'pending_link' => 'Za ta e-poštni naslov račun že obstaja. Prijavi se tako kot prej — z geslom ali z drugim družbenim računom — in tvoj račun :provider bo samodejno povezan.',
        'password_removed' => 'Tvoj e-poštni naslov je potrjen prek storitve :provider. Geslo, ki je bilo nastavljeno za ta e-poštni naslov, je bilo odstranjeno — novega lahko nastaviš v svojem profilu.',
    ],
];
