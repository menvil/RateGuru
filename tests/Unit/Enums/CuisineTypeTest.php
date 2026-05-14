<?php

use App\Enums\CuisineType;

it('contains expected cuisine types', function () {
    expect(CuisineType::Italian->value)->toBe('italian');
    expect(CuisineType::Asian->value)->toBe('asian');
    expect(CuisineType::American->value)->toBe('american');
    expect(CuisineType::Mexican->value)->toBe('mexican');
    expect(CuisineType::Other->value)->toBe('other');
    expect(CuisineType::Unknown->value)->toBe('unknown');
});
