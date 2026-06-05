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

it('documents legacy rating vote migration', function () {
    $path = base_path('docs/rating/legacy-vote-migration.md');

    expect($path)->toBeFile();

    $content = file_get_contents($path);

    expect($content)
        ->toContain('rateguru:rating:migrate-legacy-votes')
        ->toContain('--dry-run')
        ->toContain('idempotent')
        ->toContain('origin_votes')
        ->toContain('cuisine_votes');
});

it('has phase 44 configurable voting review checklist', function () {
    $path = base_path('docs/rating/phase-44-configurable-voting-review.md');

    expect($path)->toBeFile();

    $content = file_get_contents($path);

    expect($content)
        ->toContain('rating_groups')
        ->toContain('rating_options')
        ->toContain('rating_votes')
        ->toContain('legacy')
        ->toContain('SourceVoting')
        ->toContain('CategoryVoting');
});
