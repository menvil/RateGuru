<?php

return [
    'failed' => 'Tyto přihlašovací údaje neodpovídají našim záznamům.',
    'password' => 'Zadané heslo je nesprávné.',
    'throttle' => 'Příliš mnoho pokusů o přihlášení. Zkus to prosím znovu za :seconds s.',
    'username_unavailable' => 'Nepodařilo se vytvořit jedinečné uživatelské jméno. Zkus prosím jiné jméno.',
    'session_ended' => 'Tvoje relace skončila, protože se změnily přihlašovací údaje tohoto účtu. Přihlas se prosím znovu.',

    'prompts' => [
        'no_account' => 'Nemáš účet?',
        'have_account' => 'Už máš účet?',
    ],

    'fields' => [
        'name' => 'Jméno',
        'username' => 'Uživatelské jméno',
        'email' => 'E-mail',
        'password' => 'Heslo',
        'password_confirmation' => 'Potvrzení hesla',
    ],

    'login' => [
        'title' => 'Přihlášení',
        'action' => 'Přihlásit se',
        'remember' => 'Zapamatovat si mě',
        'forgot_password' => 'Zapomenuté heslo?',
    ],

    'register' => [
        'title' => 'Registrace',
        'action' => 'Zaregistrovat se',
    ],

    'divider' => 'nebo',

    'forgot_password' => [
        'intro' => 'Zapomenuté heslo? Žádný problém. Zadej svou e-mailovou adresu a pošleme ti odkaz pro obnovení hesla, pomocí kterého si nastavíš nové.',
        'action' => 'Poslat odkaz pro obnovení hesla',
    ],

    'reset_password' => [
        'action' => 'Obnovit heslo',
    ],

    'confirm_password' => [
        'intro' => 'Toto je zabezpečená část aplikace. Před pokračováním prosím potvrď své heslo.',
        'action' => 'Potvrdit',
    ],

    'verify_email' => [
        'intro' => 'Díky za registraci! Než začneš, ověř prosím svou e-mailovou adresu kliknutím na odkaz, který jsme ti právě poslali. Pokud ti e-mail nepřišel, rádi ti pošleme další.',
        'link_sent' => 'Na e-mailovou adresu zadanou při registraci byl odeslán nový ověřovací odkaz.',
        'resend' => 'Znovu poslat ověřovací e-mail',
    ],

    'social' => [
        'log_in_with' => 'Přihlásit se přes :provider',
        'unavailable' => 'Přihlášení přes :provider je momentálně nedostupné.',
        'unavailable_notice' => 'Přihlášení přes :provider je vypnuté. Pokud k přihlašování používáš :provider, nastav si pro svůj účet heslo — pošleme ti odkaz e-mailem.',
        'unavailable_set_password' => 'Nastavit heslo',
        'cancelled' => 'Přihlášení přes :provider bylo zrušeno. Zkus to prosím znovu.',
        'failed' => 'Přihlášení přes :provider se nezdařilo. Zkus to prosím znovu.',
        'expired' => 'Přihlášení přes :provider vypršelo dřív, než bylo dokončeno. Začni prosím znovu.',
        'email_missing' => 'Tvůj účet :provider nesdílí e-mailovou adresu, takže ho nelze použít k přihlášení.',
        'email_taken' => 'E-mailová adresa tohoto účtu :provider patří jinému účtu RateGuru. Přihlas se k tomuto účtu a propoj :provider tam.',
        'link_expired' => 'Relace pro propojení účtu vypršela nebo se změnila. Začni prosím propojení účtu znovu.',
        'already_signed_in' => 'Už máš aktivní přihlášení. Pokud se chceš přihlašovat také přes :provider, propoj ho v sekci Propojené účty.',
        'already_linked' => 'Tento účet :provider je už propojený s jiným účtem.',
        'provider_already_linked' => 'Tvůj účet je už propojený s jiným účtem :provider.',
        'pending_link' => 'K této e-mailové adrese už existuje účet. Přihlas se stejně jako dříve — heslem nebo jiným účtem sociální sítě — a tvůj účet :provider se propojí automaticky.',
        'password_removed' => 'Tvůj e-mail je potvrzený přes :provider. Heslo, které bylo pro tuto e-mailovou adresu nastavené, bylo odstraněno — nové si můžeš nastavit ve svém profilu.',
    ],
];
