# RateGuru — Phase 39 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 39 — Visual Regression Foundation**  
Диапазон задач: **RG-626 → RG-636**  
Основа нумерации: исходный atomic backlog, где Phase 39 начинается с задачи 626 и заканчивается задачей 636.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 39 соответствует исходному блоку:

```txt
Phase 39 — Visual Regression Foundation
```

Правильный диапазон Phase 39:

```txt
RG-626 — Add screenshot command for feed desktop
RG-627 — Add screenshot command for feed mobile
RG-628 — Add screenshot command for upload modal
RG-629 — Add screenshot command for post drawer
RG-630 — Add screenshot command for post show
RG-631 — Save first approved feed desktop baseline
RG-632 — Save first approved feed mobile baseline
RG-633 — Save first approved upload modal baseline
RG-634 — Save first approved post drawer baseline
RG-635 — Save first approved post show baseline
RG-636 — Add manual visual review checklist to PR template
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно:

```txt
Phase 38 заканчивается на RG-625.
Phase 39 занимает RG-626 → RG-636.
Phase 40 начинается с RG-637 и делает API Readiness, Not Full API.
```

Значит Phase 39 не должна добавлять API route placeholders, JSON resources, API auth strategy или versioning notes. Это визуальная инфраструктура.
---

# 2. Цель Phase 39

Phase 39 создаёт первый фундамент visual regression process.

После Phase 39 в проекте должно быть:

```txt
- команда/скрипт для screenshot feed desktop;
- команда/скрипт для screenshot feed mobile;
- команда/скрипт для screenshot upload modal;
- команда/скрипт для screenshot post drawer;
- команда/скрипт для screenshot post show;
- первые approved baseline screenshots;
- понятная структура хранения screenshots;
- документация как обновлять baselines;
- manual visual review checklist в PR template.
```

Это не полноценная CI visual diff система. Это первый управляемый baseline слой, чтобы будущие UI changes можно было сравнивать не “на глаз по памяти”, а против сохранённых эталонов.
---

# 3. Scope Phase 39

## Входит

```txt
- screenshot command/script foundation;
- deterministic seed/bootstrap for screenshot pages;
- screenshot capture for feed desktop;
- screenshot capture for feed mobile;
- screenshot capture for upload modal state;
- screenshot capture for post drawer state;
- screenshot capture for post show page;
- first approved baseline files;
- docs for local screenshot workflow;
- PR template checklist for visual review.
```

## Не входит

```txt
- automatic pixel diff in CI;
- screenshot threshold tuning;
- Percy/Chromatic/Argos integration;
- full Playwright/Cypress migration;
- visual tests for every component;
- screenshots for all admin pages;
- screenshots for all responsive breakpoints;
- image comparison reports;
- accessibility audit;
- performance testing;
- API readiness.
```

Phase 39 = foundation + first approved baselines.  
Full visual regression automation can be a later phase.
---

# 4. Critical Decisions

## 4.1. Use the same browser runner chosen in Phase 38 where possible

Phase 38 already decided browser smoke runner:

```txt
Preferred: Pest Browser Testing if available.
Fallback: Laravel Dusk.
```

Phase 39 should reuse that runner/tooling.

Bad:

```txt
Phase 38 uses Dusk, Phase 39 adds separate Playwright stack for screenshots.
```

Good:

```txt
Use existing browser test runner to open page states and save screenshots.
```

Reason:

```txt
- fewer dependencies;
- same auth/session/bootstrap approach;
- same database strategy;
- less CI/local setup chaos.
```

If the chosen runner cannot reliably save screenshots, add the smallest possible wrapper around the browser driver, not a full new E2E stack.

## 4.2. Baselines are approved artifacts, not temporary debug screenshots

Temporary screenshots:

```txt
storage/app/visual-regression/current/*
```

Approved baselines:

```txt
tests/Visual/baselines/*
```

or:

```txt
resources/visual-baselines/*
```

Recommended:

```txt
tests/Visual/baselines/
tests/Visual/current/
tests/Visual/diff/     # reserved for later, optional placeholder only
```

Approved baseline files are committed to git.

Current screenshots may or may not be committed. Recommended:

```txt
current/ is ignored
baselines/ is committed
diff/ is ignored
```

## 4.3. Do not add automatic failure-on-diff yet

Backlog says:

```txt
Save first approved baselines
Add manual visual review checklist
```

It does not say:

```txt
Add pixel comparison test
Fail CI on visual diff
```

Therefore Phase 39 should not introduce hard visual-diff CI failures yet.

Acceptable:

```txt
command saves screenshots
developer manually compares with baseline
PR checklist asks reviewer to compare
```

Not acceptable:

```txt
new CI job fails because 0.1% pixel drift
```

That will create noise before the design stabilizes.

## 4.4. Screenshots need deterministic data

Visual baselines are worthless if content changes every run.

Use Phase 36 seed data or screenshot-specific seed setup.

Required:

```txt
- stable post titles;
- stable user names;
- stable tags;
- stable counts;
- stable images/placeholders;
- stable dates where visible;
- stable viewport sizes.
```

If dates are shown as `3 minutes ago`, freeze time or hide relative drift in screenshot mode.

Recommended:

```txt
php artisan migrate:fresh --seed
php artisan visual:screenshots
```

with app in deterministic local/test mode.

## 4.5. Screenshots should avoid external assets

No remote image URLs.  
No web fonts loaded from external network unless already bundled/stable.

Why:

```txt
- network flakes;
- screenshots differ;
- CI/local mismatch;
- baseline becomes noisy.
```

Use local demo images or placeholders.

## 4.6. Viewport sizes must be fixed

Baseline names must encode viewport.

Recommended:

```txt
desktop: 1440x1000
mobile: 390x844
```

Why:

```txt
- 1440 desktop catches two-column layout;
- 390 mobile catches common iPhone width;
- height enough to capture meaningful first screen.
```

Do not let screenshot dimensions depend on developer monitor.

## 4.7. Screenshot states must be explicit

For modal/drawer states, command must not rely on manual clicking by developer.

Correct:

```txt
script opens feed, clicks upload button, waits for upload modal, screenshots
script opens feed, clicks a specific post card, waits for drawer, screenshots
```

or dedicated screenshot routes only in local/testing:

```txt
/dev/visual/upload-modal
/dev/visual/post-drawer
```

Preferred for MVP:

```txt
Use browser interaction with real UI state.
```

This verifies UI wiring and produces realistic screenshot.

## 4.8. Do not create public screenshot-only routes unless guarded

If screenshot helper routes are needed, they must be available only in:

```txt
local
testing
```

Never production.

But the preferred path is not creating routes at all.

## 4.9. Baseline review must be explicit

When saving first baselines, each task should include:

```txt
- run screenshot command;
- open screenshot;
- compare against prototype/design contract;
- approve intentionally;
- commit baseline file.
```

If screenshot does not match prototype, do not save bad baseline as approved. Fix UI or record known deviation.

## 4.10. PR checklist is not optional

Without PR checklist, screenshots will be forgotten.

PR template should force reviewers to answer:

```txt
- Did you run browser smoke tests?
- Did you update screenshots if UI changed?
- Did you compare with approved baselines?
- Did you intentionally approve any visual changes?
```
---

# 5. Recommended File Structure

```txt
tests/Visual/
  baselines/
    feed-desktop.png
    feed-mobile.png
    upload-modal.png
    post-drawer.png
    post-show.png

  current/
    .gitkeep

  diff/
    .gitkeep

tests/Browser/
  VisualScreenshotCommandTest.php       # optional smoke around command
  Support/
    VisualScreenshotData.php            # optional helper

app/Console/Commands/
  CaptureVisualScreenshotsCommand.php   # if using Artisan command

docs/testing/
  visual-regression.md

.github/
  pull_request_template.md
```

If current/diff are ignored:

```txt
tests/Visual/current/*
!tests/Visual/current/.gitkeep
tests/Visual/diff/*
!tests/Visual/diff/.gitkeep
```

Baseline files should not be ignored.
---

# 6. Screenshot Command Design

## 6.1. Preferred command

```bash
php artisan visual:screenshot feed-desktop
php artisan visual:screenshot feed-mobile
php artisan visual:screenshot upload-modal
php artisan visual:screenshot post-drawer
php artisan visual:screenshot post-show
```

Or one command for all:

```bash
php artisan visual:screenshots
```

Recommended: support both.

Signature:

```php
protected $signature = 'visual:screenshot
    {target? : feed-desktop|feed-mobile|upload-modal|post-drawer|post-show|all}
    {--baseline : Save into tests/Visual/baselines instead of current}
    {--fresh : Run migrate:fresh --seed before screenshots}
';
```

If browser runner does not make Artisan screenshot command practical, use composer scripts:

```json
{
  "scripts": {
    "visual:screenshots": "php artisan dusk --filter=VisualScreenshot"
  }
}
```

But backlog says “Add screenshot command”, so an Artisan or Composer command should exist.

## 6.2. Output paths

Current screenshots:

```txt
tests/Visual/current/feed-desktop.png
tests/Visual/current/feed-mobile.png
tests/Visual/current/upload-modal.png
tests/Visual/current/post-drawer.png
tests/Visual/current/post-show.png
```

Baseline screenshots:

```txt
tests/Visual/baselines/feed-desktop.png
tests/Visual/baselines/feed-mobile.png
tests/Visual/baselines/upload-modal.png
tests/Visual/baselines/post-drawer.png
tests/Visual/baselines/post-show.png
```

## 6.3. Naming rules

Use stable lowercase kebab-case:

```txt
feed-desktop.png
feed-mobile.png
upload-modal.png
post-drawer.png
post-show.png
```

Do not include timestamps in baseline names.

Timestamped debug screenshots can go into temporary local storage, but not baselines.
---

# 7. GitFlow для Phase 39

## Base branch

Все задачи Phase 39 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-626-add-screenshot-command-for-feed-desktop
feature/RG-631-save-first-approved-feed-desktop-baseline
feature/RG-636-add-manual-visual-review-checklist-to-pr-template
```

## Commit format

```txt
RG-626: Add screenshot command for feed desktop
RG-631: Save first approved feed desktop baseline
RG-636: Add manual visual review checklist to PR template
```

## Release branch

После выполнения `RG-626`–`RG-636`:

```txt
release/v0.2.20-phase39-visual-regression-foundation
```

## Tag

После merge release branch в `main`:

```txt
v0.2.20-phase39-visual-regression-foundation
```

Почему `v0.2.20`: Phase 38 использует `v0.2.19`, Phase 39 следующий release.
---

# 8. TDD / Verification Rules for Phase 39

## Для screenshot command задач

Тестировать:

```txt
- команда существует;
- команда принимает target;
- команда создаёт PNG файл в expected path;
- команда возвращает success exit code;
- command fails with clear error for unknown target.
```

Если полноценный browser screenshot в automated test нестабилен, допускается:

```txt
- smoke test command signature/path builder;
- manual command execution as Definition of Done.
```

Но для команды лучше иметь хотя бы one target smoke test.

## Для baseline задач

Unit test не нужен.

Verification:

```txt
- baseline PNG exists;
- file is not empty;
- file name correct;
- screenshot manually approved;
- docs/review updated.
```

Можно добавить simple file existence test:

```php
expect(file_exists(base_path('tests/Visual/baselines/feed-desktop.png')))->toBeTrue();
```

Это не проверяет качество, но защищает от случайного удаления.

## Для PR template

Тестировать:

```txt
- .github/pull_request_template.md exists;
- contains Visual Review section;
- mentions screenshots/baselines;
- mentions browser smoke tests.
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: Visual Regression / Screenshots / Baselines / Docs
Type: Command / Screenshot / Baseline / Docs
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест/проверку пишем первым. Если baseline task:
No direct unit test — approved visual artifact task.

Implementation:
Что именно меняем.

Screenshot target:
Какая страница/состояние/viewport.

Output:
Куда сохраняется PNG.

Acceptance criteria:
- Проверяемый результат 1
- Проверяемый результат 2
- Проверяемый результат 3

Definition of Done:
- Команда/скрипт работает
- PNG создаётся в ожидаемом месте
- Baseline approved вручную, если задача baseline
- Нет pixel-diff CI logic
- Нет API changes
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 39 Atomic Tasks
---

## RG-626 — Add Screenshot Command For Feed Desktop

**Area:** Visual Regression / Screenshot Command  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-626-add-screenshot-command-for-feed-desktop`  
**Base branch:** develop
**Depends on:** RG-625

### Goal

Добавить screenshot command для desktop feed.

### TDD step

Command smoke test:

```php
it('captures feed desktop screenshot', function () {
    Storage::fake('local');

    $this->artisan('visual:screenshot', [
        'target' => 'feed-desktop',
    ])->assertExitCode(0);

    expect(file_exists(base_path('tests/Visual/current/feed-desktop.png')))->toBeTrue();
});
```

Если browser screenshot в test process нестабилен, заменить на более узкий тест:

```php
it('resolves feed desktop screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('feed-desktop');

    expect($target->name)->toBe('feed-desktop');
    expect($target->viewport)->toBe([1440, 1000]);
    expect($target->path)->toEndWith('feed-desktop.png');
});
```

Manual command execution остаётся обязательным.

### Implementation

Create command:

```bash
php artisan make:command CaptureVisualScreenshotCommand
```

Command signature:

```txt
visual:screenshot {target=all} {--baseline} {--fresh}
```

For `feed-desktop`:

```txt
- viewport: 1440x1000;
- URL: route('feed');
- auth: guest or logged-in?
```

Recommended:

```txt
feed desktop baseline should be guest public feed first.
```

Reason:

```txt
- public feed is most important first impression;
- no auth state variance;
- upload button differences can be covered elsewhere.
```

But if prototype assumes logged-in layout, use authenticated demo user. Fix one and document it.

Recommended target config:

```php
'feed-desktop' => [
    'url' => route('feed'),
    'viewport' => [1440, 1000],
    'state' => 'guest',
    'output' => 'feed-desktop.png',
]
```

Implementation must:

```txt
- ensure DB has seed data;
- open feed;
- wait for [data-testid="feed-page"];
- save screenshot to tests/Visual/current/feed-desktop.png;
```

If Dusk:

```php
$browser->resize(1440, 1000)
    ->visitRoute('feed')
    ->waitFor('[data-testid="feed-page"]')
    ->screenshot($pathWithoutExtension);
```

Dusk screenshot path behavior may save under `tests/Browser/screenshots`; if so, move/copy file into `tests/Visual/current`.

If Pest Browser supports screenshot path directly, use that.

Add docs stub:

```txt
docs/testing/visual-regression.md
```

### Screenshot target

```txt
Name: feed-desktop
Viewport: 1440x1000
State: public feed
Output current: tests/Visual/current/feed-desktop.png
Output baseline: tests/Visual/baselines/feed-desktop.png
```

### Acceptance criteria

- `visual:screenshot feed-desktop` command exists.
- Command opens feed page.
- Command sets 1440x1000 viewport.
- Command waits for feed page selector.
- Command saves PNG to current path.
- Unknown targets fail clearly.
- No baseline committed yet in this task unless needed for test.
- No pixel diff logic added.

### Definition of Done

- Command/test written.
- Manual command run succeeds.
- Current screenshot generated.
- Docs stub added.
- Коммит: `RG-626: Add screenshot command for feed desktop`

### Files likely touched

```txt
app/Console/Commands/CaptureVisualScreenshotCommand.php
tests/Visual/current/.gitkeep
tests/Visual/baselines/.gitkeep
tests/Visual/diff/.gitkeep
tests/Feature/Console/VisualScreenshotCommandTest.php
tests/Browser/VisualScreenshotBrowserTest.php
docs/testing/visual-regression.md
.gitignore
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-627 — Add Screenshot Command For Feed Mobile

**Area:** Visual Regression / Screenshot Command  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-627-add-screenshot-command-for-feed-mobile`  
**Base branch:** develop
**Depends on:** RG-626

### Goal

Добавить screenshot target для mobile feed.

### TDD step

Target config test:

```php
it('resolves feed mobile screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('feed-mobile');

    expect($target->name)->toBe('feed-mobile');
    expect($target->viewportWidth)->toBe(390);
    expect($target->viewportHeight)->toBe(844);
});
```

Command smoke test if stable:

```php
$this->artisan('visual:screenshot feed-mobile')
    ->assertExitCode(0);

expect(file_exists(base_path('tests/Visual/current/feed-mobile.png')))->toBeTrue();
```

### Implementation

Add target:

```php
'feed-mobile' => [
    'url' => route('feed'),
    'viewport' => [390, 844],
    'state' => 'guest',
    'output' => 'feed-mobile.png',
]
```

Command behavior:

```txt
- set mobile viewport;
- visit feed;
- wait for feed page;
- wait for first post card;
- save screenshot.
```

Do not emulate device-specific browser if not needed. Fixed viewport is enough for foundation.

### Screenshot target

```txt
Name: feed-mobile
Viewport: 390x844
State: public feed
Output current: tests/Visual/current/feed-mobile.png
Output baseline: tests/Visual/baselines/feed-mobile.png
```

### Acceptance criteria

- `visual:screenshot feed-mobile` works.
- Viewport is 390x844.
- Screenshot captures mobile feed layout.
- Current PNG saved to expected path.
- Desktop target still works.
- No baseline committed yet unless RG-632.
- No pixel diff logic added.

### Definition of Done

- Target/test added.
- Manual command run succeeds.
- Current mobile screenshot generated.
- Docs updated.
- Коммит: `RG-627: Add screenshot command for feed mobile`

### Files likely touched

```txt
app/Console/Commands/CaptureVisualScreenshotCommand.php
app/Support/VisualRegression/VisualScreenshotTargets.php
tests/Feature/Console/VisualScreenshotCommandTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-628 — Add Screenshot Command For Upload Modal

**Area:** Visual Regression / Screenshot Command  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-628-add-screenshot-command-for-upload-modal`  
**Base branch:** develop
**Depends on:** RG-627

### Goal

Добавить screenshot target для upload modal open state.

### TDD step

Target config test:

```php
it('resolves upload modal screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('upload-modal');

    expect($target->name)->toBe('upload-modal');
    expect($target->viewportWidth)->toBe(1440);
});
```

If command smoke stable:

```php
$this->artisan('visual:screenshot upload-modal')
    ->assertExitCode(0);

expect(file_exists(base_path('tests/Visual/current/upload-modal.png')))->toBeTrue();
```

### Implementation

Upload modal requires authenticated user if upload button is hidden from guests.

Command target:

```php
'upload-modal' => [
    'url' => route('feed'),
    'viewport' => [1440, 1000],
    'state' => 'authenticated',
    'actor' => 'demo-user',
    'output' => 'upload-modal.png',
    'steps' => [
        'click' => '[data-testid="open-upload-button"]',
        'wait' => '[data-testid="upload-modal"]',
    ],
]
```

Implementation must:

```txt
- create/login deterministic user;
- visit feed;
- click upload button;
- wait for modal;
- screenshot with backdrop and modal open.
```

Do not test file upload.  
Do not save screenshot before modal fully opens.

If animation causes timing noise:

```txt
wait until modal visible + short animation settle helper, but avoid arbitrary long sleep.
```

A small 100–200ms settle may be acceptable for screenshots.

### Screenshot target

```txt
Name: upload-modal
Viewport: 1440x1000
State: authenticated feed with upload modal open
Output current: tests/Visual/current/upload-modal.png
Output baseline: tests/Visual/baselines/upload-modal.png
```

### Acceptance criteria

- `visual:screenshot upload-modal` works.
- Command logs in or creates auth state.
- Command opens upload modal.
- Screenshot includes modal and backdrop.
- Current PNG saved to expected path.
- Feed desktop/mobile targets still work.
- No baseline committed yet unless RG-633.
- No pixel diff logic added.

### Definition of Done

- Target/test added.
- Manual command run succeeds.
- Current upload modal screenshot generated.
- Коммит: `RG-628: Add screenshot command for upload modal`

### Files likely touched

```txt
app/Console/Commands/CaptureVisualScreenshotCommand.php
app/Support/VisualRegression/VisualScreenshotTargets.php
tests/Feature/Console/VisualScreenshotCommandTest.php
resources/views/livewire/upload/upload-post-form.blade.php
resources/views/layouts/navigation.blade.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-629 — Add Screenshot Command For Post Drawer

**Area:** Visual Regression / Screenshot Command  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-629-add-screenshot-command-for-post-drawer`  
**Base branch:** develop
**Depends on:** RG-628

### Goal

Добавить screenshot target для post drawer open state.

### TDD step

Target config test:

```php
it('resolves post drawer screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('post-drawer');

    expect($target->name)->toBe('post-drawer');
    expect($target->viewportWidth)->toBe(1440);
});
```

Command smoke test if stable:

```php
$this->artisan('visual:screenshot post-drawer')
    ->assertExitCode(0);

expect(file_exists(base_path('tests/Visual/current/post-drawer.png')))->toBeTrue();
```

### Implementation

Target:

```php
'post-drawer' => [
    'url' => route('feed'),
    'viewport' => [1440, 1000],
    'state' => 'guest or authenticated',
    'output' => 'post-drawer.png',
    'steps' => [
        'click' => '[data-testid="open-post-drawer-{known_post_id}"]',
        'wait' => '[data-testid="post-drawer"]',
    ],
]
```

Need deterministic post:

```txt
Use seed post with title "Demo: Homemade Italian Pasta"
or create screenshot-specific post before capture.
```

Recommended:

```txt
visual screenshot command with --fresh uses seed data;
target finds first published post via known title or first [data-testid^="open-post-drawer"].
```

Better:

```txt
data-testid="open-post-drawer-{id}"
```

Command can read first published post id after seeding and build selector.

Drawer state should include:

```txt
- backdrop;
- drawer panel;
- post image/title;
- voting controls;
- comments if visible above fold.
```

### Screenshot target

```txt
Name: post-drawer
Viewport: 1440x1000
State: feed with post drawer open
Output current: tests/Visual/current/post-drawer.png
Output baseline: tests/Visual/baselines/post-drawer.png
```

### Acceptance criteria

- `visual:screenshot post-drawer` works.
- Command opens feed.
- Command opens a deterministic post drawer.
- Command waits for drawer selector.
- Screenshot includes drawer open state.
- Current PNG saved to expected path.
- No baseline committed yet unless RG-634.
- No pixel diff logic added.

### Definition of Done

- Target/test added.
- Manual command run succeeds.
- Current post drawer screenshot generated.
- Коммит: `RG-629: Add screenshot command for post drawer`

### Files likely touched

```txt
app/Console/Commands/CaptureVisualScreenshotCommand.php
app/Support/VisualRegression/VisualScreenshotTargets.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/post/post-drawer.blade.php
tests/Feature/Console/VisualScreenshotCommandTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-630 — Add Screenshot Command For Post Show

**Area:** Visual Regression / Screenshot Command  
**Type:** Command  
**Priority:** P0  
**Branch:** `feature/RG-630-add-screenshot-command-for-post-show`  
**Base branch:** develop
**Depends on:** RG-629

### Goal

Добавить screenshot target для standalone post show page.

### TDD step

Target config test:

```php
it('resolves post show screenshot target', function () {
    $target = app(VisualScreenshotTargets::class)->get('post-show');

    expect($target->name)->toBe('post-show');
    expect($target->viewportWidth)->toBe(1440);
});
```

Command smoke test if stable:

```php
$this->artisan('visual:screenshot post-show')
    ->assertExitCode(0);

expect(file_exists(base_path('tests/Visual/current/post-show.png')))->toBeTrue();
```

### Implementation

Target should:

```txt
- find deterministic published post;
- open route('posts.show', $post);
- set viewport 1440x1000;
- wait for [data-testid="post-show-page"];
- save screenshot.
```

If route name differs, use actual route from Phase 12/32.

Add stable selector if missing:

```txt
data-testid="post-show-page"
```

Post show screenshot should capture:

```txt
- standalone post layout;
- image/title/metadata;
- votes/origin/cuisine controls;
- share panel if visible;
- comments area if above fold.
```

### Screenshot target

```txt
Name: post-show
Viewport: 1440x1000
State: standalone published post show page
Output current: tests/Visual/current/post-show.png
Output baseline: tests/Visual/baselines/post-show.png
```

### Acceptance criteria

- `visual:screenshot post-show` works.
- Command opens deterministic published post show route.
- Command waits for post show selector.
- Current PNG saved to expected path.
- All previous screenshot targets still work.
- Docs list all targets.
- No baseline committed yet unless RG-635.
- No pixel diff logic added.

### Definition of Done

- Target/test added.
- Manual command run succeeds.
- Current post show screenshot generated.
- Коммит: `RG-630: Add screenshot command for post show`

### Files likely touched

```txt
app/Console/Commands/CaptureVisualScreenshotCommand.php
app/Support/VisualRegression/VisualScreenshotTargets.php
resources/views/livewire/post/post-show.blade.php
tests/Feature/Console/VisualScreenshotCommandTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-631 — Save First Approved Feed Desktop Baseline

**Area:** Visual Regression / Baseline  
**Type:** Approved Artifact  
**Priority:** P0  
**Branch:** `feature/RG-631-save-first-approved-feed-desktop-baseline`  
**Base branch:** develop
**Depends on:** RG-630

### Goal

Создать первый approved baseline screenshot для desktop feed.

### TDD step

No direct unit test — approved visual artifact task.

Optional file existence test:

```php
it('has approved feed desktop visual baseline', function () {
    expect(file_exists(base_path('tests/Visual/baselines/feed-desktop.png')))->toBeTrue();
    expect(filesize(base_path('tests/Visual/baselines/feed-desktop.png')))->toBeGreaterThan(0);
});
```

### Implementation

Run:

```bash
php artisan migrate:fresh --seed
php artisan visual:screenshot feed-desktop --baseline
```

or:

```bash
php artisan visual:screenshots --baseline --target=feed-desktop
```

Then manually inspect:

```txt
tests/Visual/baselines/feed-desktop.png
```

Approve only if:

```txt
- feed spacing matches Phase 37 polish;
- dark background is correct;
- card radius is correct;
- header layout is correct;
- published posts visible;
- no broken images;
- no debug text;
- no random local URLs/errors.
```

Commit PNG.

Update docs:

```txt
docs/testing/visual-regression.md
```

### Output

```txt
tests/Visual/baselines/feed-desktop.png
```

### Acceptance criteria

- `feed-desktop.png` baseline exists.
- PNG is non-empty.
- Screenshot was manually inspected and approved.
- Baseline uses 1440x1000 viewport.
- Baseline does not contain debug/error output.
- Current screenshot command can regenerate same target.
- No pixel diff logic added.

### Definition of Done

- Baseline generated.
- Baseline manually approved.
- File committed.
- Optional existence test passes.
- Коммит: `RG-631: Save first approved feed desktop baseline`

### Files likely touched

```txt
tests/Visual/baselines/feed-desktop.png
tests/Feature/Visual/VisualBaselinesTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-632 — Save First Approved Feed Mobile Baseline

**Area:** Visual Regression / Baseline  
**Type:** Approved Artifact  
**Priority:** P0  
**Branch:** `feature/RG-632-save-first-approved-feed-mobile-baseline`  
**Base branch:** develop
**Depends on:** RG-631

### Goal

Создать первый approved baseline screenshot для mobile feed.

### TDD step

No direct unit test — approved visual artifact task.

Optional file existence test:

```php
it('has approved feed mobile visual baseline', function () {
    expect(file_exists(base_path('tests/Visual/baselines/feed-mobile.png')))->toBeTrue();
    expect(filesize(base_path('tests/Visual/baselines/feed-mobile.png')))->toBeGreaterThan(0);
});
```

### Implementation

Run:

```bash
php artisan migrate:fresh --seed
php artisan visual:screenshot feed-mobile --baseline
```

Manually inspect:

```txt
tests/Visual/baselines/feed-mobile.png
```

Approve only if:

```txt
- mobile card layout is clean at 390px;
- no horizontal scroll artifacts;
- header mobile layout is not broken;
- post card image/title/stats readable;
- vote controls usable;
- background/card contrast matches prototype.
```

Commit PNG.

### Output

```txt
tests/Visual/baselines/feed-mobile.png
```

### Acceptance criteria

- `feed-mobile.png` baseline exists.
- PNG is non-empty.
- Screenshot was manually inspected and approved.
- Baseline uses 390x844 viewport.
- No horizontal overflow visible.
- No debug/error output.
- No pixel diff logic added.

### Definition of Done

- Baseline generated.
- Baseline manually approved.
- File committed.
- Optional existence test passes.
- Коммит: `RG-632: Save first approved feed mobile baseline`

### Files likely touched

```txt
tests/Visual/baselines/feed-mobile.png
tests/Feature/Visual/VisualBaselinesTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-633 — Save First Approved Upload Modal Baseline

**Area:** Visual Regression / Baseline  
**Type:** Approved Artifact  
**Priority:** P0  
**Branch:** `feature/RG-633-save-first-approved-upload-modal-baseline`  
**Base branch:** develop
**Depends on:** RG-632

### Goal

Создать первый approved baseline screenshot для upload modal state.

### TDD step

No direct unit test — approved visual artifact task.

Optional file existence test:

```php
it('has approved upload modal visual baseline', function () {
    expect(file_exists(base_path('tests/Visual/baselines/upload-modal.png')))->toBeTrue();
    expect(filesize(base_path('tests/Visual/baselines/upload-modal.png')))->toBeGreaterThan(0);
});
```

### Implementation

Run:

```bash
php artisan migrate:fresh --seed
php artisan visual:screenshot upload-modal --baseline
```

Manually inspect:

```txt
tests/Visual/baselines/upload-modal.png
```

Approve only if:

```txt
- modal centered/aligned;
- backdrop correct;
- upload form fields readable;
- upload CTA styled correctly;
- focus/disabled/loading states not accidentally shown;
- no browser/debug overlay;
- no missing asset icon unless intended placeholder.
```

Commit PNG.

### Output

```txt
tests/Visual/baselines/upload-modal.png
```

### Acceptance criteria

- `upload-modal.png` baseline exists.
- PNG is non-empty.
- Screenshot includes modal open state.
- Backdrop visible and correct.
- Screenshot was manually inspected and approved.
- No debug/error output.
- No pixel diff logic added.

### Definition of Done

- Baseline generated.
- Baseline manually approved.
- File committed.
- Optional existence test passes.
- Коммит: `RG-633: Save first approved upload modal baseline`

### Files likely touched

```txt
tests/Visual/baselines/upload-modal.png
tests/Feature/Visual/VisualBaselinesTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-634 — Save First Approved Post Drawer Baseline

**Area:** Visual Regression / Baseline  
**Type:** Approved Artifact  
**Priority:** P0  
**Branch:** `feature/RG-634-save-first-approved-post-drawer-baseline`  
**Base branch:** develop
**Depends on:** RG-633

### Goal

Создать первый approved baseline screenshot для post drawer open state.

### TDD step

No direct unit test — approved visual artifact task.

Optional file existence test:

```php
it('has approved post drawer visual baseline', function () {
    expect(file_exists(base_path('tests/Visual/baselines/post-drawer.png')))->toBeTrue();
    expect(filesize(base_path('tests/Visual/baselines/post-drawer.png')))->toBeGreaterThan(0);
});
```

### Implementation

Run:

```bash
php artisan migrate:fresh --seed
php artisan visual:screenshot post-drawer --baseline
```

Manually inspect:

```txt
tests/Visual/baselines/post-drawer.png
```

Approve only if:

```txt
- drawer width matches Phase 37;
- drawer animation final state is captured, not half-transition;
- backdrop correct;
- post title/image visible;
- vote controls aligned;
- comments area not broken;
- close button visible;
- no layout overflow.
```

Commit PNG.

### Output

```txt
tests/Visual/baselines/post-drawer.png
```

### Acceptance criteria

- `post-drawer.png` baseline exists.
- PNG is non-empty.
- Screenshot includes drawer open state.
- Drawer captured after transition finished.
- Screenshot was manually inspected and approved.
- No debug/error output.
- No pixel diff logic added.

### Definition of Done

- Baseline generated.
- Baseline manually approved.
- File committed.
- Optional existence test passes.
- Коммит: `RG-634: Save first approved post drawer baseline`

### Files likely touched

```txt
tests/Visual/baselines/post-drawer.png
tests/Feature/Visual/VisualBaselinesTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-635 — Save First Approved Post Show Baseline

**Area:** Visual Regression / Baseline  
**Type:** Approved Artifact  
**Priority:** P0  
**Branch:** `feature/RG-635-save-first-approved-post-show-baseline`  
**Base branch:** develop
**Depends on:** RG-634

### Goal

Создать первый approved baseline screenshot для standalone post show page.

### TDD step

No direct unit test — approved visual artifact task.

Optional file existence test:

```php
it('has approved post show visual baseline', function () {
    expect(file_exists(base_path('tests/Visual/baselines/post-show.png')))->toBeTrue();
    expect(filesize(base_path('tests/Visual/baselines/post-show.png')))->toBeGreaterThan(0);
});
```

### Implementation

Run:

```bash
php artisan migrate:fresh --seed
php artisan visual:screenshot post-show --baseline
```

Manually inspect:

```txt
tests/Visual/baselines/post-show.png
```

Approve only if:

```txt
- standalone post layout matches Phase 37;
- desktop two-column layout is correct if used;
- image/title/metadata readable;
- share panel from Phase 32 looks correct;
- voting controls aligned;
- comments section not broken;
- no missing route/debug output.
```

Commit PNG.

### Output

```txt
tests/Visual/baselines/post-show.png
```

### Acceptance criteria

- `post-show.png` baseline exists.
- PNG is non-empty.
- Screenshot includes standalone post show page.
- Screenshot was manually inspected and approved.
- No debug/error output.
- No pixel diff logic added.

### Definition of Done

- Baseline generated.
- Baseline manually approved.
- File committed.
- Optional existence test passes.
- Коммит: `RG-635: Save first approved post show baseline`

### Files likely touched

```txt
tests/Visual/baselines/post-show.png
tests/Feature/Visual/VisualBaselinesTest.php
docs/testing/visual-regression.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-636 — Add Manual Visual Review Checklist To PR Template

**Area:** Visual Regression / PR Process  
**Type:** Docs / Process  
**Priority:** P0  
**Branch:** `feature/RG-636-add-manual-visual-review-checklist-to-pr-template`  
**Base branch:** develop
**Depends on:** RG-635

### Goal

Добавить manual visual review checklist в PR template, чтобы visual baselines реально использовались.

### TDD step

Docs/template test:

```php
it('has pull request template with visual review checklist', function () {
    $template = base_path('.github/pull_request_template.md');

    expect(file_exists($template))->toBeTrue();

    $content = file_get_contents($template);

    expect($content)->toContain('Visual Review');
    expect($content)->toContain('visual:screenshot');
    expect($content)->toContain('baselines');
    expect($content)->toContain('browser smoke');
});
```

### Implementation

Create/update:

```txt
.github/pull_request_template.md
```

Add section:

```md
## Visual Review

- [ ] I ran browser smoke tests when this PR touches UI or Livewire flows.
- [ ] I ran visual screenshots when this PR changes public UI.
- [ ] I compared current screenshots against approved baselines.
- [ ] I updated approved baselines only for intentional visual changes.
- [ ] I checked desktop feed.
- [ ] I checked mobile feed.
- [ ] I checked upload modal.
- [ ] I checked post drawer.
- [ ] I checked post show.
- [ ] I confirmed no accidental debug text, broken images, or layout overflow is visible.
```

Also finalize:

```txt
docs/testing/visual-regression.md
```

Docs should include:

```txt
- command list;
- screenshot targets;
- current vs baseline paths;
- how to approve baseline updates;
- when not to update baselines;
- no CI diff yet;
- relation to Phase 38 browser tests.
```

Example commands:

```bash
php artisan visual:screenshot feed-desktop
php artisan visual:screenshot feed-mobile
php artisan visual:screenshot upload-modal
php artisan visual:screenshot post-drawer
php artisan visual:screenshot post-show

php artisan visual:screenshot all --baseline
```

If actual command syntax differs, docs must match implementation.

Final run:

```bash
composer test
npm run build
composer test:browser
php artisan visual:screenshot all
```

or actual browser command.

### Acceptance criteria

- PR template exists.
- PR template has Visual Review section.
- Checklist mentions browser smoke tests.
- Checklist mentions screenshots/baselines.
- Checklist covers five baseline targets.
- Visual regression docs are complete.
- All baseline file existence tests pass.
- No pixel diff CI logic added.
- `composer test` passes.
- `npm run build` passes.
- Browser smoke suite still passes.

### Definition of Done

- PR template updated.
- Docs finalized.
- Tests pass.
- Browser screenshot command verified.
- Baselines committed.
- Коммит: `RG-636: Add manual visual review checklist to PR template`

### Files likely touched

```txt
.github/pull_request_template.md
docs/testing/visual-regression.md
tests/Feature/Docs/PullRequestTemplateTest.php
tests/Feature/Visual/VisualBaselinesTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 11. Phase 39 Completion Criteria

Phase 39 завершена, когда:

```txt
- RG-626–RG-636 выполнены;
- screenshot command for feed desktop exists;
- screenshot command for feed mobile exists;
- screenshot command for upload modal exists;
- screenshot command for post drawer exists;
- screenshot command for post show exists;
- current screenshot output directory exists;
- baseline screenshot directory exists;
- first approved feed desktop baseline exists;
- first approved feed mobile baseline exists;
- first approved upload modal baseline exists;
- first approved post drawer baseline exists;
- first approved post show baseline exists;
- baseline PNG files are committed and non-empty;
- docs/testing/visual-regression.md exists;
- .github/pull_request_template.md has Visual Review checklist;
- screenshot commands use deterministic data;
- screenshot commands use fixed viewport sizes;
- screenshot commands do not depend on external assets;
- no automatic pixel-diff CI failure was added;
- no API readiness files from Phase 40 were added;
- composer test passes;
- npm run build passes;
- browser smoke suite from Phase 38 still passes;
- visual screenshot command can be run locally.
```
---

# 12. Что нельзя делать в Phase 39

Без отдельной задачи нельзя:

```txt
- добавлять API route file placeholder;
- добавлять JSON API resources;
- добавлять API auth/versioning notes;
- добавлять full pixel diff engine;
- добавлять CI visual diff failure;
- подключать Percy/Chromatic/Argos;
- делать full Playwright/Cypress migration;
- создавать baselines для всех admin pages;
- создавать mobile/tablet/desktop matrix для всех pages;
- делать accessibility audit;
- делать performance assertions;
- менять UI ради baseline без отдельной UI polish задачи;
- добавлять migrations;
- добавлять backend business logic;
- добавлять React/Vue/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-626 Add screenshot command for feed desktop
RG-627 Add screenshot command for feed mobile
RG-628 Add screenshot command for upload modal
RG-629 Add screenshot command for post drawer
RG-630 Add screenshot command for post show
RG-631 Save first approved feed desktop baseline
RG-632 Save first approved feed mobile baseline
RG-633 Save first approved upload modal baseline
RG-634 Save first approved post drawer baseline
RG-635 Save first approved post show baseline
RG-636 Add manual visual review checklist to PR template
```
---

# 14. Release

После завершения Phase 39:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
composer test:browser
php artisan visual:screenshot all

git checkout -b release/v0.2.20-phase39-visual-regression-foundation
git push -u origin release/v0.2.20-phase39-visual-regression-foundation
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

git tag -a v0.2.20-phase39-visual-regression-foundation -m "RateGuru Phase 39 Visual Regression Foundation"
git push origin v0.2.20-phase39-visual-regression-foundation
```
