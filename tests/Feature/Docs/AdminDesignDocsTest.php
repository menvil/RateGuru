<?php

/**
 * The Admin v2 design documents and the two reference files they are built on,
 * with the earlier delivery of the admin prototype kept beside them.
 */
const ADMIN_DESIGN_DOCS = 'docs/design/admin';

it('files the Admin v2 contract, checklist, migration plan and both references', function (string $path) {
    expect(base_path(ADMIN_DESIGN_DOCS.'/'.$path))->toBeFile();
})->with([
    'design-contract.md',
    'ui-review-checklist.md',
    'migration-plan.md',
    'reference/original/RateGuru-Admin.html',
    'reference/original/RateGuru-Admin-v1.html',
    'reference/original/RateGuru-Dev-UI-Kit.html',
]);

it('keeps the reference files byte for byte as they were delivered', function (string $path, string $sha256) {
    // The references are evidence, not code: never formatted, minified or
    // edited. A new delivery from design replaces a file and this checksum
    // together, in a change of its own.
    expect(hash_file('sha256', base_path(ADMIN_DESIGN_DOCS.'/'.$path)))->toBe($sha256);
})->with([
    ['reference/original/RateGuru-Admin.html', '904bd741c3bfb0d9a8116c3f21719a4ad3a5d90b4676e2c0207b4b4a7a36dd4c'],
    ['reference/original/RateGuru-Admin-v1.html', 'c0f8c040a75d5068b92a398e472b21e722b895cae4c77e8f288f9349ee05860c'],
    ['reference/original/RateGuru-Dev-UI-Kit.html', '22abe18c699b13e98cd97e7db2aaa6f64b4552476f276cc5c050e04d76e19d00'],
]);

it('names both reference files as the source of truth', function () {
    $contract = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md'));

    expect($contract)
        ->toContain('reference/original/RateGuru-Dev-UI-Kit.html')
        ->toContain('reference/original/RateGuru-Admin.html')
        ->toContain('reference/original/RateGuru-Admin-v1.html')
        ->toContain('--rg-admin-*')
        ->toContain('x-admin.ui.*')
        ->toContain('/admin/dev/ui-kit')
        ->toContain('Translation Center is the single admin editor for DB-owned translated content');
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

it('records Translation Center as done, on the one catalog Languages counts, and AI suggestions in progress', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));

    $phase4 = substr($plan, (int) strpos($plan, '### Phase 4 — Translation Center'), 200);
    $phase5 = substr($plan, (int) strpos($plan, '### Phase 5+'), 500);

    expect($phase4)->toContain('**Status: done.**')
        ->and($phase5)->toContain('**Status: in progress** — AI suggestions are done: 5A, the translation engine, 5B, interactive AI suggestions, 5C, background and bulk generation, and of 5D, 5D.1, Translation AI telemetry, and 5D.2, the Translation AI page. Budgets, limits and provider policies, and the workflow of step 6, are deferred. The translation cutover, step 7, is done.');

    expect($contract)
        ->toContain('**Translation Center** (production): `/admin/translation-center`')
        ->toContain('ProjectTranslationCatalog')
        ->toContain('ProjectTranslationUnit')
        ->toContain('UpdateProjectTranslationAction')
        ->toContain('**Storage stays where it is.**')
        ->toContain('**One target language.**')
        ->toContain('**Drafts live in the browser.**')
        ->toContain('**From Languages.**')
        ->toContain('**AI suggestions.**')
        ->toContain('**Generate missing.**')
        ->toContain('**Persisted drafts.**')
        ->toContain('**Not in this step.** No review states and no translation history')
        ->toContain('| Translation Center AI |')
        ->toContain('`x-admin.ui.segmented` (FRM-08)')
        ->toContain('`x-admin.ui.filter-dropdown` (FRM-10)')
        ->toContain('`x-admin.ui.combobox` (FRM-11)')
        ->toContain('FRM-09 Radio cards · FRM-10 Filter dropdown and chips · FRM-11 Searchable combobox | all |')
        ->toContain('| DOM-01 placement |')
        ->toContain('| Languages table on narrow widths |')
        ->toContain('On a phone the stats stay on one line')
        ->toContain("A list's search is clearable");
});

it('splits AI suggestions into four steps, done but for 5D\'s budgets, limits and policies, and records the cutover as done', function () {
    $plan = (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md'));
    $start = strpos($plan, '### Phase 5+');
    $end = strpos($plan, '### Then — freeze Admin UI Kit v1');

    // Both markers must be found, in order: a missing one would otherwise
    // become offset 0 and the assertions below would read the wrong text.
    expect($start)->toBeInt()
        ->and($end)->toBeInt()
        ->and($end)->toBeGreaterThan($start);

    $phase5 = substr($plan, (int) $start, (int) $end - (int) $start);

    $steps = [
        '**5A — Translation engine. Status: done.**',
        '**5B — Interactive AI suggestions. Status: done.**',
        '**5C — Background and bulk generation. Status: done.**',
        '**5D — Observability, limits and provider policies.**',
        '**5D.1 — Translation AI telemetry. Status: done.**',
        '**5D.2 — Translation AI page. Status: done.**',
        '**Deferred: budgets, limits and provider policies**',
        '6. **Workflow** — review states, if the product needs them. **Status: deferred.**',
        '7. **Translation cutover. Status: done.**',
    ];
    $positions = array_map(fn (string $step): int|false => strpos($phase5, $step), $steps);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and(substr_count($phase5, 'Status: done.'))->toBe(6)
        ->and(substr_count($phase5, 'Status: in progress.'))->toBe(0)
        ->and(substr_count($phase5, 'Status: deferred.'))->toBe(1)
        ->and(substr_count($phase5, 'Status: next.'))->toBe(0)
        ->and($phase5)->not->toContain('**Status: done.** — Phase 5')
        ->and($phase5)->toContain('docs/architecture/translation-engine.md');
});

it('records production Translation Center\'s interactive AI suggestions', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));

    expect($contract)
        ->toContain('A Missing row offers AI translate (sparkles), a Saved row Suggest alternative')
        ->toContain('the stored text is only compared, never sent for translation')
        ->toContain('Saved version is kept until you save.')
        ->toContain('GenerateProjectTranslationSuggestionAction')
        ->toContain('ProjectTranslationRequestFactory')
        ->toContain('Nothing is stored')
        ->toContain('a failed Regenerate keeps it')
        ->toContain('**What AI translate sends**')
        ->toContain('“AI context only”')
        ->toContain('| Translation Center AI | Generate missing and Save all generated in the top bar')
        ->toContain('| Translation Center AI button |')
        ->toContain('| Translation Center while generating |')
        ->not->toContain('| Translation Center other languages |')
        ->not->toContain('production stays manual-only');
});

it('records Generate missing: background generation, persisted drafts and an explicit Save all generated', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('Generate missing (n) (sparkles) counts every translation the language is missing, whatever the filters show')
        ->toContain('StartProjectTranslationGenerationAction')
        ->toContain('“Generating 24 of 39 translations…”')
        ->toContain('“You can leave this page. Generation will continue in the background.”')
        ->toContain('never from a hidden tab')
        ->toContain('“English changed after this suggestion was generated. Generate a new translation.”')
        ->toContain('“36 AI suggestions ready · 2 failed · 1 skipped”')
        ->toContain('“Generated drafts are temporary.”')
        ->toContain('kept on the server for 48 hours from when generation started')
        ->toContain('Try again when that generation finishes.')
        ->toContain('SaveProjectTranslationGenerationAction')
        ->toContain('“Save n generated translations?”')
        ->toContain('“This publishes these AI suggestions to visitors. Anything whose English source or stored translation changed will be skipped.”')
        ->toContain('| Translation Center Generate missing |')
        ->toContain('starting under the badge — never under the counter —')
        ->toContain('with Generate missing and Save all generated in the header band after the figures')
        ->not->toContain('Not yet: Generate missing');

    expect($plan)
        ->toContain('GenerateProjectTranslationChunkJob')
        ->toContain('48 hours from creation — never the database, so no migration')
        ->toContain('is never retried');
});

it('records Translation AI telemetry and the Translation AI page as done, and budgets, limits and policies as deferred', function () {
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($plan)
        ->toContain('of 5D, 5D.1, Translation AI telemetry, and 5D.2, the Translation AI page')
        ->toContain('**5D.1 — Translation AI telemetry. Status: done.**')
        ->toContain('`TranslationAiCallRecorder`')
        ->toContain('Provider success and item success are counted apart.')
        ->toContain('`null` — never $0 —')
        ->toContain('`translations:purge-ai-calls` deletes rows older than 90 days')
        ->toContain('**5D.2 — Translation AI page. Status: done.** `/admin/translation-ai`')
        ->toContain('never from the model filter')
        ->toContain('one rejected API key with no success since is Degraded, any other failure only in a series')
        ->toContain('never a scan')
        ->toContain('Not configured for limits and budgets')
        ->toContain('**Deferred: budgets, limits and provider policies**')
        ->toContain('a short-lived, opt-in journal of what each call sent and what came back')
        ->not->toContain('**Next: budgets, limits and provider policies**')
        ->not->toContain('No page yet');
});

it('records the translation cutover as done, in one change, and the content screens first after it', function () {
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));
    $order = ['| 1 | Categories and Tags — **done** |', '| 2 | Rating groups and the group editor — **done** |', '| 3 | Project settings and Static pages — **done** |', '| 4 | Posts — **done** |', '| 5 | Comments — **done** |', '| 6 | Reports — **done** |', '| 7 | Users and user edit — **done** |', '| 8 | Media diagnostics |'];
    $positions = array_map(fn (string $row): int|false => strpos($plan, $row), $order);

    expect($plan)
        ->toContain('7. **Translation cutover. Status: done.** In one change, in the editors as they are')
        ->toContain('so one saved in Translation Center while the page was open is kept')
        ->toContain('No data moves: the stored translations stay where they are')
        ->toContain('with no note in their place')
        ->toContain('Translation Center keeps listing what visitors can see, as it does now')
        ->toContain('**Status: in effect.** Localization runs on v2, so the kit is frozen as Admin UI Kit v1')
        ->toContain('are reviewed against the current one, which then becomes their reference')
        ->toContain('Planned on their own, once the screens above are on v2: the Dashboard, which is work of its own')
        ->not->toContain('| 9 | Dashboard |')
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('describes Rating groups on v2: the range kept by every action, saved from the top bar, a group never deleted', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('### Rating groups')
        ->toContain('Rating groups are drawn entirely in Admin v2 too: the list (`ManageRatingGroups`), a new group (`CreateRatingGroup`) and one group with its options (`EditRatingGroup`)')
        ->toContain('Delete, unavailable (“Groups are kept · deactivate instead”): a group is never deleted')
        ->toContain('**Save from the top bar (NAV-02):**')
        ->toContain('the option actions keep every group\'s active options inside its range, active or not')
        ->toContain('“Raise maximum to n and activate”')
        ->toContain('In production it is `x-admin.ui.range-slots`')
        ->toContain('| STS-01 Status badge · STS-02 Counters · STS-03 Progress bar · STS-04 Active range slots · STS-05 Locale chips | all |')
        ->toContain('| Rating group Delete |')
        ->toContain('| Option range for inactive groups |');

    expect($plan)
        ->toContain('**Rating groups. Status: done.**')
        ->toContain('a group\'s and an option\'s key are fixed once created, and a group starts inactive');
});

it('describes Posts on v2: moderated a tab at a time through the moderation actions, approving without Undo', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('### Posts')
        ->toContain('Posts (`ListPosts`) is drawn entirely in Admin v2')
        ->toContain('**Approve** publishes at once, with no confirmation and no Undo')
        ->toContain('`x-admin.ui.bulk-bar`')
        ->toContain('`x-admin.ui.checkbox` (FRM-07)')
        ->toContain('| Approve Undo |')
        ->toContain('| Finalize removal |')
        ->toContain('FRM-09 Radio cards · FRM-10 Filter dropdown and chips · FRM-11 Searchable combobox | all |')
        ->toContain('all; TBL-03 and TBL-04 as live, reusable components')
        ->toContain('Posts shows its pending count, Comments its reported comments still visible and Reports its open reports (warning)');

    expect($plan)
        ->toContain('**Posts. Status: done.**')
        ->toContain('a registered stable pagination boundary');
});

it('describes Users on v2: status only through the moderation actions, said as the lifecycle has it', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('### Users')
        ->toContain('Users (`ListUsers`) and a person\'s page (`EditUser`) are drawn entirely in Admin v2')
        ->toContain('never a locked account')
        ->toContain('| What Limit and Shadowban do |')
        ->toContain('`x-admin.ui.radio-cards`');

    expect($plan)
        ->toContain('**Users. Status: done.**')
        ->toContain('`UpdateUserAccountAction`');
});

it('describes Reports on v2: the chain docked or over the list, closing apart from acting on the content', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('### Reports')
        ->toContain('Reports (`ListReports`) is drawn entirely in Admin v2')
        ->toContain('**Report chain (DOM-02):**')
        ->toContain('| Admin-specific | DOM-01 Translation field states · DOM-02 Report chain | all; DOM-02 as a live, reusable component (`x-admin.ui.timeline-item`) |')
        ->toContain('| Report order |');

    expect($plan)
        ->toContain('**Reports. Status: done.**')
        ->toContain('`ReopenReportAction`');
});

it('describes Comments on v2: moderated after the fact, a comment its author deleted kept read-only', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('### Comments')
        ->toContain('Comments (`ListComments`) is drawn entirely in Admin v2')
        ->toContain('Nothing here deletes a comment: that is its author\'s alone.')
        ->toContain('| Finalize a comment |');

    expect($plan)
        ->toContain('**Comments. Status: done.**')
        ->toContain('`filament.pages.moderation.reason`');
});

it('describes Project settings on v2: one draft saved from the top bar, the preset compared and never applied', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('### Project settings')
        ->toContain('Project settings (`ProjectSettingsPage`) is drawn entirely in Admin v2: one draft over six tabs')
        ->toContain('Nothing here applies a preset: that is `php artisan rateguru:setup`.')
        ->toContain('The editors share one top bar (`filament.pages.editor.topbar`)')
        ->toContain('| Static page content |')
        ->toContain('| Unconfigured sign-in provider |')
        ->not->toContain('**Translation status on the content editors.**');

    expect($plan)
        ->toContain('**Project settings. Status: done.**')
        ->toContain('never applied from the page');
});

it('describes Categories and Tags on v2, and leaves merging tags to be discussed once every section is', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));
    $plan = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/migration-plan.md')));

    expect($contract)
        ->toContain('Content ├── Categories ├── Tags └── Rating groups')
        ->toContain('Categories and Tags are drawn entirely in Admin v2 (`ManageCategories`, `ManageTags`, one screen each)')
        ->toContain('The server keeps a locked slug as stored whatever the browser sends.')
        ->toContain('Missing translations, in the chips\' count and the stat, are counted over offered languages only, and for categories over active ones only')
        ->toContain('Merge into… unavailable (“Not available yet”)')
        ->toContain('`x-admin.ui.row-menu` with `x-admin.ui.menu-item`')
        ->toContain('`x-admin.ui.locked-identifier` (FRM-04)')
        ->toContain('`x-admin.ui.number-stepper` (FRM-05)')
        ->toContain('`x-admin.ui.switch` (FRM-06)')
        ->toContain('A reversible action adds `action` — Undo')
        ->toContain('| Merge into… |')
        ->toContain('| Category and tag slugs |')
        ->toContain('| Categories and Tags translations note |')
        ->toContain('TBL-03 and TBL-04 as live, reusable components')
        ->not->toContain('no Undo in the stack yet')
        ->not->toContain('built when the first screen needs them');

    expect($plan)
        ->toContain('**Categories and Tags. Status: done.**')
        ->toContain('**Deferred, to discuss once every section is on v2: merging tags.**')
        ->toContain('the row menu shows Merge into… unavailable with “Not available yet”');
});

it('describes the Translation AI page: health of the configuration, the history filters choose, and the guardrails', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));

    expect($contract)
        ->toContain('Localization ├── Languages ├── Translation Center └── Translation AI')
        ->toContain('**Translation AI** (prototype):')
        ->toContain('**Translation AI** (production): `/admin/translation-ai` (`App\Filament\Pages\TranslationAiPage`)')
        ->toContain('`bot` Translation AI')
        ->toContain('**Provider health** is the configuration translations run on now')
        ->toContain('a key alone is never Healthy')
        ->toContain('Degraded (warning) when the last call that tested the API key — a success, or a rejection of it (`authentication_failed`) — was a rejection, however few failures that makes')
        ->toContain('never $0 for an unknown cost')
        ->toContain('never adds them up — a timeout of ten items is “1 failed call · 10 failed items”')
        ->toContain('Burst — five or more with the same code within a sliding ten minutes')
        ->toContain('a known list of keys read together, never a scan')
        ->toContain('Temporarily unavailable')
        ->toContain('queue worker timeout (Not available')
        ->toContain('hourly limit and budget (Not configured')
        ->toContain('`x-admin.ui.pagination` (NAV-04)')
        ->toContain('NAV-04 as a live, reusable component')
        ->toContain('| Translation AI period and Refresh |')
        ->toContain('| Translation AI chart |');
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

it('records Translation Center as the single editor of translations, the content editors editing English only', function () {
    $contract = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path(ADMIN_DESIGN_DOCS.'/design-contract.md')));

    expect($contract)
        ->toContain('**Invariant.** Translation Center is the single admin editor for DB-owned translated content.')
        ->toContain('edit their English reference content only — never an editor for every language, and no note where one was')
        ->toContain('`SaveProjectSettingsAction` refuses translations')
        ->toContain('the translations of inactive and archived content are kept, edited nowhere while it is off')
        ->not->toContain('**Translation editors in place.**')
        ->not->toContain('Until that cutover the existing translation forms')
        ->not->toContain('keep doing so until the translation cutover');
});
