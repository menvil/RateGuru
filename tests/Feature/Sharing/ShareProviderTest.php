<?php

use App\Enums\ShareProvider;

it('lists url providers not including copy_link and native', function () {
    $urlProviders = array_map(fn ($p) => $p->value, ShareProvider::urlProviders());

    foreach (['facebook', 'x', 'telegram', 'whatsapp', 'reddit', 'pinterest', 'email'] as $expected) {
        expect($urlProviders)->toContain($expected);
    }

    expect($urlProviders)->not->toContain('copy_link');
    expect($urlProviders)->not->toContain('native');
});
