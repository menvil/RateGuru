<?php

use App\Data\Games\ContentSetDraft;
use App\Services\Games\GamePreviewDrafts;

/*
 * A Content tab's unsaved set reaches its preview as it was handed over; a
 * draft that names no crowd rules is read with a new set's.
 */

it('reads a draft that names no crowd rules with a new set\'s', function () {
    $drafts = app(GamePreviewDrafts::class);
    $stamp = $drafts->put(7, 3, new ContentSetDraft(scoring: [], rounds: [], profiles: []));

    expect($drafts->get(7, 3, $stamp)?->scoring)->toBe([
        'points_per_round' => 1,
        'crowd_min_votes' => config('games.crowd.min_votes'),
        'crowd_min_gap' => config('games.crowd.min_gap'),
    ]);
});
