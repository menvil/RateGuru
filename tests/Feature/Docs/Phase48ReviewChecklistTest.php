<?php

it('phase 48 review checklist document exists', function () {
    expect(file_exists(base_path('docs/mobile/phase-48-review-checklist.md')))->toBeTrue();
});

it('review checklist references first and last task IDs', function () {
    $content = file_get_contents(base_path('docs/mobile/phase-48-review-checklist.md'));

    expect($content)->toContain('RG-755');
    expect($content)->toContain('RG-774');
});

it('review checklist includes completion status section', function () {
    $content = file_get_contents(base_path('docs/mobile/phase-48-review-checklist.md'));

    expect($content)->toContain('Phase 48');
    expect($content)->toContain('v0.3.5');
});
