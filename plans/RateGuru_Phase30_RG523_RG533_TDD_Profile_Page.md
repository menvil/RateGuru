# RateGuru — Phase 30 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 30 — Profile Page**  
Диапазон задач: **RG-523 → RG-533**  
Основа нумерации: исходный atomic backlog, где Phase 30 начинается с задачи 523 и заканчивается задачей 533.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 30 соответствует исходному блоку:

```txt
Phase 30 — Profile Page
```

Правильный диапазон Phase 30:

```txt
RG-523 — Add profile route
RG-524 — Create ProfilePage Livewire component
RG-525 — Test profile page renders user
RG-526 — Render profile header
RG-527 — Render user avatar
RG-528 — Render username
RG-529 — Render user stats
RG-530 — Render user posts grid
RG-531 — Test profile only shows published posts
RG-532 — Add edit profile placeholder
RG-533 — Add report user button placeholder
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 31 начинается с `RG-534` и делает **Notifications Foundation**. Поэтому Phase 30 не должна добавлять notifications, notification bell, notification database changes или approval/comment notifications.
---

# 2. Цель Phase 30

Phase 30 добавляет публичную страницу профиля пользователя.

После Phase 30 пользователь должен уметь:

```txt
- открыть публичный профиль по username;
- увидеть profile header;
- увидеть avatar;
- увидеть username;
- увидеть публичные stats;
- увидеть grid опубликованных posts пользователя;
- не увидеть pending/hidden/rejected posts;
- увидеть placeholder для edit profile у владельца профиля;
- увидеть placeholder для report user у чужого профиля.
```

Это первая версия профиля. Она не должна превращаться в полноценный social profile editor.
---

# 3. Scope Phase 30

## Входит

```txt
- profile route;
- ProfilePage Livewire component;
- route model/user resolution by username;
- 404 for missing user;
- profile header;
- avatar rendering;
- username rendering;
- public user stats;
- public posts grid;
- published-only profile posts;
- edit profile placeholder;
- report user button placeholder;
- tests for route/component and visibility rules.
```

## Не входит

```txt
- edit profile form;
- avatar upload/editing;
- username change;
- bio field migration;
- follow/followers;
- user reports backend;
- ReportUserAction;
- user report modal;
- private profiles;
- profile SEO/OpenGraph;
- profile tabs;
- profile comments;
- activity feed;
- notifications.
```

`edit profile placeholder` — это именно placeholder, не форма.  
`report user button placeholder` — это именно placeholder, не создание user report.
---

# 4. Product / UX Decisions

## 4.1. Profile URL

Рекомендуемый публичный route:

```txt
/@{username}
```

или:

```txt
/u/{username}
```

Для Laravel route проще и безопаснее начать с:

```txt
/u/{username}
```

Почему не сразу `/@ivan`:

```txt
- `@` в URL выглядит красиво, но может конфликтовать с routing/copying/search;
- `/u/{username}` проще тестировать;
- можно позже добавить alias `/@{username}` отдельной фазой.
```

Phase 30 фиксирует:

```txt
GET /u/{username}
```

Route name:

```txt
profile.show
```

## 4.2. Username is public identity

Profile должен искать пользователя по:

```txt
users.username
```

Не по numeric id.

Причина:

```txt
- публичный профиль должен быть читаемым;
- numeric ids раскрывают внутреннюю последовательность;
- username уже был частью User foundation.
```

If username is nullable, users without username should not have public profile route until username exists.

## 4.3. Who can view profile

Public profile is visible to:

```txt
guest
authenticated user
moderator/admin
```

But content visibility differs:

```txt
guest/normal user → only published posts
moderator/admin → still public profile should show published posts only in Phase 30
```

Admin can see hidden/pending posts in admin resources, not public profile.

## 4.4. Banned/shadowbanned user profiles

MVP behavior:

```txt
- banned user profile can render header but should not expose non-published posts;
- if product wants to hide banned profiles entirely, that is a future policy decision.
```

Do not add hidden banned profile logic in Phase 30 unless earlier user status policy already requires it.

## 4.5. User stats

Stats must be public-safe and cheap.

Recommended MVP stats:

```txt
- published posts count;
- total upvotes across published posts;
- comments received on published posts.
```

Alternative simpler MVP:

```txt
- published posts count;
- total votes score = upvotes_count - downvotes_count;
- joined date.
```

For Phase 30, use:

```txt
Published posts
Total upvotes
Comments received
```

Rules:

```txt
- count only published posts;
- do not include hidden/pending/rejected posts;
- do not count private moderation data;
- do not count reports_count.
```

Reports are moderation data, not public stats.

## 4.6. User posts grid

Profile posts grid should use the same visual language as feed.

Options:

```txt
Option A: reuse PostCard
Option B: create compact ProfilePostGridItem
```

Recommended for Phase 30:

```txt
reuse PostCard or a lightweight grid item, but do not introduce duplicate voting logic.
```

If `PostCard` is too large for profile grid, create:

```txt
resources/views/components/profile/post-grid-item.blade.php
```

But backlog does not explicitly request a new component. Keep it simple.

## 4.7. Edit profile placeholder

Only profile owner sees:

```txt
Edit profile
```

But it is disabled/placeholder:

```txt
button disabled
or link to #
or small note "Profile editing coming soon"
```

Do not create edit form.  
Do not add avatar upload.

## 4.8. Report user button placeholder

Viewer sees `Report user` only when:

```txt
viewer is authenticated
viewer is not the profile owner
```

Guest can either:

```txt
- not see the button;
- or see disabled "Log in to report".
```

Recommended MVP:

```txt
guest does not see report user button
authenticated non-owner sees disabled placeholder
owner does not see report user button
```

Because no `ReportUserAction` exists yet. Do not fake user reports through content reports.
---

# 5. Architecture Rules

## 5.1. ProfilePage owns profile rendering

Create:

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
```

Do not implement profile logic inside route closure/view only.

## 5.2. Route resolves by username

Route:

```php
Route::get('/u/{username}', ProfilePage::class)->name('profile.show');
```

Component mount:

```php
public function mount(string $username): void
{
    $this->user = User::query()
        ->where('username', $username)
        ->firstOrFail();
}
```

Do not accept arbitrary user id in public URL.

## 5.3. Published-only posts query

Profile posts query must be:

```php
Post::query()
    ->published()
    ->where('user_id', $this->user->id)
    ->latest()
```

Do not reuse admin queries.  
Do not forget status filter.

## 5.4. Avoid N+1

Profile posts query should eager-load:

```txt
user
tags
```

and any data PostCard requires.

If `PostCard` needs votes/comments counts from columns, no need to eager-load votes.

## 5.5. No user report backend

`Report user` placeholder must not create `reports` rows with `reportable_type = User::class`, because Phase 19 only supported Post and Comment reports.

If future user reports are desired, create a separate backend phase:

```txt
ReportUserAction
user report reason taxonomy
user report UI
user reports moderation flow
```

## 5.6. No profile editing backend

`Edit profile` placeholder must not mutate:

```txt
name
username
avatar_url
bio
```

No migration for `bio` in this phase.
---

# 6. Design Constraints

Profile page should reuse RateGuru dark visual language:

```txt
- dark background;
- rounded profile header card;
- avatar consistent with base avatar component;
- username prominent;
- stats as compact cards/pills;
- posts grid responsive;
- empty state if user has no published posts;
- mobile-first layout.
```

Check existing design docs:

```txt
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-8-feed-ui-review.md
docs/design/phase-12-post-show-page-review.md
```

If review docs are missing, record it in Phase 30 review notes, but do not block implementation.
---

# 7. GitFlow для Phase 30

## Base branch

Все задачи Phase 30 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-523-add-profile-route
feature/RG-524-create-profile-page-livewire-component
feature/RG-533-add-report-user-button-placeholder
```

## Commit format

```txt
RG-523: Add profile route
RG-524: Create ProfilePage Livewire component
RG-533: Add report user button placeholder
```

## Release branch

После выполнения `RG-523`–`RG-533`:

```txt
release/v0.2.11-phase30-profile-page
```

## Tag

После merge release branch в `main`:

```txt
v0.2.11-phase30-profile-page
```
---

# 8. TDD Rules for Phase 30

## Для route/component

Тестировать:

```txt
- profile route exists;
- profile route resolves by username;
- missing username returns 404;
- profile page renders selected user.
```

## Для UI sections

Тестировать Livewire/rendered output:

```txt
- profile header renders;
- avatar renders;
- username renders;
- stats render;
- posts grid renders published posts.
```

## Для visibility

Обязательно test-first:

```txt
- pending posts are not visible;
- hidden posts are not visible;
- rejected posts are not visible;
- published posts are visible.
```

## Для placeholders

Тестировать:

```txt
- owner sees edit profile placeholder;
- non-owner does not see edit profile placeholder;
- authenticated non-owner sees report user placeholder;
- owner does not see report user placeholder;
- guest does not trigger report backend.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Profile / Tests
Type: Test / Feature / Component / Route / Layout
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
- Profile shows only public-safe data
- No backend/report/edit logic hidden inside placeholders
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 30 Atomic Tasks
---

## RG-523 — Add Profile Route

**Area:** Routes / Profile  
**Type:** Route  
**Priority:** P0  
**Branch:** `feature/RG-523-add-profile-route`  
**Base branch:** develop
**Depends on:** RG-522

### Goal

Добавить публичный route для профиля пользователя.

### TDD step

Feature route test:

```php
it('has profile route by username', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    $this->get(route('profile.show', ['username' => 'chef_ivan']))
        ->assertOk();
});
```

Missing user test:

```php
it('returns 404 for missing profile username', function () {
    $this->get(route('profile.show', ['username' => 'missing_user']))
        ->assertNotFound();
});
```

Тест может падать до создания route/component.

### Implementation

Добавить route в `routes/web.php`:

```php
use App\Livewire\Profile\ProfilePage;

Route::get('/u/{username}', ProfilePage::class)
    ->name('profile.show');
```

Если `ProfilePage` ещё не создан, route может временно ссылаться на placeholder view, но лучше сразу оставить тест падающим до RG-524.  
Однако задача RG-523 должна закончиться проходящим smoke route test, поэтому допустимо создать временный minimal invokable/placeholder only if needed.

Рекомендация:

```txt
RG-523 creates route and a minimal placeholder view only if necessary.
RG-524 replaces with actual Livewire component.
```

Но так как RG-524 сразу next task, можно сделать route test less strict in RG-523 and full render in RG-525.

### Acceptance criteria

- Route `/u/{username}` exists.
- Route name = `profile.show`.
- Route does not use numeric user id.
- Missing username returns 404 once component exists.
- No profile UI implementation yet.

### Definition of Done

- Route test written.
- Route added.
- No unrelated UI work.
- Коммит: `RG-523: Add profile route`

### Files likely touched

```txt
routes/web.php
tests/Feature/Profile/ProfileRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-524 — Create ProfilePage Livewire Component

**Area:** Livewire / Profile  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-524-create-profile-page-livewire-component`  
**Base branch:** develop
**Depends on:** RG-523

### Goal

Создать Livewire component `ProfilePage`.

### TDD step

Livewire component test:

```php
it('can render profile page component', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertStatus(200);
});
```

Missing username component test:

```php
it('fails profile page component for missing username', function () {
    Livewire::test(ProfilePage::class, ['username' => 'missing_user']);
})->throws(ModelNotFoundException::class);
```

### Implementation

Создать:

```bash
php artisan make:livewire Profile/ProfilePage
```

Файлы:

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
```

Class skeleton:

```php
final class ProfilePage extends Component
{
    public User $profileUser;

    public function mount(string $username): void
    {
        $this->profileUser = User::query()
            ->where('username', $username)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.profile.profile-page');
    }
}
```

View skeleton:

```blade
<div data-testid="profile-page">
    Profile
</div>
```

Update route from RG-523 to point to component if not already.

### Acceptance criteria

- `ProfilePage` exists.
- Accepts username route param.
- Loads user by username.
- Missing username fails with 404/model not found.
- View has `data-testid="profile-page"`.
- Tests pass.

### Definition of Done

- Tests written.
- Component created.
- Route uses component.
- Tests pass.
- Коммит: `RG-524: Create ProfilePage Livewire component`

### Files likely touched

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
routes/web.php
tests/Feature/Livewire/ProfilePageTest.php
tests/Feature/Profile/ProfileRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-525 — Test Profile Page Renders User

**Area:** Tests / Profile  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-525-test-profile-page-renders-user`  
**Base branch:** develop
**Depends on:** RG-524

### Goal

Написать тест: profile page рендерит данные выбранного пользователя.

### TDD step

Feature/route test:

```php
it('renders profile page for user', function () {
    $user = User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
    ]);

    $this->get(route('profile.show', ['username' => 'chef_ivan']))
        ->assertOk()
        ->assertSee('chef_ivan')
        ->assertSee('Ivan Chef');
});
```

Component test:

```php
it('renders selected user in profile page component', function () {
    $user = User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('chef_ivan')
        ->assertSee('Ivan Chef');
});
```

Тест должен упасть до rendering implementation.

### Implementation

Только добавить тесты, если skeleton ещё не показывает user fields.  
Если нужно минимально пройти тест, вывести basic user data:

```blade
{{ $profileUser->name }}
{{ '@' . $profileUser->username }}
```

Но лучше детальный header делать в RG-526/RG-528.  
Для этой задачи достаточно вывести username/name в простом block.

### Acceptance criteria

- Test covers route rendering.
- Test covers selected user, not current auth user.
- Username visible.
- Name visible if available.
- Tests pass after minimal render.

### Definition of Done

- Tests written.
- Minimal selected user rendering works.
- No header/stats/grid yet.
- Коммит: `RG-525: Test profile page renders user`

### Files likely touched

```txt
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Profile/ProfileRouteTest.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-526 — Render Profile Header

**Area:** UI / Profile  
**Type:** Layout  
**Priority:** P0  
**Branch:** `feature/RG-526-render-profile-header`  
**Base branch:** develop
**Depends on:** RG-525

### Goal

Оформить profile header section.

### TDD step

Livewire/render test:

```php
it('renders profile header section', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-header"', false);
});
```

### Implementation

В `profile-page.blade.php`:

```blade
<div class="min-h-screen bg-rg-background">
    <section
        data-testid="profile-header"
        class="rounded-2xl border border-rg-border bg-rg-card p-6 shadow-sm"
    >
        {{-- avatar, username, stats, actions will be inserted by later tasks --}}
    </section>
</div>
```

Use existing UI components if available:

```txt
x-ui.card
x-ui.badge
x-ui.button
x-ui.avatar
```

Header should include layout slots/areas:

```txt
avatar area
identity area
stats area
actions area
```

But do not fill all content yet.

### Acceptance criteria

- Profile header section exists.
- Uses RateGuru card/surface styling.
- Has stable test id.
- Mobile-safe basic layout.
- Test passes.

### Definition of Done

- Test written.
- Header section rendered.
- No unrelated profile features.
- Коммит: `RG-526: Render profile header`

### Files likely touched

```txt
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-527 — Render User Avatar

**Area:** UI / Profile  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-527-render-user-avatar`  
**Base branch:** develop
**Depends on:** RG-526

### Goal

Отобразить avatar пользователя в profile header.

### TDD step

Livewire/render test:

```php
it('renders user avatar on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-avatar"', false)
        ->assertSee('https://example.test/avatar.jpg', false);
});
```

Fallback test:

```php
it('renders avatar fallback when user has no avatar url', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
        'avatar_url' => null,
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-avatar"', false);
});
```

### Implementation

Use existing avatar component:

```blade
<div data-testid="profile-avatar">
    <x-ui.avatar
        :src="$profileUser->avatar_url"
        :name="$profileUser->name ?? $profileUser->username"
        size="lg"
    />
</div>
```

If avatar component API differs, adapt.

No avatar upload/edit logic.

### Acceptance criteria

- Avatar area visible.
- Uses user avatar_url if available.
- Fallback works when avatar_url null.
- Uses base avatar component.
- No upload/edit behavior.
- Tests pass.

### Definition of Done

- Tests written.
- Avatar rendered.
- Fallback works.
- Коммит: `RG-527: Render user avatar`

### Files likely touched

```txt
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-528 — Render Username

**Area:** UI / Profile  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-528-render-username`  
**Base branch:** develop
**Depends on:** RG-527

### Goal

Отобразить username и display name в profile header.

### TDD step

Livewire/render test:

```php
it('renders username and display name on profile page', function () {
    $user = User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-identity"', false)
        ->assertSee('Ivan Chef')
        ->assertSee('@chef_ivan');
});
```

No name fallback test:

```php
it('renders username when display name is missing', function () {
    $user = User::factory()->create([
        'name' => null,
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('@chef_ivan');
});
```

### Implementation

In header:

```blade
<div data-testid="profile-identity">
    <h1 class="text-2xl font-semibold text-rg-text">
        {{ $profileUser->name ?: $profileUser->username }}
    </h1>

    <p class="text-sm text-rg-muted">
        {{ '@' . $profileUser->username }}
    </p>
</div>
```

Optional role/status badge only if public-safe:

```txt
Do not show banned/shadowbanned/trust status publicly unless product wants it.
```

Do not show email publicly.

### Acceptance criteria

- Display name visible when present.
- Username visible with `@`.
- Missing display name fallback works.
- Email is not shown.
- User role/status is not leaked unless intentionally public.
- Tests pass.

### Definition of Done

- Tests written.
- Identity block rendered.
- No private data exposed.
- Коммит: `RG-528: Render username`

### Files likely touched

```txt
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-529 — Render User Stats

**Area:** UI / Profile  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-529-render-user-stats`  
**Base branch:** develop
**Depends on:** RG-528

### Goal

Отобразить публичные user stats.

### TDD step

Livewire/render test:

```php
it('renders public user stats on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'upvotes_count' => 5,
        'comments_count' => 2,
    ]);

    Post::factory()->for($user)->published()->create([
        'upvotes_count' => 3,
        'comments_count' => 1,
    ]);

    Post::factory()->for($user)->hidden()->create([
        'upvotes_count' => 100,
        'comments_count' => 100,
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Published posts')
        ->assertSee('2')
        ->assertSee('Total upvotes')
        ->assertSee('8')
        ->assertSee('Comments received')
        ->assertSee('3')
        ->assertDontSee('100');
});
```

### Implementation

In `ProfilePage` add computed stats:

```php
public function getStatsProperty(): array
{
    $posts = Post::query()
        ->published()
        ->where('user_id', $this->profileUser->id);

    return [
        'published_posts' => (clone $posts)->count(),
        'total_upvotes' => (clone $posts)->sum('upvotes_count'),
        'comments_received' => (clone $posts)->sum('comments_count'),
    ];
}
```

If Livewire v3 uses `#[Computed]`, use it.

Render:

```blade
<div data-testid="profile-stats" class="grid grid-cols-3 gap-3">
    <x-ui.stat label="Published posts" :value="$this->stats['published_posts']" />
    <x-ui.stat label="Total upvotes" :value="$this->stats['total_upvotes']" />
    <x-ui.stat label="Comments received" :value="$this->stats['comments_received']" />
</div>
```

If no `x-ui.stat`, use small card/pill markup.

Do not include `reports_count`.

### Acceptance criteria

- Stats block exists.
- Published posts count uses only published posts.
- Total upvotes uses only published posts.
- Comments received uses only published posts.
- Hidden/pending/rejected posts do not affect stats.
- No moderation data leaked.
- Tests pass.

### Definition of Done

- Test written.
- Stats computed.
- Stats rendered.
- Tests pass.
- Коммит: `RG-529: Render user stats`

### Files likely touched

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-530 — Render User Posts Grid

**Area:** UI / Profile  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-530-render-user-posts-grid`  
**Base branch:** develop
**Depends on:** RG-529

### Goal

Отобразить grid опубликованных posts пользователя.

### TDD step

Livewire/render test:

```php
it('renders user published posts grid on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'title' => 'Published pasta',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-posts-grid"', false)
        ->assertSee('Published pasta');
});
```

Empty state test:

```php
it('renders empty state when user has no published posts', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('No published posts yet');
});
```

### Implementation

In `ProfilePage`:

```php
public function getPostsProperty(): LengthAwarePaginator
{
    return Post::query()
        ->published()
        ->where('user_id', $this->profileUser->id)
        ->with(['user', 'tags'])
        ->latest()
        ->paginate(12);
}
```

If pagination inside Livewire component:

```php
use WithPagination;
```

Render:

```blade
<section data-testid="profile-posts">
    <h2>Posts</h2>

    @if($this->posts->isEmpty())
        <x-ui.empty-state
            title="No published posts yet"
            description="This user has not published any posts yet."
        />
    @else
        <div data-testid="profile-posts-grid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($this->posts as $post)
                <x-feed.post-card :post="$post" />
            @endforeach
        </div>

        {{ $this->posts->links() }}
    @endif
</section>
```

If `PostCard` is too wide/noisy, use compact card, but do not create duplicate voting logic.

### Acceptance criteria

- Posts grid exists.
- Published posts visible.
- Empty state visible if no published posts.
- Posts sorted newest first.
- Pagination or reasonable limit exists.
- Query eager-loads required relations.
- Tests pass.

### Definition of Done

- Tests written.
- Posts query added.
- Grid rendered.
- Empty state rendered.
- Tests pass.
- Коммит: `RG-530: Render user posts grid`

### Files likely touched

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-531 — Test Profile Only Shows Published Posts

**Area:** Tests / Profile  
**Type:** Test / Guard  
**Priority:** P0  
**Branch:** `feature/RG-531-test-profile-only-shows-published-posts`  
**Base branch:** develop
**Depends on:** RG-530

### Goal

Жёстко зафиксировать, что профиль показывает только published posts.

### TDD step

Livewire/render test:

```php
it('only shows published posts on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'title' => 'Published dish',
    ]);

    Post::factory()->for($user)->pending()->create([
        'title' => 'Pending dish',
    ]);

    Post::factory()->for($user)->hidden()->create([
        'title' => 'Hidden dish',
    ]);

    Post::factory()->for($user)->rejected()->create([
        'title' => 'Rejected dish',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Published dish')
        ->assertDontSee('Pending dish')
        ->assertDontSee('Hidden dish')
        ->assertDontSee('Rejected dish');
});
```

Cross-user test:

```php
it('does not show other users posts on profile page', function () {
    $profileUser = User::factory()->create(['username' => 'chef_ivan']);
    $otherUser = User::factory()->create(['username' => 'other']);

    Post::factory()->for($profileUser)->published()->create(['title' => 'Own post']);
    Post::factory()->for($otherUser)->published()->create(['title' => 'Other post']);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Own post')
        ->assertDontSee('Other post');
});
```

### Implementation

If tests fail, fix posts query in `ProfilePage`:

```php
Post::query()
    ->published()
    ->where('user_id', $this->profileUser->id)
```

Also fix stats if they include non-published posts.

### Acceptance criteria

- Published own posts visible.
- Pending own posts hidden.
- Hidden own posts hidden.
- Rejected own posts hidden.
- Other users' posts hidden.
- Stats also use published-only logic.
- Tests pass.

### Definition of Done

- Tests written.
- Profile posts query fixed if needed.
- Stats query fixed if needed.
- Tests pass.
- Коммит: `RG-531: Test profile only shows published posts`

### Files likely touched

```txt
app/Livewire/Profile/ProfilePage.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-532 — Add Edit Profile Placeholder

**Area:** UI / Profile  
**Type:** Placeholder  
**Priority:** P1  
**Branch:** `feature/RG-532-add-edit-profile-placeholder`  
**Base branch:** develop
**Depends on:** RG-531

### Goal

Добавить placeholder для будущего edit profile.

### TDD step

Owner visibility test:

```php
it('shows edit profile placeholder to profile owner', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::actingAs($user)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Edit profile')
        ->assertSee('data-testid="edit-profile-placeholder"', false);
});
```

Non-owner test:

```php
it('does not show edit profile placeholder to other users', function () {
    $owner = User::factory()->create(['username' => 'chef_ivan']);
    $viewer = User::factory()->create(['username' => 'viewer']);

    Livewire::actingAs($viewer)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('Edit profile');
});
```

Guest test:

```php
it('does not show edit profile placeholder to guest', ...)
```

### Implementation

In `ProfilePage`:

```php
public function getIsOwnerProperty(): bool
{
    return auth()->id() === $this->profileUser->id;
}
```

In view:

```blade
@if($this->isOwner)
    <button
        type="button"
        disabled
        data-testid="edit-profile-placeholder"
        class="..."
        title="Profile editing coming soon"
    >
        Edit profile
    </button>
@endif
```

No route.  
No form.  
No modal.  
No avatar upload.

### Acceptance criteria

- Owner sees edit profile placeholder.
- Non-owner does not.
- Guest does not.
- Placeholder is disabled or clearly non-functional.
- No edit backend created.
- Tests pass.

### Definition of Done

- Tests written.
- Placeholder rendered.
- No edit profile implementation.
- Tests pass.
- Коммит: `RG-532: Add edit profile placeholder`

### Files likely touched

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-533 — Add Report User Button Placeholder

**Area:** UI / Profile  
**Type:** Placeholder  
**Priority:** P1  
**Branch:** `feature/RG-533-add-report-user-button-placeholder`  
**Base branch:** develop
**Depends on:** RG-532

### Goal

Добавить placeholder для будущего report user.

### TDD step

Authenticated non-owner visibility test:

```php
it('shows report user placeholder to authenticated non owner', function () {
    $owner = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    $viewer = User::factory()->create([
        'username' => 'viewer',
    ]);

    Livewire::actingAs($viewer)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Report user')
        ->assertSee('data-testid="report-user-placeholder"', false);
});
```

Owner hidden test:

```php
it('does not show report user placeholder to profile owner', function () {
    $owner = User::factory()->create(['username' => 'chef_ivan']);

    Livewire::actingAs($owner)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('Report user');
});
```

Guest hidden test:

```php
it('does not show report user placeholder to guest', function () {
    $owner = User::factory()->create(['username' => 'chef_ivan']);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('Report user');
});
```

No side effect test:

```php
it('does not create reports from report user placeholder', function () {
    ...
    expect(Report::query()->count())->toBe(0);
});
```

### Implementation

In `ProfilePage`:

```php
public function getCanSeeReportUserPlaceholderProperty(): bool
{
    return auth()->check()
        && auth()->id() !== $this->profileUser->id;
}
```

In view:

```blade
@if($this->canSeeReportUserPlaceholder)
    <button
        type="button"
        disabled
        data-testid="report-user-placeholder"
        title="User reporting is coming soon"
    >
        Report user
    </button>
@endif
```

Do not call `ReportContentAction`.  
Do not create `ReportUserAction`.  
Do not extend reports to `User::class` in this phase.

Add final review doc:

```txt
docs/design/phase-30-profile-page-review.md
```

Checklist:

```txt
- route works;
- header works;
- avatar fallback works;
- username visible;
- email not visible;
- stats are public-safe;
- posts grid shows only published posts;
- owner edit placeholder visible;
- report user placeholder visible only to non-owner auth user;
- mobile checked.
```

### Acceptance criteria

- Authenticated non-owner sees report user placeholder.
- Owner does not see report user placeholder.
- Guest does not see report user placeholder.
- Placeholder is disabled or clearly non-functional.
- No report row is created.
- No user report backend created.
- Design review note exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- Placeholder rendered.
- No hidden backend implementation.
- Design review note added.
- Tests/build pass.
- Коммит: `RG-533: Add report user button placeholder`

### Files likely touched

```txt
app/Livewire/Profile/ProfilePage.php
resources/views/livewire/profile/profile-page.blade.php
docs/design/phase-30-profile-page-review.md
tests/Feature/Livewire/ProfilePageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 30 Completion Criteria

Phase 30 завершена, когда:

```txt
- RG-523–RG-533 выполнены;
- profile route exists at /u/{username};
- route name is profile.show;
- ProfilePage Livewire component exists;
- profile resolves user by username;
- missing user returns 404;
- profile page renders selected user;
- profile header renders;
- user avatar renders with fallback;
- username renders;
- email is not exposed publicly;
- public stats render;
- stats count only published posts;
- user posts grid renders;
- grid shows only published posts;
- pending/hidden/rejected posts are not shown;
- other users' posts are not shown;
- owner sees edit profile placeholder;
- non-owner does not see edit profile placeholder;
- authenticated non-owner sees report user placeholder;
- owner/guest do not see report user placeholder;
- report user placeholder does not create reports;
- no edit profile backend was added;
- no user reports backend was added;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 30

Без отдельной задачи нельзя:

```txt
- создавать edit profile form;
- менять username/email/avatar;
- добавлять avatar upload;
- добавлять bio migration;
- добавлять follow/followers;
- добавлять user reports backend;
- расширять ReportContentAction на User;
- добавлять user report modal;
- показывать hidden/pending/rejected posts;
- показывать email публично;
- добавлять notifications;
- добавлять profile SEO/OpenGraph;
- добавлять activity feed;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-523 Add profile route
RG-524 Create ProfilePage Livewire component
RG-525 Test profile page renders user
RG-526 Render profile header
RG-527 Render user avatar
RG-528 Render username
RG-529 Render user stats
RG-530 Render user posts grid
RG-531 Test profile only shows published posts
RG-532 Add edit profile placeholder
RG-533 Add report user button placeholder
```
---

# 14. Release

После завершения Phase 30:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.11-phase30-profile-page
git push -u origin release/v0.2.11-phase30-profile-page
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.11-phase30-profile-page -m "RateGuru Phase 30 Profile Page"
git push origin v0.2.11-phase30-profile-page
```
