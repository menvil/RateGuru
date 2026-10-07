<?php

return [
    'failed' => 'Deze inloggegevens komen niet overeen met onze gegevens.',
    'password' => 'Het opgegeven wachtwoord is onjuist.',
    'throttle' => 'Te veel inlogpogingen. Probeer het over :seconds seconden opnieuw.',
    'username_unavailable' => 'Er kon geen unieke gebruikersnaam worden aangemaakt. Probeer een andere naam.',
    'session_ended' => 'Je sessie is beëindigd omdat de inloggegevens van dit account zijn gewijzigd. Log opnieuw in.',

    'prompts' => [
        'no_account' => 'Nog geen account?',
        'have_account' => 'Heb je al een account?',
    ],

    'fields' => [
        'name' => 'Naam',
        'username' => 'Gebruikersnaam',
        'email' => 'E-mail',
        'password' => 'Wachtwoord',
        'password_confirmation' => 'Wachtwoord bevestigen',
    ],

    'login' => [
        'title' => 'Inloggen',
        'action' => 'Inloggen',
        'remember' => 'Onthoud mij',
        'forgot_password' => 'Wachtwoord vergeten?',
    ],

    'register' => [
        'title' => 'Registreren',
        'action' => 'Registreren',
    ],

    'divider' => 'of',

    'forgot_password' => [
        'intro' => 'Wachtwoord vergeten? Geen probleem. Laat ons je e-mailadres weten, dan sturen we je een link waarmee je een nieuw wachtwoord kunt kiezen.',
        'action' => 'Link voor wachtwoordherstel versturen',
    ],

    'reset_password' => [
        'action' => 'Wachtwoord opnieuw instellen',
    ],

    'confirm_password' => [
        'intro' => 'Dit is een beveiligd gedeelte van de applicatie. Bevestig je wachtwoord voordat je verdergaat.',
        'action' => 'Bevestigen',
    ],

    'verify_email' => [
        'intro' => 'Bedankt voor je registratie! Wil je, voordat je begint, je e-mailadres bevestigen door op de link te klikken die we je zojuist hebben gemaild? Heb je de e-mail niet ontvangen, dan sturen we je graag een nieuwe.',
        'link_sent' => 'Er is een nieuwe verificatielink verzonden naar het e-mailadres dat je bij de registratie hebt opgegeven.',
        'resend' => 'Verificatie-e-mail opnieuw versturen',
    ],

    'social' => [
        'log_in_with' => 'Inloggen met :provider',
        'unavailable' => 'Inloggen met :provider is momenteel niet beschikbaar.',
        'unavailable_notice' => 'Inloggen met :provider is uitgeschakeld. Logde je eerder in met :provider? Stel dan een wachtwoord in voor je account — we sturen je een link per e-mail.',
        'unavailable_set_password' => 'Wachtwoord instellen',
        'cancelled' => 'Inloggen met :provider is geannuleerd. Probeer het opnieuw.',
        'failed' => 'We konden je niet inloggen met :provider. Probeer het opnieuw.',
        'expired' => 'Je aanmelding via :provider is verlopen voordat deze was voltooid. Begin opnieuw.',
        'email_missing' => 'Je :provider-account heeft geen e-mailadres gedeeld en kan daarom niet worden gebruikt om in te loggen.',
        'email_taken' => 'Het e-mailadres van dit :provider-account hoort bij een ander RateGuru-account. Log in op dat account om :provider daar te koppelen.',
        'link_expired' => 'Je sessie voor het koppelen van het account is verlopen of gewijzigd. Begin opnieuw met het koppelen van het account.',
        'already_signed_in' => 'Je bent al ingelogd. Wil je ook met :provider inloggen, koppel het dan onder ‘Gekoppelde accounts’.',
        'already_linked' => 'Dit :provider-account is al aan een ander account gekoppeld.',
        'provider_already_linked' => 'Je account is al gekoppeld aan een ander :provider-account.',
        'pending_link' => 'Er bestaat al een account met dit e-mailadres. Log in zoals je dat eerder deed — met je wachtwoord of met een ander sociaal account — en je :provider-account wordt automatisch gekoppeld.',
        'password_removed' => 'Je e-mailadres is bevestigd via :provider. Een wachtwoord dat voor dit e-mailadres was ingesteld, is verwijderd — je kunt in je profiel een nieuw wachtwoord instellen.',
    ],
];
