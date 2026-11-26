<?php

use App\Support\Configuration\EnvironmentValue;

return [
    'upload' => [
        'max_attempts' => EnvironmentValue::integer(env('RATE_LIMIT_UPLOAD_ATTEMPTS'), 5),
        'decay_seconds' => EnvironmentValue::integer(env('RATE_LIMIT_UPLOAD_DECAY_SECONDS'), 600),
    ],

    // Links read for a post (a page's or a post's on another site): each
    // fetches from the outside, so they are counted before anything is.
    'link_import' => [
        'max_attempts' => 20,
        'decay_seconds' => 600,
    ],

    // Videos downloaded for an author, each a worker's minutes at most.
    'video_import' => [
        'max_attempts' => 6,
        'decay_seconds' => 3600,
    ],

    'comment' => [
        'max_attempts' => EnvironmentValue::integer(env('RATE_LIMIT_COMMENT_ATTEMPTS'), 10),
        'decay_seconds' => EnvironmentValue::integer(env('RATE_LIMIT_COMMENT_DECAY_SECONDS'), 60),
    ],

    // Accounts one network may create. Fixed rather than a setting every host
    // has to carry; a shared connection is why they are not lower.
    'registration' => [
        'per_hour' => 5,
        'per_day' => 15,
        // While Cloudflare cannot be asked: sign-ups keep working, more slowly.
        'per_hour_without_challenge' => 1,
    ],

    'report' => [
        'max_attempts' => EnvironmentValue::integer(env('RATE_LIMIT_REPORT_ATTEMPTS'), 10),
        'decay_seconds' => EnvironmentValue::integer(env('RATE_LIMIT_REPORT_DECAY_SECONDS'), 600),
    ],

    'vote' => [
        'max_attempts' => EnvironmentValue::integer(env('RATE_LIMIT_VOTE_ATTEMPTS'), 60),
        'decay_seconds' => EnvironmentValue::integer(env('RATE_LIMIT_VOTE_DECAY_SECONDS'), 60),
    ],

    // Each one is a mail to an address not proved yet, so a fixed ceiling
    // rather than a setting every host has to carry.
    'email_confirmation' => [
        'per_minute' => 1,
        'per_hour' => 5,
    ],
];
