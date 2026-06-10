<?php

it('has phase 49 sharing review checklist', function () {
    $path = base_path('docs/sharing/phase-49-extended-sharing-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('ShareUrlBuilder');
    expect($content)->toContain('OpenGraph');
    expect($content)->toContain('Web Share API');
    expect($content)->toContain('external import is not part of Phase 49');
});

it('has extended sharing documentation', function () {
    $path = base_path('docs/sharing/extended-sharing.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('ShareUrlBuilder');
    expect($content)->toContain('ShareProvider');
    expect($content)->toContain('Web Share API');
    expect($content)->toContain('External Import Is Not Part of Phase 49');
});
