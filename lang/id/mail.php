<?php

return [
    'greeting' => 'Halo, :name!',
    'salutation' => 'Salam hangat, :app',
    'fallback_greeting' => 'Halo!',
    'error_greeting' => 'Waduh!',
    'rights_reserved' => 'Hak cipta dilindungi undang-undang.',
    'action_fallback' => 'Jika kamu kesulitan mengeklik tombol “:action”, salin dan tempel URL di bawah ini ke browsermu:',

    'verify' => [
        'subject' => 'Konfirmasi alamat emailmu',
        'line' => 'Konfirmasi alamat emailmu untuk menyelesaikan penyiapan akunmu.',
        'action' => 'Konfirmasi alamat email',
        'expires' => 'Akun yang tidak dikonfirmasi dalam :days hari akan dihapus.',
        'ignore' => 'Jika kamu tidak membuat akun, tidak ada tindakan lebih lanjut yang diperlukan.',
    ],

    'reset' => [
        'subject' => 'Atur ulang kata sandimu',
        'line' => 'Kamu menerima email ini karena kami menerima permintaan atur ulang kata sandi untuk akunmu.',
        'action' => 'Atur ulang kata sandi',
        'expire' => 'Tautan atur ulang kata sandi ini akan kedaluwarsa dalam :count menit.',
        'ignore' => 'Jika kamu tidak meminta atur ulang kata sandi, tidak ada tindakan lebih lanjut yang diperlukan.',
    ],

    'contact' => [
        'subject' => 'Pesan kontak baru: :subject',
        'heading' => 'Pesan kontak baru',
        'name' => 'Nama',
        'email' => 'Email',
        'message_subject' => 'Subjek',
        'body' => 'Pesan',
    ],

    'social' => [
        'account' => 'Akun :provider: :email',
        'action' => 'Tinjau akun terhubung',
        'connected' => [
            'subject' => ':provider telah terhubung ke akunmu',
            'line' => 'Sebuah akun :provider telah terhubung ke akun :site kamu. Sekarang akun itu bisa dipakai untuk masuk.',
            'not_you' => 'Jika ini bukan kamu, buka profilmu dan segera putuskan sambungannya.',
        ],
        'disconnected' => [
            'subject' => ':provider telah diputuskan dari akunmu',
            'line' => 'Sebuah akun :provider telah diputuskan dari akun :site kamu. Akun itu tidak bisa lagi dipakai untuk masuk.',
            'not_you' => 'Jika ini bukan kamu, masuk lalu periksa akun terhubung dan kata sandimu.',
        ],
    ],
];
