<?php

return [
    'failed' => '您输入的登录信息与我们的记录不符。',
    'password' => '密码错误。',
    'throttle' => '登录尝试次数过多，请在 :seconds 秒后重试。',
    'username_unavailable' => '无法生成唯一的用户名，请尝试其他名称。',
    'session_ended' => '由于此账号的登录信息已更改，您的会话已结束。请重新登录。',

    'prompts' => [
        'no_account' => '还没有账号？',
        'have_account' => '已有账号？',
    ],

    'fields' => [
        'name' => '姓名',
        'username' => '用户名',
        'email' => '电子邮箱',
        'password' => '密码',
        'password_confirmation' => '确认密码',
    ],

    'login' => [
        'title' => '登录',
        'action' => '登录',
        'remember' => '记住我',
        'forgot_password' => '忘记密码？',
    ],

    'register' => [
        'title' => '注册',
        'action' => '注册',
    ],

    'divider' => '或',

    'forgot_password' => [
        'intro' => '忘记密码？没关系。只需告诉我们您的电子邮箱地址，我们会向您发送一封包含密码重置链接的邮件，您可以通过该链接设置新密码。',
        'action' => '发送密码重置链接',
    ],

    'reset_password' => [
        'action' => '重置密码',
    ],

    'confirm_password' => [
        'intro' => '这是应用的安全区域。请在继续之前确认您的密码。',
        'action' => '确认',
    ],

    'verify_email' => [
        'intro' => '感谢注册！开始使用之前，请点击我们刚刚发送到您邮箱的链接，验证您的电子邮箱地址。如果您没有收到邮件，我们很乐意再发送一封。',
        'link_sent' => '新的验证链接已发送到您注册时填写的电子邮箱地址。',
        'resend' => '重新发送验证邮件',
    ],

    'social' => [
        'log_in_with' => '使用 :provider 登录',
        'unavailable' => '目前无法使用 :provider 登录。',
        'unavailable_notice' => ':provider 登录已关闭。如果您之前使用 :provider 登录，请为账号设置密码——我们会通过邮件向您发送链接。',
        'unavailable_set_password' => '设置密码',
        'cancelled' => '已取消使用 :provider 登录，请重试。',
        'failed' => '无法使用 :provider 为您登录，请重试。',
        'expired' => '您的 :provider 登录在完成前已过期，请重新开始。',
        'email_missing' => '您的 :provider 账号未提供电子邮箱地址，因此无法用于登录。',
        'email_taken' => '此 :provider 账号的电子邮箱地址已属于另一个 RateGuru 账号。请登录该账号，并在那里关联 :provider。',
        'link_expired' => '您的账号关联会话已过期或已更改，请重新开始关联账号。',
        'already_signed_in' => '您已登录。如需同时使用 :provider 登录，请在“已关联账号”中进行关联。',
        'already_linked' => '此 :provider 账号已关联到其他账号。',
        'provider_already_linked' => '您的账号已关联到另一个 :provider 账号。',
        'pending_link' => '此电子邮箱地址已注册账号。请使用之前的方式登录（使用密码或其他社交账号），您的 :provider 账号将自动关联。',
        'password_removed' => '您的电子邮箱已通过 :provider 验证。此前为该电子邮箱地址设置的密码已被移除——您可以在个人资料中设置新密码。',
    ],
];
