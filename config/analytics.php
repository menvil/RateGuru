<?php

/*
 * Growth → Experiments and Growth → Analytics. Plain values, never read from
 * the environment: each is a product rule, the same on every installation.
 */
return [

    // How long what the site records for Analytics is kept, in days: the days
    // an account did something on the site (user_activity_days).
    'retention_days' => [
        'activity' => 400,
    ],

    // How long a browser's last unpaid visit keeps a new arrival in the same session, in minutes.
    'session_minutes' => 30,

    // The longest period Analytics reads at a time, in days.
    'max_range_days' => 93,

    // How long the figures of a tab slow to read are kept once read, in minutes, unless Refresh asks for them again; 0 reads them every time.
    'read_cache_minutes' => 10,

    // The visitors a city needs before it gets a row of its own on Geography.
    'city_min_visitors' => 20,

    // The denominator a table row needs before its rate is shown rather than "—".
    'row_min_denominator' => 30,

    // How many markers a chart shows at most.
    'markers' => 6,

    // The days after the day of signing up (UTC) on which doing something activates an account: day 1 to this one.
    'activation_days' => 7,

    // The new visitors a segment of a paid source needs before its rate is shown and it gets a verdict.
    'segment_min_visitors' => 100,

    // The accounts a paid source needs in the period before its segments get verdicts.
    'segment_min_source_accounts' => 10,

    // The visitors a creative needs before it can be named a campaign's best.
    'best_creative_min_visitors' => 500,

    // How many sources the Overview table lists before the rest fold into one row.
    'sources_top' => 10,

];
