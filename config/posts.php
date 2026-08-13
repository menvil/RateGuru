<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Author-deletion retention
    |--------------------------------------------------------------------------
    |
    | How many days an author-deleted post stays recoverable by its owner
    | before the retention purge may permanently remove it together with its
    | discussion graph (docs/architecture/post-lifecycle.md). This is product
    | retention for the post itself — distinct from MEDIA_PURGE_GRACE_DAYS,
    | which governs physical media files after their asset is released.
    |
    | 0 is supported: deletion still soft-deletes first, but the restore
    | window is immediately expired and the next purge run may hard-purge.
    |
    | The raw value is deliberately NOT coerced here. Validation lives in
    | App\Support\Posts\PostRetention::days(), which fails closed on
    | anything that is not an integer >= 0 — a misconfigured value must
    | stop the purge, not silently become an immediate one.
    |
    */

    'author_delete_retention_days' => env('POST_AUTHOR_DELETE_RETENTION_DAYS', 30),

];
