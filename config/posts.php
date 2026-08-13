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
    */

    'author_delete_retention_days' => max(0, (int) env('POST_AUTHOR_DELETE_RETENTION_DAYS', 30)),

];
