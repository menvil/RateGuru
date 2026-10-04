<?php

/**
 * The Admin v2 design documents and the two reference files they are built on.
 */
const ADMIN_DESIGN_DOCS = 'docs/design/admin';

it('files the Admin v2 contract, checklist, migration plan and both references', function (string $path) {
    expect(base_path(ADMIN_DESIGN_DOCS.'/'.$path))->toBeFile();
})->with([
    'design-contract.md',
    'ui-review-checklist.md',
    'migration-plan.md',
    'reference/original/RateGuru-Admin.html',
    'reference/original/RateGuru-Dev-UI-Kit.html',
]);

it('keeps the reference files byte for byte as they were delivered', function (string $path, string $sha256) {
    // The references are evidence, not code: never formatted, minified or
    // edited. A new delivery from design replaces a file and this checksum
    // together, in a change of its own.
    expect(hash_file('sha256', base_path(ADMIN_DESIGN_DOCS.'/'.$path)))->toBe($sha256);
})->with([
    ['reference/original/RateGuru-Admin.html', 'c0f8c040a75d5068b92a398e472b21e722b895cae4c77e8f288f9349ee05860c'],
    ['reference/original/RateGuru-Dev-UI-Kit.html', '22abe18c699b13e98cd97e7db2aaa6f64b4552476f276cc5c050e04d76e19d00'],
]);

it('names both reference files as the source of truth', function () {
    $contract = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md'));

    expect($contract)
        ->toContain('reference/original/RateGuru-Dev-UI-Kit.html')
        ->toContain('reference/original/RateGuru-Admin.html')
        ->toContain('--rg-admin-*')
        ->toContain('x-admin.ui.*')
        ->toContain('/admin/dev/ui-kit')
        ->toContain('Translation Center becomes the single admin editor for DB-owned translated content');
});

it('plans the migration one vertical at a time, starting with localization', function () {
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    expect($plan)->toContain('Admin v1 → Admin v2')
        ->and($plan)->toContain('Languages v2')
        ->and($plan)->toContain('Translation Center')
        ->and($plan)->toContain('freeze Admin UI Kit v1');
});

it('describes the admin as its own design system in the public contract', function () {
    $public = (string) file_get_contents(base_path('docs/design/design-contract.md'));

    expect($public)
        ->toContain('admin/design-contract.md')
        ->toContain('--rg-admin-*')
        ->toContain("->viteTheme('resources/css/filament/admin/theme.css')")
        ->not->toContain('does not register `->viteTheme()`');
});
