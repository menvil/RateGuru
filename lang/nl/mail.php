<?php

return [
    'greeting' => 'Hallo :name!',
    'salutation' => 'Met vriendelijke groet, :app',
    'fallback_greeting' => 'Hallo!',
    'error_greeting' => 'Oeps!',
    'rights_reserved' => 'Alle rechten voorbehouden.',
    'action_fallback' => 'Lukt het niet om op de knop “:action” te klikken? Kopieer dan de onderstaande URL en plak deze in je webbrowser:',

    'verify' => [
        'subject' => 'Bevestig je e-mailadres',
        'line' => 'Bevestig je e-mailadres om het instellen van je account af te ronden.',
        'action' => 'E-mailadres bevestigen',
        'ignore' => 'Heb je geen account aangemaakt? Dan hoef je verder niets te doen.',
    ],

    'reset' => [
        'subject' => 'Stel je wachtwoord opnieuw in',
        'line' => 'Je ontvangt deze e-mail omdat we een verzoek hebben ontvangen om het wachtwoord van je account opnieuw in te stellen.',
        'action' => 'Wachtwoord opnieuw instellen',
        'expire' => 'Deze link om je wachtwoord opnieuw in te stellen verloopt over :count minuten.',
        'ignore' => 'Heb je niet gevraagd om je wachtwoord opnieuw in te stellen? Dan hoef je verder niets te doen.',
    ],

    'contact' => [
        'subject' => 'Nieuw contactbericht: :subject',
        'heading' => 'Nieuw contactbericht',
        'name' => 'Naam',
        'email' => 'E-mail',
        'message_subject' => 'Onderwerp',
        'body' => 'Bericht',
    ],

    'social' => [
        'account' => ':provider-account: :email',
        'action' => 'Gekoppelde accounts bekijken',
        'connected' => [
            'subject' => ':provider is gekoppeld aan je account',
            'line' => 'Er is een :provider-account gekoppeld aan je RateGuru-account. Je kunt het nu gebruiken om in te loggen.',
            'not_you' => 'Was jij dit niet? Open dan je profiel en ontkoppel het meteen.',
        ],
        'disconnected' => [
            'subject' => ':provider is ontkoppeld van je account',
            'line' => 'Er is een :provider-account ontkoppeld van je RateGuru-account. Je kunt het niet meer gebruiken om in te loggen.',
            'not_you' => 'Was jij dit niet? Log dan in en controleer je gekoppelde accounts en je wachtwoord.',
        ],
    ],
];
