<?php

return [
    'failed' => 'Ezek az adatok nem egyeznek a nyilvántartásunkkal.',
    'password' => 'A megadott jelszó helytelen.',
    'throttle' => 'Túl sok bejelentkezési kísérlet. Próbáld újra :seconds másodperc múlva.',
    'username_unavailable' => 'Nem sikerült egyedi felhasználónevet létrehozni. Próbálkozz egy másik névvel.',
    'session_ended' => 'A munkameneted véget ért, mert megváltoztak a fiók bejelentkezési adatai. Jelentkezz be újra.',

    'prompts' => [
        'no_account' => 'Még nincs fiókod?',
        'have_account' => 'Már van fiókod?',
    ],

    'fields' => [
        'name' => 'Név',
        'username' => 'Felhasználónév',
        'email' => 'E-mail-cím',
        'password' => 'Jelszó',
        'password_confirmation' => 'Jelszó megerősítése',
    ],

    'login' => [
        'title' => 'Bejelentkezés',
        'action' => 'Bejelentkezés',
        'remember' => 'Emlékezz rám',
        'forgot_password' => 'Elfelejtetted a jelszavad?',
    ],

    'register' => [
        'title' => 'Regisztráció',
        'action' => 'Regisztráció',
    ],

    'divider' => 'vagy',

    'forgot_password' => [
        'intro' => 'Elfelejtetted a jelszavad? Semmi gond. Add meg az e-mail-címed, és küldünk egy jelszó-visszaállító linket, amellyel új jelszót választhatsz.',
        'action' => 'Jelszó-visszaállító link küldése',
    ],

    'reset_password' => [
        'action' => 'Jelszó visszaállítása',
    ],

    'confirm_password' => [
        'intro' => 'Ez az alkalmazás védett területe. A folytatás előtt erősítsd meg a jelszavad.',
        'action' => 'Megerősítés',
    ],

    'verify_email' => [
        'intro' => 'Köszönjük, hogy regisztráltál! Mielőtt belekezdenél, megerősítenéd az e-mail-címed az imént elküldött levélben található linkre kattintva? Ha nem kaptad meg a levelet, szívesen küldünk egy újat.',
        'link_sent' => 'Új megerősítő linket küldtünk a regisztrációkor megadott e-mail-címre.',
        'resend' => 'Megerősítő e-mail újraküldése',
    ],

    'social' => [
        'log_in_with' => 'Bejelentkezés :provider-fiókkal',
        'unavailable' => 'A bejelentkezés :provider-fiókkal jelenleg nem érhető el.',
        'unavailable_notice' => 'A bejelentkezés :provider-fiókkal ki van kapcsolva. Ha eddig :provider-fiókkal jelentkeztél be, állíts be jelszót a fiókodhoz – e-mailben küldünk hozzá egy linket.',
        'unavailable_set_password' => 'Jelszó beállítása',
        'cancelled' => 'A bejelentkezés :provider-fiókkal megszakadt. Próbáld újra.',
        'failed' => 'Nem sikerült bejelentkeztetnünk :provider-fiókkal. Próbáld újra.',
        'expired' => 'A bejelentkezés :provider-fiókkal lejárt, mielőtt befejeződött volna. Kezdd újra.',
        'email_missing' => 'A :provider-fiókod nem osztott meg e-mail-címet, ezért nem használható bejelentkezéshez.',
        'email_taken' => 'Ennek a :provider-fióknak az e-mail-címe egy másik RateGuru-fiókhoz tartozik. Jelentkezz be abba a fiókba, és ott kapcsold össze a :provider-fiókot.',
        'link_expired' => 'A fiók-összekapcsolási munkamenet lejárt vagy megváltozott. Kezdd újra a fiók összekapcsolását.',
        'already_signed_in' => 'Már be vagy jelentkezve. Ha :provider-fiókkal is be szeretnél jelentkezni, kapcsold össze a „Kapcsolt fiókok” részben.',
        'already_linked' => 'Ez a :provider-fiók már egy másik fiókhoz van kapcsolva.',
        'provider_already_linked' => 'A fiókod már egy másik :provider-fiókhoz van kapcsolva.',
        'pending_link' => 'Ehhez az e-mail-címhez már tartozik fiók. Jelentkezz be úgy, mint korábban – a jelszavaddal vagy egy másik közösségi fiókkal –, és a :provider-fiókod automatikusan összekapcsolódik vele.',
        'password_removed' => 'Az e-mail-címed a :provider-fiókodon keresztül meg van erősítve. Az ehhez az e-mail-címhez korábban beállított jelszót eltávolítottuk – a profilodban újat állíthatsz be.',
    ],
];
