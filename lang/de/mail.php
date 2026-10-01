<?php

return [
    'greeting' => 'Hallo :name!',
    'salutation' => 'Viele Grüße, :app',
    'fallback_greeting' => 'Hallo!',
    'error_greeting' => 'Hoppla!',
    'rights_reserved' => 'Alle Rechte vorbehalten.',
    'action_fallback' => 'Falls der Button „:action“ nicht funktioniert, kopiere die folgende URL und füge sie in deinen Browser ein:',

    'verify' => [
        'subject' => 'Bestätige deine E-Mail-Adresse',
        'line' => 'Bitte bestätige deine E-Mail-Adresse, um die Einrichtung deines Kontos abzuschließen.',
        'action' => 'E-Mail-Adresse bestätigen',
        'ignore' => 'Falls du kein Konto erstellt hast, musst du nichts weiter tun.',
    ],

    'reset' => [
        'subject' => 'Setze dein Passwort zurück',
        'line' => 'Du erhältst diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passworts für dein Konto erhalten haben.',
        'action' => 'Passwort zurücksetzen',
        'expire' => 'Dieser Link zum Zurücksetzen des Passworts läuft in :count Minuten ab.',
        'ignore' => 'Falls du das Zurücksetzen deines Passworts nicht angefordert hast, musst du nichts weiter tun.',
    ],

    'contact' => [
        'subject' => 'Neue Kontaktnachricht: :subject',
        'heading' => 'Neue Kontaktnachricht',
        'name' => 'Name',
        'email' => 'E-Mail',
        'message_subject' => 'Betreff',
        'body' => 'Nachricht',
    ],

    'social' => [
        'account' => ':provider-Konto: :email',
        'action' => 'Verbundene Konten prüfen',
        'connected' => [
            'subject' => ':provider wurde mit deinem Konto verbunden',
            'line' => 'Ein :provider-Konto wurde mit deinem RateGuru-Konto verbunden. Es kann jetzt zur Anmeldung verwendet werden.',
            'not_you' => 'Falls du das nicht warst, öffne dein Profil und trenne die Verbindung sofort.',
        ],
        'disconnected' => [
            'subject' => ':provider wurde von deinem Konto getrennt',
            'line' => 'Ein :provider-Konto wurde von deinem RateGuru-Konto getrennt. Es kann nicht mehr zur Anmeldung verwendet werden.',
            'not_you' => 'Falls du das nicht warst, melde dich an und überprüfe deine verbundenen Konten und dein Passwort.',
        ],
    ],
];
