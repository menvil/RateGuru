<?php

return [
    'failed' => 'Šie pieteikšanās dati neatbilst mūsu ierakstiem.',
    'password' => 'Norādītā parole nav pareiza.',
    'throttle' => 'Pārāk daudz pieteikšanās mēģinājumu. Lūdzu, mēģiniet vēlreiz pēc :seconds sek.',
    'username_unavailable' => 'Neizdevās izveidot unikālu lietotājvārdu. Lūdzu, izmēģiniet citu vārdu.',
    'session_ended' => 'Jūsu sesija ir beigusies, jo šī konta pieteikšanās dati ir mainīti. Lūdzu, piesakieties vēlreiz.',

    'prompts' => [
        'no_account' => 'Vai jums nav konta?',
        'have_account' => 'Vai jums jau ir konts?',
    ],

    'fields' => [
        'name' => 'Vārds',
        'username' => 'Lietotājvārds',
        'email' => 'E-pasts',
        'password' => 'Parole',
        'password_confirmation' => 'Apstipriniet paroli',
    ],

    'login' => [
        'title' => 'Pieteikšanās',
        'action' => 'Pieteikties',
        'remember' => 'Atcerēties mani',
        'forgot_password' => 'Aizmirsāt paroli?',
    ],

    'register' => [
        'title' => 'Reģistrācija',
        'action' => 'Reģistrēties',
    ],

    'divider' => 'vai',

    'forgot_password' => [
        'intro' => 'Aizmirsāt paroli? Nekas. Norādiet savu e-pasta adresi, un mēs nosūtīsim paroles atiestatīšanas saiti, ar kuru varēsiet izvēlēties jaunu paroli.',
        'action' => 'Nosūtīt paroles atiestatīšanas saiti',
    ],

    'reset_password' => [
        'action' => 'Atiestatīt paroli',
    ],

    'confirm_password' => [
        'intro' => 'Šī ir lietotnes drošā sadaļa. Lūdzu, pirms turpināt, apstipriniet savu paroli.',
        'action' => 'Apstiprināt',
    ],

    'verify_email' => [
        'intro' => 'Paldies, ka reģistrējāties! Pirms sākat, lūdzu, apstipriniet savu e-pasta adresi, noklikšķinot uz saites, ko tikko nosūtījām jums pa e-pastu. Ja e-pastu nesaņēmāt, mēs labprāt nosūtīsim vēl vienu.',
        'link_sent' => 'Uz reģistrācijas laikā norādīto e-pasta adresi ir nosūtīta jauna apstiprinājuma saite.',
        'resend' => 'Nosūtīt apstiprinājuma e-pastu vēlreiz',
    ],

    'social' => [
        'log_in_with' => 'Pieteikties ar :provider',
        'unavailable' => 'Pieteikšanās ar :provider pašlaik nav pieejama.',
        'unavailable_notice' => 'Pieteikšanās ar :provider ir izslēgta. Ja iepriekš pieteicāties ar :provider, iestatiet sava konta paroli — mēs nosūtīsim jums saiti pa e-pastu.',
        'unavailable_set_password' => 'Iestatīt paroli',
        'cancelled' => 'Pieteikšanās ar :provider tika atcelta. Lūdzu, mēģiniet vēlreiz.',
        'failed' => 'Neizdevās jūs pieteikt ar :provider. Lūdzu, mēģiniet vēlreiz.',
        'expired' => 'Pieteikšanās ar :provider beidzās, pirms tika pabeigta. Lūdzu, sāciet no jauna.',
        'email_missing' => 'Jūsu :provider konts nekopīgoja e-pasta adresi, tāpēc to nevar izmantot pieteikšanās vajadzībām.',
        'email_taken' => 'Šī :provider konta e-pasta adrese pieder citam RateGuru kontam. Piesakieties tajā kontā, lai tur savienotu :provider.',
        'link_expired' => 'Konta savienošanas sesija ir beigusies vai mainījusies. Lūdzu, sāciet konta savienošanu no jauna.',
        'already_signed_in' => 'Pieteikšanās jau ir veikta. Lai pieteiktos arī ar :provider, savienojiet to sadaļā „Savienotie konti”.',
        'already_linked' => 'Šis :provider konts jau ir savienots ar citu kontu.',
        'provider_already_linked' => 'Jūsu konts jau ir savienots ar citu :provider kontu.',
        'pending_link' => 'Ar šo e-pasta adresi jau ir reģistrēts konts. Piesakieties tāpat kā iepriekš — ar paroli vai ar citu sociālā tīkla kontu —, un jūsu :provider konts tiks savienots automātiski.',
        'password_removed' => 'Jūsu e-pasts ir apstiprināts ar :provider starpniecību. Šai e-pasta adresei iepriekš iestatītā parole tika noņemta — jaunu varat iestatīt savā profilā.',
    ],
];
