<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content lifecycle retention
    |--------------------------------------------------------------------------
    |
    | Raw values, deliberately NOT coerced here — validation lives in the
    | strict resolvers (App\Support\ContentLifecycle\*), which fail closed on
    | anything invalid: a misconfigured value must stop destructive cleanup,
    | never silently become 0.
    |
    | comments.author_delete_retention_days: how long a purely author-deleted
    | leaf comment stays in the database before the daily cleanup may
    | physically remove it. Distinct from post author retention
    | (POST_AUTHOR_DELETE_RETENTION_DAYS) and from the media grace.
    |
    | moderation.content_retention_days: how long FINALIZED moderation
    | removals (Hidden + moderation_removed_at) are retained before physical
    | purge. Empty/absent means DISABLED: finalized moderation content is
    | retained indefinitely until an operator explicitly enables a policy.
    | Ordinary reversible Hidden content NEVER purges regardless of this
    | value.
    |
    */

    'comments' => [
        'author_delete_retention_days' => env('COMMENT_AUTHOR_DELETE_RETENTION_DAYS', 30),
    ],

    'moderation' => [
        'content_retention_days' => env('MODERATION_CONTENT_RETENTION_DAYS'),
    ],

];
