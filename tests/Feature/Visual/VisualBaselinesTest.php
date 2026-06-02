<?php

it('has approved feed desktop visual baseline', function () {
    $path = base_path('tests/Visual/baselines/feed-desktop.png');

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(0);
});

it('has approved feed mobile visual baseline', function () {
    $path = base_path('tests/Visual/baselines/feed-mobile.png');

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(0);
});

it('has approved upload modal visual baseline', function () {
    $path = base_path('tests/Visual/baselines/upload-modal.png');

    expect(file_exists($path))->toBeTrue()
        ->and(filesize($path))->toBeGreaterThan(0);
});
