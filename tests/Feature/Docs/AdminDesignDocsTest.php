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

    $steps = [
        '### Phase 0/1 — design contract, theme and UI kit',
        '### Phase 2 — Admin v2 shell and navigation',
        '### Phase 3 — Languages v2',
        '### Phase 4+ — Translation Center',
        '### Then — freeze Admin UI Kit v1',
        '### Then — the remaining sections',
    ];
    $positions = array_map(fn (string $step): int|false => strpos($plan, $step), $steps);

    expect($plan)->toContain('Admin v1 → Admin v2')
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('describes the admin as its own design system in the public contract', function () {
    $public = (string) file_get_contents(base_path('docs/design/design-contract.md'));

    expect($public)
        ->toContain('admin/design-contract.md')
        ->toContain('--rg-admin-*')
        ->toContain("->viteTheme('resources/css/filament/admin/theme.css')")
        ->not->toContain('does not register `->viteTheme()`');
});

it('records the shell as production and what it deliberately leaves out for now', function () {
    $contract = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md'));
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    expect($contract)
        ->toContain('### Production shell')
        ->toContain('### Transitional omissions')
        ->toContain('**Global search.**')
        ->toContain('**Translation Center** is not in the navigation')
        ->toContain('**Operational counts**')
        ->toContain('**Legacy page headers, breadcrumbs and actions**');

    $phase2 = substr($plan, (int) strpos($plan, '### Phase 2'), 200);

    expect($phase2)->toContain('**Status: done.**');
});

it('records Languages as migrated, with its overlays built, and Translation Center next', function () {
    $contract = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md'));
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    $phase3 = substr($plan, (int) strpos($plan, '### Phase 3'), 200);
    $phase4 = substr($plan, (int) strpos($plan, '### Phase 4+'), 200);

    expect($phase3)->toContain('**Status: done.**')
        ->and($phase4)->toContain('**Status: next**')
        ->and($phase4)->toContain('Translation Center foundation');

    expect($contract)
        ->toContain('**Languages** (production, migrated)')
        ->toContain('| Overlays & feedback | OVL-01 Confirmation dialog · OVL-02 Drawer · FBK-01 Toast · FBK-02 Inline notice | all; OVL-01, OVL-02 and FBK-01 as live, reusable components |')
        ->toContain('`x-admin.ui.confirm-dialog` (OVL-01)')
        ->toContain('`x-admin.ui.drawer` (OVL-02)')
        ->toContain('`x-admin.ui.toast-stack` (FBK-01)');
});

it('records the Languages drawer\'s editor links as a bridge until Translation Center, not as the target', function () {
    $contract = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md'));
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    // Prose wraps at any word, so the phrases are matched across line breaks.
    foreach ([$contract, $plan] as $document) {
        expect((string) preg_replace('/\s+/', ' ', $document))
            ->toContain('Target design: a missing item')
            ->toContain('the Languages drawer keeps links to the existing editors')
            ->toContain('replaces these links with Translation Center filters');
    }
});
