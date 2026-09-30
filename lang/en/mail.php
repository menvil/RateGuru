<?php

return [
    'greeting' => 'Hello, :name!',
    'salutation' => 'Regards, :app',
    'fallback_greeting' => 'Hello!',
    'error_greeting' => 'Whoops!',
    'rights_reserved' => 'All rights reserved.',
    'action_fallback' => "If you're having trouble clicking the \":action\" button, copy and paste the URL below into your web browser:",

    'verify' => [
        'subject' => 'Confirm your email address',
        'line' => 'Please confirm your email address to finish setting up your account.',
        'action' => 'Confirm email address',
        'ignore' => 'If you did not create an account, no further action is required.',
    ],

    'reset' => [
        'subject' => 'Reset your password',
        'line' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset password',
        'expire' => 'This password reset link will expire in :count minutes.',
        'ignore' => 'If you did not request a password reset, no further action is required.',
    ],

    'contact' => [
        'subject' => 'New contact message: :subject',
        'heading' => 'New contact message',
        'name' => 'Name',
        'email' => 'Email',
        'message_subject' => 'Subject',
        'body' => 'Message',
    ],

    'social' => [
        'account' => ':provider account: :email',
        'action' => 'Review connected accounts',
        'connected' => [
            'subject' => ':provider was connected to your account',
            'line' => 'A :provider account was connected to your RateGuru account. It can now be used to sign in.',
            'not_you' => 'If this was not you, open your profile and disconnect it right away.',
        ],
        'disconnected' => [
            'subject' => ':provider was disconnected from your account',
            'line' => 'A :provider account was disconnected from your RateGuru account. It can no longer be used to sign in.',
            'not_you' => 'If this was not you, sign in and check your connected accounts and your password.',
        ],
    ],
];
