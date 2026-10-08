<?php

return [
    'greeting' => 'Helo, :name!',
    'salutation' => 'Salam hormat, :app',
    'fallback_greeting' => 'Helo!',
    'error_greeting' => 'Alamak!',
    'rights_reserved' => 'Hak cipta terpelihara.',
    'action_fallback' => 'Jika anda menghadapi masalah mengklik butang “:action”, salin dan tampal URL di bawah ke dalam pelayar web anda:',

    'verify' => [
        'subject' => 'Sahkan alamat e-mel anda',
        'line' => 'Sila sahkan alamat e-mel anda untuk melengkapkan persediaan akaun anda.',
        'action' => 'Sahkan alamat e-mel',
        'ignore' => 'Jika anda tidak mencipta akaun, tiada tindakan lanjut diperlukan.',
    ],

    'reset' => [
        'subject' => 'Tetapkan semula kata laluan anda',
        'line' => 'Anda menerima e-mel ini kerana kami menerima permintaan tetapan semula kata laluan untuk akaun anda.',
        'action' => 'Tetapkan semula kata laluan',
        'expire' => 'Pautan tetapan semula kata laluan ini akan tamat tempoh dalam :count minit.',
        'ignore' => 'Jika anda tidak meminta tetapan semula kata laluan, tiada tindakan lanjut diperlukan.',
    ],

    'contact' => [
        'subject' => 'Mesej hubungan baharu: :subject',
        'heading' => 'Mesej hubungan baharu',
        'name' => 'Nama',
        'email' => 'E-mel',
        'message_subject' => 'Subjek',
        'body' => 'Mesej',
    ],

    'social' => [
        'account' => 'Akaun :provider: :email',
        'action' => 'Semak akaun yang disambungkan',
        'connected' => [
            'subject' => ':provider telah disambungkan ke akaun anda',
            'line' => 'Akaun :provider telah disambungkan ke akaun RateGuru anda. Kini ia boleh digunakan untuk log masuk.',
            'not_you' => 'Jika ini bukan anda, buka profil anda dan putuskan sambungannya dengan segera.',
        ],
        'disconnected' => [
            'subject' => ':provider telah diputuskan sambungan daripada akaun anda',
            'line' => 'Akaun :provider telah diputuskan sambungan daripada akaun RateGuru anda. Ia tidak lagi boleh digunakan untuk log masuk.',
            'not_you' => 'Jika ini bukan anda, log masuk dan semak akaun yang disambungkan serta kata laluan anda.',
        ],
    ],
];
