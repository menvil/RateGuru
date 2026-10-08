<?php

return [
    'greeting' => ':name，您好！',
    'salutation' => '祝好，:app',
    'fallback_greeting' => '您好！',
    'error_greeting' => '出错了！',
    'rights_reserved' => '保留所有权利。',
    'action_fallback' => '如果您无法点击“:action”按钮，请将下方链接复制并粘贴到浏览器中打开：',

    'verify' => [
        'subject' => '确认您的电子邮箱地址',
        'line' => '请确认您的电子邮箱地址，以完成账号设置。',
        'action' => '确认电子邮箱地址',
        'ignore' => '如果您并未创建账号，则无需进行任何操作。',
    ],

    'reset' => [
        'subject' => '重置您的密码',
        'line' => '您收到此邮件，是因为我们收到了您账号的密码重置请求。',
        'action' => '重置密码',
        'expire' => '此密码重置链接将在 :count 分钟后失效。',
        'ignore' => '如果您并未请求重置密码，则无需进行任何操作。',
    ],

    'contact' => [
        'subject' => '新的联系消息：:subject',
        'heading' => '新的联系消息',
        'name' => '姓名',
        'email' => '电子邮箱',
        'message_subject' => '主题',
        'body' => '消息',
    ],

    'social' => [
        'account' => ':provider 账号：:email',
        'action' => '查看已关联账号',
        'connected' => [
            'subject' => ':provider 已关联到您的账号',
            'line' => '一个 :provider 账号已关联到您的 RateGuru 账号，现在可以使用它登录。',
            'not_you' => '如果这不是您本人的操作，请立即打开个人资料并取消关联。',
        ],
        'disconnected' => [
            'subject' => ':provider 已与您的账号取消关联',
            'line' => '一个 :provider 账号已与您的 RateGuru 账号取消关联，此后无法再使用它登录。',
            'not_you' => '如果这不是您本人的操作，请登录并检查您的已关联账号和密码。',
        ],
    ],
];
