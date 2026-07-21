<?php

it('has generic rating vocabulary documentation', function () {
    $path = base_path('docs/domain/generic-rating-vocabulary.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Post');
    expect($content)->toContain('Rating Group');
    expect($content)->toContain('Rating Option');
    expect($content)->toContain('Rating Vote')
        ->toContain('Category')
        ->toContain('Tag');
});

it('requires generic public copy in the ui review checklist', function () {
    $path = base_path('docs/design/ui-review-checklist.md');

    expect($path)->toBeFile();

    $content = file_get_contents($path);

    expect($content)->toContain('Mandatory: public-facing copy uses generic post, rating group, rating option, category, and tag wording');
});
