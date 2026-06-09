<?php

it('has mobile ux audit document', function () {
    $path = base_path('docs/mobile/phase-48-mobile-ux-audit.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('375px');
    expect($content)->toContain('RatingVoting');
    expect($content)->toContain('horizontal overflow');
});
