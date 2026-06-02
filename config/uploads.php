<?php

return [
    'images' => [
        'max_kilobytes' => max(1, (int) env('UPLOAD_IMAGE_MAX_KB', 5120)),
        'max_width' => max(1, (int) env('UPLOAD_IMAGE_MAX_WIDTH', 6000)),
        'max_height' => max(1, (int) env('UPLOAD_IMAGE_MAX_HEIGHT', 6000)),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
