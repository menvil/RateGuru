<?php

it('has the gd extension loaded', function () {
    expect(extension_loaded('gd'))->toBeTrue();
});

it('has the exif extension loaded', function () {
    expect(extension_loaded('exif'))->toBeTrue();
});

it('has gd built with JPEG, PNG, and WebP support', function () {
    $info = gd_info();

    expect($info['JPEG Support'] ?? false)->toBeTrue()
        ->and($info['PNG Support'] ?? false)->toBeTrue()
        ->and($info['WebP Support'] ?? false)->toBeTrue();
});
