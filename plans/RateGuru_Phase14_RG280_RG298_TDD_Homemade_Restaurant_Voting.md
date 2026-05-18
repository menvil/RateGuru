# RateGuru — Phase 14 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 14 — Homemade/Restaurant Voting**  
Диапазон задач: **RG-280 → RG-298**  
Основа нумерации: исходный atomic backlog, где Phase 14 начинается с задачи 280 и заканчивается задачей 298.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 14 соответствует исходному блоку:

```txt
Phase 14 — Homemade/Restaurant Voting
```

Правильный диапазон Phase 14:

```txt
RG-280 — Create VoteOriginAction skeleton
RG-281 — Test user can vote homemade
RG-282 — Implement homemade vote
RG-283 — Test user can vote restaurant
RG-284 — Implement restaurant vote
RG-285 — Test origin vote can be changed
RG-286 — Implement origin vote update
RG-287 — Test same origin vote toggles or remains selected
RG-288 — Decide and implement same-origin behavior
RG-289 — Test guest cannot vote origin
RG-290 — Add auth guard for origin vote
RG-291 — Test hidden post cannot receive origin vote
RG-292 — Add status guard for origin vote
RG-293 — Create OriginVoting Livewire component
RG-294 — Test OriginVoting calls VoteOriginAction
RG-295 — Render Homemade/Restaurant buttons in PostCard
RG-296 — Render Homemade/Restaurant panel in drawer
RG-297 — Render origin distribution bar
RG-298 — Refresh origin counters after vote
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 14

Phase 14 добавляет голосование за происхождение блюда:

```txt
Homemade
Restaurant
```

После Phase 14 пользователь должен уметь:

```txt
- проголосовать Homemade;
- проголосовать Restaurant;
- изменить голос Homemade → Restaurant;
- изменить голос Restaurant → Homemade;
- видеть распределение голосов;
- голосовать из PostCard;
- голосовать из drawer;
- видеть обновлённые counters после голосования.
```

Это отдельная механика от up/down voting из Phase 13.
---

# 3. Scope Phase 14

## Входит

```txt
- VoteOriginAction;
- создание origin vote;
- обновление existing origin vote;
- выбранное поведение для повторного клика по тому же варианту;
- auth guard;
- published-only guard;
- OriginVoting Livewire component;
- кнопки Homemade/Restaurant в PostCard;
- панель Homemade/Restaurant в drawer;
- origin distribution bar;
- refresh counters after vote.
```

## Не входит

```txt
- cuisine voting;
- comments;
- reports;
- moderation;
- fraud detection;
- rate limiting;
- IP/device tracking;
- analytics;
- notifications;
- hot_score recalculation;
- generic counter recalculation action;
- API endpoint.
```

Cuisine voting будет Phase 15.  
Counter aggregates/recalculation будут Phase 16.  
Comments — Phase 17+.  
Reports — Phase 19+.  
Moderation — Phase 21+.
---

# 4. Product Decision: Same-Origin Behavior

Исходная задача `RG-287/RG-288` специально оставляет выбор:

```txt
same origin vote toggles or remains selected
```

Фиксируем решение:

```txt
Повторный клик по уже выбранному origin vote НЕ снимает голос.
Он оставляет голос выбранным и не меняет counters.
```

Почему так:

```txt
- Homemade/Restaurant — это классификационный выбор, а не лайк;
- один пользователь должен дать один лучший ответ;
- случайный второй клик не должен удалять полезный сигнал;
- если позже понадобится “clear vote”, это отдельная явная кнопка/action.
```

Итоговые правила:

```txt
No vote + Homemade      → create homemade vote, increment homemade_votes_count
No vote + Restaurant    → create restaurant vote, increment restaurant_votes_count

Homemade + Homemade     → no-op, counters unchanged
Restaurant + Restaurant → no-op, counters unchanged

Homemade + Restaurant   → update vote to restaurant, decrement homemade, increment restaurant
Restaurant + Homemade   → update vote to homemade, decrement restaurant, increment homemade
```
---

# 5. Data Rules

## 5.1. One origin vote per user/post

Таблица `origin_votes` уже имеет unique constraint:

```txt
post_id + user_id
```

Это значит:

```txt
- user cannot have both Homemade and Restaurant origin vote simultaneously;
- change updates existing row;
- same choice is no-op.
```

## 5.2. Counters on posts

`posts` содержит:

```txt
homemade_votes_count
restaurant_votes_count
```

Phase 14 обновляет эти counters синхронно внутри `VoteOriginAction`.

Важно:

```txt
- counters не должны уходить ниже 0;
- update Homemade → Restaurant меняет оба counters;
- update Restaurant → Homemade меняет оба counters;
- same vote no-op не меняет counters;
- action должен быть transaction-safe.
```

Phase 16 позже добавит fallback recalculation, но это не отменяет синхронное обновление в Phase 14.

## 5.3. Only published posts can receive origin votes

Нельзя голосовать origin для:

```txt
draft
pending
hidden
rejected
deleted
```

Даже если UI скрывает кнопку, backend action обязан блокировать.

## 5.4. Guest cannot vote origin

Гость может смотреть посты, но не может голосовать.

`VoteOriginAction` должен принимать nullable user или OriginVoting должен блокировать guest до action.  
Лучше сделать так же, как в Phase 13:

```php
handle(?User $user, Post $post, OriginType $origin)
```

и бросать explicit exception для guest.

## 5.5. Banned users

В исходном Phase 14 нет отдельной задачи `banned user cannot vote origin`.

Но после Phase 13 у нас уже должна быть модельная логика `User::canVote()` или аналог.  
Если она есть, `VoteOriginAction` должен её использовать в `RG-290`, чтобы не получить дыру:

```txt
banned user cannot up/down vote, but can origin vote
```

Это плохая несогласованность.

Решение:

```txt
RG-290 auth guard должен блокировать guest и users who cannot vote, если canVote() уже существует.
```

Отдельный backlog ID для banned origin vote не добавляем, чтобы не ломать нумерацию.
---

# 6. Architecture Rules

## 6.1. VoteOriginAction owns business logic

Нельзя писать origin voting прямо в Livewire:

```php
OriginVote::updateOrCreate(...)
$post->increment(...)
```

Правильно:

```php
app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
```

## 6.2. OriginVoting Livewire component is UI wrapper

`OriginVoting` отвечает за:

```txt
- отображение Homemade/Restaurant buttons;
- отображение active user origin vote;
- вызов VoteOriginAction;
- loading state;
- dispatch origin-voted event;
- refresh local counters.
```

Он не должен дублировать counter logic.

## 6.3. Reuse enum OriginType

Использовать существующий enum:

```txt
OriginType::Homemade
OriginType::Restaurant
```

Не использовать:

```txt
OriginType::Unknown
```

как пользовательский vote.

`Unknown` допустим для `posts.origin_truth`, но не для `origin_votes.origin`.

## 6.4. PostCard/PostDrawer should embed OriginVoting

Не копировать Homemade/Restaurant кнопки вручную в разных местах.

Правильно:

```blade
<livewire:posts.origin-voting :post-id="$post->id" />
```

или equivalent.

## 6.5. PostShow integration is not in Phase 14 backlog

В отличие от up/down voting Phase 13, Phase 14 явно содержит:

```txt
RG-295 — Render Homemade/Restaurant buttons in PostCard
RG-296 — Render Homemade/Restaurant panel in drawer
```

Но не содержит PostShow integration.

Поэтому в Phase 14 **не добавляем OriginVoting в PostShow**, если это не требуется отдельной будущей задачей.  
Можно оставить read-only origin distribution на PostShow из Phase 12, если он уже есть, но не расширять.
---

# 7. Design Constraints

Origin voting UI должен быть понятным:

```txt
- две кнопки: Homemade / Restaurant;
- selected state очевиден;
- distribution bar показывает доли;
- loading state без скачков layout;
- compact в PostCard;
- более подробный panel в drawer;
- dark UI, purple accent, rounded pills/cards.
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
```

Если review docs отсутствуют, зафиксировать missing prerequisite в PR notes, но не блокировать backend work.
---

# 8. GitFlow для Phase 14

## Base branch

Все задачи Phase 14 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-280-create-vote-origin-action-skeleton
feature/RG-286-implement-origin-vote-update
feature/RG-298-refresh-origin-counters-after-vote
```

## Commit format

```txt
RG-280: Create VoteOriginAction skeleton
RG-286: Implement origin vote update
RG-298: Refresh origin counters after vote
```

## Release branch

После выполнения `RG-280`–`RG-298`:

```txt
release/v0.1.5-phase14-homemade-restaurant-voting
```

## Tag

После merge release branch в `main`:

```txt
v0.1.5-phase14-homemade-restaurant-voting
```
---

# 9. TDD Rules for Phase 14

## Для VoteOriginAction

Каждое поведение делается test-first:

```txt
- user can vote homemade;
- user can vote restaurant;
- vote can be changed;
- same vote remains selected/no-op;
- guest cannot vote;
- hidden post cannot receive vote.
```

## Для counters

Каждый backend test должен проверять:

```txt
- origin_votes row;
- homemade_votes_count;
- restaurant_votes_count;
- no negative counters.
```

## Для Livewire OriginVoting

Тестировать:

```txt
- component renders;
- click calls action;
- counters update;
- active state renders;
- guest/blocked users handled.
```

## Для UI integration

Тестировать markers:

```txt
- PostCard includes OriginVoting;
- PostDrawer includes OriginVoting;
- distribution bar renders expected percentages/counts.
```
---

# 10. Universal Task Template

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

# 11. Phase 14 Atomic Tasks
---

## RG-280 — Create VoteOriginAction Skeleton

**Area:** Backend  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-280-create-vote-origin-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-279

### Goal

Создать skeleton action для Homemade/Restaurant voting.

### TDD step

Unit test:

```php
it('has vote origin action with handle method', function () {
    $action = app(VoteOriginAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Votes/VoteOriginAction.php
```

Skeleton:

```php
namespace App\Actions\Votes;

use App\Enums\OriginType;
use App\Models\Post;
use App\Models\User;

final class VoteOriginAction
{
    public function handle(?User $user, Post $post, OriginType $origin): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Сигнатура сразу nullable user, чтобы guest guard в RG-290 был чистым.

### Acceptance criteria

- `VoteOriginAction` существует.
- Есть `handle(?User $user, Post $post, OriginType $origin): void`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-280: Create VoteOriginAction skeleton`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
tests/Unit/Actions/VoteOriginActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-281 — Test User Can Vote Homemade

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-281-test-user-can-vote-homemade`  
**Base branch:** develop
**Depends on:** RG-280

### Goal

Написать падающий тест: authenticated active user может проголосовать Homemade за published post.

### TDD step

Feature/action test:

```php
it('allows user to vote homemade on a published post', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});
```

Тест должен упасть до RG-282.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет row in `origin_votes`.
- Тест проверяет `homemade_votes_count`.
- Тест проверяет `restaurant_votes_count`.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-281: Test user can vote homemade`

### Files likely touched

```txt
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-282 — Implement Homemade Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-282-implement-homemade-vote`  
**Base branch:** develop
**Depends on:** RG-281

### Goal

Реализовать создание Homemade vote.

### TDD step

Использовать падающий тест из RG-281.

### Implementation

В `VoteOriginAction::handle()`:

```php
DB::transaction(function () use ($user, $post, $origin) {
    OriginVote::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => $origin,
    ]);

    if ($origin === OriginType::Homemade) {
        $post->increment('homemade_votes_count');
    }
});
```

Пока можно реализовать только `OriginType::Homemade`.  
Но структура должна позволять ресторанную ветку в RG-284.

Не использовать `OriginType::Unknown` для vote.

### Acceptance criteria

- User can vote Homemade.
- `origin_votes` row created.
- homemade_votes_count increments.
- restaurant_votes_count unchanged.
- Transaction used.
- Test RG-281 passes.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-282: Implement homemade vote`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-283 — Test User Can Vote Restaurant

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-283-test-user-can-vote-restaurant`  
**Base branch:** develop
**Depends on:** RG-282

### Goal

Написать падающий тест: authenticated active user может проголосовать Restaurant за published post.

### TDD step

Feature/action test:

```php
it('allows user to vote restaurant on a published post', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Restaurant);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Restaurant->value,
    ]);

    expect($post->fresh()->homemade_votes_count)->toBe(0);
    expect($post->fresh()->restaurant_votes_count)->toBe(1);
});
```

Тест должен упасть до RG-284.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет row in `origin_votes`.
- Тест проверяет counters.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-283: Test user can vote restaurant`

### Files likely touched

```txt
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-284 — Implement Restaurant Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-284-implement-restaurant-vote`  
**Base branch:** develop
**Depends on:** RG-283

### Goal

Реализовать создание Restaurant vote.

### TDD step

Использовать падающий тест из RG-283.

### Implementation

В `VoteOriginAction` добавить ветку `OriginType::Restaurant`:

```php
if ($origin === OriginType::Restaurant) {
    OriginVote::create([...]);
    $post->increment('restaurant_votes_count');
}
```

Можно вынести helpers:

```php
private function incrementCounter(Post $post, OriginType $origin): void
private function decrementCounter(Post $post, OriginType $origin): void
```

Но не реализовывать update/same behavior до следующих задач, если это делает diff слишком большим.

### Acceptance criteria

- User can vote Restaurant.
- `origin_votes` row created.
- restaurant_votes_count increments.
- homemade_votes_count unchanged.
- Homemade vote behavior still works.
- Tests RG-281/RG-283 pass.

### Definition of Done

- Реализация минимальная.
- Тесты проходят.
- Коммит: `RG-284: Implement restaurant vote`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-285 — Test Origin Vote Can Be Changed

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-285-test-origin-vote-can-be-changed`  
**Base branch:** develop
**Depends on:** RG-284

### Goal

Написать падающий тест: пользователь может изменить origin vote.

### TDD step

Feature/action test:

```php
it('allows user to change origin vote from homemade to restaurant', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
    app(VoteOriginAction::class)->handle($user, $post->fresh(), OriginType::Restaurant);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Restaurant->value,
    ]);

    expect(OriginVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    expect($post->fresh()->homemade_votes_count)->toBe(0);
    expect($post->fresh()->restaurant_votes_count)->toBe(1);
});
```

Добавить обратное направление можно здесь или в RG-286:

```txt
Restaurant → Homemade
```

Лучше добавить отдельный assertion/test в той же задаче, чтобы update был симметричным.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Existing origin vote updates.
- There is exactly one vote row.
- Counters move from old origin to new origin.
- Тест падает до RG-286.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-285: Test origin vote can be changed`

### Files likely touched

```txt
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-286 — Implement Origin Vote Update

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-286-implement-origin-vote-update`  
**Base branch:** develop
**Depends on:** RG-285

### Goal

Реализовать изменение origin vote.

### TDD step

Использовать падающий тест из RG-285.

### Implementation

В `VoteOriginAction`:

```php
$existingVote = OriginVote::query()
    ->where('post_id', $post->id)
    ->where('user_id', $user->id)
    ->first();

if ($existingVote && $existingVote->origin !== $origin) {
    $oldOrigin = $existingVote->origin;

    $existingVote->update([
        'origin' => $origin,
    ]);

    $this->decrementCounter($post, $oldOrigin);
    $this->incrementCounter($post, $origin);

    return;
}
```

Helpers:

```php
private function incrementCounter(Post $post, OriginType $origin): void
{
    match ($origin) {
        OriginType::Homemade => $post->increment('homemade_votes_count'),
        OriginType::Restaurant => $post->increment('restaurant_votes_count'),
        OriginType::Unknown => null,
    };
}
```

`Unknown` should not happen. Better reject it explicitly:

```php
if ($origin === OriginType::Unknown) {
    throw CannotVoteOriginException::becauseOriginIsInvalid();
}
```

Если exception ещё не создан, можно создать в RG-290, но лучше сейчас не позволять Unknown silently.

### Acceptance criteria

- Homemade → Restaurant works.
- Restaurant → Homemade works, если test добавлен.
- There is one vote row.
- Old counter decrements.
- New counter increments.
- Counters do not go negative.
- Tests pass.

### Definition of Done

- Update logic добавлена.
- Тесты проходят.
- Коммит: `RG-286: Implement origin vote update`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-287 — Test Same Origin Vote Toggles Or Remains Selected

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-287-test-same-origin-vote-toggles-or-remains-selected`  
**Base branch:** develop
**Depends on:** RG-286

### Goal

Зафиксировать выбранное поведение для повторного клика по тому же origin vote.

Решение Phase 14:

```txt
same origin vote remains selected
```

То есть повторный клик — no-op.

### TDD step

Feature/action test:

```php
it('keeps same origin vote selected when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
    app(VoteOriginAction::class)->handle($user, $post->fresh(), OriginType::Homemade);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);

    expect(OriginVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});
```

Добавить Restaurant same-click test тоже желательно:

```txt
Restaurant + Restaurant → stays selected
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Повторный same vote не удаляет row.
- Counters unchanged.
- There is exactly one row.
- Тест падает до RG-288, если no-op не реализован.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает или уже проходит, если RG-286 был generic.
- Коммит: `RG-287: Test same origin vote toggles or remains selected`

### Files likely touched

```txt
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-288 — Decide And Implement Same-Origin Behavior

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-288-decide-and-implement-same-origin-behavior`  
**Base branch:** develop
**Depends on:** RG-287

### Goal

Реализовать выбранное поведение для same origin vote:

```txt
same origin vote remains selected / no-op
```

### TDD step

Использовать тест из RG-287.

### Implementation

В `VoteOriginAction`:

```php
if ($existingVote && $existingVote->origin === $origin) {
    return;
}
```

Этот блок должен идти до update logic.

Важно:

```txt
- не удалять existing vote;
- не менять counters;
- не создавать second row;
- не dispatch extra counter changes.
```

### Acceptance criteria

- Same Homemade vote no-op.
- Same Restaurant vote no-op.
- Counters unchanged.
- Vote row remains.
- Tests pass.

### Definition of Done

- Same-origin behavior явно реализован.
- Комментарий в коде или тесте фиксирует product decision.
- Тесты проходят.
- Коммит: `RG-288: Decide and implement same-origin behavior`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-289 — Test Guest Cannot Vote Origin

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-289-test-guest-cannot-vote-origin`  
**Base branch:** develop
**Depends on:** RG-288

### Goal

Написать тест: guest не может голосовать Homemade/Restaurant.

### TDD step

Feature/action test:

```php
it('does not allow guest to vote origin', function () {
    $post = Post::factory()->published()->create();

    app(VoteOriginAction::class)->handle(null, $post, OriginType::Homemade);
})->throws(CannotVoteOriginException::class);
```

Проверить no side effects:

```php
expect(OriginVote::query()->count())->toBe(0);
expect($post->fresh()->homemade_votes_count)->toBe(0);
expect($post->fresh()->restaurant_votes_count)->toBe(0);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Guest/null user получает explicit exception.
- No origin vote row.
- Counters unchanged.
- Тест падает до RG-290.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-289: Test guest cannot vote origin`

### Files likely touched

```txt
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-290 — Add Auth Guard For Origin Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-290-add-auth-guard-for-origin-vote`  
**Base branch:** develop
**Depends on:** RG-289

### Goal

Добавить auth guard для origin voting.

### TDD step

Использовать падающий тест из RG-289.

### Implementation

Создать exception:

```txt
app/Exceptions/Votes/CannotVoteOriginException.php
```

Пример:

```php
final class CannotVoteOriginException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on origin.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on origin.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive origin votes.');
    }

    public static function becauseOriginIsInvalid(): self
    {
        return new self('Invalid origin vote.');
    }
}
```

В `VoteOriginAction`:

```php
if ($user === null) {
    throw CannotVoteOriginException::becauseGuest();
}

if (method_exists($user, 'canVote') && ! $user->canVote()) {
    throw CannotVoteOriginException::becauseUserIsNotAllowed();
}
```

Если `User::canVote()` уже есть из Phase 13 — использовать его.  
Если нет — добавить или использовать status guard.

Также блокировать `OriginType::Unknown`:

```php
if ($origin === OriginType::Unknown) {
    throw CannotVoteOriginException::becauseOriginIsInvalid();
}
```

### Acceptance criteria

- Guest blocked.
- Banned/non-voting user blocked, если `canVote()` есть.
- Unknown origin blocked.
- No side effects on blocked vote.
- Authenticated active user can still vote.
- Tests pass.

### Definition of Done

- Exception добавлен.
- Auth guard добавлен.
- Invalid origin guard добавлен.
- Тесты проходят.
- Коммит: `RG-290: Add auth guard for origin vote`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
app/Exceptions/Votes/CannotVoteOriginException.php
app/Models/User.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-291 — Test Hidden Post Cannot Receive Origin Vote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-291-test-hidden-post-cannot-receive-origin-vote`  
**Base branch:** develop
**Depends on:** RG-290

### Goal

Написать тест: hidden post нельзя голосовать Homemade/Restaurant.

### TDD step

Feature/action test:

```php
it('does not allow origin vote on hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
})->throws(CannotVoteOriginException::class);
```

Добавить pending/rejected tests желательно:

```php
it('does not allow origin vote on pending post', ...)
it('does not allow origin vote on rejected post', ...)
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Hidden post blocked.
- Pending/rejected blocked, если tests добавлены.
- No origin vote row.
- Counters unchanged.
- Тест падает до RG-292.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-291: Test hidden post cannot receive origin vote`

### Files likely touched

```txt
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-292 — Add Status Guard For Origin Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-292-add-status-guard-for-origin-vote`  
**Base branch:** develop
**Depends on:** RG-291

### Goal

Добавить guard: origin vote можно ставить только на published posts.

### TDD step

Использовать падающие тесты из RG-291.

### Implementation

В `VoteOriginAction`:

```php
if ($post->status !== PostStatus::Published) {
    throw CannotVoteOriginException::becausePostIsNotPublic();
}
```

Если в Phase 13 уже добавлен `Post::canReceiveVotes()`:

```php
if (! $post->canReceiveVotes()) {
    throw CannotVoteOriginException::becausePostIsNotPublic();
}
```

### Acceptance criteria

- Published post can receive origin votes.
- Hidden/pending/rejected cannot.
- No side effects on blocked posts.
- Tests pass.

### Definition of Done

- Status guard добавлен.
- Тесты проходят.
- Коммит: `RG-292: Add status guard for origin vote`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
app/Models/Post.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-293 — Create OriginVoting Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-293-create-origin-voting-livewire-component`  
**Base branch:** develop
**Depends on:** RG-292

### Goal

Создать Livewire component `OriginVoting`.

### TDD step

Livewire test:

```php
it('can render origin voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Homemade')
        ->assertSee('Restaurant');
});
```

Тест должен упасть до создания component.

### Implementation

Создать:

```bash
php artisan make:livewire Posts/OriginVoting
```

Файлы:

```txt
app/Livewire/Posts/OriginVoting.php
resources/views/livewire/posts/origin-voting.blade.php
```

Class:

```php
final class OriginVoting extends Component
{
    public int $postId;

    public function getPostProperty(): Post
    {
        return Post::query()->published()->findOrFail($this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.origin-voting', [
            'post' => $this->post,
        ]);
    }
}
```

View skeleton:

```blade
<div data-testid="origin-voting">
    <button type="button">Homemade</button>
    <button type="button">Restaurant</button>
</div>
```

Пока не вызывать action. Это RG-294.

### Acceptance criteria

- `OriginVoting` component exists.
- Accepts `postId`.
- Renders Homemade/Restaurant buttons.
- Loads only published post.
- Test passes.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-293: Create OriginVoting Livewire component`

### Files likely touched

```txt
app/Livewire/Posts/OriginVoting.php
resources/views/livewire/posts/origin-voting.blade.php
tests/Feature/Livewire/OriginVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-294 — Test OriginVoting Calls VoteOriginAction

**Area:** Livewire / Tests  
**Type:** Test / Feature  
**Priority:** P0  
**Branch:** `feature/RG-294-test-origin-voting-calls-vote-origin-action`  
**Base branch:** develop
**Depends on:** RG-293

### Goal

Подключить OriginVoting к VoteOriginAction и протестировать click behavior.

### TDD step

Livewire tests:

```php
it('calls origin vote action when homemade button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->call('vote', OriginType::Homemade->value);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);
});
```

Restaurant test:

```php
->call('vote', OriginType::Restaurant->value)
```

### Implementation

В `OriginVoting`:

```php
public ?string $error = null;

public function vote(string $origin, VoteOriginAction $voteOriginAction): void
{
    $this->error = null;

    try {
        $originType = OriginType::from($origin);

        $voteOriginAction->handle(auth()->user(), $this->post, $originType);

        $this->dispatch('origin-voted', postId: $this->postId);
    } catch (CannotVoteOriginException $e) {
        $this->error = $e->getMessage();
    }
}
```

View:

```blade
<button wire:click="vote('homemade')">Homemade</button>
<button wire:click="vote('restaurant')">Restaurant</button>
```

### Acceptance criteria

- Homemade button calls action.
- Restaurant button calls action.
- Origin vote row created/updated.
- `origin-voted` event dispatched.
- Error state can render if action blocks.
- Tests pass.

### Definition of Done

- Tests написаны.
- Component calls action.
- Tests проходят.
- Коммит: `RG-294: Test OriginVoting calls VoteOriginAction`

### Files likely touched

```txt
app/Livewire/Posts/OriginVoting.php
resources/views/livewire/posts/origin-voting.blade.php
tests/Feature/Livewire/OriginVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-295 — Render Homemade/Restaurant Buttons In PostCard

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-295-render-homemade-restaurant-buttons-in-post-card`  
**Base branch:** develop
**Depends on:** RG-294

### Goal

Встроить `OriginVoting` в PostCard.

### TDD step

Blade render test:

```php
it('renders origin voting component in post card', function () {
    $post = Post::factory()->published()->create();

    $html = Blade::render('<x-feed.post-card :post="$post" />', [
        'post' => $post,
    ]);

    expect($html)->toContain('data-testid="post-card-origin-voting"');
});
```

Если Livewire component не рендерится в Blade render test, проверять wrapper marker.

### Implementation

В `resources/views/components/feed/post-card.blade.php`:

```blade
@if($post->exists)
    <div data-testid="post-card-origin-voting">
        <livewire:posts.origin-voting
            :post-id="$post->id"
            :key="'post-card-origin-voting-'.$post->id"
        />
    </div>
@else
    <div data-testid="post-card-origin-preview">
        <x-ui.badge>Homemade {{ $post->homemade_votes_count }}</x-ui.badge>
        <x-ui.badge>Restaurant {{ $post->restaurant_votes_count }}</x-ui.badge>
    </div>
@endif
```

Важно не сломать UI Kit demo с unsaved post.

### Acceptance criteria

- PostCard includes OriginVoting for persisted posts.
- UI Kit demo with unsaved post does not break.
- Homemade/Restaurant buttons visible in feed cards.
- No duplicated origin action logic in PostCard.
- Test passes.

### Definition of Done

- Test написан.
- OriginVoting встроен.
- UI Kit не сломан.
- Коммит: `RG-295: Render Homemade/Restaurant buttons in PostCard`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-296 — Render Homemade/Restaurant Panel In Drawer

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-296-render-homemade-restaurant-panel-in-drawer`  
**Base branch:** develop
**Depends on:** RG-294

### Goal

Встроить Homemade/Restaurant panel в PostDrawer.

### TDD step

Livewire test:

```php
it('renders origin voting panel in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-origin-voting"', false)
        ->assertSee('Homemade')
        ->assertSee('Restaurant');
});
```

### Implementation

В `post-drawer.blade.php`:

```blade
<section data-testid="post-drawer-origin-voting">
    <h3>Homemade or Restaurant?</h3>

    <livewire:posts.origin-voting
        :post-id="$post->id"
        :key="'post-drawer-origin-voting-'.$post->id"
    />
</section>
```

Не добавлять PostShow integration, так как его нет в Phase 14 backlog.

### Acceptance criteria

- Drawer includes OriginVoting.
- Homemade/Restaurant buttons visible in drawer.
- Component key stable by post id.
- No duplicated action logic.
- Test passes.

### Definition of Done

- Test написан.
- OriginVoting встроен в drawer.
- Коммит: `RG-296: Render Homemade/Restaurant panel in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-297 — Render Origin Distribution Bar

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-297-render-origin-distribution-bar`  
**Base branch:** develop
**Depends on:** RG-294

### Goal

Добавить distribution bar для Homemade/Restaurant votes.

### TDD step

Livewire test:

```php
it('renders origin distribution bar', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 3,
        'restaurant_votes_count' => 1,
    ]);

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('75%')
        ->assertSee('25%')
        ->assertSee('data-testid="origin-distribution-bar"', false);
});
```

Zero votes test:

```php
it('renders zero origin distribution safely', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    Livewire::test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('0%')
        ->assertSee('data-testid="origin-distribution-bar"', false);
});
```

### Implementation

In `OriginVoting` view:

```blade
@php
    $homemade = $post->homemade_votes_count;
    $restaurant = $post->restaurant_votes_count;
    $total = $homemade + $restaurant;

    $homemadePct = $total > 0 ? round(($homemade / $total) * 100) : 0;
    $restaurantPct = $total > 0 ? 100 - $homemadePct : 0;
@endphp

<div data-testid="origin-distribution-bar">
    <div class="flex justify-between">
        <span>Homemade {{ $homemadePct }}%</span>
        <span>Restaurant {{ $restaurantPct }}%</span>
    </div>

    <div class="h-2 rounded-full bg-rg-surface-muted">
        <div
            class="h-2 rounded-full bg-rg-accent"
            style="width: {{ $homemadePct }}%"
        ></div>
    </div>
</div>
```

Можно добавить counts:

```txt
3 Homemade / 1 Restaurant
```

### Acceptance criteria

- Distribution bar renders.
- Percentages correct.
- Zero-vote state safe.
- No division by zero.
- Uses dark/accent UI.
- Test passes.

### Definition of Done

- Tests написаны.
- Distribution bar добавлен.
- Tests проходят.
- Коммит: `RG-297: Render origin distribution bar`

### Files likely touched

```txt
resources/views/livewire/posts/origin-voting.blade.php
tests/Feature/Livewire/OriginVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-298 — Refresh Origin Counters After Vote

**Area:** Livewire / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-298-refresh-origin-counters-after-vote`  
**Base branch:** develop
**Depends on:** RG-297

### Goal

После origin vote обновлять counters/distribution в OriginVoting и связанных UI местах.

### TDD step

Livewire test:

```php
it('refreshes origin counters after vote', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['postId' => $post->id])
        ->assertSee('0%')
        ->call('vote', OriginType::Homemade->value)
        ->assertSee('100%')
        ->assertSee('Homemade');
});
```

Change vote refresh test:

```php
->call('vote', OriginType::Restaurant->value)
->assertSee('0%') // Homemade
->assertSee('100%') // Restaurant
```

Need avoid brittle duplicate `100%`. Use labels and counts if possible.

### Implementation

In `OriginVoting` after action:

```php
$this->dispatch('origin-voted', postId: $this->postId);
```

Ensure post is fresh on every render:

```php
public function getPostProperty(): Post
{
    return Post::query()->published()->findOrFail($this->postId);
}
```

If Livewire computed property caches, unset cached property after vote or use explicit `loadPost()` method.

Add loading state:

```blade
<button wire:loading.attr="disabled" wire:target="vote">
```

If not already added in RG-294.

Optional listener in parent components:

```php
#[On('origin-voted')]
public function refreshAfterOriginVote(): void {}
```

Minimum DoD: OriginVoting itself refreshes counters.

### Acceptance criteria

- Homemade vote updates homemade counter/distribution.
- Restaurant vote updates restaurant counter/distribution.
- Changed vote updates both sides.
- Same vote no-op keeps counters stable.
- `origin-voted` event dispatched.
- Loading state exists or at least buttons disabled during vote.
- All OriginVoting and VoteOriginAction tests pass.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Counter refresh реализован.
- Tests pass.
- Build passes.
- Коммит: `RG-298: Refresh origin counters after vote`

### Files likely touched

```txt
app/Livewire/Posts/OriginVoting.php
resources/views/livewire/posts/origin-voting.blade.php
app/Livewire/Feed/PostDrawer.php
tests/Feature/Livewire/OriginVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 12. Phase 14 Completion Criteria

Phase 14 завершена, когда:

```txt
- RG-280–RG-298 выполнены;
- VoteOriginAction существует;
- user can vote Homemade on published post;
- user can vote Restaurant on published post;
- origin vote can be changed;
- same origin vote remains selected/no-op;
- guest cannot vote origin;
- hidden/pending/rejected posts cannot receive origin votes;
- Unknown origin cannot be used as a vote;
- counters update correctly;
- counters do not go negative;
- action uses transaction;
- OriginVoting Livewire component exists;
- OriginVoting calls VoteOriginAction;
- Homemade/Restaurant buttons render in PostCard;
- Homemade/Restaurant panel renders in drawer;
- origin distribution bar renders;
- counters refresh after vote;
- composer test passes;
- npm run build passes.
```
---

# 13. Что нельзя делать в Phase 14

Без отдельной задачи нельзя:

```txt
- делать cuisine voting;
- добавлять OriginVoting в PostShow, если нет отдельной задачи;
- делать comments;
- делать reports;
- делать moderation controls;
- делать analytics;
- делать anti-fraud;
- делать rate limiting;
- делать IP/device tracking;
- делать notifications;
- пересчитывать hot_score;
- менять FeedQuery ranking;
- делать generic RecalculatePostCountersAction;
- добавлять Redis/cache layer;
- делать API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 14. Recommended Execution Order

```txt
RG-280 Create VoteOriginAction skeleton
RG-281 Test user can vote homemade
RG-282 Implement homemade vote
RG-283 Test user can vote restaurant
RG-284 Implement restaurant vote
RG-285 Test origin vote can be changed
RG-286 Implement origin vote update
RG-287 Test same origin vote toggles or remains selected
RG-288 Decide and implement same-origin behavior
RG-289 Test guest cannot vote origin
RG-290 Add auth guard for origin vote
RG-291 Test hidden post cannot receive origin vote
RG-292 Add status guard for origin vote
RG-293 Create OriginVoting Livewire component
RG-294 Test OriginVoting calls VoteOriginAction
RG-295 Render Homemade/Restaurant buttons in PostCard
RG-296 Render Homemade/Restaurant panel in drawer
RG-297 Render origin distribution bar
RG-298 Refresh origin counters after vote
```
---

# 15. Release

После завершения Phase 14:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.5-phase14-homemade-restaurant-voting
git push -u origin release/v0.1.5-phase14-homemade-restaurant-voting
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.5-phase14-homemade-restaurant-voting -m "RateGuru Phase 14 homemade restaurant voting"
git push origin v0.1.5-phase14-homemade-restaurant-voting
```
