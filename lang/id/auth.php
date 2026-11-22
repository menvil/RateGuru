<?php

return [
    'failed' => 'Informasi masuk ini tidak cocok dengan data kami.',
    'password' => 'Kata sandi yang dimasukkan salah.',
    'throttle' => 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam :seconds detik.',
    'username_unavailable' => 'Tidak dapat membuat nama pengguna yang unik. Silakan coba nama lain.',
    'session_ended' => 'Sesimu berakhir karena informasi masuk akun ini berubah. Silakan masuk lagi.',

    'prompts' => [
        'no_account' => 'Belum punya akun?',
        'have_account' => 'Sudah punya akun?',
        'remembered_password' => 'Sudah ingat kata sandimu?',
    ],

    'fields' => [
        'name' => 'Nama',
        'username' => 'Nama pengguna',
        'email' => 'Email',
        'password' => 'Kata sandi',
        'password_confirmation' => 'Konfirmasi kata sandi',
    ],

    'login' => [
        'title' => 'Masuk',
        'action' => 'Masuk',
        'remember' => 'Ingat saya',
        'forgot_password' => 'Lupa kata sandi?',
    ],

    'register' => [
        'title' => 'Daftar',
        'action' => 'Daftar',
    ],

    'divider' => 'atau',

    'forgot_password' => [
        'title' => 'Atur ulang kata sandi',
        'intro' => 'Lupa kata sandi? Tidak masalah. Beri tahu kami alamat emailmu, dan kami akan mengirimkan tautan untuk mengatur ulang kata sandi agar kamu bisa memilih yang baru.',
        'action' => 'Kirim tautan atur ulang kata sandi',
    ],

    'reset_password' => [
        'action' => 'Atur ulang kata sandi',
    ],

    'confirm_password' => [
        'intro' => 'Ini adalah area aman aplikasi. Konfirmasi kata sandimu sebelum melanjutkan.',
        'action' => 'Konfirmasi',
    ],

    'verify_email' => [
        'intro' => 'Terima kasih sudah mendaftar! Sebelum mulai, bisakah kamu memverifikasi alamat emailmu dengan mengeklik tautan yang baru saja kami kirimkan? Jika kamu tidak menerima email tersebut, kami akan dengan senang hati mengirimkan yang baru.',
        'link_sent' => 'Tautan verifikasi baru telah dikirim ke alamat email yang kamu berikan saat mendaftar.',
        'resend' => 'Kirim ulang email verifikasi',
    ],

    'social' => [
        'log_in_with' => 'Masuk dengan :provider',
        'unavailable' => 'Masuk dengan :provider sedang tidak tersedia.',
        'unavailable_notice' => 'Masuk dengan :provider dinonaktifkan. Jika dulu kamu masuk dengan :provider, buat kata sandi untuk akunmu — kami akan mengirimkan tautannya lewat email.',
        'unavailable_set_password' => 'Buat kata sandi',
        'cancelled' => 'Masuk dengan :provider dibatalkan. Silakan coba lagi.',
        'failed' => 'Gagal masuk dengan :provider. Silakan coba lagi.',
        'expired' => 'Proses masuk dengan :provider kedaluwarsa sebelum selesai. Silakan mulai lagi.',
        'email_missing' => 'Akun :provider kamu tidak membagikan alamat email, jadi tidak bisa dipakai untuk masuk.',
        'email_taken' => 'Alamat email akun :provider ini milik akun :site lain. Masuk ke akun tersebut untuk menghubungkan :provider di sana.',
        'link_expired' => 'Sesi penghubungan akunmu kedaluwarsa atau berubah. Silakan mulai menghubungkan akun lagi.',
        'already_signed_in' => 'Kamu sudah masuk. Agar juga bisa masuk dengan :provider, hubungkan di bagian Akun terhubung.',
        'already_linked' => 'Akun :provider ini sudah terhubung ke akun lain.',
        'provider_already_linked' => 'Akunmu sudah terhubung ke akun :provider lain.',
        'pending_link' => 'Alamat email ini sudah memiliki akun. Masuk seperti sebelumnya — dengan kata sandi atau akun media sosial lain — dan akun :provider kamu akan terhubung secara otomatis.',
        'password_removed' => 'Emailmu sudah dikonfirmasi melalui :provider. Kata sandi yang sebelumnya dibuat untuk alamat email ini telah dihapus — kamu bisa membuat yang baru di profilmu.',
    ],
];
