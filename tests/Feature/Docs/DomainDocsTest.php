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

it('has generic rating vocabulary documentation', function () {
    $path = base_path('docs/domain/generic-rating-vocabulary.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Post');
    expect($content)->toContain('Rating Group');
    expect($content)->toContain('Rating Option');
    expect($content)->toContain('Rating Vote');
});

it('has legacy domain compatibility note', function () {
    $path = base_path('docs/domain/legacy-domain-compatibility.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('legacy');
    expect($content)->toContain('Phase 44');
    expect($content)->toContain('origin_votes');
    expect($content)->toContain('cuisine_votes');
});

it('has phase 43 domain refactor review checklist', function () {
    $path = base_path('docs/domain/phase-43-domain-refactor-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('generic rating platform');
    expect($content)->toContain('forbidden words');
    expect($content)->toContain('docs/design/ui-review-checklist.md');
    expect($content)->toContain('phase-43-domain-refactor-review.md');
    expect($content)->toContain('Phase 44');
});

it('requires generic public copy in the ui review checklist', function () {
    $content = file_get_contents(base_path('docs/design/ui-review-checklist.md'));

    expect($content)->toContain('Mandatory: public-facing copy uses generic post, Source, and Category wording');
    expect($content)->not->toContain('Dish placeholder');
    expect($content)->not->toContain('Cuisine chips');
    expect($content)->not->toContain('Cuisine distribution');
});
