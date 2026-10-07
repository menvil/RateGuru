<?php

return [
    'failed' => 'Inloggningsuppgifterna stämmer inte med våra uppgifter.',
    'password' => 'Lösenordet är felaktigt.',
    'throttle' => 'För många inloggningsförsök. Försök igen om :seconds sekunder.',
    'username_unavailable' => 'Det gick inte att skapa ett unikt användarnamn. Försök med ett annat namn.',
    'session_ended' => 'Din session har avslutats eftersom inloggningsuppgifterna för det här kontot har ändrats. Logga in igen.',

    'prompts' => [
        'no_account' => 'Har du inget konto?',
        'have_account' => 'Har du redan ett konto?',
    ],

    'fields' => [
        'name' => 'Namn',
        'username' => 'Användarnamn',
        'email' => 'E-post',
        'password' => 'Lösenord',
        'password_confirmation' => 'Bekräfta lösenord',
    ],

    'login' => [
        'title' => 'Logga in',
        'action' => 'Logga in',
        'remember' => 'Kom ihåg mig',
        'forgot_password' => 'Glömt ditt lösenord?',
    ],

    'register' => [
        'title' => 'Skapa konto',
        'action' => 'Skapa konto',
    ],

    'divider' => 'eller',

    'forgot_password' => [
        'intro' => 'Glömt ditt lösenord? Inga problem. Ange din e-postadress så skickar vi en länk där du kan välja ett nytt lösenord.',
        'action' => 'Skicka länk för återställning',
    ],

    'reset_password' => [
        'action' => 'Återställ lösenord',
    ],

    'confirm_password' => [
        'intro' => 'Det här är ett skyddat område. Bekräfta ditt lösenord innan du fortsätter.',
        'action' => 'Bekräfta',
    ],

    'verify_email' => [
        'intro' => 'Tack för att du registrerade dig! Innan du sätter igång: bekräfta din e-postadress genom att klicka på länken vi just skickade till dig. Om du inte har fått mejlet skickar vi gärna ett nytt.',
        'link_sent' => 'En ny bekräftelselänk har skickats till e-postadressen du angav när du registrerade dig.',
        'resend' => 'Skicka bekräftelsemejlet igen',
    ],

    'social' => [
        'log_in_with' => 'Logga in med :provider',
        'unavailable' => 'Inloggning med :provider är inte tillgänglig just nu.',
        'unavailable_notice' => 'Inloggning med :provider är avstängd. Om du brukade logga in med :provider kan du ange ett lösenord för ditt konto – vi skickar en länk till din e-post.',
        'unavailable_set_password' => 'Ange ett lösenord',
        'cancelled' => 'Inloggningen med :provider avbröts. Försök igen.',
        'failed' => 'Vi kunde inte logga in dig med :provider. Försök igen.',
        'expired' => 'Din inloggning med :provider gick ut innan den slutfördes. Börja om från början.',
        'email_missing' => 'Ditt :provider-konto delade ingen e-postadress, så det kan inte användas för att logga in.',
        'email_taken' => 'E-postadressen för det här :provider-kontot tillhör ett annat RateGuru-konto. Logga in på det kontot för att koppla :provider där.',
        'link_expired' => 'Sessionen för att koppla kontot har gått ut eller ändrats. Börja koppla kontot igen.',
        'already_signed_in' => 'Du är redan inloggad. Om du även vill logga in med :provider kopplar du det under ”Kopplade konton”.',
        'already_linked' => 'Det här :provider-kontot är redan kopplat till ett annat konto.',
        'provider_already_linked' => 'Ditt konto är redan kopplat till ett annat :provider-konto.',
        'pending_link' => 'Det finns redan ett konto med den här e-postadressen. Logga in som du gjorde tidigare – med ditt lösenord eller med ett annat socialt konto – så kopplas ditt :provider-konto automatiskt.',
        'password_removed' => 'Din e-postadress är bekräftad via :provider. Ett lösenord som tidigare angetts för den här e-postadressen har tagits bort – du kan ange ett nytt i din profil.',
    ],
];
