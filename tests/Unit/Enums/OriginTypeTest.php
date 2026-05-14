<?php

use App\Enums\OriginType;

it('contains expected origin types', function () {
    expect(OriginType::Homemade->value)->toBe('homemade');
    expect(OriginType::Restaurant->value)->toBe('restaurant');
    expect(OriginType::Unknown->value)->toBe('unknown');
});
