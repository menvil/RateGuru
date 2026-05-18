# RateGuru — Phase 3 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 3 — Core Database Schema**  
Диапазон задач: **RG-056 → RG-090**  
Основа нумерации: исходный atomic backlog, где Phase 3 начинается с задачи 056 и заканчивается задачей 090.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 3 соответствует исходному блоку:

```txt
Phase 3 — Core Database Schema
```

Правильный диапазон Phase 3:

```txt
RG-056 — Add PostStatus enum
RG-057 — Add VoteType enum
RG-058 — Add OriginType enum
RG-059 — Add CuisineType enum
RG-060 — Add ReportReason enum
RG-061 — Create posts migration
RG-062 — Create comments migration
RG-063 — Create post_votes migration
RG-064 — Create origin_votes migration
RG-065 — Create cuisine_votes migration
RG-066 — Create tags migration
RG-067 — Create post_tag migration
RG-068 — Create reports migration
RG-069 — Create moderation_logs migration
RG-070 — Add indexes to posts table
RG-071 — Add unique index for post_votes user/post
RG-072 — Add unique index for origin_votes user/post
RG-073 — Add unique index for cuisine_votes user/post
RG-074 — Add Post model
RG-075 — Add Comment model
RG-076 — Add PostVote model
RG-077 — Add OriginVote model
RG-078 — Add CuisineVote model
RG-079 — Add Tag model
RG-080 — Add Report model
RG-081 — Add ModerationLog model
RG-082 — Add User hasMany posts relationship
RG-083 — Add Post belongsTo user relationship
RG-084 — Add Post hasMany comments relationship
RG-085 — Add Post hasMany postVotes relationship
RG-086 — Add Post hasMany originVotes relationship
RG-087 — Add Post hasMany cuisineVotes relationship
RG-088 — Add Post belongsToMany tags relationship
RG-089 — Add Comment belongsTo user relationship
RG-090 — Add Comment belongsTo post relationship
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 3

Phase 3 создаёт **ядро схемы данных** RateGuru.

После Phase 3 должны существовать:

```txt
- enums для статусов и типов голосований;
- таблицы posts/comments/votes/tags/reports/moderation_logs;
- индексы и уникальные ограничения;
- базовые Eloquent models;
- базовые relationships между User, Post, Comment, Vote, Tag, Report;
- тесты, подтверждающие схему и связи.
```

Эта фаза не должна реализовывать бизнес-поведение.  
Она только создаёт структуру, на которую позже лягут factories, scopes, actions, UI и админка.

---

# 3. Scope Phase 3

## Входит

```txt
- enum classes;
- database migrations;
- basic model classes;
- basic casts/fillable/guarded;
- schema tests;
- model relationship tests;
- indexes;
- unique constraints.
```

## Не входит

```txt
- factories для Post/Comment/Vote/Report/Tag;
- model scopes;
- seeders;
- CreatePostAction;
- FeedQuery;
- upload;
- image storage;
- voting actions;
- comments actions;
- moderation actions;
- Filament resources;
- Livewire components;
- UI screens;
- API resources.
```

Factories и scopes начинаются в **Phase 4**.  
Post creation backend начинается в **Phase 5**.

---

# 4. Database Design Decisions

## 4.1. SQLite-first

На старте используем SQLite.  
Поэтому миграции должны быть совместимы с SQLite.

Запрещено в Phase 3:

```txt
- PostgreSQL-only column types;
- database-specific CHECK constraints, если они ломают SQLite;
- JSONB;
- partial indexes;
- generated columns.
```

Если нужна проверка enum values — на старте делаем её на уровне PHP enum/cast/validation, не DB CHECK.

## 4.2. Soft deletes

В Phase 3 можно добавить `softDeletes()` для сущностей, где физическое удаление нежелательно:

```txt
posts
comments
```

Для votes soft delete не нужен на старте. Там лучше обновлять/удалять строку.

## 4.3. Counters on posts

В `posts` храним агрегаты:

```txt
upvotes_count
downvotes_count
homemade_votes_count
restaurant_votes_count
comments_count
reports_count
hot_score
```

Но источник истины — отдельные таблицы голосов и комментариев.  
Counters нужны для быстрой ленты и сортировки.

## 4.4. Morph reports

`reports` лучше делать polymorphic:

```txt
target_type
target_id
```

Так можно жаловаться на:

```txt
post
comment
user
```

На Phase 3 мы создаём схему, но не пишем ReportContentAction.

## 4.5. Moderation logs

`moderation_logs` тоже лучше polymorphic:

```txt
target_type
target_id
```

Так можно логировать действия над post/comment/user/report.

---

# 5. GitFlow для Phase 3

## Base branch

Все задачи Phase 3 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-056-add-post-status-enum
feature/RG-061-create-posts-migration
feature/RG-074-add-post-model
```

## Commit format

```txt
RG-056: Add PostStatus enum
RG-061: Create posts migration
RG-074: Add Post model
```

## Release branch

После выполнения `RG-056`–`RG-090`:

```txt
release/v0.0.4-phase3-core-database-schema
```

## Tag

После merge release branch в `main`:

```txt
v0.0.4-phase3-core-database-schema
```

---

# 6. TDD Rules for Phase 3

## Для enum задач

Сначала unit test:

```txt
- enum contains expected cases;
- enum values are stable strings.
```

## Для migration задач

Сначала schema test:

```txt
- table exists;
- expected columns exist;
- expected defaults are correct where testable;
- model can persist minimal record if model already exists.
```

Если model ещё не создан — schema test достаточно.

## Для index задач

Пишем тест на constraint behavior:

```txt
- duplicate user/post vote cannot be inserted;
- duplicate origin vote cannot be inserted;
- duplicate cuisine vote cannot be inserted.
```

Для простых indexes без уникальности достаточно schema/migration inspection или acceptance через migration code review.

## Для model задач

Сначала model test:

```txt
- model class exists;
- model table is correct;
- casts work if added.
```

## Для relationship задач

Сначала relationship test:

```txt
- relation method returns correct Relation class;
- related records can be created and retrieved.
```

Так как factories для Post/Comment ещё в Phase 4, в Phase 3 relationship tests могут создавать записи вручную через `Model::create()` или `DB::table()->insert()`.

---

# 7. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: DB / Backend / Tests
Type: Test / Feature / Migration / Model / Config
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
- Все связанные тесты проходят
- Нет бизнес-логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 8. Phase 3 Atomic Tasks

---

## RG-056 — Add PostStatus Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-056-add-post-status-enum`  
**Depends on:** RG-055

### Goal

Добавить enum статусов поста.

### TDD step

Сначала unit test:

```php
it('contains expected post statuses', function () {
    expect(PostStatus::Draft->value)->toBe('draft');
    expect(PostStatus::Pending->value)->toBe('pending');
    expect(PostStatus::Published->value)->toBe('published');
    expect(PostStatus::Hidden->value)->toBe('hidden');
    expect(PostStatus::Rejected->value)->toBe('rejected');
    expect(PostStatus::Deleted->value)->toBe('deleted');
});
```

### Implementation

Создать:

```txt
app/Enums/PostStatus.php
```

Содержимое:

```php
enum PostStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
    case Deleted = 'deleted';
}
```

Не добавлять state machine в Phase 3.

### Acceptance criteria

- `PostStatus` enum существует.
- Есть все ожидаемые значения.
- Значения — стабильные строки.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- Enum создан.
- Тест проходит.
- Коммит: `RG-056: Add PostStatus enum`

### Files likely touched

```txt
app/Enums/PostStatus.php
tests/Unit/Enums/PostStatusTest.php
```

---

## RG-057 — Add VoteType Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-057-add-vote-type-enum`  
**Depends on:** RG-055

### Goal

Добавить enum для up/down vote.

### TDD step

Unit test:

```php
it('contains expected vote types', function () {
    expect(VoteType::Up->value)->toBe('up');
    expect(VoteType::Down->value)->toBe('down');
});
```

### Implementation

Создать:

```txt
app/Enums/VoteType.php
```

```php
enum VoteType: string
{
    case Up = 'up';
    case Down = 'down';
}
```

### Acceptance criteria

- `VoteType` enum существует.
- Есть `up` и `down`.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- Enum создан.
- Тест проходит.
- Коммит: `RG-057: Add VoteType enum`

### Files likely touched

```txt
app/Enums/VoteType.php
tests/Unit/Enums/VoteTypeTest.php
```

---

## RG-058 — Add OriginType Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-058-add-origin-type-enum`  
**Depends on:** RG-055

### Goal

Добавить enum для голосования Homemade/Restaurant.

### TDD step

Unit test:

```php
it('contains expected origin types', function () {
    expect(OriginType::Homemade->value)->toBe('homemade');
    expect(OriginType::Restaurant->value)->toBe('restaurant');
    expect(OriginType::Unknown->value)->toBe('unknown');
});
```

### Implementation

Создать:

```txt
app/Enums/OriginType.php
```

```php
enum OriginType: string
{
    case Homemade = 'homemade';
    case Restaurant = 'restaurant';
    case Unknown = 'unknown';
}
```

`Unknown` нужен для `origin_truth`, если автор не хочет раскрывать правду.

### Acceptance criteria

- `OriginType` enum существует.
- Есть `homemade`, `restaurant`, `unknown`.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- Enum создан.
- Тест проходит.
- Коммит: `RG-058: Add OriginType enum`

### Files likely touched

```txt
app/Enums/OriginType.php
tests/Unit/Enums/OriginTypeTest.php
```

---

## RG-059 — Add CuisineType Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-059-add-cuisine-type-enum`  
**Depends on:** RG-055

### Goal

Добавить enum для cuisine voting.

### TDD step

Unit test:

```php
it('contains expected cuisine types', function () {
    expect(CuisineType::Italian->value)->toBe('italian');
    expect(CuisineType::Asian->value)->toBe('asian');
    expect(CuisineType::American->value)->toBe('american');
    expect(CuisineType::Mexican->value)->toBe('mexican');
    expect(CuisineType::Other->value)->toBe('other');
    expect(CuisineType::Unknown->value)->toBe('unknown');
});
```

### Implementation

Создать:

```txt
app/Enums/CuisineType.php
```

```php
enum CuisineType: string
{
    case Italian = 'italian';
    case Asian = 'asian';
    case American = 'american';
    case Mexican = 'mexican';
    case Other = 'other';
    case Unknown = 'unknown';
}
```

Не делать справочник кухонь в БД на Phase 3. Enum достаточно для MVP.

### Acceptance criteria

- `CuisineType` enum существует.
- Есть ожидаемые значения.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- Enum создан.
- Тест проходит.
- Коммит: `RG-059: Add CuisineType enum`

### Files likely touched

```txt
app/Enums/CuisineType.php
tests/Unit/Enums/CuisineTypeTest.php
```

---

## RG-060 — Add ReportReason Enum

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-060-add-report-reason-enum`  
**Depends on:** RG-055

### Goal

Добавить enum причин жалоб.

### TDD step

Unit test:

```php
it('contains expected report reasons', function () {
    expect(ReportReason::Spam->value)->toBe('spam');
    expect(ReportReason::Offensive->value)->toBe('offensive');
    expect(ReportReason::Fake->value)->toBe('fake');
    expect(ReportReason::Copyright->value)->toBe('copyright');
    expect(ReportReason::NotFood->value)->toBe('not_food');
    expect(ReportReason::Other->value)->toBe('other');
});
```

### Implementation

Создать:

```txt
app/Enums/ReportReason.php
```

```php
enum ReportReason: string
{
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Fake = 'fake';
    case Copyright = 'copyright';
    case NotFood = 'not_food';
    case Other = 'other';
}
```

### Acceptance criteria

- `ReportReason` enum существует.
- Есть все ожидаемые значения.
- Unit test проходит.

### Definition of Done

- Тест написан первым.
- Enum создан.
- Тест проходит.
- Коммит: `RG-060: Add ReportReason enum`

### Files likely touched

```txt
app/Enums/ReportReason.php
tests/Unit/Enums/ReportReasonTest.php
```

---

## RG-061 — Create Posts Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-061-create-posts-migration`  
**Depends on:** RG-056, RG-058, RG-059

### Goal

Создать таблицу `posts`.

### TDD step

Schema test:

```php
it('creates posts table with required columns', function () {
    expect(Schema::hasTable('posts'))->toBeTrue();
    expect(Schema::hasColumns('posts', [
        'id',
        'user_id',
        'title',
        'description',
        'image_path',
        'image_url',
        'thumbnail_url',
        'source_url',
        'status',
        'origin_truth',
        'cuisine_truth',
        'upvotes_count',
        'downvotes_count',
        'homemade_votes_count',
        'restaurant_votes_count',
        'comments_count',
        'reports_count',
        'hot_score',
        'published_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_posts_table
```

Колонки:

```php
$table->id();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

$table->string('title');
$table->text('description')->nullable();

$table->string('image_path')->nullable();
$table->string('image_url')->nullable();
$table->string('thumbnail_url')->nullable();

$table->string('source_url')->nullable();

$table->string('status')->default(PostStatus::Pending->value);
$table->string('origin_truth')->default(OriginType::Unknown->value);
$table->string('cuisine_truth')->default(CuisineType::Unknown->value);

$table->unsignedInteger('upvotes_count')->default(0);
$table->unsignedInteger('downvotes_count')->default(0);
$table->unsignedInteger('homemade_votes_count')->default(0);
$table->unsignedInteger('restaurant_votes_count')->default(0);
$table->unsignedInteger('comments_count')->default(0);
$table->unsignedInteger('reports_count')->default(0);

$table->float('hot_score')->default(0);

$table->timestamp('published_at')->nullable();
$table->timestamps();
$table->softDeletes();
```

### Acceptance criteria

- Таблица `posts` создаётся.
- Все ключевые колонки есть.
- `user_id` связан с `users`.
- Counters имеют default 0.
- `status` имеет default pending.
- `origin_truth` default unknown.
- `cuisine_truth` default unknown.
- Soft deletes включены.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- `php artisan migrate:fresh --env=testing` проходит через тесты.
- Коммит: `RG-061: Create posts migration`

### Files likely touched

```txt
database/migrations/*_create_posts_table.php
tests/Feature/Database/PostsTableTest.php
```

---

## RG-062 — Create Comments Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-062-create-comments-migration`  
**Depends on:** RG-061

### Goal

Создать таблицу `comments`.

### TDD step

Schema test:

```php
it('creates comments table with required columns', function () {
    expect(Schema::hasTable('comments'))->toBeTrue();
    expect(Schema::hasColumns('comments', [
        'id',
        'post_id',
        'user_id',
        'body',
        'status',
        'reports_count',
        'created_at',
        'updated_at',
        'deleted_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_comments_table
```

Колонки:

```php
$table->id();
$table->foreignId('post_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->text('body');
$table->string('status')->default('published');
$table->unsignedInteger('reports_count')->default(0);
$table->timestamps();
$table->softDeletes();
```

Не создавать `CommentStatus` enum в Phase 3, потому что исходный backlog его не содержит.  
Если потребуется — отдельная будущая задача.

### Acceptance criteria

- Таблица `comments` создаётся.
- Есть `post_id`, `user_id`, `body`, `status`, `reports_count`.
- Foreign keys существуют.
- Soft deletes включены.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-062: Create comments migration`

### Files likely touched

```txt
database/migrations/*_create_comments_table.php
tests/Feature/Database/CommentsTableTest.php
```

---

## RG-063 — Create Post Votes Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-063-create-post-votes-migration`  
**Depends on:** RG-057, RG-061

### Goal

Создать таблицу `post_votes` для up/down votes.

### TDD step

Schema test:

```php
it('creates post_votes table with required columns', function () {
    expect(Schema::hasTable('post_votes'))->toBeTrue();
    expect(Schema::hasColumns('post_votes', [
        'id',
        'post_id',
        'user_id',
        'type',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_post_votes_table
```

Колонки:

```php
$table->id();
$table->foreignId('post_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->string('type');
$table->timestamps();
```

Unique constraint будет добавлен в `RG-071`, чтобы задача оставалась атомарной.

### Acceptance criteria

- Таблица `post_votes` создаётся.
- Есть `post_id`, `user_id`, `type`.
- Foreign keys существуют.
- Unique user/post ещё не обязателен в этой задаче.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-063: Create post votes migration`

### Files likely touched

```txt
database/migrations/*_create_post_votes_table.php
tests/Feature/Database/PostVotesTableTest.php
```

---

## RG-064 — Create Origin Votes Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-064-create-origin-votes-migration`  
**Depends on:** RG-058, RG-061

### Goal

Создать таблицу `origin_votes` для Homemade/Restaurant voting.

### TDD step

Schema test:

```php
it('creates origin_votes table with required columns', function () {
    expect(Schema::hasTable('origin_votes'))->toBeTrue();
    expect(Schema::hasColumns('origin_votes', [
        'id',
        'post_id',
        'user_id',
        'origin',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_origin_votes_table
```

Колонки:

```php
$table->id();
$table->foreignId('post_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->string('origin');
$table->timestamps();
```

Unique constraint будет в `RG-072`.

### Acceptance criteria

- Таблица `origin_votes` создаётся.
- Есть `post_id`, `user_id`, `origin`.
- Foreign keys существуют.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-064: Create origin votes migration`

### Files likely touched

```txt
database/migrations/*_create_origin_votes_table.php
tests/Feature/Database/OriginVotesTableTest.php
```

---

## RG-065 — Create Cuisine Votes Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-065-create-cuisine-votes-migration`  
**Depends on:** RG-059, RG-061

### Goal

Создать таблицу `cuisine_votes`.

### TDD step

Schema test:

```php
it('creates cuisine_votes table with required columns', function () {
    expect(Schema::hasTable('cuisine_votes'))->toBeTrue();
    expect(Schema::hasColumns('cuisine_votes', [
        'id',
        'post_id',
        'user_id',
        'cuisine',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_cuisine_votes_table
```

Колонки:

```php
$table->id();
$table->foreignId('post_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->string('cuisine');
$table->timestamps();
```

Unique constraint будет в `RG-073`.

### Acceptance criteria

- Таблица `cuisine_votes` создаётся.
- Есть `post_id`, `user_id`, `cuisine`.
- Foreign keys существуют.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-065: Create cuisine votes migration`

### Files likely touched

```txt
database/migrations/*_create_cuisine_votes_table.php
tests/Feature/Database/CuisineVotesTableTest.php
```

---

## RG-066 — Create Tags Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-066-create-tags-migration`  
**Depends on:** RG-055

### Goal

Создать таблицу `tags`.

### TDD step

Schema test:

```php
it('creates tags table with required columns', function () {
    expect(Schema::hasTable('tags'))->toBeTrue();
    expect(Schema::hasColumns('tags', [
        'id',
        'name',
        'slug',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_tags_table
```

Колонки:

```php
$table->id();
$table->string('name');
$table->string('slug')->unique();
$table->timestamps();
```

### Acceptance criteria

- Таблица `tags` создаётся.
- Есть `name`.
- Есть unique `slug`.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-066: Create tags migration`

### Files likely touched

```txt
database/migrations/*_create_tags_table.php
tests/Feature/Database/TagsTableTest.php
```

---

## RG-067 — Create Post Tag Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-067-create-post-tag-migration`  
**Depends on:** RG-061, RG-066

### Goal

Создать pivot-таблицу `post_tag`.

### TDD step

Schema test:

```php
it('creates post_tag pivot table', function () {
    expect(Schema::hasTable('post_tag'))->toBeTrue();
    expect(Schema::hasColumns('post_tag', [
        'post_id',
        'tag_id',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_post_tag_table
```

Колонки:

```php
$table->foreignId('post_id')->constrained()->cascadeOnDelete();
$table->foreignId('tag_id')->constrained()->cascadeOnDelete();
$table->primary(['post_id', 'tag_id']);
```

SQLite поддерживает composite primary key в миграции.  
Если Laravel/SQLite вызывает проблему, использовать unique index:

```php
$table->unique(['post_id', 'tag_id']);
```

### Acceptance criteria

- Таблица `post_tag` создаётся.
- Есть `post_id`, `tag_id`.
- Дубликат post/tag невозможен.
- Foreign keys существуют.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-067: Create post tag migration`

### Files likely touched

```txt
database/migrations/*_create_post_tag_table.php
tests/Feature/Database/PostTagTableTest.php
```

---

## RG-068 — Create Reports Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-068-create-reports-migration`  
**Depends on:** RG-060

### Goal

Создать таблицу `reports`.

### TDD step

Schema test:

```php
it('creates reports table with required columns', function () {
    expect(Schema::hasTable('reports'))->toBeTrue();
    expect(Schema::hasColumns('reports', [
        'id',
        'reporter_id',
        'target_type',
        'target_id',
        'reason',
        'message',
        'status',
        'resolved_by',
        'resolved_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_reports_table
```

Колонки:

```php
$table->id();
$table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

$table->string('target_type');
$table->unsignedBigInteger('target_id');

$table->string('reason');
$table->text('message')->nullable();

$table->string('status')->default('open');

$table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('resolved_at')->nullable();

$table->timestamps();

$table->index(['target_type', 'target_id']);
$table->index(['status']);
```

Не создавать `ReportStatus` enum сейчас, потому что его нет в исходном Phase 3.

### Acceptance criteria

- Таблица `reports` создаётся.
- Есть polymorphic target fields.
- Есть reporter.
- Есть reason/message/status.
- Есть resolved_by/resolved_at.
- Есть index по target.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-068: Create reports migration`

### Files likely touched

```txt
database/migrations/*_create_reports_table.php
tests/Feature/Database/ReportsTableTest.php
```

---

## RG-069 — Create Moderation Logs Migration

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-069-create-moderation-logs-migration`  
**Depends on:** RG-055

### Goal

Создать таблицу `moderation_logs`.

### TDD step

Schema test:

```php
it('creates moderation_logs table with required columns', function () {
    expect(Schema::hasTable('moderation_logs'))->toBeTrue();
    expect(Schema::hasColumns('moderation_logs', [
        'id',
        'moderator_id',
        'action',
        'target_type',
        'target_id',
        'reason',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});
```

### Implementation

Создать миграцию:

```bash
php artisan make:migration create_moderation_logs_table
```

Колонки:

```php
$table->id();
$table->foreignId('moderator_id')->constrained('users')->cascadeOnDelete();

$table->string('action');

$table->string('target_type');
$table->unsignedBigInteger('target_id');

$table->text('reason')->nullable();
$table->json('metadata')->nullable();

$table->timestamps();

$table->index(['target_type', 'target_id']);
$table->index(['moderator_id']);
$table->index(['action']);
```

SQLite хранит JSON как text, Laravel нормально работает с cast `array` позже.

### Acceptance criteria

- Таблица `moderation_logs` создаётся.
- Есть moderator_id.
- Есть action.
- Есть polymorphic target.
- Есть reason.
- Есть metadata.
- Индексы добавлены.
- Schema test проходит.

### Definition of Done

- Тест написан первым.
- Миграция создана.
- Тест проходит.
- Коммит: `RG-069: Create moderation logs migration`

### Files likely touched

```txt
database/migrations/*_create_moderation_logs_table.php
tests/Feature/Database/ModerationLogsTableTest.php
```

---

## RG-070 — Add Indexes To Posts Table

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-070-add-indexes-to-posts-table`  
**Depends on:** RG-061

### Goal

Добавить индексы на `posts`, необходимые для будущей ленты, модерации и сортировки.

### TDD step

No direct reliable cross-database index test in Laravel для всех индексов.

Можно добавить smoke test, что миграции проходят:

```php
it('migrates posts indexes without errors', function () {
    expect(Schema::hasTable('posts'))->toBeTrue();
});
```

Главная проверка — миграция и code review.

### Implementation

Если индексы не были добавлены в `RG-061`, создать миграцию:

```bash
php artisan make:migration add_indexes_to_posts_table --table=posts
```

Добавить индексы:

```php
$table->index(['status', 'published_at']);
$table->index(['user_id', 'created_at']);
$table->index('hot_score');
$table->index('reports_count');
```

Если индексы уже добавлены в create migration, эта задача должна проверить и зафиксировать это, не дублируя.

### Acceptance criteria

- У `posts` есть index для `status/published_at`.
- У `posts` есть index для `user_id/created_at`.
- У `posts` есть index для `hot_score`.
- У `posts` есть index для `reports_count`.
- Миграции проходят на SQLite.

### Definition of Done

- Индексы добавлены или подтверждены.
- Миграции проходят.
- Коммит: `RG-070: Add indexes to posts table`

### Files likely touched

```txt
database/migrations/*_add_indexes_to_posts_table.php
tests/Feature/Database/PostsTableTest.php
```

---

## RG-071 — Add Unique Index For Post Votes User Post

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-071-add-unique-index-for-post-votes-user-post`  
**Depends on:** RG-063

### Goal

Добавить уникальность `post_votes(post_id, user_id)`, чтобы один пользователь имел только один up/down vote на пост.

### TDD step

Feature test:

```php
it('does not allow duplicate post vote for same user and post', function () {
    $user = User::factory()->create();

    $postId = DB::table('posts')->insertGetId([
        'user_id' => $user->id,
        'title' => 'Test dish',
        'status' => PostStatus::Published->value,
        'origin_truth' => OriginType::Unknown->value,
        'cuisine_truth' => CuisineType::Unknown->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('post_votes')->insert([
        'post_id' => $postId,
        'user_id' => $user->id,
        'type' => VoteType::Up->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('post_votes')->insert([
        'post_id' => $postId,
        'user_id' => $user->id,
        'type' => VoteType::Down->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
```

### Implementation

Если unique не добавлен в create migration, добавить:

```php
$table->unique(['post_id', 'user_id']);
```

Лучше прямо в `create_post_votes_table`, если ветка ещё не ушла далеко.  
Если миграции уже применялись — отдельная migration.

### Acceptance criteria

- Duplicate post vote для same user/post невозможен.
- Тест падает до unique constraint.
- Тест проходит после constraint.
- Миграции проходят.

### Definition of Done

- Constraint test написан.
- Unique index добавлен.
- Тест проходит.
- Коммит: `RG-071: Add unique index for post votes user post`

### Files likely touched

```txt
database/migrations/*_create_post_votes_table.php
database/migrations/*_add_unique_index_to_post_votes_table.php
tests/Feature/Database/PostVotesTableTest.php
```

---

## RG-072 — Add Unique Index For Origin Votes User Post

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-072-add-unique-index-for-origin-votes-user-post`  
**Depends on:** RG-064

### Goal

Добавить уникальность `origin_votes(post_id, user_id)`.

### TDD step

Feature test: duplicate origin vote для same user/post должен выбросить `QueryException`.

### Implementation

Добавить:

```php
$table->unique(['post_id', 'user_id']);
```

в `origin_votes`.

### Acceptance criteria

- Один пользователь может иметь только один origin vote на пост.
- Duplicate insert падает.
- Тест проходит.
- Миграции проходят.

### Definition of Done

- Constraint test написан.
- Unique index добавлен.
- Тест проходит.
- Коммит: `RG-072: Add unique index for origin votes user post`

### Files likely touched

```txt
database/migrations/*_create_origin_votes_table.php
database/migrations/*_add_unique_index_to_origin_votes_table.php
tests/Feature/Database/OriginVotesTableTest.php
```

---

## RG-073 — Add Unique Index For Cuisine Votes User Post

**Area:** DB  
**Type:** Migration  
**Priority:** P0  
**Branch:** `feature/RG-073-add-unique-index-for-cuisine-votes-user-post`  
**Depends on:** RG-065

### Goal

Добавить уникальность `cuisine_votes(post_id, user_id)`.

### TDD step

Feature test: duplicate cuisine vote для same user/post должен выбросить `QueryException`.

### Implementation

Добавить:

```php
$table->unique(['post_id', 'user_id']);
```

в `cuisine_votes`.

### Acceptance criteria

- Один пользователь может иметь только один cuisine vote на пост.
- Duplicate insert падает.
- Тест проходит.
- Миграции проходят.

### Definition of Done

- Constraint test написан.
- Unique index добавлен.
- Тест проходит.
- Коммит: `RG-073: Add unique index for cuisine votes user post`

### Files likely touched

```txt
database/migrations/*_create_cuisine_votes_table.php
database/migrations/*_add_unique_index_to_cuisine_votes_table.php
tests/Feature/Database/CuisineVotesTableTest.php
```

---

## RG-074 — Add Post Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-074-add-post-model`  
**Depends on:** RG-061

### Goal

Добавить Eloquent model `Post`.

### TDD step

Model test:

```php
it('has a Post model using posts table', function () {
    expect((new Post())->getTable())->toBe('posts');
});
```

### Implementation

Создать:

```bash
php artisan make:model Post
```

Настроить:

```php
use SoftDeletes;

protected $guarded = [];
```

Casts:

```php
'status' => PostStatus::class,
'origin_truth' => OriginType::class,
'cuisine_truth' => CuisineType::class,
'published_at' => 'datetime',
'hot_score' => 'float',
```

Не добавлять scopes в Phase 3.

### Acceptance criteria

- `Post` model существует.
- Использует table `posts`.
- SoftDeletes подключён.
- Enum casts работают.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Casts добавлены.
- Тест проходит.
- Коммит: `RG-074: Add Post model`

### Files likely touched

```txt
app/Models/Post.php
tests/Unit/Models/PostModelTest.php
```

---

## RG-075 — Add Comment Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-075-add-comment-model`  
**Depends on:** RG-062

### Goal

Добавить Eloquent model `Comment`.

### TDD step

Model test:

```php
it('has a Comment model using comments table', function () {
    expect((new Comment())->getTable())->toBe('comments');
});
```

### Implementation

Создать:

```bash
php artisan make:model Comment
```

Настроить:

```php
use SoftDeletes;

protected $guarded = [];
```

Не создавать CommentStatus enum сейчас.

### Acceptance criteria

- `Comment` model существует.
- Использует table `comments`.
- SoftDeletes подключён.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Тест проходит.
- Коммит: `RG-075: Add Comment model`

### Files likely touched

```txt
app/Models/Comment.php
tests/Unit/Models/CommentModelTest.php
```

---

## RG-076 — Add PostVote Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-076-add-post-vote-model`  
**Depends on:** RG-057, RG-063

### Goal

Добавить Eloquent model `PostVote`.

### TDD step

Model test:

```php
it('casts post vote type to VoteType enum', function () {
    $vote = new PostVote(['type' => VoteType::Up]);

    expect($vote->type)->toBe(VoteType::Up);
});
```

### Implementation

Создать:

```bash
php artisan make:model PostVote
```

Настроить:

```php
protected $guarded = [];

protected function casts(): array
{
    return [
        'type' => VoteType::class,
    ];
}
```

### Acceptance criteria

- `PostVote` model существует.
- Использует table `post_votes`.
- `type` кастится в `VoteType`.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Cast добавлен.
- Тест проходит.
- Коммит: `RG-076: Add PostVote model`

### Files likely touched

```txt
app/Models/PostVote.php
tests/Unit/Models/PostVoteModelTest.php
```

---

## RG-077 — Add OriginVote Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-077-add-origin-vote-model`  
**Depends on:** RG-058, RG-064

### Goal

Добавить Eloquent model `OriginVote`.

### TDD step

Model test:

```php
it('casts origin vote origin to OriginType enum', function () {
    $vote = new OriginVote(['origin' => OriginType::Homemade]);

    expect($vote->origin)->toBe(OriginType::Homemade);
});
```

### Implementation

Создать:

```bash
php artisan make:model OriginVote
```

Настроить:

```php
protected $guarded = [];

protected function casts(): array
{
    return [
        'origin' => OriginType::class,
    ];
}
```

### Acceptance criteria

- `OriginVote` model существует.
- Использует table `origin_votes`.
- `origin` кастится в `OriginType`.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Cast добавлен.
- Тест проходит.
- Коммит: `RG-077: Add OriginVote model`

### Files likely touched

```txt
app/Models/OriginVote.php
tests/Unit/Models/OriginVoteModelTest.php
```

---

## RG-078 — Add CuisineVote Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-078-add-cuisine-vote-model`  
**Depends on:** RG-059, RG-065

### Goal

Добавить Eloquent model `CuisineVote`.

### TDD step

Model test:

```php
it('casts cuisine vote cuisine to CuisineType enum', function () {
    $vote = new CuisineVote(['cuisine' => CuisineType::Italian]);

    expect($vote->cuisine)->toBe(CuisineType::Italian);
});
```

### Implementation

Создать:

```bash
php artisan make:model CuisineVote
```

Настроить:

```php
protected $guarded = [];

protected function casts(): array
{
    return [
        'cuisine' => CuisineType::class,
    ];
}
```

### Acceptance criteria

- `CuisineVote` model существует.
- Использует table `cuisine_votes`.
- `cuisine` кастится в `CuisineType`.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Cast добавлен.
- Тест проходит.
- Коммит: `RG-078: Add CuisineVote model`

### Files likely touched

```txt
app/Models/CuisineVote.php
tests/Unit/Models/CuisineVoteModelTest.php
```

---

## RG-079 — Add Tag Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-079-add-tag-model`  
**Depends on:** RG-066

### Goal

Добавить Eloquent model `Tag`.

### TDD step

Model test:

```php
it('has a Tag model using tags table', function () {
    expect((new Tag())->getTable())->toBe('tags');
});
```

### Implementation

Создать:

```bash
php artisan make:model Tag
```

Настроить:

```php
protected $guarded = [];
```

Не добавлять slug generation в Phase 3. Это отдельная логика.

### Acceptance criteria

- `Tag` model существует.
- Использует table `tags`.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Тест проходит.
- Коммит: `RG-079: Add Tag model`

### Files likely touched

```txt
app/Models/Tag.php
tests/Unit/Models/TagModelTest.php
```

---

## RG-080 — Add Report Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-080-add-report-model`  
**Depends on:** RG-060, RG-068

### Goal

Добавить Eloquent model `Report`.

### TDD step

Model test:

```php
it('casts report reason to ReportReason enum', function () {
    $report = new Report(['reason' => ReportReason::Spam]);

    expect($report->reason)->toBe(ReportReason::Spam);
});
```

### Implementation

Создать:

```bash
php artisan make:model Report
```

Настроить:

```php
protected $guarded = [];

protected function casts(): array
{
    return [
        'reason' => ReportReason::class,
        'resolved_at' => 'datetime',
    ];
}
```

Не создавать ReportStatus enum сейчас.

### Acceptance criteria

- `Report` model существует.
- Использует table `reports`.
- `reason` кастится в `ReportReason`.
- `resolved_at` кастится в datetime.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Casts добавлены.
- Тест проходит.
- Коммит: `RG-080: Add Report model`

### Files likely touched

```txt
app/Models/Report.php
tests/Unit/Models/ReportModelTest.php
```

---

## RG-081 — Add ModerationLog Model

**Area:** Backend  
**Type:** Model  
**Priority:** P0  
**Branch:** `feature/RG-081-add-moderation-log-model`  
**Depends on:** RG-069

### Goal

Добавить Eloquent model `ModerationLog`.

### TDD step

Model test:

```php
it('casts moderation log metadata to array', function () {
    $log = new ModerationLog(['metadata' => ['x' => 1]]);

    expect($log->metadata)->toBe(['x' => 1]);
});
```

### Implementation

Создать:

```bash
php artisan make:model ModerationLog
```

Настроить:

```php
protected $guarded = [];

protected function casts(): array
{
    return [
        'metadata' => 'array',
    ];
}
```

### Acceptance criteria

- `ModerationLog` model существует.
- Использует table `moderation_logs`.
- `metadata` кастится в array.
- Model test проходит.

### Definition of Done

- Тест написан первым.
- Model создан.
- Cast добавлен.
- Тест проходит.
- Коммит: `RG-081: Add ModerationLog model`

### Files likely touched

```txt
app/Models/ModerationLog.php
tests/Unit/Models/ModerationLogModelTest.php
```

---

## RG-082 — Add User HasMany Posts Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-082-add-user-has-many-posts-relationship`  
**Depends on:** RG-074

### Goal

Добавить relationship `User::posts()`.

### TDD step

Relationship test:

```php
it('allows user to have many posts', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    expect($user->posts()->first()->id)->toBe($post->id);
});
```

Минимальные required fields зависят от posts migration defaults.

### Implementation

В `User` model добавить:

```php
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}
```

Добавить imports.

### Acceptance criteria

- `User::posts()` существует.
- Возвращает `HasMany`.
- User может получить свои posts.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-082: Add User hasMany posts relationship`

### Files likely touched

```txt
app/Models/User.php
tests/Feature/Relationships/UserPostsRelationshipTest.php
```

---

## RG-083 — Add Post BelongsTo User Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-083-add-post-belongs-to-user-relationship`  
**Depends on:** RG-074

### Goal

Добавить relationship `Post::user()`.

### TDD step

Relationship test:

```php
it('allows post to belong to user', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    expect($post->user->id)->toBe($user->id);
});
```

### Implementation

В `Post` model добавить:

```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

### Acceptance criteria

- `Post::user()` существует.
- Возвращает `BelongsTo`.
- Post получает автора.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-083: Add Post belongsTo user relationship`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Relationships/PostUserRelationshipTest.php
```

---

## RG-084 — Add Post HasMany Comments Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-084-add-post-has-many-comments-relationship`  
**Depends on:** RG-074, RG-075

### Goal

Добавить relationship `Post::comments()`.

### TDD step

Relationship test:

```php
it('allows post to have many comments', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    $comment = Comment::create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Looks good',
    ]);

    expect($post->comments()->first()->id)->toBe($comment->id);
});
```

### Implementation

В `Post` model добавить:

```php
public function comments(): HasMany
{
    return $this->hasMany(Comment::class);
}
```

### Acceptance criteria

- `Post::comments()` существует.
- Возвращает `HasMany`.
- Post получает comments.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-084: Add Post hasMany comments relationship`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Relationships/PostCommentsRelationshipTest.php
```

---

## RG-085 — Add Post HasMany PostVotes Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-085-add-post-has-many-post-votes-relationship`  
**Depends on:** RG-074, RG-076

### Goal

Добавить relationship `Post::postVotes()`.

### TDD step

Relationship test создаёт post vote и проверяет связь.

### Implementation

В `Post` model добавить:

```php
public function postVotes(): HasMany
{
    return $this->hasMany(PostVote::class);
}
```

### Acceptance criteria

- `Post::postVotes()` существует.
- Возвращает `HasMany`.
- Post получает post votes.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-085: Add Post hasMany postVotes relationship`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Relationships/PostPostVotesRelationshipTest.php
```

---

## RG-086 — Add Post HasMany OriginVotes Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-086-add-post-has-many-origin-votes-relationship`  
**Depends on:** RG-074, RG-077

### Goal

Добавить relationship `Post::originVotes()`.

### TDD step

Relationship test создаёт origin vote и проверяет связь.

### Implementation

В `Post` model добавить:

```php
public function originVotes(): HasMany
{
    return $this->hasMany(OriginVote::class);
}
```

### Acceptance criteria

- `Post::originVotes()` существует.
- Возвращает `HasMany`.
- Post получает origin votes.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-086: Add Post hasMany originVotes relationship`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Relationships/PostOriginVotesRelationshipTest.php
```

---

## RG-087 — Add Post HasMany CuisineVotes Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-087-add-post-has-many-cuisine-votes-relationship`  
**Depends on:** RG-074, RG-078

### Goal

Добавить relationship `Post::cuisineVotes()`.

### TDD step

Relationship test создаёт cuisine vote и проверяет связь.

### Implementation

В `Post` model добавить:

```php
public function cuisineVotes(): HasMany
{
    return $this->hasMany(CuisineVote::class);
}
```

### Acceptance criteria

- `Post::cuisineVotes()` существует.
- Возвращает `HasMany`.
- Post получает cuisine votes.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-087: Add Post hasMany cuisineVotes relationship`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Relationships/PostCuisineVotesRelationshipTest.php
```

---

## RG-088 — Add Post BelongsToMany Tags Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-088-add-post-belongs-to-many-tags-relationship`  
**Depends on:** RG-074, RG-079

### Goal

Добавить relationship `Post::tags()`.

### TDD step

Relationship test:

```php
it('allows post to belong to many tags', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    $tag = Tag::create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $post->tags()->attach($tag);

    expect($post->tags()->first()->id)->toBe($tag->id);
});
```

### Implementation

В `Post` model добавить:

```php
public function tags(): BelongsToMany
{
    return $this->belongsToMany(Tag::class);
}
```

Опционально в `Tag` можно добавить обратную связь `posts()`, но исходная задача только про `Post belongsToMany tags`.  
Если добавляется обратная связь — отдельный тест или не добавлять.

### Acceptance criteria

- `Post::tags()` существует.
- Возвращает `BelongsToMany`.
- Post может attach tag.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-088: Add Post belongsToMany tags relationship`

### Files likely touched

```txt
app/Models/Post.php
tests/Feature/Relationships/PostTagsRelationshipTest.php
```

---

## RG-089 — Add Comment BelongsTo User Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-089-add-comment-belongs-to-user-relationship`  
**Depends on:** RG-075

### Goal

Добавить relationship `Comment::user()`.

### TDD step

Relationship test:

```php
it('allows comment to belong to user', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    $comment = Comment::create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Looks good',
    ]);

    expect($comment->user->id)->toBe($user->id);
});
```

### Implementation

В `Comment` model добавить:

```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

### Acceptance criteria

- `Comment::user()` существует.
- Возвращает `BelongsTo`.
- Comment получает автора.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-089: Add Comment belongsTo user relationship`

### Files likely touched

```txt
app/Models/Comment.php
tests/Feature/Relationships/CommentUserRelationshipTest.php
```

---

## RG-090 — Add Comment BelongsTo Post Relationship

**Area:** Backend  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-090-add-comment-belongs-to-post-relationship`  
**Depends on:** RG-075

### Goal

Добавить relationship `Comment::post()`.

### TDD step

Relationship test:

```php
it('allows comment to belong to post', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'title' => 'Test dish',
    ]);

    $comment = Comment::create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Looks good',
    ]);

    expect($comment->post->id)->toBe($post->id);
});
```

### Implementation

В `Comment` model добавить:

```php
public function post(): BelongsTo
{
    return $this->belongsTo(Post::class);
}
```

### Acceptance criteria

- `Comment::post()` существует.
- Возвращает `BelongsTo`.
- Comment получает post.
- Relationship test проходит.

### Definition of Done

- Тест написан первым.
- Relationship добавлен.
- Тест проходит.
- Коммит: `RG-090: Add Comment belongsTo post relationship`

### Files likely touched

```txt
app/Models/Comment.php
tests/Feature/Relationships/CommentPostRelationshipTest.php
```

---

# 9. Phase 3 Completion Criteria

Phase 3 завершена, когда:

```txt
- RG-056–RG-090 выполнены;
- enums PostStatus, VoteType, OriginType, CuisineType, ReportReason существуют;
- migrations создают все core tables;
- unique constraints для vote tables работают;
- posts indexes добавлены;
- models Post, Comment, PostVote, OriginVote, CuisineVote, Tag, Report, ModerationLog существуют;
- enum casts работают;
- basic relationships работают;
- composer test проходит;
- php artisan migrate:fresh проходит на SQLite;
- нет factories, scopes, actions или UI вне scope Phase 3.
```

---

# 10. Что нельзя делать в Phase 3

Без отдельной задачи нельзя:

```txt
- создавать factories для новых моделей;
- добавлять scopes;
- писать CreatePostAction;
- писать FeedQuery;
- добавлять upload;
- добавлять image storage;
- писать voting actions;
- писать comments actions;
- писать reports actions;
- писать moderation actions;
- создавать Filament resources;
- создавать Livewire components;
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
RG-056 Add PostStatus Enum
RG-057 Add VoteType Enum
RG-058 Add OriginType Enum
RG-059 Add CuisineType Enum
RG-060 Add ReportReason Enum
RG-061 Create Posts Migration
RG-062 Create Comments Migration
RG-063 Create Post Votes Migration
RG-064 Create Origin Votes Migration
RG-065 Create Cuisine Votes Migration
RG-066 Create Tags Migration
RG-067 Create Post Tag Migration
RG-068 Create Reports Migration
RG-069 Create Moderation Logs Migration
RG-070 Add Indexes To Posts Table
RG-071 Add Unique Index For Post Votes User Post
RG-072 Add Unique Index For Origin Votes User Post
RG-073 Add Unique Index For Cuisine Votes User Post
RG-074 Add Post Model
RG-075 Add Comment Model
RG-076 Add PostVote Model
RG-077 Add OriginVote Model
RG-078 Add CuisineVote Model
RG-079 Add Tag Model
RG-080 Add Report Model
RG-081 Add ModerationLog Model
RG-082 Add User HasMany Posts Relationship
RG-083 Add Post BelongsTo User Relationship
RG-084 Add Post HasMany Comments Relationship
RG-085 Add Post HasMany PostVotes Relationship
RG-086 Add Post HasMany OriginVotes Relationship
RG-087 Add Post HasMany CuisineVotes Relationship
RG-088 Add Post BelongsToMany Tags Relationship
RG-089 Add Comment BelongsTo User Relationship
RG-090 Add Comment BelongsTo Post Relationship
```

---

# 12. Release

После завершения Phase 3:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.0.4-phase3-core-database-schema
git push -u origin release/v0.0.4-phase3-core-database-schema
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.4-phase3-core-database-schema -m "RateGuru Phase 3 core database schema"
git push origin v0.0.4-phase3-core-database-schema
```
