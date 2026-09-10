<?php

return [
    'greeting' => 'Hello, :name!',
    'salutation' => 'Regards, :app',
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
];
