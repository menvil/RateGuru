<?php

use App\Support\Configuration\EnvironmentValue;

return [
    'upload' => [
        'max_attempts' => EnvironmentValue::integer(env('RATE_LIMIT_UPLOAD_ATTEMPTS'), 5),
        'decay_seconds' => EnvironmentValue::integer(env('RATE_LIMIT_UPLOAD_DECAY_SECONDS'), 600),
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
