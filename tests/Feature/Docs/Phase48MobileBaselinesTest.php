<?php

it('mobile visual baselines document exists', function () {
    expect(file_exists(base_path('docs/mobile/phase-48-visual-baselines.md')))->toBeTrue();
});

it('baselines document references 375px breakpoint', function () {
    $content = file_get_contents(base_path('docs/mobile/phase-48-visual-baselines.md'));

    expect($content)->toContain('375px');
});

it('baselines document lists screenshot targets', function () {
    $content = file_get_contents(base_path('docs/mobile/phase-48-visual-baselines.md'));

    expect($content)->toContain('feed-page');
    expect($content)->toContain('profile-header');
    expect($content)->toContain('auth-page');
});
