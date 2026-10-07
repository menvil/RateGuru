<?php

return [
    'greeting' => 'Bonjour :name !',
    'salutation' => 'Cordialement, :app',
    'fallback_greeting' => 'Bonjour !',
    'error_greeting' => 'Oups !',
    'rights_reserved' => 'Tous droits réservés.',
    'action_fallback' => 'Si vous ne parvenez pas à cliquer sur le bouton « :action », copiez et collez l\'URL ci-dessous dans votre navigateur :',

    'verify' => [
        'subject' => 'Confirmez votre adresse e-mail',
        'line' => 'Veuillez confirmer votre adresse e-mail pour terminer la configuration de votre compte.',
        'action' => 'Confirmer l\'adresse e-mail',
        'ignore' => 'Si vous n\'avez pas créé de compte, aucune action n\'est requise.',
    ],

    'reset' => [
        'subject' => 'Réinitialisez votre mot de passe',
        'line' => 'Vous recevez cet e-mail, car nous avons reçu une demande de réinitialisation du mot de passe de votre compte.',
        'action' => 'Réinitialiser le mot de passe',
        'expire' => 'Ce lien de réinitialisation du mot de passe expirera dans :count minutes.',
        'ignore' => 'Si vous n\'avez pas demandé de réinitialisation du mot de passe, aucune action n\'est requise.',
    ],

    'contact' => [
        'subject' => 'Nouveau message de contact : :subject',
        'heading' => 'Nouveau message de contact',
        'name' => 'Nom',
        'email' => 'E-mail',
        'message_subject' => 'Objet',
        'body' => 'Message',
    ],

    'social' => [
        'account' => 'Compte :provider : :email',
        'action' => 'Vérifier les comptes associés',
        'connected' => [
            'subject' => ':provider a été associé à votre compte',
            'line' => 'Un compte :provider a été associé à votre compte RateGuru. Il peut désormais être utilisé pour vous connecter.',
            'not_you' => 'Si ce n\'était pas vous, ouvrez votre profil et dissociez-le immédiatement.',
        ],
        'disconnected' => [
            'subject' => ':provider a été dissocié de votre compte',
            'line' => 'Un compte :provider a été dissocié de votre compte RateGuru. Il ne peut plus être utilisé pour vous connecter.',
            'not_you' => 'Si ce n\'était pas vous, connectez-vous et vérifiez vos comptes associés ainsi que votre mot de passe.',
        ],
    ],
];
