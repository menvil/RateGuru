<?php

return [
    'failed' => 'Thông tin đăng nhập không khớp với dữ liệu của chúng tôi.',
    'password' => 'Mật khẩu đã nhập không chính xác.',
    'throttle' => 'Bạn đã thử đăng nhập quá nhiều lần. Vui lòng thử lại sau :seconds giây.',
    'username_unavailable' => 'Không thể tạo tên người dùng duy nhất. Vui lòng thử một tên khác.',
    'session_ended' => 'Phiên của bạn đã kết thúc vì thông tin đăng nhập của tài khoản này đã thay đổi. Vui lòng đăng nhập lại.',

    'prompts' => [
        'no_account' => 'Chưa có tài khoản?',
        'have_account' => 'Đã có tài khoản?',
    ],

    'fields' => [
        'name' => 'Tên',
        'username' => 'Tên người dùng',
        'email' => 'Địa chỉ email',
        'password' => 'Mật khẩu',
        'password_confirmation' => 'Xác nhận mật khẩu',
    ],

    'login' => [
        'title' => 'Đăng nhập',
        'action' => 'Đăng nhập',
        'remember' => 'Ghi nhớ đăng nhập',
        'forgot_password' => 'Quên mật khẩu?',
    ],

    'register' => [
        'title' => 'Đăng ký',
        'action' => 'Đăng ký',
    ],

    'divider' => 'hoặc',

    'forgot_password' => [
        'intro' => 'Quên mật khẩu? Không sao cả. Chỉ cần cho chúng tôi biết địa chỉ email của bạn, chúng tôi sẽ gửi cho bạn một liên kết đặt lại mật khẩu để bạn có thể chọn mật khẩu mới.',
        'action' => 'Gửi liên kết đặt lại mật khẩu',
    ],

    'reset_password' => [
        'action' => 'Đặt lại mật khẩu',
    ],

    'confirm_password' => [
        'intro' => 'Đây là khu vực bảo mật của ứng dụng. Vui lòng xác nhận mật khẩu trước khi tiếp tục.',
        'action' => 'Xác nhận',
    ],

    'verify_email' => [
        'intro' => 'Cảm ơn bạn đã đăng ký! Trước khi bắt đầu, bạn vui lòng xác minh địa chỉ email bằng cách nhấp vào liên kết chúng tôi vừa gửi cho bạn. Nếu bạn chưa nhận được email, chúng tôi sẵn lòng gửi lại.',
        'link_sent' => 'Một liên kết xác minh mới đã được gửi đến địa chỉ email bạn đã cung cấp khi đăng ký.',
        'resend' => 'Gửi lại email xác minh',
    ],

    'social' => [
        'log_in_with' => 'Đăng nhập bằng :provider',
        'unavailable' => 'Hiện không thể đăng nhập bằng :provider.',
        'unavailable_notice' => 'Đăng nhập bằng :provider đã bị tắt. Nếu trước đây bạn đăng nhập bằng :provider, hãy đặt mật khẩu cho tài khoản của mình — chúng tôi sẽ gửi liên kết qua email cho bạn.',
        'unavailable_set_password' => 'Đặt mật khẩu',
        'cancelled' => 'Đăng nhập bằng :provider đã bị hủy. Vui lòng thử lại.',
        'failed' => 'Chúng tôi không thể đăng nhập cho bạn bằng :provider. Vui lòng thử lại.',
        'expired' => 'Phiên đăng nhập :provider của bạn đã hết hạn trước khi hoàn tất. Vui lòng bắt đầu lại.',
        'email_missing' => 'Tài khoản :provider của bạn không chia sẻ địa chỉ email nên không thể dùng để đăng nhập.',
        'email_taken' => 'Địa chỉ email của tài khoản :provider này thuộc về một tài khoản RateGuru khác. Hãy đăng nhập vào tài khoản đó để liên kết :provider tại đó.',
        'link_expired' => 'Phiên liên kết tài khoản của bạn đã hết hạn hoặc đã thay đổi. Vui lòng bắt đầu liên kết tài khoản lại.',
        'already_signed_in' => 'Bạn đã đăng nhập. Để đăng nhập thêm bằng :provider, hãy liên kết trong mục “Tài khoản đã liên kết”.',
        'already_linked' => 'Tài khoản :provider này đã được liên kết với một tài khoản khác.',
        'provider_already_linked' => 'Tài khoản của bạn đã được liên kết với một tài khoản :provider khác.',
        'pending_link' => 'Địa chỉ email này đã có tài khoản. Hãy đăng nhập theo cách bạn đã dùng trước đây — bằng mật khẩu hoặc bằng một tài khoản mạng xã hội khác — và tài khoản :provider của bạn sẽ được liên kết tự động.',
        'password_removed' => 'Email của bạn đã được xác nhận qua :provider. Mật khẩu từng được đặt cho địa chỉ email này đã bị xóa — bạn có thể đặt mật khẩu mới trong hồ sơ của mình.',
    ],
];
