# RateGuru — Phase 7 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 7 — Feed Backend**  
Диапазон задач: **RG-149 → RG-165**  
Основа нумерации: исходный atomic backlog, где Phase 7 начинается с задачи 149 и заканчивается задачей 165.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 7 соответствует исходному блоку:

```txt
Phase 7 — Feed Backend
```

Правильный диапазон Phase 7:

```txt
RG-149 — Create FeedQuery class
RG-150 — Test FeedQuery returns only published posts
RG-151 — Implement published-only feed query
RG-152 — Test FeedQuery sorts by newest
RG-153 — Implement newest sorting
RG-154 — Test FeedQuery sorts by top
RG-155 — Implement top sorting
RG-156 — Test FeedQuery sorts by hot placeholder
RG-157 — Implement hot sorting by hot_score
RG-158 — Test FeedQuery filters by tag
RG-159 — Implement tag filter
RG-160 — Test FeedQuery searches title
RG-161 — Implement title search
RG-162 — Test FeedQuery searches description
RG-163 — Implement description search
RG-164 — Test FeedQuery paginates posts
RG-165 — Implement pagination
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 7

Phase 7 создаёт backend-query слой для будущей ленты.

После Phase 7 должно быть готово:

```txt
- FeedQuery class;
- published-only выдача;
- сортировка newest;
- сортировка top;
- сортировка hot;
- фильтр по tag;
- поиск по title;
- поиск по description;
- pagination;
- тесты на каждое поведение.
```

Phase 7 не создаёт UI.  
Phase 8 будет использовать FeedQuery в Livewire-компонентах.

---

# 3. Scope Phase 7

## Входит

```txt
- app/Queries/Feed/FeedQuery.php;
- app/Data/Feed/FeedFilters.php или простая input-структура;
- enum/constant для sort values, если нужно;
- tests for feed query behavior;
- published-only protection;
- sort/filter/search/pagination.
```

## Не входит

```txt
- Livewire FeedPage;
- PostFeed component;
- SearchBar UI;
- CategoryTabs UI;
- SortDropdown UI;
- PostCard UI;
- API endpoint;
- caching;
- recommendation algorithm;
- real hot score calculation;
- full-text search engine;
- Meilisearch;
- Scout;
- PostgreSQL full-text search;
- Redis;
- analytics.
```

---

# 4. Design Decisions

## 4.1. FeedQuery is read-side query, not business action

`FeedQuery` не должен создавать, изменять или модерировать посты.

Правильно:

```php
$posts = app(FeedQuery::class)->paginate(
    search: 'pasta',
    tag: 'italian',
    sort: 'hot',
    perPage: 20,
);
```

Неправильно:

```php
FeedQuery обновляет hot_score, скрывает посты, пересчитывает counters или пишет logs.
```

## 4.2. Only published posts by default

Публичная лента должна показывать только:

```txt
status = published
```

Hidden, pending, rejected, draft, deleted не должны попадать в feed.

## 4.3. Sorting values

Минимальные sort values:

```txt
newest
top
hot
```

Если в UI позже будет label `New`, `Top`, `Hot`, backend всё равно должен принимать стабильные internal values.

## 4.4. Top sorting

Для MVP top sorting:

```txt
upvotes_count - downvotes_count desc
```

Если нужен tie-breaker:

```txt
published_at desc
```

## 4.5. Hot sorting

В Phase 7 hot sorting — только placeholder:

```txt
hot_score desc
```

Реальная формула hot score будет позже, в Phase 33.

## 4.6. Search

На SQLite используем простой `LIKE`.

Поиск должен искать:

```txt
title
description
```

На Phase 7 не использовать full-text search.

## 4.7. Tag filter

Фильтр по тегу лучше принимать как `tag` slug, а не id.

Почему:

```txt
- URL будет /?tag=pasta, а не /?tag_id=14;
- UI проще;
- slug стабилен и читаем.
```

Внутри FeedQuery используется `whereHas('tags', fn...)`.

## 4.8. Pagination

На Phase 7 используем Laravel paginator:

```php
LengthAwarePaginator
```

или `paginate($perPage)`.

Не делать infinite scroll backend отдельно.  
Livewire в Phase 8 сможет использовать тот же paginator.

---

# 5. GitFlow для Phase 7

## Base branch

Все задачи Phase 7 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-149-create-feed-query-class
feature/RG-153-implement-newest-sorting
feature/RG-165-implement-pagination
```

## Commit format

```txt
RG-149: Create FeedQuery class
RG-153: Implement newest sorting
RG-165: Implement pagination
```

## Release branch

После выполнения `RG-149`–`RG-165`:

```txt
release/v0.0.8-phase7-feed-backend
```

## Tag

После merge release branch в `main`:

```txt
v0.0.8-phase7-feed-backend
```

---

# 6. TDD Rules for Phase 7

## Для FeedQuery class

Сначала тест на existence/resolution.

## Для каждого behavior

Сначала падающий test:

```txt
- published-only
- newest sort
- top sort
- hot sort
- tag filter
- title search
- description search
- pagination
```

Потом минимальная реализация.

## Для search/filter/sort

Каждый тест должен создавать минимум:

```txt
- один подходящий published post;
- один неподходящий published post;
- один pending/hidden post, который не должен появиться даже если совпадает с фильтром.
```

Это важно: иначе можно случайно сделать фильтр, который пропускает unpublished content.

---

# 7. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Backend / Tests / Query
Type: Test / Feature / Query
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
- Нет UI/API/cache вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 8. Phase 7 Atomic Tasks

---

## RG-149 — Create FeedQuery Class

**Area:** Backend / Query  
**Type:** Query  
**Priority:** P0  
**Branch:** `feature/RG-149-create-feed-query-class`  
**Depends on:** RG-112

### Goal

Создать skeleton `FeedQuery`, который будет использоваться будущими Livewire/API слоями для получения постов ленты.

### TDD step

Сначала unit/feature test:

```php
it('resolves feed query from container', function () {
    $query = app(FeedQuery::class);

    expect($query)->toBeInstanceOf(FeedQuery::class);
});
```

Тест должен упасть до создания класса.

### Implementation

Создать:

```txt
app/Queries/Feed/FeedQuery.php
```

Skeleton:

```php
namespace App\Queries\Feed;

use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class FeedQuery
{
    public function base(): Builder
    {
        return Post::query();
    }
}
```

Пока не реализовывать published-only/sorting/filtering.

### Acceptance criteria

- `FeedQuery` существует.
- Класс резолвится из container.
- Есть метод `base(): Builder`.
- Нет UI/API logic.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Class skeleton создан.
- Тест проходит.
- Коммит: `RG-149: Create FeedQuery class`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Unit/Queries/FeedQueryTest.php
```

---

## RG-150 — Test FeedQuery Returns Only Published Posts

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-150-test-feed-query-returns-only-published-posts`  
**Depends on:** RG-149

### Goal

Написать падающий тест: FeedQuery возвращает только published posts.

### TDD step

Feature test:

```php
it('returns only published posts', function () {
    $published = Post::factory()->published()->create();
    Post::factory()->pending()->create();
    Post::factory()->hidden()->create();
    Post::factory()->rejected()->create();

    $posts = app(FeedQuery::class)->get();

    expect($posts->pluck('id')->all())->toBe([$published->id]);
});
```

Если метод `get()` ещё не существует, тест должен упасть.

### Implementation

Только добавить тест.  
Не реализовывать query в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест создаёт published/pending/hidden/rejected posts.
- Тест ожидает только published post.
- Тест падает до RG-151.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-150: Test FeedQuery returns only published posts`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-151 — Implement Published-Only Feed Query

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-151-implement-published-only-feed-query`  
**Depends on:** RG-150

### Goal

Реализовать published-only behavior в FeedQuery.

### TDD step

Использовать падающий тест из RG-150.

### Implementation

В `FeedQuery` добавить метод:

```php
public function get(): Collection
{
    return $this->base()->published()->get();
}
```

Если хочется заранее поддержать builder composition:

```php
public function query(): Builder
{
    return $this->base()->published();
}

public function get(): Collection
{
    return $this->query()->get();
}
```

Лучше второй вариант, потому что следующие задачи будут добавлять sort/filter/search.

### Acceptance criteria

- FeedQuery возвращает только published posts.
- Pending/hidden/rejected не попадают.
- Используется `Post::published()` scope.
- Тест RG-150 проходит.

### Definition of Done

- Реализация минимальная.
- Тест проходит.
- Коммит: `RG-151: Implement published-only feed query`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-152 — Test FeedQuery Sorts By Newest

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-152-test-feed-query-sorts-by-newest`  
**Depends on:** RG-151

### Goal

Написать падающий тест: FeedQuery умеет сортировать published posts по newest.

### TDD step

Feature test:

```php
it('sorts published posts by newest', function () {
    $old = Post::factory()->published()->create([
        'published_at' => now()->subDay(),
    ]);

    $new = Post::factory()->published()->create([
        'published_at' => now(),
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'newest');

    expect($posts->pluck('id')->all())->toBe([$new->id, $old->id]);
});
```

Если named args не подходят для `get()`, сигнатуру можно сделать через DTO/options в RG-153.  
Тест должен зафиксировать желаемый публичный API FeedQuery.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест создаёт два published posts с разным `published_at`.
- Тест ожидает newest first.
- Тест падает до RG-153.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-152: Test FeedQuery sorts by newest`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-153 — Implement Newest Sorting

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-153-implement-newest-sorting`  
**Depends on:** RG-152

### Goal

Реализовать сортировку `newest`.

### TDD step

Использовать падающий тест из RG-152.

### Implementation

В `FeedQuery` расширить API:

```php
public function query(
    ?string $search = null,
    ?string $tag = null,
    string $sort = 'newest',
): Builder
{
    $query = $this->base()->published();

    return match ($sort) {
        'newest' => $query->orderByDesc('published_at')->orderByDesc('created_at'),
        default => $query->orderByDesc('published_at')->orderByDesc('created_at'),
    };
}

public function get(
    ?string $search = null,
    ?string $tag = null,
    string $sort = 'newest',
): Collection {
    return $this->query($search, $tag, $sort)->get();
}
```

Не добавлять tag/search/top/hot logic в этой задаче.

### Acceptance criteria

- `get(sort: 'newest')` работает.
- Newest сортирует по `published_at desc`.
- Tie-breaker `created_at desc`.
- Published-only behavior сохраняется.
- Тесты RG-150/RG-152 проходят.

### Definition of Done

- Реализация минимальная.
- Тесты проходят.
- Коммит: `RG-153: Implement newest sorting`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-154 — Test FeedQuery Sorts By Top

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-154-test-feed-query-sorts-by-top`  
**Depends on:** RG-153

### Goal

Написать падающий тест: FeedQuery умеет сортировать по top score.

### TDD step

Feature test:

```php
it('sorts published posts by top score', function () {
    $low = Post::factory()->published()->create([
        'upvotes_count' => 3,
        'downvotes_count' => 1,
    ]);

    $high = Post::factory()->published()->create([
        'upvotes_count' => 10,
        'downvotes_count' => 2,
    ]);

    $posts = app(FeedQuery::class)->get(sort: 'top');

    expect($posts->pluck('id')->all())->toBe([$high->id, $low->id]);
});
```

Top score = `upvotes_count - downvotes_count`.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет score difference, не просто upvotes.
- Тест ожидает high score first.
- Тест падает до RG-155.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-154: Test FeedQuery sorts by top`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-155 — Implement Top Sorting

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-155-implement-top-sorting`  
**Depends on:** RG-154

### Goal

Реализовать сортировку `top`.

### TDD step

Использовать падающий тест из RG-154.

### Implementation

В `FeedQuery` добавить branch для sort:

```php
'top' => $query
    ->orderByRaw('(upvotes_count - downvotes_count) DESC')
    ->orderByDesc('published_at'),
```

SQLite поддерживает arithmetic expression в orderByRaw.  
Не использовать alias-specific DB tricks.

### Acceptance criteria

- `get(sort: 'top')` работает.
- Сортирует по `upvotes_count - downvotes_count desc`.
- Tie-breaker `published_at desc`.
- Published-only behavior сохраняется.
- Тест проходит.

### Definition of Done

- Top sorting реализован.
- Тесты проходят.
- Коммит: `RG-155: Implement top sorting`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-156 — Test FeedQuery Sorts By Hot Placeholder

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-156-test-feed-query-sorts-by-hot-placeholder`  
**Depends on:** RG-155

### Goal

Написать падающий тест: FeedQuery умеет сортировать по hot_score.

### TDD step

Feature test:

```php
it('sorts published posts by hot score', function () {
    $cold = Post::factory()->published()->create(['hot_score' => 1]);
    $hot = Post::factory()->published()->create(['hot_score' => 10]);

    $posts = app(FeedQuery::class)->get(sort: 'hot');

    expect($posts->pluck('id')->all())->toBe([$hot->id, $cold->id]);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет `hot_score desc`.
- Тест падает до RG-157.
- Тест не требует расчёта hot_score.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-156: Test FeedQuery sorts by hot placeholder`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-157 — Implement Hot Sorting By Hot Score

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-157-implement-hot-sorting-by-hot-score`  
**Depends on:** RG-156

### Goal

Реализовать сортировку `hot` через `hot_score`.

### TDD step

Использовать падающий тест из RG-156.

### Implementation

В `FeedQuery` добавить branch:

```php
'hot' => $query
    ->orderByDesc('hot_score')
    ->orderByDesc('published_at'),
```

Не вычислять hot_score в FeedQuery.  
Не вызывать HotScoreCalculator.

### Acceptance criteria

- `get(sort: 'hot')` работает.
- Сортирует по `hot_score desc`.
- Tie-breaker `published_at desc`.
- Published-only behavior сохраняется.
- Тест проходит.

### Definition of Done

- Hot sorting реализован.
- Тесты проходят.
- Коммит: `RG-157: Implement hot sorting by hot_score`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-158 — Test FeedQuery Filters By Tag

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-158-test-feed-query-filters-by-tag`  
**Depends on:** RG-157

### Goal

Написать падающий тест: FeedQuery фильтрует published posts по tag slug.

### TDD step

Feature test:

```php
it('filters published posts by tag slug', function () {
    $pasta = Tag::factory()->create(['slug' => 'pasta']);
    $dessert = Tag::factory()->create(['slug' => 'dessert']);

    $matching = Post::factory()->published()->create();
    $matching->tags()->attach($pasta);

    $other = Post::factory()->published()->create();
    $other->tags()->attach($dessert);

    $hiddenMatching = Post::factory()->hidden()->create();
    $hiddenMatching->tags()->attach($pasta);

    $posts = app(FeedQuery::class)->get(tag: 'pasta');

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест фильтрует по slug, не id.
- Тест проверяет, что hidden post с тем же tag не попадает.
- Тест падает до RG-159.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-158: Test FeedQuery filters by tag`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-159 — Implement Tag Filter

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-159-implement-tag-filter`  
**Depends on:** RG-158

### Goal

Реализовать фильтр FeedQuery по tag slug.

### TDD step

Использовать падающий тест из RG-158.

### Implementation

В `FeedQuery::query()` до sorting добавить:

```php
if ($tag !== null && $tag !== '') {
    $query->whereHas('tags', function (Builder $tagQuery) use ($tag) {
        $tagQuery->where('slug', $tag);
    });
}
```

Не создавать tags.  
Не искать по tag name.  
Не принимать массив tags в Phase 7.

### Acceptance criteria

- `get(tag: 'pasta')` работает.
- Фильтр использует tag slug.
- Hidden/pending posts не попадают даже при совпадающем tag.
- Existing sorting продолжает работать.
- Тест проходит.

### Definition of Done

- Tag filter реализован.
- Тесты проходят.
- Коммит: `RG-159: Implement tag filter`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-160 — Test FeedQuery Searches Title

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-160-test-feed-query-searches-title`  
**Depends on:** RG-159

### Goal

Написать падающий тест: FeedQuery ищет по title.

### TDD step

Feature test:

```php
it('searches published posts by title', function () {
    $matching = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Dinner',
    ]);

    Post::factory()->published()->create([
        'title' => 'Chocolate Cake',
        'description' => 'Dessert',
    ]);

    Post::factory()->hidden()->create([
        'title' => 'Hidden Carbonara',
    ]);

    $posts = app(FeedQuery::class)->get(search: 'carbonara');

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
});
```

Поиск должен быть case-insensitive настолько, насколько это стандартно работает в SQLite `LIKE`.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест ищет по title.
- Тест проверяет, что hidden matching title не попадает.
- Тест падает до RG-161.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-160: Test FeedQuery searches title`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-161 — Implement Title Search

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-161-implement-title-search`  
**Depends on:** RG-160

### Goal

Реализовать поиск по title.

### TDD step

Использовать падающий тест из RG-160.

### Implementation

В `FeedQuery::query()` добавить:

```php
if ($search !== null && trim($search) !== '') {
    $term = trim($search);

    $query->where(function (Builder $searchQuery) use ($term) {
        $searchQuery->where('title', 'like', "%{$term}%");
    });
}
```

Пока искать только по title.  
Description search будет в RG-163.

Важно: если дальше RG-163 расширит тот же closure, не нужно ломать тесты.

### Acceptance criteria

- `get(search: 'carbonara')` находит по title.
- Empty search не фильтрует.
- Hidden/pending posts не попадают.
- Тест проходит.

### Definition of Done

- Title search реализован.
- Тесты проходят.
- Коммит: `RG-161: Implement title search`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-162 — Test FeedQuery Searches Description

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-162-test-feed-query-searches-description`  
**Depends on:** RG-161

### Goal

Написать падающий тест: FeedQuery ищет по description.

### TDD step

Feature test:

```php
it('searches published posts by description', function () {
    $matching = Post::factory()->published()->create([
        'title' => 'Dinner',
        'description' => 'Fresh basil and tomato sauce',
    ]);

    Post::factory()->published()->create([
        'title' => 'Breakfast',
        'description' => 'Eggs and toast',
    ]);

    Post::factory()->hidden()->create([
        'title' => 'Hidden',
        'description' => 'Fresh basil hidden post',
    ]);

    $posts = app(FeedQuery::class)->get(search: 'basil');

    expect($posts->pluck('id')->all())->toBe([$matching->id]);
});
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест ищет по description.
- Тест проверяет, что hidden matching description не попадает.
- Тест падает до RG-163.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-162: Test FeedQuery searches description`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-163 — Implement Description Search

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-163-implement-description-search`  
**Depends on:** RG-162

### Goal

Расширить search: искать по title OR description.

### TDD step

Использовать падающий тест из RG-162 и существующий title search test.

### Implementation

Обновить search closure:

```php
if ($search !== null && trim($search) !== '') {
    $term = trim($search);

    $query->where(function (Builder $searchQuery) use ($term) {
        $searchQuery
            ->where('title', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%");
    });
}
```

Важно: `where(function...)` должен оставаться внутри published query, чтобы OR не вытащил hidden posts.  
Нельзя писать:

```php
$query->where('title', 'like', ...)->orWhere('description', 'like', ...)
```

без группировки.

### Acceptance criteria

- Search ищет по title.
- Search ищет по description.
- OR условие сгруппировано.
- Hidden/pending matching posts не попадают.
- Тесты RG-160/RG-162 проходят.

### Definition of Done

- Description search реализован.
- Тесты проходят.
- Коммит: `RG-163: Implement description search`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-164 — Test FeedQuery Paginates Posts

**Area:** Backend / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-164-test-feed-query-paginates-posts`  
**Depends on:** RG-163

### Goal

Написать падающий тест: FeedQuery возвращает paginator.

### TDD step

Feature test:

```php
it('paginates feed posts', function () {
    Post::factory()->published()->count(25)->create();

    $paginator = app(FeedQuery::class)->paginate(perPage: 10);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
    expect($paginator->items())->toHaveCount(10);
    expect($paginator->total())->toBe(25);
});
```

Также проверить, что unpublished posts не считаются в total:

```php
Post::factory()->pending()->count(5)->create();
expect($paginator->total())->toBe(25);
```

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет paginator class.
- Тест проверяет perPage.
- Тест проверяет total only published posts.
- Тест падает до RG-165.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-164: Test FeedQuery paginates posts`

### Files likely touched

```txt
tests/Feature/Queries/FeedQueryTest.php
```

---

## RG-165 — Implement Pagination

**Area:** Backend / Query  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-165-implement-pagination`  
**Depends on:** RG-164

### Goal

Реализовать pagination в FeedQuery.

### TDD step

Использовать падающий тест из RG-164.

### Implementation

Добавить метод:

```php
public function paginate(
    ?string $search = null,
    ?string $tag = null,
    string $sort = 'newest',
    int $perPage = 20,
): LengthAwarePaginator {
    $perPage = max(1, min($perPage, 50));

    return $this
        ->query(search: $search, tag: $tag, sort: $sort)
        ->paginate($perPage);
}
```

Ограничение `max 50` нужно, чтобы UI/API не могли случайно запросить 10000 постов.

Оставить `get()` для тестов/внутренних сценариев.

### Acceptance criteria

- `paginate()` возвращает `LengthAwarePaginator`.
- `perPage` работает.
- `perPage` ограничен сверху.
- Pagination учитывает published-only.
- Pagination совместима с search/tag/sort.
- Тест проходит.

### Definition of Done

- Pagination реализована.
- Все FeedQuery tests проходят.
- `composer test` проходит.
- Коммит: `RG-165: Implement pagination`

### Files likely touched

```txt
app/Queries/Feed/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
```

---

# 9. Phase 7 Completion Criteria

Phase 7 завершена, когда:

```txt
- RG-149–RG-165 выполнены;
- FeedQuery существует;
- FeedQuery возвращает только published posts;
- newest sorting работает;
- top sorting работает;
- hot sorting работает через hot_score;
- tag filter работает по slug;
- title search работает;
- description search работает;
- pagination работает;
- tests покрывают unpublished exclusion для фильтров/поиска;
- composer test проходит;
- npm run build проходит;
- нет Livewire UI, API endpoint, caching или full-text search вне scope.
```

---

# 10. Что нельзя делать в Phase 7

Без отдельной задачи нельзя:

```txt
- создавать Livewire FeedPage;
- создавать PostFeed component;
- создавать SearchBar UI;
- создавать CategoryTabs UI;
- создавать SortDropdown UI;
- создавать PostCard UI;
- делать API endpoint;
- добавлять cache layer;
- добавлять Redis;
- добавлять Scout/Meilisearch;
- переходить на PostgreSQL full-text search;
- вычислять hot_score;
- делать recommendation algorithm;
- добавлять analytics;
- менять image storage;
- менять auth stack;
- добавлять Vue/React/Inertia.
```

---

# 11. Recommended Execution Order

```txt
RG-149 Create FeedQuery class
RG-150 Test FeedQuery returns only published posts
RG-151 Implement published-only feed query
RG-152 Test FeedQuery sorts by newest
RG-153 Implement newest sorting
RG-154 Test FeedQuery sorts by top
RG-155 Implement top sorting
RG-156 Test FeedQuery sorts by hot placeholder
RG-157 Implement hot sorting by hot_score
RG-158 Test FeedQuery filters by tag
RG-159 Implement tag filter
RG-160 Test FeedQuery searches title
RG-161 Implement title search
RG-162 Test FeedQuery searches description
RG-163 Implement description search
RG-164 Test FeedQuery paginates posts
RG-165 Implement pagination
```

---

# 12. Release

После завершения Phase 7:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.0.8-phase7-feed-backend
git push -u origin release/v0.0.8-phase7-feed-backend
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.8-phase7-feed-backend -m "RateGuru Phase 7 feed backend"
git push origin v0.0.8-phase7-feed-backend
```
