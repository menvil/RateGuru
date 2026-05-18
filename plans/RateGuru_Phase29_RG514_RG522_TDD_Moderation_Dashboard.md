# RateGuru — Phase 29 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 29 — Moderation Dashboard**  
Диапазон задач: **RG-514 → RG-522**  
Основа нумерации: исходный atomic backlog, где Phase 29 начинается с задачи 514 и заканчивается задачей 522.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 29 соответствует исходному блоку:

```txt
Phase 29 — Moderation Dashboard
```

Правильный диапазон Phase 29:

```txt
RG-514 — Create ModerationDashboard Filament page
RG-515 — Add pending posts widget
RG-516 — Add reported posts widget
RG-517 — Add reported comments widget
RG-518 — Add suspicious users widget
RG-519 — Add latest reports table
RG-520 — Add quick approve action to dashboard
RG-521 — Add quick hide action to dashboard
RG-522 — Add quick resolve report action to dashboard
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 30 начинается с `RG-523` и делает **Profile Page**. Поэтому Phase 29 не должна захватывать публичные профили, notification foundation, hot score, rate limiting или новые moderation backend rules.
---

# 2. Цель Phase 29

Phase 29 добавляет отдельный Filament dashboard для модераторов и админов.

После Phase 29 moderator/admin должен видеть в `/admin` быстрый обзор:

```txt
- сколько pending posts ждёт review;
- сколько reported posts требует внимания;
- сколько reported comments требует внимания;
- сколько suspicious users найдено по текущим эвристикам;
- последние reports;
- быстрые действия:
  - approve post;
  - hide post/comment target;
  - resolve report.
```

Главная цель: уменьшить время до модераторского действия. Это не новая система модерации, а operational dashboard поверх уже созданных ресурсов и backend actions.
---

# 3. Scope Phase 29

## Входит

```txt
- ModerationDashboard Filament page;
- pending posts widget;
- reported posts widget;
- reported comments widget;
- suspicious users widget;
- latest reports table;
- quick approve action;
- quick hide action;
- quick resolve report action;
- dashboard navigation entry;
- tests/smoke tests for access, widgets and quick actions.
```

## Не входит

```txt
- новые moderation statuses;
- новые report statuses, кроме уже созданных open/resolved/ignored;
- новый anti-fraud scoring;
- новый user reports aggregate column;
- bulk moderation dashboard actions;
- notification dispatching;
- AI moderation;
- public profile page;
- public moderation UI;
- API endpoints.
```

Phase 34 будет заниматься rate limiting / abuse guards.  
Phase 31 будет заниматься notifications.  
Phase 30 будет заниматься profile page.
---

# 4. Critical Decisions

## 4.1. Dashboard не должен дублировать resources

Phase 24–28 уже создали:

```txt
PostResource
UserResource
CommentResource
ReportResource
TagResource
```

Dashboard должен быть обзорным слоем:

```txt
- counters;
- latest reports;
- quick actions;
- links to resources.
```

Нельзя превращать ModerationDashboard в ещё один огромный Resource.

## 4.2. Quick actions must call backend actions

Quick actions не должны делать прямые мутации:

```php
$post->update(['status' => PostStatus::Published]);
$report->update(['status' => ReportStatus::Resolved]);
```

Правильно:

```php
app(ApprovePostAction::class)->handle($moderator, $post, $reason);
app(HidePostAction::class)->handle($moderator, $post, $reason);
app(HideCommentAction::class)->handle($moderator, $comment, $reason);
app(ResolveReportAction::class)->handle($moderator, $report, $note);
```

Причина:

```txt
- backend actions уже содержат guards;
- backend actions пишут moderation logs;
- backend actions поддерживают consistency.
```

## 4.3. Widgets должны считать из source-of-truth

Widgets не должны использовать произвольные cached values, если их нет.

Source of truth:

```txt
pending posts widget:
posts.status = pending

reported posts widget:
posts.reports_count > 0 OR posts.needs_review = true, если поле есть

reported comments widget:
comments.reports_count > 0

suspicious users widget:
детерминированная эвристика из существующих данных, без новой ML/abuse system
```

## 4.4. Suspicious users definition

Нельзя выдумывать сложный антифрод в Phase 29.

MVP definition:

```txt
Suspicious users =
active/trusted/shadowbanned users who authored reported content
OR users already shadowbanned.
```

Практическая query-эвристика:

```txt
users where:
- status = shadowbanned
OR exists posts with reports_count > 0
OR exists comments with reports_count > 0
```

Если `comments.reports_count` отсутствует — это ошибка Phase 19/26, не повод делать новый агрегат.

Не добавлять `users.reports_count` column в Phase 29.  
Phase 25 специально оставила `reports_count placeholder`.

## 4.5. Latest reports table

Latest reports table должна показывать:

```txt
- report id;
- target type;
- reason;
- reporter;
- status;
- created_at;
- quick actions.
```

Она может быть embedded table widget или custom dashboard table.  
Если Filament table widgets уже используются — лучше `LatestReportsTable` widget.

## 4.6. Quick approve action

Quick approve относится только к:

```txt
pending post
```

Не approve reported/hidden/rejected posts.

Action source:

```txt
ApprovePostAction
```

## 4.7. Quick hide action

Quick hide может применяться к:

```txt
reported post target
reported comment target
```

Rules:

```txt
report target post + post is published → HidePostAction
report target comment + comment is visible → HideCommentAction
invalid/missing/already hidden target → action hidden/disabled
```

Quick hide не должен автоматически resolve report, если backlog этого не говорит.  
Может после hide показать “resolve report separately”.

## 4.8. Quick resolve report action

Quick resolve только закрывает report:

```txt
ReportStatus::Open → ReportStatus::Resolved
```

Source:

```txt
ResolveReportAction
```

Он не должен:

```txt
- hide target;
- ban author;
- change post/comment status.
```

Это separate quick actions.

## 4.9. Admin vs moderator access

ModerationDashboard доступен:

```txt
admin
moderator
```

Normal user / guest не имеют доступа через Phase 23 panel guards.

Quick ban user не входит в Phase 29.  
User sanctions остаются в UserResource / ReportResource.
---

# 5. Architecture Rules

## 5.1. Page location

Use Filament conventions from Phase 23+.

Likely path:

```txt
app/Filament/Pages/ModerationDashboard.php
```

Route slug:

```txt
moderation-dashboard
```

Navigation group:

```txt
Moderation
```

Navigation label:

```txt
Moderation Dashboard
```

## 5.2. Widgets location

Likely paths:

```txt
app/Filament/Widgets/PendingPostsWidget.php
app/Filament/Widgets/ReportedPostsWidget.php
app/Filament/Widgets/ReportedCommentsWidget.php
app/Filament/Widgets/SuspiciousUsersWidget.php
app/Filament/Widgets/LatestReportsTable.php
```

If project uses per-page widgets:

```txt
app/Filament/Pages/ModerationDashboard/Widgets/...
```

Use existing project convention.

## 5.3. No new database schema unless strictly required

Phase 29 should not add migrations.

If a widget needs missing fields:

```txt
- reports_count on comments;
- needs_review on posts;
- report statuses;
```

that means prior phase incomplete. Do not patch silently unless tests prove schema missing and the correction belongs here.

## 5.4. Query performance

Dashboard queries must be cheap:

```txt
COUNT(*) on indexed status/reports_count fields;
latest reports limited to 10/20;
eager-load reporter/reportable;
no unbounded joins.
```

Do not load full image/comment bodies unnecessarily.

## 5.5. No hardcoded broken resource links

Link to resources via Filament helpers where possible:

```php
PostResource::getUrl('index', ...)
ReportResource::getUrl('index', ...)
UserResource::getUrl('index', ...)
CommentResource::getUrl('index', ...)
```

Do not hardcode `/admin/posts/123/edit` if edit page does not exist.
---

# 6. GitFlow для Phase 29

## Base branch

Все задачи Phase 29 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-514-create-moderation-dashboard-filament-page
feature/RG-519-add-latest-reports-table
feature/RG-522-add-quick-resolve-report-action-to-dashboard
```

## Commit format

```txt
RG-514: Create ModerationDashboard Filament page
RG-519: Add latest reports table
RG-522: Add quick resolve report action to dashboard
```

## Release branch

После выполнения `RG-514`–`RG-522`:

```txt
release/v0.2.10-phase29-moderation-dashboard
```

## Tag

После merge release branch в `main`:

```txt
v0.2.10-phase29-moderation-dashboard
```
---

# 7. TDD Rules for Phase 29

## Для dashboard page

Тестировать:

```txt
- admin can access ModerationDashboard;
- moderator can access ModerationDashboard;
- normal user access remains blocked by Filament panel guard;
- navigation label exists if testable.
```

## Для widgets

Тестировать count logic:

```txt
- pending posts widget counts pending posts;
- reported posts widget counts reported posts;
- reported comments widget counts reported comments;
- suspicious users widget counts users matching deterministic heuristic.
```

## Для latest reports table

Тестировать:

```txt
- latest reports table shows recent open reports;
- reporter visible;
- reason visible;
- target type visible;
- sorted newest first;
- limited to reasonable number.
```

## Для quick actions

Тестировать через Filament widget/table actions if feasible:

```txt
- quick approve changes pending post to published through ApprovePostAction;
- quick hide hides post/comment target through HidePostAction/HideCommentAction;
- quick resolve changes report to resolved through ResolveReportAction;
- logs are written by backend actions where applicable.
```

Если Filament widget action tests are brittle, create tests around widget action methods and database result. But do not skip action behavior entirely.
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Admin / Filament / Dashboard / Tests
Type: Test / Feature / Page / Widget / Table / Action
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
- Dashboard action не мутирует model напрямую
- Backend action используется для state-changing actions
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 29 Atomic Tasks
---

## RG-514 — Create ModerationDashboard Filament Page

**Area:** Admin / Filament / Dashboard  
**Type:** Page  
**Priority:** P0  
**Branch:** `feature/RG-514-create-moderation-dashboard-filament-page`  
**Base branch:** develop
**Depends on:** RG-513

### Goal

Создать Filament page `ModerationDashboard`.

### TDD step

Feature/smoke test:

```php
it('allows admin to access moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk();
});
```

Moderator access test:

```php
it('allows moderator to access moderation dashboard', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(ModerationDashboard::getUrl())
        ->assertOk();
});
```

Тест должен упасть до создания page.

### Implementation

Создать page:

```bash
php artisan make:filament-page ModerationDashboard
```

Likely file:

```txt
app/Filament/Pages/ModerationDashboard.php
resources/views/filament/pages/moderation-dashboard.blade.php
```

Page config:

```php
protected static ?string $navigationGroup = 'Moderation';
protected static ?string $navigationLabel = 'Moderation Dashboard';
protected static ?string $title = 'Moderation Dashboard';
protected static ?string $slug = 'moderation-dashboard';
```

Render skeleton:

```blade
<x-filament-panels::page>
    <div data-testid="moderation-dashboard">
        Moderation Dashboard
    </div>
</x-filament-panels::page>
```

No widgets yet.

### Acceptance criteria

- `ModerationDashboard` page exists.
- Admin can access.
- Moderator can access.
- Page appears under Moderation navigation group.
- Page has stable title/slug.
- View has `data-testid="moderation-dashboard"`.
- Tests pass.

### Definition of Done

- Tests written.
- Filament page created.
- Tests pass.
- Коммит: `RG-514: Create ModerationDashboard Filament page`

### Files likely touched

```txt
app/Filament/Pages/ModerationDashboard.php
resources/views/filament/pages/moderation-dashboard.blade.php
tests/Feature/Filament/ModerationDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-515 — Add Pending Posts Widget

**Area:** Admin / Filament / Dashboard Widget  
**Type:** Widget  
**Priority:** P0  
**Branch:** `feature/RG-515-add-pending-posts-widget`  
**Base branch:** develop
**Depends on:** RG-514

### Goal

Добавить widget, показывающий количество pending posts.

### TDD step

Widget/count test:

```php
it('shows pending posts count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->pending()->count(3)->create();
    Post::factory()->published()->count(2)->create();

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Pending posts')
        ->assertSee('3');
});
```

### Implementation

Create widget:

```bash
php artisan make:filament-widget PendingPostsWidget --stats-overview
```

or manually create:

```txt
app/Filament/Widgets/PendingPostsWidget.php
```

Widget stat:

```php
Stat::make('Pending posts', Post::query()
    ->where('status', PostStatus::Pending)
    ->count()
)
    ->description('Posts waiting for review')
    ->url(PostResource::getUrl('index', ['tableFilters' => ['pending' => ['isActive' => true]]]));
```

Register on `ModerationDashboard`:

```php
protected function getHeaderWidgets(): array
{
    return [
        PendingPostsWidget::class,
    ];
}
```

If using page widgets, adapt to Filament version.

### Acceptance criteria

- Widget appears on ModerationDashboard.
- Counts only pending posts.
- Does not count published/hidden/rejected posts.
- Links to PostResource pending filter if possible.
- Test passes.

### Definition of Done

- Test written.
- Widget added.
- Widget registered on page.
- Test passes.
- Коммит: `RG-515: Add pending posts widget`

### Files likely touched

```txt
app/Filament/Pages/ModerationDashboard.php
app/Filament/Widgets/PendingPostsWidget.php
tests/Feature/Filament/ModerationDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-516 — Add Reported Posts Widget

**Area:** Admin / Filament / Dashboard Widget  
**Type:** Widget  
**Priority:** P0  
**Branch:** `feature/RG-516-add-reported-posts-widget`  
**Base branch:** develop
**Depends on:** RG-515

### Goal

Добавить widget, показывающий количество reported posts.

### TDD step

Widget/count test:

```php
it('shows reported posts count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Post::factory()->published()->create([
        'reports_count' => 2,
    ]);

    Post::factory()->published()->create([
        'reports_count' => 0,
    ]);

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Reported posts')
        ->assertSee('1');
});
```

If `needs_review` exists and should count, add:

```php
Post::factory()->published()->create(['reports_count' => 0, 'needs_review' => true]);
```

### Implementation

Create widget:

```txt
app/Filament/Widgets/ReportedPostsWidget.php
```

Query:

```php
$count = Post::query()
    ->where(function (Builder $query): void {
        $query->where('reports_count', '>', 0);

        if (Schema::hasColumn('posts', 'needs_review')) {
            $query->orWhere('needs_review', true);
        }
    })
    ->count();
```

If you want to avoid `Schema::hasColumn` inside hot path, choose one query according to actual schema. Recommended if `needs_review` exists from Phase 19:

```php
->where(fn ($query) => $query
    ->where('reports_count', '>', 0)
    ->orWhere('needs_review', true)
)
```

Register widget on dashboard.

### Acceptance criteria

- Widget appears on ModerationDashboard.
- Counts posts with reports_count > 0.
- If needs_review exists, includes those posts.
- Does not count clean posts.
- Links to PostResource reported filter if possible.
- Test passes.

### Definition of Done

- Test written.
- Widget added.
- Widget registered.
- Test passes.
- Коммит: `RG-516: Add reported posts widget`

### Files likely touched

```txt
app/Filament/Pages/ModerationDashboard.php
app/Filament/Widgets/ReportedPostsWidget.php
tests/Feature/Filament/ModerationDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-517 — Add Reported Comments Widget

**Area:** Admin / Filament / Dashboard Widget  
**Type:** Widget  
**Priority:** P0  
**Branch:** `feature/RG-517-add-reported-comments-widget`  
**Base branch:** develop
**Depends on:** RG-516

### Goal

Добавить widget, показывающий количество reported comments.

### TDD step

Widget/count test:

```php
it('shows reported comments count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    Comment::factory()->create([
        'reports_count' => 3,
        'status' => CommentStatus::Visible,
    ]);

    Comment::factory()->create([
        'reports_count' => 0,
        'status' => CommentStatus::Visible,
    ]);

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Reported comments')
        ->assertSee('1');
});
```

### Implementation

Create widget:

```txt
app/Filament/Widgets/ReportedCommentsWidget.php
```

Query:

```php
$count = Comment::query()
    ->where('reports_count', '>', 0)
    ->count();
```

If soft-deleted comments should be excluded, default Eloquent query already excludes them.  
Do not add `withTrashed()`.

Link to CommentResource reported filter if possible.

### Acceptance criteria

- Widget appears on ModerationDashboard.
- Counts comments with reports_count > 0.
- Does not count clean comments.
- Excludes soft-deleted comments by default.
- Links to CommentResource reported filter if possible.
- Test passes.

### Definition of Done

- Test written.
- Widget added.
- Widget registered.
- Test passes.
- Коммит: `RG-517: Add reported comments widget`

### Files likely touched

```txt
app/Filament/Pages/ModerationDashboard.php
app/Filament/Widgets/ReportedCommentsWidget.php
tests/Feature/Filament/ModerationDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-518 — Add Suspicious Users Widget

**Area:** Admin / Filament / Dashboard Widget  
**Type:** Widget  
**Priority:** P0  
**Branch:** `feature/RG-518-add-suspicious-users-widget`  
**Base branch:** develop
**Depends on:** RG-517

### Goal

Добавить widget, показывающий количество suspicious users по простой детерминированной эвристике.

### TDD step

Widget/count test:

```php
it('shows suspicious users count on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    $reportedPostAuthor = User::factory()->create();
    Post::factory()
        ->for($reportedPostAuthor)
        ->published()
        ->create(['reports_count' => 2]);

    $reportedCommentAuthor = User::factory()->create();
    Comment::factory()
        ->for($reportedCommentAuthor, 'user')
        ->create(['reports_count' => 1]);

    User::factory()->create([
        'status' => UserStatus::Shadowbanned,
    ]);

    User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Suspicious users')
        ->assertSee('3');
});
```

This test intentionally avoids a `users.reports_count` field.

### Implementation

Create widget:

```txt
app/Filament/Widgets/SuspiciousUsersWidget.php
```

Query:

```php
$count = User::query()
    ->where(function (Builder $query): void {
        $query
            ->where('status', UserStatus::Shadowbanned)
            ->orWhereHas('posts', fn (Builder $posts) =>
                $posts->where('reports_count', '>', 0)
            )
            ->orWhereHas('comments', fn (Builder $comments) =>
                $comments->where('reports_count', '>', 0)
            );
    })
    ->count();
```

If `User::comments()` relation does not exist, add it:

```php
public function comments(): HasMany
{
    return $this->hasMany(Comment::class);
}
```

Do not add `users.reports_count`.

Link to UserResource with no exact filter unless a suspicious filter exists. You can link to UserResource index.

### Acceptance criteria

- Widget appears on ModerationDashboard.
- Counts shadowbanned users.
- Counts users who authored reported posts.
- Counts users who authored reported comments.
- Does not require `users.reports_count`.
- Does not count clean active users.
- Test passes.

### Definition of Done

- Test written.
- Widget added.
- User comments relation added if missing.
- Widget registered.
- Test passes.
- Коммит: `RG-518: Add suspicious users widget`

### Files likely touched

```txt
app/Filament/Pages/ModerationDashboard.php
app/Filament/Widgets/SuspiciousUsersWidget.php
app/Models/User.php
tests/Feature/Filament/ModerationDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-519 — Add Latest Reports Table

**Area:** Admin / Filament / Dashboard Table  
**Type:** Table / Widget  
**Priority:** P0  
**Branch:** `feature/RG-519-add-latest-reports-table`  
**Base branch:** develop
**Depends on:** RG-518

### Goal

Добавить latest reports table на ModerationDashboard.

### TDD step

Dashboard/table test:

```php
it('shows latest reports on moderation dashboard', function () {
    $admin = User::factory()->admin()->create();

    $oldReport = Report::factory()->create([
        'reason' => ReportReason::Spam,
        'created_at' => now()->subDays(2),
    ]);

    $newReport = Report::factory()->create([
        'reason' => ReportReason::Harassment,
        'created_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(ModerationDashboard::getUrl())
        ->assertOk()
        ->assertSee('Latest reports')
        ->assertSee('Harassment')
        ->assertSee('Spam');
});
```

If order is hard to assert with raw HTML, use Filament table test helpers.

### Implementation

Create table widget:

```bash
php artisan make:filament-widget LatestReportsTable --table
```

Likely file:

```txt
app/Filament/Widgets/LatestReportsTable.php
```

Query:

```php
protected function getTableQuery(): Builder
{
    return Report::query()
        ->with(['user', 'reportable'])
        ->latest()
        ->limit(10);
}
```

Columns:

```txt
target type
reason
reporter
status
created_at
```

Use safe target type formatting:

```php
match ($record->reportable_type) {
    Post::class => 'Post',
    Comment::class => 'Comment',
    default => 'Unknown',
}
```

Register widget in dashboard footer/body widgets.

### Acceptance criteria

- Latest reports table appears.
- Shows latest reports sorted newest first.
- Shows reason.
- Shows reporter.
- Shows target type.
- Shows status.
- Shows created_at.
- Query is limited to a reasonable count.
- Handles missing reportable safely.
- Test passes.

### Definition of Done

- Test written.
- LatestReportsTable widget added.
- Widget registered.
- Test passes.
- Коммит: `RG-519: Add latest reports table`

### Files likely touched

```txt
app/Filament/Pages/ModerationDashboard.php
app/Filament/Widgets/LatestReportsTable.php
tests/Feature/Filament/ModerationDashboardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-520 — Add Quick Approve Action To Dashboard

**Area:** Admin / Filament / Dashboard Action  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-520-add-quick-approve-action-to-dashboard`  
**Base branch:** develop
**Depends on:** RG-519

### Goal

Добавить quick approve action для pending post из dashboard.

### TDD step

Action test:

```php
it('quick approves pending post from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->pending()->create();

    $report = Report::factory()
        ->for($post, 'reportable')
        ->create([
            'status' => ReportStatus::Open,
        ]);

    livewire(LatestReportsTable::class)
        ->actingAs($moderator)
        ->callTableAction('approvePost', $report, data: [
            'reason' => 'Approved from dashboard.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Published);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'moderatable_type' => Post::class,
        'moderatable_id' => $post->id,
    ]);
});
```

Visibility test:

```php
quick approve is visible only when report target is pending post
```

### Implementation

Add table action to `LatestReportsTable`:

```php
Action::make('approvePost')
    ->label('Approve post')
    ->visible(fn (Report $record): bool =>
        $record->reportable_type === Post::class
        && $record->reportable instanceof Post
        && $record->reportable->status === PostStatus::Pending
    )
    ->form([
        Textarea::make('reason')
            ->label('Reason')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Report $record, array $data): void {
        $post = $record->reportable;

        if (! $post instanceof Post) {
            return;
        }

        app(ApprovePostAction::class)->handle(
            auth()->user(),
            $post,
            $data['reason'] ?? null,
        );
    })
```

Do not resolve report automatically. That is RG-522.

### Acceptance criteria

- Quick approve action exists on latest reports table.
- Visible only for pending post target.
- Calls ApprovePostAction.
- Pending post becomes published.
- Moderation log written.
- Does not automatically resolve report.
- Test passes.

### Definition of Done

- Test written.
- Quick approve action added.
- Backend action used.
- Test passes.
- Коммит: `RG-520: Add quick approve action to dashboard`

### Files likely touched

```txt
app/Filament/Widgets/LatestReportsTable.php
tests/Feature/Filament/ModerationDashboardActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-521 — Add Quick Hide Action To Dashboard

**Area:** Admin / Filament / Dashboard Action  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-521-add-quick-hide-action-to-dashboard`  
**Base branch:** develop
**Depends on:** RG-520

### Goal

Добавить quick hide action для reported post/comment target из dashboard.

### TDD step

Post target test:

```php
it('quick hides reported post from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->published()->create();

    $report = Report::factory()
        ->for($post, 'reportable')
        ->create([
            'status' => ReportStatus::Open,
        ]);

    livewire(LatestReportsTable::class)
        ->actingAs($moderator)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Hidden from dashboard.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});
```

Comment target test:

```php
it('quick hides reported comment from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $report = Report::factory()
        ->for($comment, 'reportable')
        ->create([
            'status' => ReportStatus::Open,
        ]);

    livewire(LatestReportsTable::class)
        ->actingAs($moderator)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Hidden from dashboard.',
        ]);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});
```

Visibility test:

```txt
hide target hidden for already hidden targets or missing reportable
```

### Implementation

Add table action:

```php
Action::make('hideTarget')
    ->label('Hide target')
    ->color('danger')
    ->visible(function (Report $record): bool {
        $target = $record->reportable;

        return ($target instanceof Post && $target->status === PostStatus::Published)
            || ($target instanceof Comment && $target->status === CommentStatus::Visible);
    })
    ->form([
        Textarea::make('reason')
            ->label('Reason')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Report $record, array $data): void {
        $target = $record->reportable;
        $reason = $data['reason'] ?? null;

        if ($target instanceof Post) {
            app(HidePostAction::class)->handle(auth()->user(), $target, $reason);
            return;
        }

        if ($target instanceof Comment) {
            app(HideCommentAction::class)->handle(auth()->user(), $target, $reason);
            return;
        }
    })
```

Do not resolve report automatically.

### Acceptance criteria

- Quick hide action exists.
- Visible for published post target.
- Visible for visible comment target.
- Hidden for missing/already hidden invalid target.
- Calls HidePostAction for posts.
- Calls HideCommentAction for comments.
- Does not directly mutate target.
- Does not automatically resolve report.
- Test passes.

### Definition of Done

- Tests written.
- Quick hide action added.
- Backend actions used.
- Tests pass.
- Коммит: `RG-521: Add quick hide action to dashboard`

### Files likely touched

```txt
app/Filament/Widgets/LatestReportsTable.php
tests/Feature/Filament/ModerationDashboardActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-522 — Add Quick Resolve Report Action To Dashboard

**Area:** Admin / Filament / Dashboard Action  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-522-add-quick-resolve-report-action-to-dashboard`  
**Base branch:** develop
**Depends on:** RG-521

### Goal

Добавить quick resolve action для open report из dashboard.

### TDD step

Action test:

```php
it('quick resolves open report from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'resolved_by_user_id' => null,
        'resolved_at' => null,
    ]);

    livewire(LatestReportsTable::class)
        ->actingAs($moderator)
        ->callTableAction('resolveReport', $report, data: [
            'note' => 'Handled from dashboard.',
        ]);

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved);
    expect($report->resolved_by_user_id)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
});
```

Visibility test:

```php
resolved/ignored reports should not show resolve action
```

### Implementation

Add table action:

```php
Action::make('resolveReport')
    ->label('Resolve')
    ->color('success')
    ->visible(fn (Report $record): bool => $record->status === ReportStatus::Open)
    ->form([
        Textarea::make('note')
            ->label('Resolution note')
            ->maxLength(1000),
    ])
    ->requiresConfirmation()
    ->action(function (Report $record, array $data): void {
        app(ResolveReportAction::class)->handle(
            moderator: auth()->user(),
            report: $record,
            note: $data['note'] ?? null,
        );
    })
```

Add dashboard refresh after action if needed:

```php
$this->dispatch('$refresh');
```

or rely on Filament table refresh.

### Acceptance criteria

- Quick resolve action exists.
- Visible only for open reports.
- Calls ResolveReportAction.
- Report becomes resolved.
- resolved_by_user_id set.
- resolved_at set.
- Resolution note saved.
- Does not hide target.
- Does not ban target author.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Test written.
- Quick resolve action added.
- Backend action used.
- Tests/build pass.
- Коммит: `RG-522: Add quick resolve report action to dashboard`

### Files likely touched

```txt
app/Filament/Widgets/LatestReportsTable.php
tests/Feature/Filament/ModerationDashboardActionsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 29 Completion Criteria

Phase 29 завершена, когда:

```txt
- RG-514–RG-522 выполнены;
- ModerationDashboard Filament page exists;
- admin can access dashboard;
- moderator can access dashboard;
- pending posts widget works;
- reported posts widget works;
- reported comments widget works;
- suspicious users widget works with deterministic heuristic;
- latest reports table works;
- latest reports table sorts newest first;
- quick approve action works via ApprovePostAction;
- quick hide action works via HidePostAction / HideCommentAction;
- quick resolve report action works via ResolveReportAction;
- no quick action directly mutates model status;
- no bulk dashboard actions added;
- no new DB schema added unnecessarily;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 29

Без отдельной задачи нельзя:

```txt
- создавать публичный profile page;
- добавлять notifications;
- добавлять AI moderation;
- добавлять abuse scoring;
- добавлять rate limiting;
- добавлять bulk moderation dashboard actions;
- добавлять user reports_count aggregate column;
- автоматически resolve report after hide target;
- автоматически hide target after resolve report;
- банить target author from dashboard, если это не отдельная задача;
- менять public feed/profile UI;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-514 Create ModerationDashboard Filament page
RG-515 Add pending posts widget
RG-516 Add reported posts widget
RG-517 Add reported comments widget
RG-518 Add suspicious users widget
RG-519 Add latest reports table
RG-520 Add quick approve action to dashboard
RG-521 Add quick hide action to dashboard
RG-522 Add quick resolve report action to dashboard
```
---

# 13. Release

После завершения Phase 29:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.10-phase29-moderation-dashboard
git push -u origin release/v0.2.10-phase29-moderation-dashboard
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.10-phase29-moderation-dashboard -m "RateGuru Phase 29 Moderation Dashboard"
git push origin v0.2.10-phase29-moderation-dashboard
```
