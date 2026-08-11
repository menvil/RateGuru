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

    /*
    |--------------------------------------------------------------------------
    | Variant write lock
    |--------------------------------------------------------------------------
    |
    | MediaVariantWriter serializes the whole write lifecycle (file write + DB
    | upsert + failure cleanup) for a given asset+variant name behind a
    | Cache::lock() keyed on both, so two workers regenerating the same
    | variant concurrently can't write/delete the same deterministic path out
    | from under each other. wait_seconds is how long a writer blocks for the
    | lock before giving up (LockTimeoutException); ttl_seconds is the lock's
    | own auto-release safety net if a holder crashes without releasing it.
    |
    */

    'variant_lock' => [
        'wait_seconds' => max(1, (int) env('MEDIA_VARIANT_LOCK_WAIT_SECONDS', 30)),
        'ttl_seconds' => max(1, (int) env('MEDIA_VARIANT_LOCK_TTL_SECONDS', 60)),
    ],
];
