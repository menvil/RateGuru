<?php

/*
 * Growth → Traffic sources (docs/architecture/traffic.md). Plain values,
 * never read from the environment: each is a product rule, the same on
 * every installation.
 */
return [

    // How long what the tracker records is kept, in days.
    'retention_days' => [
        'visits' => 400,
        'visit_details' => 90,
        'postbacks' => 90,
        'spend_days' => 400,
        'block_events' => 400,
    ],

    // Query parameters the site's own pages read: a network's link can't
    // use them for its tokens, or the page would read the token instead.
    'reserved_params' => ['search', 'tag', 'category', 'ratings', 'sort', 'feed', 'tab', 'page', 'vote', 'ask'],

    // When a paid visit settles, telling its network once what it was worth
    // (docs/architecture/traffic.md, "Settling"): its account confirmed, so
    // many minutes without a new step, or so many hours after the click,
    // whichever comes first.
    'settle' => [
        'idle_minutes' => 30,
        'after_hours' => 24,
    ],

    // A campaign with no click for this many days is archived by itself.
    'quiet_campaign_days' => 30,

    // How many postback rules one source may have.
    'rules_per_source' => 20,

    // How many runs of the chance working-out are kept.
    'chance_runs_kept' => 30,

    // When Sites & zones hints that a site's paid impressions mostly never
    // reach RateGuru (docs/architecture/traffic.md, "Reach"): at least so
    // many impressions over closed days, and reached below this percentage
    // of its source's share over the same days. A hint, never blocked by
    // itself.
    'reach' => [
        'min_impressions' => 500,
        'below_percent_of_source' => 70,
    ],

];
