<?php

use Illuminate\Support\Facades\File;

it('recreates the CI summary comment so its content and timeline date stay current', function () {
    $source = File::get(base_path('.github/workflows/ci.yml'));
    $createPosition = strpos($source, 'await github.rest.issues.createComment({');
    $deletePosition = strpos($source, 'await Promise.all(existingComments.map((comment) =>');

    expect($source)
        ->toContain('<!-- rateguru-ci-summary -->')
        ->toContain('const generatedAt = new Date().toISOString();')
        ->toContain('`_Updated: ${generatedAt}_`,')
        ->toContain('github.rest.issues.createComment({')
        ->toContain('existingComments.map((comment) =>')
        ->not->toContain('github.rest.issues.updateComment({')
        ->and($createPosition)->not->toBeFalse()
        ->and($deletePosition)->not->toBeFalse()
        ->and($createPosition)->toBeLessThan($deletePosition)
        ->and(substr_count($source, 'github.rest.issues.createComment({'))->toBe(1)
        ->and(substr_count($source, 'github.rest.issues.deleteComment({'))->toBe(1);
});

it('updates the stable coverage summary comment and removes only duplicates', function () {
    $source = File::get(base_path('.github/workflows/coverage.yml'));

    expect($source)
        ->toContain('<!-- rateguru-coverage-summary -->')
        ->toContain('const [existing, ...duplicateComments] = existingComments;')
        ->toContain('if (existing) {')
        ->toContain('github.rest.issues.updateComment({')
        ->toContain('comment_id: existing.id,')
        ->toContain('} else {')
        ->toContain('github.rest.issues.createComment({')
        ->toContain('duplicateComments.map((comment) =>')
        ->not->toContain('existingComments.map((comment) =>')
        ->and(substr_count($source, 'github.rest.issues.updateComment({'))->toBe(1)
        ->and(substr_count($source, 'github.rest.issues.createComment({'))->toBe(1)
        ->and(substr_count($source, 'github.rest.issues.deleteComment({'))->toBe(1);
});
