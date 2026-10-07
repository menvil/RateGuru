<?php

return [
    'failed' => 'Šie prisijungimo duomenys neatitinka mūsų turimų duomenų.',
    'password' => 'Nurodytas slaptažodis neteisingas.',
    'throttle' => 'Per daug bandymų prisijungti. Bandykite dar kartą po :seconds sek.',
    'username_unavailable' => 'Nepavyko sukurti unikalaus naudotojo vardo. Pabandykite kitą vardą.',
    'session_ended' => 'Jūsų sesija baigėsi, nes pasikeitė šios paskyros prisijungimo duomenys. Prisijunkite iš naujo.',

    'prompts' => [
        'no_account' => 'Neturite paskyros?',
        'have_account' => 'Jau turite paskyrą?',
    ],

    'fields' => [
        'name' => 'Vardas',
        'username' => 'Naudotojo vardas',
        'email' => 'El. paštas',
        'password' => 'Slaptažodis',
        'password_confirmation' => 'Pakartokite slaptažodį',
    ],

    'login' => [
        'title' => 'Prisijungimas',
        'action' => 'Prisijungti',
        'remember' => 'Prisiminti mane',
        'forgot_password' => 'Pamiršote slaptažodį?',
    ],

    'register' => [
        'title' => 'Registracija',
        'action' => 'Registruotis',
    ],

    'divider' => 'arba',

    'forgot_password' => [
        'intro' => 'Pamiršote slaptažodį? Nieko tokio. Nurodykite savo el. pašto adresą ir atsiųsime slaptažodžio atkūrimo nuorodą, kuria galėsite pasirinkti naują slaptažodį.',
        'action' => 'Siųsti slaptažodžio atkūrimo nuorodą',
    ],

    'reset_password' => [
        'action' => 'Atkurti slaptažodį',
    ],

    'confirm_password' => [
        'intro' => 'Tai saugi programos sritis. Prieš tęsdami patvirtinkite savo slaptažodį.',
        'action' => 'Patvirtinti',
    ],

    'verify_email' => [
        'intro' => 'Ačiū, kad užsiregistravote! Prieš pradėdami patvirtinkite savo el. pašto adresą paspausdami nuorodą, kurią ką tik išsiuntėme jums el. paštu. Jei laiško negavote, mielai atsiųsime kitą.',
        'link_sent' => 'Nauja patvirtinimo nuoroda išsiųsta el. pašto adresu, kurį nurodėte registruodamiesi.',
        'resend' => 'Siųsti patvirtinimo laišką dar kartą',
    ],

    'social' => [
        'log_in_with' => 'Prisijungti per :provider',
        'unavailable' => 'Prisijungimas per :provider šiuo metu negalimas.',
        'unavailable_notice' => 'Prisijungimas per :provider išjungtas. Jei anksčiau jungdavotės per :provider, nustatykite savo paskyros slaptažodį — atsiųsime jums nuorodą el. paštu.',
        'unavailable_set_password' => 'Nustatyti slaptažodį',
        'cancelled' => 'Prisijungimas per :provider atšauktas. Bandykite dar kartą.',
        'failed' => 'Nepavyko jūsų prijungti per :provider. Bandykite dar kartą.',
        'expired' => 'Prisijungimo per :provider laikas baigėsi anksčiau, nei jis buvo užbaigtas. Pradėkite iš naujo.',
        'email_missing' => 'Jūsų :provider paskyra nepateikė el. pašto adreso, todėl jos negalima naudoti prisijungimui.',
        'email_taken' => 'Šios :provider paskyros el. pašto adresas priklauso kitai RateGuru paskyrai. Prisijunkite prie tos paskyros ir susiekite :provider joje.',
        'link_expired' => 'Paskyros susiejimo sesija baigėsi arba pasikeitė. Pradėkite susieti paskyrą iš naujo.',
        'already_signed_in' => 'Jūs jau esate prisijungę. Kad galėtumėte prisijungti ir per :provider, susiekite jį skiltyje „Susietos paskyros“.',
        'already_linked' => 'Ši :provider paskyra jau susieta su kita paskyra.',
        'provider_already_linked' => 'Jūsų paskyra jau susieta su kita :provider paskyra.',
        'pending_link' => 'Šiuo el. pašto adresu jau užregistruota paskyra. Prisijunkite taip, kaip anksčiau — slaptažodžiu arba kita socialinio tinklo paskyra, — ir jūsų :provider paskyra bus susieta automatiškai.',
        'password_removed' => 'Jūsų el. paštas patvirtintas per :provider. Šiam el. pašto adresui anksčiau nustatytas slaptažodis pašalintas — naują galite nustatyti savo profilyje.',
    ],
];
