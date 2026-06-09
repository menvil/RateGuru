<?php

use Tests\Browser\Support\MobileViewports;

it('has mobile browser viewport helper', function () {
    expect(file_exists(base_path('tests/Browser/Support/MobileViewports.php')))->toBeTrue();

    expect(MobileViewports::SMALL_MOBILE[0])->toBe(375);
    expect(MobileViewports::MOBILE[0])->toBe(390);
    expect(MobileViewports::TABLET[0])->toBe(768);
});
