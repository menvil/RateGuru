<?php

it('has food domain hardcode audit document', function () {
    $path = base_path('docs/domain/food-domain-hardcode-audit.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Cuisine');
    expect($content)->toContain('Origin');
    expect($content)->toContain('Dish');
    expect($content)->toContain('Action');
});
