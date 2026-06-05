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

it('provides canonical neutral category labels', function () {
    expect(CuisineType::Italian->label())->toBe('Category A')
        ->and(CuisineType::Italian->shortLabel())->toBe('A')
        ->and(CuisineType::Other->label())->toBe('Other')
        ->and(CuisineType::Other->shortLabel())->toBe('OT')
        ->and(CuisineType::Unknown->label())->toBe('Unknown')
        ->and(CuisineType::Unknown->shortLabel())->toBe('UN');
});
