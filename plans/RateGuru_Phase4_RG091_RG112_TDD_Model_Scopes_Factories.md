# RateGuru — Phase 4 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 4 — Model Scopes & Factories**  
Диапазон задач: **RG-091 → RG-112**  
Основа нумерации: исходный atomic backlog, где Phase 4 начинается с задачи 091 и заканчивается задачей 112.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 4 соответствует исходному блоку:

```txt
Phase 4 — Model Scopes & Factories
```

Правильный диапазон Phase 4:

```txt
RG-091 — Add PostFactory
RG-092 — Add CommentFactory
RG-093 — Add PostVoteFactory
RG-094 — Add OriginVoteFactory
RG-095 — Add CuisineVoteFactory
RG-096 — Add ReportFactory
RG-097 — Add TagFactory
RG-098 — Add Post published factory state
RG-099 — Add Post pending factory state
RG-100 — Add Post hidden factory state
RG-101 — Add Post rejected factory state
RG-102 — Add published scope to Post model
RG-103 — Add pending scope to Post model
RG-104 — Add hidden scope to Post model
RG-105 — Add reported scope to Post model
RG-106 — Add recent scope to Post model
RG-107 — Add hot scope placeholder to Post model
RG-108 — Test Post published scope
RG-109 — Test Post pending scope
RG-110 — Test Post user relationship
RG-111 — Test Post comments relationship
RG-112 — Test Post tags relationship
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 4

Phase 4 делает модели удобными для дальнейшей разработки через factories и базовые scopes.

После Phase 4 должно быть готово:

```txt
- factories для Post, Comment, PostVote, OriginVote, CuisineVote, Report, Tag;
- factory states для Post: published, pending, hidden, rejected;
- scopes на Post: published, pending, hidden, reported, recent, hot;
- relationship-тесты через factories;
- тестовая база может быстро создавать реалистичные сущности для будущих Actions/UI/Admin задач.
```

Phase 4 — это не бизнес-логика.  
Она создаёт тестовую и модельную инфраструктуру для следующих фаз.

---

# 3. Scope Phase 4

## Входит

```txt
- model factories;
- factory states;
- basic Post scopes;
- tests for factories;
- tests for scopes;
- relationship tests using factories.
```

## Не входит

```txt
- CreatePostAction;
- FeedQuery;
- upload;
- image storage;
- voting actions;
- comments actions;
- reports actions;
- moderation actions;
- Filament resources;
- Livewire feed;
- real UI;
- seeders;
- API resources.
```

Seeders будут позже, в отдельной фазе.  
FeedQuery начнётся в Phase 7.  
Post creation backend начнётся в Phase 5.

---

# 4. Design Decisions

## 4.1. Factories должны быть реалистичными, но не умными

Factory должна создавать валидную запись с минимальными зависимостями.

Правильно:

```php
Post::factory()->create();
Comment::factory()->create();
PostVote::factory()->create();
```

Неправильно:

```php
PostFactory автоматически создаёт 20 комментариев, голоса, теги, отчёты и пересчитывает score.
```

Большие сценарии должны быть отдельными states/seeders позже.

## 4.2. Factory states не должны запускать бизнес-логику

State `published()` просто выставляет:

```txt
status = published
published_at = now()
```

Он не должен вызывать:

```txt
PublishPostAction
notifications
moderation logs
score recalculation
```

Это будет позже.

## 4.3. Scopes должны быть маленькими

Scope — только query helper:

```php
Post::published()
Post::pending()
Post::reported()
Post::recent()
Post::hot()
```

Scope не должен менять данные.

## 4.4. Relationship tests в Phase 4 не дублируют Phase 3

В Phase 3 relationship tests могли использовать ручное создание записей.  
В Phase 4 `RG-110`–`RG-112` должны проверить те же связи уже через factories. Это важно: factories должны создавать корректные модели, пригодные для дальнейшего TDD.

---

# 5. GitFlow для Phase 4

## Base branch

Все задачи Phase 4 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-091-add-post-factory
feature/RG-102-add-published-scope-to-post-model
feature/RG-110-test-post-user-relationship
```

## Commit format

```txt
RG-091: Add PostFactory
RG-102: Add published scope to Post model
RG-110: Test Post user relationship
```

## Release branch

После выполнения `RG-091`–`RG-112`:

```txt
release/v0.0.5-phase4-model-scopes-and-factories
```

## Tag

После merge release branch в `main`:

```txt
v0.0.5-phase4-model-scopes-and-factories
```

---

# 6. TDD Rules for Phase 4

## Для factory задач

Сначала feature/unit test:

```txt
- factory creates model;
- created record exists in database;
- enum casts work;
- required relationships exist.
```

## Для factory state задач

Сначала test:

```txt
- state sets expected status;
- state sets expected timestamp/counter/default.
```

## Для scope задач

Сначала test, который создаёт несколько post states и проверяет фильтрацию.

## Для relationship задач

Сначала test через factory-created records.

---

# 7. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Tests
Type: Test / Feature / Factory / Scope
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
- Нет бизнес-логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 8. Phase 4 Atomic Tasks

---

## RG-091 — Add PostFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-091-add-post-factory`  
**Depends on:** RG-074

### Goal

Добавить factory для `Post`.

### TDD step

Сначала feature test:

```php
it('can create a post with factory', function () {
    $post = Post::factory()->create();

    expect($post)->toBeInstanceOf(Post::class);
    expect($post->exists)->toBeTrue();
    expect($post->user)->toBeInstanceOf(User::class);
    expect($post->status)->toBe(PostStatus::Pending);
});
```

Тест должен упасть до создания factory.

### Implementation

Создать factory:

```bash
php artisan make:factory PostFactory --model=Post
```

Минимальное состояние:

```php
return [
    'user_id' => User::factory(),
    'title' => fake()->sentence(4),
    'description' => fake()->optional()->paragraph(),
    'image_path' => null,
    'image_url' => null,
    'thumbnail_url' => null,
    'source_url' => null,

    'status' => PostStatus::Pending,
    'origin_truth' => OriginType::Unknown,
    'cuisine_truth' => CuisineType::Unknown,

    'upvotes_count' => 0,
    'downvotes_count' => 0,
    'homemade_votes_count' => 0,
    'restaurant_votes_count' => 0,
    'comments_count' => 0,
    'reports_count' => 0,
    'hot_score' => 0,

    'published_at' => null,
];
```

### Acceptance criteria

- `PostFactory` существует.
- `Post::factory()->create()` работает.
- Factory создаёт связанного user.
- Default status = pending.
- Counters = 0.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Тест проходит.
- Коммит: `RG-091: Add PostFactory`

### Files likely touched

```txt
database/factories/PostFactory.php
tests/Feature/Factories/PostFactoryTest.php
```

---

## RG-092 — Add CommentFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-092-add-comment-factory`  
**Depends on:** RG-075, RG-091

### Goal

Добавить factory для `Comment`.

### TDD step

Feature test:

```php
it('can create a comment with factory', function () {
    $comment = Comment::factory()->create();

    expect($comment)->toBeInstanceOf(Comment::class);
    expect($comment->exists)->toBeTrue();
    expect($comment->post)->toBeInstanceOf(Post::class);
    expect($comment->user)->toBeInstanceOf(User::class);
});
```

### Implementation

Создать factory:

```bash
php artisan make:factory CommentFactory --model=Comment
```

Состояние:

```php
return [
    'post_id' => Post::factory(),
    'user_id' => User::factory(),
    'body' => fake()->sentence(12),
    'status' => 'published',
    'reports_count' => 0,
];
```

### Acceptance criteria

- `CommentFactory` существует.
- `Comment::factory()->create()` работает.
- Factory создаёт связанный post.
- Factory создаёт связанного user.
- Default status = published.
- reports_count = 0.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Тест проходит.
- Коммит: `RG-092: Add CommentFactory`

### Files likely touched

```txt
database/factories/CommentFactory.php
tests/Feature/Factories/CommentFactoryTest.php
```

---

## RG-093 — Add PostVoteFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-093-add-post-vote-factory`  
**Depends on:** RG-076, RG-091

### Goal

Добавить factory для `PostVote`.

### TDD step

Feature test:

```php
it('can create a post vote with factory', function () {
    $vote = PostVote::factory()->create();

    expect($vote)->toBeInstanceOf(PostVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->type)->toBeInstanceOf(VoteType::class);
});
```

### Implementation

Создать factory:

```bash
php artisan make:factory PostVoteFactory --model=PostVote
```

Состояние:

```php
return [
    'post_id' => Post::factory(),
    'user_id' => User::factory(),
    'type' => fake()->randomElement([
        VoteType::Up,
        VoteType::Down,
    ]),
];
```

Важно: unique index `post_id/user_id` уже существует.  
Factory не должна создавать дубликаты для одного и того же post/user, если не указано явно.

### Acceptance criteria

- `PostVoteFactory` существует.
- `PostVote::factory()->create()` работает.
- `type` кастится в `VoteType`.
- Создаются связанные post/user.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Тест проходит.
- Коммит: `RG-093: Add PostVoteFactory`

### Files likely touched

```txt
database/factories/PostVoteFactory.php
tests/Feature/Factories/PostVoteFactoryTest.php
```

---

## RG-094 — Add OriginVoteFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-094-add-origin-vote-factory`  
**Depends on:** RG-077, RG-091

### Goal

Добавить factory для `OriginVote`.

### TDD step

Feature test:

```php
it('can create an origin vote with factory', function () {
    $vote = OriginVote::factory()->create();

    expect($vote)->toBeInstanceOf(OriginVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->origin)->toBeInstanceOf(OriginType::class);
});
```

### Implementation

Создать factory:

```bash
php artisan make:factory OriginVoteFactory --model=OriginVote
```

Состояние:

```php
return [
    'post_id' => Post::factory(),
    'user_id' => User::factory(),
    'origin' => fake()->randomElement([
        OriginType::Homemade,
        OriginType::Restaurant,
    ]),
];
```

Не использовать `OriginType::Unknown` для голоса пользователя. Unknown подходит для truth, но не для vote.

### Acceptance criteria

- `OriginVoteFactory` существует.
- `OriginVote::factory()->create()` работает.
- `origin` кастится в `OriginType`.
- Origin vote не создаётся с `unknown` по умолчанию.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Тест проходит.
- Коммит: `RG-094: Add OriginVoteFactory`

### Files likely touched

```txt
database/factories/OriginVoteFactory.php
tests/Feature/Factories/OriginVoteFactoryTest.php
```

---

## RG-095 — Add CuisineVoteFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-095-add-cuisine-vote-factory`  
**Depends on:** RG-078, RG-091

### Goal

Добавить factory для `CuisineVote`.

### TDD step

Feature test:

```php
it('can create a cuisine vote with factory', function () {
    $vote = CuisineVote::factory()->create();

    expect($vote)->toBeInstanceOf(CuisineVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->cuisine)->toBeInstanceOf(CuisineType::class);
});
```

### Implementation

Создать factory:

```bash
php artisan make:factory CuisineVoteFactory --model=CuisineVote
```

Состояние:

```php
return [
    'post_id' => Post::factory(),
    'user_id' => User::factory(),
    'cuisine' => fake()->randomElement([
        CuisineType::Italian,
        CuisineType::Asian,
        CuisineType::American,
        CuisineType::Mexican,
        CuisineType::Other,
    ]),
];
```

Не использовать `CuisineType::Unknown` для пользовательского vote.

### Acceptance criteria

- `CuisineVoteFactory` существует.
- `CuisineVote::factory()->create()` работает.
- `cuisine` кастится в `CuisineType`.
- Cuisine vote не создаётся с `unknown` по умолчанию.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Тест проходит.
- Коммит: `RG-095: Add CuisineVoteFactory`

### Files likely touched

```txt
database/factories/CuisineVoteFactory.php
tests/Feature/Factories/CuisineVoteFactoryTest.php
```

---

## RG-096 — Add ReportFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-096-add-report-factory`  
**Depends on:** RG-080, RG-091

### Goal

Добавить factory для `Report`.

### TDD step

Feature test:

```php
it('can create a report with factory', function () {
    $report = Report::factory()->forPost()->create();

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->exists)->toBeTrue();
    expect($report->reason)->toBeInstanceOf(ReportReason::class);
    expect($report->target_type)->toBe(Post::class);
});
```

### Implementation

Создать factory:

```bash
php artisan make:factory ReportFactory --model=Report
```

Базовое состояние:

```php
return [
    'reporter_id' => User::factory(),
    'target_type' => Post::class,
    'target_id' => Post::factory(),
    'reason' => fake()->randomElement(ReportReason::cases()),
    'message' => fake()->optional()->sentence(),
    'status' => 'open',
    'resolved_by' => null,
    'resolved_at' => null,
];
```

Если `target_id => Post::factory()` не работает корректно с текущей версией Laravel, сделать state `forPost()` через `afterMaking`/`afterCreating`.

Рекомендуемые states:

```php
public function forPost(): static
public function forComment(): static
public function forUser(): static
public function resolved(): static
```

Но если states делают задачу слишком большой, обязательно реализовать только `forPost()` и `resolved()`; остальные можно оставить будущим задачам. Минимальный acceptance — report на post.

### Acceptance criteria

- `ReportFactory` существует.
- `Report::factory()->forPost()->create()` работает.
- Report имеет reporter.
- Report имеет target_type/target_id.
- `reason` кастится в `ReportReason`.
- Default status = open.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Минимальный `forPost()` state работает.
- Тест проходит.
- Коммит: `RG-096: Add ReportFactory`

### Files likely touched

```txt
database/factories/ReportFactory.php
tests/Feature/Factories/ReportFactoryTest.php
```

---

## RG-097 — Add TagFactory

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-097-add-tag-factory`  
**Depends on:** RG-079

### Goal

Добавить factory для `Tag`.

### TDD step

Feature test:

```php
it('can create a tag with factory', function () {
    $tag = Tag::factory()->create();

    expect($tag)->toBeInstanceOf(Tag::class);
    expect($tag->exists)->toBeTrue();
    expect($tag->slug)->not->toBeEmpty();
});
```

### Implementation

Создать factory:

```bash
php artisan make:factory TagFactory --model=Tag
```

Состояние:

```php
$name = fake()->unique()->word();

return [
    'name' => ucfirst($name),
    'slug' => Str::slug($name),
];
```

Если `unique()->word()` создаёт коллизии, использовать sentence/randomNumber suffix.

### Acceptance criteria

- `TagFactory` существует.
- `Tag::factory()->create()` работает.
- `name` заполнен.
- `slug` заполнен и уникален.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Factory создана.
- Тест проходит.
- Коммит: `RG-097: Add TagFactory`

### Files likely touched

```txt
database/factories/TagFactory.php
tests/Feature/Factories/TagFactoryTest.php
```

---

## RG-098 — Add Post Published Factory State

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-098-add-post-published-factory-state`  
**Depends on:** RG-091

### Goal

Добавить `published()` state в `PostFactory`.

### TDD step

Feature test:

```php
it('can create a published post', function () {
    $post = Post::factory()->published()->create();

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});
```

### Implementation

В `PostFactory` добавить:

```php
public function published(): static
{
    return $this->state(fn () => [
        'status' => PostStatus::Published,
        'published_at' => now(),
    ]);
}
```

### Acceptance criteria

- `Post::factory()->published()` работает.
- status = published.
- published_at заполнен.
- State не вызывает бизнес-логику.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- State добавлен.
- Тест проходит.
- Коммит: `RG-098: Add Post published factory state`

### Files likely touched

```txt
database/factories/PostFactory.php
tests/Feature/Factories/PostFactoryTest.php
```

---

## RG-099 — Add Post Pending Factory State

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-099-add-post-pending-factory-state`  
**Depends on:** RG-091

### Goal

Добавить явный `pending()` state в `PostFactory`.

Даже если pending уже default, explicit state нужен для читаемости тестов.

### TDD step

Feature test:

```php
it('can create a pending post', function () {
    $post = Post::factory()->pending()->create();

    expect($post->status)->toBe(PostStatus::Pending);
    expect($post->published_at)->toBeNull();
});
```

### Implementation

В `PostFactory` добавить:

```php
public function pending(): static
{
    return $this->state(fn () => [
        'status' => PostStatus::Pending,
        'published_at' => null,
    ]);
}
```

### Acceptance criteria

- `Post::factory()->pending()` работает.
- status = pending.
- published_at = null.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- State добавлен.
- Тест проходит.
- Коммит: `RG-099: Add Post pending factory state`

### Files likely touched

```txt
database/factories/PostFactory.php
tests/Feature/Factories/PostFactoryTest.php
```

---

## RG-100 — Add Post Hidden Factory State

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-100-add-post-hidden-factory-state`  
**Depends on:** RG-091

### Goal

Добавить `hidden()` state в `PostFactory`.

### TDD step

Feature test:

```php
it('can create a hidden post', function () {
    $post = Post::factory()->hidden()->create();

    expect($post->status)->toBe(PostStatus::Hidden);
});
```

### Implementation

В `PostFactory` добавить:

```php
public function hidden(): static
{
    return $this->state(fn () => [
        'status' => PostStatus::Hidden,
        'published_at' => now(),
    ]);
}
```

Почему `published_at` можно оставить заполненным: hidden post чаще всего был опубликован, потом скрыт.  
Но не добавлять hidden_at — такой колонки нет.

### Acceptance criteria

- `Post::factory()->hidden()` работает.
- status = hidden.
- State не создаёт moderation log.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- State добавлен.
- Тест проходит.
- Коммит: `RG-100: Add Post hidden factory state`

### Files likely touched

```txt
database/factories/PostFactory.php
tests/Feature/Factories/PostFactoryTest.php
```

---

## RG-101 — Add Post Rejected Factory State

**Area:** Backend / Tests  
**Type:** Factory  
**Priority:** P0  
**Branch:** `feature/RG-101-add-post-rejected-factory-state`  
**Depends on:** RG-091

### Goal

Добавить `rejected()` state в `PostFactory`.

### TDD step

Feature test:

```php
it('can create a rejected post', function () {
    $post = Post::factory()->rejected()->create();

    expect($post->status)->toBe(PostStatus::Rejected);
    expect($post->published_at)->toBeNull();
});
```

### Implementation

В `PostFactory` добавить:

```php
public function rejected(): static
{
    return $this->state(fn () => [
        'status' => PostStatus::Rejected,
        'published_at' => null,
    ]);
}
```

### Acceptance criteria

- `Post::factory()->rejected()` работает.
- status = rejected.
- published_at = null.
- State не создаёт moderation log.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- State добавлен.
- Тест проходит.
- Коммит: `RG-101: Add Post rejected factory state`

### Files likely touched

```txt
database/factories/PostFactory.php
tests/Feature/Factories/PostFactoryTest.php
```

---

## RG-102 — Add Published Scope To Post Model

**Area:** Backend / Tests  
**Type:** Scope  
**Priority:** P0  
**Branch:** `feature/RG-102-add-published-scope-to-post-model`  
**Depends on:** RG-098

### Goal

Добавить query scope `Post::published()`.

### TDD step

Feature test:

```php
it('filters published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();

    $results = Post::published()->get();

    expect($results->pluck('id'))->toContain($published->id);
    expect($results)->toHaveCount(1);
});
```

### Implementation

В `Post` model добавить:

```php
public function scopePublished(Builder $query): Builder
{
    return $query->where('status', PostStatus::Published);
}
```

### Acceptance criteria

- `Post::published()` работает.
- Возвращает только published posts.
- Pending/hidden/rejected не попадают.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Scope добавлен.
- Тест проходит.
- Коммит: `RG-102: Add published scope to Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Scopes/PostPublishedScopeTest.php
```

---

## RG-103 — Add Pending Scope To Post Model

**Area:** Backend / Tests  
**Type:** Scope  
**Priority:** P0  
**Branch:** `feature/RG-103-add-pending-scope-to-post-model`  
**Depends on:** RG-099

### Goal

Добавить query scope `Post::pending()`.

### TDD step

Feature test:

```php
it('filters pending posts', function () {
    $pending = Post::factory()->pending()->create();
    Post::factory()->published()->create();
    Post::factory()->hidden()->create();

    $results = Post::pending()->get();

    expect($results->pluck('id'))->toContain($pending->id);
    expect($results)->toHaveCount(1);
});
```

### Implementation

В `Post` model добавить:

```php
public function scopePending(Builder $query): Builder
{
    return $query->where('status', PostStatus::Pending);
}
```

### Acceptance criteria

- `Post::pending()` работает.
- Возвращает только pending posts.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Scope добавлен.
- Тест проходит.
- Коммит: `RG-103: Add pending scope to Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Scopes/PostPendingScopeTest.php
```

---

## RG-104 — Add Hidden Scope To Post Model

**Area:** Backend / Tests  
**Type:** Scope  
**Priority:** P0  
**Branch:** `feature/RG-104-add-hidden-scope-to-post-model`  
**Depends on:** RG-100

### Goal

Добавить query scope `Post::hidden()`.

### TDD step

Feature test:

```php
it('filters hidden posts', function () {
    $hidden = Post::factory()->hidden()->create();
    Post::factory()->published()->create();
    Post::factory()->pending()->create();

    $results = Post::hidden()->get();

    expect($results->pluck('id'))->toContain($hidden->id);
    expect($results)->toHaveCount(1);
});
```

### Implementation

В `Post` model добавить:

```php
public function scopeHidden(Builder $query): Builder
{
    return $query->where('status', PostStatus::Hidden);
}
```

### Acceptance criteria

- `Post::hidden()` работает.
- Возвращает только hidden posts.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Scope добавлен.
- Тест проходит.
- Коммит: `RG-104: Add hidden scope to Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Scopes/PostHiddenScopeTest.php
```

---

## RG-105 — Add Reported Scope To Post Model

**Area:** Backend / Tests  
**Type:** Scope  
**Priority:** P0  
**Branch:** `feature/RG-105-add-reported-scope-to-post-model`  
**Depends on:** RG-091

### Goal

Добавить query scope `Post::reported()`.

### TDD step

Feature test:

```php
it('filters reported posts', function () {
    $reported = Post::factory()->create(['reports_count' => 3]);
    Post::factory()->create(['reports_count' => 0]);

    $results = Post::reported()->get();

    expect($results->pluck('id'))->toContain($reported->id);
    expect($results)->toHaveCount(1);
});
```

### Implementation

В `Post` model добавить:

```php
public function scopeReported(Builder $query): Builder
{
    return $query->where('reports_count', '>', 0);
}
```

Не hardcode threshold 3 в scope.  
`reported()` значит “есть хотя бы одна жалоба”. Threshold для moderation review будет позже.

### Acceptance criteria

- `Post::reported()` работает.
- Возвращает posts с reports_count > 0.
- Posts с reports_count = 0 не попадают.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Scope добавлен.
- Тест проходит.
- Коммит: `RG-105: Add reported scope to Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Scopes/PostReportedScopeTest.php
```

---

## RG-106 — Add Recent Scope To Post Model

**Area:** Backend / Tests  
**Type:** Scope  
**Priority:** P0  
**Branch:** `feature/RG-106-add-recent-scope-to-post-model`  
**Depends on:** RG-091

### Goal

Добавить query scope `Post::recent()`.

### TDD step

Feature test:

```php
it('orders posts by most recent first', function () {
    $old = Post::factory()->create(['created_at' => now()->subDay()]);
    $new = Post::factory()->create(['created_at' => now()]);

    $results = Post::recent()->get();

    expect($results->first()->id)->toBe($new->id);
    expect($results->last()->id)->toBe($old->id);
});
```

### Implementation

В `Post` model добавить:

```php
public function scopeRecent(Builder $query): Builder
{
    return $query->orderByDesc('created_at');
}
```

Не использовать `published_at` здесь.  
`recent()` — общий scope по created_at. Feed-specific newest sorting будет позже в FeedQuery.

### Acceptance criteria

- `Post::recent()` работает.
- Сортирует по `created_at desc`.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Scope добавлен.
- Тест проходит.
- Коммит: `RG-106: Add recent scope to Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Scopes/PostRecentScopeTest.php
```

---

## RG-107 — Add Hot Scope Placeholder To Post Model

**Area:** Backend / Tests  
**Type:** Scope  
**Priority:** P0  
**Branch:** `feature/RG-107-add-hot-scope-placeholder-to-post-model`  
**Depends on:** RG-091

### Goal

Добавить query scope `Post::hot()` как placeholder сортировки по `hot_score`.

Реальная формула hot score будет позже в Phase 33.  
Сейчас scope только сортирует по уже сохранённому `hot_score`.

### TDD step

Feature test:

```php
it('orders posts by hot score descending', function () {
    $cold = Post::factory()->create(['hot_score' => 1]);
    $hot = Post::factory()->create(['hot_score' => 10]);

    $results = Post::hot()->get();

    expect($results->first()->id)->toBe($hot->id);
    expect($results->last()->id)->toBe($cold->id);
});
```

### Implementation

В `Post` model добавить:

```php
public function scopeHot(Builder $query): Builder
{
    return $query->orderByDesc('hot_score');
}
```

Опционально можно добавить secondary sort:

```php
->orderByDesc('created_at')
```

Но только если тест это фиксирует.

### Acceptance criteria

- `Post::hot()` работает.
- Сортирует по `hot_score desc`.
- Не вычисляет score.
- Не вызывает сервисы.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Scope добавлен.
- Тест проходит.
- Коммит: `RG-107: Add hot scope placeholder to Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Scopes/PostHotScopeTest.php
```

---

## RG-108 — Test Post Published Scope

**Area:** Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-108-test-post-published-scope`  
**Depends on:** RG-102

### Goal

Добавить отдельный/финальный тест для `Post::published()` через factory states.

Эта задача может выглядеть дублирующей `RG-102`, но её смысл — закрепить scope тест в отдельном файле/наборе как часть Phase 4 acceptance.

### TDD step

Тест уже должен быть красным до `RG-102`, но теперь он должен быть оформлен как постоянный regression test.

```php
it('only returns published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    expect(Post::published()->pluck('id')->all())->toBe([$published->id]);
});
```

### Implementation

Если тест уже существует из `RG-102`, не дублировать.  
Убедиться, что он:

```txt
- использует factories;
- проверяет published vs pending/hidden/rejected;
- лежит в понятном файле.
```

При необходимости переименовать/расширить.

### Acceptance criteria

- Есть regression test для `Post::published()`.
- Тест использует factory states.
- Тест проверяет исключение pending/hidden/rejected.
- Тест проходит.

### Definition of Done

- Regression test существует.
- Нет дублирующих бессмысленных тестов.
- Коммит: `RG-108: Test Post published scope`

### Files likely touched

```txt
tests/Feature/Scopes/PostPublishedScopeTest.php
```

---

## RG-109 — Test Post Pending Scope

**Area:** Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-109-test-post-pending-scope`  
**Depends on:** RG-103

### Goal

Добавить отдельный/финальный тест для `Post::pending()` через factory states.

### TDD step

Regression test:

```php
it('only returns pending posts', function () {
    $pending = Post::factory()->pending()->create();
    Post::factory()->published()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    expect(Post::pending()->pluck('id')->all())->toBe([$pending->id]);
});
```

### Implementation

Если тест уже есть из `RG-103`, убедиться, что он достаточно полный.  
Иначе добавить/расширить.

### Acceptance criteria

- Есть regression test для `Post::pending()`.
- Тест использует factory states.
- Тест проверяет исключение published/hidden/rejected.
- Тест проходит.

### Definition of Done

- Regression test существует.
- Тест проходит.
- Коммит: `RG-109: Test Post pending scope`

### Files likely touched

```txt
tests/Feature/Scopes/PostPendingScopeTest.php
```

---

## RG-110 — Test Post User Relationship

**Area:** Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-110-test-post-user-relationship`  
**Depends on:** RG-082, RG-091

### Goal

Проверить relationship `Post::user()` через factories.

### TDD step

Test:

```php
it('belongs to a user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user)->create();

    expect($post->user)->toBeInstanceOf(User::class);
    expect($post->user->id)->toBe($user->id);
});
```

### Implementation

Если relationship уже есть из Phase 3, код модели менять не нужно.  
Добавить только regression test через factories.

### Acceptance criteria

- Тест использует `Post::factory()`.
- `Post::user` возвращает правильного user.
- Тест проходит.

### Definition of Done

- Relationship test добавлен.
- Тест проходит.
- Коммит: `RG-110: Test Post user relationship`

### Files likely touched

```txt
tests/Feature/Relationships/PostUserRelationshipTest.php
```

---

## RG-111 — Test Post Comments Relationship

**Area:** Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-111-test-post-comments-relationship`  
**Depends on:** RG-084, RG-092

### Goal

Проверить relationship `Post::comments()` через factories.

### TDD step

Test:

```php
it('has many comments', function () {
    $post = Post::factory()->create();

    $comments = Comment::factory()
        ->count(2)
        ->for($post)
        ->create();

    expect($post->comments()->count())->toBe(2);
    expect($post->comments()->pluck('id')->all())
        ->toEqualCanonicalizing($comments->pluck('id')->all());
});
```

### Implementation

Если relationship уже есть из Phase 3, код модели менять не нужно.  
Добавить только regression test через factories.

### Acceptance criteria

- Тест использует `Post::factory()` и `Comment::factory()`.
- `Post::comments()` возвращает все связанные comments.
- Тест проходит.

### Definition of Done

- Relationship test добавлен.
- Тест проходит.
- Коммит: `RG-111: Test Post comments relationship`

### Files likely touched

```txt
tests/Feature/Relationships/PostCommentsRelationshipTest.php
```

---

## RG-112 — Test Post Tags Relationship

**Area:** Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-112-test-post-tags-relationship`  
**Depends on:** RG-088, RG-097

### Goal

Проверить relationship `Post::tags()` через factories.

### TDD step

Test:

```php
it('belongs to many tags', function () {
    $post = Post::factory()->create();
    $tags = Tag::factory()->count(2)->create();

    $post->tags()->attach($tags);

    expect($post->tags()->count())->toBe(2);
    expect($post->tags()->pluck('id')->all())
        ->toEqualCanonicalizing($tags->pluck('id')->all());
});
```

### Implementation

Если relationship уже есть из Phase 3, код модели менять не нужно.  
Добавить только regression test через factories.

### Acceptance criteria

- Тест использует `Post::factory()` и `Tag::factory()`.
- `Post::tags()` возвращает связанные tags.
- Attach работает.
- Тест проходит.

### Definition of Done

- Relationship test добавлен.
- Тест проходит.
- Коммит: `RG-112: Test Post tags relationship`

### Files likely touched

```txt
tests/Feature/Relationships/PostTagsRelationshipTest.php
```

---

# 9. Phase 4 Completion Criteria

Phase 4 завершена, когда:

```txt
- RG-091–RG-112 выполнены;
- factories существуют для Post, Comment, PostVote, OriginVote, CuisineVote, Report, Tag;
- PostFactory имеет states: published, pending, hidden, rejected;
- Post scopes существуют: published, pending, hidden, reported, recent, hot;
- scope tests проходят;
- relationship tests через factories проходят;
- composer test проходит;
- php artisan migrate:fresh --env=testing проходит через test suite;
- нет бизнес-actions, FeedQuery, Livewire UI или Filament resources вне scope.
```

---

# 10. Что нельзя делать в Phase 4

Без отдельной задачи нельзя:

```txt
- писать CreatePostAction;
- писать FeedQuery;
- делать upload;
- добавлять image storage;
- писать VotePostAction;
- писать AddCommentAction;
- писать ReportContentAction;
- писать moderation actions;
- добавлять Livewire feed;
- создавать PostCard;
- создавать Filament resources;
- добавлять seeders;
- добавлять API resources;
- добавлять Redis;
- переходить на PostgreSQL;
- добавлять Vue/React/Inertia;
- менять auth stack;
- менять UI design contract.
```

---

# 11. Recommended Execution Order

```txt
RG-091 Add PostFactory
RG-092 Add CommentFactory
RG-093 Add PostVoteFactory
RG-094 Add OriginVoteFactory
RG-095 Add CuisineVoteFactory
RG-096 Add ReportFactory
RG-097 Add TagFactory
RG-098 Add Post published factory state
RG-099 Add Post pending factory state
RG-100 Add Post hidden factory state
RG-101 Add Post rejected factory state
RG-102 Add published scope to Post model
RG-103 Add pending scope to Post model
RG-104 Add hidden scope to Post model
RG-105 Add reported scope to Post model
RG-106 Add recent scope to Post model
RG-107 Add hot scope placeholder to Post model
RG-108 Test Post published scope
RG-109 Test Post pending scope
RG-110 Test Post user relationship
RG-111 Test Post comments relationship
RG-112 Test Post tags relationship
```

---

# 12. Release

После завершения Phase 4:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.0.5-phase4-model-scopes-and-factories
git push -u origin release/v0.0.5-phase4-model-scopes-and-factories
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.5-phase4-model-scopes-and-factories -m "RateGuru Phase 4 model scopes and factories"
git push origin v0.0.5-phase4-model-scopes-and-factories
```
