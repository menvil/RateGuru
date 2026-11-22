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

    // The figures read ahead of the administrators every five minutes (analytics:warm-figures).
    // idle_hours: how long since an administrator last opened Analytics before nothing is read ahead.
    // views: the most views read ahead at once — the page's default period, then the periods administrators last chose.
    'read_ahead' => [
        'idle_hours' => 24,
        'views' => 4,
    ],

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

    // Analytics' own rows of the visits (analytics_visits, analytics:refresh-visits).
    // read: whether the tabs read them once they are filled; false reads the visits themselves.
    // cells: whether a read that fits them takes each day's people from the cells (analytics_cells) — PostgreSQL with the roaringbitmap extension only; false reads the rows.
    // game_days: whether a game's figures take their whole days from the sums of its days (analytics_game_days); false reads the rows.
    // stale_minutes: how long since they were last brought up to date before the tabs read the visits instead.
    // recheck_days: how many days back from today the nightly run reads again.
    // period_hours: whether a traffic period's funnel takes the hours of assignments counted from their sums (analytics_period_hours); false reads every assignment.
    // game_cells: whether a game's visitors and the people behind its attempts are taken from the people of its days (analytics_game_cells) where the cells are read; false reads the events and attempts.
    'rollup' => [
        'read' => true,
        'cells' => true,
        'game_days' => true,
        'stale_minutes' => 30,
        'recheck_days' => 60,
        'period_hours' => true,
        'game_cells' => true,
    ],

];
