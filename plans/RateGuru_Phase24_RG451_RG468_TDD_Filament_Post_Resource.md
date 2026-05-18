# RateGuru — Phase 24 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 24 — Filament Post Resource**  
Диапазон задач: **RG-451 → RG-468**  
Основа нумерации: исходный atomic backlog, где Phase 24 начинается с задачи 451 и заканчивается задачей 468.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 24 соответствует исходному блоку:

```txt
Phase 24 — Filament Post Resource
```

Правильный диапазон Phase 24:

```txt
RG-451 — Create PostResource
RG-452 — Add image column to PostResource table
RG-453 — Add title column to PostResource table
RG-454 — Add author column to PostResource table
RG-455 — Add status column to PostResource table
RG-456 — Add reports_count column to PostResource table
RG-457 — Add created_at column to PostResource table
RG-458 — Add pending filter to PostResource
RG-459 — Add published filter to PostResource
RG-460 — Add hidden filter to PostResource
RG-461 — Add reported filter to PostResource
RG-462 — Add approve table action
RG-463 — Add reject table action
RG-464 — Add hide table action
RG-465 — Add restore table action
RG-466 — Add delete table action
RG-467 — Add bulk hide action
RG-468 — Add bulk approve action
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 25 начинается с `RG-469` и делает **Filament User Resource**. Поэтому Phase 24 не должна создавать UserResource, CommentResource, ReportResource или Dashboard widgets.
---

# 2. Цель Phase 24

Phase 24 добавляет Filament-ресурс для управления постами в `/admin`.

После Phase 24 moderator/admin должен уметь в Filament:

```txt
- открыть список posts;
- видеть image/title/author/status/reports_count/created_at;
- фильтровать posts по pending/published/hidden/reported;
- approve pending post;
- reject pending post;
- hide published post;
- restore hidden post;
- delete post через безопасную admin-only action;
- bulk hide selected posts;
- bulk approve selected posts.
```

Это не публичный UI.  
Это админский table-first moderation interface поверх backend actions из Phase 21.
---

# 3. Scope Phase 24

## Входит

```txt
- PostResource;
- ListPosts page;
- table columns;
- table filters;
- row actions for moderation;
- row delete action;
- bulk hide action;
- bulk approve action;
- authorization/visibility rules inside Filament actions;
- tests/smoke tests for resource accessibility and critical actions.
```

## Не входит

```txt
- UserResource;
- CommentResource;
- ReportResource;
- TagResource;
- ModerationDashboard;
- post create/edit form;
- full post preview page;
- image upload inside admin;
- changing tags in admin;
- resolving reports from PostResource;
- banning target author from PostResource;
- analytics widgets.
```

PostResource в Phase 24 — это table-first moderation resource.  
Не превращать его в полный CMS.
---

# 4. Critical Decisions

## 4.1. PostResource is moderation-first, not CMS-first

В Phase 24 не нужно делать полноценное редактирование постов.

Правильный MVP:

```txt
- ListPosts page;
- table columns;
- filters;
- moderation actions.
```

Неправильно:

```txt
- большой EditPost form;
- create post form;
- tag editor;
- image replacement;
- SEO editor.
```

Это раздует фазу и создаст новые rules, которых нет в backlog.

## 4.2. Filament actions must call backend actions

Нельзя в Filament action делать:

```php
$record->update(['status' => PostStatus::Published]);
```

Правильно:

```php
app(ApprovePostAction::class)->handle(auth()->user(), $record, $reason);
```

Это сохраняет:

```txt
- authorization guards;
- state transition guards;
- moderation logs;
- consistency with inline moderation UI.
```

## 4.3. Delete action is dangerous

Backlog содержит:

```txt
RG-466 — Add delete table action
```

Это не значит, что надо сделать blind hard delete.

Правило Phase 24:

```txt
Delete action:
- admin-only;
- hidden from moderators;
- confirmation required;
- soft delete preferred;
- hard delete prohibited unless project already explicitly supports it;
- should write moderation log if possible.
```

Если `Post` не использует SoftDeletes, RG-466 должен либо:

```txt
- добавить SoftDeletes migration/model support;
```

либо:

```txt
- сделать action disabled с явной заметкой "Soft delete not configured".
```

Лучше добавить SoftDeletes. Irreversible hard delete в админке — слабое решение.

## 4.4. Bulk actions must reuse single-record actions

Bulk hide/approve не должны обходить business rules.

Правильно:

```php
foreach ($records as $post) {
    app(HidePostAction::class)->handle($moderator, $post, $reason);
}
```

Неправильно:

```php
$records->each->update(['status' => 'hidden']);
```

## 4.5. Action visibility must follow status

Row actions:

```txt
pending:
- approve
- reject

published:
- hide

hidden:
- restore

rejected:
- no approve/restore unless future workflow adds it
```

Delete action can exist for admin on most statuses, but must be careful.

## 4.6. Reported filter

`reported` filter should mean:

```txt
reports_count > 0
```

or, if report threshold fields exist:

```txt
needs_review = true OR reports_count > 0
```

Recommended MVP:

```txt
reported = reports_count > 0
```

Do not join reports table unless needed.

## 4.7. Admin route naming may differ by Filament version

Phase 23 configured admin panel.  
Exact route names may vary by Filament version/panel id.

Do not hardcode brittle route names in tests if there is a helper:

```php
PostResource::getUrl('index')
```

Prefer this over raw `/admin/posts`.
---

# 5. Architecture Rules

## 5.1. Resource location

Adapt to installed Filament version.

Likely paths:

```txt
app/Filament/Resources/PostResource.php
app/Filament/Resources/PostResource/Pages/ListPosts.php
```

In newer Filament versions, table/form classes may be split differently.  
Use the project's installed Filament conventions from Phase 23.

## 5.2. Table query must eager-load author

PostResource table should avoid N+1:

```php
Post::query()->with('user')
```

If Filament resource has `getEloquentQuery()`, override it.

## 5.3. No public route leakage

PostResource is only inside `/admin`.

Do not add public routes.

## 5.4. Tests should avoid over-coupling to HTML

Filament HTML is complex.  
Tests should prioritize:

```txt
- resource route access;
- resource class has expected columns/filters/actions where feasible;
- actions call backend actions and change status;
- normal user cannot access admin remains covered by Phase 23.
```

Avoid brittle exact HTML snapshots unless visual regression phase later.

## 5.5. No duplicate moderation logic

If a Filament action needs modal reason input, it passes reason to backend action.

Business logic stays in:

```txt
ApprovePostAction
RejectPostAction
HidePostAction
RestorePostAction
CreateModerationLogAction
```
---

# 6. GitFlow для Phase 24

## Base branch

Все задачи Phase 24 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-451-create-post-resource
feature/RG-462-add-approve-table-action
feature/RG-468-add-bulk-approve-action
```

## Commit format

```txt
RG-451: Create PostResource
RG-462: Add approve table action
RG-468: Add bulk approve action
```

## Release branch

После выполнения `RG-451`–`RG-468`:

```txt
release/v0.2.5-phase24-filament-post-resource
```

## Tag

После merge release branch в `main`:

```txt
v0.2.5-phase24-filament-post-resource
```
---

# 7. TDD Rules for Phase 24

## Для Resource

Тестировать:

```txt
- admin can access PostResource index;
- moderator can access PostResource index;
- normal user cannot access admin remains covered by Phase 23;
- resource class exists;
- resource uses Post model.
```

## Для columns

Column tests могут быть class-level или smoke-level:

```txt
- table includes image column;
- table includes title column;
- table includes author column;
- table includes status column;
- table includes reports_count column;
- table includes created_at column.
```

Если прямое introspection неудобно, допускается smoke test на rendered table for one record.

## Для filters

Тестировать по результату query:

```txt
- pending filter shows pending posts only;
- published filter shows published posts only;
- hidden filter shows hidden posts only;
- reported filter shows posts with reports_count > 0 only.
```

## Для actions

Тестировать через Filament Livewire page where feasible:

```txt
- approve action changes status and creates moderation log;
- reject action changes status and creates moderation log;
- hide action changes status and creates moderation log;
- restore action changes status and creates moderation log.
```

Если Filament action testing is too brittle, test backend action already exists and add resource action registration smoke tests. Preferred path: test the Filament table action call.
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Tests
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
- Нет business logic в Filament resource
- Backend action используется для state-changing actions
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 24 Atomic Tasks
---

## RG-451 — Create PostResource

**Area:** Admin / Filament  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-451-create-post-resource`  
**Base branch:** develop
**Depends on:** RG-450

### Goal

Создать Filament `PostResource` для модели `Post`.

### TDD step

Feature/smoke test:

```php
it('allows admin to access post resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk();
});
```

Moderator access test:

```php
it('allows moderator to access post resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(PostResource::getUrl('index'))
        ->assertOk();
});
```

### Implementation

Создать resource:

```bash
php artisan make:filament-resource Post
```

Минимальный resource:

```php
protected static ?string $model = Post::class;
```

Pages:

```txt
ListPosts
```

Disable create/edit pages unless generated by default and needed.

Set navigation group:

```txt
Content
```

### Acceptance criteria

- `PostResource` exists.
- Resource uses `Post::class`.
- Admin can access index.
- Moderator can access index.
- Resource appears under Content navigation group.
- No create/edit form is required in this phase.
- Tests pass.

### Definition of Done

- Тесты написаны.
- Resource создан.
- Tests pass.
- Коммит: `RG-451: Create PostResource`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
app/Filament/Resources/PostResource/Pages/ListPosts.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-452 — Add Image Column To PostResource Table

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-452-add-image-column-to-post-resource-table`  
**Base branch:** develop
**Depends on:** RG-451

### Goal

Добавить image thumbnail column в PostResource table.

### TDD step

Feature/smoke test:

```php
it('renders post image in post resource table', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create([
        'image_path' => 'posts/demo.jpg',
    ]);

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk()
        ->assertSee('posts/demo.jpg');
});
```

Если actual URL transforms via accessor, assert accessor output.

### Implementation

В table columns:

```php
ImageColumn::make('image_url')
    ->label('Image')
    ->square()
```

или если accessor отсутствует:

```php
ImageColumn::make('image_path')
    ->disk(config('filesystems.default'))
```

Выбрать существующее поле:

```txt
image_url
image_path
thumbnail_url
```

Не создавать новое поле без необходимости.

### Acceptance criteria

- Table has image column.
- Uses thumbnail/image accessor if available.
- Missing image does not break table.
- Column label = Image.
- Test/smoke passes.

### Definition of Done

- Тест написан.
- Image column добавлен.
- Test passes.
- Коммит: `RG-452: Add image column to PostResource table`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-453 — Add Title Column To PostResource Table

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-453-add-title-column-to-post-resource-table`  
**Base branch:** develop
**Depends on:** RG-452

### Goal

Добавить title column.

### TDD step

```php
it('renders post title in post resource table', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create([
        'title' => 'Homemade Pasta',
    ]);

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Homemade Pasta');
});
```

### Implementation

Add column:

```php
TextColumn::make('title')
    ->label('Title')
    ->searchable()
    ->sortable()
    ->limit(60)
```

### Acceptance criteria

- Title visible.
- Column searchable.
- Column sortable.
- Long titles limited.
- Test passes.

### Definition of Done

- Тест написан.
- Title column добавлен.
- Test passes.
- Коммит: `RG-453: Add title column to PostResource table`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-454 — Add Author Column To PostResource Table

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-454-add-author-column-to-post-resource-table`  
**Base branch:** develop
**Depends on:** RG-453

### Goal

Добавить author column.

### TDD step

```php
it('renders post author in post resource table', function () {
    $admin = User::factory()->admin()->create();

    $author = User::factory()->create([
        'username' => 'chef_ivan',
        'name' => 'Ivan Chef',
    ]);

    Post::factory()->published()->for($author)->create();

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk()
        ->assertSee('chef_ivan');
});
```

### Implementation

Add column:

```php
TextColumn::make('user.username')
    ->label('Author')
    ->searchable()
    ->sortable()
```

If username nullable, fallback to name/email via `formatStateUsing`.

Ensure eager loading:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()->with('user');
}
```

### Acceptance criteria

- Author visible.
- Uses username if available.
- Handles missing username/user safely.
- Avoids N+1 by eager loading user.
- Test passes.

### Definition of Done

- Тест написан.
- Author column добавлен.
- Eager loading добавлен.
- Test passes.
- Коммит: `RG-454: Add author column to PostResource table`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-455 — Add Status Column To PostResource Table

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-455-add-status-column-to-post-resource-table`  
**Base branch:** develop
**Depends on:** RG-454

### Goal

Добавить status column с читаемым badge.

### TDD step

```php
it('renders post status in post resource table', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->pending()->create([
        'title' => 'Pending dish',
    ]);

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk()
        ->assertSee('pending');
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

Optional color mapping:

```php
->color(fn (PostStatus $state): string => match ($state) {
    PostStatus::Pending => 'warning',
    PostStatus::Published => 'success',
    PostStatus::Hidden => 'gray',
    PostStatus::Rejected => 'danger',
})
```

Adapt if state is string rather than enum.

### Acceptance criteria

- Status visible.
- Rendered as badge.
- Sortable.
- Colors mapped if stable.
- Test passes.

### Definition of Done

- Тест написан.
- Status column добавлен.
- Test passes.
- Коммит: `RG-455: Add status column to PostResource table`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-456 — Add Reports_Count Column To PostResource Table

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-456-add-reports-count-column-to-post-resource-table`  
**Base branch:** develop
**Depends on:** RG-455

### Goal

Добавить reports_count column.

### TDD step

```php
it('renders reports count in post resource table', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create([
        'title' => 'Reported dish',
        'reports_count' => 5,
    ]);

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk()
        ->assertSee('5');
});
```

### Implementation

Add column:

```php
TextColumn::make('reports_count')
    ->label('Reports')
    ->numeric()
    ->sortable()
    ->badge()
```

Optional color:

```php
->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
```

### Acceptance criteria

- reports_count visible.
- Sortable.
- Shows warning/danger styling for reported posts.
- Test passes.

### Definition of Done

- Тест написан.
- reports_count column добавлен.
- Test passes.
- Коммит: `RG-456: Add reports_count column to PostResource table`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-457 — Add Created_At Column To PostResource Table

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-457-add-created-at-column-to-post-resource-table`  
**Base branch:** develop
**Depends on:** RG-456

### Goal

Добавить created_at column.

### TDD step

```php
it('renders created at column in post resource table', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($admin)
        ->get(PostResource::getUrl('index'))
        ->assertOk();
});
```

Do not over-assert exact formatted date unless project has stable locale.

### Implementation

Add column:

```php
TextColumn::make('created_at')
    ->label('Created')
    ->dateTime()
    ->sortable()
```

### Acceptance criteria

- Created date visible.
- Sortable.
- Human-readable.
- Test passes.

### Definition of Done

- Тест написан.
- created_at column добавлен.
- Test passes.
- Коммит: `RG-457: Add created_at column to PostResource table`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-458 — Add Pending Filter To PostResource

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-458-add-pending-filter-to-post-resource`  
**Base branch:** develop
**Depends on:** RG-457

### Goal

Добавить pending filter.

### TDD step

Filament table filter test where feasible:

```php
it('filters posts by pending status in post resource', function () {
    $admin = User::factory()->admin()->create();

    $pending = Post::factory()->pending()->create(['title' => 'Pending dish']);
    $published = Post::factory()->published()->create(['title' => 'Published dish']);

    livewire(ListPosts::class)
        ->actingAs($admin)
        ->filterTable('pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$published]);
});
```

Adapt to installed Filament test helpers.

### Implementation

Add filter:

```php
Filter::make('pending')
    ->label('Pending')
    ->query(fn (Builder $query) => $query->where('status', PostStatus::Pending))
```

### Acceptance criteria

- Pending filter exists.
- It shows pending posts.
- It hides non-pending posts.
- Test passes.

### Definition of Done

- Тест написан.
- Pending filter добавлен.
- Test passes.
- Коммит: `RG-458: Add pending filter to PostResource`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-459 — Add Published Filter To PostResource

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-459-add-published-filter-to-post-resource`  
**Base branch:** develop
**Depends on:** RG-458

### Goal

Добавить published filter.

### TDD step

```php
it('filters posts by published status in post resource', function () {
    $admin = User::factory()->admin()->create();

    $published = Post::factory()->published()->create(['title' => 'Published dish']);
    $pending = Post::factory()->pending()->create(['title' => 'Pending dish']);

    livewire(ListPosts::class)
        ->actingAs($admin)
        ->filterTable('published')
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$pending]);
});
```

### Implementation

Add filter:

```php
Filter::make('published')
    ->label('Published')
    ->query(fn (Builder $query) => $query->where('status', PostStatus::Published))
```

### Acceptance criteria

- Published filter exists.
- It shows published posts.
- It hides non-published posts.
- Pending filter still works.
- Test passes.

### Definition of Done

- Тест написан.
- Published filter добавлен.
- Test passes.
- Коммит: `RG-459: Add published filter to PostResource`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-460 — Add Hidden Filter To PostResource

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-460-add-hidden-filter-to-post-resource`  
**Base branch:** develop
**Depends on:** RG-459

### Goal

Добавить hidden filter.

### TDD step

```php
it('filters posts by hidden status in post resource', function () {
    $admin = User::factory()->admin()->create();

    $hidden = Post::factory()->hidden()->create(['title' => 'Hidden dish']);
    $published = Post::factory()->published()->create(['title' => 'Published dish']);

    livewire(ListPosts::class)
        ->actingAs($admin)
        ->filterTable('hidden')
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$published]);
});
```

### Implementation

Add filter:

```php
Filter::make('hidden')
    ->label('Hidden')
    ->query(fn (Builder $query) => $query->where('status', PostStatus::Hidden))
```

### Acceptance criteria

- Hidden filter exists.
- It shows hidden posts.
- It hides non-hidden posts.
- Existing filters still work.
- Test passes.

### Definition of Done

- Тест написан.
- Hidden filter добавлен.
- Test passes.
- Коммит: `RG-460: Add hidden filter to PostResource`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-461 — Add Reported Filter To PostResource

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-461-add-reported-filter-to-post-resource`  
**Base branch:** develop
**Depends on:** RG-460

### Goal

Добавить reported filter.

### TDD step

```php
it('filters reported posts in post resource', function () {
    $admin = User::factory()->admin()->create();

    $reported = Post::factory()->published()->create([
        'title' => 'Reported dish',
        'reports_count' => 2,
    ]);

    $clean = Post::factory()->published()->create([
        'title' => 'Clean dish',
        'reports_count' => 0,
    ]);

    livewire(ListPosts::class)
        ->actingAs($admin)
        ->filterTable('reported')
        ->assertCanSeeTableRecords([$reported])
        ->assertCanNotSeeTableRecords([$clean]);
});
```

### Implementation

Add filter:

```php
Filter::make('reported')
    ->label('Reported')
    ->query(fn (Builder $query) => $query->where('reports_count', '>', 0))
```

If `needs_review` is primary in your schema, use:

```php
$query->where('reports_count', '>', 0)->orWhere('needs_review', true)
```

Keep it simple unless tests require.

### Acceptance criteria

- Reported filter exists.
- Shows posts with reports_count > 0.
- Hides posts with reports_count = 0.
- Existing status filters still work.
- Test passes.

### Definition of Done

- Тест написан.
- Reported filter добавлен.
- Test passes.
- Коммит: `RG-461: Add reported filter to PostResource`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-462 — Add Approve Table Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-462-add-approve-table-action`  
**Base branch:** develop
**Depends on:** RG-461

### Goal

Добавить row action для approve pending post.

### TDD step

Filament table action test:

```php
it('approves pending post from post resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->callTableAction('approve', $post, data: [
            'reason' => 'Valid post.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'moderatable_type' => Post::class,
        'moderatable_id' => $post->id,
    ]);
});
```

Visibility test:

```txt
approve action is visible for pending post and hidden for published post
```

### Implementation

Add table action:

```php
Action::make('approve')
    ->label('Approve')
    ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending)
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Post $record, array $data): void {
        app(ApprovePostAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

### Acceptance criteria

- Approve action visible only for pending posts.
- Action calls ApprovePostAction.
- Status changes to published.
- Moderation log written by backend action.
- Test passes.

### Definition of Done

- Тест написан.
- Approve action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-462: Add approve table action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-463 — Add Reject Table Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-463-add-reject-table-action`  
**Base branch:** develop
**Depends on:** RG-462

### Goal

Добавить row action для reject pending post.

### TDD step

```php
it('rejects pending post from post resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->callTableAction('reject', $post, data: [
            'reason' => 'Invalid content.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);
});
```

### Implementation

Add action:

```php
Action::make('reject')
    ->label('Reject')
    ->color('danger')
    ->visible(fn (Post $record): bool => $record->status === PostStatus::Pending)
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Post $record, array $data): void {
        app(RejectPostAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

### Acceptance criteria

- Reject action visible only for pending posts.
- Action calls RejectPostAction.
- Status changes to rejected.
- Moderation log written.
- Test passes.

### Definition of Done

- Тест написан.
- Reject action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-463: Add reject table action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-464 — Add Hide Table Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-464-add-hide-table-action`  
**Base branch:** develop
**Depends on:** RG-463

### Goal

Добавить row action для hide published post.

### TDD step

```php
it('hides published post from post resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->callTableAction('hide', $post, data: [
            'reason' => 'Reported content.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});
```

### Implementation

Add action:

```php
Action::make('hide')
    ->label('Hide')
    ->color('danger')
    ->visible(fn (Post $record): bool => $record->status === PostStatus::Published)
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Post $record, array $data): void {
        app(HidePostAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

### Acceptance criteria

- Hide action visible only for published posts.
- Action calls HidePostAction.
- Status changes to hidden.
- Moderation log written.
- Confirmation required.
- Test passes.

### Definition of Done

- Тест написан.
- Hide action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-464: Add hide table action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-465 — Add Restore Table Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-465-add-restore-table-action`  
**Base branch:** develop
**Depends on:** RG-464

### Goal

Добавить row action для restore hidden post.

### TDD step

```php
it('restores hidden post from post resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->callTableAction('restore', $post, data: [
            'reason' => 'Reviewed and restored.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
```

### Implementation

Add action:

```php
Action::make('restore')
    ->label('Restore')
    ->color('success')
    ->visible(fn (Post $record): bool => $record->status === PostStatus::Hidden)
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Post $record, array $data): void {
        app(RestorePostAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

### Acceptance criteria

- Restore action visible only for hidden posts.
- Action calls RestorePostAction.
- Status changes to published.
- Moderation log written.
- Test passes.

### Definition of Done

- Тест написан.
- Restore action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-465: Add restore table action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-466 — Add Delete Table Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-466-add-delete-table-action`  
**Base branch:** develop
**Depends on:** RG-465

### Goal

Добавить безопасную delete table action.

### TDD step

Admin-only test:

```php
it('allows admin to delete post from post resource table action', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    livewire(ListPosts::class)
        ->actingAs($admin)
        ->callTableAction('delete', $post);

    $this->assertSoftDeleted('posts', ['id' => $post->id]);
});
```

Moderator blocked/hidden test:

```php
it('does not allow moderator to delete post from post resource', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->assertTableActionHidden('delete', $post);
});
```

If SoftDeletes not available yet, this test should force the implementation decision.

### Implementation

Preferred:

```txt
- add SoftDeletes to Post if missing;
- add deleted_at migration if missing;
- use Filament DeleteAction admin-only;
- require confirmation.
```

Action:

```php
DeleteAction::make()
    ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
```

If using custom action to log deletion:

```php
Action::make('delete')
    ->label('Delete')
    ->color('danger')
    ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
    ->requiresConfirmation()
    ->action(function (Post $record): void {
        $record->delete();
    })
```

Hard delete is forbidden unless project explicitly requires it.

### Acceptance criteria

- Delete action visible only to admin.
- Hidden from moderator.
- Requires confirmation.
- Uses soft delete if available.
- Does not hard-delete accidentally.
- Test passes.

### Definition of Done

- Тест написан.
- Delete action added safely.
- SoftDeletes added if needed.
- Test passes.
- Коммит: `RG-466: Add delete table action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
app/Models/Post.php
database/migrations/*add_deleted_at_to_posts_table.php
tests/Feature/Filament/PostResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-467 — Add Bulk Hide Action

**Area:** Admin / Filament / Bulk Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-467-add-bulk-hide-action`  
**Base branch:** develop
**Depends on:** RG-466

### Goal

Добавить bulk hide action для selected published posts.

### TDD step

Bulk action test:

```php
it('bulk hides selected published posts from post resource', function () {
    $moderator = User::factory()->moderator()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->selectTableRecords([$first->id, $second->id])
        ->callTableBulkAction('bulkHide', data: [
            'reason' => 'Bulk moderation.',
        ]);

    expect($first->fresh()->status)->toBe(PostStatus::Hidden);
    expect($second->fresh()->status)->toBe(PostStatus::Hidden);
});
```

### Implementation

Add bulk action:

```php
BulkAction::make('bulkHide')
    ->label('Hide selected')
    ->color('danger')
    ->requiresConfirmation()
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->action(function (Collection $records, array $data): void {
        $records->each(function (Post $record) use ($data): void {
            if ($record->status !== PostStatus::Published) {
                return;
            }

            app(HidePostAction::class)->handle(
                auth()->user(),
                $record,
                $data['reason'] ?? null,
            );
        });
    })
```

Must call `HidePostAction`.

### Acceptance criteria

- Bulk hide action exists.
- Requires selected rows.
- Requires confirmation.
- Calls HidePostAction per record.
- Published selected posts become hidden.
- Invalid-status records are not blindly updated.
- Moderation logs written per successful record.
- Test passes.

### Definition of Done

- Тест написан.
- Bulk hide action added.
- Backend action used.
- Test passes.
- Коммит: `RG-467: Add bulk hide action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceBulkActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-468 — Add Bulk Approve Action

**Area:** Admin / Filament / Bulk Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-468-add-bulk-approve-action`  
**Base branch:** develop
**Depends on:** RG-467

### Goal

Добавить bulk approve action для selected pending posts.

### TDD step

Bulk action test:

```php
it('bulk approves selected pending posts from post resource', function () {
    $moderator = User::factory()->moderator()->create();

    $first = Post::factory()->pending()->create();
    $second = Post::factory()->pending()->create();

    livewire(ListPosts::class)
        ->actingAs($moderator)
        ->selectTableRecords([$first->id, $second->id])
        ->callTableBulkAction('bulkApprove', data: [
            'reason' => 'Bulk approval.',
        ]);

    expect($first->fresh()->status)->toBe(PostStatus::Published);
    expect($second->fresh()->status)->toBe(PostStatus::Published);
});
```

### Implementation

Add bulk action:

```php
BulkAction::make('bulkApprove')
    ->label('Approve selected')
    ->color('success')
    ->requiresConfirmation()
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->action(function (Collection $records, array $data): void {
        $records->each(function (Post $record) use ($data): void {
            if ($record->status !== PostStatus::Pending) {
                return;
            }

            app(ApprovePostAction::class)->handle(
                auth()->user(),
                $record,
                $data['reason'] ?? null,
            );
        });
    })
```

Must call `ApprovePostAction`.

### Acceptance criteria

- Bulk approve action exists.
- Requires selected rows.
- Requires confirmation.
- Calls ApprovePostAction per record.
- Pending selected posts become published.
- Invalid-status records are not blindly updated.
- Moderation logs written per successful record.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Тест написан.
- Bulk approve action added.
- Backend action used.
- Tests/build pass.
- Коммит: `RG-468: Add bulk approve action`

### Files likely touched

```txt
app/Filament/Resources/PostResource.php
tests/Feature/Filament/PostResourceBulkActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 24 Completion Criteria

Phase 24 завершена, когда:

```txt
- RG-451–RG-468 выполнены;
- PostResource exists;
- admin can access PostResource;
- moderator can access PostResource;
- table shows image;
- table shows title;
- table shows author;
- table shows status;
- table shows reports_count;
- table shows created_at;
- pending filter works;
- published filter works;
- hidden filter works;
- reported filter works;
- approve row action works via ApprovePostAction;
- reject row action works via RejectPostAction;
- hide row action works via HidePostAction;
- restore row action works via RestorePostAction;
- delete action is admin-only and safe;
- bulk hide works via HidePostAction;
- bulk approve works via ApprovePostAction;
- moderation logs are created by backend actions;
- no UserResource/CommentResource/ReportResource created;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 24

Без отдельной задачи нельзя:

```txt
- создавать UserResource;
- создавать CommentResource;
- создавать ReportResource;
- создавать TagResource;
- создавать ModerationDashboard;
- делать полноценный post edit form;
- делать post create form;
- редактировать image/tags/source_url через admin;
- resolve reports from PostResource;
- ban users from PostResource;
- добавлять dashboard widgets;
- добавлять queue jobs;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-451 Create PostResource
RG-452 Add image column to PostResource table
RG-453 Add title column to PostResource table
RG-454 Add author column to PostResource table
RG-455 Add status column to PostResource table
RG-456 Add reports_count column to PostResource table
RG-457 Add created_at column to PostResource table
RG-458 Add pending filter to PostResource
RG-459 Add published filter to PostResource
RG-460 Add hidden filter to PostResource
RG-461 Add reported filter to PostResource
RG-462 Add approve table action
RG-463 Add reject table action
RG-464 Add hide table action
RG-465 Add restore table action
RG-466 Add delete table action
RG-467 Add bulk hide action
RG-468 Add bulk approve action
```
---

# 13. Release

После завершения Phase 24:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.5-phase24-filament-post-resource
git push -u origin release/v0.2.5-phase24-filament-post-resource
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.5-phase24-filament-post-resource -m "RateGuru Phase 24 Filament Post Resource"
git push origin v0.2.5-phase24-filament-post-resource
```
