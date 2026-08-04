<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media disks
    |--------------------------------------------------------------------------
    |
    | These name Laravel filesystem disks (see config/filesystems.php), not a
    | storage provider. Moving from local storage to S3 (with or without a
    | CDN in front of it) — or to any other disk — is a matter of changing
    | the disk's own driver/url configuration, not this file or any code.
    |
    */

    'disks' => [
        'public' => env('MEDIA_PUBLIC_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media directories
    |--------------------------------------------------------------------------
    |
    | Base directory each media kind is stored under, within its disk.
    | MediaPathGenerator appends a collision-resistant, kind-specific path
    | beneath these.
    |
    */

    'directories' => [
        'post_images' => 'media/post-images',
        'avatars' => 'media/avatars',
    ],
];
