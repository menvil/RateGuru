<?php

it('documents configurable voting architecture', function () {
    $path = base_path('docs/rating/configurable-voting.md');

    expect($path)->toBeFile();

    $content = file_get_contents($path);

    expect($content)
        ->toContain('rating_groups')
        ->toContain('rating_options')
        ->toContain('rating_votes')
        ->toContain('one vote per user, post, and group')
        ->toContain('archive');
});

it('documents dynamic public rating groups', function () {
    $content = file_get_contents(base_path('docs/rating/configurable-voting.md'));

    expect($content)
        ->toContain('all active groups')
        ->toContain('No group key is hardcoded')
        ->toContain('category selection is optional');
});
