# RateGuru — Phase 13 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 13 — Up/Down Voting**  
Диапазон задач: **RG-254 → RG-279**  
Основа нумерации: исходный atomic backlog, где Phase 13 начинается с задачи 254 и заканчивается задачей 279.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 13 соответствует исходному блоку:

```txt
Phase 13 — Up/Down Voting
```

Правильный диапазон Phase 13:

```txt
RG-254 — Create VotePostAction skeleton
RG-255 — Test user can upvote post
RG-256 — Implement upvote creation
RG-257 — Test user can downvote post
RG-258 — Implement downvote creation
RG-259 — Test upvote toggles off when clicked again
RG-260 — Implement same-vote toggle
RG-261 — Test downvote toggles off when clicked again
RG-262 — Implement downvote toggle
RG-263 — Test downvote replaces upvote
RG-264 — Implement vote replacement
RG-265 — Test upvote replaces downvote
RG-266 — Implement reverse vote replacement
RG-267 — Test guest cannot vote
RG-268 — Add auth guard for voting
RG-269 — Test banned user cannot vote
RG-270 — Add banned user guard for voting
RG-271 — Test hidden post cannot be voted on
RG-272 — Add post status guard for voting
RG-273 — Create PostVoting Livewire component
RG-274 — Test PostVoting calls VotePostAction
RG-275 — Render up/down buttons in PostCard
RG-276 — Render up/down buttons in PostDrawer
RG-277 — Render up/down buttons in PostShow
RG-278 — Add optimistic-looking loading state to vote buttons
RG-279 — Refresh vote counters after vote
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 13

Phase 13 добавляет полноценное up/down голосование:

```txt
- backend action VotePostAction;
- создание upvote;
- создание downvote;
- toggle same vote off;
- replacement vote: up → down и down → up;
- auth guard;
- banned user guard;
- published-only guard;
- PostVoting Livewire component;
- кнопки up/down в PostCard;
- кнопки up/down в PostDrawer;
- кнопки up/down в PostShow;
- loading state;
- refresh counters after vote.
```

После Phase 13 пользователь должен уметь голосовать за опубликованные посты из:

```txt
- карточки в ленте;
- drawer;
- standalone post page.
```
---

# 3. Scope Phase 13

## Входит

```txt
- VotePostAction;
- PostVote creation/update/delete;
- counters update on posts;
- vote toggle behavior;
- vote replacement behavior;
- auth/user/post guards;
- PostVoting Livewire component;
- integration into PostCard/PostDrawer/PostShow;
- loading state on vote buttons;
- refresh counters after vote.
```

## Не входит

```txt
- Homemade/Restaurant voting;
- cuisine voting;
- comments;
- reports;
- moderation;
- analytics;
- anti-fraud;
- rate limiting;
- IP/device tracking;
- notification events;
- hot_score recalculation;
- feed ranking recalculation;
- optimistic client-only state without server confirmation.
```

Homemade/Restaurant voting будет Phase 14.  
Cuisine voting будет Phase 15.  
Hot score calculation будет позже отдельной фазой.
---

# 4. Product Rules

## 4.1. One user can have one post vote per post

Таблица `post_votes` уже имеет unique constraint:

```txt
post_id + user_id
```

Это значит:

```txt
- user cannot have both upvote and downvote simultaneously;
- replacement updates existing row;
- same-vote toggle deletes existing row.
```

## 4.2. Vote behavior

Правила:

```txt
No vote + Up      → create upvote, increment upvotes_count
No vote + Down    → create downvote, increment downvotes_count

Up + Up           → delete vote, decrement upvotes_count
Down + Down       → delete vote, decrement downvotes_count

Up + Down         → update vote to down, decrement upvotes_count, increment downvotes_count
Down + Up         → update vote to up, decrement downvotes_count, increment upvotes_count
```

## 4.3. Counters must stay consistent

`posts.upvotes_count` и `posts.downvotes_count` должны отражать таблицу `post_votes`.

В Phase 13 допустимо обновлять counters синхронно внутри `VotePostAction`.

Важно:

```txt
- counters не должны уходить ниже 0;
- replacement должен менять оба счётчика;
- toggle off должен уменьшать только активный счётчик;
- action должен быть transaction-safe.
```

## 4.4. Only published posts can receive votes

Нельзя голосовать за:

```txt
draft
pending
hidden
rejected
deleted
```

Это защита не только UI, но и backend action.

## 4.5. Guest cannot vote

Гость может видеть ленту и страницы, но голосовать может только authenticated user.

UI может показывать disabled/login prompt, но backend обязан блокировать guest.

## 4.6. Banned user cannot vote

Banned user не должен голосовать, даже если authenticated.

Рекомендуемое правило:

```php
$user->canCreateContent()
```

уже есть, но для голосования название не идеально. Лучше в Phase 13 добавить отдельный метод:

```php
$user->canVote()
```

Если не хочется расширять User model в этой фазе, использовать `status === UserStatus::Active`.
---

# 5. Architecture Rules

## 5.1. VotePostAction owns business logic

Нельзя реализовывать голосование прямо в Livewire component:

```php
PostVote::updateOrCreate(...)
$post->increment(...)
```

Правильно:

```php
app(VotePostAction::class)->handle($user, $post, VoteType::Up);
```

## 5.2. PostVoting Livewire component is UI wrapper

`PostVoting` отвечает за:

```txt
- отображение counters;
- отображение active user vote;
- вызов VotePostAction;
- dispatch event после vote;
- loading state.
```

Он не должен дублировать бизнес-логику action.

## 5.3. PostCard/PostDrawer/PostShow should embed PostVoting

Не копировать up/down buttons в три места вручную.

Правильно:

```blade
<livewire:posts.post-voting :post="$post" />
```

или:

```blade
<livewire:posts.post-voting :post-id="$post->id" :key="'vote-'.$post->id" />
```

Рекомендация: передавать `postId`, а component сам загружает post. Это безопаснее для refresh counters.

## 5.4. Refresh counters after vote

После успешного голосования:

```txt
- PostVoting refreshes its own counters;
- parent PostCard/Drawer/Show can listen to vote event if needed;
- FeedPage may refresh if counters are displayed outside PostVoting.
```

Минимально достаточно:

```txt
PostVoting re-loads post counters after action.
```

Если PostCard уже показывает отдельный stats area вне PostVoting, его надо заменить/синхронизировать.

## 5.5. No hot_score recalculation in Phase 13

Голосование меняет counters, но не пересчитывает `hot_score`.

Это отдельная будущая фаза.  
Иначе Phase 13 раздуется и начнёт ломать FeedQuery hot sorting.
---

# 6. Design Constraints

Vote buttons должны соответствовать RateGuru UI:

```txt
- dark button surface;
- clear up/down icons or labels;
- active state with purple/accent;
- disabled state for guest/banned;
- compact in PostCard;
- larger/readable in Drawer/PostShow;
- loading state without layout jump.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-8-feed-ui-review.md
docs/design/phase-11-drawer-ui-review.md
docs/design/phase-12-post-show-page-review.md
```

Если последнего файла нет, не блокировать Phase 13, но зафиксировать missing previous review в PR notes.
---

# 7. GitFlow для Phase 13

## Base branch

Все задачи Phase 13 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-254-create-vote-post-action-skeleton
feature/RG-264-implement-vote-replacement
feature/RG-279-refresh-vote-counters-after-vote
```

## Commit format

```txt
RG-254: Create VotePostAction skeleton
RG-264: Implement vote replacement
RG-279: Refresh vote counters after vote
```

## Release branch

После выполнения `RG-254`–`RG-279`:

```txt
release/v0.1.4-phase13-up-down-voting
```

## Tag

После merge release branch в `main`:

```txt
v0.1.4-phase13-up-down-voting
```
---

# 8. TDD Rules for Phase 13

## Для VotePostAction

Каждое поведение делается строго test-first:

```txt
- user can upvote;
- user can downvote;
- upvote toggles off;
- downvote toggles off;
- downvote replaces upvote;
- upvote replaces downvote;
- guest cannot vote;
- banned user cannot vote;
- hidden post cannot be voted on.
```

## Для counters

Каждый action test должен проверять:

```txt
- post_votes row;
- upvotes_count;
- downvotes_count;
- no negative counters.
```

## Для Livewire PostVoting

Тестировать:

```txt
- component renders;
- click calls action;
- counters update;
- active state renders;
- guest/banned disabled or blocked.
```

## Для UI integration

Тестировать presence of component/markup:

```txt
- PostCard includes PostVoting;
- PostDrawer includes PostVoting;
- PostShow includes PostVoting.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Livewire / UI / Tests
Type: Test / Feature / Action / Component / Integration
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
- Нет логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 13 Atomic Tasks
---

## RG-254 — Create VotePostAction Skeleton

**Area:** Backend  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-254-create-vote-post-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-253

### Goal

Создать skeleton action для up/down voting.

### TDD step

Unit test:

```php
it('has vote post action with handle method', function () {
    $action = app(VotePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Votes/VotePostAction.php
```

Skeleton:

```php
namespace App\Actions\Votes;

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\User;

final class VotePostAction
{
    public function handle(User $user, Post $post, VoteType $type): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

На этой задаче не реализовывать поведение.

### Acceptance criteria

- `VotePostAction` существует.
- Есть `handle(User $user, Post $post, VoteType $type): void`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-254: Create VotePostAction skeleton`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Unit/Actions/VotePostActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-255 — Test User Can Upvote Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-255-test-user-can-upvote-post`  
**Base branch:** develop
**Depends on:** RG-254

### Goal

Написать падающий тест: authenticated active user может поставить upvote published post.

### TDD step

Feature/action test:

```php
it('allows user to upvote a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(0);
});
```

Тест должен упасть до RG-256.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет row in `post_votes`.
- Тест проверяет `upvotes_count`.
- Тест проверяет `downvotes_count`.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-255: Test user can upvote post`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-256 — Implement Upvote Creation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-256-implement-upvote-creation`  
**Base branch:** develop
**Depends on:** RG-255

### Goal

Реализовать создание upvote.

### TDD step

Использовать падающий тест из RG-255.

### Implementation

В `VotePostAction::handle()`:

```php
DB::transaction(function () use ($user, $post, $type) {
    PostVote::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => $type,
    ]);

    if ($type === VoteType::Up) {
        $post->increment('upvotes_count');
    }
});
```

На этой задаче можно реализовать только ветку `VoteType::Up`.  
Но не ломать будущие downvote tests.

Лучше сразу сделать минимальную структуру:

```php
if ($type === VoteType::Up) { ... }
if ($type === VoteType::Down) { ... later }
```

Пока не делать toggle/replacement.

### Acceptance criteria

- User can upvote published post.
- `post_votes` row created.
- upvotes_count increments.
- downvotes_count unchanged.
- Transaction used.
- Test RG-255 passes.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-256: Implement upvote creation`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-257 — Test User Can Downvote Post

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-257-test-user-can-downvote-post`  
**Base branch:** develop
**Depends on:** RG-256

### Goal

Написать падающий тест: authenticated active user может поставить downvote published post.

### TDD step

Feature/action test:

```php
it('allows user to downvote a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteType::Down);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Down->value,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(1);
});
```

Тест должен упасть до RG-258.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет row in `post_votes`.
- Тест проверяет counters.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-257: Test user can downvote post`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-258 — Implement Downvote Creation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-258-implement-downvote-creation`  
**Base branch:** develop
**Depends on:** RG-257

### Goal

Реализовать создание downvote.

### TDD step

Использовать падающий тест из RG-257.

### Implementation

В `VotePostAction` добавить ветку `VoteType::Down`:

```php
if ($type === VoteType::Down) {
    PostVote::create([...]);
    $post->increment('downvotes_count');
}
```

Не реализовывать toggle/replacement в этой задаче.  
Если текущий код дублируется, можно аккуратно подготовить приватные методы:

```php
private function incrementCounter(Post $post, VoteType $type): void
```

Но не раздувать.

### Acceptance criteria

- User can downvote published post.
- `post_votes` row created.
- downvotes_count increments.
- upvotes_count unchanged.
- Upvote behavior still works.
- Tests RG-255/RG-257 pass.

### Definition of Done

- Реализация минимальная.
- Тесты проходят.
- Коммит: `RG-258: Implement downvote creation`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-259 — Test Upvote Toggles Off When Clicked Again

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-259-test-upvote-toggles-off-when-clicked-again`  
**Base branch:** develop
**Depends on:** RG-258

### Goal

Написать падающий тест: повторный upvote снимает upvote.

### TDD step

Feature/action test:

```php
it('toggles upvote off when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Up);

    $this->assertDatabaseMissing('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(0);
});
```

Тест должен упасть до RG-260.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Повторный upvote удаляет vote row.
- upvotes_count возвращается к 0.
- downvotes_count не меняется.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-259: Test upvote toggles off when clicked again`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-260 — Implement Same-Vote Toggle

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-260-implement-same-vote-toggle`  
**Base branch:** develop
**Depends on:** RG-259

### Goal

Реализовать toggle off для same upvote.

### TDD step

Использовать падающий тест из RG-259.

### Implementation

В action:

```php
$existingVote = PostVote::query()
    ->where('post_id', $post->id)
    ->where('user_id', $user->id)
    ->first();

if ($existingVote && $existingVote->type === $type) {
    $existingVote->delete();
    $this->decrementCounter($post, $type);
    return;
}
```

Нужен helper:

```php
private function decrementCounter(Post $post, VoteType $type): void
{
    $column = $type === VoteType::Up ? 'upvotes_count' : 'downvotes_count';

    if ($post->fresh()->{$column} > 0) {
        $post->decrement($column);
    }
}
```

Не допускать negative counters.

### Acceptance criteria

- Повторный upvote снимает vote.
- upvotes_count decrements safely.
- downvotes_count unchanged.
- No negative counters.
- Upvote/downvote creation still works.
- Tests pass.

### Definition of Done

- Toggle logic добавлена.
- Тесты проходят.
- Коммит: `RG-260: Implement same-vote toggle`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-261 — Test Downvote Toggles Off When Clicked Again

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-261-test-downvote-toggles-off-when-clicked-again`  
**Base branch:** develop
**Depends on:** RG-260

### Goal

Написать падающий тест: повторный downvote снимает downvote.

### TDD step

Feature/action test:

```php
it('toggles downvote off when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Down);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Down);

    $this->assertDatabaseMissing('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(0);
});
```

Если RG-260 implemented same-vote toggle generically, тест может сразу пройти. Это нормально как regression test.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Повторный downvote удаляет vote row.
- downvotes_count возвращается к 0.
- upvotes_count не меняется.
- Тест проходит после RG-262 или уже после RG-260.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-261: Test downvote toggles off when clicked again`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-262 — Implement Downvote Toggle

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-262-implement-downvote-toggle`  
**Base branch:** develop
**Depends on:** RG-261

### Goal

Убедиться, что downvote toggle реализован.

### TDD step

Использовать тест из RG-261.

### Implementation

Если same-vote toggle из RG-260 уже generic, изменений может не потребоваться.  
Если реализация была only-upvote, расширить её на `VoteType::Down`.

### Acceptance criteria

- Повторный downvote снимает vote.
- downvotes_count decrements safely.
- No negative counters.
- All previous vote tests pass.

### Definition of Done

- Downvote toggle работает.
- Тесты проходят.
- Коммит: `RG-262: Implement downvote toggle`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-263 — Test Downvote Replaces Upvote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-263-test-downvote-replaces-upvote`  
**Base branch:** develop
**Depends on:** RG-262

### Goal

Написать падающий тест: если user уже поставил upvote, downvote должен заменить его.

### TDD step

Feature/action test:

```php
it('replaces upvote with downvote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Down);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Down->value,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(1);
});
```

Тест должен упасть до RG-264.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Existing vote row updates to down.
- upvotes_count decrements.
- downvotes_count increments.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-263: Test downvote replaces upvote`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-264 — Implement Vote Replacement

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-264-implement-vote-replacement`  
**Base branch:** develop
**Depends on:** RG-263

### Goal

Реализовать replacement upvote → downvote.

### TDD step

Использовать падающий тест из RG-263.

### Implementation

В action:

```php
if ($existingVote && $existingVote->type !== $type) {
    $oldType = $existingVote->type;

    $existingVote->update([
        'type' => $type,
    ]);

    $this->decrementCounter($post, $oldType);
    $this->incrementCounter($post, $type);

    return;
}
```

Важно: если casts возвращают `VoteType`, сравнивать enum, не строки.

### Acceptance criteria

- Downvote replaces upvote.
- Vote row остается один.
- upvotes_count decrements.
- downvotes_count increments.
- Unique constraint не падает.
- Tests pass.

### Definition of Done

- Replacement logic добавлена.
- Тесты проходят.
- Коммит: `RG-264: Implement vote replacement`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-265 — Test Upvote Replaces Downvote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-265-test-upvote-replaces-downvote`  
**Base branch:** develop
**Depends on:** RG-264

### Goal

Написать тест: если user уже поставил downvote, upvote должен заменить его.

### TDD step

Feature/action test:

```php
it('replaces downvote with upvote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Down);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Up);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(0);
});
```

Если RG-264 implemented generic replacement, тест может сразу пройти.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Existing vote row updates to up.
- downvotes_count decrements.
- upvotes_count increments.
- Тест проходит после RG-266 или уже после RG-264.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-265: Test upvote replaces downvote`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-266 — Implement Reverse Vote Replacement

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-266-implement-reverse-vote-replacement`  
**Base branch:** develop
**Depends on:** RG-265

### Goal

Убедиться, что replacement downvote → upvote работает.

### TDD step

Использовать тест из RG-265.

### Implementation

Если replacement logic из RG-264 generic — изменений не требуется.  
Если была only up→down ветка, расширить на оба направления.

### Acceptance criteria

- Upvote replaces downvote.
- Vote row remains one.
- downvotes_count decrements.
- upvotes_count increments.
- All previous vote tests pass.

### Definition of Done

- Reverse replacement работает.
- Тесты проходят.
- Коммит: `RG-266: Implement reverse vote replacement`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-267 — Test Guest Cannot Vote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-267-test-guest-cannot-vote`  
**Base branch:** develop
**Depends on:** RG-266

### Goal

Написать тест: guest не может голосовать.

Так как `VotePostAction` принимает `User`, guest guard в чистом action невозможен напрямую. Нужно тестировать через `PostVoting` позже или создать service method accepting nullable user. Но исходный порядок ставит guest test до Livewire component.

Решение: добавить explicit exception/guard helper и тестировать через nullable wrapper method.

Лучший вариант для Phase 13:

```txt
VotePostAction::handle(?User $user, Post $post, VoteType $type)
```

Сигнатуру можно изменить на nullable User.
```

### TDD step

Feature/action test:

```php
it('does not allow guest to vote', function () {
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle(null, $post, VoteType::Up);
})->throws(CannotVoteException::class);
```

Если не хочется менять signature, тогда RG-267 можно временно написать как будущий Livewire test, но это нарушит порядок. Поэтому nullable user signature лучше.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Guest/null user получает explicit exception.
- Vote row не создаётся.
- Counters не меняются.
- Тест падает до RG-268.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-267: Test guest cannot vote`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-268 — Add Auth Guard For Voting

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-268-add-auth-guard-for-voting`  
**Base branch:** develop
**Depends on:** RG-267

### Goal

Добавить auth guard в VotePostAction.

### TDD step

Использовать падающий тест из RG-267.

### Implementation

Создать exception:

```txt
app/Exceptions/Votes/CannotVoteException.php
```

Пример:

```php
final class CannotVoteException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot be voted on.');
    }
}
```

Изменить signature:

```php
public function handle(?User $user, Post $post, VoteType $type): void
```

В начале:

```php
if ($user === null) {
    throw CannotVoteException::becauseGuest();
}
```

### Acceptance criteria

- Guest/null user blocked.
- Explicit exception.
- Vote row not created.
- Counters unchanged.
- Authenticated user behavior still works.
- Tests pass.

### Definition of Done

- Exception добавлен.
- Auth guard добавлен.
- Тесты проходят.
- Коммит: `RG-268: Add auth guard for voting`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
app/Exceptions/Votes/CannotVoteException.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-269 — Test Banned User Cannot Vote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-269-test-banned-user-cannot-vote`  
**Base branch:** develop
**Depends on:** RG-268

### Goal

Написать тест: banned user не может голосовать.

### TDD step

Feature/action test:

```php
it('does not allow banned user to vote', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
})->throws(CannotVoteException::class);
```

Проверить no side effects:

```php
expect(PostVote::query()->count())->toBe(0);
expect($post->fresh()->upvotes_count)->toBe(0);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Banned user gets CannotVoteException.
- No vote row.
- No counter update.
- Тест падает до RG-270.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-269: Test banned user cannot vote`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-270 — Add Banned User Guard For Voting

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-270-add-banned-user-guard-for-voting`  
**Base branch:** develop
**Depends on:** RG-269

### Goal

Добавить guard, запрещающий banned users голосовать.

### TDD step

Использовать падающий тест из RG-269.

### Implementation

В `User` model добавить метод, если его ещё нет:

```php
public function canVote(): bool
{
    return $this->status === UserStatus::Active;
}
```

Тестировать отдельно не обязательно, если покрывается VotePostAction.

В `VotePostAction`:

```php
if (! $user->canVote()) {
    throw CannotVoteException::becauseUserIsNotAllowed();
}
```

Если не добавлять `canVote()`, использовать:

```php
if ($user->status !== UserStatus::Active) { ... }
```

Рекомендация: добавить `canVote()`, потому что дальше origin/cuisine voting переиспользуют это правило.

### Acceptance criteria

- Banned user blocked.
- Active user can vote.
- No vote/counter side effect for banned.
- `canVote()` exists or equivalent guard clear.
- Tests pass.

### Definition of Done

- Guard добавлен.
- Тесты проходят.
- Коммит: `RG-270: Add banned user guard for voting`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
app/Models/User.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-271 — Test Hidden Post Cannot Be Voted On

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-271-test-hidden-post-cannot-be-voted-on`  
**Base branch:** develop
**Depends on:** RG-270

### Goal

Написать тест: hidden post нельзя голосовать.

### TDD step

Feature/action test:

```php
it('does not allow voting on hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
})->throws(CannotVoteException::class);
```

Добавить pending/rejected tests можно в этой же задаче:

```php
it('does not allow voting on pending post', ...)
it('does not allow voting on rejected post', ...)
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Hidden post blocked.
- Pending post blocked, если тест добавлен.
- Rejected post blocked, если тест добавлен.
- No vote row.
- Counters unchanged.
- Тест падает до RG-272.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-271: Test hidden post cannot be voted on`

### Files likely touched

```txt
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-272 — Add Post Status Guard For Voting

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-272-add-post-status-guard-for-voting`  
**Base branch:** develop
**Depends on:** RG-271

### Goal

Добавить guard: голосовать можно только за published posts.

### TDD step

Использовать падающие тесты из RG-271.

### Implementation

В `VotePostAction`:

```php
if ($post->status !== PostStatus::Published) {
    throw CannotVoteException::becausePostIsNotPublic();
}
```

Можно добавить helper в `Post`:

```php
public function canReceiveVotes(): bool
{
    return $this->status === PostStatus::Published;
}
```

Рекомендация: добавить helper, потому что Phase 14/15 тоже будут проверять status.

### Acceptance criteria

- Published post can receive votes.
- Hidden/pending/rejected cannot receive votes.
- No side effects on blocked posts.
- Tests pass.

### Definition of Done

- Post status guard добавлен.
- Тесты проходят.
- Коммит: `RG-272: Add post status guard for voting`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
app/Models/Post.php
tests/Feature/Actions/VotePostActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-273 — Create PostVoting Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-273-create-post-voting-livewire-component`  
**Base branch:** develop
**Depends on:** RG-272

### Goal

Создать Livewire component `PostVoting` для up/down UI.

### TDD step

Livewire test:

```php
it('can render post voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Up')
        ->assertSee('Down');
});
```

Тест должен упасть до создания component.

### Implementation

Создать:

```bash
php artisan make:livewire Posts/PostVoting
```

Файлы:

```txt
app/Livewire/Posts/PostVoting.php
resources/views/livewire/posts/post-voting.blade.php
```

Class:

```php
final class PostVoting extends Component
{
    public int $postId;

    public string $error = '';

    public function getPostProperty(): Post
    {
        return Post::query()->published()->findOrFail($this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.post-voting', [
            'post' => $this->post,
        ]);
    }
}
```

View skeleton:

```blade
<div data-testid="post-voting">
    <button type="button">Up</button>
    <button type="button">Down</button>
</div>
```

Пока не вызывать action. Это RG-274.

### Acceptance criteria

- `PostVoting` component exists.
- Accepts `postId`.
- Renders Up/Down buttons.
- Loads only published post.
- Test passes.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-273: Create PostVoting Livewire component`

### Files likely touched

```txt
app/Livewire/Posts/PostVoting.php
resources/views/livewire/posts/post-voting.blade.php
tests/Feature/Livewire/PostVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-274 — Test PostVoting Calls VotePostAction

**Area:** Livewire / Tests  
**Type:** Test / Feature  
**Priority:** P0  
**Branch:** `feature/RG-274-test-post-voting-calls-vote-post-action`  
**Base branch:** develop
**Depends on:** RG-273

### Goal

Подключить PostVoting к VotePostAction и протестировать click behavior.

### TDD step

Livewire tests:

```php
it('calls vote action when up button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', VoteType::Up->value);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);
});
```

Downvote test:

```php
->call('vote', VoteType::Down->value)
```

### Implementation

В `PostVoting`:

```php
public function vote(string $type, VotePostAction $votePostAction): void
{
    $voteType = VoteType::from($type);

    $votePostAction->handle(auth()->user(), $this->post, $voteType);

    $this->dispatch('post-voted', postId: $this->postId);
}
```

View:

```blade
<button wire:click="vote('up')">Up</button>
<button wire:click="vote('down')">Down</button>
```

Handle exception:

```php
catch (CannotVoteException $e) {
    $this->error = $e->getMessage();
}
```

Можно добавить error state позже, но минимально не ломать.

### Acceptance criteria

- Up button calls action.
- Down button calls action.
- Vote row created/updated.
- `post-voted` event dispatched.
- Guest handled by action exception.
- Tests pass.

### Definition of Done

- Tests написаны.
- Component calls action.
- Tests проходят.
- Коммит: `RG-274: Test PostVoting calls VotePostAction`

### Files likely touched

```txt
app/Livewire/Posts/PostVoting.php
resources/views/livewire/posts/post-voting.blade.php
tests/Feature/Livewire/PostVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-275 — Render Up/Down Buttons In PostCard

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-275-render-up-down-buttons-in-post-card`  
**Base branch:** develop
**Depends on:** RG-274

### Goal

Встроить `PostVoting` в PostCard.

### TDD step

Blade render test:

```php
it('renders post voting component in post card', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', [
        'post' => $post,
    ]);

    expect($html)->toContain('post-voting');
});
```

Если Livewire component markup не рендерится напрямую в Blade render test, проверить наличие `livewire:posts.post-voting` или `wire:id` может быть сложно. Допустимо добавить wrapper marker:

```blade
<div data-testid="post-card-voting">
    <livewire:posts.post-voting :post-id="$post->id" :key="'card-vote-'.$post->id" />
</div>
```

Тестировать marker.

### Implementation

В `resources/views/components/feed/post-card.blade.php` заменить/дополнить read-only stats area:

```blade
<div data-testid="post-card-voting">
    <livewire:posts.post-voting
        :post-id="$post->id"
        :key="'post-card-voting-'.$post->id"
    />
</div>
```

Если PostCard используется в UI Kit с unsaved fake post, нужно не ломать UI Kit:

```blade
@if($post->exists)
    <livewire:posts.post-voting ... />
@else
    <div>Score preview...</div>
@endif
```

### Acceptance criteria

- PostCard includes PostVoting for persisted posts.
- UI Kit demo with unsaved post does not break.
- Up/down buttons visible in feed cards.
- No duplicated voting logic in PostCard.
- Test passes.

### Definition of Done

- Test написан.
- PostVoting встроен.
- UI Kit не сломан.
- Коммит: `RG-275: Render up/down buttons in PostCard`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-276 — Render Up/Down Buttons In PostDrawer

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-276-render-up-down-buttons-in-post-drawer`  
**Base branch:** develop
**Depends on:** RG-274

### Goal

Встроить `PostVoting` в PostDrawer.

### TDD step

Livewire test:

```php
it('renders post voting component in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-voting"', false);
});
```

### Implementation

В `post-drawer.blade.php` рядом с vote summary:

```blade
<div data-testid="post-drawer-voting">
    <livewire:posts.post-voting
        :post-id="$post->id"
        :key="'post-drawer-voting-'.$post->id"
    />
</div>
```

Можно оставить read-only summary или заменить его на PostVoting, если PostVoting показывает counters.

Рекомендация: PostVoting должен сам показывать counters, а старый summary block в drawer можно оставить как higher-level distribution. Но не дублировать score в двух местах слишком явно.

### Acceptance criteria

- Drawer includes PostVoting.
- Up/down buttons visible in drawer.
- Component key stable by post id.
- No duplicated action logic.
- Test passes.

### Definition of Done

- Test написан.
- PostVoting встроен в drawer.
- Коммит: `RG-276: Render up/down buttons in PostDrawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-277 — Render Up/Down Buttons In PostShow

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-277-render-up-down-buttons-in-post-show`  
**Base branch:** develop
**Depends on:** RG-274

### Goal

Встроить `PostVoting` в standalone post show page.

### TDD step

HTTP test:

```php
it('renders post voting component on post show page', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-voting"', false);
});
```

### Implementation

В `post-show.blade.php`:

```blade
<div data-testid="post-show-voting">
    <livewire:posts.post-voting
        :post-id="$post->id"
        :key="'post-show-voting-'.$post->id"
    />
</div>
```

В voting panels section можно заменить read-only score panel на PostVoting или добавить PostVoting поверх panels.

Рекомендация: оставить summary panels, но PostVoting должен быть рядом и синхронизировать counters later in RG-279.

### Acceptance criteria

- PostShow includes PostVoting.
- Up/down buttons visible on standalone page.
- No duplicated action logic.
- Test passes.

### Definition of Done

- Test написан.
- PostVoting встроен в PostShow.
- Коммит: `RG-277: Render up/down buttons in PostShow`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-278 — Add Optimistic-Looking Loading State To Vote Buttons

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-278-add-optimistic-looking-loading-state-to-vote-buttons`  
**Base branch:** develop
**Depends on:** RG-277

### Goal

Добавить loading state для vote buttons, чтобы интерфейс выглядел отзывчиво.

Важно: “optimistic-looking” не значит менять counters до server success.  
В Phase 13 не делаем настоящую optimistic update. Просто показываем loading/disabled state.

### TDD step

Livewire/markup test:

```php
it('has vote loading state markup', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostVoting::class, ['postId' => $post->id])
        ->assertSee('wire:loading', false)
        ->assertSee('wire:loading.attr="disabled"', false);
});
```

### Implementation

В `post-voting.blade.php`:

```blade
<button
    type="button"
    wire:click="vote('up')"
    wire:loading.attr="disabled"
    wire:target="vote"
>
    <span wire:loading.remove wire:target="vote">Up</span>
    <span wire:loading wire:target="vote">...</span>
</button>
```

То же для down.

Добавить classes:

```txt
opacity-60
cursor-wait
disabled styles
```

### Acceptance criteria

- Vote buttons disabled during vote call.
- Loading indicator visible.
- Layout does not jump.
- No client-only counter mutation before server success.
- Test passes.

### Definition of Done

- Loading state добавлен.
- Test passes.
- Manual check выполнен.
- Коммит: `RG-278: Add optimistic-looking loading state to vote buttons`

### Files likely touched

```txt
resources/views/livewire/posts/post-voting.blade.php
tests/Feature/Livewire/PostVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-279 — Refresh Vote Counters After Vote

**Area:** Livewire / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-279-refresh-vote-counters-after-vote`  
**Base branch:** develop
**Depends on:** RG-278

### Goal

После vote обновлять counters в PostVoting и связанных UI местах.

### TDD step

Livewire test:

```php
it('refreshes vote counters after vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->assertSee('0')
        ->call('vote', VoteType::Up->value)
        ->assertSee('1');
});
```

Toggle refresh test:

```php
->call('vote', VoteType::Up->value)
->assertSee('0');
```

### Implementation

В `PostVoting` после action:

```php
$this->dispatch('post-voted', postId: $this->postId);
```

Чтобы counters обновились:

```php
public function getPostProperty(): Post
{
    return Post::query()->published()->findOrFail($this->postId);
}
```

Каждый render должен fresh-загружать post.

Если Livewire computed property кеширует значение, сбросить:

```php
unset($this->post);
```

или использовать explicit method:

```php
private function loadPost(): Post
```

В view показывать:

```blade
{{ $post->upvotes_count }}
{{ $post->downvotes_count }}
{{ $post->upvotes_count - $post->downvotes_count }}
```

Для parent components, если они показывают отдельные stale counters, добавить listeners:

```php
#[On('post-voted')]
public function refreshAfterVote(): void {}
```

Минимальный DoD: PostVoting counters обновляются.

### Acceptance criteria

- Upvote updates displayed upvote count.
- Downvote updates displayed downvote count.
- Toggle off updates displayed count.
- Replacement updates both counters.
- `post-voted` event dispatched.
- All voting tests pass.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Counter refresh реализован.
- Tests pass.
- Build passes.
- Коммит: `RG-279: Refresh vote counters after vote`

### Files likely touched

```txt
app/Livewire/Posts/PostVoting.php
resources/views/livewire/posts/post-voting.blade.php
app/Livewire/Feed/PostFeed.php
app/Livewire/Feed/PostDrawer.php
app/Livewire/Posts/PostShow.php
tests/Feature/Livewire/PostVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 13 Completion Criteria

Phase 13 завершена, когда:

```txt
- RG-254–RG-279 выполнены;
- VotePostAction существует;
- user can upvote published post;
- user can downvote published post;
- same upvote toggles off;
- same downvote toggles off;
- downvote replaces upvote;
- upvote replaces downvote;
- guest cannot vote;
- banned user cannot vote;
- hidden/pending/rejected posts cannot be voted on;
- counters update correctly;
- counters do not go negative;
- VotePostAction uses transaction;
- PostVoting Livewire component exists;
- PostVoting calls VotePostAction;
- up/down buttons render in PostCard;
- up/down buttons render in PostDrawer;
- up/down buttons render in PostShow;
- loading state exists;
- counters refresh after vote;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 13

Без отдельной задачи нельзя:

```txt
- делать Homemade/Restaurant voting;
- делать cuisine voting;
- делать comments;
- делать reports;
- делать moderation buttons;
- делать analytics;
- делать anti-fraud;
- делать rate limiting;
- делать IP/device tracking;
- делать notifications;
- пересчитывать hot_score;
- менять FeedQuery ranking;
- добавлять Redis/cache layer;
- делать API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-254 Create VotePostAction skeleton
RG-255 Test user can upvote post
RG-256 Implement upvote creation
RG-257 Test user can downvote post
RG-258 Implement downvote creation
RG-259 Test upvote toggles off when clicked again
RG-260 Implement same-vote toggle
RG-261 Test downvote toggles off when clicked again
RG-262 Implement downvote toggle
RG-263 Test downvote replaces upvote
RG-264 Implement vote replacement
RG-265 Test upvote replaces downvote
RG-266 Implement reverse vote replacement
RG-267 Test guest cannot vote
RG-268 Add auth guard for voting
RG-269 Test banned user cannot vote
RG-270 Add banned user guard for voting
RG-271 Test hidden post cannot be voted on
RG-272 Add post status guard for voting
RG-273 Create PostVoting Livewire component
RG-274 Test PostVoting calls VotePostAction
RG-275 Render up/down buttons in PostCard
RG-276 Render up/down buttons in PostDrawer
RG-277 Render up/down buttons in PostShow
RG-278 Add optimistic-looking loading state to vote buttons
RG-279 Refresh vote counters after vote
```
---

# 14. Release

После завершения Phase 13:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.4-phase13-up-down-voting
git push -u origin release/v0.1.4-phase13-up-down-voting
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.4-phase13-up-down-voting -m "RateGuru Phase 13 up down voting"
git push origin v0.1.4-phase13-up-down-voting
```
