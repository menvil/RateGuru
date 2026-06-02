<?php

return [
    'images' => [
        'max_kilobytes' => env('UPLOAD_IMAGE_MAX_KB', 5120),
        'max_width' => env('UPLOAD_IMAGE_MAX_WIDTH', 6000),
        'max_height' => env('UPLOAD_IMAGE_MAX_HEIGHT', 6000),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
