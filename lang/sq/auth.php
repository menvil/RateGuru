<?php

return [
    'failed' => 'Këto kredenciale nuk përputhen me të dhënat tona.',
    'password' => 'Fjalëkalimi i dhënë është i pasaktë.',
    'throttle' => 'Shumë përpjekje për hyrje. Provo sërish pas :seconds sekondash.',
    'username_unavailable' => 'Nuk u krijua dot një emër përdoruesi unik. Provo një emër tjetër.',
    'session_ended' => 'Sesioni yt përfundoi sepse të dhënat e hyrjes së kësaj llogarie ndryshuan. Hyr sërish.',

    'prompts' => [
        'no_account' => 'Nuk ke llogari?',
        'have_account' => 'Ke tashmë llogari?',
    ],

    'fields' => [
        'name' => 'Emri',
        'username' => 'Emri i përdoruesit',
        'email' => 'Email-i',
        'password' => 'Fjalëkalimi',
        'password_confirmation' => 'Konfirmo fjalëkalimin',
    ],

    'login' => [
        'title' => 'Hyrje',
        'action' => 'Hyr',
        'remember' => 'Më mbaj mend',
        'forgot_password' => 'Ke harruar fjalëkalimin?',
    ],

    'register' => [
        'title' => 'Regjistrim',
        'action' => 'Regjistrohu',
    ],

    'divider' => 'ose',

    'forgot_password' => [
        'intro' => 'Ke harruar fjalëkalimin? S’ka problem. Na trego adresën tënde të email-it dhe do të të dërgojmë një lidhje për rivendosjen e fjalëkalimit, me të cilën mund të zgjedhësh një të ri.',
        'action' => 'Dërgo lidhjen për rivendosjen e fjalëkalimit',
    ],

    'reset_password' => [
        'action' => 'Rivendos fjalëkalimin',
    ],

    'confirm_password' => [
        'intro' => 'Kjo është një zonë e mbrojtur e aplikacionit. Konfirmo fjalëkalimin para se të vazhdosh.',
        'action' => 'Konfirmo',
    ],

    'verify_email' => [
        'intro' => 'Faleminderit që u regjistrove! Para se të fillosh, a mund ta verifikosh adresën tënde të email-it duke klikuar lidhjen që sapo të dërguam? Nëse nuk e ke marrë email-in, me kënaqësi do të të dërgojmë një tjetër.',
        'link_sent' => 'Një lidhje e re verifikimi u dërgua në adresën e email-it që dhe gjatë regjistrimit.',
        'resend' => 'Ridërgo email-in e verifikimit',
    ],

    'social' => [
        'log_in_with' => 'Hyr me :provider',
        'unavailable' => 'Hyrja me :provider nuk është e disponueshme për momentin.',
        'unavailable_notice' => 'Hyrja me :provider është çaktivizuar. Nëse hyje më parë me :provider, vendos një fjalëkalim për llogarinë tënde – do të të dërgojmë një lidhje me email.',
        'unavailable_set_password' => 'Vendos një fjalëkalim',
        'cancelled' => 'Hyrja me :provider u anulua. Provo sërish.',
        'failed' => 'Hyrja me :provider nuk u krye. Provo sërish.',
        'expired' => 'Hyrja jote me :provider skadoi para se të përfundonte. Fillo nga e para.',
        'email_missing' => 'Llogaria jote :provider nuk ndau ndonjë adresë email-i, ndaj nuk mund të përdoret për hyrje.',
        'email_taken' => 'Adresa e email-it e kësaj llogarie :provider i përket një llogarie tjetër RateGuru. Hyr në atë llogari për të lidhur :provider atje.',
        'link_expired' => 'Sesioni i lidhjes së llogarisë skadoi ose ndryshoi. Fillo sërish lidhjen e llogarisë.',
        'already_signed_in' => 'Ke hyrë tashmë. Për të hyrë edhe me :provider, lidhe te „Llogaritë e lidhura“.',
        'already_linked' => 'Kjo llogari :provider është lidhur tashmë me një llogari tjetër.',
        'provider_already_linked' => 'Llogaria jote është lidhur tashmë me një llogari tjetër :provider.',
        'pending_link' => 'Për këtë adresë email-i ekziston tashmë një llogari. Hyr si më parë – me fjalëkalimin ose me një llogari tjetër sociale – dhe llogaria jote :provider do të lidhet automatikisht.',
        'password_removed' => 'Email-i yt është konfirmuar përmes :provider. Një fjalëkalim që ishte vendosur për këtë adresë email-i u hoq – mund të vendosësh një të ri te profili yt.',
    ],
];
