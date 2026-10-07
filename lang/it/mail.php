<?php

return [
    'greeting' => 'Ciao :name!',
    'salutation' => 'Un saluto, :app',
    'fallback_greeting' => 'Ciao!',
    'error_greeting' => 'Ops!',
    'rights_reserved' => 'Tutti i diritti riservati.',
    'action_fallback' => 'Se hai problemi a fare clic sul pulsante «:action», copia e incolla l\'URL qui sotto nel tuo browser:',

    'verify' => [
        'subject' => 'Conferma il tuo indirizzo e-mail',
        'line' => 'Conferma il tuo indirizzo e-mail per completare la configurazione del tuo account.',
        'action' => 'Conferma indirizzo e-mail',
        'ignore' => 'Se non hai creato un account, non devi fare nient\'altro.',
    ],

    'reset' => [
        'subject' => 'Reimposta la tua password',
        'line' => 'Ricevi questa e-mail perché abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account.',
        'action' => 'Reimposta password',
        'expire' => 'Questo link per reimpostare la password scadrà tra :count minuti.',
        'ignore' => 'Se non hai richiesto la reimpostazione della password, non devi fare nient\'altro.',
    ],

    'contact' => [
        'subject' => 'Nuovo messaggio di contatto: :subject',
        'heading' => 'Nuovo messaggio di contatto',
        'name' => 'Nome',
        'email' => 'E-mail',
        'message_subject' => 'Oggetto',
        'body' => 'Messaggio',
    ],

    'social' => [
        'account' => 'Account :provider: :email',
        'action' => 'Controlla gli account collegati',
        'connected' => [
            'subject' => ':provider è stato collegato al tuo account',
            'line' => 'Un account :provider è stato collegato al tuo account RateGuru. Ora può essere usato per accedere.',
            'not_you' => 'Se non sei stato tu, apri il tuo profilo e scollegalo subito.',
        ],
        'disconnected' => [
            'subject' => ':provider è stato scollegato dal tuo account',
            'line' => 'Un account :provider è stato scollegato dal tuo account RateGuru. Non può più essere usato per accedere.',
            'not_you' => 'Se non sei stato tu, accedi e controlla i tuoi account collegati e la tua password.',
        ],
    ],
];
