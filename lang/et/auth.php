<?php

return [
    'failed' => 'Need sisselogimisandmed ei ühti meie andmetega.',
    'password' => 'Sisestatud parool on vale.',
    'throttle' => 'Liiga palju sisselogimiskatseid. Proovi uuesti :seconds sekundi pärast.',
    'username_unavailable' => 'Unikaalset kasutajanime ei õnnestunud luua. Proovi mõnda teist nime.',
    'session_ended' => 'Sinu seanss lõppes, sest selle konto sisselogimisandmed muutusid. Logi uuesti sisse.',

    'prompts' => [
        'no_account' => 'Sul pole veel kontot?',
        'have_account' => 'Sul on juba konto?',
    ],

    'fields' => [
        'name' => 'Nimi',
        'username' => 'Kasutajanimi',
        'email' => 'E-post',
        'password' => 'Parool',
        'password_confirmation' => 'Kinnita parool',
    ],

    'login' => [
        'title' => 'Logi sisse',
        'action' => 'Logi sisse',
        'remember' => 'Jäta mind meelde',
        'forgot_password' => 'Unustasid parooli?',
    ],

    'register' => [
        'title' => 'Registreeru',
        'action' => 'Registreeru',
    ],

    'divider' => 'või',

    'forgot_password' => [
        'intro' => 'Unustasid parooli? Pole probleemi. Sisesta oma e-posti aadress ja me saadame sulle parooli lähtestamise lingi, mille kaudu saad valida uue parooli.',
        'action' => 'Saada parooli lähtestamise link',
    ],

    'reset_password' => [
        'action' => 'Lähtesta parool',
    ],

    'confirm_password' => [
        'intro' => 'See on rakenduse turvaline ala. Enne jätkamist kinnita oma parool.',
        'action' => 'Kinnita',
    ],

    'verify_email' => [
        'intro' => 'Täname registreerumast! Enne alustamist kinnita palun oma e-posti aadress, klõpsates lingil, mille sulle just e-postiga saatsime. Kui sa e-kirja ei saanud, saadame meelsasti uue.',
        'link_sent' => 'Registreerumisel sisestatud e-posti aadressile saadeti uus kinnituslink.',
        'resend' => 'Saada kinnituskiri uuesti',
    ],

    'social' => [
        'log_in_with' => 'Logi sisse teenusega :provider',
        'unavailable' => 'Sisselogimine teenusega :provider pole praegu saadaval.',
        'unavailable_notice' => 'Sisselogimine teenusega :provider on välja lülitatud. Kui logisid varem sisse teenusega :provider, määra oma kontole parool — saadame sulle e-postiga lingi.',
        'unavailable_set_password' => 'Määra parool',
        'cancelled' => 'Sisselogimine teenusega :provider katkestati. Proovi uuesti.',
        'failed' => 'Sisselogimine teenusega :provider ebaõnnestus. Proovi uuesti.',
        'expired' => 'Sisselogimine teenusega :provider aegus enne lõpetamist. Alusta uuesti.',
        'email_missing' => 'Sinu :provider konto ei jaganud e-posti aadressi, seega ei saa seda sisselogimiseks kasutada.',
        'email_taken' => 'Selle :provider konto e-posti aadress kuulub teisele RateGuru kontole. Logi sellele kontole sisse ja ühenda :provider seal.',
        'link_expired' => 'Konto ühendamise seanss aegus või muutus. Alusta konto ühendamist uuesti.',
        'already_signed_in' => 'Oled juba sisse logitud. Et logida sisse ka teenusega :provider, ühenda see jaotises „Ühendatud kontod“.',
        'already_linked' => 'See :provider konto on juba ühendatud teise kontoga.',
        'provider_already_linked' => 'Sinu konto on juba ühendatud teise :provider kontoga.',
        'pending_link' => 'Selle e-posti aadressiga konto on juba olemas. Logi sisse samamoodi nagu varem — parooliga või mõne teise sotsiaalmeedia kontoga — ja sinu :provider konto ühendatakse automaatselt.',
        'password_removed' => 'Sinu e-post on kinnitatud teenuse :provider kaudu. Sellele e-posti aadressile varem määratud parool eemaldati — uue saad määrata oma profiilis.',
    ],
];
