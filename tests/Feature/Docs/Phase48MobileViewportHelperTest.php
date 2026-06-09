<?php

it('has mobile browser viewport helper', function () {
    $path = base_path('tests/Browser/Support/MobileViewports.php');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('375');
    expect($content)->toContain('390');
    expect($content)->toContain('768');
});
