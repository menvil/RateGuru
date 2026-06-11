<?php

it('has profile 2 audit document', function () {
    $path = base_path('docs/profile/phase-53-profile-audit.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('profile');
    expect($content)->toContain('privacy');
    expect($content)->toContain('saved posts');
    expect($content)->toContain('rating activity');
});
