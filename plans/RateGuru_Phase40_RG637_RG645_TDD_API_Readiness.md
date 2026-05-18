# RateGuru — Phase 40 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 40 — API Readiness, Not Full API**  
Диапазон задач: **RG-637 → RG-645**  
Основа нумерации: исходный atomic backlog, где Phase 40 начинается с задачи 637 и заканчивается задачей 645.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 40 соответствует исходному блоку:

```txt
Phase 40 — API Readiness, Not Full API
```

Правильный диапазон Phase 40:

```txt
RG-637 — Create API route file placeholder
RG-638 — Create PostResource JSON resource
RG-639 — Test PostResource shape
RG-640 — Create CommentResource JSON resource
RG-641 — Test CommentResource shape
RG-642 — Create UserResource JSON resource
RG-643 — Test UserResource shape
RG-644 — Add API auth strategy note
RG-645 — Add API versioning note
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно:

```txt
Phase 39 заканчивается на RG-636.
Phase 40 занимает RG-637 → RG-645.
Phase 41 начинается с RG-646 и делает Performance Basics.
```

Значит Phase 40 не должна добавлять eager loading performance fixes из Phase 41, cache placeholders, pagination guard, image size guard или feed query optimization.
---

# 2. Цель Phase 40

Phase 40 подготавливает проект к будущему API, но не строит полноценный публичный API.

После Phase 40 в проекте должно быть:

```txt
- placeholder для routes/api.php;
- понятное место для будущих API routes;
- PostResource JSON resource;
- tests for PostResource shape;
- CommentResource JSON resource;
- tests for CommentResource shape;
- UserResource JSON resource;
- tests for UserResource shape;
- API auth strategy note;
- API versioning note.
```

Главная цель: заранее определить форму API-ответов и архитектурные решения, чтобы позже не городить API поверх Blade/Livewire хаоса.

Но это не API-фаза. Здесь нельзя начинать строить endpoints.
---

# 3. Scope Phase 40

## Входит

```txt
- routes/api.php placeholder;
- registration/loading of API route file if Laravel app does not load it yet;
- comments/TODOs in route file;
- App\Http\Resources\Api\PostResource;
- App\Http\Resources\Api\CommentResource;
- App\Http\Resources\Api\UserResource;
- resource shape tests;
- API auth strategy documentation;
- API versioning documentation;
- docs index/link updates.
```

## Не входит

```txt
- public API controllers;
- /api/posts endpoint;
- /api/comments endpoint;
- /api/users endpoint;
- API write endpoints;
- API auth implementation;
- Sanctum installation/config unless already present and only documented;
- OAuth;
- personal access token UI;
- mobile app auth;
- API rate limiting;
- API pagination implementation;
- API filtering/sorting implementation;
- OpenAPI/Swagger generation;
- API versioned route groups;
- performance eager loading from Phase 41.
```

Если в этой фазе появляется настоящий `/api/v1/posts` endpoint — это scope creep.
---

# 4. Critical Decisions

## 4.1. “API Readiness” means shape and architecture, not endpoints

Смысл Phase 40:

```txt
- подготовить route file;
- подготовить JSON Resources;
- зафиксировать auth/versioning decisions;
- покрыть shape тестами.
```

Неправильно:

```txt
- создать PostApiController;
- добавить Route::get('/posts');
- открыть публичный API;
- прикрутить Sanctum fully;
- писать API docs для несуществующих endpoints.
```

API endpoints надо делать отдельной фазой, когда понятно:

```txt
- кто клиенты API;
- public/private API;
- auth model;
- rate limits;
- pagination/filtering;
- response versioning;
- backward compatibility policy.
```

## 4.2. JSON Resources should be API-specific

Не использовать Filament Resources и не путать с Livewire components.

Правильное место:

```txt
app/Http/Resources/Api/PostResource.php
app/Http/Resources/Api/CommentResource.php
app/Http/Resources/Api/UserResource.php
```

Почему `Api` namespace:

```txt
- будущие public/admin/internal API resources могут отличаться;
- не смешиваем Blade/ViewModel concerns с API output;
- легче версионировать later.
```

## 4.3. Resource shape must be conservative

API resource должен отдавать только public-safe fields.

Например `UserResource` не должен отдавать:

```txt
email
role
status
is_banned
is_shadowbanned
reports_count
moderation flags
created internal IDs if public API does not need them
```

Но Phase 40 shape tests могут включить `id`, потому что для internal future API это часто нужно. Решение:

```txt
UserResource in Phase 40 = public-safe user summary.
```

Recommended public-safe `UserResource`:

```json
{
  "id": 123,
  "username": "alice",
  "display_name": "Alice Demo",
  "avatar_url": null,
  "profile_url": "/u/alice"
}
```

Do not expose email.

## 4.4. PostResource should expose published/public post fields

Recommended `PostResource` shape:

```json
{
  "id": 1,
  "title": "Demo: Homemade Italian Pasta",
  "description": "Fresh pasta...",
  "status": "published",
  "image_url": "...",
  "thumbnail_url": "...",
  "canonical_url": "...",
  "author": { ...UserResource },
  "tags": [],
  "stats": {
    "upvotes_count": 10,
    "downvotes_count": 2,
    "comments_count": 5,
    "reports_count": 0
  },
  "scores": {
    "hot_score": 0.123456
  },
  "truth": {
    "origin": "homemade",
    "cuisine": "italian"
  },
  "created_at": "2026-05-15T10:00:00+00:00",
  "published_at": "2026-05-15T10:05:00+00:00"
}
```

But be careful:

```txt
reports_count is moderation-ish. Do not expose it in public PostResource unless product wants it.
```

Recommended Phase 40 decision:

```txt
PostResource excludes reports_count.
```

Stats should include public engagement only:

```txt
upvotes_count
downvotes_count
comments_count
```

Do not expose `needs_review`, `reports_count`, `hidden_at`, `rejected_at`.

## 4.5. CommentResource should expose public-safe comment fields

Recommended `CommentResource` shape:

```json
{
  "id": 1,
  "post_id": 10,
  "body": "Looks good.",
  "author": { ...UserResource },
  "created_at": "2026-05-15T10:00:00+00:00"
}
```

Do not expose:

```txt
status if hidden/internal;
reports_count;
moderation metadata;
deleted_at;
user email.
```

If comment is hidden, future API should not return it at all.  
The resource can assume it receives a visible comment.

## 4.6. Resource tests should use arrays, not snapshots

Avoid giant JSON snapshot files for this phase.

Use:

```php
$response = (new PostResource($post))->resolve();

expect($response)->toHaveKeys([...]);
expect($response)->not->toHaveKey('reports_count');
```

Why:

```txt
- snapshots become noisy;
- shape tests should assert contract intentionally;
- easier to understand what changed.
```

## 4.7. No N+1 work in Phase 40

JSON resources can use:

```php
$this->whenLoaded('user')
$this->whenLoaded('tags')
```

Do not make resources query relationships implicitly:

```php
'author' => new UserResource($this->user)
```

if `user` is not loaded and this triggers N+1.

Correct:

```php
'author' => new UserResource($this->whenLoaded('user'))
```

or:

```php
'author' => UserResource::make($this->whenLoaded('user'))
```

Future controllers/queries will decide eager loading.  
Phase 41 handles performance basics.

## 4.8. API auth strategy is a decision doc, not implementation

RG-644 should create docs, not install auth package unless already present.

The doc should compare:

```txt
Option A: public read API without auth
Option B: Laravel Sanctum personal access tokens
Option C: session-authenticated internal API for Livewire/admin only
Option D: OAuth later
```

Recommended MVP strategy:

```txt
- Read API can start public for published content only.
- Write API must require Sanctum token auth later.
- Admin/moderation API should not be exposed in first API phase.
- Do not implement API auth in Phase 40.
```

## 4.9. API versioning note is a decision doc, not route implementation

RG-645 should document versioning decision.

Recommended:

```txt
Use /api/v1 prefix when real endpoints are introduced.
Keep resources under App\Http\Resources\Api\V1 if version-specific shape diverges later.
For Phase 40 keep resources under App\Http\Resources\Api to avoid premature version folders.
```

Alternative:

```txt
Create V1 namespace now.
```

Which is better?

For RateGuru, recommended:

```txt
Use App\Http\Resources\Api for readiness now.
Document future V1 route group.
Do not create /api/v1 endpoints yet.
```

Reason:

```txt
- no endpoints yet;
- no actual version contract yet;
- premature V1 directories create false sense of released API.
```

## 4.10. API route placeholder must not accidentally expose data

`routes/api.php` should be safe.

Acceptable:

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RateGuru API Routes
|--------------------------------------------------------------------------
|
| API endpoints are intentionally not implemented yet.
| Phase 40 only prepares the route file and JSON Resources.
| Future endpoints should use /api/v1 and explicit auth/versioning rules.
|
*/
```

Maybe add health placeholder?

Do not add `Route::get('/posts', ...)`.

If adding `/api/health`:

```txt
This is already an endpoint. Avoid unless project needs it.
```

Recommended: no endpoint.
---

# 5. Architecture Rules

## 5.1. Resource namespace

Use:

```txt
App\Http\Resources\Api\PostResource
App\Http\Resources\Api\CommentResource
App\Http\Resources\Api\UserResource
```

Not:

```txt
App\Http\Resources\PostResource
```

because Filament already uses `PostResource` naming, but in different namespace. The namespace disambiguates.

## 5.2. Avoid class name confusion with Filament resources

There will be:

```txt
App\Filament\Resources\PostResource
App\Http\Resources\Api\PostResource
```

This is acceptable but imports must be explicit.

In tests, alias if needed:

```php
use App\Http\Resources\Api\PostResource as ApiPostResource;
```

Do not import wrong class.

## 5.3. Use ISO timestamps

API resources should output timestamps consistently:

```php
$this->created_at?->toISOString()
```

or:

```php
$this->created_at?->toJSON()
```

Pick one and test it.

Recommended:

```txt
ISO-8601 string via toISOString()
```

## 5.4. URLs should reuse existing URL helpers

From Phase 32, canonical post URL helper/service likely exists.

Use it if present:

```php
app(PostUrlGenerator::class)->canonical($this->resource)
```

If not present, use route safely:

```php
Route::has('posts.show') ? route('posts.show', $this->resource) : null
```

Do not manually concatenate:

```php
config('app.url') . '/posts/' . $post->id
```

## 5.5. Do not leak hidden content

Resource tests should assert that sensitive fields are absent, but actual visibility filtering belongs to future controllers/queries.

Still, resources must not include moderation-only fields.

Examples absent:

```txt
reports_count
needs_review
hidden_at
rejected_at
moderation_reason
email
role
status for user
```

Post `status` is tricky. For a future public API, status might be unnecessary because only published posts are returned.

Recommended:

```txt
PostResource includes no `status` in public shape.
```

If internal API later needs status, create separate AdminPostResource or add conditional field.

## 5.6. JSON resources should not mutate models

No side effects:

```txt
no score recalculation;
no counter recalculation;
no relationship loading;
no logging;
no auth checks.
```

They only transform already-loaded data.
---

# 6. Suggested API Shapes

## 6.1. UserResource

```json
{
  "id": 1,
  "username": "alice",
  "display_name": "Alice Demo",
  "avatar_url": null,
  "profile_url": "https://rateguru.test/u/alice"
}
```

Absent:

```txt
email
role
status
banned fields
shadowban fields
reports_count
created_at unless intentionally public
```

## 6.2. CommentResource

```json
{
  "id": 10,
  "post_id": 1,
  "body": "Looks good.",
  "author": {
    "id": 2,
    "username": "bob",
    "display_name": "Bob Demo",
    "avatar_url": null,
    "profile_url": "https://rateguru.test/u/bob"
  },
  "created_at": "2026-05-15T10:00:00.000000Z"
}
```

Absent:

```txt
status
reports_count
deleted_at
updated_at unless needed
```

## 6.3. PostResource

```json
{
  "id": 1,
  "title": "Demo: Homemade Italian Pasta",
  "description": "Fresh pasta with tomato sauce.",
  "image_url": "https://rateguru.test/storage/demo/posts/pasta.jpg",
  "thumbnail_url": null,
  "canonical_url": "https://rateguru.test/posts/1-demo-homemade-italian-pasta",
  "author": {
    "id": 2,
    "username": "alice",
    "display_name": "Alice Demo",
    "avatar_url": null,
    "profile_url": "https://rateguru.test/u/alice"
  },
  "tags": [
    {
      "name": "Italian",
      "slug": "italian"
    }
  ],
  "stats": {
    "upvotes_count": 10,
    "downvotes_count": 2,
    "comments_count": 5
  },
  "scores": {
    "hot_score": 0.123456
  },
  "created_at": "2026-05-15T10:00:00.000000Z",
  "published_at": "2026-05-15T10:05:00.000000Z"
}
```

Absent:

```txt
reports_count
needs_review
moderation fields
hidden/rejected metadata
internal storage path if image_url can be public URL
```
---

# 7. GitFlow для Phase 40

## Base branch

Все задачи Phase 40 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-637-create-api-route-file-placeholder
feature/RG-638-create-post-resource-json-resource
feature/RG-645-add-api-versioning-note
```

## Commit format

```txt
RG-637: Create API route file placeholder
RG-638: Create PostResource JSON resource
RG-645: Add API versioning note
```

## Release branch

После выполнения `RG-637`–`RG-645`:

```txt
release/v0.2.21-phase40-api-readiness
```

## Tag

После merge release branch в `main`:

```txt
v0.2.21-phase40-api-readiness
```

Почему `v0.2.21`: Phase 39 использует `v0.2.20`, Phase 40 следующий release.
---

# 8. TDD Rules for Phase 40

## Для route placeholder

Тестировать:

```txt
- routes/api.php exists;
- app boots with API route file registered if applicable;
- no accidental /api/posts endpoint exists;
- route file contains explicit "not implemented yet" note.
```

## Для JSON Resources

Тестировать:

```txt
- resource resolves to array;
- expected public keys exist;
- nested resources shape correct;
- timestamps are strings/null;
- sensitive fields absent;
- relationships use whenLoaded behavior where practical.
```

## Для docs

Тестировать:

```txt
- API auth strategy note exists;
- note mentions public read API vs write API auth;
- note mentions Sanctum as likely write auth option, but not implemented now;
- API versioning note exists;
- note mentions future /api/v1;
- note says Phase 40 does not expose endpoints.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: API Readiness / JSON Resources / Docs
Type: Test / Feature / Resource / Route Placeholder / Docs
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым. Если это docs-only:
Docs existence/content test.

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
- Нет настоящих API endpoints
- Нет API auth implementation
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 40 Atomic Tasks
---

## RG-637 — Create API Route File Placeholder

**Area:** API Readiness / Routing  
**Type:** Route Placeholder  
**Priority:** P0  
**Branch:** `feature/RG-637-create-api-route-file-placeholder`  
**Base branch:** develop
**Depends on:** RG-636

### Goal

Создать placeholder для будущих API routes без добавления реальных endpoints.

### TDD step

File existence test:

```php
it('has api route file placeholder', function () {
    expect(file_exists(base_path('routes/api.php')))->toBeTrue();

    $content = file_get_contents(base_path('routes/api.php'));

    expect($content)->toContain('API endpoints are intentionally not implemented yet');
});
```

No accidental endpoints test:

```php
it('does not expose public api post endpoints yet', function () {
    $this->getJson('/api/posts')
        ->assertNotFound();
});
```

If Laravel returns 405/404 depending routing, assert not OK:

```php
$response = $this->getJson('/api/posts');

expect($response->status())->not->toBe(200);
```

Optional route file registration test:

```php
it('application boots with api route placeholder', function () {
    $this->get('/')->assertStatus(fn ($status) => in_array($status, [200, 302, 404]));
});
```

### Implementation

Create:

```txt
routes/api.php
```

Content:

```php
<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RateGuru API Routes
|--------------------------------------------------------------------------
|
| API endpoints are intentionally not implemented yet.
| Phase 40 only prepares the route file and JSON Resources.
|
| Future API endpoints should:
| - use /api/v1 route grouping;
| - expose published/public-safe data by default;
| - require explicit auth strategy for write endpoints;
| - never reuse Livewire/Filament resources as API contracts.
|
*/
```

If the Laravel app does not load `routes/api.php`, update routing config.

Modern Laravel app likely uses `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

But do not add API endpoints.

Add docs stub:

```txt
docs/api/README.md
```

with note:

```txt
Phase 40 prepares API readiness only. No public endpoints are available yet.
```

### Acceptance criteria

- `routes/api.php` exists.
- Route file explicitly says API endpoints are not implemented yet.
- App can load route file without errors.
- `/api/posts` is not available yet.
- No API controllers created.
- No API endpoints created.
- Tests pass.

### Definition of Done

- Tests written.
- Placeholder route file created.
- Routing config adjusted only if required.
- Tests pass.
- Коммит: `RG-637: Create API route file placeholder`

### Files likely touched

```txt
routes/api.php
bootstrap/app.php
docs/api/README.md
tests/Feature/Api/ApiRoutePlaceholderTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-638 — Create PostResource JSON Resource

**Area:** API Readiness / JSON Resources / Posts  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-638-create-post-resource-json-resource`  
**Base branch:** develop
**Depends on:** RG-637

### Goal

Создать API JSON Resource для post.

### TDD step

Skeleton test:

```php
use App\Http\Resources\Api\PostResource as ApiPostResource;

it('has api post resource', function () {
    $post = Post::factory()->published()->make();

    $resource = new ApiPostResource($post);

    expect($resource)->toBeInstanceOf(ApiPostResource::class);
});
```

Method/payload smoke:

```php
it('resolves api post resource to array', function () {
    $post = Post::factory()->published()->create();

    $data = (new ApiPostResource($post))->resolve();

    expect($data)->toBeArray();
});
```

This can pass once class exists, detailed shape comes RG-639.

### Implementation

Create:

```bash
php artisan make:resource Api/PostResource
```

Expected:

```txt
app/Http/Resources/Api/PostResource.php
```

Initial implementation:

```php
namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->publicImageUrl(),
            'thumbnail_url' => $this->thumbnail_url,
            'canonical_url' => $this->canonicalUrl(),
            'author' => UserResource::make($this->whenLoaded('user')),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()),
            'stats' => [
                'upvotes_count' => (int) $this->upvotes_count,
                'downvotes_count' => (int) $this->downvotes_count,
                'comments_count' => (int) $this->comments_count,
            ],
            'scores' => [
                'hot_score' => (float) $this->hot_score,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
        ];
    }

    private function canonicalUrl(): ?string
    {
        return route('posts.show', $this->resource, absolute: true);
    }

    private function publicImageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return asset('storage/' . ltrim($this->image_path, '/'));
    }
}
```

Adapt fields to actual schema.

Important:

```txt
- do not include reports_count;
- do not include needs_review;
- do not include hidden/rejected metadata;
- do not query relationships implicitly.
```

If `UserResource` does not exist yet, either:

```txt
- temporarily return null/whenLoaded placeholder;
- or create minimal UserResource in RG-642 only.
```

Because RG-638 comes before RG-642, avoid hard dependency that breaks tests. Options:

```php
'author' => $this->whenLoaded('user', fn () => [
    'id' => $this->user->id,
    'username' => $this->user->username,
])
```

But later RG-642 should refactor to `UserResource`.

Recommended: create PostResource with `author` closure returning minimal array for now, then RG-642 can introduce UserResource and update.

### Acceptance criteria

- `App\Http\Resources\Api\PostResource` exists.
- Resource resolves to array.
- Resource uses public-safe fields.
- No moderation-only fields included.
- No endpoint/controller created.
- Tests pass.

### Definition of Done

- Tests written.
- Resource created.
- Minimal safe payload implemented.
- Tests pass.
- Коммит: `RG-638: Create PostResource JSON resource`

### Files likely touched

```txt
app/Http/Resources/Api/PostResource.php
tests/Unit/Http/Resources/Api/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-639 — Test PostResource Shape

**Area:** API Readiness / JSON Resources / Tests  
**Type:** Test + Resource Shape  
**Priority:** P0  
**Branch:** `feature/RG-639-test-post-resource-shape`  
**Base branch:** develop
**Depends on:** RG-638

### Goal

Жёстко зафиксировать public-safe shape `PostResource`.

### TDD step

Shape test:

```php
use App\Http\Resources\Api\PostResource as ApiPostResource;

it('returns expected api post resource shape', function () {
    $author = User::factory()->create([
        'username' => 'alice',
        'name' => 'Alice Demo',
        'email' => 'alice@example.test',
    ]);

    $tag = Tag::factory()->create([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);

    $post = Post::factory()
        ->for($author, 'user')
        ->published()
        ->create([
            'title' => 'API Shape Post',
            'description' => 'Description for API shape test.',
            'upvotes_count' => 10,
            'downvotes_count' => 2,
            'comments_count' => 5,
            'reports_count' => 99,
            'hot_score' => 0.123456,
        ]);

    $post->tags()->attach($tag);

    $data = (new ApiPostResource($post->load(['user', 'tags'])))->resolve();

    expect($data)->toHaveKeys([
        'id',
        'title',
        'description',
        'image_url',
        'thumbnail_url',
        'canonical_url',
        'author',
        'tags',
        'stats',
        'scores',
        'created_at',
        'published_at',
    ]);

    expect($data['stats'])->toMatchArray([
        'upvotes_count' => 10,
        'downvotes_count' => 2,
        'comments_count' => 5,
    ]);

    expect($data['scores'])->toHaveKey('hot_score');
    expect($data['tags'][0])->toMatchArray([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);

    expect($data)->not->toHaveKey('reports_count');
    expect($data)->not->toHaveKey('needs_review');
    expect($data)->not->toHaveKey('hidden_at');
    expect($data['author'])->not->toHaveKey('email');
});
```

Relation safety test:

```php
it('does not force-load author and tags in post resource', function () {
    $post = Post::factory()->published()->create();

    $data = (new ApiPostResource($post))->resolve();

    expect($data)->toHaveKey('author');
    expect($data)->toHaveKey('tags');
});
```

The exact expected value for unloaded relationship can be `null`, missing value, or empty array depending implementation. Pick one and test it.

Recommended:

```txt
author = null when not loaded
tags = [] or missing when not loaded
```

### Implementation

Adjust `PostResource` until tests pass.

Recommended final behavior:

```php
'author' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(...)->values(), []),
```

If `UserResource` not created yet, use minimal closure now and refactor in RG-642.

For canonical URL:

```php
'canonical_url' => Route::has('posts.show')
    ? route('posts.show', $this->resource, absolute: true)
    : null,
```

Avoid test fragility if route differs.

### Acceptance criteria

- PostResource shape test exists.
- Expected public keys are present.
- Engagement stats present.
- Hot score present.
- Tags shape stable.
- Author does not expose email.
- Moderation fields absent.
- Resource does not create endpoint.
- Tests pass.

### Definition of Done

- Shape tests written.
- Resource adjusted.
- Sensitive fields excluded.
- Tests pass.
- Коммит: `RG-639: Test PostResource shape`

### Files likely touched

```txt
app/Http/Resources/Api/PostResource.php
tests/Unit/Http/Resources/Api/PostResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-640 — Create CommentResource JSON Resource

**Area:** API Readiness / JSON Resources / Comments  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-640-create-comment-resource-json-resource`  
**Base branch:** develop
**Depends on:** RG-639

### Goal

Создать API JSON Resource для comment.

### TDD step

Skeleton test:

```php
use App\Http\Resources\Api\CommentResource as ApiCommentResource;

it('has api comment resource', function () {
    $comment = Comment::factory()->make();

    $resource = new ApiCommentResource($comment);

    expect($resource)->toBeInstanceOf(ApiCommentResource::class);
});
```

Smoke test:

```php
it('resolves api comment resource to array', function () {
    $comment = Comment::factory()->create();

    $data = (new ApiCommentResource($comment))->resolve();

    expect($data)->toBeArray();
});
```

### Implementation

Create:

```bash
php artisan make:resource Api/CommentResource
```

Expected:

```txt
app/Http/Resources/Api/CommentResource.php
```

Initial implementation:

```php
namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'body' => $this->body,
            'author' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'display_name' => $this->user->name,
                'avatar_url' => $this->user->avatar_url,
                'profile_url' => route('profile.show', ['username' => $this->user->username], absolute: true),
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

Later RG-642 can replace author array with `UserResource`.

Do not include:

```txt
status
reports_count
deleted_at
updated_at
moderation metadata
```

### Acceptance criteria

- `App\Http\Resources\Api\CommentResource` exists.
- Resource resolves to array.
- Resource uses public-safe fields.
- No moderation-only fields included.
- No endpoint/controller created.
- Tests pass.

### Definition of Done

- Tests written.
- Resource created.
- Minimal safe payload implemented.
- Tests pass.
- Коммит: `RG-640: Create CommentResource JSON resource`

### Files likely touched

```txt
app/Http/Resources/Api/CommentResource.php
tests/Unit/Http/Resources/Api/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-641 — Test CommentResource Shape

**Area:** API Readiness / JSON Resources / Tests  
**Type:** Test + Resource Shape  
**Priority:** P0  
**Branch:** `feature/RG-641-test-comment-resource-shape`  
**Base branch:** develop
**Depends on:** RG-640

### Goal

Жёстко зафиксировать public-safe shape `CommentResource`.

### TDD step

Shape test:

```php
use App\Http\Resources\Api\CommentResource as ApiCommentResource;

it('returns expected api comment resource shape', function () {
    $author = User::factory()->create([
        'username' => 'bob',
        'name' => 'Bob Demo',
        'email' => 'bob@example.test',
    ]);

    $post = Post::factory()->published()->create();

    $comment = Comment::factory()
        ->for($post)
        ->for($author, 'user')
        ->create([
            'body' => 'API comment body.',
            'reports_count' => 7,
        ]);

    $data = (new ApiCommentResource($comment->load('user')))->resolve();

    expect($data)->toHaveKeys([
        'id',
        'post_id',
        'body',
        'author',
        'created_at',
    ]);

    expect($data['post_id'])->toBe($post->id);
    expect($data['body'])->toBe('API comment body.');
    expect($data['author']['username'])->toBe('bob');

    expect($data)->not->toHaveKey('status');
    expect($data)->not->toHaveKey('reports_count');
    expect($data)->not->toHaveKey('deleted_at');
    expect($data['author'])->not->toHaveKey('email');
});
```

Unloaded author test:

```php
it('does not force-load author in comment resource', function () {
    $comment = Comment::factory()->create();

    $data = (new ApiCommentResource($comment))->resolve();

    expect($data)->toHaveKey('author');
});
```

Decide expected unloaded value:

```txt
author = null when user not loaded
```

### Implementation

Adjust `CommentResource` until tests pass.

Recommended:

```php
'author' => $this->whenLoaded('user', fn () => UserResource::make($this->user), null),
```

If `UserResource` not available yet, keep safe array until RG-642.

### Acceptance criteria

- CommentResource shape test exists.
- Expected public keys are present.
- Author shape public-safe.
- Email absent.
- status/reports_count/deleted_at absent.
- Resource does not create endpoint.
- Tests pass.

### Definition of Done

- Shape tests written.
- Resource adjusted.
- Sensitive fields excluded.
- Tests pass.
- Коммит: `RG-641: Test CommentResource shape`

### Files likely touched

```txt
app/Http/Resources/Api/CommentResource.php
tests/Unit/Http/Resources/Api/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-642 — Create UserResource JSON Resource

**Area:** API Readiness / JSON Resources / Users  
**Type:** Resource  
**Priority:** P0  
**Branch:** `feature/RG-642-create-user-resource-json-resource`  
**Base branch:** develop
**Depends on:** RG-641

### Goal

Создать API JSON Resource для public-safe user summary.

### TDD step

Skeleton test:

```php
use App\Http\Resources\Api\UserResource as ApiUserResource;

it('has api user resource', function () {
    $user = User::factory()->make();

    $resource = new ApiUserResource($user);

    expect($resource)->toBeInstanceOf(ApiUserResource::class);
});
```

Smoke test:

```php
it('resolves api user resource to array', function () {
    $user = User::factory()->create();

    $data = (new ApiUserResource($user))->resolve();

    expect($data)->toBeArray();
});
```

### Implementation

Create:

```bash
php artisan make:resource Api/UserResource
```

Expected:

```txt
app/Http/Resources/Api/UserResource.php
```

Implementation:

```php
namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'profile_url' => $this->profileUrl(),
        ];
    }

    private function profileUrl(): ?string
    {
        if (! $this->username || ! Route::has('profile.show')) {
            return null;
        }

        return route('profile.show', ['username' => $this->username], absolute: true);
    }
}
```

Then refactor:

```txt
PostResource author → UserResource
CommentResource author → UserResource
```

Use `whenLoaded`.

### Acceptance criteria

- `App\Http\Resources\Api\UserResource` exists.
- Resource resolves to array.
- Resource includes public-safe identity fields.
- No email/role/status exposed.
- PostResource/CommentResource can reuse UserResource.
- No endpoint/controller created.
- Tests pass.

### Definition of Done

- Tests written.
- UserResource created.
- Post/Comment resources refactored if safe.
- Tests pass.
- Коммит: `RG-642: Create UserResource JSON resource`

### Files likely touched

```txt
app/Http/Resources/Api/UserResource.php
app/Http/Resources/Api/PostResource.php
app/Http/Resources/Api/CommentResource.php
tests/Unit/Http/Resources/Api/UserResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-643 — Test UserResource Shape

**Area:** API Readiness / JSON Resources / Tests  
**Type:** Test + Resource Shape  
**Priority:** P0  
**Branch:** `feature/RG-643-test-user-resource-shape`  
**Base branch:** develop
**Depends on:** RG-642

### Goal

Жёстко зафиксировать public-safe shape `UserResource`.

### TDD step

Shape test:

```php
use App\Http\Resources\Api\UserResource as ApiUserResource;

it('returns expected api user resource shape', function () {
    $user = User::factory()->create([
        'username' => 'alice',
        'name' => 'Alice Demo',
        'email' => 'alice@example.test',
        'role' => UserRole::Admin,
        'status' => UserStatus::Banned,
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    $data = (new ApiUserResource($user))->resolve();

    expect($data)->toHaveKeys([
        'id',
        'username',
        'display_name',
        'avatar_url',
        'profile_url',
    ]);

    expect($data)->toMatchArray([
        'id' => $user->id,
        'username' => 'alice',
        'display_name' => 'Alice Demo',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    expect($data)->not->toHaveKey('email');
    expect($data)->not->toHaveKey('role');
    expect($data)->not->toHaveKey('status');
    expect($data)->not->toHaveKey('reports_count');
});
```

Profile URL test:

```php
it('includes public profile url when username is available', function () {
    $user = User::factory()->create([
        'username' => 'alice',
    ]);

    $data = (new ApiUserResource($user))->resolve();

    expect($data['profile_url'])->toContain('/u/alice');
});
```

Nested resources consistency test:

```php
it('uses user resource shape for post and comment authors', function () {
    ...
});
```

### Implementation

Adjust `UserResource`, `PostResource`, `CommentResource`.

PostResource:

```php
'author' => UserResource::make($this->whenLoaded('user')),
```

CommentResource:

```php
'author' => UserResource::make($this->whenLoaded('user')),
```

But if `whenLoaded` returns MissingValue, ensure resolved output is predictable.

Recommended:

```php
'author' => $this->whenLoaded('user', fn () => UserResource::make($this->user), null),
```

### Acceptance criteria

- UserResource shape test exists.
- id/username/display_name/avatar_url/profile_url present.
- email absent.
- role/status absent.
- moderation-related fields absent.
- PostResource and CommentResource nested author shape is consistent.
- Tests pass.

### Definition of Done

- Shape tests written.
- Resource adjusted.
- Nested resources refactored.
- Sensitive fields excluded.
- Tests pass.
- Коммит: `RG-643: Test UserResource shape`

### Files likely touched

```txt
app/Http/Resources/Api/UserResource.php
app/Http/Resources/Api/PostResource.php
app/Http/Resources/Api/CommentResource.php
tests/Unit/Http/Resources/Api/UserResourceTest.php
tests/Unit/Http/Resources/Api/PostResourceTest.php
tests/Unit/Http/Resources/Api/CommentResourceTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-644 — Add API Auth Strategy Note

**Area:** API Readiness / Docs / Auth  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-644-add-api-auth-strategy-note`  
**Base branch:** develop
**Depends on:** RG-643

### Goal

Добавить документ с API auth strategy.

### TDD step

Docs existence/content test:

```php
it('has api auth strategy note', function () {
    $path = base_path('docs/api/auth-strategy.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('API Auth Strategy');
    expect($content)->toContain('public read');
    expect($content)->toContain('write endpoints');
    expect($content)->toContain('Sanctum');
    expect($content)->toContain('not implemented in Phase 40');
});
```

### Implementation

Create:

```txt
docs/api/auth-strategy.md
```

Recommended content:

```md
# RateGuru API Auth Strategy

## Phase 40 status

Phase 40 does not implement API auth and does not expose public endpoints.

## Future API categories

### Public read API

Possible future endpoints:

- published posts
- visible comments
- public user profiles
- tags

Auth:

- no auth required
- only published/public-safe data
- rate limited later

### Authenticated write API

Possible future endpoints:

- create post
- vote
- comment
- report content

Auth:

- Laravel Sanctum personal access tokens are the preferred first option
- session auth may be used only for internal app requests
- OAuth is not needed for MVP

### Admin/moderation API

Not part of first public API phase.

Rules:

- do not expose moderation endpoints until policies/actions are stable
- require admin/moderator auth
- require audit logs
- require strict rate limits
```

Include explicit decisions:

```txt
- No API auth implementation in Phase 40.
- Do not install/configure new auth package in this phase unless already present.
- Future write API must call existing Actions.
- Future API must not bypass Policies/Actions.
```

Update:

```txt
docs/api/README.md
```

with link.

### Acceptance criteria

- `docs/api/auth-strategy.md` exists.
- Document distinguishes public read API vs authenticated write API.
- Document mentions Sanctum as preferred future write auth option.
- Document explicitly says auth is not implemented in Phase 40.
- Document warns not to expose moderation API early.
- Docs test passes.

### Definition of Done

- Docs test written.
- Auth strategy doc added.
- API README linked.
- Tests pass.
- Коммит: `RG-644: Add API auth strategy note`

### Files likely touched

```txt
docs/api/auth-strategy.md
docs/api/README.md
tests/Feature/Docs/ApiDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-645 — Add API Versioning Note

**Area:** API Readiness / Docs / Versioning  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-645-add-api-versioning-note`  
**Base branch:** develop
**Depends on:** RG-644

### Goal

Добавить документ с API versioning strategy.

### TDD step

Docs existence/content test:

```php
it('has api versioning note', function () {
    $path = base_path('docs/api/versioning.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('API Versioning');
    expect($content)->toContain('/api/v1');
    expect($content)->toContain('backward compatibility');
    expect($content)->toContain('not implemented in Phase 40');
});
```

API README link test:

```php
it('links api readiness docs from api readme', function () {
    $content = file_get_contents(base_path('docs/api/README.md'));

    expect($content)->toContain('auth-strategy.md');
    expect($content)->toContain('versioning.md');
});
```

### Implementation

Create:

```txt
docs/api/versioning.md
```

Recommended content:

```md
# RateGuru API Versioning

## Phase 40 status

Phase 40 does not expose versioned endpoints.

## Future route structure

When real API endpoints are introduced, use:

```txt
/api/v1/...
```

Example future route groups:

```php
Route::prefix('v1')
    ->name('api.v1.')
    ->group(function () {
        // future endpoints
    });
```

## Compatibility rules

- Do not remove fields from v1 responses without a new version.
- Adding nullable fields is usually allowed.
- Changing field meaning requires new version.
- Changing auth requirements requires explicit migration note.
- Write endpoints must be versioned from the first release.

## Resources

Phase 40 resources live under:

```txt
App\Http\Resources\Api
```

If/when API v1 is formally released and needs stable contracts, move or alias to:

```txt
App\Http\Resources\Api\V1
```

## Not implemented in Phase 40

- no /api/v1 endpoints
- no API controllers
- no OpenAPI spec
- no version negotiation
```

Update `docs/api/README.md`:

```md
# RateGuru API Readiness

Phase 40 prepares API resources and strategy docs only. No public API endpoints are implemented yet.

- [Auth strategy](auth-strategy.md)
- [Versioning strategy](versioning.md)
```

Add final review doc:

```txt
docs/api/phase-40-api-readiness-review.md
```

Checklist:

```txt
- routes/api.php exists;
- no public endpoints exposed;
- PostResource shape tested;
- CommentResource shape tested;
- UserResource shape tested;
- sensitive fields excluded;
- auth strategy documented;
- versioning strategy documented.
```

### Acceptance criteria

- `docs/api/versioning.md` exists.
- Document mentions future `/api/v1`.
- Document explains compatibility rules.
- Document says versioned endpoints are not implemented in Phase 40.
- API README links auth and versioning docs.
- Phase 40 review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Docs tests written.
- Versioning doc added.
- API README updated.
- Review doc added.
- Tests/build pass.
- Коммит: `RG-645: Add API versioning note`

### Files likely touched

```txt
docs/api/versioning.md
docs/api/README.md
docs/api/phase-40-api-readiness-review.md
tests/Feature/Docs/ApiDocsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 11. Phase 40 Completion Criteria

Phase 40 завершена, когда:

```txt
- RG-637–RG-645 выполнены;
- routes/api.php exists;
- API route placeholder explicitly says endpoints are not implemented yet;
- app loads API route placeholder without errors;
- no public API endpoints were added;
- /api/posts is not available yet;
- App\Http\Resources\Api\PostResource exists;
- PostResource shape is tested;
- PostResource excludes moderation-only fields;
- App\Http\Resources\Api\CommentResource exists;
- CommentResource shape is tested;
- CommentResource excludes moderation-only fields;
- App\Http\Resources\Api\UserResource exists;
- UserResource shape is tested;
- UserResource excludes email/role/status;
- nested author resources are public-safe;
- docs/api/auth-strategy.md exists;
- docs/api/versioning.md exists;
- docs/api/README.md links API readiness docs;
- docs/api/phase-40-api-readiness-review.md exists;
- no API auth implementation was added;
- no API controllers were added;
- no API versioned route group was exposed;
- no Phase 41 performance tasks were added;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 40

Без отдельной задачи нельзя:

```txt
- добавлять Route::get('/api/posts');
- создавать PostApiController;
- создавать CommentApiController;
- создавать UserApiController;
- добавлять write API endpoints;
- устанавливать/настраивать Sanctum как обязательную часть;
- добавлять token management UI;
- добавлять OAuth;
- добавлять OpenAPI/Swagger;
- добавлять API pagination/filtering/sorting;
- добавлять API rate limiting;
- делать eager loading performance fixes из Phase 41;
- добавлять cache placeholders из Phase 41;
- менять Livewire UI;
- менять Filament resources;
- добавлять migrations;
- добавлять React/Vue/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-637 Create API route file placeholder
RG-638 Create PostResource JSON resource
RG-639 Test PostResource shape
RG-640 Create CommentResource JSON resource
RG-641 Test CommentResource shape
RG-642 Create UserResource JSON resource
RG-643 Test UserResource shape
RG-644 Add API auth strategy note
RG-645 Add API versioning note
```
---

# 14. Release

После завершения Phase 40:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
composer test:browser
php artisan visual:screenshot all

git checkout -b release/v0.2.21-phase40-api-readiness
git push -u origin release/v0.2.21-phase40-api-readiness
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

git tag -a v0.2.21-phase40-api-readiness -m "RateGuru Phase 40 API Readiness"
git push origin v0.2.21-phase40-api-readiness
```
