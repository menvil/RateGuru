<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'session_ended' => 'Your session has ended because this account\'s sign-in details changed. Please sign in again.',

    'prompts' => [
        'no_account' => "Don't have an account?",
        'have_account' => 'Already have an account?',
    ],

    'social' => [
        'log_in_with' => 'Log in with :provider',
        'unavailable' => 'Sign-in with :provider is currently unavailable.',
        'unavailable_notice' => 'Sign-in with :provider is turned off. If you used to sign in with :provider, set a password for your account — we will email you a link.',
        'unavailable_set_password' => 'Set a password',
        'cancelled' => 'Sign-in with :provider was cancelled. Please try again.',
        'failed' => 'We could not sign you in with :provider. Please try again.',
        'expired' => 'Your :provider sign-in expired before it finished. Please start again.',
        'email_missing' => 'Your :provider account did not share an email address, so it cannot be used to sign in.',
        'email_taken' => 'The email address of this :provider account belongs to another RateGuru account. Sign in to that account to connect :provider there.',
        'link_expired' => 'Your account connection session expired or changed. Please start connecting the account again.',
        'already_signed_in' => 'You are already signed in. To sign in with :provider as well, connect it under Connected accounts.',
        'already_linked' => 'This :provider account is already connected to a different account.',
        'provider_already_linked' => 'Your account is already connected to a different :provider account.',
        'pending_link' => 'This email address already has an account. Sign in the way you did before — with your password or with another social account — and your :provider account will be connected automatically.',
        'password_removed' => 'Your email is confirmed through :provider. A password that had been set for this email address was removed — you can set a new one in your profile.',
    ],
];
