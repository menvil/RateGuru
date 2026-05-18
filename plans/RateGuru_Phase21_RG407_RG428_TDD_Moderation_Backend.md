# RateGuru — Phase 21 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 21 — Moderation Backend**  
Диапазон задач: **RG-407 → RG-428**  
Основа нумерации: исходный atomic backlog, где Phase 21 начинается с задачи 407 и заканчивается задачей 428.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 21 соответствует исходному блоку:

```txt
Phase 21 — Moderation Backend
```

Правильный диапазон Phase 21:

```txt
RG-407 — Create ApprovePostAction skeleton
RG-408 — Test moderator can approve pending post
RG-409 — Implement post approval
RG-410 — Create RejectPostAction skeleton
RG-411 — Test moderator can reject pending post
RG-412 — Implement post rejection
RG-413 — Create HidePostAction skeleton
RG-414 — Test moderator can hide published post
RG-415 — Implement post hiding
RG-416 — Create RestorePostAction skeleton
RG-417 — Test moderator can restore hidden post
RG-418 — Implement post restore
RG-419 — Create BanUserAction skeleton
RG-420 — Test admin can ban user
RG-421 — Implement user ban
RG-422 — Create ShadowbanUserAction skeleton
RG-423 — Test admin can shadowban user
RG-424 — Implement user shadowban
RG-425 — Create CreateModerationLogAction skeleton
RG-426 — Test moderation action creates log
RG-427 — Write moderation logs from post actions
RG-428 — Write moderation logs from user actions
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 22 начинается с `RG-429` и делает **Inline Moderation UI**. Поэтому Phase 21 не должна добавлять кнопки модерации, confirm modals или inline UI.
---

# 2. Цель Phase 21

Phase 21 добавляет backend-ядро модерации.

После Phase 21 должно быть готово:

```txt
- ApprovePostAction;
- RejectPostAction;
- HidePostAction;
- RestorePostAction;
- BanUserAction;
- ShadowbanUserAction;
- CreateModerationLogAction;
- moderation logs для post actions;
- moderation logs для user actions.
```

Это foundation для:

```txt
- Phase 22 Inline Moderation UI;
- Phase 24 Filament Post Resource;
- Phase 25 Filament User Resource;
- Phase 29 Moderation Dashboard.
```
---

# 3. Scope Phase 21

## Входит

```txt
- approve pending post;
- reject pending post;
- hide published post;
- restore hidden post;
- ban user;
- shadowban user;
- moderation log action;
- запись логов из post moderation actions;
- запись логов из user moderation actions;
- authorization guards для moderator/admin;
- state guards для допустимых переходов.
```

## Не входит

```txt
- InlinePostModeration Livewire component;
- approve/hide/reject/restore buttons;
- confirmation modal;
- reason input UI;
- Filament resources;
- moderation dashboard;
- report resolution UI;
- notifications;
- email alerts;
- automatic moderation;
- AI moderation;
- rate limiting;
- queue jobs.
```

UI будет Phase 22.  
Filament admin — Phase 23+.  
Notifications — Phase 31.
---

# 4. Product / Domain Decisions

## 4.1. Кто может модерировать posts

Post moderation actions доступны:

```txt
moderator
admin
```

Normal user не может:

```txt
approve post
reject post
hide post
restore post
```

## 4.2. Кто может банить пользователей

User moderation actions доступны только:

```txt
admin
```

Moderator не должен банить или shadowban users в Phase 21.

Почему:

```txt
- post moderation и user sanctions — разные уровни власти;
- бан пользователя имеет сильные последствия;
- лучше начать строго, потом расширить permissions при необходимости.
```

## 4.3. Allowed post status transitions

Фиксируем разрешённые переходы:

```txt
pending   → published   via ApprovePostAction
pending   → rejected    via RejectPostAction
published → hidden      via HidePostAction
hidden    → published   via RestorePostAction
```

Запрещённые переходы:

```txt
published → approved again
hidden    → approve
rejected  → approve
rejected  → restore
pending   → restore
hidden    → reject
```

Если позже нужен сложный workflow, например `rejected → pending`, это отдельная фаза.

## 4.4. Approval behavior

При approval:

```txt
status = published
published_at = now(), если пустой
approved_by_user_id = moderator/admin id, если колонка есть
approved_at = now(), если колонка есть
rejected_at = null, если колонка есть
hidden_at = null, если колонка есть
needs_review = false, если колонка есть
```

Если approval metadata columns отсутствуют, не надо блокировать фазу.  
Минимально обязательны:

```txt
status = published
published_at set
moderation log written
```

## 4.5. Rejection behavior

При rejection:

```txt
status = rejected
rejected_at = now(), если колонка есть
rejected_by_user_id = moderator/admin id, если колонка есть
rejection_reason = reason, если колонка есть
needs_review = false, если колонка есть
```

Post не удаляется физически.

## 4.6. Hide behavior

При hide:

```txt
status = hidden
hidden_at = now(), если колонка есть
hidden_by_user_id = moderator/admin id, если колонка есть
hide_reason = reason, если колонка есть
```

Hidden post не должен показываться в feed/post show/drawer, это уже должно обеспечиваться published-only query из ранних фаз.

## 4.7. Restore behavior

Restore возвращает hidden post в published:

```txt
status = published
hidden_at = null, если колонка есть
hidden_by_user_id = null, если колонка есть
needs_review = false, если колонка есть
```

Restore не должен работать для rejected/pending posts.

## 4.8. Ban behavior

Ban user:

```txt
user.status = banned
```

Banned user не может:

```txt
create posts
vote
comment
report
```

Эти guards уже должны быть в actions ранних фазах. BanUserAction только меняет user status и пишет log.

## 4.9. Shadowban behavior

Shadowban user:

```txt
user.status = shadowbanned
```

Если `UserStatus::Shadowbanned` ещё нет, RG-424 должен добавить enum value и миграционная/validation часть должна его поддержать.

Shadowban product rule для MVP:

```txt
shadowbanned user может думать, что он создаёт контент,
но его новые posts/comments должны становиться pending/hidden depending on future rules.
```

Однако в Phase 21 мы **не меняем CreatePostAction/AddCommentAction** под shadowban, если это не было заранее заложено.  
Phase 21 только добавляет status/action/log. Поведение shadowbanned users в контентных actions можно доработать позже отдельной фазой.

## 4.10. Moderation logs обязательны

Любое moderation action должно писать log:

```txt
approve_post
reject_post
hide_post
restore_post
ban_user
shadowban_user
```

Без moderation log действие считается неполным.

Лог должен хранить:

```txt
moderator_id
action
moderatable_type
moderatable_id
reason / note nullable
metadata JSON nullable
created_at
```

Если existing `moderation_logs` schema отличается, адаптировать action к ней, но не терять смысл.
---

# 5. Architecture Rules

## 5.1. Each moderation operation has its own action

Не делать один огромный:

```php
ModeratePostAction
```

В этой фазе нужны отдельные actions:

```txt
ApprovePostAction
RejectPostAction
HidePostAction
RestorePostAction
BanUserAction
ShadowbanUserAction
```

Так проще тестировать и подключать к UI/Filament.

## 5.2. Actions own business logic

UI/Filament later must call actions.

Нельзя потом делать:

```php
$post->update(['status' => 'hidden'])
$user->update(['status' => 'banned'])
```

Правильно:

```php
app(HidePostAction::class)->handle($moderator, $post, $reason);
app(BanUserAction::class)->handle($admin, $user, $reason);
```

## 5.3. CreateModerationLogAction owns log creation

Нельзя размазывать log creation по всем actions как raw `ModerationLog::create(...)`.

Правильно:

```php
app(CreateModerationLogAction::class)->handle(
    moderator: $moderator,
    action: ModerationActionType::HidePost,
    target: $post,
    reason: $reason,
    metadata: [...]
);
```

## 5.4. Authorization guard inside action

Даже если будущий UI скрывает кнопки, actions должны сами защищаться:

```txt
normal user cannot approve/hide/reject/restore;
moderator cannot ban/shadowban user;
admin can do everything.
```

## 5.5. State guard inside action

Actions должны проверять текущий status.

Пример:

```php
if ($post->status !== PostStatus::Pending) {
    throw CannotModeratePostException::becausePostStatusIsInvalid();
}
```

Не полагаться на UI фильтры.

## 5.6. No UI in Phase 21

Не создавать:

```txt
InlinePostModeration
moderation buttons
confirmation modal
reason input UI
admin pages
```
---

# 6. GitFlow для Phase 21

## Base branch

Все задачи Phase 21 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-407-create-approve-post-action-skeleton
feature/RG-421-implement-user-ban
feature/RG-428-write-moderation-logs-from-user-actions
```

## Commit format

```txt
RG-407: Create ApprovePostAction skeleton
RG-421: Implement user ban
RG-428: Write moderation logs from user actions
```

## Release branch

После выполнения `RG-407`–`RG-428`:

```txt
release/v0.2.2-phase21-moderation-backend
```

## Tag

После merge release branch в `main`:

```txt
v0.2.2-phase21-moderation-backend
```
---

# 7. TDD Rules for Phase 21

## Для post actions

Каждое поведение test-first:

```txt
- moderator can approve pending post;
- moderator can reject pending post;
- moderator can hide published post;
- moderator can restore hidden post;
- normal user cannot run those actions;
- invalid status transitions are blocked.
```

## Для user actions

Test-first:

```txt
- admin can ban user;
- moderator cannot ban user;
- admin can shadowban user;
- moderator cannot shadowban user;
- admin cannot ban self, если добавляем safety test;
- admin cannot ban another admin, если добавляем safety test.
```

## Для logs

Test-first:

```txt
- CreateModerationLogAction creates log;
- post actions write logs;
- user actions write logs.
```

## Для safety

Тесты должны проверять не только happy path, но и отсутствие side effects:

```txt
when action is forbidden:
- status does not change;
- log is not written.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Moderation / Tests
Type: Test / Feature / Action / Validation / Audit
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

# 9. Phase 21 Atomic Tasks
---

## RG-407 — Create ApprovePostAction Skeleton

**Area:** Backend / Moderation  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-407-create-approve-post-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-406

### Goal

Создать skeleton action для approval pending post.

### TDD step

Unit test:

```php
it('has approve post action with handle method', function () {
    $action = app(ApprovePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Moderation/ApprovePostAction.php
```

Skeleton:

```php
namespace App\Actions\Moderation;

use App\Models\Post;
use App\Models\User;

final class ApprovePostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Не реализовывать approval пока.

### Acceptance criteria

- `ApprovePostAction` exists.
- `handle(User $moderator, Post $post, ?string $reason = null): void` exists.
- Action resolves from container.
- No business logic yet.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-407: Create ApprovePostAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/ApprovePostAction.php
tests/Unit/Actions/ApprovePostActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-408 — Test Moderator Can Approve Pending Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-408-test-moderator-can-approve-pending-post`  
**Base branch:** develop
**Depends on:** RG-407

### Goal

Написать падающий тест: moderator может approve pending post.

### TDD step

Feature/action test:

```php
it('allows moderator to approve pending post', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->pending()->create([
        'published_at' => null,
    ]);

    app(ApprovePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Looks valid.'
    );

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});
```

Safety test желательно добавить здесь же:

```php
it('does not allow normal user to approve pending post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    app(ApprovePostAction::class)->handle($user, $post);
})->throws(CannotModeratePostException::class);
```

И invalid transition:

```php
it('does not approve non pending post', ...)
```

Тесты должны упасть до RG-409.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Тест на moderator happy path существует.
- Normal user blocked test есть или будет добавлен.
- Invalid status transition test есть или будет добавлен.
- Tests fail before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-408: Test moderator can approve pending post`

### Files likely touched

```txt
tests/Feature/Actions/ApprovePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-409 — Implement Post Approval

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-409-implement-post-approval`  
**Base branch:** develop
**Depends on:** RG-408

### Goal

Реализовать approval pending post.

### TDD step

Использовать падающие тесты из RG-408.

### Implementation

Создать exception:

```txt
app/Exceptions/Moderation/CannotModeratePostException.php
```

Example:

```php
final class CannotModeratePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to moderate posts.');
    }

    public static function becausePostStatusIsInvalid(): self
    {
        return new self('Post status is invalid for this moderation action.');
    }
}
```

В `ApprovePostAction`:

```php
if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
    throw CannotModeratePostException::becauseUserIsNotAllowed();
}

if ($post->status !== PostStatus::Pending) {
    throw CannotModeratePostException::becausePostStatusIsInvalid();
}

$post->forceFill([
    'status' => PostStatus::Published,
    'published_at' => $post->published_at ?? now(),
    'needs_review' => false,
])->save();
```

Если `needs_review` column отсутствует, не использовать его.  
Если moderation metadata columns есть, обновить их.

Log будет добавлен позже RG-427, не в этой задаче.

### Acceptance criteria

- Moderator can approve pending post.
- Admin can approve pending post, если test добавлен.
- Normal user cannot approve.
- Non-pending post cannot be approved.
- published_at is set.
- No moderation log required yet until RG-427.
- Tests pass.

### Definition of Done

- Approval logic добавлена.
- Guards добавлены.
- Tests pass.
- Коммит: `RG-409: Implement post approval`

### Files likely touched

```txt
app/Actions/Moderation/ApprovePostAction.php
app/Exceptions/Moderation/CannotModeratePostException.php
tests/Feature/Actions/ApprovePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-410 — Create RejectPostAction Skeleton

**Area:** Backend / Moderation  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-410-create-reject-post-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-409

### Goal

Создать skeleton action для rejection pending post.

### TDD step

Unit test:

```php
it('has reject post action with handle method', function () {
    $action = app(RejectPostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

### Implementation

Создать:

```txt
app/Actions/Moderation/RejectPostAction.php
```

Skeleton:

```php
final class RejectPostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

### Acceptance criteria

- `RejectPostAction` exists.
- `handle(...)` exists.
- Action resolves.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Коммит: `RG-410: Create RejectPostAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/RejectPostAction.php
tests/Unit/Actions/RejectPostActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-411 — Test Moderator Can Reject Pending Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-411-test-moderator-can-reject-pending-post`  
**Base branch:** develop
**Depends on:** RG-410

### Goal

Написать падающий тест: moderator может reject pending post.

### TDD step

Feature/action test:

```php
it('allows moderator to reject pending post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    app(RejectPostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Image does not match rules.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Rejected);
});
```

Safety tests:

```php
normal user cannot reject pending post
published post cannot be rejected by this action
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Moderator rejection test exists.
- Permission guard test exists or planned.
- Invalid status transition test exists or planned.
- Tests fail before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-411: Test moderator can reject pending post`

### Files likely touched

```txt
tests/Feature/Actions/RejectPostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-412 — Implement Post Rejection

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-412-implement-post-rejection`  
**Base branch:** develop
**Depends on:** RG-411

### Goal

Реализовать rejection pending post.

### TDD step

Использовать падающие тесты из RG-411.

### Implementation

В `RejectPostAction`:

```php
if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
    throw CannotModeratePostException::becauseUserIsNotAllowed();
}

if ($post->status !== PostStatus::Pending) {
    throw CannotModeratePostException::becausePostStatusIsInvalid();
}

$post->forceFill([
    'status' => PostStatus::Rejected,
    'needs_review' => false,
])->save();
```

Если columns есть:

```txt
rejected_at
rejected_by_user_id
rejection_reason
```

заполнить их.

Log будет RG-427.

### Acceptance criteria

- Moderator can reject pending post.
- Admin can reject pending post, если test добавлен.
- Normal user cannot reject.
- Non-pending post cannot be rejected.
- Post is not deleted.
- Tests pass.

### Definition of Done

- Rejection logic добавлена.
- Guards добавлены.
- Tests pass.
- Коммит: `RG-412: Implement post rejection`

### Files likely touched

```txt
app/Actions/Moderation/RejectPostAction.php
tests/Feature/Actions/RejectPostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-413 — Create HidePostAction Skeleton

**Area:** Backend / Moderation  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-413-create-hide-post-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-412

### Goal

Создать skeleton action для hiding published post.

### TDD step

Unit test:

```php
it('has hide post action with handle method', function () {
    $action = app(HidePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

### Implementation

Создать:

```txt
app/Actions/Moderation/HidePostAction.php
```

Skeleton:

```php
final class HidePostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

### Acceptance criteria

- `HidePostAction` exists.
- `handle(...)` exists.
- Action resolves.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Коммит: `RG-413: Create HidePostAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/HidePostAction.php
tests/Unit/Actions/HidePostActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-414 — Test Moderator Can Hide Published Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-414-test-moderator-can-hide-published-post`  
**Base branch:** develop
**Depends on:** RG-413

### Goal

Написать падающий тест: moderator может hide published post.

### TDD step

Feature/action test:

```php
it('allows moderator to hide published post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    app(HidePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Reported content.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});
```

Safety tests:

```php
normal user cannot hide published post
pending post cannot be hidden by this action
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Moderator hide test exists.
- Permission guard test exists or planned.
- Invalid status transition test exists or planned.
- Tests fail before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-414: Test moderator can hide published post`

### Files likely touched

```txt
tests/Feature/Actions/HidePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-415 — Implement Post Hiding

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-415-implement-post-hiding`  
**Base branch:** develop
**Depends on:** RG-414

### Goal

Реализовать hiding published post.

### TDD step

Использовать падающие тесты из RG-414.

### Implementation

В `HidePostAction`:

```php
if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
    throw CannotModeratePostException::becauseUserIsNotAllowed();
}

if ($post->status !== PostStatus::Published) {
    throw CannotModeratePostException::becausePostStatusIsInvalid();
}

$post->forceFill([
    'status' => PostStatus::Hidden,
])->save();
```

Если columns есть:

```txt
hidden_at
hidden_by_user_id
hide_reason
```

заполнить их.

### Acceptance criteria

- Moderator can hide published post.
- Admin can hide published post, если test добавлен.
- Normal user cannot hide.
- Non-published post cannot be hidden.
- Hidden post disappears from published-only queries via existing scopes.
- Tests pass.

### Definition of Done

- Hiding logic добавлена.
- Guards добавлены.
- Tests pass.
- Коммит: `RG-415: Implement post hiding`

### Files likely touched

```txt
app/Actions/Moderation/HidePostAction.php
tests/Feature/Actions/HidePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-416 — Create RestorePostAction Skeleton

**Area:** Backend / Moderation  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-416-create-restore-post-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-415

### Goal

Создать skeleton action для restoring hidden post.

### TDD step

Unit test:

```php
it('has restore post action with handle method', function () {
    $action = app(RestorePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

### Implementation

Создать:

```txt
app/Actions/Moderation/RestorePostAction.php
```

Skeleton:

```php
final class RestorePostAction
{
    public function handle(User $moderator, Post $post, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

### Acceptance criteria

- `RestorePostAction` exists.
- `handle(...)` exists.
- Action resolves.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Коммит: `RG-416: Create RestorePostAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/RestorePostAction.php
tests/Unit/Actions/RestorePostActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-417 — Test Moderator Can Restore Hidden Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-417-test-moderator-can-restore-hidden-post`  
**Base branch:** develop
**Depends on:** RG-416

### Goal

Написать падающий тест: moderator может restore hidden post.

### TDD step

Feature/action test:

```php
it('allows moderator to restore hidden post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->hidden()->create();

    app(RestorePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Reviewed and restored.'
    );

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
```

Safety tests:

```php
normal user cannot restore hidden post
pending/rejected post cannot be restored
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Moderator restore test exists.
- Permission guard test exists or planned.
- Invalid status transition test exists or planned.
- Tests fail before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-417: Test moderator can restore hidden post`

### Files likely touched

```txt
tests/Feature/Actions/RestorePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-418 — Implement Post Restore

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-418-implement-post-restore`  
**Base branch:** develop
**Depends on:** RG-417

### Goal

Реализовать restoring hidden post.

### TDD step

Использовать падающие тесты из RG-417.

### Implementation

В `RestorePostAction`:

```php
if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
    throw CannotModeratePostException::becauseUserIsNotAllowed();
}

if ($post->status !== PostStatus::Hidden) {
    throw CannotModeratePostException::becausePostStatusIsInvalid();
}

$post->forceFill([
    'status' => PostStatus::Published,
    'needs_review' => false,
])->save();
```

Если columns есть:

```txt
hidden_at = null
hidden_by_user_id = null
hide_reason = null
```

Ставить `published_at`, если пустой:

```php
'published_at' => $post->published_at ?? now()
```

### Acceptance criteria

- Moderator can restore hidden post.
- Admin can restore hidden post, если test добавлен.
- Normal user cannot restore.
- Only hidden post can be restored.
- Restored post becomes published.
- Tests pass.

### Definition of Done

- Restore logic добавлена.
- Guards добавлены.
- Tests pass.
- Коммит: `RG-418: Implement post restore`

### Files likely touched

```txt
app/Actions/Moderation/RestorePostAction.php
tests/Feature/Actions/RestorePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-419 — Create BanUserAction Skeleton

**Area:** Backend / Moderation  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-419-create-ban-user-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-418

### Goal

Создать skeleton action для banning user.

### TDD step

Unit test:

```php
it('has ban user action with handle method', function () {
    $action = app(BanUserAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

### Implementation

Создать:

```txt
app/Actions/Moderation/BanUserAction.php
```

Skeleton:

```php
final class BanUserAction
{
    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

### Acceptance criteria

- `BanUserAction` exists.
- `handle(User $admin, User $target, ?string $reason = null): void` exists.
- Action resolves.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Коммит: `RG-419: Create BanUserAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/BanUserAction.php
tests/Unit/Actions/BanUserActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-420 — Test Admin Can Ban User

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-420-test-admin-can-ban-user`  
**Base branch:** develop
**Depends on:** RG-419

### Goal

Написать падающий тест: admin может ban user.

### TDD step

Feature/action test:

```php
it('allows admin to ban user', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    app(BanUserAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Repeated abuse.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
});
```

Safety tests:

```php
moderator cannot ban user
normal user cannot ban user
admin cannot ban self
admin cannot ban another admin
```

Не все safety tests обязаны быть в RG-420, но хотя бы moderator cannot ban — обязательно.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Admin ban test exists.
- Moderator cannot ban test exists or planned.
- No side effects when forbidden.
- Tests fail before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-420: Test admin can ban user`

### Files likely touched

```txt
tests/Feature/Actions/BanUserActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-421 — Implement User Ban

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-421-implement-user-ban`  
**Base branch:** develop
**Depends on:** RG-420

### Goal

Реализовать user ban.

### TDD step

Использовать падающие тесты из RG-420.

### Implementation

Создать exception:

```txt
app/Exceptions/Moderation/CannotModerateUserException.php
```

Example:

```php
final class CannotModerateUserException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to moderate users.');
    }

    public static function becauseTargetIsProtected(): self
    {
        return new self('Target user cannot be moderated.');
    }
}
```

В `BanUserAction`:

```php
if (! $admin->isAdmin()) {
    throw CannotModerateUserException::becauseUserIsNotAllowed();
}

if ($admin->id === $target->id) {
    throw CannotModerateUserException::becauseTargetIsProtected();
}

if ($target->isAdmin()) {
    throw CannotModerateUserException::becauseTargetIsProtected();
}

$target->forceFill([
    'status' => UserStatus::Banned,
])->save();
```

Если columns есть:

```txt
banned_at
banned_by_user_id
ban_reason
```

заполнить.

Log будет RG-428.

### Acceptance criteria

- Admin can ban normal user.
- Moderator cannot ban user.
- Normal user cannot ban user.
- Admin cannot ban self.
- Admin cannot ban another admin.
- Target status becomes banned.
- Tests pass.

### Definition of Done

- Ban logic добавлена.
- Guards добавлены.
- Tests pass.
- Коммит: `RG-421: Implement user ban`

### Files likely touched

```txt
app/Actions/Moderation/BanUserAction.php
app/Exceptions/Moderation/CannotModerateUserException.php
tests/Feature/Actions/BanUserActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-422 — Create ShadowbanUserAction Skeleton

**Area:** Backend / Moderation  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-422-create-shadowban-user-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-421

### Goal

Создать skeleton action для shadowban user.

### TDD step

Unit test:

```php
it('has shadowban user action with handle method', function () {
    $action = app(ShadowbanUserAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

### Implementation

Создать:

```txt
app/Actions/Moderation/ShadowbanUserAction.php
```

Skeleton:

```php
final class ShadowbanUserAction
{
    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

### Acceptance criteria

- `ShadowbanUserAction` exists.
- `handle(...)` exists.
- Action resolves.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Коммит: `RG-422: Create ShadowbanUserAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/ShadowbanUserAction.php
tests/Unit/Actions/ShadowbanUserActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-423 — Test Admin Can Shadowban User

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-423-test-admin-can-shadowban-user`  
**Base branch:** develop
**Depends on:** RG-422

### Goal

Написать падающий тест: admin может shadowban user.

### TDD step

Feature/action test:

```php
it('allows admin to shadowban user', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    app(ShadowbanUserAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Suspicious behavior.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Shadowbanned);
});
```

Safety tests:

```php
moderator cannot shadowban user
admin cannot shadowban self
admin cannot shadowban another admin
```

Если `UserStatus::Shadowbanned` ещё нет, тест сначала упадёт на enum.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Admin shadowban test exists.
- Moderator cannot shadowban test exists or planned.
- Protected target test exists or planned.
- Tests fail before implementation.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-423: Test admin can shadowban user`

### Files likely touched

```txt
tests/Feature/Actions/ShadowbanUserActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-424 — Implement User Shadowban

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-424-implement-user-shadowban`  
**Base branch:** develop
**Depends on:** RG-423

### Goal

Реализовать user shadowban.

### TDD step

Использовать падающие тесты из RG-423.

### Implementation

Если enum value отсутствует, добавить:

```php
UserStatus::Shadowbanned
```

В `ShadowbanUserAction`:

```php
if (! $admin->isAdmin()) {
    throw CannotModerateUserException::becauseUserIsNotAllowed();
}

if ($admin->id === $target->id || $target->isAdmin()) {
    throw CannotModerateUserException::becauseTargetIsProtected();
}

$target->forceFill([
    'status' => UserStatus::Shadowbanned,
])->save();
```

Если columns есть:

```txt
shadowbanned_at
shadowbanned_by_user_id
shadowban_reason
```

заполнить.

Не менять CreatePostAction/AddCommentAction behavior в этой задаче, если оно не было явно запланировано.

### Acceptance criteria

- Admin can shadowban normal user.
- Moderator cannot shadowban.
- Normal user cannot shadowban.
- Admin cannot shadowban self.
- Admin cannot shadowban another admin.
- User status becomes shadowbanned.
- No content behavior changes hidden inside this task.
- Tests pass.

### Definition of Done

- Shadowban logic добавлена.
- Guards добавлены.
- Tests pass.
- Коммит: `RG-424: Implement user shadowban`

### Files likely touched

```txt
app/Actions/Moderation/ShadowbanUserAction.php
app/Enums/UserStatus.php
tests/Feature/Actions/ShadowbanUserActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-425 — Create CreateModerationLogAction Skeleton

**Area:** Backend / Audit  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-425-create-create-moderation-log-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-424

### Goal

Создать skeleton action для записи moderation logs.

### TDD step

Unit test:

```php
it('has create moderation log action with handle method', function () {
    $action = app(CreateModerationLogAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

### Implementation

Создать:

```txt
app/Actions/Moderation/CreateModerationLogAction.php
app/Enums/ModerationActionType.php
```

Skeleton:

```php
final class CreateModerationLogAction
{
    public function handle(
        User $moderator,
        ModerationActionType $action,
        Model $target,
        ?string $reason = null,
        array $metadata = [],
    ): ModerationLog {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Enum values:

```txt
ApprovePost
RejectPost
HidePost
RestorePost
BanUser
ShadowbanUser
```

Не реализовывать creation пока.

### Acceptance criteria

- `CreateModerationLogAction` exists.
- `ModerationActionType` exists.
- `handle(...)` exists.
- Action resolves.
- Test passes.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Enum создан.
- Коммит: `RG-425: Create CreateModerationLogAction skeleton`

### Files likely touched

```txt
app/Actions/Moderation/CreateModerationLogAction.php
app/Enums/ModerationActionType.php
tests/Unit/Actions/CreateModerationLogActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-426 — Test Moderation Action Creates Log

**Area:** Backend / Audit / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-426-test-moderation-action-creates-log`  
**Base branch:** develop
**Depends on:** RG-425

### Goal

Написать тест: CreateModerationLogAction создаёт log.

### TDD step

Feature/action test:

```php
it('creates moderation log', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $log = app(CreateModerationLogAction::class)->handle(
        moderator: $moderator,
        action: ModerationActionType::HidePost,
        target: $post,
        reason: 'Reported content.',
        metadata: ['source' => 'test']
    );

    expect($log)->toBeInstanceOf(ModerationLog::class);
    expect($log->moderator_id)->toBe($moderator->id);
    expect($log->action)->toBe(ModerationActionType::HidePost);
    expect($log->moderatable_type)->toBe(Post::class);
    expect($log->moderatable_id)->toBe($post->id);
    expect($log->reason)->toBe('Reported content.');
    expect($log->metadata)->toMatchArray(['source' => 'test']);
});
```

Если schema uses different names, adjust but preserve semantics.

### Implementation

В `CreateModerationLogAction`:

```php
return ModerationLog::create([
    'moderator_id' => $moderator->id,
    'action' => $action,
    'moderatable_type' => $target::class,
    'moderatable_id' => $target->id,
    'reason' => $reason ? trim($reason) : null,
    'metadata' => $metadata,
]);
```

If `moderation_logs` table lacks fields, add migration to align with action.

### Acceptance criteria

- Log row created.
- Moderator stored.
- Action type stored.
- Target polymorphic type/id stored.
- Reason stored trimmed/null.
- Metadata stored.
- Test passes.

### Definition of Done

- Тест написан.
- Log creation implemented.
- Test passes.
- Коммит: `RG-426: Test moderation action creates log`

### Files likely touched

```txt
app/Actions/Moderation/CreateModerationLogAction.php
app/Models/ModerationLog.php
database/migrations/*update_moderation_logs_table.php
tests/Feature/Actions/CreateModerationLogActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-427 — Write Moderation Logs From Post Actions

**Area:** Backend / Audit / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-427-write-moderation-logs-from-post-actions`  
**Base branch:** develop
**Depends on:** RG-426

### Goal

Подключить moderation logs ко всем post moderation actions.

### TDD step

Feature/action tests:

```php
it('writes moderation log when approving post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    app(ApprovePostAction::class)->handle($moderator, $post, 'Valid post.');

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'action' => ModerationActionType::ApprovePost->value,
        'moderatable_type' => Post::class,
        'moderatable_id' => $post->id,
    ]);
});
```

Repeat for:

```txt
reject_post
hide_post
restore_post
```

Also test forbidden action does not log:

```php
normal user failed approve should not create moderation log
```

### Implementation

Inject `CreateModerationLogAction` into each post action:

```php
public function __construct(
    private readonly CreateModerationLogAction $createModerationLog,
) {}
```

After successful status change:

```php
$this->createModerationLog->handle(
    moderator: $moderator,
    action: ModerationActionType::ApprovePost,
    target: $post,
    reason: $reason,
    metadata: [
        'from_status' => PostStatus::Pending->value,
        'to_status' => PostStatus::Published->value,
    ],
);
```

Do this for:

```txt
ApprovePostAction
RejectPostAction
HidePostAction
RestorePostAction
```

### Acceptance criteria

- Approve writes log.
- Reject writes log.
- Hide writes log.
- Restore writes log.
- Reason passed to log.
- Metadata includes from/to status.
- Failed/forbidden actions do not write log.
- Tests pass.

### Definition of Done

- Post moderation actions write logs.
- Tests pass.
- Коммит: `RG-427: Write moderation logs from post actions`

### Files likely touched

```txt
app/Actions/Moderation/ApprovePostAction.php
app/Actions/Moderation/RejectPostAction.php
app/Actions/Moderation/HidePostAction.php
app/Actions/Moderation/RestorePostAction.php
tests/Feature/Actions/ApprovePostActionTest.php
tests/Feature/Actions/RejectPostActionTest.php
tests/Feature/Actions/HidePostActionTest.php
tests/Feature/Actions/RestorePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-428 — Write Moderation Logs From User Actions

**Area:** Backend / Audit / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-428-write-moderation-logs-from-user-actions`  
**Base branch:** develop
**Depends on:** RG-427

### Goal

Подключить moderation logs к user moderation actions.

### TDD step

Feature/action tests:

```php
it('writes moderation log when banning user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    app(BanUserAction::class)->handle($admin, $target, 'Abuse.');

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::BanUser->value,
        'moderatable_type' => User::class,
        'moderatable_id' => $target->id,
    ]);
});
```

Shadowban test:

```php
it('writes moderation log when shadowbanning user', ...)
```

Forbidden test:

```php
moderator failed ban should not create moderation log
```

### Implementation

Inject `CreateModerationLogAction` into:

```txt
BanUserAction
ShadowbanUserAction
```

After successful status change:

```php
$this->createModerationLog->handle(
    moderator: $admin,
    action: ModerationActionType::BanUser,
    target: $target,
    reason: $reason,
    metadata: [
        'from_status' => $oldStatus->value,
        'to_status' => UserStatus::Banned->value,
    ],
);
```

Same for shadowban.

### Acceptance criteria

- Ban writes log.
- Shadowban writes log.
- Reason passed to log.
- Metadata includes from/to status.
- Failed/forbidden user actions do not write log.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- User moderation actions write logs.
- Tests pass.
- Build passes.
- Коммит: `RG-428: Write moderation logs from user actions`

### Files likely touched

```txt
app/Actions/Moderation/BanUserAction.php
app/Actions/Moderation/ShadowbanUserAction.php
tests/Feature/Actions/BanUserActionTest.php
tests/Feature/Actions/ShadowbanUserActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 21 Completion Criteria

Phase 21 завершена, когда:

```txt
- RG-407–RG-428 выполнены;
- ApprovePostAction exists;
- moderator/admin can approve pending post;
- invalid approve transitions are blocked;
- RejectPostAction exists;
- moderator/admin can reject pending post;
- invalid reject transitions are blocked;
- HidePostAction exists;
- moderator/admin can hide published post;
- invalid hide transitions are blocked;
- RestorePostAction exists;
- moderator/admin can restore hidden post;
- invalid restore transitions are blocked;
- BanUserAction exists;
- admin can ban normal user;
- moderator cannot ban user;
- admin cannot ban self/admin, if safety tests added;
- ShadowbanUserAction exists;
- admin can shadowban normal user;
- moderator cannot shadowban user;
- CreateModerationLogAction exists;
- moderation log is created with moderator/action/target/reason/metadata;
- post moderation actions write logs;
- user moderation actions write logs;
- failed moderation actions do not write logs;
- no Inline Moderation UI was added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 21

Без отдельной задачи нельзя:

```txt
- создавать InlinePostModeration Livewire component;
- добавлять approve/hide/reject/restore buttons;
- добавлять confirmation modal;
- добавлять moderation reason input UI;
- создавать Filament admin resources;
- создавать moderation dashboard;
- resolve report UI;
- notification dispatching;
- email alerts;
- automatic moderation;
- AI moderation;
- rate limiting;
- queue jobs;
- API endpoint;
- Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-407 Create ApprovePostAction skeleton
RG-408 Test moderator can approve pending post
RG-409 Implement post approval
RG-410 Create RejectPostAction skeleton
RG-411 Test moderator can reject pending post
RG-412 Implement post rejection
RG-413 Create HidePostAction skeleton
RG-414 Test moderator can hide published post
RG-415 Implement post hiding
RG-416 Create RestorePostAction skeleton
RG-417 Test moderator can restore hidden post
RG-418 Implement post restore
RG-419 Create BanUserAction skeleton
RG-420 Test admin can ban user
RG-421 Implement user ban
RG-422 Create ShadowbanUserAction skeleton
RG-423 Test admin can shadowban user
RG-424 Implement user shadowban
RG-425 Create CreateModerationLogAction skeleton
RG-426 Test moderation action creates log
RG-427 Write moderation logs from post actions
RG-428 Write moderation logs from user actions
```
---

# 13. Release

После завершения Phase 21:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.2-phase21-moderation-backend
git push -u origin release/v0.2.2-phase21-moderation-backend
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.2-phase21-moderation-backend -m "RateGuru Phase 21 moderation backend"
git push origin v0.2.2-phase21-moderation-backend
```
