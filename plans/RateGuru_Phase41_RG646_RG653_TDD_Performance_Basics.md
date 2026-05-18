# RateGuru — Phase 41 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 41 — Performance Basics**  
Диапазон задач: **RG-646 → RG-653**  
Основа нумерации: исходный atomic backlog, где Phase 41 начинается с задачи 646 и заканчивается задачей 653.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 41 соответствует исходному блоку:

```txt
Phase 41 — Performance Basics
```

Правильный диапазон Phase 41:

```txt
RG-646 — Add eager loading to FeedQuery
RG-647 — Test FeedQuery avoids N+1 for user relationship
RG-648 — Add eager loading for tags
RG-649 — Add eager loading for vote counts
RG-650 — Add pagination limit guard
RG-651 — Add image size guard
RG-652 — Add post list cache placeholder
RG-653 — Add cache invalidation placeholder after vote
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно:

```txt
Phase 40 заканчивается на RG-645.
Phase 41 занимает RG-646 → RG-653.
Phase 42 начинается с RG-654 и делает Deployment Prep.
```

Значит Phase 41 не должна добавлять production checklist, storage symlink docs, queue worker docs, deploy migration docs или backup strategy notes из Phase 42.
---

# 2. Цель Phase 41

Phase 41 закрывает базовые performance-риски перед deployment prep.

После Phase 41 проект должен:

```txt
- не делать N+1 на user relationship в feed;
- не делать N+1 на tags в feed;
- не делать лишние запросы для vote counts в feed;
- защищаться от слишком большого perPage/page size;
- защищаться от слишком больших изображений на upload;
- иметь placeholder/контракт для будущего кэша списков posts;
- иметь placeholder/контракт для будущей invalidation после vote.
```

Это не фаза глубокой оптимизации. Это базовая страховка от очевидных проблем.
---

# 3. Scope Phase 41

## Входит

```txt
- FeedQuery eager loading;
- query-count tests for user relationship;
- eager loading tags;
- vote counts loading/aggregate protection;
- pagination limit guard;
- upload/image size guard;
- config for feed pagination and image limits;
- cache placeholder docs/classes;
- cache invalidation placeholder after vote;
- tests for guards/placeholders.
```

## Не входит

```txt
- Redis setup;
- cache implementation for feed results;
- tagged cache dependency;
- cache hit/miss metrics;
- full query profiler;
- DB index optimization;
- PostgreSQL migration;
- CDN/image resizing pipeline;
- image compression job implementation;
- queue worker setup;
- deployment docs from Phase 42;
- API pagination/filtering from Phase 40/future API.
```

Если в этой фазе появляется Redis как обязательная зависимость — это ошибка.
---

# 4. Critical Decisions

## 4.1. Optimize FeedQuery first

Feed — самый частый публичный экран. Базовый render feed почти наверняка требует:

```txt
Post
author/user
tags
engagement counters
origin/cuisine counters if shown on cards
```

Если `PostCard` обращается к `$post->user`, `$post->tags`, `$post->comments`, `$post->postVotes`, `$post->originVotes`, `$post->cuisineVotes` лениво, feed деградирует линейно от количества карточек.

Phase 41 исправляет только очевидную форму запроса.

## 4.2. FeedQuery должен возвращать ready-to-render posts

Неправильно:

```php
Post::published()->latest()->paginate();
```

а потом в Blade:

```php
$post->user;
$post->tags;
$post->comments()->count();
$post->postVotes()->count();
```

Правильно:

```php
Post::query()
    ->published()
    ->with(['user', 'tags'])
    ->paginate($perPage);
```

А counters должны читаться из агрегатных колонок, созданных предыдущими фазами.

## 4.3. Vote counts: использовать агрегаты, не rows

Для feed/card rendering использовать:

```txt
upvotes_count
downvotes_count
comments_count
origin_homemade_count
origin_restaurant_count
cuisine_* counters
```

Не использовать:

```php
$post->postVotes->count();
$post->originVotes->where(...)->count();
$post->cuisineVotes->where(...)->count();
```

Это не “eager load all vote rows”. Это проверка, что UI не вытягивает огромные relation collections только ради чисел.

## 4.4. Query count tests должны быть полезными, но не хрупкими

Не надо утверждать, что feed всегда делает ровно `3` SQL-запроса. Это ломается от framework noise.

Лучше:

```txt
- создать 1 post и 10 posts;
- убедиться, что query count не растёт на +1 за каждый post;
- или поставить разумный threshold.
```

## 4.5. Pagination guard обязателен

Нельзя разрешить:

```txt
?perPage=100000
```

Рекомендуемые значения:

```txt
default_per_page = 12
max_per_page = 50
```

Правила:

```txt
perPage missing → default
perPage <= 0 → default
perPage > max → max
```

## 4.6. Image size guard защищает storage/processing

Минимальный серверный guard:

```txt
max image file size: 5 MB
mimes: jpg/jpeg/png/webp
optional max dimensions: 6000x6000
```

Client-side подсказки можно добавить позже. В Phase 41 нужен server-side guard до storage/processing.

## 4.7. Cache placeholders — это контракт, не кэш

Backlog говорит:

```txt
Add post list cache placeholder
Add cache invalidation placeholder after vote
```

Это значит:

```txt
- создать seam для будущего кэша;
- задокументировать ключи и invalidation triggers;
- добавить no-op implementation;
- не включать настоящий кэш feed.
```

Нельзя делать:

```php
Cache::tags(['posts'])->flush();
```

Потому что file/database cache может не поддерживать tags, а Redis ещё не является обязательной частью проекта.
---

# 5. Architecture Rules

## 5.1. FeedQuery остаётся центральным query object

Не дублировать feed query в Livewire-компонентах:

```txt
FeedPage
PostFeed
future API
```

Все должны опираться на один источник query-логики.

## 5.2. Config вместо magic numbers

Рекомендуемые файлы:

```txt
config/feed.php
config/uploads.php
```

`config/feed.php`:

```php
return [
    'default_per_page' => env('FEED_DEFAULT_PER_PAGE', 12),
    'max_per_page' => env('FEED_MAX_PER_PAGE', 50),
];
```

`config/uploads.php`:

```php
return [
    'images' => [
        'max_kilobytes' => env('UPLOAD_IMAGE_MAX_KB', 5120),
        'max_width' => env('UPLOAD_IMAGE_MAX_WIDTH', 6000),
        'max_height' => env('UPLOAD_IMAGE_MAX_HEIGHT', 6000),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
```

## 5.3. Cache placeholder лучше сделать сервисом

Рекомендуемый файл:

```txt
app/Support/Cache/PostListCacheManager.php
```

Минимальный контракт:

```php
public function remember(string $key, Closure $callback): mixed;
public function invalidateForPost(Post $post): void;
public function keyForFeed(array $filters): string;
```

В Phase 41 `remember()` просто возвращает результат callback, а `invalidateForPost()` ничего не делает.

## 5.4. Vote action может вызывать invalidation seam

После успешного vote:

```php
$this->postListCache->invalidateForPost($post);
```

Но это no-op. Смысл — не забыть точку интеграции при включении реального кэша позже.

## 5.5. Rate limiting и cache — разные вещи

Phase 34 уже занималась abuse/rate limiting. Phase 41 не должна подменять это кэшем и не должна менять rate limit rules.
---

# 6. Suggested Files

Likely touched:

```txt
app/Queries/FeedQuery.php
app/Livewire/Feed/PostFeed.php
resources/views/components/feed/post-card.blade.php
app/Actions/Posts/CreatePostAction.php
app/Livewire/Upload/UploadPostForm.php
app/Actions/Votes/VotePostAction.php
app/Actions/Votes/VoteOriginAction.php
app/Actions/Votes/VoteCuisineAction.php
app/Support/Cache/PostListCacheManager.php
config/feed.php
config/uploads.php
docs/performance/post-list-cache-placeholder.md
docs/performance/phase-41-performance-basics-review.md
tests/Feature/Queries/FeedQueryPerformanceTest.php
tests/Feature/Queries/FeedQueryPaginationTest.php
tests/Feature/Livewire/UploadPostFormTest.php
tests/Feature/Actions/CreatePostActionImageGuardTest.php
tests/Unit/Support/Cache/PostListCacheManagerTest.php
```
---

# 7. GitFlow для Phase 41

## Base branch

```txt
develop
```

## Branch format

```txt
feature/RG-646-add-eager-loading-to-feed-query
feature/RG-650-add-pagination-limit-guard
feature/RG-653-add-cache-invalidation-placeholder-after-vote
```

## Commit format

```txt
RG-646: Add eager loading to FeedQuery
RG-650: Add pagination limit guard
RG-653: Add cache invalidation placeholder after vote
```

## Release branch

```txt
release/v0.2.22-phase41-performance-basics
```

## Tag

```txt
v0.2.22-phase41-performance-basics
```
---

# 8. TDD Rules for Phase 41

## Для eager loading

```txt
- FeedQuery returns posts with user relation loaded;
- FeedQuery returns posts with tags relation loaded;
- render/access does not trigger N+1;
- query count stays bounded when post count grows.
```

## Для vote counts

```txt
- PostCard/feed can access counters without loading vote relations;
- counters are present as attributes;
- no per-post vote count queries in feed render.
```

## Для pagination guard

```txt
- requested perPage > max clamps to max;
- requested perPage <= 0 falls back to default;
- missing perPage uses default;
- configured max is respected.
```

## Для image size guard

```txt
- image above max KB is rejected;
- allowed image passes;
- invalid mime is rejected if not already covered;
- rejection happens before storage side effects.
```

## Для cache placeholders

```txt
- service resolves;
- remember() returns callback result;
- invalidateForPost() exists and is safe no-op;
- vote action calls invalidation placeholder after successful vote.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Performance / Feed / Upload / Cache
Type: Test / Feature / Refactor / Guard / Placeholder
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым. Если задача placeholder:
Тест на наличие контракта/сервиса/вызова.

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
- Нет Redis/cache dependency creep
- Нет Phase 42 deployment docs
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 41 Atomic Tasks
---

## RG-646 — Add Eager Loading To FeedQuery

**Area:** Performance / Feed  
**Type:** Query Refactor  
**Priority:** P0  
**Branch:** `feature/RG-646-add-eager-loading-to-feed-query`  
**Base branch:** develop
**Depends on:** RG-645

### Goal

Добавить базовый eager loading в `FeedQuery`, чтобы feed возвращал posts, готовые для `PostCard`.

### TDD step

```php
it('loads authors for feed posts', function () {
    $author = User::factory()->create();

    Post::factory()
        ->for($author, 'user')
        ->published()
        ->create();

    $posts = app(FeedQuery::class)->handle();

    $first = $posts->items()[0];

    expect($first->relationLoaded('user'))->toBeTrue();
});
```

Smoke на прежнее поведение:

```php
it('still returns only published posts after eager loading change', function () {
    Post::factory()->published()->create();
    Post::factory()->pending()->create();

    $posts = app(FeedQuery::class)->handle();

    expect(collect($posts->items())->every(
        fn ($post) => $post->status === PostStatus::Published
    ))->toBeTrue();
});
```

### Implementation

В `FeedQuery`:

```php
$query = Post::query()
    ->published()
    ->with([
        'user',
    ]);
```

Если relationship называется `author`, использовать фактическое имя.

Не добавлять tags/vote counts в этой задаче — это RG-648/RG-649.

### Acceptance criteria

- FeedQuery eager-loads `user`/author relation.
- Existing published-only behavior still works.
- Existing search/tag/sort behavior still works.
- No Livewire component duplicates feed query.
- Tests pass.

### Definition of Done

- Test written.
- FeedQuery updated minimally.
- Existing FeedQuery tests pass.
- Коммит: `RG-646: Add eager loading to FeedQuery`

### Files likely touched

```txt
app/Queries/FeedQuery.php
tests/Feature/Queries/FeedQueryTest.php
tests/Feature/Queries/FeedQueryPerformanceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-647 — Test FeedQuery Avoids N+1 For User Relationship

**Area:** Performance / Feed / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-647-test-feed-query-avoids-n-plus-one-for-user-relationship`  
**Base branch:** develop
**Depends on:** RG-646

### Goal

Зафиксировать тестом, что доступ к `$post->user` в feed не создаёт N+1.

### TDD step

```php
it('does not perform n plus one queries when accessing feed post authors', function () {
    Post::factory()
        ->count(10)
        ->published()
        ->for(User::factory(), 'user')
        ->create();

    $queryCount = $this->countQueries(function () {
        $posts = app(FeedQuery::class)->handle(perPage: 10);

        foreach ($posts->items() as $post) {
            $post->user?->username;
        }
    });

    expect($queryCount)->toBeLessThanOrEqual(5);
});
```

Если framework noise выше, threshold можно поднять, но тест должен ловить линейный рост запросов.

### Implementation

Если RG-646 сделан правильно, тест должен пройти. Если нет — исправить `FeedQuery`.

Не добавлять profiler/debugbar packages.

### Acceptance criteria

- Test creates multiple posts with authors.
- Test accesses author relationship.
- Query count remains bounded.
- Test fails if user eager loading is removed.
- Tests pass.

### Definition of Done

- N+1 test written.
- FeedQuery adjusted if needed.
- Tests pass.
- Коммит: `RG-647: Test FeedQuery avoids N+1 for user relationship`

### Files likely touched

```txt
app/Queries/FeedQuery.php
tests/Feature/Queries/FeedQueryPerformanceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-648 — Add Eager Loading For Tags

**Area:** Performance / Feed / Tags  
**Type:** Query Refactor  
**Priority:** P0  
**Branch:** `feature/RG-648-add-eager-loading-for-tags`  
**Base branch:** develop
**Depends on:** RG-647

### Goal

Добавить eager loading `tags` в `FeedQuery`, чтобы `PostCard` не делал N+1 на tags.

### TDD step

```php
it('loads tags for feed posts', function () {
    $tag = Tag::factory()->create();

    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    $posts = app(FeedQuery::class)->handle();

    $first = $posts->items()[0];

    expect($first->relationLoaded('tags'))->toBeTrue();
});
```

N+1 test:

```php
it('does not perform n plus one queries when accessing feed post tags', function () {
    $tags = Tag::factory()->count(3)->create();

    Post::factory()
        ->count(10)
        ->published()
        ->create()
        ->each(fn ($post) => $post->tags()->attach($tags->random(2)));

    $queryCount = $this->countQueries(function () {
        $posts = app(FeedQuery::class)->handle(perPage: 10);

        foreach ($posts->items() as $post) {
            $post->tags->pluck('slug')->all();
        }
    });

    expect($queryCount)->toBeLessThanOrEqual(6);
});
```

### Implementation

В `FeedQuery`:

```php
->with([
    'user',
    'tags',
])
```

Не оптимизировать selected columns преждевременно. Для `belongsToMany` легко сломать pivot keys.

### Acceptance criteria

- FeedQuery eager-loads tags.
- PostCard can access tags without extra per-post queries.
- Existing tag filtering still works.
- Existing feed tests still pass.
- Tests pass.

### Definition of Done

- Tests written.
- Tags eager loading added.
- N+1 protection test passes.
- Коммит: `RG-648: Add eager loading for tags`

### Files likely touched

```txt
app/Queries/FeedQuery.php
tests/Feature/Queries/FeedQueryPerformanceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-649 — Add Eager Loading For Vote Counts

**Area:** Performance / Feed / Vote Counts  
**Type:** Query/UI Refactor  
**Priority:** P0  
**Branch:** `feature/RG-649-add-eager-loading-for-vote-counts`  
**Base branch:** develop
**Depends on:** RG-648

### Goal

Убедиться, что feed/PostCard получает vote/comment counts без per-post count queries и без загрузки всех vote rows.

### TDD step

```php
it('provides vote count attributes for feed posts', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 3,
        'downvotes_count' => 1,
        'comments_count' => 2,
    ]);

    $posts = app(FeedQuery::class)->handle();

    $first = collect($posts->items())->firstWhere('id', $post->id);

    expect($first->upvotes_count)->toBe(3);
    expect($first->downvotes_count)->toBe(1);
    expect($first->comments_count)->toBe(2);
});
```

Проверка, что feed не грузит полные vote relations ради count:

```php
it('does not load full vote relations for feed counts', function () {
    Post::factory()->published()->create();

    $posts = app(FeedQuery::class)->handle();

    $first = $posts->items()[0];

    expect($first->relationLoaded('postVotes'))->toBeFalse();
    expect($first->relationLoaded('originVotes'))->toBeFalse();
    expect($first->relationLoaded('cuisineVotes'))->toBeFalse();
});
```

### Implementation

Проверить `PostCard` и voting components.

Правильное решение:

```txt
PostCard читает aggregate columns:
- upvotes_count
- downvotes_count
- comments_count
- origin_homemade_count
- origin_restaurant_count
- cuisine_* counters
```

Если где-то есть:

```php
$post->postVotes->count()
$post->comments()->count()
```

заменить на агрегаты.

Если нужный агрегат отсутствует, использовать `withCount` только как fallback, не загружая rows.

### Acceptance criteria

- Feed posts expose required count attributes.
- Feed/PostCard does not load full vote relations only for counts.
- Feed render does not do per-post vote count queries.
- Existing vote behavior unchanged.
- Existing counter recalculation remains source of truth.
- Tests pass.

### Definition of Done

- Tests written.
- Feed/PostCard count access fixed.
- No unnecessary vote relation eager loading.
- Tests pass.
- Коммит: `RG-649: Add eager loading for vote counts`

### Files likely touched

```txt
app/Queries/FeedQuery.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
tests/Feature/Queries/FeedQueryPerformanceTest.php
tests/Feature/Livewire/PostFeedTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-650 — Add Pagination Limit Guard

**Area:** Performance / Feed / Pagination  
**Type:** Guard  
**Priority:** P0  
**Branch:** `feature/RG-650-add-pagination-limit-guard`  
**Base branch:** develop
**Depends on:** RG-649

### Goal

Защитить feed pagination от слишком большого `perPage`.

### TDD step

Default:

```php
it('uses default feed per page when perPage is missing', function () {
    config()->set('feed.default_per_page', 12);
    config()->set('feed.max_per_page', 50);

    Post::factory()->count(20)->published()->create();

    $posts = app(FeedQuery::class)->handle(perPage: null);

    expect($posts->perPage())->toBe(12);
});
```

Clamp max:

```php
it('clamps feed per page to configured max', function () {
    config()->set('feed.default_per_page', 12);
    config()->set('feed.max_per_page', 50);

    Post::factory()->count(100)->published()->create();

    $posts = app(FeedQuery::class)->handle(perPage: 1000);

    expect($posts->perPage())->toBe(50);
});
```

Invalid:

```php
it('falls back to default feed per page for invalid perPage', function () {
    config()->set('feed.default_per_page', 12);

    Post::factory()->count(20)->published()->create();

    $posts = app(FeedQuery::class)->handle(perPage: -10);

    expect($posts->perPage())->toBe(12);
});
```

### Implementation

Создать `config/feed.php`:

```php
return [
    'default_per_page' => env('FEED_DEFAULT_PER_PAGE', 12),
    'max_per_page' => env('FEED_MAX_PER_PAGE', 50),
];
```

В `FeedQuery`:

```php
private function normalizePerPage(?int $perPage): int
{
    $default = (int) config('feed.default_per_page', 12);
    $max = (int) config('feed.max_per_page', 50);

    if ($perPage === null || $perPage < 1) {
        return $default;
    }

    return min($perPage, $max);
}
```

Если `perPage` приходит строкой из query string, безопасно привести к int.

### Acceptance criteria

- `config/feed.php` exists.
- Missing perPage uses default.
- Invalid perPage uses default.
- Excessive perPage clamps to max.
- FeedQuery uses normalized perPage.
- No user can request unlimited feed.
- Tests pass.

### Definition of Done

- Tests written.
- Config added.
- Guard implemented.
- Tests pass.
- Коммит: `RG-650: Add pagination limit guard`

### Files likely touched

```txt
config/feed.php
app/Queries/FeedQuery.php
app/Livewire/Feed/PostFeed.php
tests/Feature/Queries/FeedQueryPaginationTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-651 — Add Image Size Guard

**Area:** Performance / Upload / Images  
**Type:** Validation Guard  
**Priority:** P0  
**Branch:** `feature/RG-651-add-image-size-guard`  
**Base branch:** develop
**Depends on:** RG-650

### Goal

Добавить server-side guard против слишком больших изображений.

### TDD step

Oversized image:

```php
it('rejects uploaded images above configured max size', function () {
    config()->set('uploads.images.max_kilobytes', 5120);

    $user = User::factory()->create();

    $file = UploadedFile::fake()
        ->image('huge.jpg')
        ->size(6000);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Huge Image Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['image']);
});
```

Allowed image:

```php
it('allows uploaded images within configured max size', function () {
    config()->set('uploads.images.max_kilobytes', 5120);

    $user = User::factory()->create();
    Storage::fake('public');

    $file = UploadedFile::fake()
        ->image('ok.jpg')
        ->size(1000);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Allowed Image Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasNoErrors(['image']);
});
```

### Implementation

Создать `config/uploads.php`:

```php
return [
    'images' => [
        'max_kilobytes' => env('UPLOAD_IMAGE_MAX_KB', 5120),
        'max_width' => env('UPLOAD_IMAGE_MAX_WIDTH', 6000),
        'max_height' => env('UPLOAD_IMAGE_MAX_HEIGHT', 6000),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
```

Validation rules:

```php
'image' => [
    'required',
    'image',
    'mimes:' . implode(',', config('uploads.images.mimes')),
    'max:' . config('uploads.images.max_kilobytes'),
    Rule::dimensions()
        ->maxWidth(config('uploads.images.max_width'))
        ->maxHeight(config('uploads.images.max_height')),
],
```

Guard должен быть server-side. HTML file input constraints не считаются защитой.

### Acceptance criteria

- `config/uploads.php` exists.
- Oversized image is rejected.
- Allowed image passes.
- Unsupported mime rejected if tested.
- Rejection happens before file is stored.
- Upload error is user-visible in Livewire form.
- No image processing pipeline added.
- Tests pass.

### Definition of Done

- Tests written.
- Image validation guard implemented.
- Upload UI shows validation error.
- Tests pass.
- Коммит: `RG-651: Add image size guard`

### Files likely touched

```txt
config/uploads.php
app/Livewire/Upload/UploadPostForm.php
app/Actions/Posts/CreatePostAction.php
app/Data/CreatePostData.php
tests/Feature/Livewire/UploadPostFormTest.php
tests/Feature/Actions/CreatePostActionImageGuardTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-652 — Add Post List Cache Placeholder

**Area:** Performance / Cache Placeholder  
**Type:** Placeholder  
**Priority:** P1  
**Branch:** `feature/RG-652-add-post-list-cache-placeholder`  
**Base branch:** develop
**Depends on:** RG-651

### Goal

Добавить placeholder/контракт для будущего кэширования списков posts без включения реального кэша.

### TDD step

Service resolves:

```php
it('resolves post list cache manager', function () {
    expect(app(PostListCacheManager::class))
        ->toBeInstanceOf(PostListCacheManager::class);
});
```

No-op remember:

```php
it('post list cache placeholder returns callback result', function () {
    $result = app(PostListCacheManager::class)->remember(
        key: 'feed:newest:page:1',
        callback: fn () => 'fresh-result',
    );

    expect($result)->toBe('fresh-result');
});
```

Stable key:

```php
it('generates stable post list cache keys from filters', function () {
    $key = app(PostListCacheManager::class)->keyForFeed([
        'sort' => 'newest',
        'search' => 'pasta',
        'tag' => 'italian',
        'page' => 1,
        'perPage' => 12,
    ]);

    expect($key)->toContain('post-list:feed');
    expect($key)->toContain('sort=newest');
    expect($key)->toContain('search=pasta');
});
```

### Implementation

Создать:

```txt
app/Support/Cache/PostListCacheManager.php
```

```php
final class PostListCacheManager
{
    public function remember(string $key, Closure $callback): mixed
    {
        return $callback();
    }

    public function invalidateForPost(Post $post): void
    {
        // Placeholder: real invalidation will be implemented when feed caching is enabled.
    }

    public function keyForFeed(array $filters): string
    {
        ksort($filters);

        $parts = collect($filters)
            ->map(fn ($value, $key) => $key . '=' . (string) $value)
            ->implode(':');

        return 'post-list:feed:' . $parts;
    }
}
```

Добавить docs:

```txt
docs/performance/post-list-cache-placeholder.md
```

В документе зафиксировать:

```txt
- real feed cache is not enabled;
- future cache keys;
- invalidation triggers: vote, comment, post create, moderation status change, tag change;
- Redis is not required now;
- Cache::tags is not allowed as MVP dependency.
```

### Acceptance criteria

- PostListCacheManager exists.
- Service resolves.
- `remember()` returns callback result.
- `invalidateForPost()` exists and is safe no-op.
- Stable key builder exists.
- Docs explain future caching strategy.
- No real cache behavior enabled.
- No Redis/tagged cache dependency.
- Tests pass.

### Definition of Done

- Tests written.
- Placeholder service created.
- Docs added.
- Tests pass.
- Коммит: `RG-652: Add post list cache placeholder`

### Files likely touched

```txt
app/Support/Cache/PostListCacheManager.php
docs/performance/post-list-cache-placeholder.md
tests/Unit/Support/Cache/PostListCacheManagerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-653 — Add Cache Invalidation Placeholder After Vote

**Area:** Performance / Cache Placeholder / Voting  
**Type:** Placeholder Integration  
**Priority:** P1  
**Branch:** `feature/RG-653-add-cache-invalidation-placeholder-after-vote`  
**Base branch:** develop
**Depends on:** RG-652

### Goal

Добавить вызов placeholder invalidation после успешного vote, не включая реальный кэш.

### TDD step

Successful post vote:

```php
it('calls post list cache invalidation after successful post vote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldReceive('invalidateForPost')
        ->once()
        ->with(Mockery::on(fn ($givenPost) => $givenPost->is($post)));

    app()->instance(PostListCacheManager::class, $cache);

    app(VotePostAction::class)->handle($user, $post, VoteValue::Up);
});
```

Failed vote:

```php
it('does not invalidate post list cache after failed vote', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();

    $cache = Mockery::mock(PostListCacheManager::class);
    $cache->shouldNotReceive('invalidateForPost');

    app()->instance(PostListCacheManager::class, $cache);

    try {
        app(VotePostAction::class)->handle($user, $post, VoteValue::Up);
    } catch (CannotVoteException) {
        // expected
    }
});
```

Если origin/cuisine vote counts показываются в feed, аналогично проверить:

```txt
VoteOriginAction
VoteCuisineAction
```

### Implementation

Внедрить `PostListCacheManager` в vote actions.

После успешной мутации:

```php
$this->postListCache->invalidateForPost($post->fresh());
```

Рекомендуемый порядок:

```txt
1. validate/auth/status/rate limit
2. create/update/toggle vote
3. recalculate counters
4. recalculate hot score if applicable
5. invalidate post list cache placeholder
```

Не вызывать invalidation при exception/failed vote.

Добавить review doc:

```txt
docs/performance/phase-41-performance-basics-review.md
```

Checklist:

```txt
- FeedQuery eager-loads user;
- FeedQuery eager-loads tags;
- vote counts do not trigger N+1;
- pagination max guard exists;
- image size guard exists;
- cache placeholder exists;
- vote invalidation placeholder called;
- no Redis requirement.
```

### Acceptance criteria

- Successful post vote calls invalidation placeholder.
- Failed vote does not call invalidation placeholder.
- Origin/cuisine votes call invalidation if their counts appear in feed.
- Invalidation is no-op.
- No real cache behavior enabled.
- No Redis/tagged cache dependency.
- Phase 41 review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- Vote actions call placeholder after success.
- Failed votes do not invalidate.
- Review doc added.
- Tests/build pass.
- Коммит: `RG-653: Add cache invalidation placeholder after vote`

### Files likely touched

```txt
app/Actions/Votes/VotePostAction.php
app/Actions/Votes/VoteOriginAction.php
app/Actions/Votes/VoteCuisineAction.php
app/Support/Cache/PostListCacheManager.php
docs/performance/phase-41-performance-basics-review.md
tests/Feature/Actions/VoteCacheInvalidationPlaceholderTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 41 Completion Criteria

Phase 41 завершена, когда:

```txt
- RG-646–RG-653 выполнены;
- FeedQuery eager-loads user/author;
- FeedQuery avoids N+1 for user relationship;
- FeedQuery eager-loads tags;
- FeedQuery avoids N+1 for tags;
- feed uses aggregate vote/comment counters instead of full vote relation counts;
- vote count rendering does not create per-post queries;
- pagination limit guard exists;
- excessive perPage clamps to configured max;
- invalid perPage falls back to default;
- image size guard exists;
- oversized image uploads are rejected before storage;
- allowed image uploads still work;
- post list cache placeholder exists;
- cache placeholder does not enable real caching;
- cache invalidation placeholder is called after successful vote;
- failed vote does not trigger invalidation;
- no Redis requirement added;
- no Phase 42 deployment docs added;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 41

Без отдельной задачи нельзя:

```txt
- добавлять Redis как обязательную зависимость;
- включать реальный feed cache;
- использовать Cache::tags как requirement;
- добавлять cache hit/miss metrics;
- добавлять CDN integration;
- добавлять image compression/resizing job;
- добавлять DB index migrations без отдельного решения;
- добавлять deployment checklist из Phase 42;
- добавлять storage symlink docs из Phase 42;
- добавлять queue worker docs из Phase 42;
- делать SQLite→PostgreSQL migration note;
- добавлять API endpoints;
- менять UI polish/screenshots;
- добавлять React/Vue/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-646 Add eager loading to FeedQuery
RG-647 Test FeedQuery avoids N+1 for user relationship
RG-648 Add eager loading for tags
RG-649 Add eager loading for vote counts
RG-650 Add pagination limit guard
RG-651 Add image size guard
RG-652 Add post list cache placeholder
RG-653 Add cache invalidation placeholder after vote
```
---

# 14. Release

После завершения Phase 41:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
composer test:browser
php artisan visual:screenshot all

git checkout -b release/v0.2.22-phase41-performance-basics
git push -u origin release/v0.2.22-phase41-performance-basics
```

Если browser/screenshot команды не входят в обязательный local release check, минимум:

```bash
composer test
npm run build
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.22-phase41-performance-basics -m "RateGuru Phase 41 Performance Basics"
git push origin v0.2.22-phase41-performance-basics
```
