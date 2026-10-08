<?php

return [
    'failed' => 'Ces identifiants ne correspondent à aucun compte.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Veuillez réessayer dans :seconds secondes.',
    'username_unavailable' => 'Impossible de créer un nom d\'utilisateur unique. Veuillez essayer avec un autre nom.',
    'session_ended' => 'Votre session a pris fin, car les identifiants de connexion de ce compte ont changé. Veuillez vous reconnecter.',

    'prompts' => [
        'no_account' => 'Vous n\'avez pas de compte ?',
        'have_account' => 'Vous avez déjà un compte ?',
    ],

    'fields' => [
        'name' => 'Nom',
        'username' => 'Nom d\'utilisateur',
        'email' => 'E-mail',
        'password' => 'Mot de passe',
        'password_confirmation' => 'Confirmer le mot de passe',
    ],

    'login' => [
        'title' => 'Connexion',
        'action' => 'Se connecter',
        'remember' => 'Se souvenir de moi',
        'forgot_password' => 'Mot de passe oublié ?',
    ],

    'register' => [
        'title' => 'Inscription',
        'action' => 'S\'inscrire',
    ],

    'divider' => 'ou',

    'forgot_password' => [
        'intro' => 'Mot de passe oublié ? Aucun problème. Indiquez-nous simplement votre adresse e-mail et nous vous enverrons un lien de réinitialisation qui vous permettra d\'en choisir un nouveau.',
        'action' => 'Envoyer le lien de réinitialisation',
    ],

    'reset_password' => [
        'action' => 'Réinitialiser le mot de passe',
    ],

    'confirm_password' => [
        'intro' => 'Cette zone de l\'application est sécurisée. Veuillez confirmer votre mot de passe avant de continuer.',
        'action' => 'Confirmer',
    ],

    'verify_email' => [
        'intro' => 'Merci pour votre inscription ! Avant de commencer, pourriez-vous confirmer votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer ? Si vous n\'avez pas reçu l\'e-mail, nous vous en enverrons volontiers un autre.',
        'link_sent' => 'Un nouveau lien de vérification a été envoyé à l\'adresse e-mail que vous avez indiquée lors de votre inscription.',
        'resend' => 'Renvoyer l\'e-mail de vérification',
    ],

    'social' => [
        'log_in_with' => 'Se connecter avec :provider',
        'unavailable' => 'La connexion avec :provider est actuellement indisponible.',
        'unavailable_notice' => 'La connexion avec :provider est désactivée. Si vous vous connectiez avec :provider, définissez un mot de passe pour votre compte — nous vous enverrons un lien par e-mail.',
        'unavailable_set_password' => 'Définir un mot de passe',
        'cancelled' => 'La connexion avec :provider a été annulée. Veuillez réessayer.',
        'failed' => 'Nous n\'avons pas pu vous connecter avec :provider. Veuillez réessayer.',
        'expired' => 'Votre connexion avec :provider a expiré avant d\'aboutir. Veuillez recommencer.',
        'email_missing' => 'Votre compte :provider n\'a pas partagé d\'adresse e-mail ; il ne peut donc pas être utilisé pour vous connecter.',
        'email_taken' => 'L\'adresse e-mail de ce compte :provider appartient à un autre compte RateGuru. Connectez-vous à ce compte pour y associer :provider.',
        'link_expired' => 'Votre session d\'association de compte a expiré ou a changé. Veuillez recommencer l\'association du compte.',
        'already_signed_in' => 'Vous êtes déjà connecté. Pour vous connecter également avec :provider, associez-le dans « Comptes associés ».',
        'already_linked' => 'Ce compte :provider est déjà associé à un autre compte.',
        'provider_already_linked' => 'Votre compte est déjà associé à un autre compte :provider.',
        'pending_link' => 'Un compte existe déjà pour cette adresse e-mail. Connectez-vous comme d\'habitude — avec votre mot de passe ou un autre compte de réseau social — et votre compte :provider sera associé automatiquement.',
        'password_removed' => 'Votre adresse e-mail est confirmée via :provider. Le mot de passe qui avait été défini pour cette adresse e-mail a été supprimé — vous pouvez en définir un nouveau dans votre profil.',
    ],
];
