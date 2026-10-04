<?php

it('ships a social crawler compatible fallback image', function () {
    $path = public_path('images/og/rateguru-post-placeholder.png');

    expect(is_file($path))->toBeTrue();

    $metadata = getimagesize($path);

    expect($metadata)->not->toBeFalse()
        ->and($metadata[0])->toBe(1200)
        ->and($metadata[1])->toBe(630)
        ->and($metadata['mime'])->toBe('image/png')
        ->and(filesize($path))->toBeLessThanOrEqual(700 * 1024);
});
