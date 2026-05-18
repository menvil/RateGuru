# RateGuru — Phase 36 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 36 — Seed Data**  
Диапазон задач: **RG-587 → RG-597**  
Основа нумерации: исходный atomic backlog, где Phase 36 начинается с задачи 587 и заканчивается задачей 597.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 36 соответствует исходному блоку:

```txt
Phase 36 — Seed Data
```

Правильный диапазон Phase 36:

```txt
RG-587 — Create seed users
RG-588 — Create seed tags
RG-589 — Create seed published posts
RG-590 — Create seed pending posts
RG-591 — Create seed hidden posts
RG-592 — Create seed comments
RG-593 — Create seed votes
RG-594 — Create seed reports
RG-595 — Add demo admin account
RG-596 — Add demo moderator account
RG-597 — Add database seeder command docs
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 37 начинается с `RG-598` и делает **UI Polish Pass**. Поэтому Phase 36 не должна править spacing, radius, colors, drawer animation или любые visual polish задачи.
---

# 2. Цель Phase 36

Phase 36 добавляет воспроизводимый seed dataset для разработки, ручной проверки UI, демо и browser smoke tests.

После Phase 36 разработчик должен уметь выполнить:

```bash
php artisan migrate:fresh --seed
```

и получить локальную базу, где есть:

```txt
- normal users;
- trusted users;
- banned/shadowbanned users if supported;
- tags;
- published posts;
- pending posts;
- hidden posts;
- comments;
- votes;
- reports;
- demo admin;
- demo moderator;
- docs with seed commands and demo credentials.
```

Главная цель: быстро получить реалистичное состояние приложения без ручного кликанья через UI.
---

# 3. Scope Phase 36

## Входит

```txt
- dedicated seeders;
- deterministic demo users;
- deterministic demo tags;
- published posts for feed/profile/post show checks;
- pending posts for moderation checks;
- hidden posts for admin/moderation checks;
- comments for comment UI checks;
- votes for counters/ranking checks;
- reports for moderation/report resources;
- demo admin account;
- demo moderator account;
- seeder command docs.
```

## Не входит

```txt
- production data import;
- real user data;
- image upload processing;
- remote image downloading;
- Cloudinary/S3 seed upload;
- UI polish;
- browser smoke tests;
- visual regression baselines;
- performance indexing;
- fixtures for external APIs.
```

Seed data не должна быть зависима от интернета. Никаких download-from-URL в seeders.
---

# 4. Critical Decisions

## 4.1. Seed data is local/demo only

Seeders должны быть безопасны.

Правило:

```txt
Demo admin/moderator accounts must not be created accidentally in production.
```

Recommended implementation:

```php
if (! app()->environment(['local', 'testing'])) {
    return;
}
```

или отдельный seeder:

```txt
DemoDatabaseSeeder
```

который явно запускается только локально.

Но `php artisan migrate:fresh --seed` в local должен работать без дополнительных вопросов.

## 4.2. Seed data should be deterministic enough

Полностью random seed data раздражает:

```txt
- сложно писать browser smoke tests;
- сложно сверять UI;
- сложно документировать demo credentials;
- сложно воспроизводить bug.
```

Поэтому использовать:

```txt
- fixed usernames;
- fixed demo emails;
- fixed tag slugs;
- recognizable post titles;
- stable counts where tests depend on them.
```

Factories могут генерировать дополнительные random записи, но минимум demo dataset должен быть предсказуемым.

## 4.3. Do not use production-looking credentials carelessly

Demo credentials должны быть очевидно локальными:

```txt
admin@rateguru.test
moderator@rateguru.test
password: password
```

Это нормально для local/test, но опасно в production.

Docs должны прямо сказать:

```txt
Demo credentials are local-only. Do not seed them in production.
```

## 4.4. Use factories, but keep relationships consistent

Seeders должны использовать factories и relations:

```php
Post::factory()->for($user)->published()->create()
Comment::factory()->for($post)->for($user, 'user')->create()
```

Нельзя создавать orphan records:

```txt
comment without post
vote without post
report without reportable
post_tag without existing tag/post
```

## 4.5. Counters must be consistent

Если seeders создают comments/votes/reports напрямую, counters могут стать неправильными:

```txt
comments_count
upvotes_count
downvotes_count
reports_count
hot_score
```

После seed data нужно либо:

```txt
- использовать Actions, которые сами обновляют counters;
```

либо:

```txt
- вызвать recalculation commands/actions после seed.
```

Рекомендация для seeders:

```txt
- для простоты создавать records factories/directly;
- после этого вызвать RecalculatePostCountersAction / command;
- вызвать RecalculatePostScoreAction / command;
- обновить reports_count where needed.
```

Если commands already exist:

```bash
php artisan posts:recalculate-counters
php artisan posts:recalculate-hot-scores
```

использовать их в docs. В seeders лучше вызывать Actions напрямую, не shell commands.

## 4.6. Avoid notification noise during seeding

Phase 31 notifications могут сработать, если seeders используют Actions:

```txt
AddCommentAction
ApprovePostAction
```

Для seed data это нежелательно.

Рекомендация:

```txt
- seed raw demo comments/reports/votes via factories;
- recalculate aggregates after;
- do not dispatch user notifications during seeding.
```

Если используешь Actions, отключай events/notifications явно только если безопасно и локально.

## 4.7. Seed images

Не загружать реальные файлы из сети.

Options:

```txt
Option A: use placeholder local asset path
Option B: copy bundled demo images from resources/demo/images to storage
Option C: use existing base image placeholder component
```

Recommended MVP:

```txt
Use static placeholder image paths / demo image assets committed to repo if already available.
```

Если demo images не готовы:

```txt
image_path = 'demo/posts/demo-01.jpg'
thumbnail_url = null
```

и UI должен показать placeholder.

Не блокировать seed phase из-за отсутствия реальных изображений.

## 4.8. Pending/hidden posts are for moderation/admin checks

Seeded pending posts должны быть видны в:

```txt
- Filament PostResource pending filter;
- Inline moderation for moderator/admin;
- ModerationDashboard pending widget.
```

Seeded hidden posts должны быть видны в:

```txt
- Filament PostResource hidden filter;
- hidden moderation checks.
```

Но hidden/pending posts не должны появляться в public feed/profile.

## 4.9. Reports seed should create realistic moderation state

Reports должны покрывать:

```txt
- reported post;
- reported comment;
- open report;
- resolved report optional;
- ignored report optional if status exists.
```

Minimum:

```txt
open reports for one published post and one visible comment
```

This powers:

```txt
ReportResource
ModerationDashboard
reported filters
reports_count columns
```

## 4.10. Demo admin/moderator roles

`demo admin` and `demo moderator` are not generic users. They should be created as stable accounts:

```txt
admin@rateguru.test / password
moderator@rateguru.test / password
```

Usernames:

```txt
admin
moderator
```

Roles:

```txt
admin
moderator
```

Status:

```txt
active
```

Do not make them trusted/banned/shadowbanned.
---

# 5. Architecture Rules

## 5.1. Use dedicated seeders

Do not dump everything into `DatabaseSeeder`.

Recommended structure:

```txt
database/seeders/DatabaseSeeder.php
database/seeders/Demo/SeedUsers.php
database/seeders/Demo/SeedTags.php
database/seeders/Demo/SeedPosts.php
database/seeders/Demo/SeedComments.php
database/seeders/Demo/SeedVotes.php
database/seeders/Demo/SeedReports.php
database/seeders/Demo/SeedDemoAdmin.php
database/seeders/Demo/SeedDemoModerator.php
```

Alternative if too many classes:

```txt
database/seeders/DemoDatabaseSeeder.php
```

with private methods. But atomic tasks become cleaner with separate seeders.

Recommended:

```txt
Use DemoDatabaseSeeder orchestrator + small seeders/classes.
```

## 5.2. Seeders should be idempotent where practical

Running seed twice should not duplicate stable accounts/tags infinitely.

Use:

```php
User::updateOrCreate(['email' => 'admin@rateguru.test'], [...])
Tag::updateOrCreate(['slug' => 'italian'], [...])
```

Posts/comments/votes can be recreated after `migrate:fresh --seed`, but if someone runs `db:seed` twice, stable demo accounts/tags should not duplicate.

For demo posts, either:

```txt
- delete demo posts first by known source marker;
```

or:

```txt
- use updateOrCreate with known slugs/titles if posts have slug;
```

If no post slug, duplication on manual repeated `db:seed` is acceptable but docs should prefer `migrate:fresh --seed`.

## 5.3. Avoid hardcoding ids

Wrong:

```php
$userId = 1;
$postId = 10;
```

Correct:

```php
$admin = User::where('email', 'admin@rateguru.test')->firstOrFail();
$post = Post::where('title', 'Demo: Italian Pasta')->firstOrFail();
```

## 5.4. Use model enums

Use existing enums:

```txt
UserRole
UserStatus
PostStatus
ReportStatus
ReportReason
VoteType / VoteValue
OriginType
CuisineType
```

Do not seed raw strings unless model currently expects strings and enums are not available.

## 5.5. Do not bypass password hashing

Wrong:

```php
'password' => 'password'
```

Correct:

```php
'password' => Hash::make('password')
```

## 5.6. Docs are part of the feature

Phase 36 is useless if nobody knows how to run it.

Docs must include:

```txt
- command to seed fresh DB;
- command to seed demo only if available;
- demo admin credentials;
- demo moderator credentials;
- warning not to seed production;
- expected dataset summary.
```
---

# 6. GitFlow для Phase 36

## Base branch

Все задачи Phase 36 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-587-create-seed-users
feature/RG-595-add-demo-admin-account
feature/RG-597-add-database-seeder-command-docs
```

## Commit format

```txt
RG-587: Create seed users
RG-595: Add demo admin account
RG-597: Add database seeder command docs
```

## Release branch

После выполнения `RG-587`–`RG-597`:

```txt
release/v0.2.17-phase36-seed-data
```

## Tag

После merge release branch в `main`:

```txt
v0.2.17-phase36-seed-data
```

Почему `v0.2.17`: Phase 35 использует `v0.2.16`, Phase 36 следующий release.
---

# 7. TDD Rules for Phase 36

## Для seed users

Тестировать:

```txt
- seeder creates expected users;
- users have expected roles/statuses;
- seeded users have usernames;
- seeded users have hashed passwords where relevant.
```

## Для seed tags

Тестировать:

```txt
- expected tags exist;
- slugs are unique;
- names are human-readable.
```

## Для seed posts

Тестировать:

```txt
- published posts exist;
- pending posts exist;
- hidden posts exist;
- posts belong to users;
- posts have tags;
- public feed still shows only published posts.
```

## Для seed comments/votes/reports

Тестировать:

```txt
- comments exist and belong to posts/users;
- votes exist and counters are consistent;
- reports exist and counters are consistent;
- no orphan records.
```

## Для demo accounts

Тестировать:

```txt
- demo admin exists locally;
- demo moderator exists locally;
- passwords are hashed;
- production guard exists.
```

## Для docs

Тестировать minimally:

```txt
- docs file exists;
- docs mention migrate:fresh --seed;
- docs mention local-only demo credentials warning.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Seed Data / Database / Tests / Docs
Type: Test / Seeder / Data / Docs
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
- Seed data is local/demo safe
- No production credential risk
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 36 Atomic Tasks
---

## RG-587 — Create Seed Users

**Area:** Seed Data / Users  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-587-create-seed-users`  
**Base branch:** develop
**Depends on:** RG-586

### Goal

Создать базовых demo users для локальной базы.

### TDD step

Seeder test:

```php
it('seeds demo users', function () {
    $this->seed(DemoUsersSeeder::class);

    expect(User::query()->where('email', 'alice@rateguru.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'bob@rateguru.test')->exists())->toBeTrue();
    expect(User::query()->where('email', 'trusted@rateguru.test')->exists())->toBeTrue();
});
```

Role/status test:

```php
it('seeds users with expected roles and statuses', function () {
    $this->seed(DemoUsersSeeder::class);

    expect(User::where('email', 'alice@rateguru.test')->first()->status)
        ->toBe(UserStatus::Active);

    expect(User::where('email', 'trusted@rateguru.test')->first()->status)
        ->toBe(UserStatus::Trusted);
});
```

If `UserStatus::Trusted` does not exist, adapt to existing trust model from Phase 25.

### Implementation

Create:

```bash
php artisan make:seeder DemoUsersSeeder
```

Recommended users:

```txt
alice@rateguru.test       username: alice       active normal user
bob@rateguru.test         username: bob         active normal user
carla@rateguru.test       username: carla       active normal user
trusted@rateguru.test     username: trusted     trusted user/status
banned@rateguru.test      username: banned      banned user/status
shadow@rateguru.test      username: shadow      shadowbanned user/status if supported
```

Implementation pattern:

```php
User::updateOrCreate(
    ['email' => 'alice@rateguru.test'],
    [
        'name' => 'Alice Demo',
        'username' => 'alice',
        'password' => Hash::make('password'),
        'role' => UserRole::User,
        'status' => UserStatus::Active,
        'avatar_url' => null,
    ],
);
```

If `UserRole::User` is named differently, use actual enum.

Add orchestrator:

```txt
database/seeders/DemoDatabaseSeeder.php
```

or call from `DatabaseSeeder`.

Production guard:

```php
if (! app()->environment(['local', 'testing'])) {
    return;
}
```

### Acceptance criteria

- Demo users seeder exists.
- At least 3 active normal users exist.
- Trusted user exists if status supported.
- Banned user exists.
- Shadowbanned user exists if status supported.
- Passwords are hashed.
- Seeder does not create demo users in production.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- DatabaseSeeder or DemoDatabaseSeeder wired.
- Tests pass.
- Коммит: `RG-587: Create seed users`

### Files likely touched

```txt
database/seeders/DemoUsersSeeder.php
database/seeders/DemoDatabaseSeeder.php
database/seeders/DatabaseSeeder.php
tests/Feature/Seeders/DemoUsersSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-588 — Create Seed Tags

**Area:** Seed Data / Tags  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-588-create-seed-tags`  
**Base branch:** develop
**Depends on:** RG-587

### Goal

Создать базовые tags для demo posts/feed/search.

### TDD step

Seeder test:

```php
it('seeds demo tags', function () {
    $this->seed(DemoTagsSeeder::class);

    expect(Tag::query()->where('slug', 'italian')->exists())->toBeTrue();
    expect(Tag::query()->where('slug', 'asian')->exists())->toBeTrue();
    expect(Tag::query()->where('slug', 'homemade')->exists())->toBeTrue();
});
```

Unique slug test:

```php
it('seeds tags with unique slugs', function () {
    $this->seed(DemoTagsSeeder::class);

    $total = Tag::query()->count();
    $uniqueSlugs = Tag::query()->distinct('slug')->count('slug');

    expect($uniqueSlugs)->toBe($total);
});
```

### Implementation

Create:

```bash
php artisan make:seeder DemoTagsSeeder
```

Recommended tags:

```txt
italian
asian
american
mexican
homemade
restaurant
dessert
breakfast
street-food
healthy
spicy
comfort-food
```

Implementation:

```php
collect([
    ['name' => 'Italian', 'slug' => 'italian'],
    ['name' => 'Asian', 'slug' => 'asian'],
    ...
])->each(fn ($tag) => Tag::updateOrCreate(['slug' => $tag['slug']], $tag));
```

Call from `DemoDatabaseSeeder` after users.

### Acceptance criteria

- Demo tags seeder exists.
- At least 10 tags exist.
- Slugs are unique.
- Slugs are URL-safe/lowercase.
- Seeder is idempotent via `updateOrCreate`.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Orchestrator wired.
- Tests pass.
- Коммит: `RG-588: Create seed tags`

### Files likely touched

```txt
database/seeders/DemoTagsSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoTagsSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-589 — Create Seed Published Posts

**Area:** Seed Data / Posts  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-589-create-seed-published-posts`  
**Base branch:** develop
**Depends on:** RG-588

### Goal

Создать published posts для feed, profile, post show, share, hot ranking checks.

### TDD step

Seeder test:

```php
it('seeds published demo posts', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoPublishedPostsSeeder::class);

    expect(Post::query()->where('status', PostStatus::Published)->count())
        ->toBeGreaterThanOrEqual(6);
});
```

Relationship test:

```php
it('seeds published posts with authors and tags', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $post = Post::query()->where('status', PostStatus::Published)->firstOrFail();

    expect($post->user)->not->toBeNull();
    expect($post->tags()->count())->toBeGreaterThan(0);
});
```

Feed visibility test:

```php
it('seeded published posts are visible through FeedQuery', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $posts = app(FeedQuery::class)->handle()->items();

    expect(collect($posts)->every(fn ($post) => $post->status === PostStatus::Published))->toBeTrue();
});
```

Adapt FeedQuery API to actual implementation.

### Implementation

Create:

```bash
php artisan make:seeder DemoPublishedPostsSeeder
```

Recommended published posts:

```txt
Demo: Homemade Italian Pasta
Demo: Restaurant Sushi Plate
Demo: Mexican Street Tacos
Demo: American Breakfast Stack
Demo: Healthy Asian Bowl
Demo: Chocolate Dessert Plate
```

For each post:

```php
$post = Post::factory()
    ->for($author)
    ->published()
    ->create([
        'title' => 'Demo: Homemade Italian Pasta',
        'description' => 'Fresh pasta with tomato sauce and basil.',
        'image_path' => 'demo/posts/pasta.jpg',
        'thumbnail_url' => null,
        'source_url' => null,
        'origin_truth' => OriginType::Homemade,
        'cuisine_truth' => CuisineType::Italian,
        'published_at' => now()->subHours(3),
    ]);

$post->tags()->sync([...]);
```

If actual enum names differ, adapt.

Do not rely on remote image URLs.

### Acceptance criteria

- Published posts seeder exists.
- At least 6 published posts exist.
- Posts belong to seeded users.
- Posts have tags.
- Posts have realistic titles/descriptions.
- Posts have image placeholders or demo image paths.
- Public feed can show them.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Orchestrator wired.
- Tests pass.
- Коммит: `RG-589: Create seed published posts`

### Files likely touched

```txt
database/seeders/DemoPublishedPostsSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoPublishedPostsSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-590 — Create Seed Pending Posts

**Area:** Seed Data / Moderation Posts  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-590-create-seed-pending-posts`  
**Base branch:** develop
**Depends on:** RG-589

### Goal

Создать pending posts для moderation UI/admin checks.

### TDD step

Seeder test:

```php
it('seeds pending demo posts', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Post::query()->where('status', PostStatus::Pending)->count())
        ->toBeGreaterThanOrEqual(3);
});
```

Public feed exclusion test:

```php
it('seeded pending posts are not visible in public feed', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $pendingTitle = Post::query()
        ->where('status', PostStatus::Pending)
        ->firstOrFail()
        ->title;

    $this->get(route('feed'))
        ->assertDontSee($pendingTitle);
});
```

Adapt route name to actual feed route.

### Implementation

Create:

```bash
php artisan make:seeder DemoPendingPostsSeeder
```

Recommended pending posts:

```txt
Demo Pending: Needs Moderation 01
Demo Pending: Needs Moderation 02
Demo Pending: Needs Moderation 03
```

Implementation:

```php
Post::factory()
    ->for($author)
    ->pending()
    ->create([
        'title' => 'Demo Pending: Needs Moderation 01',
        'published_at' => null,
    ]);
```

Attach tags.

Do not auto-approve them.

### Acceptance criteria

- Pending posts seeder exists.
- At least 3 pending posts exist.
- Pending posts belong to users.
- Pending posts have tags/image placeholders.
- Pending posts are not visible in public feed.
- Pending posts are visible in moderation/admin filters.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Orchestrator wired.
- Tests pass.
- Коммит: `RG-590: Create seed pending posts`

### Files likely touched

```txt
database/seeders/DemoPendingPostsSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoPendingPostsSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-591 — Create Seed Hidden Posts

**Area:** Seed Data / Moderation Posts  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-591-create-seed-hidden-posts`  
**Base branch:** develop
**Depends on:** RG-590

### Goal

Создать hidden posts для admin/moderation checks.

### TDD step

Seeder test:

```php
it('seeds hidden demo posts', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Post::query()->where('status', PostStatus::Hidden)->count())
        ->toBeGreaterThanOrEqual(2);
});
```

Public feed exclusion:

```php
it('seeded hidden posts are not visible in public feed', function () {
    $this->seed(DemoDatabaseSeeder::class);

    $hiddenTitle = Post::query()
        ->where('status', PostStatus::Hidden)
        ->firstOrFail()
        ->title;

    $this->get(route('feed'))
        ->assertDontSee($hiddenTitle);
});
```

### Implementation

Create:

```bash
php artisan make:seeder DemoHiddenPostsSeeder
```

Recommended hidden posts:

```txt
Demo Hidden: Removed From Feed 01
Demo Hidden: Removed From Feed 02
```

Implementation:

```php
Post::factory()
    ->for($author)
    ->hidden()
    ->create([
        'title' => 'Demo Hidden: Removed From Feed 01',
        'hidden_at' => now()->subDay(), // if column exists
    ]);
```

Attach tags.

Do not create reports automatically here unless RG-594 needs them.

### Acceptance criteria

- Hidden posts seeder exists.
- At least 2 hidden posts exist.
- Hidden posts belong to users.
- Hidden posts have tags/image placeholders.
- Hidden posts are not visible in public feed.
- Hidden posts are available for admin hidden filter.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Orchestrator wired.
- Tests pass.
- Коммит: `RG-591: Create seed hidden posts`

### Files likely touched

```txt
database/seeders/DemoHiddenPostsSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoHiddenPostsSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-592 — Create Seed Comments

**Area:** Seed Data / Comments  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-592-create-seed-comments`  
**Base branch:** develop
**Depends on:** RG-591

### Goal

Создать comments для published posts и comment UI checks.

### TDD step

Seeder test:

```php
it('seeds comments for published posts', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Comment::query()->count())->toBeGreaterThanOrEqual(10);

    $comment = Comment::query()->firstOrFail();

    expect($comment->post)->not->toBeNull();
    expect($comment->user)->not->toBeNull();
});
```

Counter consistency test:

```php
it('keeps comments_count consistent after seeding comments', function () {
    $this->seed(DemoDatabaseSeeder::class);

    Post::query()->each(function (Post $post) {
        expect($post->comments_count)->toBe(
            $post->comments()->where('status', CommentStatus::Visible)->count()
        );
    });
});
```

If hidden comments count should be excluded, use actual business rule.

### Implementation

Create:

```bash
php artisan make:seeder DemoCommentsSeeder
```

Implementation:

```php
$publishedPosts = Post::query()
    ->where('status', PostStatus::Published)
    ->get();

$users = User::query()
    ->where('status', UserStatus::Active)
    ->get();

foreach ($publishedPosts as $post) {
    Comment::factory()
        ->count(2)
        ->for($post)
        ->for($users->random(), 'user')
        ->create([
            'body' => 'Demo comment for ' . $post->title,
            'status' => CommentStatus::Visible,
        ]);
}
```

After comments:

```txt
recalculate comments_count
```

Possible approach:

```php
$post->forceFill([
    'comments_count' => $post->comments()->visible()->count(),
])->save();
```

or existing `RecalculatePostCountersAction`.

Avoid notifications during seeding.

### Acceptance criteria

- Comments seeder exists.
- At least 10 comments exist.
- Comments belong to posts.
- Comments belong to users.
- Comments are mostly on published posts.
- comments_count is consistent.
- No notifications are required from seeding.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Counters handled.
- Tests pass.
- Коммит: `RG-592: Create seed comments`

### Files likely touched

```txt
database/seeders/DemoCommentsSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoCommentsSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-593 — Create Seed Votes

**Area:** Seed Data / Votes  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-593-create-seed-votes`  
**Base branch:** develop
**Depends on:** RG-592

### Goal

Создать votes для demo counters, hot score and ranking checks.

### TDD step

Seeder test:

```php
it('seeds post votes', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(PostVote::query()->count())->toBeGreaterThan(0);
});
```

Counter consistency:

```php
it('keeps vote counters consistent after seeding votes', function () {
    $this->seed(DemoDatabaseSeeder::class);

    Post::query()->each(function (Post $post) {
        expect($post->upvotes_count)->toBe(
            $post->postVotes()->where('value', VoteValue::Up)->count()
        );

        expect($post->downvotes_count)->toBe(
            $post->postVotes()->where('value', VoteValue::Down)->count()
        );
    });
});
```

Adapt `VoteValue`/relation names.

Hot score test:

```php
it('seeds posts with recalculated hot scores', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Post::query()->where('status', PostStatus::Published)->where('hot_score', '>', 0)->count())
        ->toBeGreaterThan(0);
});
```

### Implementation

Create:

```bash
php artisan make:seeder DemoVotesSeeder
```

Seed:

```txt
- up/down post votes;
- origin votes if origin voting exists;
- cuisine votes if cuisine voting exists.
```

Minimum required by task:

```txt
post_votes
```

But because app has origin/cuisine voting, seed those too if models exist.

Implementation pattern:

```php
foreach ($publishedPosts as $post) {
    foreach ($users->random(min(4, $users->count())) as $user) {
        if ($user->id === $post->user_id) {
            continue;
        }

        PostVote::updateOrCreate(
            ['user_id' => $user->id, 'post_id' => $post->id],
            ['value' => fake()->boolean(80) ? VoteValue::Up : VoteValue::Down],
        );
    }
}
```

After votes:

```txt
- recalculate counters;
- recalculate hot_score.
```

Use existing actions:

```php
app(RecalculatePostCountersAction::class)->handle($post);
app(RecalculatePostScoreAction::class)->handle($post);
```

### Acceptance criteria

- Votes seeder exists.
- Post votes exist.
- Votes belong to real users/posts.
- Unique user/post vote constraint respected.
- Vote counters are consistent.
- hot_score recalculated for published posts.
- Optional origin/cuisine votes seeded if models exist.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Counters/hot scores recalculated.
- Tests pass.
- Коммит: `RG-593: Create seed votes`

### Files likely touched

```txt
database/seeders/DemoVotesSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoVotesSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-594 — Create Seed Reports

**Area:** Seed Data / Reports  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-594-create-seed-reports`  
**Base branch:** develop
**Depends on:** RG-593

### Goal

Создать reports для moderation dashboard, ReportResource, reported filters.

### TDD step

Seeder test:

```php
it('seeds reports for posts and comments', function () {
    $this->seed(DemoDatabaseSeeder::class);

    expect(Report::query()->count())->toBeGreaterThanOrEqual(4);

    expect(Report::query()->where('reportable_type', Post::class)->exists())->toBeTrue();
    expect(Report::query()->where('reportable_type', Comment::class)->exists())->toBeTrue();
});
```

Counter consistency:

```php
it('keeps reports_count consistent for reported posts and comments', function () {
    $this->seed(DemoDatabaseSeeder::class);

    Post::query()->each(function (Post $post) {
        expect($post->reports_count)->toBe(
            Report::query()
                ->where('reportable_type', Post::class)
                ->where('reportable_id', $post->id)
                ->count()
        );
    });

    Comment::query()->each(function (Comment $comment) {
        expect($comment->reports_count)->toBe(
            Report::query()
                ->where('reportable_type', Comment::class)
                ->where('reportable_id', $comment->id)
                ->count()
        );
    });
});
```

Adapt morph column names if different.

### Implementation

Create:

```bash
php artisan make:seeder DemoReportsSeeder
```

Seed cases:

```txt
- open report for published post;
- open report for visible comment;
- resolved report optional;
- ignored report optional if ReportStatus::Ignored exists.
```

Implementation:

```php
Report::updateOrCreate(
    [
        'user_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
    ],
    [
        'reason' => ReportReason::Spam,
        'message' => 'Demo report for moderation testing.',
        'status' => ReportStatus::Open,
    ],
);
```

After reports:

```php
$post->forceFill([
    'reports_count' => $post->reports()->count(),
    'needs_review' => true, // if column exists and threshold rule expects it
])->save();

$comment->forceFill([
    'reports_count' => $comment->reports()->count(),
])->save();
```

Do not hide target automatically.

### Acceptance criteria

- Reports seeder exists.
- At least 4 reports exist.
- At least one post report exists.
- At least one comment report exists.
- reports_count on posts/comments is consistent.
- ReportResource reported filters have data.
- ModerationDashboard latest reports has data.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Report counters handled.
- Tests pass.
- Коммит: `RG-594: Create seed reports`

### Files likely touched

```txt
database/seeders/DemoReportsSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoReportsSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-595 — Add Demo Admin Account

**Area:** Seed Data / Admin User  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-595-add-demo-admin-account`  
**Base branch:** develop
**Depends on:** RG-594

### Goal

Добавить стабильный local-only demo admin account.

### TDD step

Seeder test:

```php
it('seeds demo admin account', function () {
    $this->seed(DemoAdminSeeder::class);

    $admin = User::query()->where('email', 'admin@rateguru.test')->first();

    expect($admin)->not->toBeNull();
    expect($admin->username)->toBe('admin');
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->status)->toBe(UserStatus::Active);
});
```

Password hash test:

```php
it('seeds demo admin with hashed password', function () {
    $this->seed(DemoAdminSeeder::class);

    $admin = User::where('email', 'admin@rateguru.test')->firstOrFail();

    expect(Hash::check('password', $admin->password))->toBeTrue();
    expect($admin->password)->not->toBe('password');
});
```

Production guard test, if feasible:

```php
it('does not seed demo admin in production environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->seed(DemoAdminSeeder::class);

    expect(User::where('email', 'admin@rateguru.test')->exists())->toBeFalse();
});
```

Environment override tests can be brittle. If not practical, document guard and test local behavior.

### Implementation

Create:

```bash
php artisan make:seeder DemoAdminSeeder
```

Implementation:

```php
if (! app()->environment(['local', 'testing'])) {
    return;
}

User::updateOrCreate(
    ['email' => 'admin@rateguru.test'],
    [
        'name' => 'Demo Admin',
        'username' => 'admin',
        'password' => Hash::make(env('DEMO_ADMIN_PASSWORD', 'password')),
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
        'avatar_url' => null,
    ],
);
```

Call from `DemoDatabaseSeeder`.

Do not use real email domain.

### Acceptance criteria

- DemoAdminSeeder exists.
- `admin@rateguru.test` exists in local/test seed.
- Role = admin.
- Status = active.
- Password is hashed.
- Seeder is idempotent.
- Production guard exists.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Orchestrator wired.
- Tests pass.
- Коммит: `RG-595: Add demo admin account`

### Files likely touched

```txt
database/seeders/DemoAdminSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoAdminSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-596 — Add Demo Moderator Account

**Area:** Seed Data / Moderator User  
**Type:** Seeder  
**Priority:** P0  
**Branch:** `feature/RG-596-add-demo-moderator-account`  
**Base branch:** develop
**Depends on:** RG-595

### Goal

Добавить стабильный local-only demo moderator account.

### TDD step

Seeder test:

```php
it('seeds demo moderator account', function () {
    $this->seed(DemoModeratorSeeder::class);

    $moderator = User::query()->where('email', 'moderator@rateguru.test')->first();

    expect($moderator)->not->toBeNull();
    expect($moderator->username)->toBe('moderator');
    expect($moderator->role)->toBe(UserRole::Moderator);
    expect($moderator->status)->toBe(UserStatus::Active);
});
```

Password hash test:

```php
it('seeds demo moderator with hashed password', function () {
    $this->seed(DemoModeratorSeeder::class);

    $moderator = User::where('email', 'moderator@rateguru.test')->firstOrFail();

    expect(Hash::check('password', $moderator->password))->toBeTrue();
    expect($moderator->password)->not->toBe('password');
});
```

Access smoke test:

```php
it('demo moderator can access filament panel', function () {
    $this->seed(DemoModeratorSeeder::class);

    $moderator = User::where('email', 'moderator@rateguru.test')->firstOrFail();

    $this->actingAs($moderator)
        ->get('/admin')
        ->assertOk();
});
```

Adapt admin path if different.

### Implementation

Create:

```bash
php artisan make:seeder DemoModeratorSeeder
```

Implementation:

```php
if (! app()->environment(['local', 'testing'])) {
    return;
}

User::updateOrCreate(
    ['email' => 'moderator@rateguru.test'],
    [
        'name' => 'Demo Moderator',
        'username' => 'moderator',
        'password' => Hash::make(env('DEMO_MODERATOR_PASSWORD', 'password')),
        'role' => UserRole::Moderator,
        'status' => UserStatus::Active,
        'avatar_url' => null,
    ],
);
```

Call from `DemoDatabaseSeeder`.

### Acceptance criteria

- DemoModeratorSeeder exists.
- `moderator@rateguru.test` exists in local/test seed.
- Role = moderator.
- Status = active.
- Password is hashed.
- Seeder is idempotent.
- Production guard exists.
- Moderator can access admin panel.
- Tests pass.

### Definition of Done

- Tests written.
- Seeder implemented.
- Orchestrator wired.
- Tests pass.
- Коммит: `RG-596: Add demo moderator account`

### Files likely touched

```txt
database/seeders/DemoModeratorSeeder.php
database/seeders/DemoDatabaseSeeder.php
tests/Feature/Seeders/DemoModeratorSeederTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-597 — Add Database Seeder Command Docs

**Area:** Docs / Seed Data  
**Type:** Docs  
**Priority:** P0  
**Branch:** `feature/RG-597-add-database-seeder-command-docs`  
**Base branch:** develop
**Depends on:** RG-596

### Goal

Добавить документацию по запуску seed data и demo credentials.

### TDD step

Docs existence test:

```php
it('has seed data documentation', function () {
    expect(file_exists(base_path('docs/dev/seed-data.md')))->toBeTrue();
});
```

Docs content test:

```php
it('documents seed commands and demo credentials warning', function () {
    $docs = file_get_contents(base_path('docs/dev/seed-data.md'));

    expect($docs)->toContain('php artisan migrate:fresh --seed');
    expect($docs)->toContain('admin@rateguru.test');
    expect($docs)->toContain('moderator@rateguru.test');
    expect($docs)->toContain('local-only');
});
```

### Implementation

Create:

```txt
docs/dev/seed-data.md
```

Recommended content:

```md
# RateGuru Seed Data

## Local reset

```bash
php artisan migrate:fresh --seed
```

## Demo accounts

Admin:

```txt
Email: admin@rateguru.test
Password: password
```

Moderator:

```txt
Email: moderator@rateguru.test
Password: password
```

## Dataset summary

- normal users
- trusted/banned/shadowbanned users
- tags
- published posts
- pending posts
- hidden posts
- comments
- votes
- reports

## Safety

These credentials are local-only. Do not seed demo accounts in production.
```

Update README if needed with link:

```md
See docs/dev/seed-data.md
```

Final full seed smoke test:

```php
it('runs full database seeder successfully', function () {
    $this->artisan('migrate:fresh', ['--seed' => true])
        ->assertExitCode(0);
});
```

This test can be heavy; if it slows suite, keep as separate seeder feature test.

### Acceptance criteria

- `docs/dev/seed-data.md` exists.
- Docs include `php artisan migrate:fresh --seed`.
- Docs list demo admin credentials.
- Docs list demo moderator credentials.
- Docs warn local-only / not production.
- Docs summarize seeded dataset.
- README links to seed docs if appropriate.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Docs tests written.
- Docs added.
- Full seed command manually verified.
- Tests/build pass.
- Коммит: `RG-597: Add database seeder command docs`

### Files likely touched

```txt
docs/dev/seed-data.md
README.md
tests/Feature/Docs/SeedDataDocsTest.php
tests/Feature/Seeders/DatabaseSeederSmokeTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 10. Phase 36 Completion Criteria

Phase 36 завершена, когда:

```txt
- RG-587–RG-597 выполнены;
- Demo users exist;
- Demo tags exist;
- Published posts exist;
- Pending posts exist;
- Hidden posts exist;
- Comments exist;
- Votes exist;
- Reports exist;
- Demo admin exists;
- Demo moderator exists;
- Demo accounts are local/test-only;
- Demo account passwords are hashed;
- Seeded posts belong to users;
- Seeded posts have tags;
- Seeded comments belong to posts/users;
- Seeded votes have consistent counters;
- Seeded reports have consistent reports_count;
- Published posts appear in public feed;
- Pending/hidden posts do not appear in public feed;
- Moderation/admin pages have useful data;
- seed docs exist;
- php artisan migrate:fresh --seed works locally;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 36

Без отдельной задачи нельзя:

```txt
- добавлять production import;
- добавлять реальные пользовательские данные;
- скачивать изображения из интернета;
- настраивать Cloudinary/S3 seed upload;
- добавлять UI polish;
- создавать browser smoke tests;
- создавать visual regression baselines;
- добавлять scheduled jobs;
- добавлять queue workers;
- менять authorization policies;
- добавлять API endpoints;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-587 Create seed users
RG-588 Create seed tags
RG-589 Create seed published posts
RG-590 Create seed pending posts
RG-591 Create seed hidden posts
RG-592 Create seed comments
RG-593 Create seed votes
RG-594 Create seed reports
RG-595 Add demo admin account
RG-596 Add demo moderator account
RG-597 Add database seeder command docs
```
---

# 13. Release

После завершения Phase 36:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh --seed

git checkout -b release/v0.2.17-phase36-seed-data
git push -u origin release/v0.2.17-phase36-seed-data
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.17-phase36-seed-data -m "RateGuru Phase 36 Seed Data"
git push origin v0.2.17-phase36-seed-data
```
