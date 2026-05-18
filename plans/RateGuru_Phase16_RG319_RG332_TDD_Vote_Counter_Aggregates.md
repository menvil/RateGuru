# RateGuru — Phase 16 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 16 — Vote Counter Aggregates**  
Диапазон задач: **RG-319 → RG-332**  
Основа нумерации: исходный atomic backlog, где Phase 16 начинается с задачи 319 и заканчивается задачей 332.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 16 соответствует исходному блоку:

```txt
Phase 16 — Vote Counter Aggregates
```

Правильный диапазон Phase 16:

```txt
RG-319 — Create RecalculatePostCountersAction skeleton
RG-320 — Test counters update after upvote
RG-321 — Implement upvote counter recalculation
RG-322 — Test counters update after downvote
RG-323 — Implement downvote counter recalculation
RG-324 — Test counters update after origin vote
RG-325 — Implement origin counter recalculation
RG-326 — Test counters update after cuisine vote
RG-327 — Implement cuisine counter recalculation
RG-328 — Dispatch counter recalculation after post vote
RG-329 — Dispatch counter recalculation after origin vote
RG-330 — Dispatch counter recalculation after cuisine vote
RG-331 — Add fallback command to recalculate all counters
RG-332 — Test fallback counter command
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 16

Phase 16 добавляет защитный слой для пересчёта vote counters и агрегатов.

После Phase 16 должно быть готово:

```txt
- RecalculatePostCountersAction;
- пересчёт upvotes_count из post_votes;
- пересчёт downvotes_count из post_votes;
- пересчёт homemade_votes_count из origin_votes;
- пересчёт restaurant_votes_count из origin_votes;
- пересчёт cuisine distribution из cuisine_votes без добавления новых колонок в posts;
- автоматический dispatch пересчёта после post vote;
- автоматический dispatch пересчёта после origin vote;
- автоматический dispatch пересчёта после cuisine vote;
- fallback artisan command для пересчёта всех counters;
- tests для action и command.
```

Главная задача фазы — исправлять рассинхрон counters, который может появиться после багов, ручных операций, сидов, импорта или будущих миграций.
---

# 3. Главный технический нюанс

В `posts` есть persisted counters:

```txt
upvotes_count
downvotes_count
homemade_votes_count
restaurant_votes_count
comments_count
reports_count
hot_score
```

Но нет колонок:

```txt
italian_votes_count
asian_votes_count
american_votes_count
mexican_votes_count
other_votes_count
```

Поэтому `RG-326/RG-327` нельзя трактовать как добавление cuisine counter columns.

Правильная трактовка Phase 16:

```txt
- persisted post counters пересчитываются и сохраняются в posts;
- cuisine counters пересчитываются как read-side aggregate/distribution из cuisine_votes;
- fallback command может проверить/прогреть/вывести cuisine aggregate, но не пишет несуществующие колонки.
```

Если очень хочется persisted cuisine counters, это должна быть отдельная фаза с новой migration, а не скрытое изменение Phase 16.
---

# 4. Scope Phase 16

## Входит

```txt
- RecalculatePostCountersAction;
- PostCounterSnapshot DTO;
- пересчёт up/down counters;
- пересчёт homemade/restaurant counters;
- cuisine distribution aggregate;
- optional lightweight CuisineVoteDistribution helper/query;
- dispatch recalculation after VotePostAction;
- dispatch recalculation after VoteOriginAction;
- dispatch recalculation after VoteCuisineAction;
- artisan command для пересчёта всех posts;
- command tests.
```

## Не входит

```txt
- новые cuisine counter columns;
- comments_count recalculation;
- reports_count recalculation;
- hot_score recalculation;
- caching;
- queues;
- Redis;
- Horizon;
- analytics;
- admin dashboard;
- UI changes;
- API endpoint.
```

`comments_count` будет затронут в Comments Backend.  
`reports_count` будет затронут в Reports Backend.  
`hot_score` будет отдельной будущей фазой.
---

# 5. Design Decisions

## 5.1. Action должен быть idempotent

Повторный запуск:

```php
RecalculatePostCountersAction::handle($post)
```

не должен менять результат, если underlying votes не изменились.

## 5.2. Action должен считать из source-of-truth tables

Source of truth:

```txt
post_votes      → upvotes_count/downvotes_count
origin_votes    → homemade_votes_count/restaurant_votes_count
cuisine_votes   → cuisine distribution aggregate
```

Нельзя пересчитывать из текущих `posts.*_count`, потому что именно они могут быть неверными.

## 5.3. Action должен быть безопасен для рассинхрона

Если в `posts.upvotes_count = 999`, а в `post_votes` реально один upvote, action должен поставить:

```txt
upvotes_count = 1
```

Это не increment/decrement action.  
Это absolute recalculation.

## 5.4. Counters не должны быть negative

Пересчёт через `COUNT(*)` естественно не даст negative values.  
Но tests должны фиксировать, что старые отрицательные значения исправляются.

## 5.5. Cuisine distribution should be returned, not persisted

Рекомендуемый DTO:

```php
final readonly class PostCounterSnapshot
{
    public function __construct(
        public int $upvotes,
        public int $downvotes,
        public int $homemadeVotes,
        public int $restaurantVotes,
        /** @var array<string,int> */
        public array $cuisineVotes = [],
    ) {}
}
```

Action может:

```txt
- persist persisted counters to posts;
- return snapshot with cuisine distribution.
```

Это честно закрывает `RG-327`, не ломая схему.

## 5.6. Dispatch after vote should not require queue

`Dispatch counter recalculation` в Phase 16 лучше понимать как **вызов action после успешного vote**, а не Laravel queued job.

Причина:

```txt
- проект пока не использует Redis/queue worker;
- counters нужны сразу после vote;
- голосования уже синхронные;
- queue foundation не входит в Phase 16.
```

Можно реализовать как прямой вызов:

```php
$this->recalculatePostCountersAction->handle($post);
```

или через Laravel event/listener, но не тащить queue.

Рекомендация для Phase 16:

```txt
прямой вызов action внутри VotePostAction/VoteOriginAction/VoteCuisineAction после изменения vote.
```

Если позже нужен event-based pipeline, это отдельная фаза.
---

# 6. Architecture Rules

## 6.1. Vote actions keep business logic, counter action keeps repair logic

`VotePostAction`, `VoteOriginAction`, `VoteCuisineAction` уже могут обновлять counters синхронно.  
Phase 16 не обязательно удаляет эту логику.

Но после Phase 16 должен появиться единый способ восстановить/проверить counters:

```php
app(RecalculatePostCountersAction::class)->handle($post);
```

Можно постепенно упростить vote actions:

```txt
- сначала они меняют votes;
- затем вызывают recalc action;
- recalc action устанавливает counters абсолютными значениями.
```

Это надёжнее, чем increment/decrement logic.

## 6.2. Avoid circular dependency

Нельзя делать так:

```txt
VotePostAction → RecalculatePostCountersAction → VotePostAction
```

Counter action не должен вызывать vote actions.

## 6.3. No UI in Phase 16

Не менять:

```txt
PostCard
PostDrawer
PostShow
PostVoting
OriginVoting
CuisineVoting
```

кроме случаев, если tests падают из-за изменённой синхронизации counters.

## 6.4. Command must be safe on production data

Fallback command должен:

```txt
- проходить по posts chunkById;
- пересчитывать counters;
- выводить summary;
- не падать на одном проблемном post, если можно продолжить;
- иметь tests.
```

Не надо делать interactive prompts.
---

# 7. GitFlow для Phase 16

## Base branch

Все задачи Phase 16 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-319-create-recalculate-post-counters-action-skeleton
feature/RG-327-implement-cuisine-counter-recalculation
feature/RG-331-add-fallback-command-to-recalculate-all-counters
```

## Commit format

```txt
RG-319: Create RecalculatePostCountersAction skeleton
RG-327: Implement cuisine counter recalculation
RG-331: Add fallback command to recalculate all counters
```

## Release branch

После выполнения `RG-319`–`RG-332`:

```txt
release/v0.1.7-phase16-vote-counter-aggregates
```

## Tag

После merge release branch в `main`:

```txt
v0.1.7-phase16-vote-counter-aggregates
```
---

# 8. TDD Rules for Phase 16

## Для RecalculatePostCountersAction

Каждый test должен намеренно создавать рассинхрон:

```txt
posts.upvotes_count = wrong value
actual post_votes = real value
```

Потом action должен исправить persisted counter.

## Для cuisine aggregate

Тест не должен ожидать новых columns.  
Он должен проверять returned snapshot:

```php
$snapshot->cuisineVotes['italian'] === 2
```

или отдельный distribution helper.

## Для dispatch after vote

Тест должен ломать old counter перед vote и проверять, что после vote значение стало абсолютным правильным count, а не просто increment from wrong value.

Пример:

```txt
posts.upvotes_count = 99
existing post_votes = 0
user votes up
after action: upvotes_count = 1, not 100
```

Это доказывает, что вызывается recalculation, а не старый increment.

## Для command

Command test должен:

```txt
- создать несколько posts;
- намеренно испортить counters;
- запустить artisan command;
- проверить, что counters исправлены.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Console / Tests
Type: Test / Feature / Action / Command
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

# 10. Phase 16 Atomic Tasks
---

## RG-319 — Create RecalculatePostCountersAction Skeleton

**Area:** Backend  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-319-create-recalculate-post-counters-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-318

### Goal

Создать skeleton action для пересчёта counters и агрегатов.

### TDD step

Unit test:

```php
it('has recalculate post counters action with handle method', function () {
    $action = app(RecalculatePostCountersAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Counters/RecalculatePostCountersAction.php
app/Data/Counters/PostCounterSnapshot.php
```

Skeleton:

```php
namespace App\Actions\Counters;

use App\Data\Counters\PostCounterSnapshot;
use App\Models\Post;

final class RecalculatePostCountersAction
{
    public function handle(Post $post): PostCounterSnapshot
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

DTO:

```php
namespace App\Data\Counters;

final readonly class PostCounterSnapshot
{
    public function __construct(
        public int $upvotes,
        public int $downvotes,
        public int $homemadeVotes,
        public int $restaurantVotes,
        public array $cuisineVotes = [],
    ) {}
}
```

На этой задаче не реализовывать пересчёт.

### Acceptance criteria

- `RecalculatePostCountersAction` существует.
- `PostCounterSnapshot` существует.
- Action имеет `handle(Post $post): PostCounterSnapshot`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Action skeleton создан.
- DTO создан.
- Тест проходит.
- Коммит: `RG-319: Create RecalculatePostCountersAction skeleton`

### Files likely touched

```txt
app/Actions/Counters/RecalculatePostCountersAction.php
app/Data/Counters/PostCounterSnapshot.php
tests/Unit/Actions/RecalculatePostCountersActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-320 — Test Counters Update After Upvote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-320-test-counters-update-after-upvote`  
**Base branch:** develop
**Depends on:** RG-319

### Goal

Написать падающий тест: action пересчитывает `upvotes_count` из `post_votes`.

### TDD step

Feature/action test:

```php
it('recalculates upvote counter from post votes', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 99,
        'downvotes_count' => 0,
    ]);

    PostVote::factory()->for($post)->create([
        'type' => VoteType::Up,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($snapshot->upvotes)->toBe(1);
});
```

Также добавить negative repair test:

```php
it('repairs negative upvote counter', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => -5,
    ]);

    app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->upvotes_count)->toBe(0);
});
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Тесты существуют.
- Тест намеренно создаёт wrong persisted counter.
- Action должен поставить абсолютное значение из `post_votes`.
- Snapshot должен вернуть пересчитанное значение.
- Тест падает до RG-321.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-320: Test counters update after upvote`

### Files likely touched

```txt
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-321 — Implement Upvote Counter Recalculation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-321-implement-upvote-counter-recalculation`  
**Base branch:** develop
**Depends on:** RG-320

### Goal

Реализовать пересчёт `upvotes_count`.

### TDD step

Использовать падающие тесты из RG-320.

### Implementation

В `RecalculatePostCountersAction::handle()`:

```php
$upvotes = PostVote::query()
    ->where('post_id', $post->id)
    ->where('type', VoteType::Up)
    ->count();

$post->forceFill([
    'upvotes_count' => $upvotes,
])->save();
```

Пока остальные values в snapshot можно вернуть из текущих persisted values или 0, но лучше сразу собрать структуру аккуратно:

```php
return new PostCounterSnapshot(
    upvotes: $upvotes,
    downvotes: $post->fresh()->downvotes_count,
    homemadeVotes: $post->fresh()->homemade_votes_count,
    restaurantVotes: $post->fresh()->restaurant_votes_count,
    cuisineVotes: [],
);
```

Следующие задачи расширят.

### Acceptance criteria

- upvotes_count пересчитывается из `post_votes`.
- Wrong persisted value исправляется.
- Negative value исправляется в 0.
- Snapshot содержит upvotes.
- Тесты RG-320 проходят.

### Definition of Done

- Реализация минимальная.
- Тесты проходят.
- Коммит: `RG-321: Implement upvote counter recalculation`

### Files likely touched

```txt
app/Actions/Counters/RecalculatePostCountersAction.php
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-322 — Test Counters Update After Downvote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-322-test-counters-update-after-downvote`  
**Base branch:** develop
**Depends on:** RG-321

### Goal

Написать падающий тест: action пересчитывает `downvotes_count` из `post_votes`.

### TDD step

Feature/action test:

```php
it('recalculates downvote counter from post votes', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 99,
    ]);

    PostVote::factory()->for($post)->create([
        'type' => VoteType::Down,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->downvotes_count)->toBe(1);
    expect($snapshot->downvotes)->toBe(1);
});
```

Также проверить, что up/down считаются независимо:

```php
PostVote::factory()->for($post)->create(['type' => VoteType::Up]);
PostVote::factory()->for($post)->create(['type' => VoteType::Down]);
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Тесты существуют.
- Wrong downvotes_count исправляется.
- Snapshot должен вернуть downvotes.
- Тест падает до RG-323.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-322: Test counters update after downvote`

### Files likely touched

```txt
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-323 — Implement Downvote Counter Recalculation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-323-implement-downvote-counter-recalculation`  
**Base branch:** develop
**Depends on:** RG-322

### Goal

Реализовать пересчёт `downvotes_count`.

### TDD step

Использовать падающие тесты из RG-322.

### Implementation

В action:

```php
$downvotes = PostVote::query()
    ->where('post_id', $post->id)
    ->where('type', VoteType::Down)
    ->count();

$post->forceFill([
    'upvotes_count' => $upvotes,
    'downvotes_count' => $downvotes,
])->save();
```

Лучше на этом этапе `handle()` уже пересчитывает оба counters вместе:

```txt
upvotes_count
downvotes_count
```

### Acceptance criteria

- downvotes_count пересчитывается из `post_votes`.
- upvotes_count продолжает пересчитываться.
- Snapshot содержит upvotes/downvotes.
- Тесты проходят.

### Definition of Done

- Downvote recalculation добавлен.
- Тесты проходят.
- Коммит: `RG-323: Implement downvote counter recalculation`

### Files likely touched

```txt
app/Actions/Counters/RecalculatePostCountersAction.php
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-324 — Test Counters Update After Origin Vote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-324-test-counters-update-after-origin-vote`  
**Base branch:** develop
**Depends on:** RG-323

### Goal

Написать падающий тест: action пересчитывает origin counters из `origin_votes`.

### TDD step

Feature/action test:

```php
it('recalculates origin vote counters from origin votes', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 99,
        'restaurant_votes_count' => 88,
    ]);

    OriginVote::factory()->for($post)->create([
        'origin' => OriginType::Homemade,
    ]);

    OriginVote::factory()->for($post)->create([
        'origin' => OriginType::Restaurant,
    ]);

    OriginVote::factory()->for($post)->create([
        'origin' => OriginType::Restaurant,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(2);
    expect($snapshot->homemadeVotes)->toBe(1);
    expect($snapshot->restaurantVotes)->toBe(2);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Wrong homemade/restaurant counters исправляются.
- Snapshot должен вернуть origin counters.
- Тест падает до RG-325.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-324: Test counters update after origin vote`

### Files likely touched

```txt
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-325 — Implement Origin Counter Recalculation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-325-implement-origin-counter-recalculation`  
**Base branch:** develop
**Depends on:** RG-324

### Goal

Реализовать пересчёт:

```txt
homemade_votes_count
restaurant_votes_count
```

из `origin_votes`.

### TDD step

Использовать падающий тест из RG-324.

### Implementation

В action:

```php
$homemadeVotes = OriginVote::query()
    ->where('post_id', $post->id)
    ->where('origin', OriginType::Homemade)
    ->count();

$restaurantVotes = OriginVote::query()
    ->where('post_id', $post->id)
    ->where('origin', OriginType::Restaurant)
    ->count();

$post->forceFill([
    'upvotes_count' => $upvotes,
    'downvotes_count' => $downvotes,
    'homemade_votes_count' => $homemadeVotes,
    'restaurant_votes_count' => $restaurantVotes,
])->save();
```

Не считать `OriginType::Unknown`, потому что user votes не должны его использовать.

### Acceptance criteria

- homemade_votes_count пересчитывается.
- restaurant_votes_count пересчитывается.
- up/down counters still work.
- Snapshot содержит all four persisted vote counters.
- Тесты проходят.

### Definition of Done

- Origin recalculation добавлен.
- Тесты проходят.
- Коммит: `RG-325: Implement origin counter recalculation`

### Files likely touched

```txt
app/Actions/Counters/RecalculatePostCountersAction.php
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-326 — Test Counters Update After Cuisine Vote

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-326-test-counters-update-after-cuisine-vote`  
**Base branch:** develop
**Depends on:** RG-325

### Goal

Написать тест: action пересчитывает cuisine aggregate из `cuisine_votes`.

Важно: это не persisted columns в `posts`.

### TDD step

Feature/action test:

```php
it('recalculates cuisine vote distribution from cuisine votes', function () {
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->for($post)->create([
        'cuisine' => CuisineType::Italian,
    ]);

    CuisineVote::factory()->for($post)->create([
        'cuisine' => CuisineType::Italian,
    ]);

    CuisineVote::factory()->for($post)->create([
        'cuisine' => CuisineType::Asian,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($snapshot->cuisineVotes)->toMatchArray([
        CuisineType::Italian->value => 2,
        CuisineType::Asian->value => 1,
        CuisineType::American->value => 0,
        CuisineType::Mexican->value => 0,
        CuisineType::Other->value => 0,
    ]);
});
```

Добавить safety test:

```php
it('does not require persisted cuisine counter columns on posts', function () {
    $post = Post::factory()->published()->create();

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($snapshot->cuisineVotes)->toBeArray();
});
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Тесты существуют.
- Cuisine distribution считается из `cuisine_votes`.
- Все valid cuisines присутствуют в snapshot.
- Missing cuisines имеют 0.
- Тест не ожидает новых columns в posts.
- Тест падает до RG-327.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают.
- Коммит: `RG-326: Test counters update after cuisine vote`

### Files likely touched

```txt
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-327 — Implement Cuisine Counter Recalculation

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-327-implement-cuisine-counter-recalculation`  
**Base branch:** develop
**Depends on:** RG-326

### Goal

Реализовать cuisine distribution aggregate в `RecalculatePostCountersAction`.

### TDD step

Использовать падающие тесты из RG-326.

### Implementation

В action:

```php
$cuisineCounts = CuisineVote::query()
    ->where('post_id', $post->id)
    ->selectRaw('cuisine, COUNT(*) as total')
    ->groupBy('cuisine')
    ->pluck('total', 'cuisine')
    ->map(fn ($value) => (int) $value)
    ->all();

$validCuisines = [
    CuisineType::Italian,
    CuisineType::Asian,
    CuisineType::American,
    CuisineType::Mexican,
    CuisineType::Other,
];

$cuisineVotes = [];

foreach ($validCuisines as $cuisine) {
    $cuisineVotes[$cuisine->value] = (int) ($cuisineCounts[$cuisine->value] ?? 0);
}
```

Return snapshot:

```php
return new PostCounterSnapshot(
    upvotes: $upvotes,
    downvotes: $downvotes,
    homemadeVotes: $homemadeVotes,
    restaurantVotes: $restaurantVotes,
    cuisineVotes: $cuisineVotes,
);
```

Не писать cuisine values в `posts`.

### Acceptance criteria

- Snapshot содержит cuisineVotes.
- All valid cuisines present.
- Missing cuisines = 0.
- Unknown cuisine not included.
- No new post columns added.
- Tests pass.

### Definition of Done

- Cuisine aggregate реализован.
- Тесты проходят.
- Коммит: `RG-327: Implement cuisine counter recalculation`

### Files likely touched

```txt
app/Actions/Counters/RecalculatePostCountersAction.php
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-328 — Dispatch Counter Recalculation After Post Vote

**Area:** Backend / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-328-dispatch-counter-recalculation-after-post-vote`  
**Base branch:** develop
**Depends on:** RG-327

### Goal

После up/down vote запускать `RecalculatePostCountersAction`.

### TDD step

Feature/action test:

```php
it('recalculates counters after post vote instead of incrementing stale value', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'upvotes_count' => 99,
        'downvotes_count' => 88,
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(0);
});
```

Этот тест критичен: если старый код просто increment, получится 100, а должно быть 1.

### Implementation

В `VotePostAction` после изменения `post_votes` вызвать:

```php
app(RecalculatePostCountersAction::class)->handle($post->fresh());
```

Лучше внедрить через constructor:

```php
public function __construct(
    private readonly RecalculatePostCountersAction $recalculatePostCounters,
) {}
```

и вызвать внутри transaction или сразу после transaction.

Рекомендация:

```txt
- votes mutation inside transaction;
- recalculate inside same transaction, если удобно;
- иначе сразу после transaction.
```

Для синхронности UI лучше до return.

### Acceptance criteria

- After upvote, counters absolute-recalculated.
- After downvote, counters absolute-recalculated.
- Toggle/replacement behavior still correct.
- Stale counters fixed.
- Tests pass.

### Definition of Done

- VotePostAction вызывает RecalculatePostCountersAction.
- Tests pass.
- Коммит: `RG-328: Dispatch counter recalculation after post vote`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
tests/Feature/Actions/VotePostActionTest.php
tests/Feature/Actions/RecalculatePostCountersActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-329 — Dispatch Counter Recalculation After Origin Vote

**Area:** Backend / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-329-dispatch-counter-recalculation-after-origin-vote`  
**Base branch:** develop
**Depends on:** RG-328

### Goal

После Homemade/Restaurant vote запускать `RecalculatePostCountersAction`.

### TDD step

Feature/action test:

```php
it('recalculates counters after origin vote instead of incrementing stale value', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 99,
        'restaurant_votes_count' => 88,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});
```

Change vote stale test:

```php
Homemade → Restaurant should produce 0/1, not stale adjusted.
```

### Implementation

В `VoteOriginAction` после изменения `origin_votes` вызвать:

```php
$this->recalculatePostCounters->handle($post->fresh());
```

Через constructor dependency лучше, чем `app()`.

Если VoteOriginAction ещё вручную increment/decrement counters, можно оставить, но recalc должен исправлять результат. Лучше постепенно убрать manual counter updates после успешного подключения recalc, чтобы не было двойной логики.

### Acceptance criteria

- Origin vote triggers recalc.
- Stale homemade/restaurant counters fixed.
- Same-origin no-op keeps counters correct after recalc or does not call recalc unnecessarily.
- Change vote updates absolute counters.
- Tests pass.

### Definition of Done

- VoteOriginAction вызывает RecalculatePostCountersAction.
- Tests pass.
- Коммит: `RG-329: Dispatch counter recalculation after origin vote`

### Files likely touched

```txt
app/Actions/Votes/VoteOriginAction.php
tests/Feature/Actions/VoteOriginActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-330 — Dispatch Counter Recalculation After Cuisine Vote

**Area:** Backend / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-330-dispatch-counter-recalculation-after-cuisine-vote`  
**Base branch:** develop
**Depends on:** RG-329

### Goal

После cuisine vote запускать `RecalculatePostCountersAction`.

Это не обновит persisted cuisine counters, потому что их нет.  
Но это гарантирует единый hook после всех типов vote и возвращает актуальный cuisine aggregate/snapshot при необходимости.

### TDD step

Feature/action test with spy/fake.

Вариант через fake action:

```php
it('calls counter recalculation after cuisine vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $fake = new class extends RecalculatePostCountersAction {
        public bool $called = false;

        public function handle(Post $post): PostCounterSnapshot
        {
            $this->called = true;

            return new PostCounterSnapshot(
                upvotes: 0,
                downvotes: 0,
                homemadeVotes: 0,
                restaurantVotes: 0,
                cuisineVotes: []
            );
        }
    };

    app()->instance(RecalculatePostCountersAction::class, $fake);

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);

    expect($fake->called)->toBeTrue();
});
```

Если extending final class невозможно, использовать mockery spy.

Более простой тест:

```php
after cuisine vote, RecalculatePostCountersAction::handle($post) returns updated cuisineVotes
```

Но это не доказывает dispatch. Лучше spy/mock.

### Implementation

В `VoteCuisineAction` после изменения `cuisine_votes` вызвать:

```php
$this->recalculatePostCounters->handle($post->fresh());
```

Через constructor dependency:

```php
public function __construct(
    private readonly RecalculatePostCountersAction $recalculatePostCounters,
) {}
```

### Acceptance criteria

- VoteCuisineAction triggers RecalculatePostCountersAction.
- Cuisine vote row still created/updated.
- Same-cuisine no-op behavior remains correct.
- No persisted cuisine columns added.
- Tests pass.

### Definition of Done

- VoteCuisineAction вызывает RecalculatePostCountersAction.
- Tests pass.
- Коммит: `RG-330: Dispatch counter recalculation after cuisine vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-331 — Add Fallback Command To Recalculate All Counters

**Area:** Console / Backend  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-331-add-fallback-command-to-recalculate-all-counters`  
**Base branch:** develop
**Depends on:** RG-330

### Goal

Добавить artisan command для пересчёта counters всех posts.

### TDD step

Command existence test:

```php
it('has recalculate post counters command', function () {
    $this->artisan('rateguru:recalculate-post-counters')
        ->assertExitCode(0);
});
```

Этот тест может быть слишком слабым. Основной behavior test будет RG-332.

### Implementation

Создать command:

```bash
php artisan make:command RecalculatePostCountersCommand
```

Файл:

```txt
app/Console/Commands/RecalculatePostCountersCommand.php
```

Signature:

```php
protected $signature = 'rateguru:recalculate-post-counters {--post-id=}';
```

Description:

```php
protected $description = 'Recalculate vote counters for posts.';
```

Implementation:

```php
public function handle(RecalculatePostCountersAction $action): int
{
    $postId = $this->option('post-id');

    $query = Post::query();

    if ($postId) {
        $query->whereKey($postId);
    }

    $count = 0;

    $query->chunkById(100, function ($posts) use ($action, &$count) {
        foreach ($posts as $post) {
            $action->handle($post);
            $count++;
        }
    });

    $this->info("Recalculated counters for {$count} posts.");

    return self::SUCCESS;
}
```

Не фильтровать только published posts: fallback command должен чинить counters для всех posts, потому что hidden/pending тоже могут иметь historical votes или быть модерируемыми.

### Acceptance criteria

- Command exists.
- Signature = `rateguru:recalculate-post-counters`.
- Supports optional `--post-id`.
- Uses chunkById.
- Calls RecalculatePostCountersAction.
- Prints summary.
- Exit code 0.
- Basic command test passes.

### Definition of Done

- Command создана.
- Basic test проходит.
- Коммит: `RG-331: Add fallback command to recalculate all counters`

### Files likely touched

```txt
app/Console/Commands/RecalculatePostCountersCommand.php
tests/Feature/Console/RecalculatePostCountersCommandTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-332 — Test Fallback Counter Command

**Area:** Console / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-332-test-fallback-counter-command`  
**Base branch:** develop
**Depends on:** RG-331

### Goal

Проверить, что fallback command реально исправляет counters.

### TDD step

Feature/console test:

```php
it('recalculates all post counters with fallback command', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 99,
        'downvotes_count' => 88,
        'homemade_votes_count' => 77,
        'restaurant_votes_count' => 66,
    ]);

    PostVote::factory()->for($post)->create(['type' => VoteType::Up]);
    PostVote::factory()->for($post)->create(['type' => VoteType::Down]);

    OriginVote::factory()->for($post)->create(['origin' => OriginType::Homemade]);
    OriginVote::factory()->for($post)->create(['origin' => OriginType::Restaurant]);
    OriginVote::factory()->for($post)->create(['origin' => OriginType::Restaurant]);

    $this->artisan('rateguru:recalculate-post-counters')
        ->expectsOutput('Recalculated counters for 1 posts.')
        ->assertExitCode(0);

    $post->refresh();

    expect($post->upvotes_count)->toBe(1);
    expect($post->downvotes_count)->toBe(1);
    expect($post->homemade_votes_count)->toBe(1);
    expect($post->restaurant_votes_count)->toBe(2);
});
```

Test `--post-id`:

```php
it('can recalculate one post by id', function () {
    $target = Post::factory()->published()->create(['upvotes_count' => 99]);
    $other = Post::factory()->published()->create(['upvotes_count' => 99]);

    PostVote::factory()->for($target)->create(['type' => VoteType::Up]);

    $this->artisan('rateguru:recalculate-post-counters', [
        '--post-id' => $target->id,
    ])->assertExitCode(0);

    expect($target->fresh()->upvotes_count)->toBe(1);
    expect($other->fresh()->upvotes_count)->toBe(99);
});
```

### Implementation

Если command из RG-331 не проходит tests:

```txt
- исправить signature;
- исправить chunk query;
- исправить output;
- исправить --post-id handling.
```

### Acceptance criteria

- Command fixes up/down counters.
- Command fixes origin counters.
- Command can target one post by id.
- Command does not add cuisine columns.
- Command exits 0.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Command behavior tests добавлены.
- Tests pass.
- Build passes.
- Коммит: `RG-332: Test fallback counter command`

### Files likely touched

```txt
tests/Feature/Console/RecalculatePostCountersCommandTest.php
app/Console/Commands/RecalculatePostCountersCommand.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 16 Completion Criteria

Phase 16 завершена, когда:

```txt
- RG-319–RG-332 выполнены;
- RecalculatePostCountersAction существует;
- PostCounterSnapshot существует;
- upvotes_count пересчитывается из post_votes;
- downvotes_count пересчитывается из post_votes;
- homemade_votes_count пересчитывается из origin_votes;
- restaurant_votes_count пересчитывается из origin_votes;
- cuisineVotes distribution считается из cuisine_votes;
- no cuisine counter columns added to posts;
- VotePostAction triggers counter recalculation;
- VoteOriginAction triggers counter recalculation;
- VoteCuisineAction triggers counter recalculation;
- fallback command exists;
- fallback command recalculates all posts;
- fallback command supports --post-id;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 16

Без отдельной задачи нельзя:

```txt
- добавлять italian_votes_count/asian_votes_count/etc в posts;
- пересчитывать comments_count;
- пересчитывать reports_count;
- пересчитывать hot_score;
- менять FeedQuery sorting;
- менять voting UI;
- добавлять admin dashboard;
- добавлять analytics;
- добавлять Redis/cache layer;
- добавлять queue jobs/Horizon;
- делать API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-319 Create RecalculatePostCountersAction skeleton
RG-320 Test counters update after upvote
RG-321 Implement upvote counter recalculation
RG-322 Test counters update after downvote
RG-323 Implement downvote counter recalculation
RG-324 Test counters update after origin vote
RG-325 Implement origin counter recalculation
RG-326 Test counters update after cuisine vote
RG-327 Implement cuisine counter recalculation
RG-328 Dispatch counter recalculation after post vote
RG-329 Dispatch counter recalculation after origin vote
RG-330 Dispatch counter recalculation after cuisine vote
RG-331 Add fallback command to recalculate all counters
RG-332 Test fallback counter command
```
---

# 14. Release

После завершения Phase 16:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.7-phase16-vote-counter-aggregates
git push -u origin release/v0.1.7-phase16-vote-counter-aggregates
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.7-phase16-vote-counter-aggregates -m "RateGuru Phase 16 vote counter aggregates"
git push origin v0.1.7-phase16-vote-counter-aggregates
```
