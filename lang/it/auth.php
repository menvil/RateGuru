<?php

return [
    'failed' => 'Queste credenziali non corrispondono ai nostri dati.',
    'password' => 'La password inserita non è corretta.',
    'throttle' => 'Troppi tentativi di accesso. Riprova tra :seconds secondi.',
    'username_unavailable' => 'Impossibile creare un nome utente univoco. Prova con un nome diverso.',
    'session_ended' => 'La tua sessione è terminata perché i dati di accesso di questo account sono cambiati. Accedi di nuovo.',

    'prompts' => [
        'no_account' => 'Non hai un account?',
        'have_account' => 'Hai già un account?',
    ],

    'fields' => [
        'name' => 'Nome',
        'username' => 'Nome utente',
        'email' => 'E-mail',
        'password' => 'Password',
        'password_confirmation' => 'Conferma password',
    ],

    'login' => [
        'title' => 'Accedi',
        'action' => 'Accedi',
        'remember' => 'Ricordami',
        'forgot_password' => 'Hai dimenticato la password?',
    ],

    'register' => [
        'title' => 'Registrati',
        'action' => 'Registrati',
    ],

    'divider' => 'oppure',

    'forgot_password' => [
        'intro' => 'Hai dimenticato la password? Nessun problema. Indicaci il tuo indirizzo e-mail e ti invieremo un link per reimpostare la password e sceglierne una nuova.',
        'action' => 'Invia link per reimpostare la password',
    ],

    'reset_password' => [
        'action' => 'Reimposta password',
    ],

    'confirm_password' => [
        'intro' => 'Questa è un\'area protetta dell\'applicazione. Conferma la tua password prima di continuare.',
        'action' => 'Conferma',
    ],

    'verify_email' => [
        'intro' => 'Grazie per esserti registrato! Prima di iniziare, puoi verificare il tuo indirizzo e-mail facendo clic sul link che ti abbiamo appena inviato? Se non hai ricevuto l\'e-mail, te ne invieremo volentieri un\'altra.',
        'link_sent' => 'Un nuovo link di verifica è stato inviato all\'indirizzo e-mail che hai indicato durante la registrazione.',
        'resend' => 'Invia di nuovo l\'e-mail di verifica',
    ],

    'social' => [
        'log_in_with' => 'Accedi con :provider',
        'unavailable' => 'L\'accesso con :provider non è al momento disponibile.',
        'unavailable_notice' => 'L\'accesso con :provider è disattivato. Se finora accedevi con :provider, imposta una password per il tuo account: ti invieremo un link via e-mail.',
        'unavailable_set_password' => 'Imposta una password',
        'cancelled' => 'L\'accesso con :provider è stato annullato. Riprova.',
        'failed' => 'Non siamo riusciti a farti accedere con :provider. Riprova.',
        'expired' => 'Il tuo accesso con :provider è scaduto prima di essere completato. Ricomincia da capo.',
        'email_missing' => 'Il tuo account :provider non ha condiviso un indirizzo e-mail, quindi non può essere usato per accedere.',
        'email_taken' => 'L\'indirizzo e-mail di questo account :provider appartiene a un altro account RateGuru. Accedi a quell\'account per collegare :provider lì.',
        'link_expired' => 'La sessione di collegamento dell\'account è scaduta o è cambiata. Ricomincia a collegare l\'account.',
        'already_signed_in' => 'Hai già effettuato l\'accesso. Per accedere anche con :provider, collegalo in «Account collegati».',
        'already_linked' => 'Questo account :provider è già collegato a un altro account.',
        'provider_already_linked' => 'Il tuo account è già collegato a un altro account :provider.',
        'pending_link' => 'Esiste già un account con questo indirizzo e-mail. Accedi come hai fatto finora, con la tua password o con un altro account social, e il tuo account :provider verrà collegato automaticamente.',
        'password_removed' => 'Il tuo indirizzo e-mail è confermato tramite :provider. La password impostata in precedenza per questo indirizzo e-mail è stata rimossa: puoi impostarne una nuova nel tuo profilo.',
    ],
];
