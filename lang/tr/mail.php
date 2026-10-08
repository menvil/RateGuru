<?php

return [
    'greeting' => 'Merhaba :name!',
    'salutation' => 'Saygılarımızla, :app',
    'fallback_greeting' => 'Merhaba!',
    'error_greeting' => 'Hay aksi!',
    'rights_reserved' => 'Tüm hakları saklıdır.',
    'action_fallback' => '“:action” düğmesine tıklamakta sorun yaşıyorsanız aşağıdaki URL\'yi kopyalayıp web tarayıcınıza yapıştırın:',

    'verify' => [
        'subject' => 'E-posta adresinizi doğrulayın',
        'line' => 'Hesabınızın kurulumunu tamamlamak için lütfen e-posta adresinizi doğrulayın.',
        'action' => 'E-posta adresini doğrula',
        'ignore' => 'Bir hesap oluşturmadıysanız başka bir işlem yapmanıza gerek yoktur.',
    ],

    'reset' => [
        'subject' => 'Şifrenizi sıfırlayın',
        'line' => 'Hesabınız için bir şifre sıfırlama isteği aldığımız için bu e-postayı alıyorsunuz.',
        'action' => 'Şifreyi sıfırla',
        'expire' => 'Bu şifre sıfırlama bağlantısının süresi :count dakika içinde dolacak.',
        'ignore' => 'Şifre sıfırlama isteğinde bulunmadıysanız başka bir işlem yapmanıza gerek yoktur.',
    ],

    'contact' => [
        'subject' => 'Yeni iletişim mesajı: :subject',
        'heading' => 'Yeni iletişim mesajı',
        'name' => 'Ad',
        'email' => 'E-posta',
        'message_subject' => 'Konu',
        'body' => 'Mesaj',
    ],

    'social' => [
        'account' => ':provider hesabı: :email',
        'action' => 'Bağlı hesapları gözden geçir',
        'connected' => [
            'subject' => ':provider hesabınıza bağlandı',
            'line' => 'RateGuru hesabınıza bir :provider hesabı bağlandı. Artık giriş yapmak için kullanılabilir.',
            'not_you' => 'Bunu siz yapmadıysanız profilinizi açın ve bağlantıyı hemen kesin.',
        ],
        'disconnected' => [
            'subject' => ':provider bağlantısı hesabınızdan kaldırıldı',
            'line' => 'Bir :provider hesabının RateGuru hesabınızla bağlantısı kesildi. Artık giriş yapmak için kullanılamaz.',
            'not_you' => 'Bunu siz yapmadıysanız giriş yapın, bağlı hesaplarınızı ve şifrenizi kontrol edin.',
        ],
    ],
];
