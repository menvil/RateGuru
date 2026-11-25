<?php

use App\Support\Configuration\EnvironmentValue;

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
        // Uploads nobody may fetch yet: a video as it arrived, until it has
        // been processed into what pages play. Laravel's own private disk,
        // on every target alike, so it is no key a target's environment has
        // to supply.
        'private' => 'local',
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
        'post_videos' => 'media/post-videos',
        // A video upload as it arrived, on the private disk, until it has
        // been processed.
        'originals' => 'media/originals',
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
        'wait_seconds' => max(1, EnvironmentValue::integer(env('MEDIA_VARIANT_LOCK_WAIT_SECONDS'), 30)),
        'ttl_seconds' => max(1, EnvironmentValue::integer(env('MEDIA_VARIANT_LOCK_TTL_SECONDS'), 60)),
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
        'purge_grace_days' => max(0, EnvironmentValue::integer(env('MEDIA_PURGE_GRACE_DAYS'), 7)),
        'orphan_grace_hours' => max(0, EnvironmentValue::integer(env('MEDIA_ORPHAN_GRACE_HOURS'), 24)),
        'purge_lock' => [
            'ttl_seconds' => max(1, EnvironmentValue::integer(env('MEDIA_PURGE_LOCK_TTL_SECONDS'), 60)),
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
    | crash-safety net, not the expected run time. The default here
    | (3660s) is deliberately longer than RunMediaAuditJob::$timeout
    | (3600s) — a shorter TTL would let the lock expire mid-run and allow a
    | second full audit to start concurrently, exactly what it exists to
    | prevent. RunMediaAuditJob::lockTtlSeconds() additionally enforces this
    | relationship at runtime (max(this value, $timeout + 60)) regardless of
    | what this env var is actually set to, so a misconfigured value here
    | can't reintroduce the race — it only ever raises the effective floor.
    |
    | audit_run_retention: how many MediaAuditRun snapshots (and their
    | MediaAuditIssue rows, which cascade-delete with them) RunMediaAuditJob
    | keeps after each successful completion — no separate scheduler prunes
    | these.
    |
    */

    'diagnostics' => [
        'audit_lock' => [
            'ttl_seconds' => max(1, EnvironmentValue::integer(env('MEDIA_AUDIT_LOCK_TTL_SECONDS'), 3660)),
        ],
        'audit_run_retention' => max(1, EnvironmentValue::integer(env('MEDIA_AUDIT_RUN_RETENTION'), 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video tools
    |--------------------------------------------------------------------------
    |
    | ffmpeg and ffprobe are the pinned static build install-bootstrap-runtime
    | installs on every host (FFMPEG_* in
    | infrastructure/config/external-runtimes/versions.env), and CI installs
    | the same one; locally, `brew install ffmpeg`. Every run goes through
    | MediaToolRunner — an argument list, never a shell line — and every
    | input through FfmpegInput's whitelists. probe_timeout_seconds bounds
    | one ffprobe read: reading a container's headers takes well under a
    | second, so the bound only matters for a file built to stall it.
    |
    | A bare binary name is looked up on PATH, then in search_directories, in
    | order — PHP-FPM workers run with no PATH at all. /usr/local/bin is where
    | install-bootstrap-runtime installs the pinned ffmpeg build, so it comes
    | before /usr/bin, where a host prepared earlier may still carry Ubuntu's
    | own, older ffmpeg.
    |
    */

    'video' => [
        'ffmpeg_binary' => 'ffmpeg',
        'ffprobe_binary' => 'ffprobe',
        'search_directories' => ['/usr/local/bin', '/usr/bin'],
        'probe_timeout_seconds' => 30,
        // One ffmpeg run (a rendition or the poster). A 90-second clip at
        // 1080 takes a few minutes on two threads; ProcessVideoAssetJob's
        // own timeout bounds all of them together.
        'transcode_timeout_seconds' => 600,
    ],
];
