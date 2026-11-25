<?php

use App\Support\Configuration\EnvironmentValue;

return [
    'images' => [
        'max_kilobytes' => max(1, EnvironmentValue::integer(env('UPLOAD_IMAGE_MAX_KB'), 5120)),
        'max_width' => max(1, EnvironmentValue::integer(env('UPLOAD_IMAGE_MAX_WIDTH'), 6000)),
        'max_height' => max(1, EnvironmentValue::integer(env('UPLOAD_IMAGE_MAX_HEIGHT'), 6000)),
        // 16MP keeps peak ingest memory (~8 bytes/pixel: a source + a
        // rotated/flipped destination bitmap during EXIF correction) around
        // 128MiB, safely under the 256M php-fpm memory_limit configured in
        // infrastructure/config/php-fpm/rateguru-{production,staging}.conf —
        // 36MP would already be ~288MiB before any framework overhead.
        'max_pixels' => max(1, EnvironmentValue::integer(env('UPLOAD_IMAGE_MAX_PIXELS'), 16_000_000)),
        'jpeg_quality' => max(1, min(100, EnvironmentValue::integer(env('UPLOAD_IMAGE_JPEG_QUALITY'), 90))),
        'png_compression' => max(0, min(9, EnvironmentValue::integer(env('UPLOAD_IMAGE_PNG_COMPRESSION'), 6))),
        'webp_quality' => max(1, min(100, EnvironmentValue::integer(env('UPLOAD_IMAGE_WEBP_QUALITY'), 90))),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    // Videos and animated GIFs, as VideoIngestPolicy reads them: a clip of
    // up to a minute and a half, 100 MB, a 4K picture either way up, and 60
    // frames a second. Architectural limits, not per-environment knobs.
    'videos' => [
        'max_kilobytes' => 102_400,
        'max_duration_seconds' => 90,
        'max_width' => 3840,
        'max_height' => 3840,
        'max_frame_rate' => 60,
        // What the upload form takes as a video, by the type the file's
        // contents show; ffprobe has the final word on every one of them.
        // (application/mp4 is what some MP4 files read as.)
        'mimetypes' => ['video/mp4', 'application/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'image/gif'],
        // Videos one author may have waiting for processing at once. A host
        // has one media worker; this keeps one account from queueing an hour
        // of transcoding ahead of everyone else.
        'max_processing_per_author' => 2,
        // How long an upload counts as processing for that limit: past every
        // retry ProcessVideoAssetJob could make (5 attempts of at most 15
        // minutes, with their waits).
        'processing_window_minutes' => 180,
    ],
];
