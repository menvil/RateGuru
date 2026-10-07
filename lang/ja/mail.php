<?php

return [
    'greeting' => ':name さん、こんにちは！',
    'salutation' => ':app より',
    'fallback_greeting' => 'こんにちは！',
    'error_greeting' => 'エラーが発生しました！',
    'rights_reserved' => '無断転載を禁じます。',
    'action_fallback' => '「:action」ボタンをクリックできない場合は、以下のURLをコピーしてWebブラウザに貼り付けてください：',

    'verify' => [
        'subject' => 'メールアドレスの確認',
        'line' => 'アカウントの設定を完了するため、メールアドレスを確認してください。',
        'action' => 'メールアドレスを確認',
        'ignore' => 'アカウントを作成した覚えがない場合は、特に操作は必要ありません。',
    ],

    'reset' => [
        'subject' => 'パスワードのリセット',
        'line' => 'お使いのアカウントでパスワードリセットのリクエストを受け付けたため、このメールをお送りしています。',
        'action' => 'パスワードをリセット',
        'expire' => 'このパスワードリセット用リンクの有効期限は :count 分です。',
        'ignore' => 'パスワードのリセットをリクエストしていない場合は、特に操作は必要ありません。',
    ],

    'contact' => [
        'subject' => '新しいお問い合わせ：:subject',
        'heading' => '新しいお問い合わせ',
        'name' => '名前',
        'email' => 'メールアドレス',
        'message_subject' => '件名',
        'body' => 'メッセージ',
    ],

    'social' => [
        'account' => ':provider アカウント：:email',
        'action' => '連携済みアカウントを確認',
        'connected' => [
            'subject' => 'アカウントに :provider が連携されました',
            'line' => 'お使いの RateGuru アカウントに :provider アカウントが連携されました。今後はこのアカウントでログインできます。',
            'not_you' => 'お心当たりがない場合は、すぐにプロフィールを開いて連携を解除してください。',
        ],
        'disconnected' => [
            'subject' => 'アカウントから :provider の連携が解除されました',
            'line' => 'お使いの RateGuru アカウントから :provider アカウントの連携が解除されました。今後はこのアカウントでログインできません。',
            'not_you' => 'お心当たりがない場合は、ログインして連携済みアカウントとパスワードを確認してください。',
        ],
    ],
];
