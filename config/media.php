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

    /*
    |--------------------------------------------------------------------------
    | Media lifecycle
    |--------------------------------------------------------------------------
    |
    | Soft-deleting a MediaAsset (avatar replacement, an unreferenced asset
    | released after a user account is deleted) never removes its physical
    | file immediately — purge_grace_days is how long it stays soft-deleted,
    | still on disk, before media:purge is allowed to force-delete it (and
    | only once it's also unreferenced — see MediaReferenceChecker). This is
    | a recovery window, not a UI-facing undo feature.
    |
    | orphan_grace_hours guards physical-orphan detection specifically: a
    | file on disk with no matching MediaAsset/MediaVariant row at all is
    | only ever a *candidate* once it's older than this — an in-flight
    | upload from moments ago must never be flagged mid-write.
    |
    | purge_lock mirrors variant_lock's Cache::store('database') pattern
    | (see MediaVariantWriter), but media:purge takes a single non-blocking
    | attempt per asset rather than waiting, so only a ttl_seconds knob is
    | needed — see MediaLifecycleService::purgeLock().
    |
    */

    'lifecycle' => [
        'purge_grace_days' => max(0, (int) env('MEDIA_PURGE_GRACE_DAYS', 7)),
        'orphan_grace_hours' => max(0, (int) env('MEDIA_ORPHAN_GRACE_HOURS', 24)),
        'purge_lock' => [
            'ttl_seconds' => max(1, (int) env('MEDIA_PURGE_LOCK_TTL_SECONDS', 60)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media diagnostics
    |--------------------------------------------------------------------------
    |
    | RunMediaAuditJob's own Cache::store('database') lock (same pattern as
    | purge_lock above, keyed "media-audit:full"), a single non-blocking
    | attempt: a second full audit dispatched while one is already running
    | fails fast (MediaAuditAlreadyRunningException) rather than queueing up
    | behind it. ttl_seconds is generous relative to purge_lock's because a
    | full audit (a DB sweep plus a filesystem directory listing) can
    | legitimately run much longer than a single purge attempt — it's a
    | crash-safety net, not the expected run time.
    |
    | audit_run_retention: how many MediaAuditRun snapshots (and their
    | MediaAuditIssue rows, which cascade-delete with them) RunMediaAuditJob
    | keeps after each successful completion — no separate scheduler prunes
    | these.
    |
    */

    'diagnostics' => [
        'audit_lock' => [
            'ttl_seconds' => max(1, (int) env('MEDIA_AUDIT_LOCK_TTL_SECONDS', 1800)),
        ],
        'audit_run_retention' => max(1, (int) env('MEDIA_AUDIT_RUN_RETENTION', 30)),
    ],
];
