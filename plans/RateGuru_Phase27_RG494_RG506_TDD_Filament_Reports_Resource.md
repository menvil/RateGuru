# RateGuru — Phase 27 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 27 — Filament Reports Resource**  
Диапазон задач: **RG-494 → RG-506**  
Основа нумерации: исходный atomic backlog, где Phase 27 начинается с задачи 494 и заканчивается задачей 506.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 27 соответствует исходному блоку:

```txt
Phase 27 — Filament Reports Resource
```

Правильный диапазон Phase 27:

```txt
RG-494 — Create ReportResource
RG-495 — Add target type column
RG-496 — Add reason column
RG-497 — Add reporter column
RG-498 — Add status column
RG-499 — Add created_at column
RG-500 — Add open filter
RG-501 — Add resolved filter
RG-502 — Add ignored filter
RG-503 — Add resolve action
RG-504 — Add ignore action
RG-505 — Add hide target action
RG-506 — Add ban target author action
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 28 начинается с `RG-507` и делает **Filament Tags Resource**. Поэтому Phase 27 не должна создавать TagResource, ModerationDashboard или новые публичные UI-экраны.
---

# 2. Цель Phase 27

Phase 27 добавляет Filament-ресурс для обработки жалоб в `/admin`.

После Phase 27 moderator/admin должен уметь в Filament:

```txt
- открыть список reports;
- видеть target type;
- видеть reason;
- видеть reporter;
- видеть report status;
- видеть created_at;
- фильтровать open reports;
- фильтровать resolved reports;
- фильтровать ignored reports;
- resolve report;
- ignore report;
- hide reported target;
- ban target author.
```

Это админский moderation workflow поверх backend-а из Phase 19, Phase 21, Phase 24–26.
---

# 3. Scope Phase 27

## Входит

```txt
- ReportResource;
- ListReports page;
- target type column;
- reason column;
- reporter column;
- status column;
- created_at column;
- open/resolved/ignored filters;
- resolve report table action;
- ignore report table action;
- hide target action;
- ban target author action;
- minimal IgnoreReportAction backend, если его ещё нет;
- safe target resolution for Post/Comment;
- tests/smoke tests for resource access, filters and critical actions.
```

## Не входит

```txt
- public reports UI;
- report modal changes;
- bulk resolve/ignore/hide/ban actions;
- moderation dashboard widgets;
- report analytics;
- report notifications;
- user report history page;
- editing report payload;
- deleting reports;
- changing report reason;
- resolving reports automatically after hiding target.
```

Phase 29 будет делать dashboard.  
Phase 31 будет делать notifications foundation.
---

# 4. Critical Decisions

## 4.1. ReportResource is moderation queue, not CRUD editor

В Phase 27 нельзя делать полноценное редактирование жалоб.

Правильный MVP:

```txt
- ListReports page;
- table columns;
- filters;
- moderation actions.
```

Неправильно:

```txt
- edit report form;
- create report form;
- delete report action;
- arbitrary status editor;
- arbitrary reason editor.
```

Reports создаются пользователями через public UI. Admin только обрабатывает их.

## 4.2. Resolve action must use ResolveReportAction

Phase 19 создала:

```txt
ResolveReportAction
```

Поэтому `RG-503` обязан вызывать его:

```php
app(ResolveReportAction::class)->handle(auth()->user(), $record, $note);
```

Нельзя делать:

```php
$record->update(['status' => ReportStatus::Resolved]);
```

## 4.3. Ignore action needs backend action

Backlog требует:

```txt
RG-504 — Add ignore action
```

Но Phase 19 создавала только `ResolveReportAction`. Значит RG-504 должен создать minimal backend action:

```txt
IgnoreReportAction
```

или расширить существующую модель статусов так, чтобы `ignored` был first-class status.

Рекомендация:

```txt
- добавить ReportStatus::Ignored;
- создать IgnoreReportAction;
- action пишет ignored_by_user_id / ignored_at / resolution_note, если поля есть;
- если поля only resolved_* существуют, использовать их как processed_by/processed_at нельзя без миграции или явного решения.
```

Лучше иметь отдельные поля:

```txt
resolved_by_user_id
resolved_at
resolution_note
ignored_by_user_id
ignored_at
ignore_note
```

Но если хочется минимально, можно использовать:

```txt
status = ignored
resolved_by_user_id = moderator id
resolved_at = now
resolution_note = note
```

Это допустимо, но надо назвать в коде как “processed metadata”. Не делать скрытую семантическую кашу.

## 4.4. Status names must be consistent

Phase 19 мог использовать:

```txt
open
resolved
dismissed
```

Phase 27 backlog говорит:

```txt
ignored
```

Нельзя оставить одновременно `dismissed` и `ignored` без маппинга.

Решение Phase 27:

```txt
- canonical UI status: ignored;
- если ReportStatus::Dismissed уже есть, заменить/alias на ReportStatus::Ignored;
- фильтр RG-502 должен искать именно ignored reports.
```

Если migration/data уже использует `dismissed`, добавить временный compatibility query:

```php
whereIn('status', [ReportStatus::Ignored, ReportStatus::Dismissed])
```

Но лучше не плодить два статуса.

## 4.5. Hide target action must call target-specific backend actions

Report target can be:

```txt
Post
Comment
```

Hide target must dispatch to correct action:

```php
Post    → HidePostAction
Comment → HideCommentAction
```

Нельзя делать:

```php
$report->reportable->update(['status' => 'hidden']);
```

После hide target report не обязан автоматически становиться resolved. Это отдельное решение. В Phase 27 recommended behavior:

```txt
- hide target only hides target;
- report remains open unless moderator explicitly clicks Resolve/Ignore;
- optional note tells moderator to resolve/ignore after action.
```

Почему: hiding content and resolving a report are different audit events.

## 4.6. Ban target author action must call BanUserAction

Target author:

```txt
Post::user
Comment::user
```

Ban action:

```php
app(BanUserAction::class)->handle(auth()->user(), $targetAuthor, $reason);
```

Нельзя делать direct update:

```php
$user->update(['status' => UserStatus::Banned]);
```

Only admin can ban. Moderator can see the report, can resolve/ignore/hide target, but cannot ban target author.

## 4.7. Target may be missing

Reported content may be deleted/soft-deleted.

ReportResource must not crash when:

```txt
reportable is null
reportable user is null
```

Columns/actions must handle missing targets gracefully.

## 4.8. No bulk report actions in Phase 27

Bulk resolve/ignore/hide/ban is tempting, but not in backlog.

Do not add:

```txt
bulk resolve
bulk ignore
bulk hide target
bulk ban users
```

Bulk moderation without careful safeguards is dangerous.
---

# 5. Architecture Rules

## 5.1. Resource location

Adapt to installed Filament version and conventions from Phase 24–26.

Likely paths:

```txt
app/Filament/Resources/ReportResource.php
app/Filament/Resources/ReportResource/Pages/ListReports.php
```

## 5.2. Query must eager-load reporter, reportable and nested authors

Avoid N+1:

```php
Report::query()
    ->with([
        'user',
        'reportable',
    ])
```

For target author, you may need conditional eager loading:

```txt
Post reportable → user
Comment reportable → user
```

If polymorphic eager loading is cumbersome in current Laravel version, keep it simple but do not write per-row query helpers that explode under load.

## 5.3. Filament actions must remain thin

Filament Resource should only:

```txt
- show fields;
- collect reason/note from modal;
- call backend action;
- show notification.
```

Business rules live in:

```txt
ResolveReportAction
IgnoreReportAction
HidePostAction
HideCommentAction
BanUserAction
```

## 5.4. Authorization

Resource visibility:

```txt
admin/moderator can view ReportResource
normal user cannot access admin panel already handled by Phase 23
```

Action permissions:

```txt
resolve report → moderator/admin
ignore report  → moderator/admin
hide target     → moderator/admin
ban target      → admin only
```

Backend actions still enforce permissions.

## 5.5. No Report delete action

Do not delete reports. Reports are audit data.
---

# 6. GitFlow для Phase 27

## Base branch

Все задачи Phase 27 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-494-create-report-resource
feature/RG-504-add-ignore-action
feature/RG-506-add-ban-target-author-action
```

## Commit format

```txt
RG-494: Create ReportResource
RG-504: Add ignore action
RG-506: Add ban target author action
```

## Release branch

После выполнения `RG-494`–`RG-506`:

```txt
release/v0.2.8-phase27-filament-reports-resource
```

## Tag

После merge release branch в `main`:

```txt
v0.2.8-phase27-filament-reports-resource
```
---

# 7. TDD Rules for Phase 27

## Для Resource

Тестировать:

```txt
- admin can access ReportResource index;
- moderator can access ReportResource index;
- resource uses Report model.
```

## Для columns

Тестировать:

```txt
- target type visible;
- reason visible;
- reporter visible;
- status visible;
- created_at visible.
```

## Для filters

Тестировать результат:

```txt
- open filter shows open reports only;
- resolved filter shows resolved reports only;
- ignored filter shows ignored reports only.
```

## Для actions

Тестировать:

```txt
- resolve action calls ResolveReportAction;
- ignore action calls IgnoreReportAction;
- hide target calls HidePostAction for post report;
- hide target calls HideCommentAction for comment report;
- ban target author calls BanUserAction;
- moderator cannot ban target author;
- missing target does not crash table/actions.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Reports / Tests
Type: Test / Feature / Resource / Table / Filter / Action
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым. Если тест напрямую невозможен:
No direct test — причина.

Implementation:
Что именно меняем.

Acceptance criteria:
- Проверяемый результат 1
- Проверяемый результат 2
- Проверяемый результат 3

Definition of Done:
- Тест написан первым, если задача тестируемая
- Тест падает до реализации, если применимо
- Реализация минимальная
- Тест проходит
- Нет direct status mutation в Filament resource
- Backend action используется для state-changing actions
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 27 Atomic Tasks
---

## RG-494 — Create ReportResource

**Area:** Admin / Filament  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-494-create-report-resource`  
**Base branch:** develop
**Depends on:** RG-493

### Goal

Создать Filament `ReportResource` для модели `Report`.

### TDD step

Feature/smoke test:

```php
it('allows admin to access report resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk();
});
```

Moderator access:

```php
it('allows moderator to access report resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(ReportResource::getUrl('index'))
        ->assertOk();
});
```

Тест должен упасть до создания resource.

### Implementation

Создать resource:

```bash
php artisan make:filament-resource Report
```

Минимальный resource:

```php
protected static ?string $model = Report::class;
```

Pages:

```txt
ListReports
```

Set navigation group:

```txt
Moderation
```

Disable create/edit pages unless generated by default. Phase 27 does not need report create/edit forms.

### Acceptance criteria

- `ReportResource` exists.
- Resource uses `Report::class`.
- Admin can access index.
- Moderator can access index.
- Resource appears under Moderation navigation group.
- No create/edit form required.
- Tests pass.

### Definition of Done

- Тесты написаны.
- Resource создан.
- Tests pass.
- Коммит: `RG-494: Create ReportResource`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
app/Filament/Resources/ReportResource/Pages/ListReports.php
tests/Feature/Filament/ReportResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-495 — Add Target Type Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-495-add-target-type-column`  
**Base branch:** develop
**Depends on:** RG-494

### Goal

Добавить column, показывающую тип target content.

### TDD step

Feature test:

```php
it('renders report target type in report resource table', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    Report::factory()->for($post, 'reportable')->create();

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Post');
});
```

### Implementation

Add column:

```php
TextColumn::make('reportable_type')
    ->label('Target')
    ->badge()
    ->formatStateUsing(fn (?string $state): string => match ($state) {
        Post::class => 'Post',
        Comment::class => 'Comment',
        default => 'Unknown',
    })
```

Optional color:

```txt
Post → info
Comment → gray
Unknown → danger
```

### Acceptance criteria

- Target type visible.
- Post report shows `Post`.
- Comment report shows `Comment`.
- Unknown/missing type does not crash.
- Test passes.

### Definition of Done

- Тест написан.
- Target type column добавлен.
- Test passes.
- Коммит: `RG-495: Add target type column`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-496 — Add Reason Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-496-add-reason-column`  
**Base branch:** develop
**Depends on:** RG-495

### Goal

Добавить reason column.

### TDD step

Feature test:

```php
it('renders report reason in report resource table', function () {
    $admin = User::factory()->admin()->create();

    Report::factory()->create([
        'reason' => ReportReason::Spam,
    ]);

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk()
        ->assertSee('spam');
});
```

### Implementation

Add column:

```php
TextColumn::make('reason')
    ->label('Reason')
    ->badge()
    ->sortable()
```

If enum labels exist, use them:

```php
->formatStateUsing(fn (ReportReason|string $state) => ...)
```

### Acceptance criteria

- Reason visible.
- Rendered as badge.
- Sortable if field supports sorting.
- Handles enum/string state.
- Test passes.

### Definition of Done

- Тест написан.
- Reason column добавлен.
- Test passes.
- Коммит: `RG-496: Add reason column`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-497 — Add Reporter Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-497-add-reporter-column`  
**Base branch:** develop
**Depends on:** RG-496

### Goal

Добавить reporter column.

### TDD step

Feature test:

```php
it('renders report reporter in report resource table', function () {
    $admin = User::factory()->admin()->create();

    $reporter = User::factory()->create([
        'username' => 'reporter_user',
    ]);

    Report::factory()->for($reporter, 'user')->create();

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk()
        ->assertSee('reporter_user');
});
```

### Implementation

Add eager loading:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->with(['user', 'reportable']);
}
```

Add column:

```php
TextColumn::make('user.username')
    ->label('Reporter')
    ->searchable()
    ->sortable()
    ->placeholder('—')
```

Fallback to name/email if username missing.

### Acceptance criteria

- Reporter visible.
- Uses username where available.
- Handles missing/deleted reporter safely.
- Avoids N+1.
- Test passes.

### Definition of Done

- Тест написан.
- Reporter column добавлен.
- Eager loading added.
- Test passes.
- Коммит: `RG-497: Add reporter column`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-498 — Add Status Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-498-add-status-column`  
**Base branch:** develop
**Depends on:** RG-497

### Goal

Добавить report status column.

### TDD step

Feature test:

```php
it('renders report status in report resource table', function () {
    $admin = User::factory()->admin()->create();

    Report::factory()->create([
        'status' => ReportStatus::Open,
    ]);

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk()
        ->assertSee('open');
});
```

### Implementation

Add column:

```php
TextColumn::make('status')
    ->label('Status')
    ->badge()
    ->sortable()
```

Color mapping:

```txt
open     → warning
resolved → success
ignored  → gray
```

If `ReportStatus::Ignored` does not exist yet, RG-504 will add it. For now column should not crash if status string is `ignored`.

### Acceptance criteria

- Status visible.
- Rendered as badge.
- Sortable.
- Color mapping exists.
- Test passes.

### Definition of Done

- Тест написан.
- Status column добавлен.
- Test passes.
- Коммит: `RG-498: Add status column`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-499 — Add Created_At Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-499-add-created-at-column`  
**Base branch:** develop
**Depends on:** RG-498

### Goal

Добавить created_at column.

### TDD step

Feature test:

```php
it('renders report created at in report resource table', function () {
    $admin = User::factory()->admin()->create();

    Report::factory()->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($admin)
        ->get(ReportResource::getUrl('index'))
        ->assertOk();
});
```

If exact formatted date is brittle, assert resource table renders and class has column.

### Implementation

Add column:

```php
TextColumn::make('created_at')
    ->label('Created')
    ->dateTime()
    ->sortable()
```

Recommended default sort:

```php
->defaultSort('created_at', 'desc')
```

### Acceptance criteria

- Created date visible.
- Sortable.
- Default table sort newest reports first.
- Test passes.

### Definition of Done

- Тест написан.
- created_at column добавлен.
- Default sort added.
- Test passes.
- Коммит: `RG-499: Add created_at column`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-500 — Add Open Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-500-add-open-filter`  
**Base branch:** develop
**Depends on:** RG-499

### Goal

Добавить open filter.

### TDD step

Filament filter test:

```php
it('filters open reports in report resource', function () {
    $admin = User::factory()->admin()->create();

    $open = Report::factory()->create(['status' => ReportStatus::Open]);
    $resolved = Report::factory()->create(['status' => ReportStatus::Resolved]);

    livewire(ListReports::class)
        ->actingAs($admin)
        ->filterTable('open')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$resolved]);
});
```

### Implementation

Add filter:

```php
Filter::make('open')
    ->label('Open')
    ->query(fn (Builder $query) => $query->where('status', ReportStatus::Open))
```

### Acceptance criteria

- Open filter exists.
- Shows open reports.
- Hides resolved/ignored reports.
- Test passes.

### Definition of Done

- Тест написан.
- Open filter добавлен.
- Test passes.
- Коммит: `RG-500: Add open filter`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-501 — Add Resolved Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-501-add-resolved-filter`  
**Base branch:** develop
**Depends on:** RG-500

### Goal

Добавить resolved filter.

### TDD step

Filament filter test:

```php
it('filters resolved reports in report resource', function () {
    $admin = User::factory()->admin()->create();

    $resolved = Report::factory()->create(['status' => ReportStatus::Resolved]);
    $open = Report::factory()->create(['status' => ReportStatus::Open]);

    livewire(ListReports::class)
        ->actingAs($admin)
        ->filterTable('resolved')
        ->assertCanSeeTableRecords([$resolved])
        ->assertCanNotSeeTableRecords([$open]);
});
```

### Implementation

Add filter:

```php
Filter::make('resolved')
    ->label('Resolved')
    ->query(fn (Builder $query) => $query->where('status', ReportStatus::Resolved))
```

### Acceptance criteria

- Resolved filter exists.
- Shows resolved reports.
- Hides open/ignored reports.
- Open filter still works.
- Test passes.

### Definition of Done

- Тест написан.
- Resolved filter добавлен.
- Test passes.
- Коммит: `RG-501: Add resolved filter`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-502 — Add Ignored Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-502-add-ignored-filter`  
**Base branch:** develop
**Depends on:** RG-501

### Goal

Добавить ignored filter.

### TDD step

Filament filter test:

```php
it('filters ignored reports in report resource', function () {
    $admin = User::factory()->admin()->create();

    $ignored = Report::factory()->create(['status' => ReportStatus::Ignored]);
    $open = Report::factory()->create(['status' => ReportStatus::Open]);

    livewire(ListReports::class)
        ->actingAs($admin)
        ->filterTable('ignored')
        ->assertCanSeeTableRecords([$ignored])
        ->assertCanNotSeeTableRecords([$open]);
});
```

If `ReportStatus::Ignored` does not exist yet, this test should fail and force implementation.

### Implementation

Add enum value if missing:

```php
ReportStatus::Ignored
```

If DB enum exists, add migration. If status is string, no migration needed.

Add filter:

```php
Filter::make('ignored')
    ->label('Ignored')
    ->query(fn (Builder $query) => $query->where('status', ReportStatus::Ignored))
```

If old `Dismissed` status exists, either migrate it or support compatibility deliberately.

### Acceptance criteria

- Ignored status exists.
- Ignored filter exists.
- Shows ignored reports.
- Hides open/resolved reports.
- Test passes.

### Definition of Done

- Тест написан.
- Ignored status support added if needed.
- Ignored filter добавлен.
- Test passes.
- Коммит: `RG-502: Add ignored filter`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
app/Enums/ReportStatus.php
database/migrations/*update_report_status_enum.php
tests/Feature/Filament/ReportResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-503 — Add Resolve Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-503-add-resolve-action`  
**Base branch:** develop
**Depends on:** RG-502

### Goal

Добавить resolve report table action.

### TDD step

Filament action test:

```php
it('resolves open report from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
    ]);

    livewire(ListReports::class)
        ->actingAs($moderator)
        ->callTableAction('resolve', $report, data: [
            'note' => 'Reviewed and handled.',
        ]);

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved);
    expect($report->resolved_by_user_id)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
});
```

Visibility test:

```php
it('shows resolve action only for open reports', ...)
```

### Implementation

Add table action:

```php
Action::make('resolve')
    ->label('Resolve')
    ->color('success')
    ->icon('heroicon-o-check-circle')
    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open)
    ->form([
        Textarea::make('note')
            ->label('Resolution note')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Report $record, array $data): void {
        app(ResolveReportAction::class)->handle(
            auth()->user(),
            $record,
            $data['note'] ?? null,
        );
    })
```

### Acceptance criteria

- Resolve action visible only for open reports.
- Calls ResolveReportAction.
- Status becomes resolved.
- resolved_by/resolved_at/note set by backend action.
- Test passes.

### Definition of Done

- Тест написан.
- Resolve action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-503: Add resolve action`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-504 — Add Ignore Action

**Area:** Admin / Filament / Action / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-504-add-ignore-action`  
**Base branch:** develop
**Depends on:** RG-503

### Goal

Добавить ignore report action и backend `IgnoreReportAction`, если его ещё нет.

### TDD step

Backend action test first:

```php
it('allows moderator to ignore open report', function () {
    $moderator = User::factory()->moderator()->create();

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
    ]);

    app(IgnoreReportAction::class)->handle(
        moderator: $moderator,
        report: $report,
        note: 'Not actionable.'
    );

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Ignored);
});
```

Filament action test:

```php
it('ignores open report from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    livewire(ListReports::class)
        ->actingAs($moderator)
        ->callTableAction('ignore', $report, data: [
            'note' => 'No violation found.',
        ]);

    expect($report->fresh()->status)->toBe(ReportStatus::Ignored);
});
```

### Implementation

Create backend action:

```txt
app/Actions/Reports/IgnoreReportAction.php
```

Action:

```php
final class IgnoreReportAction
{
    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotResolveReportException::becauseUserIsNotAllowed();
        }

        if ($report->status !== ReportStatus::Open) {
            return; // idempotent/safe for admin UX
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        $report->forceFill([
            'status' => ReportStatus::Ignored,
            'resolved_by_user_id' => $moderator->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();
    }
}
```

Use dedicated ignored fields if schema has them:

```txt
ignored_by_user_id
ignored_at
ignore_note
```

Add Filament action:

```php
Action::make('ignore')
    ->label('Ignore')
    ->color('gray')
    ->icon('heroicon-o-no-symbol')
    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open)
    ->form([
        Textarea::make('note')->label('Ignore note')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(fn (Report $record, array $data) =>
        app(IgnoreReportAction::class)->handle(auth()->user(), $record, $data['note'] ?? null)
    )
```

### Acceptance criteria

- IgnoreReportAction exists.
- Moderator/admin can ignore open report.
- Normal user cannot ignore.
- Ignore action visible only for open reports.
- Status becomes ignored.
- Note/processor metadata saved.
- Test passes.

### Definition of Done

- Backend action/test written.
- Filament action/test written.
- No direct report status mutation in resource.
- Test passes.
- Коммит: `RG-504: Add ignore action`

### Files likely touched

```txt
app/Actions/Reports/IgnoreReportAction.php
app/Enums/ReportStatus.php
app/Filament/Resources/ReportResource.php
tests/Feature/Actions/IgnoreReportActionTest.php
tests/Feature/Filament/ReportResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-505 — Add Hide Target Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-505-add-hide-target-action`  
**Base branch:** develop
**Depends on:** RG-504

### Goal

Добавить action, который скрывает target report-а.

### TDD step

Post target test:

```php
it('hides reported post from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $report = Report::factory()
        ->for($post, 'reportable')
        ->create(['status' => ReportStatus::Open]);

    livewire(ListReports::class)
        ->actingAs($moderator)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Violates content rules.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});
```

Comment target test:

```php
it('hides reported comment from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

    $report = Report::factory()
        ->for($comment, 'reportable')
        ->create(['status' => ReportStatus::Open]);

    livewire(ListReports::class)
        ->actingAs($moderator)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Abusive comment.',
        ]);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});
```

Missing target test:

```php
it('does not crash hide target action when target is missing', ...)
```

### Implementation

Add action:

```php
Action::make('hideTarget')
    ->label('Hide target')
    ->color('danger')
    ->icon('heroicon-o-eye-slash')
    ->visible(fn (Report $record): bool => $record->reportable !== null)
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Report $record, array $data): void {
        $target = $record->reportable;
        $reason = $data['reason'] ?? null;

        match (true) {
            $target instanceof Post => app(HidePostAction::class)->handle(auth()->user(), $target, $reason),
            $target instanceof Comment => app(HideCommentAction::class)->handle(auth()->user(), $target, $reason),
            default => throw new RuntimeException('Unsupported report target.'),
        };
    })
```

If `HideCommentAction` does not accept reason, extend it safely.

Do not automatically resolve the report unless explicitly decided. Recommended: show a notification telling moderator to resolve/ignore report next.

### Acceptance criteria

- Hide target action visible only when target exists.
- Post target uses HidePostAction.
- Comment target uses HideCommentAction.
- No direct target status mutation.
- Missing/unsupported target handled safely.
- Report status is not silently changed.
- Test passes.

### Definition of Done

- Tests written.
- Hide target action added.
- Backend actions used.
- Test passes.
- Коммит: `RG-505: Add hide target action`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
app/Actions/Comments/HideCommentAction.php
tests/Feature/Filament/ReportResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-506 — Add Ban Target Author Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-506-add-ban-target-author-action`  
**Base branch:** develop
**Depends on:** RG-505

### Goal

Добавить action, который банит автора target content.

### TDD step

Post author test:

```php
it('allows admin to ban reported post author from report resource', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['status' => UserStatus::Active]);
    $post = Post::factory()->for($author)->published()->create();

    $report = Report::factory()
        ->for($post, 'reportable')
        ->create(['status' => ReportStatus::Open]);

    livewire(ListReports::class)
        ->actingAs($admin)
        ->callTableAction('banTargetAuthor', $report, data: [
            'reason' => 'Repeated violations.',
        ]);

    expect($author->fresh()->status)->toBe(UserStatus::Banned);
});
```

Comment author test:

```php
it('allows admin to ban reported comment author from report resource', ...)
```

Moderator blocked/hidden test:

```php
it('does not show ban target author action to moderator', ...)
```

Protected author test:

```php
it('does not allow banning admin target author from report resource', ...)
```

### Implementation

Add helper in `ReportResource` or small private method:

```php
private static function targetAuthor(Report $report): ?User
{
    $target = $report->reportable;

    return match (true) {
        $target instanceof Post => $target->user,
        $target instanceof Comment => $target->user,
        default => null,
    };
}
```

Add action:

```php
Action::make('banTargetAuthor')
    ->label('Ban target author')
    ->color('danger')
    ->icon('heroicon-o-user-minus')
    ->visible(function (Report $record): bool {
        $admin = auth()->user();
        $author = static::targetAuthor($record);

        return $admin?->isAdmin()
            && $author !== null
            && $admin->id !== $author->id
            && ! $author->isAdmin()
            && $author->status !== UserStatus::Banned;
    })
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Report $record, array $data): void {
        $author = static::targetAuthor($record);

        if (! $author) {
            throw new RuntimeException('Report target author not found.');
        }

        app(BanUserAction::class)->handle(
            auth()->user(),
            $author,
            $data['reason'] ?? null,
        );
    })
```

Do not direct update user status.

### Acceptance criteria

- Ban target author visible only to admin.
- Hidden from moderator.
- Hidden when target/author missing.
- Hidden/protected for admin/self target.
- Post author can be banned via BanUserAction.
- Comment author can be banned via BanUserAction.
- Moderation log written by backend action.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- Ban target author action added.
- Backend action used.
- Tests/build pass.
- Коммит: `RG-506: Add ban target author action`

### Files likely touched

```txt
app/Filament/Resources/ReportResource.php
tests/Feature/Filament/ReportResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 27 Completion Criteria

Phase 27 завершена, когда:

```txt
- RG-494–RG-506 выполнены;
- ReportResource exists;
- admin can access ReportResource;
- moderator can access ReportResource;
- table shows target type;
- table shows reason;
- table shows reporter;
- table shows status;
- table shows created_at;
- open filter works;
- resolved filter works;
- ignored filter works;
- resolve action works via ResolveReportAction;
- ignore action works via IgnoreReportAction;
- hide target action works for Post via HidePostAction;
- hide target action works for Comment via HideCommentAction;
- ban target author works via BanUserAction;
- moderator cannot ban target author;
- missing target/author does not crash table;
- no direct status mutation in ReportResource;
- no bulk report actions added;
- no TagResource/ModerationDashboard created;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 27

Без отдельной задачи нельзя:

```txt
- создавать TagResource;
- создавать ModerationDashboard;
- делать bulk resolve/ignore/hide/ban;
- удалять reports;
- редактировать report reason/message;
- создавать public reports UI;
- менять ReportModal;
- автоматически resolve report после hide target;
- автоматически ban user after repeated reports;
- добавлять notifications;
- добавлять analytics widgets;
- добавлять queue jobs;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-494 Create ReportResource
RG-495 Add target type column
RG-496 Add reason column
RG-497 Add reporter column
RG-498 Add status column
RG-499 Add created_at column
RG-500 Add open filter
RG-501 Add resolved filter
RG-502 Add ignored filter
RG-503 Add resolve action
RG-504 Add ignore action
RG-505 Add hide target action
RG-506 Add ban target author action
```
---

# 13. Release

После завершения Phase 27:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.8-phase27-filament-reports-resource
git push -u origin release/v0.2.8-phase27-filament-reports-resource
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.8-phase27-filament-reports-resource -m "RateGuru Phase 27 Filament Reports Resource"
git push origin v0.2.8-phase27-filament-reports-resource
```
