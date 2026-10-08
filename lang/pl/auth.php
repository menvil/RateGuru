<?php

return [
    'failed' => 'Podane dane logowania są nieprawidłowe.',
    'password' => 'Podane hasło jest nieprawidłowe.',
    'throttle' => 'Zbyt wiele prób logowania. Spróbuj ponownie za :seconds s.',
    'username_unavailable' => 'Nie udało się utworzyć unikalnej nazwy użytkownika. Spróbuj użyć innej nazwy.',
    'session_ended' => 'Twoja sesja została zakończona, ponieważ dane logowania do tego konta uległy zmianie. Zaloguj się ponownie.',

    'prompts' => [
        'no_account' => 'Nie masz konta?',
        'have_account' => 'Masz już konto?',
    ],

    'fields' => [
        'name' => 'Imię',
        'username' => 'Nazwa użytkownika',
        'email' => 'E-mail',
        'password' => 'Hasło',
        'password_confirmation' => 'Potwierdź hasło',
    ],

    'login' => [
        'title' => 'Zaloguj się',
        'action' => 'Zaloguj się',
        'remember' => 'Zapamiętaj mnie',
        'forgot_password' => 'Nie pamiętasz hasła?',
    ],

    'register' => [
        'title' => 'Zarejestruj się',
        'action' => 'Zarejestruj się',
    ],

    'divider' => 'lub',

    'forgot_password' => [
        'intro' => 'Nie pamiętasz hasła? Nie ma problemu. Podaj swój adres e-mail, a wyślemy Ci link do zresetowania hasła, dzięki któremu ustawisz nowe.',
        'action' => 'Wyślij link do resetowania hasła',
    ],

    'reset_password' => [
        'action' => 'Zresetuj hasło',
    ],

    'confirm_password' => [
        'intro' => 'To jest chroniony obszar aplikacji. Zanim przejdziesz dalej, potwierdź swoje hasło.',
        'action' => 'Potwierdź',
    ],

    'verify_email' => [
        'intro' => 'Dziękujemy za rejestrację! Zanim zaczniesz, potwierdź swój adres e-mail, klikając link, który właśnie Ci wysłaliśmy. Jeśli wiadomość do Ciebie nie dotarła, chętnie wyślemy kolejną.',
        'link_sent' => 'Nowy link weryfikacyjny został wysłany na adres e-mail podany podczas rejestracji.',
        'resend' => 'Wyślij ponownie e-mail weryfikacyjny',
    ],

    'social' => [
        'log_in_with' => 'Zaloguj się przez :provider',
        'unavailable' => 'Logowanie przez :provider jest obecnie niedostępne.',
        'unavailable_notice' => 'Logowanie przez :provider jest wyłączone. Jeśli Twoje konto korzystało z logowania przez :provider, ustaw do niego hasło — wyślemy Ci link e-mailem.',
        'unavailable_set_password' => 'Ustaw hasło',
        'cancelled' => 'Logowanie przez :provider zostało anulowane. Spróbuj ponownie.',
        'failed' => 'Nie udało się zalogować Cię przez :provider. Spróbuj ponownie.',
        'expired' => 'Logowanie przez :provider wygasło, zanim zostało ukończone. Zacznij od nowa.',
        'email_missing' => 'Twoje konto :provider nie udostępniło adresu e-mail, więc nie można go użyć do logowania.',
        'email_taken' => 'Adres e-mail tego konta :provider należy do innego konta RateGuru. Zaloguj się na tamto konto, aby połączyć z nim :provider.',
        'link_expired' => 'Sesja łączenia konta wygasła lub uległa zmianie. Rozpocznij łączenie konta od nowa.',
        'already_signed_in' => 'Masz już aktywną sesję. Aby logować się także przez :provider, połącz to konto w sekcji „Połączone konta”.',
        'already_linked' => 'To konto :provider jest już połączone z innym kontem.',
        'provider_already_linked' => 'Twoje konto jest już połączone z innym kontem :provider.',
        'pending_link' => 'Do tego adresu e-mail jest już przypisane konto. Zaloguj się tak jak wcześniej — hasłem lub innym kontem społecznościowym — a Twoje konto :provider zostanie połączone automatycznie.',
        'password_removed' => 'Twój adres e-mail został potwierdzony przez :provider. Hasło ustawione wcześniej dla tego adresu e-mail zostało usunięte — możesz ustawić nowe w swoim profilu.',
    ],
];
