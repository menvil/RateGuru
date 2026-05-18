# RateGuru — Phase 35 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 35 — Authorization Policies**  
Диапазон задач: **RG-575 → RG-586**  
Основа нумерации: исходный atomic backlog, где Phase 35 начинается с задачи 575 и заканчивается задачей 586.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 35 соответствует исходному блоку:

```txt
Phase 35 — Authorization Policies
```

Правильный диапазон Phase 35:

```txt
RG-575 — Create PostPolicy
RG-576 — Test user can update own draft post
RG-577 — Test user cannot update published post after lock rule
RG-578 — Test moderator can hide post
RG-579 — Test admin can delete post
RG-580 — Create CommentPolicy
RG-581 — Test user can delete own comment
RG-582 — Test moderator can hide comment
RG-583 — Create ModerationPolicy
RG-584 — Test normal user cannot moderate
RG-585 — Test moderator can moderate content
RG-586 — Test admin can ban user
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 34 закончилась на `RG-574`, а Phase 36 начинается с `RG-587`. Значит Phase 35 строго занимает `RG-575 → RG-586`.
---

# 2. Цель Phase 35

Phase 35 формализует authorization layer через Laravel Policies.

После Phase 35 должны существовать и быть покрыты тестами:

```txt
- PostPolicy;
- CommentPolicy;
- ModerationPolicy;
- правила update/hide/delete для posts;
- правила delete/hide для comments;
- правила доступа к moderation actions;
- правила admin-only для ban user.
```

Главная цель: убрать неявные scattered authorization checks из UI/Filament/Livewire и дать единую точку прав доступа.

Но важный момент: Policies **не заменяют backend guards** в Actions. Они дополняют их.
---

# 3. Scope Phase 35

## Входит

```txt
- PostPolicy;
- tests for own draft update;
- tests for published post lock rule;
- moderator hide post rule;
- admin delete post rule;
- CommentPolicy;
- own comment delete rule;
- moderator hide comment rule;
- ModerationPolicy;
- normal user cannot moderate;
- moderator can moderate content;
- admin can ban user;
- registration/discovery of policies;
- minimal integration with actions/UI where obviously needed.
```

## Не входит

```txt
- rewriting every action to use Gate::authorize;
- replacing all existing guards;
- new roles system;
- permissions package;
- Spatie Permission;
- team/organization permissions;
- policy UI;
- admin role editor;
- audit dashboard;
- authorization API.
```

Phase 35 — это базовые Laravel Policies, не полноценная RBAC-платформа.
---

# 4. Critical Decisions

## 4.1. Policies are first-class authorization rules

Future UI/Filament/Livewire should use:

```php
$user->can('hide', $post)
Gate::allows('moderateContent')
$this->authorize('delete', $comment)
```

Wrong direction:

```php
if ($user->role === 'admin') { ... }
```

directly scattered across views/resources.

Existing actions can still have internal guards, but UI visibility should move toward policies.

## 4.2. Actions remain final guard

Do not remove authorization checks from backend actions just because a policy exists.

Reason:

```txt
- actions may be called from tests, console, Filament, Livewire, API later;
- UI visibility is not security;
- policies can be forgotten by caller;
- backend actions must remain safe.
```

Correct layering:

```txt
UI/Filament:
- hides buttons using policies
- calls actions

Actions:
- still enforce critical authorization / invalid state guards
```

## 4.3. Post update lock rule

Backlog says:

```txt
Test user cannot update published post after lock rule
```

Meaning:

```txt
- user can update own draft post;
- once post is published, normal user cannot update it.
```

Draft status needs careful handling.

Possible project states:

```txt
Option A: PostStatus::Draft exists
Option B: project uses pending as unpublished state
Option C: no draft status exists yet
```

Rule for Phase 35:

```txt
Use existing draft status if it exists.
If no draft status exists, do not silently invent a new workflow without checking earlier phases.
```

But the task explicitly says draft. If `PostStatus::Draft` is missing, create the smallest compatible support only if required by tests:

```txt
- add PostStatus::Draft enum value;
- factory state draft();
- do not add public UI for drafts;
- do not change upload workflow unless existing code already expects draft.
```

Do not confuse `pending` with `draft` unless the current domain clearly uses pending as editable draft. Pending usually means submitted for review, not draft.

Recommended policy:

```txt
update:
- owner can update own draft;
- owner cannot update published/hidden/rejected/pending after submission;
- admin/moderator update is not included unless already required elsewhere.
```

## 4.4. Post hide rule

Backlog says:

```txt
moderator can hide post
```

Policy:

```txt
hide post:
- moderator can hide published post;
- admin can hide published post;
- normal user cannot hide post;
- owner cannot hide via moderation hide action unless also moderator/admin.
```

Owner deletion or owner archive is a different product feature, not this hide rule.

## 4.5. Post delete rule

Backlog says:

```txt
admin can delete post
```

Policy:

```txt
delete post:
- admin can delete post;
- moderator cannot delete post by default;
- normal user cannot delete post by default in this moderation/admin sense.
```

This may conflict with user-owned draft deletion if such feature exists. For Phase 35, follow backlog:

```txt
admin delete post only
```

If future owner draft delete is needed, add explicit policy method or separate task.

## 4.6. Comment delete rule

Backlog says:

```txt
user can delete own comment
```

Policy:

```txt
delete comment:
- owner can delete own comment;
- admin can delete any comment if earlier Phase 26 delete action was admin-only;
- moderator cannot delete by default unless already required by DeleteCommentAction policy;
- deleted/hidden state constraints should be handled by action/state guard.
```

This reconciles Phase 17/26:

```txt
owner delete own comment
admin delete any comment from Filament
```

## 4.7. Comment hide rule

Backlog says:

```txt
moderator can hide comment
```

Policy:

```txt
hide comment:
- moderator can hide visible comment;
- admin can hide visible comment;
- normal user cannot hide;
- owner cannot hide via moderation action unless moderator/admin.
```

## 4.8. ModerationPolicy is not tied to one model

`ModerationPolicy` is for cross-cutting permissions:

```txt
moderate content
ban user
access moderation tools
resolve reports maybe later
```

Laravel policies usually map to models, but for cross-cutting checks we can use `Gate::define()` or a policy around a dummy class.

Recommended implementation:

```txt
Create App\Policies\ModerationPolicy
Register gates in AuthServiceProvider/AppServiceProvider:
- moderate-content
- ban-user
```

Methods:

```php
public function moderateContent(User $user): bool
public function banUser(User $user): bool
```

Then:

```php
Gate::define('moderate-content', [ModerationPolicy::class, 'moderateContent']);
Gate::define('ban-user', [ModerationPolicy::class, 'banUser']);
```

This is clearer than inventing a `Moderation` model.

## 4.9. Role definitions

Expected roles from earlier phases:

```txt
user
moderator
admin
```

Rules:

```txt
normal user:
- cannot moderate
- cannot ban user

moderator:
- can moderate content
- cannot ban user

admin:
- can moderate content
- can ban user
```

If trusted user exists from Phase 25, trusted user is not moderator:

```txt
trusted ≠ moderator
trusted cannot moderate
trusted cannot ban
```

## 4.10. Policy tests should use Gate/user->can

Tests should verify policy behavior directly:

```php
expect($user->can('update', $post))->toBeTrue();
expect($moderator->can('hide', $post))->toBeTrue();
Gate::forUser($admin)->allows('ban-user')
```

Do not only test UI button visibility. UI tests are secondary.
---

# 5. Architecture Rules

## 5.1. Use standard Laravel policy location

Expected paths:

```txt
app/Policies/PostPolicy.php
app/Policies/CommentPolicy.php
app/Policies/ModerationPolicy.php
```

## 5.2. Register policies explicitly if auto-discovery is not enough

Depending on Laravel version/project structure, policies may auto-discover. Still, explicit registration is safer if tests fail.

Likely location:

```txt
app/Providers/AuthServiceProvider.php
```

or in modern Laravel app structure:

```txt
app/Providers/AppServiceProvider.php
```

Do not create duplicate providers unnecessarily.

## 5.3. Policy names should be expressive

PostPolicy:

```php
update(User $user, Post $post): bool
hide(User $user, Post $post): bool
delete(User $user, Post $post): bool
```

CommentPolicy:

```php
delete(User $user, Comment $comment): bool
hide(User $user, Comment $comment): bool
```

ModerationPolicy:

```php
moderateContent(User $user): bool
banUser(User $user): bool
```

## 5.4. Avoid role string literals everywhere

Use existing helpers if present:

```php
$user->isAdmin()
$user->isModerator()
$user->isRegularUser()
```

If helpers do not exist, add tiny helpers to `User` or use enum comparisons.

Do not scatter:

```php
$user->role === 'admin'
```

everywhere.

## 5.5. Status checks belong in policy only when they are authorization-related

Example:

```txt
update own draft post:
- status matters because published lock rule is authorization/product rule.
```

Example where action still matters:

```txt
hide post:
- policy says moderator can hide
- action still checks post status is published
```

Policy can include status for UI visibility, but action remains source of state transition validity.
---

# 6. GitFlow для Phase 35

## Base branch

Все задачи Phase 35 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-575-create-post-policy
feature/RG-580-create-comment-policy
feature/RG-586-test-admin-can-ban-user
```

## Commit format

```txt
RG-575: Create PostPolicy
RG-580: Create CommentPolicy
RG-586: Test admin can ban user
```

## Release branch

После выполнения `RG-575`–`RG-586`:

```txt
release/v0.2.16-phase35-authorization-policies
```

## Tag

После merge release branch в `main`:

```txt
v0.2.16-phase35-authorization-policies
```

Почему `v0.2.16`: Phase 34 использует `v0.2.15`, Phase 35 следующий release.
---

# 7. TDD Rules for Phase 35

## Для PostPolicy

Тестировать:

```txt
- owner can update own draft;
- owner cannot update own published post;
- another user cannot update draft;
- moderator can hide post;
- admin can hide post;
- normal user cannot hide post;
- admin can delete post;
- moderator cannot delete post unless explicitly allowed;
- normal user cannot delete post.
```

## Для CommentPolicy

Тестировать:

```txt
- owner can delete own comment;
- non-owner cannot delete comment;
- admin can delete any comment if Phase 26 uses admin delete;
- moderator can hide comment;
- admin can hide comment;
- normal user cannot hide comment.
```

## Для ModerationPolicy / Gates

Тестировать:

```txt
- normal user cannot moderate;
- trusted user cannot moderate;
- moderator can moderate content;
- admin can moderate content;
- admin can ban user;
- moderator cannot ban user;
- normal user cannot ban user.
```

## Для integration

Минимально проверить, что policies/gates зарегистрированы:

```txt
$user->can(...)
Gate::forUser(...)->allows(...)
```

No need to test every Blade/Filament button in this phase unless policy integration is added there.
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Authorization / Policies / Tests
Type: Test / Feature / Policy / Gate
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
- Backend action guards не удалены
- Policy/gate registered
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 35 Atomic Tasks
---

## RG-575 — Create PostPolicy

**Area:** Authorization / Posts  
**Type:** Policy  
**Priority:** P0  
**Branch:** `feature/RG-575-create-post-policy`  
**Base branch:** develop
**Depends on:** RG-574

### Goal

Создать `PostPolicy` skeleton и зарегистрировать policy для `Post`.

### TDD step

Policy existence/registration test:

```php
it('has post policy registered', function () {
    $user = User::factory()->create();
    $post = Post::factory()->make();

    expect(Gate::getPolicyFor(Post::class))->toBeInstanceOf(PostPolicy::class);
});
```

Method existence test:

```php
it('has expected post policy methods', function () {
    $policy = app(PostPolicy::class);

    expect(method_exists($policy, 'update'))->toBeTrue();
    expect(method_exists($policy, 'hide'))->toBeTrue();
    expect(method_exists($policy, 'delete'))->toBeTrue();
});
```

Тест должен упасть до создания policy.

### Implementation

Create:

```bash
php artisan make:policy PostPolicy --model=Post
```

Expected file:

```txt
app/Policies/PostPolicy.php
```

Skeleton:

```php
final class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return false;
    }

    public function hide(User $user, Post $post): bool
    {
        return false;
    }

    public function delete(User $user, Post $post): bool
    {
        return false;
    }
}
```

Register if needed:

```php
protected $policies = [
    Post::class => PostPolicy::class,
];
```

or modern:

```php
Gate::policy(Post::class, PostPolicy::class);
```

Do not implement rules yet except safe false defaults.

### Acceptance criteria

- `PostPolicy` exists.
- Policy is registered/discoverable for `Post`.
- Methods `update`, `hide`, `delete` exist.
- Default behavior is safe deny until tests implement rules.
- Tests pass.

### Definition of Done

- Tests written.
- Policy skeleton created.
- Policy registered.
- Tests pass.
- Коммит: `RG-575: Create PostPolicy`

### Files likely touched

```txt
app/Policies/PostPolicy.php
app/Providers/AuthServiceProvider.php
app/Providers/AppServiceProvider.php
tests/Unit/Policies/PostPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-576 — Test User Can Update Own Draft Post

**Area:** Authorization / Posts / Tests  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-576-test-user-can-update-own-draft-post`  
**Base branch:** develop
**Depends on:** RG-575

### Goal

Проверить и реализовать правило: user can update own draft post.

### TDD step

Policy test:

```php
it('allows user to update own draft post', function () {
    $user = User::factory()->create();

    $post = Post::factory()
        ->for($user)
        ->draft()
        ->create();

    expect($user->can('update', $post))->toBeTrue();
});
```

Non-owner test:

```php
it('does not allow user to update another users draft post', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $post = Post::factory()
        ->for($owner)
        ->draft()
        ->create();

    expect($other->can('update', $post))->toBeFalse();
});
```

If `draft()` factory state does not exist, add it.  
If `PostStatus::Draft` does not exist, check current domain before adding.

### Implementation

In `PostPolicy`:

```php
public function update(User $user, Post $post): bool
{
    return $post->user_id === $user->id
        && $post->status === PostStatus::Draft;
}
```

If project uses `author_id` instead of `user_id`, use actual field.

If no `PostStatus::Draft` exists:

```txt
Preferred:
- if existing unpublished editable state exists, use it and name test accordingly.
- if not, add PostStatus::Draft only as minimal domain support.
```

If adding draft support:

```txt
- add enum value;
- add factory draft() state;
- do not change upload workflow;
- do not add UI.
```

### Acceptance criteria

- Owner can update own draft post.
- Non-owner cannot update draft post.
- Rule uses owner id and draft status.
- No published update allowed by this task.
- Tests pass.

### Definition of Done

- Tests written.
- Policy update rule implemented.
- Draft state support added only if necessary.
- Tests pass.
- Коммит: `RG-576: Test user can update own draft post`

### Files likely touched

```txt
app/Policies/PostPolicy.php
app/Enums/PostStatus.php
database/factories/PostFactory.php
tests/Unit/Policies/PostPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-577 — Test User Cannot Update Published Post After Lock Rule

**Area:** Authorization / Posts / Tests  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-577-test-user-cannot-update-published-post-after-lock-rule`  
**Base branch:** develop
**Depends on:** RG-576

### Goal

Проверить и закрепить lock rule: owner cannot update published post.

### TDD step

Policy test:

```php
it('does not allow user to update own published post after lock rule', function () {
    $user = User::factory()->create();

    $post = Post::factory()
        ->for($user)
        ->published()
        ->create();

    expect($user->can('update', $post))->toBeFalse();
});
```

Additional non-draft states:

```php
it('does not allow user to update own pending post', ...)
it('does not allow user to update own hidden post', ...)
it('does not allow user to update own rejected post', ...)
```

At minimum, published lock test is required by backlog.

### Implementation

If RG-576 implemented update as only draft allowed, this test should already pass.

If not, change policy to allow only:

```php
$post->status === PostStatus::Draft
```

Do not add edit-after-publish time window unless explicitly required.

### Acceptance criteria

- Owner cannot update published post.
- Published lock rule is explicit.
- Pending/hidden/rejected update is denied if tests added.
- Draft update still works.
- Tests pass.

### Definition of Done

- Tests written.
- Policy update rule adjusted if needed.
- Tests pass.
- Коммит: `RG-577: Test user cannot update published post after lock rule`

### Files likely touched

```txt
app/Policies/PostPolicy.php
tests/Unit/Policies/PostPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-578 — Test Moderator Can Hide Post

**Area:** Authorization / Posts / Moderation  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-578-test-moderator-can-hide-post`  
**Base branch:** develop
**Depends on:** RG-577

### Goal

Проверить и реализовать правило: moderator can hide post.

### TDD step

Policy test:

```php
it('allows moderator to hide post', function () {
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()->published()->create();

    expect($moderator->can('hide', $post))->toBeTrue();
});
```

Admin test:

```php
it('allows admin to hide post', function () {
    $admin = User::factory()->admin()->create();
    $post = Post::factory()->published()->create();

    expect($admin->can('hide', $post))->toBeTrue();
});
```

Normal user test:

```php
it('does not allow normal user to hide post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    expect($user->can('hide', $post))->toBeFalse();
});
```

### Implementation

In `PostPolicy`:

```php
public function hide(User $user, Post $post): bool
{
    return ($user->isModerator() || $user->isAdmin())
        && $post->status === PostStatus::Published;
}
```

If state transition validity is already handled by `HidePostAction`, policy can be looser:

```php
return $user->isModerator() || $user->isAdmin();
```

Recommended for UI visibility:

```txt
include published status check
```

because hide button should not show for hidden/pending posts.

### Acceptance criteria

- Moderator can hide published post.
- Admin can hide published post.
- Normal user cannot hide post.
- Policy does not grant owner hide rights by default.
- Hidden/pending posts are not hideable if status test added.
- Tests pass.

### Definition of Done

- Tests written.
- Hide rule implemented.
- Tests pass.
- Коммит: `RG-578: Test moderator can hide post`

### Files likely touched

```txt
app/Policies/PostPolicy.php
tests/Unit/Policies/PostPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-579 — Test Admin Can Delete Post

**Area:** Authorization / Posts / Admin  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-579-test-admin-can-delete-post`  
**Base branch:** develop
**Depends on:** RG-578

### Goal

Проверить и реализовать правило: admin can delete post.

### TDD step

Policy test:

```php
it('allows admin to delete post', function () {
    $admin = User::factory()->admin()->create();

    $post = Post::factory()->published()->create();

    expect($admin->can('delete', $post))->toBeTrue();
});
```

Moderator denied:

```php
it('does not allow moderator to delete post', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    expect($moderator->can('delete', $post))->toBeFalse();
});
```

Normal user denied:

```php
it('does not allow normal user to delete post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->published()->create();

    expect($user->can('delete', $post))->toBeFalse();
});
```

### Implementation

In `PostPolicy`:

```php
public function delete(User $user, Post $post): bool
{
    return $user->isAdmin();
}
```

If owner draft delete exists in product, do not mix it into this policy silently unless existing tests require it. A separate method like `deleteOwnDraft` would be cleaner later.

### Acceptance criteria

- Admin can delete post.
- Moderator cannot delete post.
- Normal user cannot delete post.
- Owner cannot delete published post by default.
- Tests pass.

### Definition of Done

- Tests written.
- Delete rule implemented.
- Tests pass.
- Коммит: `RG-579: Test admin can delete post`

### Files likely touched

```txt
app/Policies/PostPolicy.php
tests/Unit/Policies/PostPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-580 — Create CommentPolicy

**Area:** Authorization / Comments  
**Type:** Policy  
**Priority:** P0  
**Branch:** `feature/RG-580-create-comment-policy`  
**Base branch:** develop
**Depends on:** RG-579

### Goal

Создать `CommentPolicy` skeleton и зарегистрировать policy для `Comment`.

### TDD step

Policy registration test:

```php
it('has comment policy registered', function () {
    expect(Gate::getPolicyFor(Comment::class))->toBeInstanceOf(CommentPolicy::class);
});
```

Method existence test:

```php
it('has expected comment policy methods', function () {
    $policy = app(CommentPolicy::class);

    expect(method_exists($policy, 'delete'))->toBeTrue();
    expect(method_exists($policy, 'hide'))->toBeTrue();
});
```

### Implementation

Create:

```bash
php artisan make:policy CommentPolicy --model=Comment
```

Skeleton:

```php
final class CommentPolicy
{
    public function delete(User $user, Comment $comment): bool
    {
        return false;
    }

    public function hide(User $user, Comment $comment): bool
    {
        return false;
    }
}
```

Register if needed:

```php
Gate::policy(Comment::class, CommentPolicy::class);
```

Do not implement rules yet except safe false defaults.

### Acceptance criteria

- `CommentPolicy` exists.
- Policy is registered/discoverable for `Comment`.
- Methods `delete`, `hide` exist.
- Default behavior is safe deny.
- Tests pass.

### Definition of Done

- Tests written.
- Policy skeleton created.
- Policy registered.
- Tests pass.
- Коммит: `RG-580: Create CommentPolicy`

### Files likely touched

```txt
app/Policies/CommentPolicy.php
app/Providers/AuthServiceProvider.php
app/Providers/AppServiceProvider.php
tests/Unit/Policies/CommentPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-581 — Test User Can Delete Own Comment

**Area:** Authorization / Comments / Owner Actions  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-581-test-user-can-delete-own-comment`  
**Base branch:** develop
**Depends on:** RG-580

### Goal

Проверить и реализовать правило: user can delete own comment.

### TDD step

Policy test:

```php
it('allows user to delete own comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()
        ->for($user, 'user')
        ->create();

    expect($user->can('delete', $comment))->toBeTrue();
});
```

Non-owner denied:

```php
it('does not allow user to delete another users comment', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $comment = Comment::factory()
        ->for($owner, 'user')
        ->create();

    expect($other->can('delete', $comment))->toBeFalse();
});
```

Admin delete any comment, to align Phase 26:

```php
it('allows admin to delete any comment', function () {
    $admin = User::factory()->admin()->create();
    $comment = Comment::factory()->create();

    expect($admin->can('delete', $comment))->toBeTrue();
});
```

Moderator delete denied unless product explicitly allowed:

```php
it('does not allow moderator to delete comment by default', ...)
```

### Implementation

In `CommentPolicy`:

```php
public function delete(User $user, Comment $comment): bool
{
    return $user->isAdmin()
        || $comment->user_id === $user->id;
}
```

If comment owner field is not `user_id`, use actual relation/column.

Do not allow moderator delete by default. Moderator can hide, not delete.

### Acceptance criteria

- User can delete own comment.
- User cannot delete another user's comment.
- Admin can delete any comment.
- Moderator cannot delete by default.
- Tests pass.

### Definition of Done

- Tests written.
- Delete rule implemented.
- Tests pass.
- Коммит: `RG-581: Test user can delete own comment`

### Files likely touched

```txt
app/Policies/CommentPolicy.php
tests/Unit/Policies/CommentPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-582 — Test Moderator Can Hide Comment

**Area:** Authorization / Comments / Moderation  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-582-test-moderator-can-hide-comment`  
**Base branch:** develop
**Depends on:** RG-581

### Goal

Проверить и реализовать правило: moderator can hide comment.

### TDD step

Policy test:

```php
it('allows moderator to hide comment', function () {
    $moderator = User::factory()->moderator()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    expect($moderator->can('hide', $comment))->toBeTrue();
});
```

Admin test:

```php
it('allows admin to hide comment', function () {
    $admin = User::factory()->admin()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    expect($admin->can('hide', $comment))->toBeTrue();
});
```

Normal user denied:

```php
it('does not allow normal user to hide comment', function () {
    $user = User::factory()->create();

    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    expect($user->can('hide', $comment))->toBeFalse();
});
```

Hidden comment test optional:

```php
it('does not allow hiding already hidden comment', ...)
```

### Implementation

In `CommentPolicy`:

```php
public function hide(User $user, Comment $comment): bool
{
    return ($user->isModerator() || $user->isAdmin())
        && $comment->status === CommentStatus::Visible;
}
```

If `HideCommentAction` already handles status, policy can still include status for UI visibility.

### Acceptance criteria

- Moderator can hide visible comment.
- Admin can hide visible comment.
- Normal user cannot hide comment.
- Owner cannot hide via moderation policy by default.
- Already hidden comment is not hideable if test added.
- Tests pass.

### Definition of Done

- Tests written.
- Hide rule implemented.
- Tests pass.
- Коммит: `RG-582: Test moderator can hide comment`

### Files likely touched

```txt
app/Policies/CommentPolicy.php
tests/Unit/Policies/CommentPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-583 — Create ModerationPolicy

**Area:** Authorization / Moderation  
**Type:** Policy / Gate  
**Priority:** P0  
**Branch:** `feature/RG-583-create-moderation-policy`  
**Base branch:** develop
**Depends on:** RG-582

### Goal

Создать `ModerationPolicy` для cross-cutting moderation permissions и зарегистрировать Gates.

### TDD step

Policy/gate test:

```php
it('has moderation gates registered', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('moderate-content'))->toBeFalse();
    expect(Gate::forUser($user)->allows('ban-user'))->toBeFalse();
});
```

Method existence:

```php
it('has expected moderation policy methods', function () {
    $policy = app(ModerationPolicy::class);

    expect(method_exists($policy, 'moderateContent'))->toBeTrue();
    expect(method_exists($policy, 'banUser'))->toBeTrue();
});
```

### Implementation

Create:

```txt
app/Policies/ModerationPolicy.php
```

Class:

```php
final class ModerationPolicy
{
    public function moderateContent(User $user): bool
    {
        return false;
    }

    public function banUser(User $user): bool
    {
        return false;
    }
}
```

Register gates:

```php
Gate::define('moderate-content', [ModerationPolicy::class, 'moderateContent']);
Gate::define('ban-user', [ModerationPolicy::class, 'banUser']);
```

Registration location:

```txt
AuthServiceProvider boot()
```

or modern Laravel provider boot method.

Do not create dummy `Moderation` model unless project requires model-based policy.

### Acceptance criteria

- `ModerationPolicy` exists.
- Methods `moderateContent`, `banUser` exist.
- Gates `moderate-content`, `ban-user` are registered.
- Default behavior is safe deny until next tasks.
- Tests pass.

### Definition of Done

- Tests written.
- Policy created.
- Gates registered.
- Tests pass.
- Коммит: `RG-583: Create ModerationPolicy`

### Files likely touched

```txt
app/Policies/ModerationPolicy.php
app/Providers/AuthServiceProvider.php
app/Providers/AppServiceProvider.php
tests/Unit/Policies/ModerationPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-584 — Test Normal User Cannot Moderate

**Area:** Authorization / Moderation / Tests  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-584-test-normal-user-cannot-moderate`  
**Base branch:** develop
**Depends on:** RG-583

### Goal

Проверить, что normal user не может модерировать.

### TDD step

Gate tests:

```php
it('does not allow normal user to moderate content', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('moderate-content'))->toBeFalse();
});
```

Trusted user test if Phase 25 trusted status exists:

```php
it('does not allow trusted user to moderate content', function () {
    $trustedUser = User::factory()->trusted()->create();

    expect(Gate::forUser($trustedUser)->allows('moderate-content'))->toBeFalse();
});
```

Ban denied:

```php
it('does not allow normal user to ban user', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('ban-user'))->toBeFalse();
});
```

### Implementation

If `ModerationPolicy` default false already passes, no code change needed.  
If needed:

```php
public function moderateContent(User $user): bool
{
    return $user->isModerator() || $user->isAdmin();
}

public function banUser(User $user): bool
{
    return $user->isAdmin();
}
```

This will still make normal user false.

### Acceptance criteria

- Normal user cannot moderate content.
- Normal user cannot ban user.
- Trusted user cannot moderate if trusted exists.
- Tests pass.

### Definition of Done

- Tests written.
- Policy remains safe for normal users.
- Tests pass.
- Коммит: `RG-584: Test normal user cannot moderate`

### Files likely touched

```txt
app/Policies/ModerationPolicy.php
tests/Unit/Policies/ModerationPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-585 — Test Moderator Can Moderate Content

**Area:** Authorization / Moderation / Tests  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-585-test-moderator-can-moderate-content`  
**Base branch:** develop
**Depends on:** RG-584

### Goal

Проверить и реализовать правило: moderator can moderate content.

### TDD step

Gate test:

```php
it('allows moderator to moderate content', function () {
    $moderator = User::factory()->moderator()->create();

    expect(Gate::forUser($moderator)->allows('moderate-content'))->toBeTrue();
});
```

Admin can moderate content:

```php
it('allows admin to moderate content', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('moderate-content'))->toBeTrue();
});
```

Moderator cannot ban:

```php
it('does not allow moderator to ban user', function () {
    $moderator = User::factory()->moderator()->create();

    expect(Gate::forUser($moderator)->allows('ban-user'))->toBeFalse();
});
```

### Implementation

In `ModerationPolicy`:

```php
public function moderateContent(User $user): bool
{
    return $user->isModerator() || $user->isAdmin();
}

public function banUser(User $user): bool
{
    return $user->isAdmin();
}
```

If helpers missing, add to `User`:

```php
public function isModerator(): bool
public function isAdmin(): bool
```

But these likely already exist from earlier phases. Do not duplicate.

### Acceptance criteria

- Moderator can moderate content.
- Admin can moderate content.
- Normal user still cannot moderate.
- Moderator cannot ban user.
- Tests pass.

### Definition of Done

- Tests written.
- moderateContent rule implemented.
- Tests pass.
- Коммит: `RG-585: Test moderator can moderate content`

### Files likely touched

```txt
app/Policies/ModerationPolicy.php
app/Models/User.php
tests/Unit/Policies/ModerationPolicyTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-586 — Test Admin Can Ban User

**Area:** Authorization / Moderation / Admin  
**Type:** Test + Policy Rule  
**Priority:** P0  
**Branch:** `feature/RG-586-test-admin-can-ban-user`  
**Base branch:** develop
**Depends on:** RG-585

### Goal

Проверить и закрепить правило: admin can ban user.

### TDD step

Gate test:

```php
it('allows admin to ban user', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('ban-user'))->toBeTrue();
});
```

Normal user denied:

```php
it('does not allow normal user to ban user', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('ban-user'))->toBeFalse();
});
```

Moderator denied:

```php
it('does not allow moderator to ban user', function () {
    $moderator = User::factory()->moderator()->create();

    expect(Gate::forUser($moderator)->allows('ban-user'))->toBeFalse();
});
```

Optional integration with `BanUserAction`:

```php
it('ban user action authorization aligns with ban-user gate', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    expect(Gate::forUser($moderator)->allows('ban-user'))->toBeFalse();

    app(BanUserAction::class)->handle($moderator, $target);
})->throws(CannotModerateUserException::class);
```

### Implementation

If RG-585 already implemented:

```php
public function banUser(User $user): bool
{
    return $user->isAdmin();
}
```

this test should pass.

Optional policy integration in Filament UserResource visibility:

Replace direct checks:

```php
auth()->user()?->isAdmin()
```

with:

```php
Gate::allows('ban-user')
```

But do not refactor all resources aggressively. Minimal safe integration:

```txt
UserResource ban/shadowban/unban/mark trusted visibility can use Gate::allows('ban-user') where applicable.
```

Careful:

```txt
mark trusted/unban are admin-only but not exactly ban-user.
```

A future gate `manage-users` would be cleaner, but not in backlog. Do not overbuild.

Add review doc:

```txt
docs/security/phase-35-authorization-policies-review.md
```

Checklist:

```txt
- PostPolicy registered;
- CommentPolicy registered;
- Moderation gates registered;
- owner draft update allowed;
- published post update locked;
- moderator hide post/comment allowed;
- admin delete post allowed;
- owner delete comment allowed;
- normal user cannot moderate;
- moderator can moderate content;
- admin can ban user;
- backend action guards preserved.
```

### Acceptance criteria

- Admin can ban user via `ban-user` gate.
- Moderator cannot ban user.
- Normal user cannot ban user.
- Gate matches existing BanUserAction guard.
- Review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- banUser rule verified.
- Optional integration done only where safe.
- Review note added.
- Tests/build pass.
- Коммит: `RG-586: Test admin can ban user`

### Files likely touched

```txt
app/Policies/ModerationPolicy.php
app/Filament/Resources/UserResource.php
docs/security/phase-35-authorization-policies-review.md
tests/Unit/Policies/ModerationPolicyTest.php
tests/Feature/Actions/BanUserActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 35 Completion Criteria

Phase 35 завершена, когда:

```txt
- RG-575–RG-586 выполнены;
- PostPolicy exists;
- PostPolicy is registered;
- owner can update own draft post;
- non-owner cannot update draft post;
- owner cannot update published post after lock rule;
- moderator/admin can hide published post;
- normal user cannot hide post;
- admin can delete post;
- moderator/normal user cannot delete post;
- CommentPolicy exists;
- CommentPolicy is registered;
- owner can delete own comment;
- non-owner cannot delete comment;
- admin can delete any comment if Phase 26 requires it;
- moderator/admin can hide visible comment;
- normal user cannot hide comment;
- ModerationPolicy exists;
- gates moderate-content and ban-user exist;
- normal user cannot moderate;
- trusted user cannot moderate if trusted exists;
- moderator can moderate content;
- admin can moderate content;
- admin can ban user;
- moderator cannot ban user;
- backend action guards were not removed;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 35

Без отдельной задачи нельзя:

```txt
- ставить Spatie Permission;
- строить полноценный RBAC/permissions UI;
- добавлять roles management page;
- добавлять team/organization permissions;
- удалять guards из backend actions;
- переписывать все Filament resources целиком;
- менять moderation workflow;
- добавлять новые post/comment statuses без необходимости draft rule;
- добавлять API authorization layer;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-575 Create PostPolicy
RG-576 Test user can update own draft post
RG-577 Test user cannot update published post after lock rule
RG-578 Test moderator can hide post
RG-579 Test admin can delete post
RG-580 Create CommentPolicy
RG-581 Test user can delete own comment
RG-582 Test moderator can hide comment
RG-583 Create ModerationPolicy
RG-584 Test normal user cannot moderate
RG-585 Test moderator can moderate content
RG-586 Test admin can ban user
```
---

# 13. Release

После завершения Phase 35:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.16-phase35-authorization-policies
git push -u origin release/v0.2.16-phase35-authorization-policies
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.16-phase35-authorization-policies -m "RateGuru Phase 35 Authorization Policies"
git push origin v0.2.16-phase35-authorization-policies
```
