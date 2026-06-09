<?php

it('has share providers config', function () {
    expect(config('share.providers'))->toHaveKeys([
        'copy_link',
        'native',
        'facebook',
        'x',
        'telegram',
        'whatsapp',
        'reddit',
        'pinterest',
        'email',
    ]);
});

it('has share config enabled by default', function () {
    expect(config('share.enabled'))->toBeTrue();
});

it('can disable individual providers', function () {
    config(['share.providers.facebook.enabled' => false]);

    expect(config('share.providers.facebook.enabled'))->toBeFalse();
    expect(config('share.providers.x.enabled'))->toBeTrue();
});
