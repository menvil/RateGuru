<?php

it('has phase 52 follow authors review checklist', function () {
    $path = base_path('docs/follows/phase-52-follow-authors-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('follows');
    expect($content)->toContain('FollowAuthorAction');
    expect($content)->toContain('FollowedAuthorPostedNotification');
    expect($content)->toContain('no recommendations');
});

it('has phase 52 follow authors documentation', function () {
    $path = base_path('docs/follows/follow-authors.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('follows');
    expect($content)->toContain('FollowAuthorAction');
    expect($content)->toContain('in-app');
    expect($content)->toContain('Phase 52');
});
