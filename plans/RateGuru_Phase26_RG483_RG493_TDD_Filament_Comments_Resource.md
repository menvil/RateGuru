# RateGuru — Phase 26 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 26 — Filament Comments Resource**  
Диапазон задач: **RG-483 → RG-493**  
Основа нумерации: исходный atomic backlog, где Phase 26 начинается с задачи 483 и заканчивается задачей 493.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 26 соответствует исходному блоку:

```txt
Phase 26 — Filament Comments Resource
```

Правильный диапазон Phase 26:

```txt
RG-483 — Create CommentResource
RG-484 — Add body excerpt column
RG-485 — Add author column
RG-486 — Add post column
RG-487 — Add status column
RG-488 — Add reports_count column
RG-489 — Add hidden filter
RG-490 — Add reported filter
RG-491 — Add hide comment action
RG-492 — Add restore comment action
RG-493 — Add delete comment action
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 27 начинается с `RG-494` и делает **Filament Reports Resource**. Поэтому Phase 26 не должна создавать ReportResource, Reports dashboard или report resolution UI.
---

# 2. Цель Phase 26

Phase 26 добавляет Filament-ресурс для просмотра и модерации комментариев в `/admin`.

После Phase 26 moderator/admin должен уметь в Filament:

```txt
- открыть список comments;
- видеть body excerpt;
- видеть author;
- видеть связанный post;
- видеть status;
- видеть reports_count;
- фильтровать hidden comments;
- фильтровать reported comments;
- hide visible comment;
- restore hidden comment;
- delete comment через безопасную action.
```

Это table-first moderation resource, а не полноценный редактор комментариев.
---

# 3. Scope Phase 26

## Входит

```txt
- CommentResource;
- ListComments page;
- body excerpt column;
- author column;
- post column;
- status column;
- reports_count column;
- hidden filter;
- reported filter;
- hide comment table action;
- restore comment table action;
- delete comment table action;
- minimal RestoreCommentAction backend if missing;
- safe delete behavior;
- tests/smoke tests for resource access and critical actions.
```

## Не входит

```txt
- ReportResource;
- resolve report action from CommentResource;
- hide reported target from ReportResource;
- ban target author from CommentResource;
- edit comment body form;
- create comment form;
- bulk hide comments;
- bulk delete comments;
- comment replies/nesting;
- comment analytics;
- public comments UI changes.
```

Phase 27 — Filament Reports Resource.  
Phase 29 — Moderation Dashboard.
---

# 4. Critical Decisions

## 4.1. CommentResource is moderation-first

В Phase 26 нельзя превращать CommentResource в редактор комментариев.

Правильный MVP:

```txt
- ListComments page;
- table columns;
- filters;
- moderation row actions.
```

Неправильно:

```txt
- edit comment body form;
- create comment form;
- rich text editor;
- relation managers;
- reports resolution inside comments table.
```

## 4.2. Filament actions must call backend actions

Нельзя в Filament action делать:

```php
$record->update(['status' => CommentStatus::Hidden]);
$record->delete();
```

Правильно:

```php
app(HideCommentAction::class)->handle(auth()->user(), $record, $reason);
app(RestoreCommentAction::class)->handle(auth()->user(), $record, $reason);
app(DeleteCommentAction::class)->handle(auth()->user(), $record);
```

Если нужного backend action нет, задача должна создать minimal action, а не обойти слой.

## 4.3. RestoreCommentAction was not created earlier

Phase 17 создала:

```txt
DeleteCommentAction
HideCommentAction
```

Но не создала:

```txt
RestoreCommentAction
```

Backlog Phase 26 требует:

```txt
RG-492 — Add restore comment action
```

Поэтому RG-492 должен включить:

```txt
- RestoreCommentAction backend;
- tests for backend restore;
- Filament table action.
```

Иначе получится прямой `update(status = visible)` в resource, что ломает архитектуру.

## 4.4. Delete comment action is dangerous

Backlog содержит:

```txt
RG-493 — Add delete comment action
```

Но это не значит hard delete.

Правило Phase 26:

```txt
Delete comment action:
- admin-only или owner/admin depending DeleteCommentAction policy;
- for Filament лучше admin-only;
- confirmation required;
- soft delete preferred;
- hard delete prohibited unless project explicitly decided it earlier;
- comments_count should update through backend action if action supports it.
```

Если `DeleteCommentAction` из Phase 17 рассчитан только на owner deletion, его нельзя слепо использовать для admin deletion без policy update.

В RG-493 нужно проверить:

```txt
- может ли admin удалить чужой comment через DeleteCommentAction?
```

Если нет, нужно либо:

```txt
- расширить CommentPolicy delete rule для admin;
```

либо:

```txt
- создать AdminDeleteCommentAction.
```

Рекомендация: расширить `CommentPolicy::delete()`:

```txt
owner can delete own comment
admin can delete any comment
moderator cannot delete unless отдельно разрешено
```

Hide/restore — для moderator/admin. Delete — admin-only.

## 4.5. reports_count column already should exist

Phase 19 требовала:

```txt
comments.reports_count
```

Если колонка отсутствует, это значит Phase 19 была реализована неправильно или неполно.

RG-488 не должен создавать reports_count column заново без проверки.  
Он может добавить migration только если проект реально не содержит поля и тест падает на schema.

## 4.6. Hidden filter

Hidden filter should mean:

```txt
status = hidden
```

If soft-deleted comments are excluded by default, hidden filter does not show deleted comments unless resource uses withTrashed.  
Do not include deleted comments in hidden filter unless explicitly needed.

## 4.7. Reported filter

Reported filter should mean:

```txt
reports_count > 0
```

Do not join reports table unless needed.

## 4.8. Comment status model

Expected statuses:

```txt
visible
hidden
```

Potential additional statuses:

```txt
deleted
pending
```

If comments use SoftDeletes, deleted is not a status.

Recommended Phase 26 behavior:

```txt
visible comments can be hidden;
hidden comments can be restored;
deleted comments are not listed unless withTrashed is explicitly added.
```

Do not introduce a `deleted` status if SoftDeletes already exists.

## 4.9. Moderation logs for comments

Phase 21 wrote logs from post and user actions, not comment actions.  
But comment moderation is still moderation.

Recommended:

```txt
- HideCommentAction should write moderation log if CreateModerationLogAction exists;
- RestoreCommentAction should write moderation log;
- admin delete comment should write moderation log if feasible.
```

If adding logs creates too much churn, minimally document it as a known gap.  
But best implementation: add `ModerationActionType::HideComment`, `RestoreComment`, `DeleteComment`.
---

# 5. Architecture Rules

## 5.1. Resource location

Adapt to installed Filament version and conventions from Phase 24/25.

Likely paths:

```txt
app/Filament/Resources/CommentResource.php
app/Filament/Resources/CommentResource/Pages/ListComments.php
```

## 5.2. Query must eager-load author and post

Avoid N+1:

```php
Comment::query()->with(['user', 'post'])
```

If using `getEloquentQuery()`:

```php
return parent::getEloquentQuery()->with(['user', 'post']);
```

## 5.3. No public UI changes

CommentResource is admin-only.  
Do not touch:

```txt
CommentsSection
CommentItem
CommentForm
PostDrawer
PostShow
```

unless required by failing shared components.

## 5.4. Actions visible only where valid

Visibility:

```txt
hide:
- visible for visible comments
- moderator/admin

restore:
- visible for hidden comments
- moderator/admin

delete:
- visible for admin only
- confirmation required
```

Even if hidden in UI, backend actions still enforce authorization.

## 5.5. No report resolution in CommentResource

Reported comments can be filtered, but resolving the actual report belongs to ReportResource Phase 27.

CommentResource can hide a reported comment, but it must not mark reports as resolved.
---

# 6. GitFlow для Phase 26

## Base branch

Все задачи Phase 26 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-483-create-comment-resource
feature/RG-491-add-hide-comment-action
feature/RG-493-add-delete-comment-action
```

## Commit format

```txt
RG-483: Create CommentResource
RG-491: Add hide comment action
RG-493: Add delete comment action
```

## Release branch

После выполнения `RG-483`–`RG-493`:

```txt
release/v0.2.7-phase26-filament-comments-resource
```

## Tag

После merge release branch в `main`:

```txt
v0.2.7-phase26-filament-comments-resource
```
---

# 7. TDD Rules for Phase 26

## Для Resource

Тестировать:

```txt
- admin can access CommentResource index;
- moderator can access CommentResource index;
- resource uses Comment model.
```

## Для columns

Тестировать:

```txt
- body excerpt visible;
- author visible;
- post title/link visible;
- status visible;
- reports_count visible.
```

## Для filters

Тестировать результат:

```txt
- hidden filter shows hidden comments only;
- reported filter shows comments with reports_count > 0 only.
```

## Для actions

Тестировать:

```txt
- moderator/admin can hide visible comment;
- moderator/admin can restore hidden comment;
- admin can delete comment;
- moderator cannot delete comment, if delete is admin-only;
- hidden/deleted state changes happen through backend actions;
- post comments_count updates where applicable;
- moderation logs are written if supported.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Comments / Tests
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

# 9. Phase 26 Atomic Tasks
---

## RG-483 — Create CommentResource

**Area:** Admin / Filament  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-483-create-comment-resource`  
**Base branch:** develop
**Depends on:** RG-482

### Goal

Создать Filament `CommentResource` для модели `Comment`.

### TDD step

Feature/smoke test:

```php
it('allows admin to access comment resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk();
});
```

Moderator access:

```php
it('allows moderator to access comment resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(CommentResource::getUrl('index'))
        ->assertOk();
});
```

Тест должен упасть до создания resource.

### Implementation

Создать resource:

```bash
php artisan make:filament-resource Comment
```

Минимальный resource:

```php
protected static ?string $model = Comment::class;
```

Pages:

```txt
ListComments
```

Set navigation group:

```txt
Moderation
```

Disable create/edit pages unless generated by default and required by Filament.  
Phase 26 does not need comment create/edit forms.

### Acceptance criteria

- `CommentResource` exists.
- Resource uses `Comment::class`.
- Admin can access index.
- Moderator can access index.
- Resource appears under Moderation navigation group.
- No create/edit form required.
- Tests pass.

### Definition of Done

- Тесты написаны.
- Resource создан.
- Tests pass.
- Коммит: `RG-483: Create CommentResource`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
app/Filament/Resources/CommentResource/Pages/ListComments.php
tests/Feature/Filament/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-484 — Add Body Excerpt Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-484-add-body-excerpt-column`  
**Base branch:** develop
**Depends on:** RG-483

### Goal

Добавить body excerpt column.

### TDD step

Feature test:

```php
it('renders comment body excerpt in comment resource table', function () {
    $admin = User::factory()->admin()->create();

    Comment::factory()->create([
        'body' => 'This comment should be visible as an excerpt in the admin table.',
    ]);

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk()
        ->assertSee('This comment should be visible');
});
```

### Implementation

Add column:

```php
TextColumn::make('body')
    ->label('Comment')
    ->limit(80)
    ->wrap()
    ->searchable()
```

Do not render raw HTML.  
Comment body must be escaped by default.

### Acceptance criteria

- Body excerpt visible.
- Long body limited.
- Searchable.
- HTML is not rendered as raw HTML.
- Test passes.

### Definition of Done

- Тест написан.
- Body excerpt column добавлен.
- Test passes.
- Коммит: `RG-484: Add body excerpt column`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
tests/Feature/Filament/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-485 — Add Author Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-485-add-author-column`  
**Base branch:** develop
**Depends on:** RG-484

### Goal

Добавить author column.

### TDD step

Feature test:

```php
it('renders comment author in comment resource table', function () {
    $admin = User::factory()->admin()->create();

    $author = User::factory()->create([
        'username' => 'comment_author',
    ]);

    Comment::factory()->for($author, 'user')->create();

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk()
        ->assertSee('comment_author');
});
```

### Implementation

Add eager loading:

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->with(['user', 'post']);
}
```

Add column:

```php
TextColumn::make('user.username')
    ->label('Author')
    ->searchable()
    ->sortable()
    ->placeholder('—')
```

If username nullable, use name/email fallback.

### Acceptance criteria

- Author visible.
- Uses username where available.
- Handles missing/deleted user safely.
- Avoids N+1 through eager loading.
- Test passes.

### Definition of Done

- Тест написан.
- Author column добавлен.
- Eager loading added.
- Test passes.
- Коммит: `RG-485: Add author column`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
tests/Feature/Filament/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-486 — Add Post Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-486-add-post-column`  
**Base branch:** develop
**Depends on:** RG-485

### Goal

Добавить post column, показывающую связанный пост.

### TDD step

Feature test:

```php
it('renders related post in comment resource table', function () {
    $admin = User::factory()->admin()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Pasta post',
    ]);

    Comment::factory()->for($post)->create();

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Pasta post');
});
```

### Implementation

Add column:

```php
TextColumn::make('post.title')
    ->label('Post')
    ->limit(50)
    ->searchable()
    ->sortable()
    ->placeholder('—')
```

Optional link to PostResource:

```php
->url(fn (Comment $record) => $record->post
    ? PostResource::getUrl('index', ['tableSearch' => $record->post->title])
    : null
)
```

Do not hardcode broken edit route if PostResource edit page does not exist.

### Acceptance criteria

- Related post title visible.
- Handles missing/deleted post safely.
- Does not hardcode broken route.
- Test passes.

### Definition of Done

- Тест написан.
- Post column добавлен.
- Test passes.
- Коммит: `RG-486: Add post column`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
tests/Feature/Filament/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-487 — Add Status Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-487-add-status-column`  
**Base branch:** develop
**Depends on:** RG-486

### Goal

Добавить status column.

### TDD step

Feature test:

```php
it('renders comment status in comment resource table', function () {
    $admin = User::factory()->admin()->create();

    Comment::factory()->create([
        'status' => CommentStatus::Hidden,
    ]);

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk()
        ->assertSee('hidden');
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
visible → success
hidden  → danger/gray
```

Adapt to actual `CommentStatus` enum/string.

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
- Коммит: `RG-487: Add status column`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
tests/Feature/Filament/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-488 — Add Reports_Count Column

**Area:** Admin / Filament / Table  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-488-add-reports-count-column`  
**Base branch:** develop
**Depends on:** RG-487

### Goal

Добавить reports_count column.

### TDD step

Feature test:

```php
it('renders comment reports count in comment resource table', function () {
    $admin = User::factory()->admin()->create();

    Comment::factory()->create([
        'body' => 'Reported comment',
        'reports_count' => 4,
    ]);

    $this->actingAs($admin)
        ->get(CommentResource::getUrl('index'))
        ->assertOk()
        ->assertSee('4');
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
    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
```

If `reports_count` column is missing, add migration only after confirming Phase 19 did not already add it:

```php
$table->unsignedInteger('reports_count')->default(0);
```

### Acceptance criteria

- reports_count visible.
- Sortable.
- Reported comments visually stand out.
- No duplicate migration if column exists.
- Test passes.

### Definition of Done

- Тест написан.
- reports_count column добавлен.
- Schema fixed only if necessary.
- Test passes.
- Коммит: `RG-488: Add reports_count column`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
database/migrations/*add_reports_count_to_comments_table.php
tests/Feature/Filament/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-489 — Add Hidden Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-489-add-hidden-filter`  
**Base branch:** develop
**Depends on:** RG-488

### Goal

Добавить hidden filter.

### TDD step

Filament filter test:

```php
it('filters hidden comments in comment resource', function () {
    $admin = User::factory()->admin()->create();

    $hidden = Comment::factory()->create([
        'body' => 'Hidden comment',
        'status' => CommentStatus::Hidden,
    ]);

    $visible = Comment::factory()->create([
        'body' => 'Visible comment',
        'status' => CommentStatus::Visible,
    ]);

    livewire(ListComments::class)
        ->actingAs($admin)
        ->filterTable('hidden')
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$visible]);
});
```

Adapt to installed Filament test helpers.

### Implementation

Add filter:

```php
Filter::make('hidden')
    ->label('Hidden')
    ->query(fn (Builder $query) => $query->where('status', CommentStatus::Hidden))
```

If status is string:

```php
->where('status', 'hidden')
```

### Acceptance criteria

- Hidden filter exists.
- Shows hidden comments.
- Hides visible comments.
- Does not show soft-deleted comments unless withTrashed is explicitly enabled.
- Test passes.

### Definition of Done

- Тест написан.
- Hidden filter добавлен.
- Test passes.
- Коммит: `RG-489: Add hidden filter`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
tests/Feature/Filament/CommentResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-490 — Add Reported Filter

**Area:** Admin / Filament / Filter  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-490-add-reported-filter`  
**Base branch:** develop
**Depends on:** RG-489

### Goal

Добавить reported filter.

### TDD step

Filament filter test:

```php
it('filters reported comments in comment resource', function () {
    $admin = User::factory()->admin()->create();

    $reported = Comment::factory()->create([
        'body' => 'Reported comment',
        'reports_count' => 2,
    ]);

    $clean = Comment::factory()->create([
        'body' => 'Clean comment',
        'reports_count' => 0,
    ]);

    livewire(ListComments::class)
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

Do not join reports table unless counter is missing or unreliable.

### Acceptance criteria

- Reported filter exists.
- Shows comments with reports_count > 0.
- Hides comments with reports_count = 0.
- Hidden filter still works.
- Test passes.

### Definition of Done

- Тест написан.
- Reported filter добавлен.
- Test passes.
- Коммит: `RG-490: Add reported filter`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
tests/Feature/Filament/CommentResourceFiltersTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-491 — Add Hide Comment Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-491-add-hide-comment-action`  
**Base branch:** develop
**Depends on:** RG-490

### Goal

Добавить hide comment table action.

### TDD step

Filament action test:

```php
it('hides visible comment from comment resource table action', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
        'body' => 'Bad comment',
    ]);

    livewire(ListComments::class)
        ->actingAs($moderator)
        ->callTableAction('hide', $comment, data: [
            'reason' => 'Abusive language.',
        ]);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});
```

Visibility test:

```php
it('shows hide action only for visible comments', ...)
```

### Implementation

Use existing backend action from Phase 17:

```php
HideCommentAction
```

Add table action:

```php
Action::make('hide')
    ->label('Hide')
    ->icon('heroicon-o-eye-slash')
    ->color('danger')
    ->visible(fn (Comment $record): bool =>
        $record->status === CommentStatus::Visible
        && (auth()->user()?->isModerator() || auth()->user()?->isAdmin())
    )
    ->form([
        Textarea::make('reason')
            ->label('Reason')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Comment $record, array $data): void {
        app(HideCommentAction::class)->handle(
            auth()->user(),
            $record,
            $data['reason'] ?? null,
        );
    })
```

If `HideCommentAction::handle()` does not accept reason, either:

```txt
- extend it to accept optional reason without breaking existing calls;
```

or:

```txt
- do not pass reason and document gap.
```

Recommended: extend to `handle(User $moderator, Comment $comment, ?string $reason = null): void`.

### Acceptance criteria

- Hide action visible for visible comments.
- Hidden for already hidden comments.
- Moderator/admin can hide.
- Calls HideCommentAction.
- Status becomes hidden.
- comments_count updates through backend action if applicable.
- Test passes.

### Definition of Done

- Тест написан.
- Hide action добавлен.
- Backend action used.
- Test passes.
- Коммит: `RG-491: Add hide comment action`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
app/Actions/Comments/HideCommentAction.php
tests/Feature/Filament/CommentResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-492 — Add Restore Comment Action

**Area:** Admin / Filament / Action / Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-492-add-restore-comment-action`  
**Base branch:** develop
**Depends on:** RG-491

### Goal

Добавить restore comment table action и backend `RestoreCommentAction`, если его ещё нет.

### TDD step

Backend action test first:

```php
it('allows moderator to restore hidden comment', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Hidden,
    ]);

    app(RestoreCommentAction::class)->handle(
        moderator: $moderator,
        comment: $comment,
        reason: 'Restored after review.'
    );

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
});
```

Filament action test:

```php
it('restores hidden comment from comment resource table action', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Hidden,
    ]);

    livewire(ListComments::class)
        ->actingAs($moderator)
        ->callTableAction('restore', $comment, data: [
            'reason' => 'Restored after review.',
        ]);

    expect($comment->fresh()->status)->toBe(CommentStatus::Visible);
});
```

Visibility test:

```php
it('shows restore action only for hidden comments', ...)
```

### Implementation

Create backend action if missing:

```txt
app/Actions/Comments/RestoreCommentAction.php
app/Exceptions/Comments/CannotRestoreCommentException.php
```

Action:

```php
final class RestoreCommentAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $moderator, Comment $comment, ?string $reason = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotRestoreCommentException::becauseUserIsNotAllowed();
        }

        if ($comment->status !== CommentStatus::Hidden) {
            throw CannotRestoreCommentException::becauseCommentStatusIsInvalid();
        }

        DB::transaction(function () use ($moderator, $comment, $reason) {
            $post = $comment->post;

            $comment->forceFill([
                'status' => CommentStatus::Visible,
            ])->save();

            $this->refreshCommentsCount($post);

            $this->createModerationLog->handle(
                moderator: $moderator,
                action: ModerationActionType::RestoreComment,
                target: $comment,
                reason: $reason,
                metadata: [
                    'from_status' => CommentStatus::Hidden->value,
                    'to_status' => CommentStatus::Visible->value,
                ],
            );
        });
    }
}
```

Add enum value:

```txt
ModerationActionType::RestoreComment
```

Filament action:

```php
Action::make('restore')
    ->label('Restore')
    ->color('success')
    ->visible(fn (Comment $record): bool =>
        $record->status === CommentStatus::Hidden
        && (auth()->user()?->isModerator() || auth()->user()?->isAdmin())
    )
    ->form([
        Textarea::make('reason')->label('Reason')->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(fn (Comment $record, array $data) =>
        app(RestoreCommentAction::class)->handle(auth()->user(), $record, $data['reason'] ?? null)
    )
```

### Acceptance criteria

- RestoreCommentAction exists if missing.
- Moderator/admin can restore hidden comment.
- Normal user cannot restore.
- Restore action visible only for hidden comments.
- Calls RestoreCommentAction.
- Status becomes visible.
- comments_count updates.
- Moderation log written if moderation logging is available.
- Test passes.

### Definition of Done

- Backend action/test written.
- Filament action/test written.
- No direct status mutation in resource.
- Test passes.
- Коммит: `RG-492: Add restore comment action`

### Files likely touched

```txt
app/Actions/Comments/RestoreCommentAction.php
app/Exceptions/Comments/CannotRestoreCommentException.php
app/Enums/ModerationActionType.php
app/Filament/Resources/CommentResource.php
tests/Feature/Actions/RestoreCommentActionTest.php
tests/Feature/Filament/CommentResourceActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-493 — Add Delete Comment Action

**Area:** Admin / Filament / Action  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-493-add-delete-comment-action`  
**Base branch:** develop
**Depends on:** RG-492

### Goal

Добавить безопасную delete comment table action.

### TDD step

Admin delete test:

```php
it('allows admin to delete comment from comment resource table action', function () {
    $admin = User::factory()->admin()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    livewire(ListComments::class)
        ->actingAs($admin)
        ->callTableAction('delete', $comment);

    $this->assertSoftDeleted('comments', ['id' => $comment->id]);
});
```

Moderator blocked/hidden test:

```php
it('does not show delete action to moderator', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create();

    livewire(ListComments::class)
        ->actingAs($moderator)
        ->assertTableActionHidden('delete', $comment);
});
```

If SoftDeletes are not available, this test should force the implementation decision.

### Implementation

Preferred implementation:

```txt
- ensure Comment model uses SoftDeletes;
- ensure comments table has deleted_at;
- allow admin delete through CommentPolicy or dedicated action;
- require confirmation.
```

If existing `DeleteCommentAction` from Phase 17 only allows owner deletion, update policy:

```php
public function delete(User $user, Comment $comment): bool
{
    return $comment->user_id === $user->id || $user->isAdmin();
}
```

Then Filament action can call:

```php
app(DeleteCommentAction::class)->handle(auth()->user(), $record);
```

Filament action:

```php
Action::make('delete')
    ->label('Delete')
    ->icon('heroicon-o-trash')
    ->color('danger')
    ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
    ->requiresConfirmation()
    ->action(function (Comment $record): void {
        app(DeleteCommentAction::class)->handle(auth()->user(), $record);
    })
```

Do not use Filament built-in `DeleteAction` if it bypasses:

```txt
- DeleteCommentAction;
- comments_count update;
- authorization policy;
- moderation/audit logic.
```

If adding moderation log for delete:

```txt
ModerationActionType::DeleteComment
```

and write log inside DeleteCommentAction or AdminDeleteCommentAction.

### Acceptance criteria

- Delete action visible only to admin.
- Hidden from moderator.
- Requires confirmation.
- Calls DeleteCommentAction or dedicated backend action.
- Uses soft delete.
- comments_count updates.
- Hard delete is not used.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Test written.
- Delete action added safely.
- Backend action/policy adjusted if needed.
- Tests/build pass.
- Коммит: `RG-493: Add delete comment action`

### Files likely touched

```txt
app/Filament/Resources/CommentResource.php
app/Actions/Comments/DeleteCommentAction.php
app/Policies/CommentPolicy.php
app/Models/Comment.php
database/migrations/*add_deleted_at_to_comments_table.php
tests/Feature/Filament/CommentResourceActionsTest.php
tests/Feature/Actions/DeleteCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 26 Completion Criteria

Phase 26 завершена, когда:

```txt
- RG-483–RG-493 выполнены;
- CommentResource exists;
- admin can access CommentResource;
- moderator can access CommentResource;
- table shows body excerpt;
- table shows author;
- table shows related post;
- table shows status;
- table shows reports_count;
- hidden filter works;
- reported filter works;
- hide action works via HideCommentAction;
- restore action works via RestoreCommentAction;
- delete action works through safe backend action;
- delete is admin-only;
- no hard delete is used unless explicitly decided earlier;
- comments_count remains correct after hide/restore/delete;
- no ReportResource was created;
- no report resolution UI was added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 26

Без отдельной задачи нельзя:

```txt
- создавать ReportResource;
- создавать report resolution UI;
- делать hide target action from report;
- делать ban target author action from comment;
- создавать comment edit form;
- создавать comment create form;
- редактировать body через admin;
- добавлять bulk hide/delete comments;
- добавлять comment analytics;
- менять public comments UI;
- добавлять dashboard widgets;
- добавлять queue jobs;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-483 Create CommentResource
RG-484 Add body excerpt column
RG-485 Add author column
RG-486 Add post column
RG-487 Add status column
RG-488 Add reports_count column
RG-489 Add hidden filter
RG-490 Add reported filter
RG-491 Add hide comment action
RG-492 Add restore comment action
RG-493 Add delete comment action
```
---

# 13. Release

После завершения Phase 26:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.7-phase26-filament-comments-resource
git push -u origin release/v0.2.7-phase26-filament-comments-resource
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.7-phase26-filament-comments-resource -m "RateGuru Phase 26 Filament Comments Resource"
git push origin v0.2.7-phase26-filament-comments-resource
```
