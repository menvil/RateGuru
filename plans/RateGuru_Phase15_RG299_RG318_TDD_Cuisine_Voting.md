# RateGuru — Phase 15 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 15 — Cuisine Voting**  
Диапазон задач: **RG-299 → RG-318**  
Основа нумерации: исходный atomic backlog, где Phase 15 начинается с задачи 299 и заканчивается задачей 318.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 15 соответствует исходному блоку:

```txt
Phase 15 — Cuisine Voting
```

Правильный диапазон Phase 15:

```txt
RG-299 — Create VoteCuisineAction skeleton
RG-300 — Test user can vote Italian cuisine
RG-301 — Implement Italian vote
RG-302 — Test user can vote Asian cuisine
RG-303 — Implement Asian vote
RG-304 — Test user can vote American cuisine
RG-305 — Implement American vote
RG-306 — Test user can vote Mexican cuisine
RG-307 — Implement Mexican vote
RG-308 — Test user can vote Other cuisine
RG-309 — Implement Other vote
RG-310 — Test cuisine vote can be changed
RG-311 — Implement cuisine vote update
RG-312 — Test guest cannot vote cuisine
RG-313 — Add auth guard for cuisine vote
RG-314 — Create CuisineVoting Livewire component
RG-315 — Test CuisineVoting calls VoteCuisineAction
RG-316 — Render cuisine buttons in drawer
RG-317 — Render cuisine distribution panel
RG-318 — Refresh cuisine counters after vote
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 15

Phase 15 добавляет голосование за кухню блюда:

```txt
Italian
Asian
American
Mexican
Other
```

После Phase 15 пользователь должен уметь:

```txt
- проголосовать за Italian;
- проголосовать за Asian;
- проголосовать за American;
- проголосовать за Mexican;
- проголосовать за Other;
- изменить свой cuisine vote;
- увидеть распределение голосов по кухням;
- голосовать из drawer.
```

Это отдельная механика от:

```txt
- up/down voting из Phase 13;
- Homemade/Restaurant voting из Phase 14.
```
---

# 3. Главный технический нюанс Phase 15

В текущей схеме `posts` есть counters для:

```txt
upvotes_count
downvotes_count
homemade_votes_count
restaurant_votes_count
comments_count
reports_count
```

Но нет колонок:

```txt
italian_votes_count
asian_votes_count
american_votes_count
mexican_votes_count
other_votes_count
```

Поэтому Phase 15 **не должна выдумывать новые колонки**.

Правильная модель для Phase 15:

```txt
- пользовательский выбор хранится в cuisine_votes;
- distribution panel считает агрегаты из cuisine_votes;
- refresh after vote перезагружает агрегаты;
- Phase 16 отдельно займётся Vote Counter Aggregates.
```

Нельзя в Phase 15 делать новую migration под cuisine counters, если её нет в backlog.
---

# 4. Scope Phase 15

## Входит

```txt
- VoteCuisineAction;
- создание cuisine vote;
- обновление existing cuisine vote;
- auth guard;
- user voting permission guard, если User::canVote() уже есть;
- published-only guard;
- CuisineVoting Livewire component;
- cuisine buttons in drawer;
- cuisine distribution panel;
- refresh distribution after vote.
```

## Не входит

```txt
- новые колонки в posts для cuisine counters;
- RecalculatePostCountersAction;
- hot_score recalculation;
- cuisine voting in PostCard;
- cuisine voting in PostShow;
- comments;
- reports;
- moderation;
- analytics;
- anti-fraud;
- rate limiting;
- notifications;
- API endpoint.
```

Counter aggregate foundation будет Phase 16.  
Comments — Phase 17+.  
Reports — Phase 19+.  
Moderation — Phase 21+.
---

# 5. Product Rules

## 5.1. One cuisine vote per user/post

Таблица `cuisine_votes` уже имеет unique constraint:

```txt
post_id + user_id
```

Значит:

```txt
- user не может одновременно выбрать Italian и Asian для одного post;
- смена кухни обновляет существующую запись;
- повторный выбор той же кухни — no-op.
```

## 5.2. Same cuisine vote behavior

В Phase 15 нет отдельной задачи для same-vote toggle.  
Но логика всё равно нужна, иначе повторный клик либо создаст unique violation, либо случайно сломает UX.

Решение:

```txt
Повторный клик по уже выбранной cuisine не снимает голос.
Он оставляет голос выбранным и не меняет данные.
```

Почему:

```txt
- cuisine vote — классификационный выбор, не лайк;
- случайный второй клик не должен удалять полезный сигнал;
- если позже нужен clear vote, это отдельная явная задача.
```

Правила:

```txt
No vote + Italian      → create Italian vote
No vote + Asian        → create Asian vote
No vote + American     → create American vote
No vote + Mexican      → create Mexican vote
No vote + Other        → create Other vote

Italian + Italian      → no-op
Asian + Asian          → no-op
American + American    → no-op
Mexican + Mexican      → no-op
Other + Other          → no-op

Italian + Asian        → update vote to Asian
Asian + Mexican        → update vote to Mexican
Any cuisine + Other    → update vote to Other
etc.
```

## 5.3. Unknown cuisine cannot be used as a vote

`CuisineType::Unknown` допустим для:

```txt
posts.cuisine_truth
```

но не для:

```txt
cuisine_votes.cuisine
```

Пользовательский vote должен быть одним из:

```txt
Italian
Asian
American
Mexican
Other
```

`Unknown` должен быть заблокирован в `VoteCuisineAction`.

## 5.4. Only published posts can receive cuisine votes

Нельзя голосовать cuisine для:

```txt
draft
pending
hidden
rejected
deleted
```

Backend action обязан блокировать это даже если UI не показывает кнопку.

## 5.5. Guest cannot vote cuisine

Гость может смотреть feed/drawer, но не может голосовать за cuisine.

`VoteCuisineAction` должен принимать nullable user или `CuisineVoting` должен блокировать guest до action.

Лучше использовать тот же подход, что в Phase 13/14:

```php
handle(?User $user, Post $post, CuisineType $cuisine)
```

и explicit exception для guest.

## 5.6. Banned users

В исходном Phase 15 нет отдельной задачи `banned user cannot vote cuisine`.

Но если Phase 13 уже ввела `User::canVote()`, Phase 15 должна использовать это в `RG-313`.

Иначе получится дыра:

```txt
banned user не может up/down vote,
но может cuisine vote.
```

Это недопустимо.
---

# 6. Architecture Rules

## 6.1. VoteCuisineAction owns business logic

Нельзя писать cuisine voting прямо в Livewire:

```php
CuisineVote::updateOrCreate(...)
```

Правильно:

```php
app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
```

## 6.2. CuisineVoting Livewire component is UI wrapper

`CuisineVoting` отвечает за:

```txt
- отображение cuisine buttons;
- отображение active user cuisine vote;
- вызов VoteCuisineAction;
- loading state;
- dispatch cuisine-voted event;
- refresh local distribution.
```

Он не должен дублировать business logic action.

## 6.3. Distribution panel counts from cuisine_votes

Так как cuisine counters не лежат в `posts`, distribution нужно считать из таблицы `cuisine_votes`.

Допустимо в `CuisineVoting`:

```php
CuisineVote::query()
    ->where('post_id', $this->postId)
    ->selectRaw('cuisine, COUNT(*) as total')
    ->groupBy('cuisine')
    ->pluck('total', 'cuisine');
```

Для SQLite это нормально.

Не добавлять cache в Phase 15.

## 6.4. Drawer-only integration in this phase

Backlog Phase 15 содержит:

```txt
RG-316 — Render cuisine buttons in drawer
```

Он не содержит:

```txt
Render cuisine buttons in PostCard
Render cuisine buttons in PostShow
```

Поэтому Phase 15 не добавляет CuisineVoting в PostCard/PostShow.

Это правильное ограничение: cuisine voting UI слишком широкий для карточки, а PostShow integration можно сделать отдельной задачей позже.
---

# 7. Design Constraints

Cuisine voting UI должен быть компактным, но не тесным:

```txt
- кнопки cuisine в drawer;
- selected state obvious;
- distribution panel with bars or rows;
- dark cards;
- purple/accent selected state;
- safe mobile layout;
- no horizontal overflow.
```

Рекомендуемый UI:

```txt
- grid of cuisine buttons: 2 columns mobile, 3 columns desktop inside drawer;
- distribution list below buttons;
- each row: cuisine label, count, percentage, small progress bar.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-11-drawer-ui-review.md
docs/design/phase-14-origin-voting-review.md
```

Если предыдущий review doc отсутствует, не блокировать backend work, но зафиксировать missing prerequisite в PR notes.
---

# 8. GitFlow для Phase 15

## Base branch

Все задачи Phase 15 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-299-create-vote-cuisine-action-skeleton
feature/RG-311-implement-cuisine-vote-update
feature/RG-318-refresh-cuisine-counters-after-vote
```

## Commit format

```txt
RG-299: Create VoteCuisineAction skeleton
RG-311: Implement cuisine vote update
RG-318: Refresh cuisine counters after vote
```

## Release branch

После выполнения `RG-299`–`RG-318`:

```txt
release/v0.1.6-phase15-cuisine-voting
```

## Tag

После merge release branch в `main`:

```txt
v0.1.6-phase15-cuisine-voting
```
---

# 9. TDD Rules for Phase 15

## Для VoteCuisineAction

Каждое поведение test-first:

```txt
- user can vote Italian;
- user can vote Asian;
- user can vote American;
- user can vote Mexican;
- user can vote Other;
- cuisine vote can be changed;
- same cuisine vote is no-op;
- guest cannot vote;
- hidden/pending/rejected post cannot receive vote.
```

Даже если backlog не выделяет same-vote отдельной задачей, это должно быть покрыто в RG-311 как часть safe update behavior.

## Для distribution

Тестировать не через posts counters, а через `cuisine_votes` aggregates:

```txt
- count per cuisine;
- percentage per cuisine;
- zero-vote state;
- refresh after vote.
```

## Для Livewire

Тестировать:

```txt
- component renders;
- click calls action;
- vote row created/updated;
- selected cuisine visible;
- distribution refreshed.
```

## Для UI integration

Тестировать markers:

```txt
- drawer includes CuisineVoting;
- distribution panel renders;
- loading state exists.
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

# 11. Phase 15 Atomic Tasks
---

## RG-299 — Create VoteCuisineAction Skeleton

**Area:** Backend  
**Type:** Action  
**Priority:** P0  
**Branch:** `feature/RG-299-create-vote-cuisine-action-skeleton`  
**Base branch:** develop
**Depends on:** RG-298

### Goal

Создать skeleton action для cuisine voting.

### TDD step

Unit test:

```php
it('has vote cuisine action with handle method', function () {
    $action = app(VoteCuisineAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
```

Тест должен упасть до создания action.

### Implementation

Создать:

```txt
app/Actions/Votes/VoteCuisineAction.php
```

Skeleton:

```php
namespace App\Actions\Votes;

use App\Enums\CuisineType;
use App\Models\Post;
use App\Models\User;

final class VoteCuisineAction
{
    public function handle(?User $user, Post $post, CuisineType $cuisine): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
```

Сигнатура сразу nullable user, чтобы guest guard в RG-313 был чистым.

### Acceptance criteria

- `VoteCuisineAction` существует.
- Есть `handle(?User $user, Post $post, CuisineType $cuisine): void`.
- Action резолвится из container.
- Нет бизнес-логики кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Skeleton создан.
- Тест проходит.
- Коммит: `RG-299: Create VoteCuisineAction skeleton`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Unit/Actions/VoteCuisineActionSkeletonTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-300 — Test User Can Vote Italian Cuisine

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-300-test-user-can-vote-italian-cuisine`  
**Base branch:** develop
**Depends on:** RG-299

### Goal

Написать падающий тест: authenticated active user может проголосовать Italian за published post.

### TDD step

Feature/action test:

```php
it('allows user to vote italian cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);
});
```

Тест должен упасть до RG-301.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет row in `cuisine_votes`.
- Тест проверяет exactly one row.
- Тест не проверяет несуществующие cuisine counters на posts.
- Тест падает до реализации.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-300: Test user can vote Italian cuisine`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-301 — Implement Italian Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-301-implement-italian-vote`  
**Base branch:** develop
**Depends on:** RG-300

### Goal

Реализовать создание Italian cuisine vote.

### TDD step

Использовать падающий тест из RG-300.

### Implementation

В `VoteCuisineAction::handle()`:

```php
DB::transaction(function () use ($user, $post, $cuisine) {
    CuisineVote::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => $cuisine,
    ]);
});
```

Пока можно реализовать только создание vote.  
Guards будут в RG-313 и status guard рекомендуется добавить там же или до UI.

Но не разрешать `CuisineType::Unknown` silently, если это легко проверить уже сейчас.

### Acceptance criteria

- User can vote Italian.
- `cuisine_votes` row created.
- No post cuisine counter columns touched.
- Transaction used.
- Test RG-300 passes.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-301: Implement Italian vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-302 — Test User Can Vote Asian Cuisine

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-302-test-user-can-vote-asian-cuisine`  
**Base branch:** develop
**Depends on:** RG-301

### Goal

Написать падающий тест: authenticated active user может проголосовать Asian.

### TDD step

Feature/action test:

```php
it('allows user to vote asian cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Asian);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Asian->value,
    ]);
});
```

Тест должен упасть до RG-303, если реализация ограничена Italian.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет `CuisineType::Asian`.
- Тест падает до реализации, если generic creation ещё не готов.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-302: Test user can vote Asian cuisine`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-303 — Implement Asian Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-303-implement-asian-vote`  
**Base branch:** develop
**Depends on:** RG-302

### Goal

Реализовать создание Asian cuisine vote.

### TDD step

Использовать тест из RG-302.

### Implementation

Если RG-301 уже сделал generic creation для всех valid cuisines, изменений почти не нужно.  
Иначе расширить allowed cuisines:

```php
if (! in_array($cuisine, [
    CuisineType::Italian,
    CuisineType::Asian,
], true)) {
    // later
}
```

Лучше сразу использовать generic valid list:

```php
private function validCuisineValues(): array
{
    return [
        CuisineType::Italian,
        CuisineType::Asian,
        CuisineType::American,
        CuisineType::Mexican,
        CuisineType::Other,
    ];
}
```

Но не добавлять лишний complexity без тестов.

### Acceptance criteria

- User can vote Asian.
- Italian still works.
- No post cuisine counter columns touched.
- Tests pass.

### Definition of Done

- Реализация обновлена.
- Тесты проходят.
- Коммит: `RG-303: Implement Asian vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-304 — Test User Can Vote American Cuisine

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-304-test-user-can-vote-american-cuisine`  
**Base branch:** develop
**Depends on:** RG-303

### Goal

Написать тест: user может проголосовать American.

### TDD step

Feature/action test:

```php
it('allows user to vote american cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::American);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::American->value,
    ]);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Проверяет `CuisineType::American`.
- Тест падает до RG-305, если American ещё не разрешён.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-304: Test user can vote American cuisine`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-305 — Implement American Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-305-implement-american-vote`  
**Base branch:** develop
**Depends on:** RG-304

### Goal

Реализовать American cuisine vote.

### TDD step

Использовать тест из RG-304.

### Implementation

Расширить valid cuisines / generic creation.

### Acceptance criteria

- User can vote American.
- Italian/Asian still work.
- Tests pass.

### Definition of Done

- Реализация обновлена.
- Тесты проходят.
- Коммит: `RG-305: Implement American vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-306 — Test User Can Vote Mexican Cuisine

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-306-test-user-can-vote-mexican-cuisine`  
**Base branch:** develop
**Depends on:** RG-305

### Goal

Написать тест: user может проголосовать Mexican.

### TDD step

Feature/action test:

```php
it('allows user to vote mexican cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Mexican);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Mexican->value,
    ]);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Проверяет `CuisineType::Mexican`.
- Тест падает до RG-307, если Mexican ещё не разрешён.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-306: Test user can vote Mexican cuisine`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-307 — Implement Mexican Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-307-implement-mexican-vote`  
**Base branch:** develop
**Depends on:** RG-306

### Goal

Реализовать Mexican cuisine vote.

### TDD step

Использовать тест из RG-306.

### Implementation

Расширить valid cuisines / generic creation.

### Acceptance criteria

- User can vote Mexican.
- Previous cuisine votes still work.
- Tests pass.

### Definition of Done

- Реализация обновлена.
- Тесты проходят.
- Коммит: `RG-307: Implement Mexican vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-308 — Test User Can Vote Other Cuisine

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-308-test-user-can-vote-other-cuisine`  
**Base branch:** develop
**Depends on:** RG-307

### Goal

Написать тест: user может проголосовать Other.

### TDD step

Feature/action test:

```php
it('allows user to vote other cuisine on a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Other);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Other->value,
    ]);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Проверяет `CuisineType::Other`.
- Тест падает до RG-309, если Other ещё не разрешён.

### Definition of Done

- Тест добавлен.
- Коммит: `RG-308: Test user can vote Other cuisine`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-309 — Implement Other Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-309-implement-other-vote`  
**Base branch:** develop
**Depends on:** RG-308

### Goal

Реализовать Other cuisine vote и закрыть full valid cuisine list.

### TDD step

Использовать тест из RG-308.

### Implementation

Сделать окончательный allowed list:

```php
private function isValidVoteCuisine(CuisineType $cuisine): bool
{
    return in_array($cuisine, [
        CuisineType::Italian,
        CuisineType::Asian,
        CuisineType::American,
        CuisineType::Mexican,
        CuisineType::Other,
    ], true);
}
```

`CuisineType::Unknown` должен быть invalid.

Если exception ещё не создан, можно временно не тестировать Unknown до RG-313, но лучше сразу сделать explicit guard после добавления exception.

### Acceptance criteria

- User can vote Other.
- Italian/Asian/American/Mexican still work.
- Unknown is not treated as valid vote, если guard уже добавлен.
- Tests pass.

### Definition of Done

- Реализация обновлена.
- Тесты проходят.
- Коммит: `RG-309: Implement Other vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-310 — Test Cuisine Vote Can Be Changed

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-310-test-cuisine-vote-can-be-changed`  
**Base branch:** develop
**Depends on:** RG-309

### Goal

Написать тест: пользователь может изменить cuisine vote.

### TDD step

Feature/action test:

```php
it('allows user to change cuisine vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
    app(VoteCuisineAction::class)->handle($user, $post->fresh(), CuisineType::Asian);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Asian->value,
    ]);

    $this->assertDatabaseMissing('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);
});
```

Добавить same-cuisine no-op test здесь же:

```php
it('keeps same cuisine vote selected when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
    app(VoteCuisineAction::class)->handle($user, $post->fresh(), CuisineType::Italian);

    expect(CuisineVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);
});
```

### Implementation

Только добавить тесты.

### Acceptance criteria

- Тесты существуют.
- Cuisine vote can be changed.
- Same cuisine vote is no-op.
- There is exactly one row per user/post.
- Тесты падают до RG-311, если update/no-op ещё не реализованы.

### Definition of Done

- Тесты добавлены.
- Тесты ожидаемо падают или частично проходят.
- Коммит: `RG-310: Test cuisine vote can be changed`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-311 — Implement Cuisine Vote Update

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-311-implement-cuisine-vote-update`  
**Base branch:** develop
**Depends on:** RG-310

### Goal

Реализовать update existing cuisine vote и same-cuisine no-op.

### TDD step

Использовать тесты из RG-310.

### Implementation

В `VoteCuisineAction`:

```php
DB::transaction(function () use ($user, $post, $cuisine) {
    $existingVote = CuisineVote::query()
        ->where('post_id', $post->id)
        ->where('user_id', $user->id)
        ->first();

    if ($existingVote && $existingVote->cuisine === $cuisine) {
        return;
    }

    if ($existingVote) {
        $existingVote->update([
            'cuisine' => $cuisine,
        ]);

        return;
    }

    CuisineVote::create([
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => $cuisine,
    ]);
});
```

Не обновлять posts counters, потому что cuisine counters не существуют.

### Acceptance criteria

- New cuisine vote creates row.
- Changed cuisine vote updates existing row.
- Same cuisine vote no-op.
- Exactly one row per user/post.
- No posts cuisine counters invented.
- Tests pass.

### Definition of Done

- Update/no-op logic реализована.
- Тесты проходят.
- Коммит: `RG-311: Implement cuisine vote update`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-312 — Test Guest Cannot Vote Cuisine

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-312-test-guest-cannot-vote-cuisine`  
**Base branch:** develop
**Depends on:** RG-311

### Goal

Написать тест: guest не может голосовать за cuisine.

### TDD step

Feature/action test:

```php
it('does not allow guest to vote cuisine', function () {
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle(null, $post, CuisineType::Italian);
})->throws(CannotVoteCuisineException::class);
```

Проверить no side effects:

```php
expect(CuisineVote::query()->count())->toBe(0);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Guest/null user получает explicit exception.
- No cuisine vote row.
- Тест падает до RG-313.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-312: Test guest cannot vote cuisine`

### Files likely touched

```txt
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-313 — Add Auth Guard For Cuisine Vote

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-313-add-auth-guard-for-cuisine-vote`  
**Base branch:** develop
**Depends on:** RG-312

### Goal

Добавить auth/user/status guards для cuisine voting.

### TDD step

Использовать падающий тест из RG-312.

Добавить тест hidden post guard здесь же, потому что в Phase 15 нет отдельной задачи под hidden post, но backend без status guard будет дырявым:

```php
it('does not allow cuisine vote on hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Italian);
})->throws(CannotVoteCuisineException::class);
```

И тест Unknown:

```php
it('does not allow unknown cuisine vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VoteCuisineAction::class)->handle($user, $post, CuisineType::Unknown);
})->throws(CannotVoteCuisineException::class);
```

### Implementation

Создать exception:

```txt
app/Exceptions/Votes/CannotVoteCuisineException.php
```

Пример:

```php
final class CannotVoteCuisineException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on cuisine.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on cuisine.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive cuisine votes.');
    }

    public static function becauseCuisineIsInvalid(): self
    {
        return new self('Invalid cuisine vote.');
    }
}
```

В `VoteCuisineAction` перед transaction:

```php
if ($user === null) {
    throw CannotVoteCuisineException::becauseGuest();
}

if (method_exists($user, 'canVote') && ! $user->canVote()) {
    throw CannotVoteCuisineException::becauseUserIsNotAllowed();
}

if (! $post->canReceiveVotes()) {
    throw CannotVoteCuisineException::becausePostIsNotPublic();
}

if ($cuisine === CuisineType::Unknown) {
    throw CannotVoteCuisineException::becauseCuisineIsInvalid();
}
```

Если `Post::canReceiveVotes()` нет, использовать:

```php
$post->status === PostStatus::Published
```

Если `User::canVote()` нет, добавить или использовать active status.

### Acceptance criteria

- Guest blocked.
- Banned/non-voting user blocked, если canVote exists.
- Hidden/pending/rejected posts blocked.
- Unknown cuisine blocked.
- No side effects on blocked vote.
- Authenticated active user can still vote.
- Tests pass.

### Definition of Done

- Exception добавлен.
- Auth guard добавлен.
- User voting permission guard добавлен.
- Post status guard добавлен.
- Unknown cuisine guard добавлен.
- Тесты проходят.
- Коммит: `RG-313: Add auth guard for cuisine vote`

### Files likely touched

```txt
app/Actions/Votes/VoteCuisineAction.php
app/Exceptions/Votes/CannotVoteCuisineException.php
app/Models/User.php
app/Models/Post.php
tests/Feature/Actions/VoteCuisineActionTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-314 — Create CuisineVoting Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-314-create-cuisine-voting-livewire-component`  
**Base branch:** develop
**Depends on:** RG-313

### Goal

Создать Livewire component `CuisineVoting`.

### TDD step

Livewire test:

```php
it('can render cuisine voting component', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertStatus(200)
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other');
});
```

Тест должен упасть до создания component.

### Implementation

Создать:

```bash
php artisan make:livewire Posts/CuisineVoting
```

Файлы:

```txt
app/Livewire/Posts/CuisineVoting.php
resources/views/livewire/posts/cuisine-voting.blade.php
```

Class:

```php
final class CuisineVoting extends Component
{
    public int $postId;

    public function getPostProperty(): Post
    {
        return Post::query()->published()->findOrFail($this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.cuisine-voting', [
            'post' => $this->post,
            'options' => [
                CuisineType::Italian,
                CuisineType::Asian,
                CuisineType::American,
                CuisineType::Mexican,
                CuisineType::Other,
            ],
        ]);
    }
}
```

View skeleton:

```blade
<div data-testid="cuisine-voting">
    @foreach($options as $option)
        <button type="button">{{ $option->label() }}</button>
    @endforeach
</div>
```

Если enum не имеет `label()`, использовать match/helper в component.

### Acceptance criteria

- `CuisineVoting` component exists.
- Accepts `postId`.
- Renders five valid cuisine buttons.
- Does not render Unknown as a button.
- Loads only published post.
- Test passes.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-314: Create CuisineVoting Livewire component`

### Files likely touched

```txt
app/Livewire/Posts/CuisineVoting.php
resources/views/livewire/posts/cuisine-voting.blade.php
tests/Feature/Livewire/CuisineVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-315 — Test CuisineVoting Calls VoteCuisineAction

**Area:** Livewire / Tests  
**Type:** Test / Feature  
**Priority:** P0  
**Branch:** `feature/RG-315-test-cuisine-voting-calls-vote-cuisine-action`  
**Base branch:** develop
**Depends on:** RG-314

### Goal

Подключить CuisineVoting к VoteCuisineAction и протестировать click behavior.

### TDD step

Livewire tests:

```php
it('calls cuisine vote action when italian button is clicked', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value);

    $this->assertDatabaseHas('cuisine_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'cuisine' => CuisineType::Italian->value,
    ]);
});
```

Добавить один test для смены vote через component:

```php
->call('vote', CuisineType::Italian->value)
->call('vote', CuisineType::Mexican->value);
```

### Implementation

В `CuisineVoting`:

```php
public ?string $error = null;

public function vote(string $cuisine, VoteCuisineAction $voteCuisineAction): void
{
    $this->error = null;

    try {
        $cuisineType = CuisineType::from($cuisine);

        $voteCuisineAction->handle(auth()->user(), $this->post, $cuisineType);

        $this->dispatch('cuisine-voted', postId: $this->postId);
    } catch (CannotVoteCuisineException $e) {
        $this->error = $e->getMessage();
    }
}
```

View:

```blade
<button wire:click="vote('{{ $option->value }}')">
    {{ $label }}
</button>
```

Add loading disabled:

```blade
wire:loading.attr="disabled"
wire:target="vote"
```

### Acceptance criteria

- Cuisine buttons call action.
- Cuisine vote row created.
- Existing cuisine vote can update through component.
- `cuisine-voted` event dispatched.
- Error state can render if action blocks.
- Tests pass.

### Definition of Done

- Tests написаны.
- Component calls action.
- Tests проходят.
- Коммит: `RG-315: Test CuisineVoting calls VoteCuisineAction`

### Files likely touched

```txt
app/Livewire/Posts/CuisineVoting.php
resources/views/livewire/posts/cuisine-voting.blade.php
tests/Feature/Livewire/CuisineVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-316 — Render Cuisine Buttons In Drawer

**Area:** UI / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-316-render-cuisine-buttons-in-drawer`  
**Base branch:** develop
**Depends on:** RG-315

### Goal

Встроить CuisineVoting в PostDrawer.

### TDD step

Livewire test:

```php
it('renders cuisine voting buttons in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-cuisine-voting"', false)
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other');
});
```

### Implementation

В `post-drawer.blade.php`:

```blade
<section data-testid="post-drawer-cuisine-voting">
    <h3>What cuisine is it?</h3>

    <livewire:posts.cuisine-voting
        :post-id="$post->id"
        :key="'post-drawer-cuisine-voting-'.$post->id"
    />
</section>
```

Не добавлять CuisineVoting в PostCard/PostShow, потому что это не входит в Phase 15.

### Acceptance criteria

- Drawer includes CuisineVoting.
- Five cuisine buttons visible in drawer.
- Unknown is not visible as option.
- Component key stable by post id.
- No duplicated action logic.
- Test passes.

### Definition of Done

- Test написан.
- CuisineVoting встроен в drawer.
- Коммит: `RG-316: Render cuisine buttons in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-317 — Render Cuisine Distribution Panel

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-317-render-cuisine-distribution-panel`  
**Base branch:** develop
**Depends on:** RG-315

### Goal

Добавить cuisine distribution panel.

### TDD step

Livewire test:

```php
it('renders cuisine distribution panel', function () {
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

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="cuisine-distribution-panel"', false)
        ->assertSee('Italian')
        ->assertSee('2')
        ->assertSee('67%')
        ->assertSee('Asian')
        ->assertSee('1')
        ->assertSee('33%');
});
```

Zero votes test:

```php
it('renders zero cuisine distribution safely', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('data-testid="cuisine-distribution-panel"', false)
        ->assertSee('No cuisine votes yet');
});
```

### Implementation

В `CuisineVoting` добавить метод:

```php
public function distribution(): array
{
    $counts = CuisineVote::query()
        ->where('post_id', $this->postId)
        ->selectRaw('cuisine, COUNT(*) as total')
        ->groupBy('cuisine')
        ->pluck('total', 'cuisine');

    $options = [
        CuisineType::Italian,
        CuisineType::Asian,
        CuisineType::American,
        CuisineType::Mexican,
        CuisineType::Other,
    ];

    $total = $counts->sum();

    return collect($options)
        ->map(function (CuisineType $cuisine) use ($counts, $total) {
            $count = (int) ($counts[$cuisine->value] ?? 0);

            return [
                'cuisine' => $cuisine,
                'label' => $this->labelFor($cuisine),
                'count' => $count,
                'percentage' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
            ];
        })
        ->all();
}
```

В view:

```blade
<div data-testid="cuisine-distribution-panel">
    @if($total === 0)
        <x-ui.empty-state title="No cuisine votes yet" />
    @else
        @foreach($distribution as $row)
            <div>
                <span>{{ $row['label'] }}</span>
                <span>{{ $row['count'] }}</span>
                <span>{{ $row['percentage'] }}%</span>
                <div class="...">
                    <div style="width: {{ $row['percentage'] }}%"></div>
                </div>
            </div>
        @endforeach
    @endif
</div>
```

Не использовать posts counters.

### Acceptance criteria

- Distribution panel renders.
- Counts are calculated from `cuisine_votes`.
- Percentages are calculated safely.
- Zero-vote state safe.
- Unknown cuisine not shown.
- No division by zero.
- Tests pass.

### Definition of Done

- Tests написаны.
- Distribution panel добавлен.
- Tests проходят.
- Коммит: `RG-317: Render cuisine distribution panel`

### Files likely touched

```txt
app/Livewire/Posts/CuisineVoting.php
resources/views/livewire/posts/cuisine-voting.blade.php
tests/Feature/Livewire/CuisineVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-318 — Refresh Cuisine Counters After Vote

**Area:** Livewire / Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-318-refresh-cuisine-counters-after-vote`  
**Base branch:** develop
**Depends on:** RG-317

### Goal

После cuisine vote обновлять distribution panel.

Название задачи говорит `counters`, но в текущей схеме это не persisted post counters.  
В Phase 15 под counters понимаются агрегированные counts из `cuisine_votes`.

### TDD step

Livewire test:

```php
it('refreshes cuisine distribution after vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->assertSee('No cuisine votes yet')
        ->call('vote', CuisineType::Italian->value)
        ->assertSee('Italian')
        ->assertSee('1')
        ->assertSee('100%');
});
```

Change vote refresh test:

```php
it('refreshes cuisine distribution after vote change', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['postId' => $post->id])
        ->call('vote', CuisineType::Italian->value)
        ->call('vote', CuisineType::Mexican->value)
        ->assertSee('Mexican')
        ->assertSee('100%');
});
```

Чтобы избежать хрупкости из-за повторяющихся процентов, лучше assert database plus visible label/count.

### Implementation

В `CuisineVoting` после action:

```php
$this->dispatch('cuisine-voted', postId: $this->postId);
```

Distribution должен считаться fresh на render.  
Если Livewire computed property кеширует distribution, сбросить после vote:

```php
unset($this->distribution);
```

или не использовать cached computed property.

Добавить listener, если нужен:

```php
#[On('cuisine-voted')]
public function refreshAfterCuisineVote(): void
{
    // no-op, forces render
}
```

В view loading state:

```blade
<button
    wire:loading.attr="disabled"
    wire:target="vote"
>
```

### Acceptance criteria

- New cuisine vote updates distribution.
- Changed cuisine vote updates distribution.
- Same cuisine vote leaves distribution stable.
- `cuisine-voted` event dispatched.
- Loading state exists.
- No post cuisine counter columns added.
- All CuisineVoting and VoteCuisineAction tests pass.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Distribution refresh реализован.
- Tests pass.
- Build passes.
- Коммит: `RG-318: Refresh cuisine counters after vote`

### Files likely touched

```txt
app/Livewire/Posts/CuisineVoting.php
resources/views/livewire/posts/cuisine-voting.blade.php
tests/Feature/Livewire/CuisineVotingTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 12. Phase 15 Completion Criteria

Phase 15 завершена, когда:

```txt
- RG-299–RG-318 выполнены;
- VoteCuisineAction существует;
- user can vote Italian;
- user can vote Asian;
- user can vote American;
- user can vote Mexican;
- user can vote Other;
- cuisine vote can be changed;
- same cuisine vote is no-op;
- guest cannot vote cuisine;
- banned/non-voting user cannot vote cuisine, если User::canVote() есть;
- hidden/pending/rejected posts cannot receive cuisine votes;
- Unknown cuisine cannot be used as user vote;
- action uses transaction;
- CuisineVoting Livewire component exists;
- CuisineVoting calls VoteCuisineAction;
- cuisine buttons render in drawer;
- cuisine distribution panel renders;
- distribution counts are calculated from cuisine_votes;
- distribution refreshes after vote;
- no new post cuisine counter columns are added;
- composer test passes;
- npm run build passes.
```
---

# 13. Что нельзя делать в Phase 15

Без отдельной задачи нельзя:

```txt
- добавлять italian_votes_count/asian_votes_count/etc в posts;
- делать RecalculatePostCountersAction;
- добавлять CuisineVoting в PostCard;
- добавлять CuisineVoting в PostShow;
- делать comments;
- делать reports;
- делать moderation controls;
- делать analytics;
- делать anti-fraud;
- делать rate limiting;
- делать notifications;
- пересчитывать hot_score;
- менять FeedQuery ranking;
- добавлять Redis/cache layer;
- делать API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 14. Recommended Execution Order

```txt
RG-299 Create VoteCuisineAction skeleton
RG-300 Test user can vote Italian cuisine
RG-301 Implement Italian vote
RG-302 Test user can vote Asian cuisine
RG-303 Implement Asian vote
RG-304 Test user can vote American cuisine
RG-305 Implement American vote
RG-306 Test user can vote Mexican cuisine
RG-307 Implement Mexican vote
RG-308 Test user can vote Other cuisine
RG-309 Implement Other vote
RG-310 Test cuisine vote can be changed
RG-311 Implement cuisine vote update
RG-312 Test guest cannot vote cuisine
RG-313 Add auth guard for cuisine vote
RG-314 Create CuisineVoting Livewire component
RG-315 Test CuisineVoting calls VoteCuisineAction
RG-316 Render cuisine buttons in drawer
RG-317 Render cuisine distribution panel
RG-318 Refresh cuisine counters after vote
```
---

# 15. Release

После завершения Phase 15:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.6-phase15-cuisine-voting
git push -u origin release/v0.1.6-phase15-cuisine-voting
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.6-phase15-cuisine-voting -m "RateGuru Phase 15 cuisine voting"
git push origin v0.1.6-phase15-cuisine-voting
```
