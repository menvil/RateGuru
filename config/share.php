<?php

return [

    'enabled' => true,

    'open_graph' => [
        'width' => 1200,
        'height' => 630,
        'mime_type' => 'image/jpeg',
        'jpeg_quality' => 82,
        'minimum_jpeg_quality' => 42,
        'max_kilobytes' => 700,
        'fallback_path' => 'images/og/rateguru-post-placeholder.png',
        'fallback_mime_type' => 'image/png',
    ],

    'providers' => [
        'copy_link' => [
            'label_key' => 'sharing.copy_link',
            'enabled' => true,
        ],
        'native' => [
            'label_key' => 'sharing.native',
            'enabled' => true,
        ],
        'facebook' => [
            'label_key' => 'sharing.facebook',
            'enabled' => true,
        ],
        'x' => [
            'label_key' => 'sharing.x',
            'enabled' => true,
        ],
        'telegram' => [
            'label_key' => 'sharing.telegram',
            'enabled' => true,
        ],
        'whatsapp' => [
            'label_key' => 'sharing.whatsapp',
            'enabled' => true,
        ],
        'reddit' => [
            'label_key' => 'sharing.reddit',
            'enabled' => true,
        ],
        'pinterest' => [
            'label_key' => 'sharing.pinterest',
            'enabled' => true,
        ],
        'email' => [
            'label_key' => 'sharing.email',
            'enabled' => true,
        ],
    ],

];
