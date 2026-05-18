# RateGuru — Phase 19 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 19 — Reports Backend**  
Диапазон задач: **RG-375 → RG-394**  
Основа нумерации: исходный atomic backlog, где Phase 19 начинается с задачи 375 и заканчивается задачей 394.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 19 соответствует исходному блоку:

```txt
Phase 19 — Reports Backend
```

Правильный диапазон Phase 19:

```txt
RG-375 — Create ReportContentAction skeleton
RG-376 — Test user can report post
RG-377 — Implement post report creation
RG-378 — Test user can report comment
RG-379 — Implement comment report creation
RG-380 — Test guest cannot report
RG-381 — Add auth guard for reports
RG-382 — Test banned user cannot report
RG-383 — Add banned guard for reports
RG-384 — Test duplicate report from same user is blocked
RG-385 — Add duplicate report guard
RG-386 — Test reports_count increments on post
RG-387 — Implement post reports_count increment
RG-388 — Test reports_count increments on comment
RG-389 — Implement comment reports_count increment
RG-390 — Test report threshold flags post for review
RG-391 — Implement report threshold rule
RG-392 — Create ResolveReportAction skeleton
RG-393 — Test moderator can resolve report
RG-394 — Implement report resolution
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 19

Phase 19 добавляет backend-логику жалоб на контент.

После Phase 19 должно быть готово:

```txt
- ReportContentAction;
- жалоба на post;
- жалоба на comment;
- guest guard;
- banned user guard;
- duplicate report guard;
- reports_count increment/recalculation для post;
- reports_count increment/recalculation для comment;
- threshold rule для flag post for review;
- ResolveReportAction;
- moderator report resolution.
```

UI жалоб будет в Phase 20.  
Moderation backend будет в Phase 21.  
Filament admin resources будут позже.
---

# 3. Scope Phase 19

## Входит

```txt
- ReportContentAction;
- ResolveReportAction;
- CannotReportContentException;
- CannotResolveReportException;
- ReportReason enum, если его ещё нет;
- ReportStatus enum, если его ещё нет;
- создание report для Post;
- создание report для Comment;
- duplicate prevention;
- reports_count update;
- threshold flag for posts;
- moderator/admin resolve report.
```

## Не входит

```txt
- ReportModal Livewire component;
- report button in PostCard;
- report button in PostDrawer;
- report button in CommentItem;
- report success/error UI;
- moderation dashboard;
- hiding reported content automatically;
- notification to moderators;
- rate limiting;
- IP/device fingerprinting;
- abuse scoring;
- API endpoint.
```

Phase 20 — Reports UI.  
Phase 21 — Moderation Backend.  
Phase 29 — Moderation Dashboard.  
Phase 34 — Rate Limiting & Abuse Guards.
---

# 4. Product / Domain Decisions

## 4.1. What can be reported

Phase 19 supports reports for:

```txt
Post
Comment
```

Do not add user reports, profile reports, image reports, or category reports in this phase.

## 4.2. Report content

A report should store at minimum:

```txt
user_id
reportable_type
reportable_id
reason
message / details
status
resolved_by_user_id nullable
resolved_at nullable
resolution_note nullable
```

If the existing schema has different names, use the existing names.  
If the reports table is missing required columns, add a migration inside the relevant implementation task instead of silently dropping data.

## 4.3. Report reasons

Phase 20 will render report reasons, so backend should already have a stable reason enum.

Recommended MVP reasons:

```txt
spam
harassment
nudity
violence
hate
copyright
illegal
other
```

Do not over-engineer reason taxonomy. It can be refined later.

## 4.4. Report message length

Optional message/details max length:

```txt
1000 characters
```

Rules:

```txt
- reason is required;
- message is optional;
- message is trimmed;
- message over 1000 chars is rejected;
- empty trimmed message becomes null.
```

The backlog does not list separate validation tasks, but backend without validation is weak. Keep validation minimal and covered by tests where touched.

## 4.5. Duplicate report rule

A user can report a specific content item only once.

Duplicate key:

```txt
user_id + reportable_type + reportable_id
```

Decision:

```txt
Block duplicate reports forever, even if the old report was resolved.
```

Reason:

```txt
- simpler MVP;
- prevents spam;
- if content is still bad after resolution, moderators should reopen/create internal moderation action later, not rely on duplicate user reports.
```

If product later needs “report again after resolved”, that is a separate phase.

## 4.6. reports_count meaning

For posts:

```txt
posts.reports_count = total reports for this post
```

For comments:

```txt
comments.reports_count = total reports for this comment
```

Use absolute recalculation from `reports`, not blind increment, to avoid stale counter bugs.

## 4.7. Report threshold

Threshold for post review flag:

```txt
3 reports
```

Meaning:

```txt
when a published post reaches 3 reports, it is flagged for moderator review.
```

Do not auto-hide content in Phase 19.

Flagging strategy:

```txt
Preferred:
- posts.needs_review = true
- posts.flagged_at = now()
- posts.flagged_reason = 'reports_threshold'
```

If those columns do not exist, RG-391 may add a small migration.

Do not change `posts.status` from `published` to `hidden` automatically. That would be moderation, not reporting.

## 4.8. Comment threshold is not in Phase 19

The backlog only says:

```txt
Test report threshold flags post for review
```

So Phase 19 does not flag comments by threshold.  
Comment moderation will be handled by moderators/admin UI later.

## 4.9. Resolve report does not automatically moderate content

Resolving a report means:

```txt
- report.status = resolved
- report.resolved_by_user_id = moderator/admin id
- report.resolved_at = now
- optional resolution_note saved
```

It does not automatically:

```txt
- hide post;
- hide comment;
- ban user;
- approve/reject post.
```

Those are Phase 21 moderation actions.
---

# 5. Architecture Rules

## 5.1. ReportContentAction owns report creation

Do not create reports directly in UI later:

```php
Report::create(...)
```

Correct:

```php
app(ReportContentAction::class)->handle($user, $content, $reason, $message);
```

## 5.2. ResolveReportAction owns report resolution

Do not resolve reports directly in Filament/UI later:

```php
$report->update(['status' => 'resolved']);
```

Correct:

```php
app(ResolveReportAction::class)->handle($moderator, $report, $note);
```

## 5.3. Use polymorphic reportable

Recommended model shape:

```php
Report belongsTo User
Report morphTo reportable
Post morphMany reports
Comment morphMany reports
```

If existing schema is not polymorphic, do not invent a parallel report system. Adapt to existing schema, but keep the public action API stable.

## 5.4. Guards live in actions

The UI may hide report buttons for guests/banned users later, but actions must still protect:

```txt
guest;
banned/non-active user;
duplicate reporter;
unsupported reportable type;
deleted/hidden content if applicable.
```

## 5.5. No UI in Phase 19

Do not create:

```txt
ReportModal
report buttons
Alpine modal behavior
success state UI
validation UI
```

That is Phase 20.
---

# 6. GitFlow для Phase 19

## Base branch

Все задачи Phase 19 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-375-create-report-content-action-skeleton
feature/RG-385-add-duplicate-report-guard
feature/RG-394-implement-report-resolution
```

## Commit format

```txt
RG-375: Create ReportContentAction skeleton
RG-385: Add duplicate report guard
RG-394: Implement report resolution
```

## Release branch

После выполнения `RG-375`–`RG-394`:

```txt
release/v0.2.0-phase19-reports-backend
```

## Tag

После merge release branch в `main`:

```txt
v0.2.0-phase19-reports-backend
```

Почему `v0.2.0`: после comments + reports backend начинается полноценный moderation-ready MVP slice. Если хочешь держать patch-only версионирование, можно заменить на `v0.1.10-phase19-reports-backend`, но это менее аккуратно.
---

# 7. TDD Rules for Phase 19

## Для ReportContentAction

Каждое поведение test-first:

```txt
- user can report post;
- user can report comment;
- guest cannot report;
- banned user cannot report;
- duplicate report blocked;
- post reports_count updates;
- comment reports_count updates;
- threshold flags post for review.
```

## Для ResolveReportAction

Test-first:

```txt
- moderator can resolve report;
- normal user cannot resolve report, если добавлен safety test;
- resolved_at/resolved_by/status update correctly.
```

## Для counters

Тесты должны намеренно создавать stale value:

```txt
reports_count = 99
actual reports = 1
after action = 1
```

Это доказывает absolute recalculation, а не blind increment.

## Для threshold

Тест должен создать reports below threshold and at threshold:

```txt
2 reports → not flagged
3 reports → flagged
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Reports / Tests
Type: Test / Feature / Action / Validation / Moderation
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым.

Implementation:
Что именно меняем.

Acceptance criteria:
- Проверяемый результат 1
- Проверяемый результат 2
- Проверяемый результат 3

Definition of Done:
- Тест написан первым
- Тест падает до реализации, если применимо
- Реализация минимальная
- Тест проходит
- Все связанные тесты проходят
- Нет UI вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 19 Atomic Tasks
---

## RG-375 — Create ReportContentAction Skeleton

**Area:** Backend / Reports  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-375-create-report-content-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-374

### Goal

Создать skeleton action для создания report на Post или Comment.

### TDD step

Unit test:

```php
it('has report content action with handle method', function () {
    $action = app(ReportContentAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Reports/ReportContentAction.php
```

Skeleton:

```php
namespace App\Actions\Reports;

use App\Enums\ReportReason;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ReportContentAction
{
    public function handle(
        ?User $user,
        Model $content,
        ReportReason $reason,
        ?string $message = null,
    ): Report {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Создать `ReportReason` enum, если его ещё нет:

```txt
app/Enums/ReportReason.php
```

Minimal values:

```txt
Spam
Harassment
Nudity
Violence
Hate
Copyright
Illegal
Other
```

Не реализовывать создание report пока.

### Acceptance criteria

- `ReportContentAction` существует.
- `handle(?User $user, Model $content, ReportReason $reason, ?string $message = null): Report` существует.
- `ReportReason` enum существует или используется существующий.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Action skeleton создан.
- Enum создан/подключён.
- Тест проходит.
- Коммит: `RG-375: Create ReportContentAction skeleton`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
app/Enums/ReportReason.php
tests/Unit/Actions/ReportContentActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-376 — Test User Can Report Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-376-test-user-can-report-post`  
**Base branch:** develop
**Depends on:** RG-375

### Goal

Написать падающий тест: authenticated active user может пожаловаться на published post.

### TDD step

Feature/action test:

```php
it('allows user to report post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $report = app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam,
        message: 'This looks like spam.'
    );

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->exists)->toBeTrue();
    expect($report->user_id)->toBe($user->id);
    expect($report->reportable_type)->toBe(Post::class);
    expect($report->reportable_id)->toBe($post->id);
    expect($report->reason)->toBe(ReportReason::Spam);
});
```

Если enum casts return string, assertion адаптировать:

```php
expect($report->reason)->toBe(ReportReason::Spam->value);
```

Тест должен упасть до RG-377.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет report row.
- Тест проверяет polymorphic post target.
- Тест проверяет reason/message.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-376: Test user can report post`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-377 — Implement Post Report Creation

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-377-implement-post-report-creation`  
**Base branch:** develop
**Depends on:** RG-376

### Goal

Реализовать создание report для Post.

### TDD step

Использовать падающий тест из RG-376.

### Implementation

Создать exception, если нужно будет для validation/unsupported later:

```txt
app/Exceptions/Reports/CannotReportContentException.php
```

Минимальная реализация в `ReportContentAction`:

```php
if (! $content instanceof Post) {
    throw CannotReportContentException::becauseUnsupportedContent();
}

$message = trim((string) $message);
$message = $message === '' ? null : $message;

return Report::create([
    'user_id' => $user->id,
    'reportable_type' => $content::class,
    'reportable_id' => $content->id,
    'reason' => $reason,
    'message' => $message,
    'status' => ReportStatus::Open,
]);
```

Создать `ReportStatus` enum, если его нет:

```txt
open
resolved
dismissed
```

Если reports table не имеет `status`, `message`, `resolved_*` fields, зафиксировать missing prerequisite или добавить migration в соответствующих implementation tasks.

### Acceptance criteria

- User can report post.
- Report row created.
- reportable points to Post.
- reason saved.
- message trimmed/saved.
- default status = open.
- Тест RG-376 проходит.
- Guards будут добавлены позже.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-377: Implement post report creation`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
app/Enums/ReportStatus.php
app/Exceptions/Reports/CannotReportContentException.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-378 — Test User Can Report Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-378-test-user-can-report-comment`  
**Base branch:** develop
**Depends on:** RG-377

### Goal

Написать падающий тест: authenticated active user может пожаловаться на visible comment.

### TDD step

Feature/action test:

```php
it('allows user to report comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $report = app(ReportContentAction::class)->handle(
        user: $user,
        content: $comment,
        reason: ReportReason::Harassment,
        message: 'This comment is abusive.'
    );

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->reportable_type)->toBe(Comment::class);
    expect($report->reportable_id)->toBe($comment->id);
    expect($report->reason)->toBe(ReportReason::Harassment);
});
```

Тест должен упасть до RG-379, если action разрешает только Post.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет report row.
- Тест проверяет polymorphic comment target.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-378: Test user can report comment`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-379 — Implement Comment Report Creation

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-379-implement-comment-report-creation`  
**Base branch:** develop
**Depends on:** RG-378

### Goal

Реализовать создание report для Comment.

### TDD step

Использовать падающий тест из RG-378.

### Implementation

В `ReportContentAction` разрешить:

```php
$content instanceof Post || $content instanceof Comment
```

Unsupported types:

```php
throw CannotReportContentException::becauseUnsupportedContent();
```

Для comment желательно проверять, что comment visible/not deleted позже или сразу:

```txt
hidden comment should not be reportable through public UI
```

Backlog не даёт отдельной задачи, но backend должен не принимать hidden/deleted content. Можно добавить safety test here if not too large.

### Acceptance criteria

- User can report visible comment.
- Report row created.
- reportable points to Comment.
- Post report behavior still works.
- Unsupported content blocked or ready to be blocked.
- Тесты проходят.

### Definition of Done

- Comment support добавлен.
- Тесты проходят.
- Коммит: `RG-379: Implement comment report creation`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-380 — Test Guest Cannot Report

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-380-test-guest-cannot-report`  
**Base branch:** develop
**Depends on:** RG-379

### Goal

Написать тест: guest не может создавать report.

### TDD step

Feature/action test:

```php
it('does not allow guest to report content', function () {
    $post = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle(
        user: null,
        content: $post,
        reason: ReportReason::Spam,
        message: null
    );
})->throws(CannotReportContentException::class);
```

No side effects:

```php
expect(Report::query()->count())->toBe(0);
```

Тест должен упасть до RG-381.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Guest/null user получает explicit exception.
- Report row не создаётся.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-380: Test guest cannot report`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-381 — Add Auth Guard For Reports

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-381-add-auth-guard-for-reports`  
**Base branch:** develop
**Depends on:** RG-380

### Goal

Добавить auth guard в `ReportContentAction`.

### TDD step

Использовать падающий тест из RG-380.

### Implementation

В `CannotReportContentException`:

```php
public static function becauseGuest(): self
{
    return new self('Guests cannot report content.');
}
```

В `ReportContentAction` перед любыми create:

```php
if ($user === null) {
    throw CannotReportContentException::becauseGuest();
}
```

### Acceptance criteria

- Guest/null user blocked.
- Explicit exception.
- No report row created.
- Authenticated user can still report post/comment.
- Тесты проходят.

### Definition of Done

- Auth guard добавлен.
- Тесты проходят.
- Коммит: `RG-381: Add auth guard for reports`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
app/Exceptions/Reports/CannotReportContentException.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-382 — Test Banned User Cannot Report

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-382-test-banned-user-cannot-report`  
**Base branch:** develop
**Depends on:** RG-381

### Goal

Написать тест: banned user не может создавать report.

### TDD step

Feature/action test:

```php
it('does not allow banned user to report content', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam,
        message: null
    );
})->throws(CannotReportContentException::class);
```

No side effects:

```php
expect(Report::query()->count())->toBe(0);
```

Тест должен упасть до RG-383.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Banned user receives CannotReportContentException.
- No report row.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-382: Test banned user cannot report`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-383 — Add Banned Guard For Reports

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-383-add-banned-guard-for-reports`  
**Base branch:** develop
**Depends on:** RG-382

### Goal

Добавить guard, запрещающий banned users создавать reports.

### TDD step

Использовать падающий тест из RG-382.

### Implementation

В `User` model добавить, если ещё нет:

```php
public function canReport(): bool
{
    return $this->status === UserStatus::Active;
}
```

В `ReportContentAction`:

```php
if (! $user->canReport()) {
    throw CannotReportContentException::becauseUserIsNotAllowed();
}
```

Exception method:

```php
public static function becauseUserIsNotAllowed(): self
{
    return new self('User is not allowed to report content.');
}
```

Не использовать `canVote()` автоматически: report — отдельное поведение.

### Acceptance criteria

- Banned user blocked.
- Active user can report.
- No side effects for blocked user.
- `canReport()` exists or equivalent explicit guard.
- Тесты проходят.

### Definition of Done

- Guard добавлен.
- Тесты проходят.
- Коммит: `RG-383: Add banned guard for reports`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
app/Exceptions/Reports/CannotReportContentException.php
app/Models/User.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-384 — Test Duplicate Report From Same User Is Blocked

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-384-test-duplicate-report-from-same-user-is-blocked`  
**Base branch:** develop
**Depends on:** RG-383

### Goal

Написать тест: один user не может дважды пожаловаться на один и тот же content item.

### TDD step

Feature/action test for post:

```php
it('blocks duplicate report from same user for same post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);
    app(ReportContentAction::class)->handle($user, $post, ReportReason::Spam);
})->throws(CannotReportContentException::class);
```

Check only one report:

```php
expect(Report::query()
    ->where('user_id', $user->id)
    ->where('reportable_type', Post::class)
    ->where('reportable_id', $post->id)
    ->count()
)->toBe(1);
```

Also add comment duplicate test:

```php
it('blocks duplicate report from same user for same comment', ...)
```

Same user can report different content:

```php
it('allows same user to report different content items', ...)
```

Different users can report same content:

```php
it('allows different users to report same content', ...)
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Duplicate same user/content blocked.
- Same user can report different content.
- Different users can report same content.
- Only one row remains after duplicate attempt.
- Тесты падают до RG-385.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-384: Test duplicate report from same user is blocked`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-385 — Add Duplicate Report Guard

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-385-add-duplicate-report-guard`  
**Base branch:** develop
**Depends on:** RG-384

### Goal

Добавить duplicate report guard.

### TDD step

Использовать падающие тесты из RG-384.

### Implementation

В `ReportContentAction` перед create:

```php
$alreadyReported = Report::query()
    ->where('user_id', $user->id)
    ->where('reportable_type', $content::class)
    ->where('reportable_id', $content->id)
    ->exists();

if ($alreadyReported) {
    throw CannotReportContentException::becauseDuplicateReport();
}
```

Exception method:

```php
public static function becauseDuplicateReport(): self
{
    return new self('You have already reported this content.');
}
```

DB-level safety migration if missing:

```txt
unique index on reports(user_id, reportable_type, reportable_id)
```

If existing schema already has unique index, do not duplicate it.  
If not, add migration in this task.

### Acceptance criteria

- Duplicate same user/content blocked.
- Different users can report same content.
- Same user can report different content.
- Optional unique DB index exists.
- Тесты проходят.

### Definition of Done

- Duplicate guard добавлен.
- DB unique index added if missing.
- Тесты проходят.
- Коммит: `RG-385: Add duplicate report guard`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
app/Exceptions/Reports/CannotReportContentException.php
database/migrations/*add_unique_user_reportable_index_to_reports_table.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-386 — Test Reports_Count Increments On Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-386-test-reports-count-increments-on-post`  
**Base branch:** develop
**Depends on:** RG-385

### Goal

Написать тест: после report на post обновляется `posts.reports_count`.

### TDD step

Feature/action test:

```php
it('updates post reports count after report', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'reports_count' => 99,
    ]);

    app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam
    );

    expect($post->fresh()->reports_count)->toBe(1);
});
```

Почему 99 → 1: нужен absolute recalculation, не blind increment.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Stale reports_count исправляется.
- Тест падает до RG-387.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-386: Test reports_count increments on post`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-387 — Implement Post Reports_Count Increment

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-387-implement-post-reports-count-increment`  
**Base branch:** develop
**Depends on:** RG-386

### Goal

Обновлять `posts.reports_count` после report на post.

### TDD step

Использовать падающий тест из RG-386.

### Implementation

В `ReportContentAction` после create report:

```php
if ($content instanceof Post) {
    $this->refreshPostReportsCount($content);
}
```

Helper:

```php
private function refreshPostReportsCount(Post $post): void
{
    $count = Report::query()
        ->where('reportable_type', Post::class)
        ->where('reportable_id', $post->id)
        ->count();

    $post->forceFill([
        'reports_count' => $count,
    ])->save();
}
```

Не увеличивать blindly:

```php
$post->increment('reports_count')
```

because stale counters.

### Acceptance criteria

- Post reports_count updates after report.
- Stale reports_count repaired.
- Duplicate blocked report does not increment.
- Comment report does not affect post reports_count.
- Тесты проходят.

### Definition of Done

- Post reports_count update добавлен.
- Тесты проходят.
- Коммит: `RG-387: Implement post reports_count increment`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-388 — Test Reports_Count Increments On Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-388-test-reports-count-increments-on-comment`  
**Base branch:** develop
**Depends on:** RG-387

### Goal

Написать тест: после report на comment обновляется `comments.reports_count`.

### TDD step

Feature/action test:

```php
it('updates comment reports count after report', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->create([
        'reports_count' => 99,
        'status' => CommentStatus::Visible,
    ]);

    app(ReportContentAction::class)->handle(
        user: $user,
        content: $comment,
        reason: ReportReason::Harassment
    );

    expect($comment->fresh()->reports_count)->toBe(1);
});
```

Если `comments.reports_count` column отсутствует, этот тест покажет missing schema.  
Do not ignore it: task explicitly requires comment reports_count.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Stale comment reports_count исправляется.
- Тест падает до RG-389.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-388: Test reports_count increments on comment`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-389 — Implement Comment Reports_Count Increment

**Area:** Backend / Reports  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-389-implement-comment-reports-count-increment`  
**Base branch:** develop
**Depends on:** RG-388

### Goal

Обновлять `comments.reports_count` после report на comment.

### TDD step

Использовать падающий тест из RG-388.

### Implementation

Если `comments.reports_count` отсутствует, добавить migration:

```txt
add_reports_count_to_comments_table
```

Column:

```php
$table->unsignedInteger('reports_count')->default(0);
```

В `ReportContentAction`:

```php
if ($content instanceof Comment) {
    $this->refreshCommentReportsCount($content);
}
```

Helper:

```php
private function refreshCommentReportsCount(Comment $comment): void
{
    $count = Report::query()
        ->where('reportable_type', Comment::class)
        ->where('reportable_id', $comment->id)
        ->count();

    $comment->forceFill([
        'reports_count' => $count,
    ])->save();
}
```

### Acceptance criteria

- Comment reports_count updates after report.
- Stale reports_count repaired.
- Duplicate blocked report does not increment.
- Post report does not affect comment reports_count.
- Migration added only if column missing.
- Тесты проходят.

### Definition of Done

- Comment reports_count update добавлен.
- Schema fixed if needed.
- Тесты проходят.
- Коммит: `RG-389: Implement comment reports_count increment`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
database/migrations/*add_reports_count_to_comments_table.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-390 — Test Report Threshold Flags Post For Review

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-390-test-report-threshold-flags-post-for-review`  
**Base branch:** develop
**Depends on:** RG-389

### Goal

Написать тест: когда post достигает report threshold, он помечается для review.

### TDD step

Feature/action test:

```php
it('flags post for review when report threshold is reached', function () {
    $post = Post::factory()->published()->create([
        'reports_count' => 0,
        'needs_review' => false,
        'flagged_at' => null,
    ]);

    $users = User::factory()->count(3)->create();

    foreach ($users as $user) {
        app(ReportContentAction::class)->handle(
            user: $user,
            content: $post->fresh(),
            reason: ReportReason::Spam
        );
    }

    $post->refresh();

    expect($post->reports_count)->toBe(3);
    expect($post->needs_review)->toBeTrue();
    expect($post->flagged_at)->not->toBeNull();
});
```

Below-threshold test:

```php
it('does not flag post before report threshold is reached', function () {
    $post = Post::factory()->published()->create([
        'needs_review' => false,
    ]);

    $users = User::factory()->count(2)->create();

    foreach ($users as $user) {
        app(ReportContentAction::class)->handle($user, $post->fresh(), ReportReason::Spam);
    }

    expect($post->fresh()->needs_review)->toBeFalse();
});
```

Если columns `needs_review/flagged_at` отсутствуют, тест должен сначала упасть, а RG-391 добавит migration.

### Implementation

Только добавить тесты.

### Acceptance criteria

- 2 reports do not flag.
- 3 reports flag.
- reports_count correct.
- flagged_at set.
- Post remains published; not auto-hidden.
- Тесты падают до RG-391.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-390: Test report threshold flags post for review`

### Files likely touched

```txt
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-391 — Implement Report Threshold Rule

**Area:** Backend / Reports / Moderation Readiness  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-391-implement-report-threshold-rule`  
**Base branch:** develop
**Depends on:** RG-390

### Goal

Реализовать threshold rule для post reports.

### TDD step

Использовать падающие тесты из RG-390.

### Implementation

Константа:

```php
private const POST_REVIEW_REPORT_THRESHOLD = 3;
```

После refresh reports_count for post:

```php
$this->flagPostForReviewIfThresholdReached($post->fresh());
```

Helper:

```php
private function flagPostForReviewIfThresholdReached(Post $post): void
{
    if ($post->reports_count < self::POST_REVIEW_REPORT_THRESHOLD) {
        return;
    }

    if ($post->needs_review) {
        return;
    }

    $post->forceFill([
        'needs_review' => true,
        'flagged_at' => now(),
        'flagged_reason' => 'reports_threshold',
    ])->save();
}
```

Если columns отсутствуют, add migration:

```txt
add_review_flags_to_posts_table
```

Columns:

```php
$table->boolean('needs_review')->default(false)->index();
$table->timestamp('flagged_at')->nullable();
$table->string('flagged_reason')->nullable();
```

Do not change `status`.

### Acceptance criteria

- Threshold = 3.
- 2 reports do not flag.
- 3 reports flag.
- flagged_at set once.
- Post status remains published.
- Repeated reports after threshold do not reset flagged_at unnecessarily.
- Тесты проходят.

### Definition of Done

- Threshold rule добавлен.
- Migration added if needed.
- Тесты проходят.
- Коммит: `RG-391: Implement report threshold rule`

### Files likely touched

```txt
app/Actions/Reports/ReportContentAction.php
database/migrations/*add_review_flags_to_posts_table.php
tests/Feature/Actions/ReportContentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-392 — Create ResolveReportAction Skeleton

**Area:** Backend / Reports  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-392-create-resolve-report-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-391

### Goal

Создать skeleton action для resolution report модератором.

### TDD step

Unit test:

```php
it('has resolve report action with handle method', function () {
    $action = app(ResolveReportAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Reports/ResolveReportAction.php
```

Skeleton:

```php
namespace App\Actions\Reports;

use App\Models\Report;
use App\Models\User;

final class ResolveReportAction
{
    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Не реализовывать resolution пока.

### Acceptance criteria

- `ResolveReportAction` существует.
- Есть `handle(User $moderator, Report $report, ?string $note = null): void`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-392: Create ResolveReportAction skeleton`

### Files likely touched

```txt
app/Actions/Reports/ResolveReportAction.php
tests/Unit/Actions/ResolveReportActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-393 — Test Moderator Can Resolve Report

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-393-test-moderator-can-resolve-report`  
**Base branch:** develop
**Depends on:** RG-392

### Goal

Написать тест: moderator может resolve report.

### TDD step

Feature/action test:

```php
it('allows moderator to resolve report', function () {
    $moderator = User::factory()->moderator()->create();

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'resolved_by_user_id' => null,
        'resolved_at' => null,
    ]);

    app(ResolveReportAction::class)->handle(
        moderator: $moderator,
        report: $report,
        note: 'Reviewed and handled.'
    );

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved);
    expect($report->resolved_by_user_id)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
    expect($report->resolution_note)->toBe('Reviewed and handled.');
});
```

Safety test:

```php
it('does not allow normal user to resolve report', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();

    app(ResolveReportAction::class)->handle($user, $report);
})->throws(CannotResolveReportException::class);
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Moderator can resolve open report.
- resolved_by/resolved_at set.
- resolution note saved.
- Normal user blocked, если test добавлен.
- Тесты падают до RG-394.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-393: Test moderator can resolve report`

### Files likely touched

```txt
tests/Feature/Actions/ResolveReportActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-394 — Implement Report Resolution

**Area:** Backend / Reports / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-394-implement-report-resolution`  
**Base branch:** develop
**Depends on:** RG-393

### Goal

Реализовать report resolution.

### TDD step

Использовать падающие тесты из RG-393.

### Implementation

Создать exception:

```txt
app/Exceptions/Reports/CannotResolveReportException.php
```

Exception:

```php
final class CannotResolveReportException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to resolve reports.');
    }

    public static function becauseReportIsAlreadyResolved(): self
    {
        return new self('Report is already resolved.');
    }
}
```

Action:

```php
final class ResolveReportAction
{
    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotResolveReportException::becauseUserIsNotAllowed();
        }

        if ($report->status === ReportStatus::Resolved) {
            return; // or throw; choose idempotent for moderation UX
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        $report->forceFill([
            'status' => ReportStatus::Resolved,
            'resolved_by_user_id' => $moderator->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();
    }
}
```

Decision:

```txt
Resolving an already resolved report is idempotent no-op.
```

Reason:

```txt
moderation UIs can double-submit; idempotency is safer than throwing.
```

If the tests expect throw, adjust. Recommended: no-op.

If report table lacks resolution fields, add migration:

```txt
resolved_by_user_id nullable FK users
resolved_at nullable timestamp
resolution_note nullable text
```

### Acceptance criteria

- Moderator can resolve report.
- Admin can resolve report, if test added.
- Normal user cannot resolve.
- status becomes resolved.
- resolved_by_user_id set.
- resolved_at set.
- resolution_note trimmed/saved.
- Double resolve safe.
- Does not auto-hide content.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- ResolveReportAction implemented.
- Exception added.
- Migration added if needed.
- Tests pass.
- Build passes.
- Коммит: `RG-394: Implement report resolution`

### Files likely touched

```txt
app/Actions/Reports/ResolveReportAction.php
app/Exceptions/Reports/CannotResolveReportException.php
database/migrations/*add_resolution_fields_to_reports_table.php
tests/Feature/Actions/ResolveReportActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 19 Completion Criteria

Phase 19 завершена, когда:

```txt
- RG-375–RG-394 выполнены;
- ReportContentAction exists;
- user can report post;
- user can report comment;
- guest cannot report;
- banned user cannot report;
- duplicate report from same user/content is blocked;
- same user can report different content;
- different users can report same content;
- post reports_count updates/recalculates;
- comment reports_count updates/recalculates;
- report threshold flags post for review;
- threshold is 3 reports;
- threshold does not auto-hide post;
- ResolveReportAction exists;
- moderator/admin can resolve report;
- normal user cannot resolve report;
- resolving report stores status/resolved_by/resolved_at/note;
- no Reports UI was added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 19

Без отдельной задачи нельзя:

```txt
- создавать ReportModal Livewire component;
- добавлять report button в PostCard;
- добавлять report button в PostDrawer;
- добавлять report button в CommentItem;
- делать report success/error UI;
- делать moderation dashboard;
- автоматически скрывать post/comment при threshold;
- банить пользователей по report;
- отправлять notifications to moderators;
- добавлять report rate limiting;
- добавлять IP/device fingerprinting;
- делать abuse scoring;
- делать API endpoint;
- добавлять Redis/cache layer;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-375 Create ReportContentAction skeleton
RG-376 Test user can report post
RG-377 Implement post report creation
RG-378 Test user can report comment
RG-379 Implement comment report creation
RG-380 Test guest cannot report
RG-381 Add auth guard for reports
RG-382 Test banned user cannot report
RG-383 Add banned guard for reports
RG-384 Test duplicate report from same user is blocked
RG-385 Add duplicate report guard
RG-386 Test reports_count increments on post
RG-387 Implement post reports_count increment
RG-388 Test reports_count increments on comment
RG-389 Implement comment reports_count increment
RG-390 Test report threshold flags post for review
RG-391 Implement report threshold rule
RG-392 Create ResolveReportAction skeleton
RG-393 Test moderator can resolve report
RG-394 Implement report resolution
```
---

# 13. Release

После завершения Phase 19:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.0-phase19-reports-backend
git push -u origin release/v0.2.0-phase19-reports-backend
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.0-phase19-reports-backend -m "RateGuru Phase 19 reports backend"
git push origin v0.2.0-phase19-reports-backend
```
