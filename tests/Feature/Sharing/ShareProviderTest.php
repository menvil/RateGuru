<?php

use App\Enums\ShareProvider;

it('validates supported share providers', function () {
    expect(ShareProvider::isValid('facebook'))->toBeTrue();
    expect(ShareProvider::isValid('x'))->toBeTrue();
    expect(ShareProvider::isValid('telegram'))->toBeTrue();
    expect(ShareProvider::isValid('unknown'))->toBeFalse();
});

it('validates all nine providers', function () {
    foreach (['copy_link', 'native', 'facebook', 'x', 'telegram', 'whatsapp', 'reddit', 'pinterest', 'email'] as $value) {
        expect(ShareProvider::isValid($value))->toBeTrue("Provider '$value' should be valid");
    }
});

it('rejects invalid provider values', function () {
    expect(ShareProvider::isValid(''))->toBeFalse();
    expect(ShareProvider::isValid('instagram'))->toBeFalse();
    expect(ShareProvider::isValid('twitter'))->toBeFalse();
});

it('lists url providers not including copy_link and native', function () {
    $urlProviders = array_map(fn ($p) => $p->value, ShareProvider::urlProviders());

    expect($urlProviders)->toContain('facebook');
    expect($urlProviders)->toContain('x');
    expect($urlProviders)->toContain('telegram');
    expect($urlProviders)->not->toContain('copy_link');
    expect($urlProviders)->not->toContain('native');
});
