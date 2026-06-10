<?php

use App\Enums\ImportProvider;

it('validates supported import providers', function () {
    expect(ImportProvider::isValid('direct_image'))->toBeTrue();
    expect(ImportProvider::isValid('open_graph'))->toBeTrue();
    expect(ImportProvider::isValid('instagram'))->toBeTrue();
    expect(ImportProvider::isValid('unknown'))->toBeFalse();
});

it('has unsupported provider case', function () {
    expect(ImportProvider::Unsupported->value)->toBe('unsupported');
});
