<?php

return [
    'failed' => 'Diese Zugangsdaten stimmen nicht mit unseren Daten überein.',
    'password' => 'Das angegebene Passwort ist falsch.',
    'throttle' => 'Zu viele Anmeldeversuche. Bitte versuche es in :seconds Sekunden erneut.',
    'username_unavailable' => 'Es konnte kein eindeutiger Benutzername erstellt werden. Bitte versuche es mit einem anderen Namen.',
    'session_ended' => 'Deine Sitzung wurde beendet, weil sich die Anmeldedaten dieses Kontos geändert haben. Bitte melde dich erneut an.',

    'prompts' => [
        'no_account' => 'Noch kein Konto?',
        'have_account' => 'Du hast bereits ein Konto?',
    ],

    'fields' => [
        'name' => 'Name',
        'username' => 'Benutzername',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwort bestätigen',
    ],

    'login' => [
        'title' => 'Anmelden',
        'action' => 'Anmelden',
        'remember' => 'Angemeldet bleiben',
        'forgot_password' => 'Passwort vergessen?',
    ],

    'register' => [
        'title' => 'Registrieren',
        'action' => 'Registrieren',
    ],

    'divider' => 'oder',

    'forgot_password' => [
        'intro' => 'Passwort vergessen? Kein Problem. Gib einfach deine E-Mail-Adresse an, und wir schicken dir einen Link, mit dem du ein neues Passwort festlegen kannst.',
        'action' => 'Link zum Zurücksetzen senden',
    ],

    'reset_password' => [
        'action' => 'Passwort zurücksetzen',
    ],

    'confirm_password' => [
        'intro' => 'Dies ist ein geschützter Bereich. Bitte bestätige dein Passwort, bevor du fortfährst.',
        'action' => 'Bestätigen',
    ],

    'verify_email' => [
        'intro' => 'Danke für deine Registrierung! Bevor es losgeht: Bitte bestätige deine E-Mail-Adresse über den Link, den wir dir gerade geschickt haben. Falls keine E-Mail angekommen ist, senden wir dir gern eine neue.',
        'link_sent' => 'Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die du bei der Registrierung angegeben hast.',
        'resend' => 'Bestätigungs-E-Mail erneut senden',
    ],

    'social' => [
        'log_in_with' => 'Mit :provider anmelden',
        'unavailable' => 'Die Anmeldung mit :provider ist derzeit nicht verfügbar.',
        'unavailable_notice' => 'Die Anmeldung mit :provider ist deaktiviert. Wenn du dich bisher mit :provider angemeldet hast, lege ein Passwort für dein Konto fest – wir schicken dir dafür einen Link per E-Mail.',
        'unavailable_set_password' => 'Passwort festlegen',
        'cancelled' => 'Die Anmeldung mit :provider wurde abgebrochen. Bitte versuche es erneut.',
        'failed' => 'Wir konnten dich nicht mit :provider anmelden. Bitte versuche es erneut.',
        'expired' => 'Deine Anmeldung mit :provider ist abgelaufen, bevor sie abgeschlossen war. Bitte beginne von vorn.',
        'email_missing' => 'Dein :provider-Konto hat keine E-Mail-Adresse freigegeben und kann daher nicht zur Anmeldung verwendet werden.',
        'email_taken' => 'Die E-Mail-Adresse dieses :provider-Kontos gehört zu einem anderen RateGuru-Konto. Melde dich bei diesem Konto an, um :provider dort zu verbinden.',
        'link_expired' => 'Deine Sitzung zum Verbinden des Kontos ist abgelaufen oder hat sich geändert. Bitte starte die Verbindung erneut.',
        'already_signed_in' => 'Du bist bereits angemeldet. Um dich auch mit :provider anzumelden, verbinde es unter „Verbundene Konten“.',
        'already_linked' => 'Dieses :provider-Konto ist bereits mit einem anderen Konto verbunden.',
        'provider_already_linked' => 'Dein Konto ist bereits mit einem anderen :provider-Konto verbunden.',
        'pending_link' => 'Für diese E-Mail-Adresse gibt es bereits ein Konto. Melde dich so an wie bisher – mit deinem Passwort oder einem anderen Social-Login –, dann wird dein :provider-Konto automatisch verbunden.',
        'password_removed' => 'Deine E-Mail-Adresse ist über :provider bestätigt. Ein zuvor für diese E-Mail-Adresse festgelegtes Passwort wurde entfernt – du kannst in deinem Profil ein neues festlegen.',
    ],
];
