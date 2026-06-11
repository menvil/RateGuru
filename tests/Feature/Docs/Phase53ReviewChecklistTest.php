<?php

it('has phase 53 profile review checklist', function () {
    $path = base_path('docs/profile/phase-53-profile-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Profile 2.0');
    expect($content)->toContain('saved posts');
    expect($content)->toContain('rating activity');
    expect($content)->toContain('private by default');
});

it('has profile 2 documentation', function () {
    $path = base_path('docs/profile/profile-2.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('profile');
    expect($content)->toContain('avatar');
    expect($content)->toContain('tabs');
});
