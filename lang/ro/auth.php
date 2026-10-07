<?php

return [
    'failed' => 'Aceste date de autentificare nu se potrivesc cu înregistrările noastre.',
    'password' => 'Parola introdusă este incorectă.',
    'throttle' => 'Prea multe încercări de autentificare. Încearcă din nou peste :seconds secunde.',
    'username_unavailable' => 'Nu s-a putut crea un nume de utilizator unic. Încearcă un alt nume.',
    'session_ended' => 'Sesiunea ta s-a încheiat deoarece datele de autentificare ale acestui cont s-au schimbat. Autentifică-te din nou.',

    'prompts' => [
        'no_account' => 'Nu ai cont?',
        'have_account' => 'Ai deja cont?',
    ],

    'fields' => [
        'name' => 'Nume',
        'username' => 'Nume de utilizator',
        'email' => 'E-mail',
        'password' => 'Parolă',
        'password_confirmation' => 'Confirmă parola',
    ],

    'login' => [
        'title' => 'Autentificare',
        'action' => 'Autentifică-te',
        'remember' => 'Ține-mă minte',
        'forgot_password' => 'Ai uitat parola?',
    ],

    'register' => [
        'title' => 'Înregistrare',
        'action' => 'Înregistrează-te',
    ],

    'divider' => 'sau',

    'forgot_password' => [
        'intro' => 'Ai uitat parola? Nicio problemă. Spune-ne adresa ta de e-mail și îți vom trimite un link de resetare a parolei, cu care îți poți alege una nouă.',
        'action' => 'Trimite linkul de resetare a parolei',
    ],

    'reset_password' => [
        'action' => 'Resetează parola',
    ],

    'confirm_password' => [
        'intro' => 'Aceasta este o zonă securizată a aplicației. Confirmă-ți parola înainte de a continua.',
        'action' => 'Confirmă',
    ],

    'verify_email' => [
        'intro' => 'Mulțumim că te-ai înregistrat! Înainte de a începe, te rugăm să îți confirmi adresa de e-mail accesând linkul pe care tocmai ți l-am trimis. Dacă nu ai primit e-mailul, îți vom trimite cu plăcere altul.',
        'link_sent' => 'Un nou link de confirmare a fost trimis la adresa de e-mail pe care ai indicat-o la înregistrare.',
        'resend' => 'Retrimite e-mailul de confirmare',
    ],

    'social' => [
        'log_in_with' => 'Autentifică-te cu :provider',
        'unavailable' => 'Autentificarea cu :provider nu este disponibilă momentan.',
        'unavailable_notice' => 'Autentificarea cu :provider este dezactivată. Dacă te autentificai cu :provider, setează o parolă pentru contul tău – îți vom trimite un link pe e-mail.',
        'unavailable_set_password' => 'Setează o parolă',
        'cancelled' => 'Autentificarea cu :provider a fost anulată. Încearcă din nou.',
        'failed' => 'Nu te-am putut autentifica cu :provider. Încearcă din nou.',
        'expired' => 'Autentificarea cu :provider a expirat înainte de a se finaliza. Începe din nou.',
        'email_missing' => 'Contul tău :provider nu a partajat o adresă de e-mail, așa că nu poate fi folosit pentru autentificare.',
        'email_taken' => 'Adresa de e-mail a acestui cont :provider aparține altui cont RateGuru. Autentifică-te în acel cont pentru a conecta :provider acolo.',
        'link_expired' => 'Sesiunea de conectare a contului a expirat sau s-a schimbat. Începe din nou conectarea contului.',
        'already_signed_in' => 'Ești deja autentificat. Pentru a te autentifica și cu :provider, conectează-l din „Conturi conectate”.',
        'already_linked' => 'Acest cont :provider este deja conectat la un alt cont.',
        'provider_already_linked' => 'Contul tău este deja conectat la un alt cont :provider.',
        'pending_link' => 'Există deja un cont pentru această adresă de e-mail. Autentifică-te ca înainte – cu parola sau cu un alt cont social – și contul tău :provider va fi conectat automat.',
        'password_removed' => 'Adresa ta de e-mail este confirmată prin :provider. O parolă care fusese setată pentru această adresă de e-mail a fost eliminată – poți seta una nouă în profilul tău.',
    ],
];
