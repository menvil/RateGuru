<?php

use Illuminate\Support\Facades\File;

it('updates stable workflow summary comments and removes only duplicates', function (string $workflow, string $marker) {
    $source = File::get(base_path($workflow));

    expect($source)
        ->toContain($marker)
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
})->with([
    'CI summary' => ['.github/workflows/ci.yml', '<!-- rateguru-ci-summary -->'],
    'coverage summary' => ['.github/workflows/coverage.yml', '<!-- rateguru-coverage-summary -->'],
]);
