<?php

it('has mobile overflow smoke browser test file', function () {
    $path = base_path('tests/Browser/MobileOverflowSmokeTest.php');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('scrollWidth');
    expect($content)->toContain('375');
    expect($content)->toContain('innerWidth');
});
