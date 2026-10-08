<?php

return [
    'greeting' => 'Xin chào :name!',
    'salutation' => 'Trân trọng, :app',
    'fallback_greeting' => 'Xin chào!',
    'error_greeting' => 'Rất tiếc!',
    'rights_reserved' => 'Bảo lưu mọi quyền.',
    'action_fallback' => 'Nếu bạn gặp sự cố khi nhấp vào nút “:action”, hãy sao chép và dán URL bên dưới vào trình duyệt web của bạn:',

    'verify' => [
        'subject' => 'Xác nhận địa chỉ email của bạn',
        'line' => 'Vui lòng xác nhận địa chỉ email để hoàn tất thiết lập tài khoản.',
        'action' => 'Xác nhận địa chỉ email',
        'ignore' => 'Nếu bạn không tạo tài khoản, bạn không cần làm gì thêm.',
    ],

    'reset' => [
        'subject' => 'Đặt lại mật khẩu của bạn',
        'line' => 'Bạn nhận được email này vì chúng tôi đã nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn.',
        'action' => 'Đặt lại mật khẩu',
        'expire' => 'Liên kết đặt lại mật khẩu này sẽ hết hạn sau :count phút.',
        'ignore' => 'Nếu bạn không yêu cầu đặt lại mật khẩu, bạn không cần làm gì thêm.',
    ],

    'contact' => [
        'subject' => 'Tin nhắn liên hệ mới: :subject',
        'heading' => 'Tin nhắn liên hệ mới',
        'name' => 'Tên',
        'email' => 'Địa chỉ email',
        'message_subject' => 'Chủ đề',
        'body' => 'Nội dung',
    ],

    'social' => [
        'account' => 'Tài khoản :provider: :email',
        'action' => 'Xem lại tài khoản đã liên kết',
        'connected' => [
            'subject' => ':provider đã được liên kết với tài khoản của bạn',
            'line' => 'Một tài khoản :provider đã được liên kết với tài khoản RateGuru của bạn. Giờ đây bạn có thể dùng tài khoản này để đăng nhập.',
            'not_you' => 'Nếu đây không phải là bạn, hãy mở hồ sơ và hủy liên kết ngay lập tức.',
        ],
        'disconnected' => [
            'subject' => ':provider đã bị hủy liên kết khỏi tài khoản của bạn',
            'line' => 'Một tài khoản :provider đã bị hủy liên kết khỏi tài khoản RateGuru của bạn. Tài khoản này không còn dùng để đăng nhập được nữa.',
            'not_you' => 'Nếu đây không phải là bạn, hãy đăng nhập và kiểm tra các tài khoản đã liên kết cũng như mật khẩu của bạn.',
        ],
    ],
];
