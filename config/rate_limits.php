<?php

return [
    'upload' => [
        'max_attempts' => (int) env('RATE_LIMIT_UPLOAD_ATTEMPTS', 5),
        'decay_seconds' => (int) env('RATE_LIMIT_UPLOAD_DECAY_SECONDS', 600),
    ],

    'comment' => [
        'max_attempts' => (int) env('RATE_LIMIT_COMMENT_ATTEMPTS', 10),
        'decay_seconds' => (int) env('RATE_LIMIT_COMMENT_DECAY_SECONDS', 60),
    ],

    'report' => [
        'max_attempts' => (int) env('RATE_LIMIT_REPORT_ATTEMPTS', 10),
        'decay_seconds' => (int) env('RATE_LIMIT_REPORT_DECAY_SECONDS', 600),
    ],

    'vote' => [
        'max_attempts' => (int) env('RATE_LIMIT_VOTE_ATTEMPTS', 60),
        'decay_seconds' => (int) env('RATE_LIMIT_VOTE_DECAY_SECONDS', 60),
    ],
];
