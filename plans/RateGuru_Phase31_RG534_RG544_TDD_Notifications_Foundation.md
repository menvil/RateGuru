# RateGuru — Phase 31 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 31 — Notifications Foundation**  
Диапазон задач: **RG-534 → RG-544**  
Основа нумерации: исходный atomic backlog, где Phase 31 начинается с задачи 534 и заканчивается задачей 544.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 31 соответствует исходному блоку:

```txt
Phase 31 — Notifications Foundation
```

Правильный диапазон Phase 31:

```txt
RG-534 — Create notifications table if not using Laravel default
RG-535 — Create PostCommentedNotification
RG-536 — Test comment creates notification for post owner
RG-537 — Dispatch notification after comment
RG-538 — Create PostApprovedNotification
RG-539 — Test post approval creates notification
RG-540 — Dispatch notification after approval
RG-541 — Create NotificationBell Livewire component
RG-542 — Test NotificationBell shows unread count
RG-543 — Render notifications dropdown
RG-544 — Mark notification as read
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 32 начинается с `RG-545` и делает **Share & URL Behavior**. Поэтому Phase 31 не должна захватывать canonical URL helpers, copy link buttons, share panels или Open Graph.
---

# 2. Цель Phase 31

Phase 31 добавляет фундамент уведомлений.

После Phase 31 пользователь должен получать database notifications для двух событий:

```txt
- кто-то прокомментировал его post;
- его post был approved модератором.
```

И должен видеть базовый UI:

```txt
- NotificationBell;
- unread count;
- notifications dropdown;
- mark as read.
```

Это foundation, а не полноценная notification-система.
---

# 3. Scope Phase 31

## Входит

```txt
- notifications table;
- Laravel database notification channel;
- PostCommentedNotification;
- PostApprovedNotification;
- dispatch notification after comment;
- dispatch notification after post approval;
- NotificationBell Livewire component;
- unread count;
- dropdown list;
- mark notification as read;
- tests for notification creation and bell behavior.
```

## Не входит

```txt
- email notifications;
- push notifications;
- broadcasting / realtime websockets;
- notification preferences;
- notification settings page;
- notification digest;
- notification pagination page;
- notifications archive;
- mark all as read;
- delete notification;
- queue worker setup;
- Redis;
- admin notification dashboard.
```

Очереди/Redis не нужны в Phase 31. Laravel database notifications достаточно для MVP.
---

# 4. Critical Decisions

## 4.1. Use Laravel database notifications

Используем стандартный Laravel notifications механизм:

```txt
Illuminate\Notifications\Notifiable
database channel
notifications table
```

Почему:

```txt
- меньше собственного кода;
- Laravel уже даёт unread/read behavior;
- тестируется через Notification fake и database assertions;
- UI может читать $user->unreadNotifications / notifications.
```

Не создавать кастомную таблицу:

```txt
user_notifications
app_notifications
notification_events
```

если стандартная `notifications` table подходит.

## 4.2. Notifications table

Laravel database notification channel требует таблицу `notifications`.

Если таблицы нет:

```bash
php artisan make:notifications-table
php artisan migrate
```

Но задача формулируется как:

```txt
Create notifications table if not using Laravel default
```

Поэтому RG-534 должен сначала проверить, нет ли уже migration/table.

Правило:

```txt
- если notifications table/migration уже есть — не создавать дубль;
- если нет — создать стандартную migration;
- если User id UUID/ULID — migration должна использовать правильный morphs тип.
```

Для RateGuru, если users используют обычные integer ids, стандартный `morphs('notifiable')` подходит.

## 4.3. Notifications are database-only in Phase 31

`via()` должен возвращать:

```php
['database']
```

Не добавлять `mail`, `broadcast`, `slack`.

Почему:

```txt
- email требует templates/from/domain/email delivery;
- broadcast требует websockets/Echo/Pusher;
- это другой scope.
```

## 4.4. PostCommentedNotification recipient

Когда user A комментирует post user B:

```txt
recipient = post owner
actor = commenter
target = post
optional secondary target = comment
```

Правило:

```txt
post owner does not get notification for own comment
```

Иначе пользователь будет получать мусорные уведомления о своих действиях.

## 4.5. PostApprovedNotification recipient

Когда moderator/admin approves pending post:

```txt
recipient = post owner
actor = moderator/admin
target = post
```

Правило:

```txt
if moderator approves own post, notification can still be skipped or sent.
```

Рекомендация:

```txt
skip self-notification if actor is post owner.
```

Но если moderator approving own post невозможен по policy, тест не нужен.

## 4.6. Notification payload shape

Database notification data должна быть стабильной и UI-friendly.

Recommended shape for `PostCommentedNotification`:

```php
[
    'type' => 'post_commented',
    'post_id' => $post->id,
    'post_title' => $post->title,
    'comment_id' => $comment->id,
    'actor_id' => $commenter->id,
    'actor_name' => $commenter->name,
    'actor_username' => $commenter->username,
    'message' => '@chef commented on your post',
    'url' => route('posts.show', $post),
]
```

Recommended shape for `PostApprovedNotification`:

```php
[
    'type' => 'post_approved',
    'post_id' => $post->id,
    'post_title' => $post->title,
    'actor_id' => $moderator->id,
    'actor_name' => $moderator->name,
    'actor_username' => $moderator->username,
    'message' => 'Your post was approved',
    'url' => route('posts.show', $post),
]
```

If `posts.show` route does not exist or uses slug/id differently, use existing canonical post URL helper if already created.  
Do not create Phase 32 canonical helper early.

Fallback:

```php
'url' => '#'
```

with TODO is acceptable if post URL is not stable yet.

## 4.7. Dispatch location

Notifications should be dispatched from backend actions after successful state change:

```txt
AddCommentAction
ApprovePostAction
```

Not from UI components.

Wrong:

```php
CommentForm::submit() sends notification
InlinePostModeration::approve() sends notification
Filament action sends notification
```

Correct:

```php
AddCommentAction creates comment → sends notification
ApprovePostAction approves post → sends notification
```

Reason:

```txt
- Livewire UI, Filament UI, API/future endpoints all share same behavior;
- no missed notifications when action is called from admin/dashboard.
```

## 4.8. NotificationBell placement

Phase 31 creates the component. It should be integratable into main layout/header.

Minimum:

```txt
NotificationBell component exists and can be rendered in authenticated header.
```

If app layout/header component exists, integrate there.  
If not, add it to the primary navigation layout where authenticated user sees it.

Do not redesign full header.

## 4.9. NotificationBell guest behavior

Guest should not see bell.

Rules:

```txt
guest → no bell / empty output
authenticated user → bell with unread count
```

## 4.10. Mark as read

MVP action:

```txt
mark one notification as read
```

Not included:

```txt
mark all as read
delete notification
archive notification
```

Security rule:

```txt
user can only mark own notification as read
```

Never trust notification id alone without scoping to current user.
---

# 5. Architecture Rules

## 5.1. Use Laravel Notifiable trait

User model should use:

```php
use Illuminate\Notifications\Notifiable;
```

Most Laravel User models already include it.  
If missing, add it in RG-534 or RG-535.

## 5.2. Notification classes location

Use standard Laravel path:

```txt
app/Notifications/PostCommentedNotification.php
app/Notifications/PostApprovedNotification.php
```

Do not put notifications into Actions or Services.

## 5.3. Database notification payload must not store full models

Do not store:

```php
'post' => $post
'comment' => $comment
'user' => $user
```

Store ids, titles, names, URLs.

Reason:

```txt
- notifications.data is JSON;
- full model serialization is noisy and brittle;
- stale snapshots should be intentional.
```

## 5.4. NotificationBell only reads current user's notifications

Wrong:

```php
DatabaseNotification::query()->latest()
```

Correct:

```php
auth()->user()->notifications()
auth()->user()->unreadNotifications()
```

## 5.5. No queues in this phase

Notifications can implement neither `ShouldQueue` nor queue config.

Why:

```txt
- local SQLite MVP;
- tests simpler;
- no queue worker deployment yet.
```

If later notifications become expensive, add `ShouldQueue` in deployment/queue phase.

## 5.6. Tests must not rely on real mail/broadcast

Use:

```php
Notification::fake()
```

for dispatch tests, and database assertions where testing Bell/Dropdown.
---

# 6. GitFlow для Phase 31

## Base branch

Все задачи Phase 31 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-534-create-notifications-table
feature/RG-541-create-notification-bell-livewire-component
feature/RG-544-mark-notification-as-read
```

## Commit format

```txt
RG-534: Create notifications table
RG-541: Create NotificationBell Livewire component
RG-544: Mark notification as read
```

## Release branch

После выполнения `RG-534`–`RG-544`:

```txt
release/v0.2.12-phase31-notifications-foundation
```

## Tag

После merge release branch в `main`:

```txt
v0.2.12-phase31-notifications-foundation
```
---

# 7. TDD Rules for Phase 31

## Для таблицы notifications

Тестировать:

```txt
- notifications table exists;
- User can receive database notification;
- unread notifications can be queried.
```

## Для PostCommentedNotification

Тестировать:

```txt
- notification class exists;
- via returns database;
- toArray/toDatabase contains stable keys;
- comment by another user notifies post owner;
- own comment does not notify self.
```

## Для PostApprovedNotification

Тестировать:

```txt
- notification class exists;
- via returns database;
- toArray/toDatabase contains stable keys;
- approval notifies post owner;
- failed approval does not notify.
```

## Для NotificationBell

Тестировать:

```txt
- guest does not see bell;
- authenticated user sees bell;
- unread count is correct;
- dropdown renders notification messages;
- mark as read changes read_at only for current user's notification.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Notifications / Livewire / Tests
Type: Test / Feature / Component / Migration / UI
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
- Notifications are database-only
- No queue/email/broadcast scope creep
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 31 Atomic Tasks
---

## RG-534 — Create Notifications Table If Not Using Laravel Default

**Area:** Notifications / Database  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-534-create-notifications-table`  
**Base branch:** develop
**Depends on:** RG-533

### Goal

Подготовить стандартную database storage для Laravel notifications.

### TDD step

Database test:

```php
it('has notifications table', function () {
    expect(Schema::hasTable('notifications'))->toBeTrue();

    expect(Schema::hasColumns('notifications', [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'data',
        'read_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

User notifiable smoke test:

```php
it('allows user to receive database notification', function () {
    $user = User::factory()->create();

    expect(method_exists($user, 'notify'))->toBeTrue();
    expect(method_exists($user, 'unreadNotifications'))->toBeTrue();
});
```

### Implementation

Проверить, существует ли уже migration/table:

```bash
php artisan migrate:status
```

Если notifications migration отсутствует:

```bash
php artisan make:notifications-table
```

Then migrate in test env.

Проверить `User` model:

```php
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
}
```

Если trait уже есть — не трогать.

### Acceptance criteria

- `notifications` table exists.
- Table uses Laravel default database notification schema.
- `User` model has Notifiable behavior.
- No duplicate notifications migration.
- Tests pass.
- No email/broadcast/queue setup added.

### Definition of Done

- Tests written.
- Migration exists if needed.
- User Notifiable confirmed.
- Tests pass.
- Коммит: `RG-534: Create notifications table if not using Laravel default`

### Files likely touched

```txt
database/migrations/*_create_notifications_table.php
app/Models/User.php
tests/Feature/Notifications/NotificationsTableTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-535 — Create PostCommentedNotification

**Area:** Notifications  
**Type:** Notification Class  
**Priority:** P0  
**Branch:** `feature/RG-535-create-post-commented-notification`  
**Base branch:** develop
**Depends on:** RG-534

### Goal

Создать notification class для события "новый комментарий к твоему посту".

### TDD step

Unit test:

```php
it('creates post commented notification payload', function () {
    $postOwner = User::factory()->create([
        'username' => 'owner',
    ]);

    $commenter = User::factory()->create([
        'name' => 'Commenter',
        'username' => 'commenter',
    ]);

    $post = Post::factory()->for($postOwner)->published()->create([
        'title' => 'Pasta dish',
    ]);

    $comment = Comment::factory()
        ->for($post)
        ->for($commenter, 'user')
        ->create([
            'body' => 'Looks good.',
        ]);

    $notification = new PostCommentedNotification($post, $comment, $commenter);

    expect($notification->via($postOwner))->toBe(['database']);

    $data = $notification->toArray($postOwner);

    expect($data)->toMatchArray([
        'type' => 'post_commented',
        'post_id' => $post->id,
        'post_title' => 'Pasta dish',
        'comment_id' => $comment->id,
        'actor_id' => $commenter->id,
        'actor_username' => 'commenter',
    ]);

    expect($data)->toHaveKey('message');
    expect($data)->toHaveKey('url');
});
```

Тест должен упасть до создания notification.

### Implementation

Create:

```bash
php artisan make:notification PostCommentedNotification
```

Class:

```php
final class PostCommentedNotification extends Notification
{
    public function __construct(
        public readonly Post $post,
        public readonly Comment $comment,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'post_commented',
            'post_id' => $this->post->id,
            'post_title' => $this->post->title,
            'comment_id' => $this->comment->id,
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->name,
            'actor_username' => $this->actor->username,
            'message' => '@' . $this->actor->username . ' commented on your post',
            'url' => $this->postUrl(),
        ];
    }

    private function postUrl(): string
    {
        return Route::has('posts.show')
            ? route('posts.show', $this->post)
            : '#';
    }
}
```

If project has post show route with different name, use actual route.  
Do not create canonical URL helper in Phase 31; Phase 32 owns URL behavior.

### Acceptance criteria

- `PostCommentedNotification` exists.
- `via()` returns only `database`.
- Payload contains stable keys.
- Payload does not serialize full models.
- URL has safe fallback if post show route is unavailable.
- Test passes.

### Definition of Done

- Test written.
- Notification class created.
- Payload stable.
- Test passes.
- Коммит: `RG-535: Create PostCommentedNotification`

### Files likely touched

```txt
app/Notifications/PostCommentedNotification.php
tests/Unit/Notifications/PostCommentedNotificationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-536 — Test Comment Creates Notification For Post Owner

**Area:** Notifications / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-536-test-comment-creates-notification-for-post-owner`  
**Base branch:** develop
**Depends on:** RG-535

### Goal

Написать падающий тест: создание комментария через `AddCommentAction` отправляет notification владельцу post.

### TDD step

Feature/action test:

```php
it('notifies post owner when another user comments on their post', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::factory()
        ->for($postOwner)
        ->published()
        ->create();

    app(AddCommentAction::class)->handle(
        user: $commenter,
        post: $post,
        body: 'Looks good.'
    );

    Notification::assertSentTo(
        $postOwner,
        PostCommentedNotification::class
    );
});
```

Self-comment test:

```php
it('does not notify post owner when they comment on their own post', function () {
    Notification::fake();

    $owner = User::factory()->create();

    $post = Post::factory()
        ->for($owner)
        ->published()
        ->create();

    app(AddCommentAction::class)->handle(
        user: $owner,
        post: $post,
        body: 'My own comment.'
    );

    Notification::assertNothingSent();
});
```

Тест должен упасть до RG-537.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Test proves post owner is notified.
- Test proves self-comment does not notify.
- Test uses AddCommentAction, not UI.
- Test fails before implementation.
- No notification dispatch added in this task.

### Definition of Done

- Tests written.
- Tests expected to fail before implementation.
- Коммит: `RG-536: Test comment creates notification for post owner`

### Files likely touched

```txt
tests/Feature/Actions/AddCommentActionNotificationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-537 — Dispatch Notification After Comment

**Area:** Notifications / Comments Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-537-dispatch-notification-after-comment`  
**Base branch:** develop
**Depends on:** RG-536

### Goal

Отправлять `PostCommentedNotification` после успешного comment creation.

### TDD step

Использовать падающие тесты из RG-536.

### Implementation

В `AddCommentAction` после успешного создания comment и counter update:

```php
if ($post->user_id !== $user->id) {
    $post->user->notify(
        new PostCommentedNotification(
            post: $post,
            comment: $comment,
            actor: $user,
        )
    );
}
```

Important:

```txt
- dispatch only after comment was actually created;
- do not dispatch if validation/guard fails;
- do not dispatch self-comment;
- do not dispatch from CommentForm.
```

Ensure `Post` relation has owner:

```php
public function user(): BelongsTo
```

If relation is named `author`, use actual relation.

Potential transaction concern:

```txt
If comment creation is inside DB::transaction, notification should be sent after the transaction closure returns.
```

Better:

```php
$comment = DB::transaction(...);

$this->notifyPostOwner($user, $post->fresh('user'), $comment);

return $comment;
```

For MVP, inside transaction is acceptable but less clean. Prefer after transaction.

### Acceptance criteria

- Post owner notified after another user comments.
- Self-comment does not notify.
- Failed comment action does not notify.
- Notification dispatched from AddCommentAction.
- Tests pass.

### Definition of Done

- Notification dispatch added.
- Tests pass.
- Коммит: `RG-537: Dispatch notification after comment`

### Files likely touched

```txt
app/Actions/Comments/AddCommentAction.php
tests/Feature/Actions/AddCommentActionNotificationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-538 — Create PostApprovedNotification

**Area:** Notifications  
**Type:** Notification Class  
**Priority:** P0  
**Branch:** `feature/RG-538-create-post-approved-notification`  
**Base branch:** develop
**Depends on:** RG-537

### Goal

Создать notification class для события "твой пост approved".

### TDD step

Unit test:

```php
it('creates post approved notification payload', function () {
    $postOwner = User::factory()->create([
        'username' => 'owner',
    ]);

    $moderator = User::factory()->moderator()->create([
        'name' => 'Moderator',
        'username' => 'moderator',
    ]);

    $post = Post::factory()->for($postOwner)->published()->create([
        'title' => 'Approved dish',
    ]);

    $notification = new PostApprovedNotification($post, $moderator);

    expect($notification->via($postOwner))->toBe(['database']);

    $data = $notification->toArray($postOwner);

    expect($data)->toMatchArray([
        'type' => 'post_approved',
        'post_id' => $post->id,
        'post_title' => 'Approved dish',
        'actor_id' => $moderator->id,
        'actor_username' => 'moderator',
    ]);

    expect($data)->toHaveKey('message');
    expect($data)->toHaveKey('url');
});
```

### Implementation

Create:

```bash
php artisan make:notification PostApprovedNotification
```

Class:

```php
final class PostApprovedNotification extends Notification
{
    public function __construct(
        public readonly Post $post,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'post_approved',
            'post_id' => $this->post->id,
            'post_title' => $this->post->title,
            'actor_id' => $this->actor->id,
            'actor_name' => $this->actor->name,
            'actor_username' => $this->actor->username,
            'message' => 'Your post was approved',
            'url' => $this->postUrl(),
        ];
    }

    private function postUrl(): string
    {
        return Route::has('posts.show')
            ? route('posts.show', $this->post)
            : '#';
    }
}
```

### Acceptance criteria

- `PostApprovedNotification` exists.
- `via()` returns only `database`.
- Payload contains stable keys.
- Payload does not serialize full models.
- URL has safe fallback.
- Test passes.

### Definition of Done

- Test written.
- Notification class created.
- Payload stable.
- Test passes.
- Коммит: `RG-538: Create PostApprovedNotification`

### Files likely touched

```txt
app/Notifications/PostApprovedNotification.php
tests/Unit/Notifications/PostApprovedNotificationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-539 — Test Post Approval Creates Notification

**Area:** Notifications / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-539-test-post-approval-creates-notification`  
**Base branch:** develop
**Depends on:** RG-538

### Goal

Написать падающий тест: approval post через `ApprovePostAction` отправляет notification владельцу post.

### TDD step

Feature/action test:

```php
it('notifies post owner when post is approved', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $moderator = User::factory()->moderator()->create();

    $post = Post::factory()
        ->for($owner)
        ->pending()
        ->create();

    app(ApprovePostAction::class)->handle(
        moderator: $moderator,
        post: $post,
        reason: 'Valid post.'
    );

    Notification::assertSentTo(
        $owner,
        PostApprovedNotification::class
    );
});
```

Failed approval no notification:

```php
it('does not notify when post approval fails', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $normalUser = User::factory()->create();

    $post = Post::factory()
        ->for($owner)
        ->pending()
        ->create();

    try {
        app(ApprovePostAction::class)->handle($normalUser, $post);
    } catch (CannotModeratePostException) {
        // expected
    }

    Notification::assertNothingSent();
});
```

Тест должен упасть до RG-540.

### Implementation

Только добавить тесты.

### Acceptance criteria

- Test proves post owner is notified on approval.
- Test proves failed approval does not notify.
- Test uses ApprovePostAction, not Filament/Livewire UI.
- Test fails before implementation.
- No notification dispatch added in this task.

### Definition of Done

- Tests written.
- Tests expected to fail before implementation.
- Коммит: `RG-539: Test post approval creates notification`

### Files likely touched

```txt
tests/Feature/Actions/ApprovePostActionNotificationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-540 — Dispatch Notification After Approval

**Area:** Notifications / Moderation Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-540-dispatch-notification-after-approval`  
**Base branch:** develop
**Depends on:** RG-539

### Goal

Отправлять `PostApprovedNotification` после успешного post approval.

### TDD step

Использовать падающие тесты из RG-539.

### Implementation

В `ApprovePostAction` после успешного status change and moderation log:

```php
if ($post->user_id !== $moderator->id) {
    $post->user->notify(
        new PostApprovedNotification(
            post: $post,
            actor: $moderator,
        )
    );
}
```

Important:

```txt
- dispatch only after successful approval;
- do not dispatch on invalid transition;
- do not dispatch on unauthorized action;
- do not dispatch from Filament/PostResource/InlinePostModeration;
- action caller agnostic.
```

If approval is inside transaction, send notification after transaction.

Recommended structure:

```php
DB::transaction(function () { ... });

$this->notifyPostOwner($moderator, $post->fresh('user'));
```

### Acceptance criteria

- Post owner notified after approval.
- Failed approval does not notify.
- Notification dispatched from ApprovePostAction.
- Works regardless of whether approval came from inline UI, Filament, dashboard, or tests.
- Tests pass.

### Definition of Done

- Notification dispatch added.
- Tests pass.
- Коммит: `RG-540: Dispatch notification after approval`

### Files likely touched

```txt
app/Actions/Moderation/ApprovePostAction.php
tests/Feature/Actions/ApprovePostActionNotificationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-541 — Create NotificationBell Livewire Component

**Area:** Livewire / Notifications UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-541-create-notification-bell-livewire-component`  
**Base branch:** develop
**Depends on:** RG-540

### Goal

Создать Livewire component `NotificationBell`.

### TDD step

Livewire component test:

```php
it('can render notification bell for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertStatus(200)
        ->assertSee('data-testid="notification-bell"', false);
});
```

Guest test:

```php
it('does not render notification bell for guest', function () {
    Livewire::test(NotificationBell::class)
        ->assertDontSee('data-testid="notification-bell"', false);
});
```

Тест должен упасть до component creation.

### Implementation

Create:

```bash
php artisan make:livewire Notifications/NotificationBell
```

Files:

```txt
app/Livewire/Notifications/NotificationBell.php
resources/views/livewire/notifications/notification-bell.blade.php
```

Class skeleton:

```php
final class NotificationBell extends Component
{
    public function render(): View
    {
        return view('livewire.notifications.notification-bell');
    }
}
```

View skeleton:

```blade
@if(auth()->check())
    <div data-testid="notification-bell">
        Notifications
    </div>
@endif
```

Optionally integrate in authenticated header/layout:

```blade
<livewire:notifications.notification-bell />
```

But if layout location is not stable, component creation is enough for RG-541. Integration can happen in RG-543.

### Acceptance criteria

- `NotificationBell` exists.
- Authenticated user sees bell wrapper.
- Guest does not see bell.
- No dropdown/read logic yet.
- Tests pass.

### Definition of Done

- Tests written.
- Component created.
- Tests pass.
- Коммит: `RG-541: Create NotificationBell Livewire component`

### Files likely touched

```txt
app/Livewire/Notifications/NotificationBell.php
resources/views/livewire/notifications/notification-bell.blade.php
tests/Feature/Livewire/NotificationBellTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-542 — Test NotificationBell Shows Unread Count

**Area:** Livewire / Notifications UI / Tests  
**Type:** Test + Feature  
**Priority:** P0  
**Branch:** `feature/RG-542-test-notification-bell-shows-unread-count`  
**Base branch:** develop
**Depends on:** RG-541

### Goal

NotificationBell должен показывать unread count текущего пользователя.

### TDD step

Livewire test:

```php
it('shows unread notification count for authenticated user', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification(['message' => 'Unread 1']));
    $user->notify(new TestDatabaseNotification(['message' => 'Unread 2']));

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('2');
});
```

Read notification ignored:

```php
it('does not count read notifications', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification(['message' => 'Unread']));
    $user->notify(new TestDatabaseNotification(['message' => 'Read']));

    $user->notifications()->latest()->first()->markAsRead();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('1');
});
```

Other user's notifications ignored:

```php
it('does not count other users notifications', function () {
    ...
});
```

`TestDatabaseNotification` can be a test-only class or use existing notification with fake post/comment.

### Implementation

In `NotificationBell`:

```php
public function getUnreadCountProperty(): int
{
    if (! auth()->check()) {
        return 0;
    }

    return auth()->user()
        ->unreadNotifications()
        ->count();
}
```

View:

```blade
@if(auth()->check())
    <button data-testid="notification-bell">
        <span>Notifications</span>

        @if($this->unreadCount > 0)
            <span data-testid="notification-unread-count">
                {{ $this->unreadCount }}
            </span>
        @endif
    </button>
@endif
```

### Acceptance criteria

- Unread count visible.
- Read notifications not counted.
- Other users' notifications not counted.
- Count hidden or zero when no unread notifications.
- Tests pass.

### Definition of Done

- Tests written.
- Unread count implemented.
- Tests pass.
- Коммит: `RG-542: Test NotificationBell shows unread count`

### Files likely touched

```txt
app/Livewire/Notifications/NotificationBell.php
resources/views/livewire/notifications/notification-bell.blade.php
tests/Feature/Livewire/NotificationBellTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-543 — Render Notifications Dropdown

**Area:** Livewire / Notifications UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-543-render-notifications-dropdown`  
**Base branch:** develop
**Depends on:** RG-542

### Goal

Отобразить dropdown со списком последних notifications.

### TDD step

Livewire/render test:

```php
it('renders notifications dropdown with notification messages', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification([
        'message' => 'Your post was approved',
        'url' => '/posts/1',
    ]));

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('data-testid="notifications-dropdown"', false)
        ->assertSee('Your post was approved');
});
```

Empty state:

```php
it('renders notifications empty state', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('No notifications yet');
});
```

Limit test:

```php
it('renders only latest notifications in dropdown', ...)
```

Optional but useful.

### Implementation

In `NotificationBell`:

```php
public function getNotificationsProperty(): Collection
{
    if (! auth()->check()) {
        return collect();
    }

    return auth()->user()
        ->notifications()
        ->latest()
        ->limit(10)
        ->get();
}
```

View with Alpine dropdown:

```blade
@if(auth()->check())
    <div
        x-data="{ open: false }"
        data-testid="notification-bell"
        @keydown.escape.window="open = false"
    >
        <button type="button" @click="open = ! open">
            Bell
            ...
        </button>

        <div
            x-show="open"
            x-cloak
            data-testid="notifications-dropdown"
        >
            @forelse($this->notifications as $notification)
                <a
                    href="{{ $notification->data['url'] ?? '#' }}"
                    data-testid="notification-item"
                    class="{{ $notification->read_at ? 'opacity-60' : '' }}"
                >
                    <span>{{ $notification->data['message'] ?? 'Notification' }}</span>
                    <time>{{ $notification->created_at->diffForHumans() }}</time>
                </a>
            @empty
                <div>No notifications yet</div>
            @endforelse
        </div>
    </div>
@endif
```

Integrate bell into authenticated navigation layout if exists:

```blade
<livewire:notifications.notification-bell />
```

Do not add full notifications page.

### Acceptance criteria

- Dropdown exists.
- Shows latest notifications.
- Shows message from data.
- Shows safe fallback when message/url missing.
- Shows empty state.
- Limits list to 10.
- Guest does not see dropdown.
- Bell integrated into authenticated layout if layout exists.
- Tests pass.

### Definition of Done

- Tests written.
- Dropdown rendered.
- Optional layout integration done.
- Tests pass.
- Коммит: `RG-543: Render notifications dropdown`

### Files likely touched

```txt
app/Livewire/Notifications/NotificationBell.php
resources/views/livewire/notifications/notification-bell.blade.php
resources/views/layouts/navigation.blade.php
resources/views/components/layout/header.blade.php
tests/Feature/Livewire/NotificationBellTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-544 — Mark Notification As Read

**Area:** Livewire / Notifications UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-544-mark-notification-as-read`  
**Base branch:** develop
**Depends on:** RG-543

### Goal

Добавить action для mark one notification as read.

### TDD step

Livewire test:

```php
it('marks notification as read', function () {
    $user = User::factory()->create();

    $user->notify(new TestDatabaseNotification([
        'message' => 'Unread notification',
    ]));

    $notification = $user->notifications()->first();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('markAsRead', $notification->id)
        ->assertSee('0');

    expect($notification->fresh()->read_at)->not->toBeNull();
});
```

Security test:

```php
it('does not mark another users notification as read', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $owner->notify(new TestDatabaseNotification([
        'message' => 'Private notification',
    ]));

    $notification = $owner->notifications()->first();

    Livewire::actingAs($attacker)
        ->test(NotificationBell::class)
        ->call('markAsRead', $notification->id);

    expect($notification->fresh()->read_at)->toBeNull();
});
```

Already read idempotent test optional:

```php
it('marking already read notification is safe', ...)
```

### Implementation

In `NotificationBell`:

```php
public function markAsRead(string $notificationId): void
{
    if (! auth()->check()) {
        return;
    }

    $notification = auth()->user()
        ->notifications()
        ->whereKey($notificationId)
        ->first();

    if (! $notification) {
        return;
    }

    $notification->markAsRead();
}
```

View item action:

```blade
<button
    type="button"
    wire:click.prevent="markAsRead('{{ $notification->id }}')"
    data-testid="mark-notification-read"
>
    Mark as read
</button>
```

Alternative UX:

```txt
clicking notification item marks it as read and follows URL
```

But that is trickier because Livewire click + navigation. MVP uses explicit button.

Add final review doc:

```txt
docs/design/phase-31-notifications-foundation-review.md
```

Checklist:

```txt
- bell visible to authenticated user;
- guest hidden;
- unread count correct;
- dropdown empty state;
- dropdown with items;
- mark as read updates count;
- mobile header checked.
```

### Acceptance criteria

- User can mark own notification as read.
- read_at is set.
- unread count updates.
- User cannot mark another user's notification as read.
- Missing/invalid notification id does not crash.
- Explicit mark-as-read button exists.
- No mark-all-as-read added.
- Design review note exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- markAsRead implemented.
- Security scoping implemented.
- Review note added.
- Tests/build pass.
- Коммит: `RG-544: Mark notification as read`

### Files likely touched

```txt
app/Livewire/Notifications/NotificationBell.php
resources/views/livewire/notifications/notification-bell.blade.php
docs/design/phase-31-notifications-foundation-review.md
tests/Feature/Livewire/NotificationBellTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 31 Completion Criteria

Phase 31 завершена, когда:

```txt
- RG-534–RG-544 выполнены;
- notifications table exists;
- User model can receive Laravel notifications;
- PostCommentedNotification exists;
- PostCommentedNotification uses database channel only;
- comment by another user notifies post owner;
- own comment does not notify self;
- PostApprovedNotification exists;
- PostApprovedNotification uses database channel only;
- successful post approval notifies post owner;
- failed approval does not notify;
- NotificationBell exists;
- guest does not see bell;
- authenticated user sees bell;
- unread count is correct;
- notifications dropdown renders latest notifications;
- dropdown has empty state;
- mark notification as read works;
- user cannot mark another user's notification read;
- no email/push/broadcast/queue implementation added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 31

Без отдельной задачи нельзя:

```txt
- добавлять email notifications;
- добавлять broadcast/realtime notifications;
- добавлять Pusher/Echo/websockets;
- добавлять notification preferences;
- добавлять notification settings page;
- добавлять notification archive page;
- добавлять mark all as read;
- добавлять delete notification;
- добавлять notification pagination page;
- добавлять queue worker/Redis setup;
- добавлять canonical URL helper;
- добавлять share panel/copy link;
- добавлять Open Graph behavior;
- добавлять API endpoints;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-534 Create notifications table if not using Laravel default
RG-535 Create PostCommentedNotification
RG-536 Test comment creates notification for post owner
RG-537 Dispatch notification after comment
RG-538 Create PostApprovedNotification
RG-539 Test post approval creates notification
RG-540 Dispatch notification after approval
RG-541 Create NotificationBell Livewire component
RG-542 Test NotificationBell shows unread count
RG-543 Render notifications dropdown
RG-544 Mark notification as read
```
---

# 13. Release

После завершения Phase 31:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.12-phase31-notifications-foundation
git push -u origin release/v0.2.12-phase31-notifications-foundation
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.12-phase31-notifications-foundation -m "RateGuru Phase 31 Notifications Foundation"
git push origin v0.2.12-phase31-notifications-foundation
```
