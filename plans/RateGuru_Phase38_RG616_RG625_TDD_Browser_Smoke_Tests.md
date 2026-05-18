# RateGuru — Phase 38 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 38 — Browser Smoke Tests**  
Диапазон задач: **RG-616 → RG-625**  
Основа нумерации: исходный atomic backlog, где Phase 38 начинается с задачи 616 и заканчивается задачей 625.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 38 соответствует исходному блоку:

```txt
Phase 38 — Browser Smoke Tests
```

Правильный диапазон Phase 38:

```txt
RG-616 — Add browser test for feed page load
RG-617 — Add browser test for login flow
RG-618 — Add browser test for upload modal open
RG-619 — Add browser test for post drawer open
RG-620 — Add browser test for upvote click
RG-621 — Add browser test for origin vote click
RG-622 — Add browser test for comment submit
RG-623 — Add browser test for report modal open
RG-624 — Add browser test for admin access denied to user
RG-625 — Add browser test for admin access allowed to moderator
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно:

```txt
Phase 37 заканчивается на RG-615.
Phase 38 занимает RG-616 → RG-625.
Phase 39 начинается с RG-626 и делает Visual Regression Foundation.
```

Значит Phase 38 не должна создавать screenshot baselines, visual diff tooling или PR visual checklist из Phase 39.
---

# 2. Цель Phase 38

Phase 38 добавляет минимальный browser smoke test layer.

После Phase 38 проект должен иметь автоматическую проверку, что ключевые пользовательские сценарии в реальном браузере хотя бы открываются и выполняют базовые действия:

```txt
- feed loads;
- login works;
- upload modal opens;
- post drawer opens;
- upvote click works;
- origin vote click works;
- comment submit works;
- report modal opens;
- normal user cannot access admin;
- moderator can access admin.
```

Это не visual regression.  
Это не pixel-perfect UI testing.  
Это не exhaustive E2E test suite.

Это минимальная страховка от грубых поломок Livewire/Alpine/routes/auth/admin access после будущих рефакторингов.
---

# 3. Scope Phase 38

## Входит

```txt
- browser testing setup if missing;
- browser smoke test for feed page load;
- browser smoke test for login flow;
- browser smoke test for upload modal open;
- browser smoke test for post drawer open;
- browser smoke test for upvote click;
- browser smoke test for origin vote click;
- browser smoke test for comment submit;
- browser smoke test for report modal open;
- browser smoke test for admin denied to normal user;
- browser smoke test for admin allowed to moderator;
- browser test seed/bootstrap helpers;
- docs for running browser smoke tests.
```

## Не входит

```txt
- screenshot baseline generation;
- image snapshot comparison;
- visual diff thresholds;
- Playwright/Cypress migration unless already chosen;
- full browser coverage for every page;
- testing every validation branch;
- testing every moderation/admin action;
- performance timings;
- accessibility audit;
- CI browser matrix;
- mobile screenshot baselines.
```

Phase 39 owns screenshot baselines.  
Phase 41 owns performance basics.  
Phase 42 owns deployment prep/CI deployment docs.
---

# 4. Testing Tool Decision

## 4.1. Preferred runner

Because RateGuru already uses Pest from Phase 0, preferred browser testing runner:

```txt
Pest Browser Testing, if the installed Pest/Laravel stack supports it.
```

Fallback:

```txt
Laravel Dusk, if Pest Browser Testing is not available or not stable in the current project version.
```

Do not install both in Phase 38.

## 4.2. Why not Cypress/Playwright directly?

For this Laravel/Livewire MVP, adding standalone Playwright/Cypress now creates extra moving parts:

```txt
- separate Node test runner;
- separate auth/session helpers;
- separate database reset strategy;
- more CI setup;
- more config before MVP is even stable.
```

Browser smoke tests should be close to Laravel/Pest/PHP test infrastructure first.

## 4.3. Browser tests are smoke tests

These tests should answer:

```txt
Does this critical user flow still basically work in a browser?
```

They should not try to answer:

```txt
Does every pixel match prototype?
Does every edge case work?
Is every animation perfect?
```

That is why assertions stay intentionally broad:

```txt
- route opens;
- modal appears;
- drawer appears;
- button click changes count/state;
- comment text appears;
- admin access is denied/allowed.
```
---

# 5. Critical Decisions

## 5.1. Seed data must be deterministic

Phase 36 created seed data. Phase 38 browser tests must rely on deterministic data.

Rules:

```txt
- use factories inside tests for scenario-specific records;
- use demo seeders only when a broader page needs realistic data;
- avoid depending on random titles;
- use stable test-specific titles/emails/usernames.
```

Bad:

```txt
click first random post and hope it has comments/votes/reports
```

Good:

```txt
create post titled "Browser Smoke Test Post" and assert it appears
```

## 5.2. Tests must be isolated

Each browser test should reset database state.

Use existing Laravel test database strategy:

```txt
RefreshDatabase / DatabaseMigrations / browser test database reset approach
```

Exact approach depends on selected runner.

For Dusk, standard `RefreshDatabase` is usually not used the same way as normal feature tests because browser and test process may be separate. Prefer Dusk-compatible database migration/reset trait or explicit seeding in test setup.

For Pest Browser, use the project’s recommended database reset mechanism.

Rule:

```txt
Do not let one browser test depend on another browser test.
```

## 5.3. Stable selectors are mandatory

Browser tests should not click random CSS classes that are likely to change in UI polish.

Need stable selectors:

```txt
data-testid="feed-page"
data-testid="login-form"
data-testid="open-upload-button"
data-testid="upload-modal"
data-testid="post-card"
data-testid="post-drawer"
data-testid="post-upvote-button"
data-testid="origin-vote-homemade"
data-testid="comment-form"
data-testid="report-button"
data-testid="report-modal"
data-testid="admin-panel"
```

If missing, each task may add minimal `data-testid` attributes.

Do not add ugly visible text only for tests.

## 5.4. Browser tests should not rely on exact copy unless copy is product-critical

Prefer:

```txt
assertVisible('@upload-modal')
assertSee('Upload')
```

but avoid brittle long text assertions.

Stable selectors are better than long copy strings.

## 5.5. Avoid testing third-party browser quirks

Do not make tests depend on:

```txt
clipboard API;
native file picker;
complex drag/drop upload;
external image loading;
browser permission prompts.
```

For upload modal open, only test the modal opens.  
Do not test real image upload here unless browser file upload is stable and already supported.

## 5.6. Admin access tests are smoke, not full authorization suite

Phase 35 already tests Policies.

Phase 38 admin browser tests only check:

```txt
normal user cannot open /admin
moderator can open /admin
```

Do not test all Filament resources here.

## 5.7. Keep browser suite small and fast

This phase creates 10 tests. They should remain smoke-level.

Avoid:

```txt
- 40 assertions per test;
- testing every field validation;
- waiting arbitrary seconds;
- huge seeded dataset;
- screenshot capture on every test.
```

Use explicit waits for Livewire/Alpine UI states, not blind sleeps.
---

# 6. Suggested Browser Test Structure

Preferred if using Pest Browser:

```txt
tests/Browser/FeedBrowserTest.php
tests/Browser/AuthBrowserTest.php
tests/Browser/UploadBrowserTest.php
tests/Browser/PostDrawerBrowserTest.php
tests/Browser/VotingBrowserTest.php
tests/Browser/CommentsBrowserTest.php
tests/Browser/ReportsBrowserTest.php
tests/Browser/AdminAccessBrowserTest.php
```

Fallback if using Dusk:

```txt
tests/Browser/FeedPageLoadTest.php
tests/Browser/LoginFlowTest.php
tests/Browser/UploadModalOpenTest.php
tests/Browser/PostDrawerOpenTest.php
tests/Browser/VotingSmokeTest.php
tests/Browser/CommentSubmitTest.php
tests/Browser/ReportModalOpenTest.php
tests/Browser/AdminAccessTest.php
```

Support files:

```txt
tests/Browser/Pages/FeedPage.php
tests/Browser/Pages/LoginPage.php
tests/Browser/Pages/PostShowPage.php
tests/Browser/Pages/AdminPage.php
tests/Browser/Concerns/CreatesBrowserSmokeData.php
```

Only create page objects if they reduce duplication. Do not over-abstract prematurely.
---

# 7. Required Data-Test IDs

Phase 38 may add these if missing:

```txt
feed:
data-testid="feed-page"
data-testid="post-card"
data-testid="post-card-title"

auth:
data-testid="login-form"
data-testid="login-email"
data-testid="login-password"
data-testid="login-submit"

upload:
data-testid="open-upload-button"
data-testid="upload-modal"

drawer:
data-testid="post-drawer"
data-testid="post-drawer-title"

voting:
data-testid="post-upvote-button"
data-testid="post-upvote-count"
data-testid="origin-vote-homemade"
data-testid="origin-vote-restaurant"

comments:
data-testid="comment-form"
data-testid="comment-body"
data-testid="comment-submit"
data-testid="comment-item"

reports:
data-testid="report-button"
data-testid="report-modal"

admin:
data-testid="admin-dashboard"
```

If the project already has different stable test IDs, use existing ones. Do not duplicate.
---

# 8. Browser Test Commands

Add or document one command:

```bash
php artisan test --testsuite=Browser
```

or if Dusk:

```bash
php artisan dusk
```

If Pest Browser uses a separate command in the installed version, document the actual command.

Composer script recommendation:

```json
{
  "scripts": {
    "test:browser": "php artisan test --testsuite=Browser"
  }
}
```

or for Dusk:

```json
{
  "scripts": {
    "test:browser": "php artisan dusk"
  }
}
```

Do not make normal `composer test` run browser tests by default yet unless the suite is stable in local and CI.

Recommended:

```txt
composer test       → unit/feature/livewire
composer test:browser → browser smoke suite
```
---

# 9. GitFlow для Phase 38

## Base branch

Все задачи Phase 38 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-616-add-browser-test-for-feed-page-load
feature/RG-620-add-browser-test-for-upvote-click
feature/RG-625-add-browser-test-for-admin-access-allowed-to-moderator
```

## Commit format

```txt
RG-616: Add browser test for feed page load
RG-620: Add browser test for upvote click
RG-625: Add browser test for admin access allowed to moderator
```

## Release branch

После выполнения `RG-616`–`RG-625`:

```txt
release/v0.2.19-phase38-browser-smoke-tests
```

## Tag

После merge release branch в `main`:

```txt
v0.2.19-phase38-browser-smoke-tests
```

Почему `v0.2.19`: Phase 37 использует `v0.2.18`, Phase 38 следующий release.
---

# 10. TDD / Browser Test Rules

## Для каждого browser smoke test

Порядок:

```txt
1. Создать минимальные данные через factory/seeder.
2. Открыть страницу в браузере.
3. Найти stable selector.
4. Выполнить одно ключевое действие.
5. Проверить одно-два следствия.
6. Не проверять визуальные детали.
```

## Forbidden in Phase 38 tests

```txt
- screenshot assertions;
- pixel comparison;
- arbitrary sleep(5);
- reliance on external images;
- reliance on external services;
- full admin resource CRUD;
- testing every validation path;
- testing email/notification delivery;
- testing queue jobs.
```

## Required verification per task

```txt
- target browser test passes;
- related feature/livewire test still passes if touched;
- npm run build passes if UI selectors/views changed;
- docs updated if command/setup changes.
```
---

# 11. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Browser Tests / Smoke / UI Flow
Type: Browser Test / Setup / Selector / Docs
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Какой browser smoke flow должен быть покрыт.

TDD step:
Какой browser test пишем первым.

Implementation:
Что именно меняем.

Test data:
Какие factory/seeder записи нужны.

Selectors:
Какие data-testid нужны.

Acceptance criteria:
- Проверяемый результат 1
- Проверяемый результат 2
- Проверяемый результат 3

Definition of Done:
- Browser test написан
- Browser test падает до фикса/селектора, если применимо
- Реализация минимальная
- Browser test проходит
- Нет screenshot baselines
- Нет visual regression logic
- Коммит содержит ID задачи

Files likely touched:
- path/to/test
- path/to/view
```
---

# 12. Phase 38 Atomic Tasks
---

## RG-616 — Add Browser Test For Feed Page Load

**Area:** Browser Tests / Feed  
**Type:** Browser Test + Setup  
**Priority:** P0  
**Branch:** `feature/RG-616-add-browser-test-for-feed-page-load`  
**Base branch:** develop
**Depends on:** RG-615

### Goal

Добавить первый browser smoke test: feed page opens and shows published post.

Также в этой задаче нужно зафиксировать browser test runner, если он ещё не установлен.

### TDD step

Browser test:

```php
it('loads the feed page and shows published posts', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Browser Smoke Feed Post',
    ]);

    visit(route('feed'))
        ->assertSee('Browser Smoke Feed Post')
        ->assertPresent('[data-testid="feed-page"]')
        ->assertPresent('[data-testid="post-card"]');
});
```

If using Dusk style:

```php
public function test_feed_page_loads_and_shows_published_posts(): void
{
    $post = Post::factory()->published()->create([
        'title' => 'Browser Smoke Feed Post',
    ]);

    $this->browse(function (Browser $browser) {
        $browser->visitRoute('feed')
            ->assertSee('Browser Smoke Feed Post')
            ->assertPresent('[data-testid="feed-page"]')
            ->assertPresent('[data-testid="post-card"]');
    });
}
```

Adapt syntax to selected runner.

### Implementation

1. Choose one runner:

```txt
Preferred: Pest Browser Testing if available.
Fallback: Laravel Dusk.
```

2. If runner missing, install/setup in this task.

Possible Pest Browser setup:

```bash
composer require pestphp/pest-plugin-browser --dev
```

Exact package/command must match installed Pest version.

Possible Dusk setup:

```bash
composer require laravel/dusk --dev
php artisan dusk:install
```

3. Add browser test command:

```json
"test:browser": "..."
```

4. Ensure `.env.dusk.local` or browser test env config exists if Dusk is used.

5. Add stable selectors if missing:

```txt
data-testid="feed-page"
data-testid="post-card"
```

6. Add docs note:

```txt
docs/testing/browser-smoke-tests.md
```

with selected command.

### Test data

```txt
- one published post with stable title
- author user if factory needs it
- tags if PostCard expects tags
```

### Selectors

```txt
data-testid="feed-page"
data-testid="post-card"
```

### Acceptance criteria

- Browser test infrastructure exists.
- `test:browser` command exists or is documented.
- Feed browser test opens feed page.
- Published post title is visible.
- Feed page selector exists.
- Post card selector exists.
- No screenshot baseline added.
- Test passes locally.

### Definition of Done

- Browser runner selected.
- Feed browser smoke test written.
- Required selectors added.
- Browser test command documented.
- Test passes.
- Коммит: `RG-616: Add browser test for feed page load`

### Files likely touched

```txt
composer.json
composer.lock
tests/Browser/FeedBrowserTest.php
tests/Browser/FeedPageLoadTest.php
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/components/feed/post-card.blade.php
docs/testing/browser-smoke-tests.md
.env.dusk.local.example
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-617 — Add Browser Test For Login Flow

**Area:** Browser Tests / Auth  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-617-add-browser-test-for-login-flow`  
**Base branch:** develop
**Depends on:** RG-616

### Goal

Добавить browser smoke test для login flow.

### TDD step

Browser test:

```php
it('allows user to log in from browser', function () {
    $user = User::factory()->create([
        'email' => 'browser-login@rateguru.test',
        'password' => Hash::make('password'),
    ]);

    visit(route('login'))
        ->assertPresent('[data-testid="login-form"]')
        ->type('[data-testid="login-email"]', 'browser-login@rateguru.test')
        ->type('[data-testid="login-password"]', 'password')
        ->click('[data-testid="login-submit"]')
        ->assertAuthenticated()
        ->assertPathIs(route('feed', absolute: false));
});
```

Dusk-style equivalent if using Dusk:

```php
$browser->visitRoute('login')
    ->type('@login-email', 'browser-login@rateguru.test')
    ->type('@login-password', 'password')
    ->press('@login-submit')
    ->assertAuthenticated()
    ->assertRouteIs('feed');
```

Adapt selectors.

### Implementation

Add stable selectors to login view:

```txt
data-testid="login-form"
data-testid="login-email"
data-testid="login-password"
data-testid="login-submit"
```

If Breeze/Jetstream auth views exist, add selectors there.  
Do not change auth logic.

If successful login redirects to dashboard instead of feed, decide target:

```txt
Preferred RateGuru behavior: redirect authenticated user to feed.
If current app redirects to dashboard, test actual intended behavior and document it.
```

### Test data

```txt
- user with known email/password
```

### Selectors

```txt
login-form
login-email
login-password
login-submit
```

### Acceptance criteria

- Browser opens login page.
- User can enter email/password.
- Submit logs user in.
- Authenticated state is confirmed.
- Redirect target is stable.
- No auth backend refactor.
- Test passes.

### Definition of Done

- Browser test written.
- Login selectors added if missing.
- Test passes.
- Коммит: `RG-617: Add browser test for login flow`

### Files likely touched

```txt
tests/Browser/AuthBrowserTest.php
resources/views/auth/login.blade.php
resources/views/livewire/auth/login.blade.php
docs/testing/browser-smoke-tests.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-618 — Add Browser Test For Upload Modal Open

**Area:** Browser Tests / Upload  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-618-add-browser-test-for-upload-modal-open`  
**Base branch:** develop
**Depends on:** RG-617

### Goal

Добавить browser smoke test: authenticated user can open upload modal.

### TDD step

Browser test:

```php
it('opens upload modal from feed', function () {
    $user = User::factory()->create();

    actingAs($user);

    visit(route('feed'))
        ->click('[data-testid="open-upload-button"]')
        ->assertVisible('[data-testid="upload-modal"]')
        ->assertSee('Upload');
});
```

Dusk-style equivalent:

```php
$browser->loginAs($user)
    ->visitRoute('feed')
    ->click('@open-upload-button')
    ->waitFor('@upload-modal')
    ->assertVisible('@upload-modal');
```

### Implementation

Add stable selectors:

```txt
data-testid="open-upload-button"
data-testid="upload-modal"
```

If upload is a Livewire modal:

```txt
- wait for Livewire/Alpine modal state;
- do not test actual file upload here.
```

If guest does not see upload button, test uses authenticated user.

### Test data

```txt
- authenticated active user
```

### Selectors

```txt
open-upload-button
upload-modal
```

### Acceptance criteria

- Authenticated user sees upload button.
- Clicking button opens upload modal.
- Modal is visible in browser.
- No real image upload is tested in this task.
- No storage/S3 dependency.
- Test passes.

### Definition of Done

- Browser test written.
- Upload selectors added if missing.
- Test passes.
- Коммит: `RG-618: Add browser test for upload modal open`

### Files likely touched

```txt
tests/Browser/UploadBrowserTest.php
resources/views/layouts/navigation.blade.php
resources/views/components/layout/header.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-619 — Add Browser Test For Post Drawer Open

**Area:** Browser Tests / Post Drawer  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-619-add-browser-test-for-post-drawer-open`  
**Base branch:** develop
**Depends on:** RG-618

### Goal

Добавить browser smoke test: clicking a post card opens post drawer.

### TDD step

Browser test:

```php
it('opens post drawer from feed post card', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Browser Drawer Test Post',
    ]);

    visit(route('feed'))
        ->click('[data-testid="post-card"]')
        ->assertVisible('[data-testid="post-drawer"]')
        ->assertSee('Browser Drawer Test Post');
});
```

More stable if multiple cards:

```txt
create one post only
or use data-testid="post-card-{id}"
```

Dusk-style:

```php
$browser->visitRoute('feed')
    ->click('@post-card-' . $post->id)
    ->waitFor('@post-drawer')
    ->assertSee('Browser Drawer Test Post');
```

### Implementation

Add selectors:

```txt
data-testid="post-card"
data-testid="post-card-{post_id}" optional
data-testid="post-drawer"
data-testid="post-drawer-title"
```

If card has internal clickable button/link, click the actual drawer opener:

```txt
data-testid="open-post-drawer-{id}"
```

Do not rely on clicking the entire card if actual UX uses a button/link.

### Test data

```txt
- one published post with stable title
- author user
- tags if needed
```

### Selectors

```txt
post-card
post-drawer
post-drawer-title
```

### Acceptance criteria

- Feed page shows test post.
- Browser click opens drawer.
- Drawer becomes visible.
- Drawer shows selected post title.
- Drawer close behavior not required here.
- Test passes.

### Definition of Done

- Browser test written.
- Drawer/card selectors added if missing.
- Test passes.
- Коммит: `RG-619: Add browser test for post drawer open`

### Files likely touched

```txt
tests/Browser/PostDrawerBrowserTest.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/post/post-drawer.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-620 — Add Browser Test For Upvote Click

**Area:** Browser Tests / Voting  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-620-add-browser-test-for-upvote-click`  
**Base branch:** develop
**Depends on:** RG-619

### Goal

Добавить browser smoke test: authenticated user can click upvote and see updated state/count.

### TDD step

Browser test:

```php
it('allows authenticated user to upvote a post', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Browser Upvote Test Post',
        'upvotes_count' => 0,
    ]);

    actingAs($user);

    visit(route('feed'))
        ->click('[data-testid="post-upvote-button"]')
        ->waitForText('1')
        ->assertSeeIn('[data-testid="post-upvote-count"]', '1');
});
```

Dusk-style:

```php
$browser->loginAs($user)
    ->visitRoute('feed')
    ->click('@post-upvote-button-' . $post->id)
    ->waitForTextIn('@post-upvote-count-' . $post->id, '1');
```

### Implementation

Add selectors:

```txt
data-testid="post-upvote-button"
data-testid="post-upvote-button-{post_id}" preferred
data-testid="post-upvote-count"
data-testid="post-upvote-count-{post_id}" preferred
```

If count update is async Livewire:

```txt
wait for count text/state
```

Do not test downvote here.

### Test data

```txt
- authenticated active user
- published post with zero upvotes
```

### Selectors

```txt
post-upvote-button-{id}
post-upvote-count-{id}
```

### Acceptance criteria

- Authenticated user can click upvote.
- Upvote count updates in browser.
- Button selected/active state appears if testable by selector/class/aria.
- Database vote exists after click if checked.
- Test does not rely on exact animation.
- Test passes.

### Definition of Done

- Browser test written.
- Voting selectors added if missing.
- Test passes.
- Коммит: `RG-620: Add browser test for upvote click`

### Files likely touched

```txt
tests/Browser/VotingBrowserTest.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/components/feed/post-card.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-621 — Add Browser Test For Origin Vote Click

**Area:** Browser Tests / Origin Voting  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-621-add-browser-test-for-origin-vote-click`  
**Base branch:** develop
**Depends on:** RG-620

### Goal

Добавить browser smoke test: authenticated user can vote Homemade/Restaurant origin.

### TDD step

Browser test:

```php
it('allows authenticated user to vote on post origin', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Browser Origin Vote Test Post',
    ]);

    actingAs($user);

    visit(route('feed'))
        ->click('[data-testid="origin-vote-homemade"]')
        ->assertPresent('[data-testid="origin-vote-homemade"][aria-pressed="true"]');
});
```

If `aria-pressed` is not used yet, add it.  
Alternative assertion:

```txt
assert database has origin vote
```

Dusk-style can check visible selected text/class if selector support is limited.

### Implementation

Add selectors and accessibility state:

```txt
data-testid="origin-vote-homemade"
data-testid="origin-vote-restaurant"
aria-pressed="true|false"
```

Prefer per-post selectors:

```txt
origin-vote-homemade-{post_id}
```

If origin voting only appears in drawer, test should:

```txt
- open drawer;
- click origin vote inside drawer.
```

Choose actual UX location.

### Test data

```txt
- authenticated active user
- published post
```

### Selectors

```txt
origin-vote-homemade-{id}
origin-vote-restaurant-{id}
```

### Acceptance criteria

- Browser can click origin vote.
- Selected state changes or database vote exists.
- `aria-pressed` reflects selected state if added.
- No cuisine voting tested here.
- Test passes.

### Definition of Done

- Browser test written.
- Origin vote selectors/states added if missing.
- Test passes.
- Коммит: `RG-621: Add browser test for origin vote click`

### Files likely touched

```txt
tests/Browser/VotingBrowserTest.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/post/post-drawer.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-622 — Add Browser Test For Comment Submit

**Area:** Browser Tests / Comments  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-622-add-browser-test-for-comment-submit`  
**Base branch:** develop
**Depends on:** RG-621

### Goal

Добавить browser smoke test: authenticated user can submit comment and see it.

### TDD step

Browser test:

```php
it('allows authenticated user to submit a comment', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Browser Comment Test Post',
    ]);

    actingAs($user);

    visit(route('posts.show', $post))
        ->type('[data-testid="comment-body"]', 'Browser smoke test comment')
        ->click('[data-testid="comment-submit"]')
        ->waitForText('Browser smoke test comment')
        ->assertSee('Browser smoke test comment');
});
```

If comments are only in drawer for MVP:

```txt
visit feed → open drawer → submit comment inside drawer.
```

Prefer route/show if stable. Use actual current UX.

Dusk-style:

```php
$browser->loginAs($user)
    ->visitRoute('posts.show', $post)
    ->type('@comment-body', 'Browser smoke test comment')
    ->press('@comment-submit')
    ->waitForText('Browser smoke test comment');
```

### Implementation

Add selectors:

```txt
data-testid="comment-form"
data-testid="comment-body"
data-testid="comment-submit"
data-testid="comment-item"
```

Ensure submit button has loading/disabled state from Phase 37 but do not assert visual details.

### Test data

```txt
- authenticated active user
- published post
```

### Selectors

```txt
comment-form
comment-body
comment-submit
comment-item
```

### Acceptance criteria

- Browser can type comment.
- Browser can submit comment.
- Submitted comment appears without manual refresh, or after expected Livewire update.
- Database comment exists if checked.
- comments_count update is not required but acceptable to assert if stable.
- Test passes.

### Definition of Done

- Browser test written.
- Comment selectors added if missing.
- Test passes.
- Коммит: `RG-622: Add browser test for comment submit`

### Files likely touched

```txt
tests/Browser/CommentsBrowserTest.php
resources/views/livewire/comments/comment-form.blade.php
resources/views/livewire/comments/comment-item.blade.php
resources/views/livewire/post/post-show.blade.php
resources/views/livewire/post/post-drawer.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-623 — Add Browser Test For Report Modal Open

**Area:** Browser Tests / Reports  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-623-add-browser-test-for-report-modal-open`  
**Base branch:** develop
**Depends on:** RG-622

### Goal

Добавить browser smoke test: authenticated user can open report modal for post/comment.

### TDD step

Browser test:

```php
it('opens report modal for a post', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'title' => 'Browser Report Modal Test Post',
    ]);

    actingAs($user);

    visit(route('feed'))
        ->click('[data-testid="report-button"]')
        ->assertVisible('[data-testid="report-modal"]')
        ->assertSee('Report');
});
```

If report button is inside drawer:

```txt
visit feed → open drawer → click report button → assert modal
```

Dusk-style:

```php
$browser->loginAs($user)
    ->visitRoute('feed')
    ->click('@post-card-' . $post->id)
    ->waitFor('@post-drawer')
    ->click('@report-button-' . $post->id)
    ->waitFor('@report-modal')
    ->assertVisible('@report-modal');
```

Do not submit report in this task. Just opening modal.

### Implementation

Add selectors:

```txt
data-testid="report-button"
data-testid="report-button-{post_id}" preferred
data-testid="report-modal"
```

If report modal is shared for comments/posts, test post report only for smoke.

### Test data

```txt
- authenticated active user
- published post
```

### Selectors

```txt
report-button-{id}
report-modal
```

### Acceptance criteria

- Authenticated user can find report button.
- Clicking report opens modal.
- Modal is visible.
- Modal shows report UI.
- Report submission is not tested here.
- Test passes.

### Definition of Done

- Browser test written.
- Report selectors added if missing.
- Test passes.
- Коммит: `RG-623: Add browser test for report modal open`

### Files likely touched

```txt
tests/Browser/ReportsBrowserTest.php
resources/views/livewire/reports/report-modal.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/views/livewire/comments/comment-item.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-624 — Add Browser Test For Admin Access Denied To User

**Area:** Browser Tests / Admin Access  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-624-add-browser-test-for-admin-access-denied-to-user`  
**Base branch:** develop
**Depends on:** RG-623

### Goal

Добавить browser smoke test: normal user cannot access `/admin`.

### TDD step

Browser test:

```php
it('denies admin access to normal user', function () {
    $user = User::factory()->create();

    actingAs($user);

    visit('/admin')
        ->assertForbidden();
});
```

Depending browser runner, forbidden assertion may differ. Alternative:

```txt
assert path redirected away from /admin
assert see 403
assert see login if unauthenticated
```

Dusk-style:

```php
$browser->loginAs($user)
    ->visit('/admin')
    ->assertSee('403');
```

If Filament redirects unauthorized users instead of 403, assert intended behavior:

```txt
- not on admin dashboard
- see forbidden/unauthorized/login page
```

### Implementation

No UI changes should be needed if Phase 23/35 are correct.

If selector needed for admin dashboard, do not add for denied test.  
Denied test should assert absence of admin dashboard.

Possible assertions:

```txt
assertDontSee('Admin')
assertPathIsNot('/admin')
assertSee('Forbidden')
```

Pick stable behavior.

### Test data

```txt
- authenticated normal user
```

### Selectors

None required.

### Acceptance criteria

- Normal user cannot access `/admin`.
- Browser does not show admin dashboard.
- Behavior matches Filament panel access rule.
- Test does not depend on admin resources.
- Test passes.

### Definition of Done

- Browser test written.
- No unnecessary app changes.
- Test passes.
- Коммит: `RG-624: Add browser test for admin access denied to user`

### Files likely touched

```txt
tests/Browser/AdminAccessBrowserTest.php
app/Models/User.php
app/Providers/Filament/AdminPanelProvider.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-625 — Add Browser Test For Admin Access Allowed To Moderator

**Area:** Browser Tests / Admin Access  
**Type:** Browser Test  
**Priority:** P0  
**Branch:** `feature/RG-625-add-browser-test-for-admin-access-allowed-to-moderator`  
**Base branch:** develop
**Depends on:** RG-624

### Goal

Добавить browser smoke test: moderator can access `/admin`.

### TDD step

Browser test:

```php
it('allows moderator to access admin panel', function () {
    $moderator = User::factory()->moderator()->create();

    actingAs($moderator);

    visit('/admin')
        ->assertOk()
        ->assertPresent('[data-testid="admin-dashboard"]');
});
```

If Filament dashboard markup cannot easily receive `data-testid`, assert visible stable text:

```txt
assertSee('Dashboard')
assertSee('RateGuru')
```

Dusk-style:

```php
$browser->loginAs($moderator)
    ->visit('/admin')
    ->assertSee('Dashboard')
    ->assertSee('RateGuru');
```

### Implementation

If possible, add stable marker to admin dashboard placeholder from Phase 23:

```txt
data-testid="admin-dashboard"
```

Do not test PostResource/UserResource here.  
That belongs to Filament resource tests already created earlier.

Add final browser testing docs update:

```txt
docs/testing/browser-smoke-tests.md
```

Include:

```txt
- selected runner;
- command;
- local prerequisites;
- APP_URL requirement if Dusk;
- how to reset DB;
- what Phase 38 covers;
- what it deliberately does not cover.
```

### Test data

```txt
- authenticated moderator
```

### Selectors

```txt
admin-dashboard
```

### Acceptance criteria

- Moderator can open `/admin`.
- Browser sees admin dashboard/RateGuru panel.
- Normal user denied test still passes.
- Docs for browser smoke tests exist/updated.
- `test:browser` command passes.
- `composer test` passes if not including browser tests by default.
- `npm run build` passes.

### Definition of Done

- Browser test written.
- Admin dashboard marker added if practical.
- Browser testing docs finalized.
- Browser smoke suite passes.
- No visual regression files added.
- Коммит: `RG-625: Add browser test for admin access allowed to moderator`

### Files likely touched

```txt
tests/Browser/AdminAccessBrowserTest.php
app/Filament/Pages/Dashboard.php
resources/views/filament/pages/dashboard.blade.php
app/Providers/Filament/AdminPanelProvider.php
docs/testing/browser-smoke-tests.md
composer.json
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 13. Phase 38 Completion Criteria

Phase 38 завершена, когда:

```txt
- RG-616–RG-625 выполнены;
- browser test runner выбран и настроен;
- browser smoke command exists or is documented;
- feed page browser test passes;
- login flow browser test passes;
- upload modal open browser test passes;
- post drawer open browser test passes;
- upvote click browser test passes;
- origin vote click browser test passes;
- comment submit browser test passes;
- report modal open browser test passes;
- normal user admin denied browser test passes;
- moderator admin allowed browser test passes;
- stable data-testid selectors exist where needed;
- tests use deterministic factory/seed data;
- tests do not rely on screenshots;
- no visual regression baseline files created;
- docs/testing/browser-smoke-tests.md exists;
- composer test passes;
- npm run build passes;
- browser smoke suite passes locally.
```
---

# 14. Что нельзя делать в Phase 38

Без отдельной задачи нельзя:

```txt
- добавлять screenshot baseline files;
- добавлять visual diff comparison;
- добавлять pixel threshold tooling;
- добавлять Phase 39 PR visual checklist;
- делать full Cypress/Playwright migration;
- тестировать все Filament resources;
- тестировать все validation errors;
- тестировать image upload end-to-end with real storage if unstable;
- тестировать email/notifications delivery;
- добавлять performance assertions;
- добавлять accessibility audit tooling;
- добавлять new backend features;
- добавлять migrations;
- добавлять API endpoints.
```
---

# 15. Recommended Execution Order

```txt
RG-616 Add browser test for feed page load
RG-617 Add browser test for login flow
RG-618 Add browser test for upload modal open
RG-619 Add browser test for post drawer open
RG-620 Add browser test for upvote click
RG-621 Add browser test for origin vote click
RG-622 Add browser test for comment submit
RG-623 Add browser test for report modal open
RG-624 Add browser test for admin access denied to user
RG-625 Add browser test for admin access allowed to moderator
```
---

# 16. Release

После завершения Phase 38:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
composer test:browser

git checkout -b release/v0.2.19-phase38-browser-smoke-tests
git push -u origin release/v0.2.19-phase38-browser-smoke-tests
```

Если browser command не через Composer, использовать фактическую команду:

```bash
php artisan test --testsuite=Browser
```

или:

```bash
php artisan dusk
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.19-phase38-browser-smoke-tests -m "RateGuru Phase 38 Browser Smoke Tests"
git push origin v0.2.19-phase38-browser-smoke-tests
```
