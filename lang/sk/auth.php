<?php

return [
    'failed' => 'Tieto prihlasovacie údaje sa nezhodujú s našimi záznamami.',
    'password' => 'Zadané heslo je nesprávne.',
    'throttle' => 'Príliš veľa pokusov o prihlásenie. Skús to znova o :seconds s.',
    'username_unavailable' => 'Nepodarilo sa vytvoriť jedinečné používateľské meno. Skús iné meno.',
    'session_ended' => 'Tvoja relácia sa skončila, pretože sa zmenili prihlasovacie údaje tohto účtu. Prihlás sa znova.',

    'prompts' => [
        'no_account' => 'Nemáš účet?',
        'have_account' => 'Už máš účet?',
    ],

    'fields' => [
        'name' => 'Meno',
        'username' => 'Používateľské meno',
        'email' => 'E-mail',
        'password' => 'Heslo',
        'password_confirmation' => 'Potvrdenie hesla',
    ],

    'login' => [
        'title' => 'Prihlásenie',
        'action' => 'Prihlásiť sa',
        'remember' => 'Zapamätať si ma',
        'forgot_password' => 'Zabudnuté heslo?',
    ],

    'register' => [
        'title' => 'Registrácia',
        'action' => 'Zaregistrovať sa',
    ],

    'divider' => 'alebo',

    'forgot_password' => [
        'intro' => 'Zabudnuté heslo? Žiadny problém. Zadaj svoju e-mailovú adresu a pošleme ti odkaz na obnovenie hesla, pomocou ktorého si nastavíš nové.',
        'action' => 'Poslať odkaz na obnovenie hesla',
    ],

    'reset_password' => [
        'action' => 'Obnoviť heslo',
    ],

    'confirm_password' => [
        'intro' => 'Toto je zabezpečená časť aplikácie. Pred pokračovaním potvrď svoje heslo.',
        'action' => 'Potvrdiť',
    ],

    'verify_email' => [
        'intro' => 'Vďaka za registráciu! Skôr než začneš, over svoju e-mailovú adresu kliknutím na odkaz, ktorý sme ti práve poslali. Ak ti e-mail neprišiel, radi ti pošleme ďalší.',
        'link_sent' => 'Na e-mailovú adresu uvedenú pri registrácii sme poslali nový overovací odkaz.',
        'resend' => 'Znova poslať overovací e-mail',
    ],

    'social' => [
        'log_in_with' => 'Prihlásiť sa cez :provider',
        'unavailable' => 'Prihlásenie cez :provider je momentálne nedostupné.',
        'unavailable_notice' => 'Prihlásenie cez :provider je vypnuté. Ak sa prihlasuješ cez :provider, nastav si pre svoj účet heslo — pošleme ti odkaz e-mailom.',
        'unavailable_set_password' => 'Nastaviť heslo',
        'cancelled' => 'Prihlásenie cez :provider bolo zrušené. Skús to znova.',
        'failed' => 'Nepodarilo sa ťa prihlásiť cez :provider. Skús to znova.',
        'expired' => 'Platnosť prihlásenia cez :provider vypršala skôr, než sa dokončilo. Začni znova.',
        'email_missing' => 'Tvoj účet :provider nesprístupnil e-mailovú adresu, preto ho nemožno použiť na prihlásenie.',
        'email_taken' => 'E-mailová adresa tohto účtu :provider patrí inému účtu RateGuru. Prihlás sa do toho účtu a prepoj :provider tam.',
        'link_expired' => 'Relácia prepojenia účtu vypršala alebo sa zmenila. Začni prepájať účet znova.',
        'already_signed_in' => 'Už si prihlásený. Ak sa chceš prihlasovať aj cez :provider, prepoj ho v sekcii Prepojené účty.',
        'already_linked' => 'Tento účet :provider je už prepojený s iným účtom.',
        'provider_already_linked' => 'Tvoj účet je už prepojený s iným účtom :provider.',
        'pending_link' => 'K tejto e-mailovej adrese už existuje účet. Prihlás sa tak ako predtým — heslom alebo iným sociálnym účtom — a tvoj účet :provider sa prepojí automaticky.',
        'password_removed' => 'Tvoj e-mail je potvrdený cez :provider. Heslo, ktoré bolo nastavené pre túto e-mailovú adresu, bolo odstránené — nové si môžeš nastaviť vo svojom profile.',
    ],
];
