<?php

return [
    'failed' => 'Antamasi tunnistetiedot eivät vastaa tietojamme.',
    'password' => 'Antamasi salasana on virheellinen.',
    'throttle' => 'Liian monta kirjautumisyritystä. Yritä uudelleen :seconds sekunnin kuluttua.',
    'username_unavailable' => 'Yksilöllistä käyttäjänimeä ei voitu luoda. Kokeile toista nimeä.',
    'session_ended' => 'Istuntosi päättyi, koska tämän tilin kirjautumistiedot muuttuivat. Kirjaudu sisään uudelleen.',

    'prompts' => [
        'no_account' => 'Eikö sinulla ole tiliä?',
        'have_account' => 'Onko sinulla jo tili?',
    ],

    'fields' => [
        'name' => 'Nimi',
        'username' => 'Käyttäjänimi',
        'email' => 'Sähköposti',
        'password' => 'Salasana',
        'password_confirmation' => 'Vahvista salasana',
    ],

    'login' => [
        'title' => 'Kirjaudu sisään',
        'action' => 'Kirjaudu sisään',
        'remember' => 'Muista minut',
        'forgot_password' => 'Unohditko salasanasi?',
    ],

    'register' => [
        'title' => 'Rekisteröidy',
        'action' => 'Rekisteröidy',
    ],

    'divider' => 'tai',

    'forgot_password' => [
        'intro' => 'Unohditko salasanasi? Ei hätää. Kerro meille sähköpostiosoitteesi, niin lähetämme sinulle linkin, jonka avulla voit valita uuden salasanan.',
        'action' => 'Lähetä salasanan palautuslinkki',
    ],

    'reset_password' => [
        'action' => 'Palauta salasana',
    ],

    'confirm_password' => [
        'intro' => 'Tämä on sovelluksen suojattu alue. Vahvista salasanasi ennen kuin jatkat.',
        'action' => 'Vahvista',
    ],

    'verify_email' => [
        'intro' => 'Kiitos rekisteröitymisestä! Ennen kuin aloitat, vahvistaisitko sähköpostiosoitteesi napsauttamalla linkkiä, jonka juuri lähetimme sinulle? Jos et saanut viestiä, lähetämme mielellämme uuden.',
        'link_sent' => 'Uusi vahvistuslinkki on lähetetty sähköpostiosoitteeseen, jonka annoit rekisteröityessäsi.',
        'resend' => 'Lähetä vahvistusviesti uudelleen',
    ],

    'social' => [
        'log_in_with' => 'Kirjaudu :provider-tilillä',
        'unavailable' => 'Kirjautuminen :provider-tilillä ei ole tällä hetkellä käytettävissä.',
        'unavailable_notice' => 'Kirjautuminen :provider-tilillä on poistettu käytöstä. Jos olet aiemmin kirjautunut :provider-tilillä, aseta tilillesi salasana – lähetämme sinulle linkin sähköpostitse.',
        'unavailable_set_password' => 'Aseta salasana',
        'cancelled' => 'Kirjautuminen :provider-tilillä peruutettiin. Yritä uudelleen.',
        'failed' => 'Emme voineet kirjata sinua sisään :provider-tilillä. Yritä uudelleen.',
        'expired' => 'Kirjautuminen :provider-tilillä vanheni ennen kuin se valmistui. Aloita alusta.',
        'email_missing' => ':provider-tilisi ei jakanut sähköpostiosoitetta, joten sillä ei voi kirjautua.',
        'email_taken' => 'Tämän :provider-tilin sähköpostiosoite kuuluu toiseen RateGuru-tiliin. Kirjaudu siihen tiliin ja yhdistä :provider-tili siellä.',
        'link_expired' => 'Tilin yhdistämisistunto vanheni tai muuttui. Aloita tilin yhdistäminen uudelleen.',
        'already_signed_in' => 'Olet jo kirjautunut sisään. Jos haluat kirjautua myös :provider-tilillä, yhdistä se kohdassa ”Yhdistetyt tilit”.',
        'already_linked' => 'Tämä :provider-tili on jo yhdistetty toiseen tiliin.',
        'provider_already_linked' => 'Tilisi on jo yhdistetty toiseen :provider-tiliin.',
        'pending_link' => 'Tällä sähköpostiosoitteella on jo tili. Kirjaudu sisään samalla tavalla kuin aiemmin – salasanalla tai toisella some-tilillä – niin :provider-tilisi yhdistetään automaattisesti.',
        'password_removed' => 'Sähköpostiosoitteesi on vahvistettu :provider-tilin kautta. Tälle sähköpostiosoitteelle aiemmin asetettu salasana poistettiin – voit asettaa uuden profiilissasi.',
    ],
];
