<?php

/*
 * Growth → Games. Plain values, never read from the environment: none of them
 * differs between installations, and each one is a product rule documented
 * in docs/architecture/games.md.
 */
return [

    // What a new content set asks of the crowd before a round scores by it:
    // the site votes each post needs, and the gap, in percentage points,
    // between the two posts' upvote shares — none, so the larger share wins
    // however small its lead, unless the administrator asks for one.
    'crowd' => [
        'min_votes' => 10,
        'min_gap' => 0,
    ],

    // The anonymous visitor's first-party cookie, used only to keep them on
    // the version they were given and to count them once. Only its SHA-256 is
    // stored.
    'visitor_cookie' => [
        'name' => 'rg_game_visitor',
        'lifetime_minutes' => 60 * 24 * 365,
    ],

    // How long after an attempt started a sign-up or sign-in in the same
    // session still counts as the attempt's, and how long an attempt waits
    // before it counts as abandoned.
    'attribution_hours' => 24,
    'abandoned_after_hours' => 24,

    // How many of a browser's recent attempts its session remembers.
    'session_attempts' => 20,

    // Per visitor: attempts started, answers given and emails left, each as
    // [attempts, per seconds].
    'rate_limits' => [
        'start' => [30, 3600],
        'answer' => [120, 60],
        'email' => [5, 600],
        'view' => [120, 60],
    ],

    // How long tracked events and captured emails are kept, in days.
    'retention_days' => [
        'events' => 400,
        'email_captures' => 365,
    ],

];
