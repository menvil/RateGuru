<?php

return [
    'images' => [
        'max_kilobytes' => max(1, (int) env('UPLOAD_IMAGE_MAX_KB', 5120)),
        'max_width' => max(1, (int) env('UPLOAD_IMAGE_MAX_WIDTH', 6000)),
        'max_height' => max(1, (int) env('UPLOAD_IMAGE_MAX_HEIGHT', 6000)),
        // 16MP keeps peak ingest memory (~8 bytes/pixel: a source + a
        // rotated/flipped destination bitmap during EXIF correction) around
        // 128MiB, safely under the 256M php-fpm memory_limit configured in
        // infrastructure/config/php-fpm/rateguru-{production,staging}.conf —
        // 36MP would already be ~288MiB before any framework overhead.
        'max_pixels' => max(1, (int) env('UPLOAD_IMAGE_MAX_PIXELS', 16_000_000)),
        'jpeg_quality' => max(1, min(100, (int) env('UPLOAD_IMAGE_JPEG_QUALITY', 90))),
        'png_compression' => max(0, min(9, (int) env('UPLOAD_IMAGE_PNG_COMPRESSION', 6))),
        'webp_quality' => max(1, min(100, (int) env('UPLOAD_IMAGE_WEBP_QUALITY', 90))),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
