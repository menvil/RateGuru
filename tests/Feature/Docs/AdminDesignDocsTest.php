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
        '### Phase 4 — Translation Center',
        '### Phase 5+ — AI suggestions, workflow and the translation cutover',
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
        ->toContain('**Operational counts**')
        ->toContain('**Translation Center\'s missing count.**')
        ->toContain('**Legacy page headers, breadcrumbs and actions**');

    $phase2 = substr($plan, (int) strpos($plan, '### Phase 2'), 200);

    expect($phase2)->toContain('**Status: done.**');
});

it('records Languages as migrated, with its overlays built', function () {
    $contract = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md'));
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    $phase3 = substr($plan, (int) strpos($plan, '### Phase 3'), 200);

    expect($phase3)->toContain('**Status: done.**');

    expect($contract)
        ->toContain('**Languages** (production, migrated)')
        ->toContain('| Overlays & feedback | OVL-01 Confirmation dialog · OVL-02 Drawer · FBK-01 Toast · FBK-02 Inline notice | all; OVL-01, OVL-02 and FBK-01 as live, reusable components |')
        ->toContain('`x-admin.ui.confirm-dialog` (OVL-01)')
        ->toContain('`x-admin.ui.drawer` (OVL-02)')
        ->toContain('`x-admin.ui.toast-stack` (FBK-01)');
});

it('records Translation Center as done, on the one catalog Languages counts, and AI suggestions next', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    $phase4 = substr($plan, (int) strpos($plan, '### Phase 4 — Translation Center'), 200);
    $phase5 = substr($plan, (int) strpos($plan, '### Phase 5+'), 200);

    expect($phase4)->toContain('**Status: done.**')
        ->and($phase5)->toContain('**Status: next** — Phase 5, AI suggestions.');

    expect($contract)
        ->toContain('**Translation Center** (production): `/admin/translation-center`')
        ->toContain('ProjectTranslationCatalog')
        ->toContain('ProjectTranslationUnit')
        ->toContain('UpdateProjectTranslationAction')
        ->toContain('**Storage stays where it is.**')
        ->toContain('**One target language.**')
        ->toContain('**Drafts live in the browser.**')
        ->toContain('**From Languages.**')
        ->toContain('**Not in this step.** No AI')
        ->toContain('| Translation Center AI |')
        ->toContain('`x-admin.ui.segmented` (FRM-08)')
        ->toContain('`x-admin.ui.filter-dropdown` (FRM-10)')
        ->toContain('`x-admin.ui.combobox` (FRM-11)')
        ->toContain('| FRM-01–03, FRM-08, FRM-10, FRM-11 |');
});

it('records the Languages drawer\'s bridge to the editors as closed, with Translate leading to Translation Center', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($plan)->toContain('**Transitional bridge**, closed in Phase 4.')
        ->and($contract)
        ->toContain('Translate, which opens Translation Center on that item')
        ->toContain('Catalog issues are the release\'s to fix: nothing sends them to Translation Center')
        ->not->toContain('which is not built yet');
});
