# RateGuru — Phase 17 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 17 — Comments Backend**  
Диапазон задач: **RG-333 → RG-354**  
Основа нумерации: исходный atomic backlog, где Phase 17 начинается с задачи 333 и заканчивается задачей 354.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 17 соответствует исходному блоку:

```txt
Phase 17 — Comments Backend
```

Правильный диапазон Phase 17:

```txt
RG-333 — Create AddCommentAction skeleton
RG-334 — Test user can add comment
RG-335 — Implement comment creation
RG-336 — Test guest cannot add comment
RG-337 — Add auth guard for comments
RG-338 — Test banned user cannot comment
RG-339 — Add banned guard for comments
RG-340 — Test hidden post cannot receive comment
RG-341 — Add post status guard for comments
RG-342 — Test empty comment is rejected
RG-343 — Add body validation
RG-344 — Test too long comment is rejected
RG-345 — Add comment length validation
RG-346 — Test comments_count increments
RG-347 — Implement comments_count increment
RG-348 — Create DeleteCommentAction skeleton
RG-349 — Test user can delete own comment
RG-350 — Implement own comment deletion
RG-351 — Test user cannot delete other user comment
RG-352 — Add CommentPolicy delete rule
RG-353 — Test moderator can hide comment
RG-354 — Implement HideCommentAction
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 17

Phase 17 добавляет backend-логику комментариев без UI.

После Phase 17 должно быть готово:

```txt
- AddCommentAction;
- создание комментария authenticated active user;
- guest guard;
- banned user guard;
- published-only post guard;
- body validation;
- max length validation;
- comments_count update;
- DeleteCommentAction;
- own comment deletion;
- запрет удаления чужого comment;
- CommentPolicy delete rule;
- HideCommentAction;
- moderator hide comment.
```

UI комментариев будет в Phase 18.  
Reports для comment будут в Phase 19.  
Filament comments admin будет в Phase 26.
---

# 3. Scope Phase 17

## Входит

```txt
- AddCommentAction;
- DeleteCommentAction;
- HideCommentAction;
- CannotCommentException;
- CannotDeleteCommentException;
- CannotHideCommentException;
- body validation на уровне action;
- comments_count update;
- CommentPolicy delete rule;
- tests for guards and rules.
```

## Не входит

```txt
- CommentsSection Livewire component;
- CommentForm Livewire component;
- CommentItem Blade component;
- comments list UI;
- comment validation rendering in UI;
- delete button UI;
- moderator hide button UI;
- reports for comments;
- notifications after comment;
- comments_count fallback recalculation command;
- nested comments/replies;
- editing comments;
- likes on comments.
```

Phase 18 делает UI.  
Phase 19 делает reports backend.  
Phase 31 делает notification после comment, если понадобится.
---

# 4. Product / Domain Decisions

## 4.1. Comments are allowed only on published posts

Комментировать можно только:

```txt
PostStatus::Published
```

Нельзя комментировать:

```txt
pending
hidden
rejected
draft
deleted
```

Даже если UI не показывает форму, backend action обязан блокировать.

## 4.2. Guest cannot comment

Гость может читать feed/post page, но не может создать comment.

`AddCommentAction` должен принимать nullable user:

```php
handle(?User $user, Post $post, string $body): Comment
```

и бросать explicit exception для guest.

## 4.3. Banned user cannot comment

Комментарий — это content creation, а не vote. Не нужно использовать `canVote()`.

Рекомендуемое правило:

```php
$user->canComment()
```

Если такого метода нет, добавить:

```php
public function canComment(): bool
{
    return $this->status === UserStatus::Active;
}
```

## 4.4. Empty comment is invalid

Invalid:

```txt
''
'   '
"\n\t"
```

Body должен trim-иться перед сохранением.

## 4.5. Max comment length

Фиксируем максимальную длину:

```txt
1000 characters
```

Это достаточно для MVP. Длинные обзоры, markdown и rich-text — отдельные будущие функции.

## 4.6. Delete own comment vs moderator hide comment

Это разные операции:

```txt
Delete own comment:
- выполняет автор;
- удаляет комментарий как пользовательское действие;
- лучше через SoftDeletes, если comments table уже поддерживает deleted_at;
- comments_count уменьшается / пересчитывается.

Moderator hide comment:
- выполняет moderator/admin;
- меняет status на hidden;
- не физически удаляет;
- comments_count должен считать только visible comments, значит при hide visible comment comments_count уменьшается / пересчитывается.
```

Если soft deletes в comments table ещё не добавлены, в RG-350 можно добавить маленькую migration `deleted_at` для comments. Это не UI и не новая продуктовая функция, а техническая необходимость для корректного own delete.

## 4.7. comments_count should count visible, non-deleted comments

`posts.comments_count` должен отражать публичные видимые comments:

```txt
comments where post_id = X and status = visible and deleted_at is null
```

Если soft deletes нет, условие `deleted_at is null` не используется.

В Phase 17 допускается приватный helper внутри actions для пересчёта comments_count. Отдельный глобальный counter command не входит в эту фазу.
---

# 5. Architecture Rules

## 5.1. AddCommentAction owns comment creation

Нельзя создавать comments напрямую в Livewire/UI в Phase 18.

Правильно:

```php
app(AddCommentAction::class)->handle($user, $post, $body);
```

## 5.2. DeleteCommentAction owns own deletion

Нельзя удалять comment напрямую из UI:

```php
$comment->delete()
```

Правильно:

```php
app(DeleteCommentAction::class)->handle($user, $comment);
```

## 5.3. HideCommentAction owns moderator hiding

Нельзя модерировать comments через прямой model update:

```php
$comment->update(['status' => 'hidden']);
```

Правильно:

```php
app(HideCommentAction::class)->handle($moderator, $comment);
```

## 5.4. Policies are authorization layer, actions are business layer

`CommentPolicy::delete()` отвечает на вопрос:

```txt
может ли user удалить этот comment?
```

`DeleteCommentAction` отвечает за:

```txt
проверку policy;
удаление;
обновление comments_count.
```

Не складывать всю бизнес-логику в policy.

## 5.5. No UI in Phase 17

Не создавать:

```txt
CommentsSection
CommentForm
CommentItem
Blade components
Livewire comment UI
```

Это Phase 18.
---

# 6. GitFlow для Phase 17

## Base branch

Все задачи Phase 17 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-333-create-add-comment-action-skeleton
feature/RG-347-implement-comments-count-increment
feature/RG-354-implement-hide-comment-action
```

## Commit format

```txt
RG-333: Create AddCommentAction skeleton
RG-347: Implement comments_count increment
RG-354: Implement HideCommentAction
```

## Release branch

После выполнения `RG-333`–`RG-354`:

```txt
release/v0.1.8-phase17-comments-backend
```

## Tag

После merge release branch в `main`:

```txt
v0.1.8-phase17-comments-backend
```
---

# 7. TDD Rules for Phase 17

## Для AddCommentAction

Каждое поведение test-first:

```txt
- active user can add comment;
- guest cannot add comment;
- banned user cannot comment;
- hidden post cannot receive comment;
- empty comment rejected;
- too long comment rejected;
- comments_count increments/recalculates.
```

## Для DeleteCommentAction

Test-first:

```txt
- user can delete own comment;
- user cannot delete other user's comment;
- comments_count updates after deletion.
```

Хотя backlog не содержит отдельную задачу для decrement, это обязательный side effect, иначе `comments_count` будет неправильным сразу после удаления.

## Для HideCommentAction

Test-first:

```txt
- moderator can hide comment;
- normal user cannot hide comment;
- comment status becomes hidden;
- comments_count updates after hiding visible comment.
```

## Для validation

Action должен валидировать body независимо от будущего UI/FormRequest.  
Phase 18 UI может иметь свои validation messages, но backend должен быть защищён сам.
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Policy / Tests
Type: Test / Feature / Action / Policy / Validation
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

# 9. Phase 17 Atomic Tasks
---

## RG-333 — Create AddCommentAction Skeleton

**Area:** Backend  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-333-create-add-comment-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-332

### Goal

Создать skeleton action для добавления комментария.

### TDD step

Unit test:

```php
it('has add comment action with handle method', function () {
    $action = app(AddCommentAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Comments/AddCommentAction.php
```

Skeleton:

```php
namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

final class AddCommentAction
{
    public function handle(?User $user, Post $post, string $body): Comment
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Сигнатура сразу nullable user, чтобы guest guard в RG-337 был чистым.

### Acceptance criteria

- `AddCommentAction` существует.
- Есть `handle(?User $user, Post $post, string $body): Comment`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-333: Create AddCommentAction skeleton`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Unit/Actions/AddCommentActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-334 — Test User Can Add Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-334-test-user-can-add-comment`  
**Base branch:** develop
**Depends on:** RG-333

### Goal

Написать падающий тест: authenticated active user может добавить comment к published post.

### TDD step

Feature/action test:

```php
it('allows user to add comment to published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: 'Looks delicious.'
    );

    expect($comment)->toBeInstanceOf(Comment::class);
    expect($comment->exists)->toBeTrue();
    expect($comment->user_id)->toBe($user->id);
    expect($comment->post_id)->toBe($post->id);
    expect($comment->body)->toBe('Looks delicious.');

    $this->assertDatabaseHas('comments', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Looks delicious.',
    ]);
});
```

Тест должен упасть до RG-335.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет created Comment.
- Тест проверяет user_id/post_id/body.
- Тест проверяет database row.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-334: Test user can add comment`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-335 — Implement Comment Creation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-335-implement-comment-creation`  
**Base branch:** develop
**Depends on:** RG-334

### Goal

Реализовать создание comment.

### TDD step

Использовать падающий тест из RG-334.

### Implementation

В `AddCommentAction::handle()`:

```php
return Comment::create([
    'user_id' => $user->id,
    'post_id' => $post->id,
    'body' => trim($body),
    'status' => CommentStatus::Visible,
]);
```

Если `CommentStatus` enum ещё не существует, но comment status column есть как string:

```php
'status' => 'visible',
```

Пока guards и validation будут добавлены в следующих задачах.

### Acceptance criteria

- Active user can create comment.
- Body trimmed before save.
- Comment has user_id/post_id/body.
- Default status = visible, если status field есть.
- Тест RG-334 проходит.
- Нет comments_count update пока, это RG-347.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-335: Implement comment creation`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-336 — Test Guest Cannot Add Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-336-test-guest-cannot-add-comment`  
**Base branch:** develop
**Depends on:** RG-335

### Goal

Написать тест: guest не может добавить comment.

### TDD step

Feature/action test:

```php
it('does not allow guest to add comment', function () {
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle(
        user: null,
        post: $post,
        body: 'Guest comment'
    );
})->throws(CannotCommentException::class);
```

Дополнительно проверить no side effects:

```php
expect(Comment::query()->count())->toBe(0);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Guest/null user получает explicit exception.
- Comment row не создаётся.
- Тест падает до RG-337.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-336: Test guest cannot add comment`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-337 — Add Auth Guard For Comments

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-337-add-auth-guard-for-comments`  
**Base branch:** develop
**Depends on:** RG-336

### Goal

Добавить auth guard в `AddCommentAction`.

### TDD step

Использовать падающий тест из RG-336.

### Implementation

Создать exception:

```txt
app/Exceptions/Comments/CannotCommentException.php
```

Пример:

```php
namespace App\Exceptions\Comments;

use DomainException;

final class CannotCommentException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot comment.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to comment.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive comments.');
    }

    public static function becauseBodyIsInvalid(string $message = 'Comment body is invalid.'): self
    {
        return new self($message);
    }
}
```

В `AddCommentAction`:

```php
if ($user === null) {
    throw CannotCommentException::becauseGuest();
}
```

### Acceptance criteria

- Guest/null user blocked.
- Explicit exception.
- No comment row created.
- Authenticated user can still comment.
- Тесты проходят.

### Definition of Done

- Exception добавлен.
- Auth guard добавлен.
- Тесты проходят.
- Коммит: `RG-337: Add auth guard for comments`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
app/Exceptions/Comments/CannotCommentException.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-338 — Test Banned User Cannot Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-338-test-banned-user-cannot-comment`  
**Base branch:** develop
**Depends on:** RG-337

### Goal

Написать тест: banned user не может добавить comment.

### TDD step

Feature/action test:

```php
it('does not allow banned user to add comment', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: 'Banned comment'
    );
})->throws(CannotCommentException::class);
```

No side effects:

```php
expect(Comment::query()->count())->toBe(0);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Banned user receives CannotCommentException.
- No comment row.
- Тест падает до RG-339.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-338: Test banned user cannot comment`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-339 — Add Banned Guard For Comments

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-339-add-banned-guard-for-comments`  
**Base branch:** develop
**Depends on:** RG-338

### Goal

Добавить guard, запрещающий banned users создавать comments.

### TDD step

Использовать падающий тест из RG-338.

### Implementation

В `User` model добавить, если ещё нет:

```php
public function canComment(): bool
{
    return $this->status === UserStatus::Active;
}
```

В `AddCommentAction`:

```php
if (! $user->canComment()) {
    throw CannotCommentException::becauseUserIsNotAllowed();
}
```

Если уже есть `canCreateContent()`, можно использовать его, но `canComment()` лучше — comments можно будет ограничивать отдельно.

### Acceptance criteria

- Banned user blocked.
- Active user can comment.
- No side effects for blocked user.
- `canComment()` exists or equivalent guard is explicit.
- Тесты проходят.

### Definition of Done

- Guard добавлен.
- Тесты проходят.
- Коммит: `RG-339: Add banned guard for comments`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
app/Models/User.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-340 — Test Hidden Post Cannot Receive Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-340-test-hidden-post-cannot-receive-comment`  
**Base branch:** develop
**Depends on:** RG-339

### Goal

Написать тест: hidden post нельзя комментировать.

### TDD step

Feature/action test:

```php
it('does not allow adding comment to hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: 'Comment on hidden post'
    );
})->throws(CannotCommentException::class);
```

Добавить pending/rejected tests желательно:

```php
it('does not allow adding comment to pending post', ...)
it('does not allow adding comment to rejected post', ...)
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Hidden post blocked.
- Pending/rejected blocked, если tests добавлены.
- No comment row.
- Тест падает до RG-341.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-340: Test hidden post cannot receive comment`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-341 — Add Post Status Guard For Comments

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-341-add-post-status-guard-for-comments`  
**Base branch:** develop
**Depends on:** RG-340

### Goal

Добавить guard: comments можно добавлять только к published posts.

### TDD step

Использовать падающие тесты из RG-340.

### Implementation

В `Post` model добавить:

```php
public function canReceiveComments(): bool
{
    return $this->status === PostStatus::Published;
}
```

В `AddCommentAction`:

```php
if (! $post->canReceiveComments()) {
    throw CannotCommentException::becausePostIsNotPublic();
}
```

Не использовать `canReceiveVotes()` напрямую: комментарии и голосования могут иметь разные правила в будущем.

### Acceptance criteria

- Published post can receive comments.
- Hidden/pending/rejected cannot.
- No side effects on blocked posts.
- Тесты проходят.

### Definition of Done

- Post status guard добавлен.
- Тесты проходят.
- Коммит: `RG-341: Add post status guard for comments`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
app/Models/Post.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-342 — Test Empty Comment Is Rejected

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-342-test-empty-comment-is-rejected`  
**Base branch:** develop
**Depends on:** RG-341

### Goal

Написать тест: пустой comment body rejected.

### TDD step

Feature/action test:

```php
it('rejects empty comment body', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: ''
    );
})->throws(CannotCommentException::class);
```

Whitespace test:

```php
it('rejects whitespace only comment body', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: " \n\t "
    );
})->throws(CannotCommentException::class);
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Empty body rejected.
- Whitespace-only body rejected.
- No comment row.
- Тесты падают до RG-343.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-342: Test empty comment is rejected`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-343 — Add Body Validation

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-343-add-body-validation`  
**Base branch:** develop
**Depends on:** RG-342

### Goal

Добавить validation body в `AddCommentAction`.

### TDD step

Использовать падающие тесты из RG-342.

### Implementation

В `AddCommentAction`:

```php
$body = trim($body);

if ($body === '') {
    throw CannotCommentException::becauseBodyIsInvalid('Comment body is required.');
}
```

После validation сохранять trimmed body:

```php
'body' => $body,
```

Добавить test, если ещё нет:

```php
it('trims comment body before saving', function () {
    $comment = app(AddCommentAction::class)->handle($user, $post, '  Nice  ');
    expect($comment->body)->toBe('Nice');
});
```

### Acceptance criteria

- Empty body rejected.
- Whitespace-only body rejected.
- Body trimmed before save.
- Non-empty body accepted.
- Тесты проходят.

### Definition of Done

- Body validation добавлена.
- Тесты проходят.
- Коммит: `RG-343: Add body validation`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-344 — Test Too Long Comment Is Rejected

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-344-test-too-long-comment-is-rejected`  
**Base branch:** develop
**Depends on:** RG-343

### Goal

Написать тест: слишком длинный comment rejected.

### TDD step

Feature/action test:

```php
it('rejects too long comment body', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: str_repeat('a', 1001)
    );
})->throws(CannotCommentException::class);
```

Boundary test:

```php
it('allows comment body at max length', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $comment = app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: str_repeat('a', 1000)
    );

    expect($comment->body)->toHaveLength(1000);
});
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- 1001 chars rejected.
- 1000 chars accepted.
- No row for rejected comment.
- Тесты падают до RG-345.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-344: Test too long comment is rejected`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-345 — Add Comment Length Validation

**Area:** Backend / Validation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-345-add-comment-length-validation`  
**Base branch:** develop
**Depends on:** RG-344

### Goal

Добавить max length validation для comment body.

### TDD step

Использовать падающие тесты из RG-344.

### Implementation

В `AddCommentAction`:

```php
private const MAX_BODY_LENGTH = 1000;
```

Validation:

```php
if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
    throw CannotCommentException::becauseBodyIsInvalid('Comment body is too long.');
}
```

Использовать `mb_strlen`, а не `strlen`, чтобы Unicode не ломал length check.

### Acceptance criteria

- 1001 characters rejected.
- 1000 characters accepted.
- Unicode length handled via `mb_strlen`.
- Body trim still works.
- Тесты проходят.

### Definition of Done

- Length validation добавлена.
- Тесты проходят.
- Коммит: `RG-345: Add comment length validation`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-346 — Test Comments_Count Increments

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-346-test-comments-count-increments`  
**Base branch:** develop
**Depends on:** RG-345

### Goal

Написать тест: `posts.comments_count` увеличивается после создания visible comment.

### TDD step

Feature/action test:

```php
it('increments post comments count after adding comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'comments_count' => 0,
    ]);

    app(AddCommentAction::class)->handle(
        user: $user,
        post: $post,
        body: 'Nice.'
    );

    expect($post->fresh()->comments_count)->toBe(1);
});
```

Можно добавить stale counter repair test:

```php
it('sets comments count to visible comments count after adding comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'comments_count' => 99,
    ]);

    app(AddCommentAction::class)->handle($user, $post, 'Nice.');

    expect($post->fresh()->comments_count)->toBe(1);
});
```

Backlog говорит increment, но absolute recalculation надёжнее.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Тест существует.
- comments_count updates after comment.
- Тест падает до RG-347.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-346: Test comments_count increments`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-347 — Implement Comments_Count Increment

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-347-implement-comments-count-increment`  
**Base branch:** develop
**Depends on:** RG-346

### Goal

Обновлять `posts.comments_count` после создания comment.

### TDD step

Использовать падающий тест из RG-346.

### Implementation

Минимальный вариант:

```php
$post->increment('comments_count');
```

Но это плохо чинит stale counters.

Рекомендация:

```php
private function refreshCommentsCount(Post $post): void
{
    $query = Comment::query()
        ->where('post_id', $post->id);

    if (Schema::hasColumn('comments', 'status')) {
        $query->where('status', CommentStatus::Visible);
    }

    $post->forceFill([
        'comments_count' => $query->count(),
    ])->save();
}
```

В нормальном коде не нужно каждый раз проверять `Schema::hasColumn`, если schema уже стабильна. Это только если проект реально не уверен в `status`.

В `AddCommentAction`:

```php
return DB::transaction(function () use ($user, $post, $body) {
    $comment = Comment::create([...]);

    $this->refreshCommentsCount($post);

    return $comment;
});
```

### Acceptance criteria

- comments_count updates after comment.
- Лучше: comments_count is absolute visible comments count.
- Transaction used.
- Rejected comments do not increment count.
- Тесты проходят.

### Definition of Done

- comments_count update добавлен.
- Тесты проходят.
- Коммит: `RG-347: Implement comments_count increment`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Feature/Actions/AddCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-348 — Create DeleteCommentAction Skeleton

**Area:** Backend  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-348-create-delete-comment-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-347

### Goal

Создать skeleton action для удаления собственного комментария.

### TDD step

Unit test:

```php
it('has delete comment action with handle method', function () {
    $action = app(DeleteCommentAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Comments/DeleteCommentAction.php
```

Skeleton:

```php
namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\User;

final class DeleteCommentAction
{
    public function handle(User $user, Comment $comment): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

### Acceptance criteria

- `DeleteCommentAction` существует.
- Есть `handle(User $user, Comment $comment): void`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-348: Create DeleteCommentAction skeleton`

### Files likely touched

```txt
app/Actions/Comments/DeleteCommentAction.php
tests/Unit/Actions/DeleteCommentActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-349 — Test User Can Delete Own Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-349-test-user-can-delete-own-comment`  
**Base branch:** develop
**Depends on:** RG-348

### Goal

Написать тест: user может удалить свой comment.

### TDD step

Feature/action test:

```php
it('allows user to delete own comment', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'comments_count' => 1,
    ]);

    $comment = Comment::factory()
        ->for($user)
        ->for($post)
        ->create([
            'status' => CommentStatus::Visible,
        ]);

    app(DeleteCommentAction::class)->handle($user, $comment);

    $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    expect($post->fresh()->comments_count)->toBe(0);
});
```

Если SoftDeletes не используется, временно проверять:

```php
expect($comment->fresh())->toBeNull();
```

Но рекомендация — soft delete.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Own comment can be deleted.
- comments_count decreases/recalculates.
- Тест падает до RG-350.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-349: Test user can delete own comment`

### Files likely touched

```txt
tests/Feature/Actions/DeleteCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-350 — Implement Own Comment Deletion

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-350-implement-own-comment-deletion`  
**Base branch:** develop
**Depends on:** RG-349

### Goal

Реализовать удаление собственного comment.

### TDD step

Использовать падающий тест из RG-349.

### Implementation

Создать exception:

```txt
app/Exceptions/Comments/CannotDeleteCommentException.php
```

Пример:

```php
final class CannotDeleteCommentException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to delete this comment.');
    }
}
```

В `DeleteCommentAction`:

```php
if ($comment->user_id !== $user->id) {
    throw CannotDeleteCommentException::becauseUserIsNotAllowed();
}
```

Удаление:

```php
DB::transaction(function () use ($comment) {
    $post = $comment->post;

    $comment->delete();

    $this->refreshCommentsCount($post);
});
```

Если `Comment` не использует SoftDeletes, добавить:

```txt
database migration add_deleted_at_to_comments_table
use SoftDeletes in Comment model
```

`refreshCommentsCount()` можно сделать приватным методом временно. Лучше позже вынести в отдельный counter action, но это не Phase 17.

### Acceptance criteria

- User can delete own comment.
- Comment is soft-deleted or deleted according to schema decision.
- comments_count updates.
- Other user deletion not yet fully covered until RG-351/RG-352.
- Тест проходит.

### Definition of Done

- Deletion реализована.
- comments_count update реализован.
- Тест проходит.
- Коммит: `RG-350: Implement own comment deletion`

### Files likely touched

```txt
app/Actions/Comments/DeleteCommentAction.php
app/Exceptions/Comments/CannotDeleteCommentException.php
app/Models/Comment.php
database/migrations/*add_deleted_at_to_comments_table.php
tests/Feature/Actions/DeleteCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-351 — Test User Cannot Delete Other User Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-351-test-user-cannot-delete-other-user-comment`  
**Base branch:** develop
**Depends on:** RG-350

### Goal

Написать тест: user не может удалить чужой comment.

### TDD step

Feature/action test:

```php
it('does not allow user to delete another users comment', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $comment = Comment::factory()
        ->for($owner)
        ->create();

    app(DeleteCommentAction::class)->handle($otherUser, $comment);
})->throws(CannotDeleteCommentException::class);
```

No side effects:

```php
expect($comment->fresh())->not->toBeNull();
```

Если SoftDeletes:

```php
$this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Other user blocked.
- Comment remains.
- comments_count unchanged.
- Тест падает до RG-352, если policy ещё не подключена.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-351: Test user cannot delete other user comment`

### Files likely touched

```txt
tests/Feature/Actions/DeleteCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-352 — Add CommentPolicy Delete Rule

**Area:** Backend / Policy  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-352-add-comment-policy-delete-rule`  
**Base branch:** develop
**Depends on:** RG-351

### Goal

Добавить `CommentPolicy::delete()` и использовать его в `DeleteCommentAction`.

### TDD step

Policy tests:

```php
it('allows comment owner to delete comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()
        ->for($user)
        ->create();

    expect($user->can('delete', $comment))->toBeTrue();
});
```

```php
it('does not allow other user to delete comment', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $comment = Comment::factory()
        ->for($owner)
        ->create();

    expect($other->can('delete', $comment))->toBeFalse();
});
```

### Implementation

Создать policy, если её нет:

```bash
php artisan make:policy CommentPolicy --model=Comment
```

Policy:

```php
public function delete(User $user, Comment $comment): bool
{
    return $comment->user_id === $user->id;
}
```

Зарегистрировать policy, если авто-discovery не работает.

В `DeleteCommentAction` заменить manual check:

```php
if (! $user->can('delete', $comment)) {
    throw CannotDeleteCommentException::becauseUserIsNotAllowed();
}
```

### Acceptance criteria

- Owner can delete own comment.
- Other user cannot delete comment.
- DeleteCommentAction uses policy.
- Tests RG-349/RG-351 pass.
- Policy tests pass.

### Definition of Done

- Policy добавлена.
- DeleteCommentAction использует policy.
- Тесты проходят.
- Коммит: `RG-352: Add CommentPolicy delete rule`

### Files likely touched

```txt
app/Policies/CommentPolicy.php
app/Providers/AuthServiceProvider.php
app/Actions/Comments/DeleteCommentAction.php
tests/Feature/Policies/CommentPolicyTest.php
tests/Feature/Actions/DeleteCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-353 — Test Moderator Can Hide Comment

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-353-test-moderator-can-hide-comment`  
**Base branch:** develop
**Depends on:** RG-352

### Goal

Написать тест: moderator может скрыть comment.

### TDD step

Feature/action test:

```php
it('allows moderator to hide comment', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->published()->create([
        'comments_count' => 1,
    ]);

    $comment = Comment::factory()
        ->for($post)
        ->create([
            'status' => CommentStatus::Visible,
        ]);

    app(HideCommentAction::class)->handle($moderator, $comment);

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
    expect($post->fresh()->comments_count)->toBe(0);
});
```

Добавить security test:

```php
it('does not allow normal user to hide comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();

    app(HideCommentAction::class)->handle($user, $comment);
})->throws(CannotHideCommentException::class);
```

Тест должен упасть до RG-354.

### Implementation

Только добавить тест.

### Acceptance criteria

- Moderator can hide visible comment.
- Comment status becomes hidden.
- comments_count updates.
- Normal user cannot hide, если test добавлен.
- Тест падает до RG-354.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-353: Test moderator can hide comment`

### Files likely touched

```txt
tests/Feature/Actions/HideCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-354 — Implement HideCommentAction

**Area:** Backend / Moderation  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-354-implement-hide-comment-action`  
**Base branch:** develop
**Depends on:** RG-353

### Goal

Реализовать moderator action для скрытия comment.

### TDD step

Использовать падающие тесты из RG-353.

### Implementation

Создать:

```txt
app/Actions/Comments/HideCommentAction.php
app/Exceptions/Comments/CannotHideCommentException.php
```

Exception:

```php
final class CannotHideCommentException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to hide comments.');
    }
}
```

Action:

```php
final class HideCommentAction
{
    public function handle(User $user, Comment $comment): void
    {
        if (! $user->isModerator() && ! $user->isAdmin()) {
            throw CannotHideCommentException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($comment) {
            $post = $comment->post;

            $comment->update([
                'status' => CommentStatus::Hidden,
            ]);

            $this->refreshCommentsCount($post);
        });
    }
}
```

Если `User::isModerator()` / `isAdmin()` не существуют, добавить helpers или использовать `UserRole` enum directly.

Если comment уже hidden, action должен быть idempotent:

```txt
повторное скрытие не ломает counters.
```

### Acceptance criteria

- Moderator can hide comment.
- Admin can hide comment, если helper/test добавлен.
- Normal user cannot hide comment.
- Comment status becomes hidden.
- comments_count recalculates visible comments.
- Action is safe if comment already hidden.
- Тесты проходят.
- `composer test` проходит.
- `npm run build` проходит.

### Definition of Done

- HideCommentAction создан.
- Exception создан.
- Moderator/admin guard добавлен.
- Status update реализован.
- comments_count update реализован.
- Tests pass.
- Build passes.
- Коммит: `RG-354: Implement HideCommentAction`

### Files likely touched

```txt
app/Actions/Comments/HideCommentAction.php
app/Exceptions/Comments/CannotHideCommentException.php
app/Models/User.php
tests/Feature/Actions/HideCommentActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 17 Completion Criteria

Phase 17 завершена, когда:

```txt
- RG-333–RG-354 выполнены;
- AddCommentAction существует;
- active user can add comment to published post;
- guest cannot add comment;
- banned user cannot comment;
- hidden/pending/rejected posts cannot receive comments;
- empty comment rejected;
- whitespace-only comment rejected;
- too long comment rejected;
- body is trimmed before save;
- comments_count updates after comment creation;
- DeleteCommentAction exists;
- user can delete own comment;
- user cannot delete other user's comment;
- CommentPolicy delete rule exists and is used;
- HideCommentAction exists;
- moderator can hide comment;
- normal user cannot hide comment;
- comments_count updates after delete/hide;
- no Comments UI was added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 17

Без отдельной задачи нельзя:

```txt
- создавать CommentsSection Livewire component;
- создавать CommentForm Livewire component;
- создавать CommentItem Blade component;
- добавлять comments UI в drawer/post page;
- делать report comment action;
- делать notifications after comment;
- делать nested comments/replies;
- делать edit comment;
- делать comment likes;
- делать markdown/rich text;
- делать WYSIWYG editor;
- делать spam detection;
- делать rate limiting;
- добавлять Redis/cache layer;
- делать API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-333 Create AddCommentAction skeleton
RG-334 Test user can add comment
RG-335 Implement comment creation
RG-336 Test guest cannot add comment
RG-337 Add auth guard for comments
RG-338 Test banned user cannot comment
RG-339 Add banned guard for comments
RG-340 Test hidden post cannot receive comment
RG-341 Add post status guard for comments
RG-342 Test empty comment is rejected
RG-343 Add body validation
RG-344 Test too long comment is rejected
RG-345 Add comment length validation
RG-346 Test comments_count increments
RG-347 Implement comments_count increment
RG-348 Create DeleteCommentAction skeleton
RG-349 Test user can delete own comment
RG-350 Implement own comment deletion
RG-351 Test user cannot delete other user comment
RG-352 Add CommentPolicy delete rule
RG-353 Test moderator can hide comment
RG-354 Implement HideCommentAction
```
---

# 13. Release

После завершения Phase 17:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.8-phase17-comments-backend
git push -u origin release/v0.1.8-phase17-comments-backend
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.8-phase17-comments-backend -m "RateGuru Phase 17 comments backend"
git push origin v0.1.8-phase17-comments-backend
```
