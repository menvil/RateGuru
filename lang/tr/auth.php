<?php

return [
    'failed' => 'Bu bilgiler kayıtlarımızla eşleşmiyor.',
    'password' => 'Girdiğiniz şifre yanlış.',
    'throttle' => 'Çok fazla giriş denemesi yapıldı. Lütfen :seconds saniye sonra tekrar deneyin.',
    'username_unavailable' => 'Benzersiz bir kullanıcı adı oluşturulamadı. Lütfen farklı bir ad deneyin.',
    'session_ended' => 'Bu hesabın giriş bilgileri değiştiği için oturumunuz sonlandırıldı. Lütfen tekrar giriş yapın.',

    'prompts' => [
        'no_account' => 'Hesabınız yok mu?',
        'have_account' => 'Zaten hesabınız var mı?',
    ],

    'fields' => [
        'name' => 'Ad',
        'username' => 'Kullanıcı adı',
        'email' => 'E-posta',
        'password' => 'Şifre',
        'password_confirmation' => 'Şifreyi onayla',
    ],

    'login' => [
        'title' => 'Giriş yap',
        'action' => 'Giriş yap',
        'remember' => 'Beni hatırla',
        'forgot_password' => 'Şifrenizi mi unuttunuz?',
    ],

    'register' => [
        'title' => 'Kaydol',
        'action' => 'Kaydol',
    ],

    'divider' => 'veya',

    'forgot_password' => [
        'intro' => 'Şifrenizi mi unuttunuz? Sorun değil. E-posta adresinizi bize bildirin, size yeni bir şifre seçmenizi sağlayacak bir şifre sıfırlama bağlantısı gönderelim.',
        'action' => 'Şifre sıfırlama bağlantısı gönder',
    ],

    'reset_password' => [
        'action' => 'Şifreyi sıfırla',
    ],

    'confirm_password' => [
        'intro' => 'Burası uygulamanın güvenli bir alanıdır. Devam etmeden önce lütfen şifrenizi onaylayın.',
        'action' => 'Onayla',
    ],

    'verify_email' => [
        'intro' => 'Kaydolduğunuz için teşekkürler! Başlamadan önce, size az önce e-postayla gönderdiğimiz bağlantıya tıklayarak e-posta adresinizi doğrular mısınız? E-postayı almadıysanız size memnuniyetle yenisini göndeririz.',
        'link_sent' => 'Kayıt sırasında belirttiğiniz e-posta adresine yeni bir doğrulama bağlantısı gönderildi.',
        'resend' => 'Doğrulama e-postasını yeniden gönder',
    ],

    'social' => [
        'log_in_with' => ':provider ile giriş yap',
        'unavailable' => ':provider ile giriş şu anda kullanılamıyor.',
        'unavailable_notice' => ':provider ile giriş kapatıldı. Daha önce :provider ile giriş yapıyorduysanız hesabınız için bir şifre belirleyin — size e-postayla bir bağlantı göndereceğiz.',
        'unavailable_set_password' => 'Şifre belirle',
        'cancelled' => ':provider ile giriş iptal edildi. Lütfen tekrar deneyin.',
        'failed' => ':provider ile giriş yapmanızı sağlayamadık. Lütfen tekrar deneyin.',
        'expired' => ':provider ile giriş işleminin süresi, işlem tamamlanmadan doldu. Lütfen baştan başlayın.',
        'email_missing' => ':provider hesabınız bir e-posta adresi paylaşmadı, bu nedenle giriş için kullanılamaz.',
        'email_taken' => 'Bu :provider hesabının e-posta adresi başka bir RateGuru hesabına ait. :provider bağlantısını orada kurmak için o hesaba giriş yapın.',
        'link_expired' => 'Hesap bağlama oturumunuzun süresi doldu veya oturum değişti. Lütfen hesabı bağlamaya yeniden başlayın.',
        'already_signed_in' => 'Zaten giriş yaptınız. :provider ile de giriş yapabilmek için “Bağlı hesaplar” bölümünden bağlantı kurun.',
        'already_linked' => 'Bu :provider hesabı zaten başka bir hesaba bağlı.',
        'provider_already_linked' => 'Hesabınız zaten başka bir :provider hesabına bağlı.',
        'pending_link' => 'Bu e-posta adresine ait bir hesap zaten var. Daha önce nasıl giriş yaptıysanız öyle giriş yapın — şifrenizle veya başka bir sosyal hesapla — :provider hesabınız otomatik olarak bağlanacak.',
        'password_removed' => 'E-posta adresiniz :provider üzerinden doğrulandı. Bu e-posta adresi için daha önce belirlenmiş bir şifre kaldırıldı — profilinizden yeni bir şifre belirleyebilirsiniz.',
    ],
];
