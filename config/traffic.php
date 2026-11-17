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

    // How many runs of the chance working-out are kept.
    'chance_runs_kept' => 30,

];
