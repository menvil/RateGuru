# RateGuru — Phase 37 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 37 — UI Polish Pass**  
Диапазон задач: **RG-598 → RG-615**  
Основа нумерации: исходный atomic backlog, где Phase 37 начинается с задачи 598 и заканчивается задачей 615.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 37 соответствует исходному блоку:

```txt
Phase 37 — UI Polish Pass
```

Правильный диапазон Phase 37:

```txt
RG-598 — Align feed spacing with prototype
RG-599 — Align card radius with prototype
RG-600 — Align dark background with prototype
RG-601 — Align accent purple usage
RG-602 — Align header layout
RG-603 — Align upload button style
RG-604 — Align vote button states
RG-605 — Align origin vote pills
RG-606 — Align cuisine vote chips
RG-607 — Align drawer width desktop
RG-608 — Align drawer animation
RG-609 — Align modal backdrop
RG-610 — Align mobile card layout
RG-611 — Align desktop two-column layout
RG-612 — Add hover states
RG-613 — Add focus states
RG-614 — Add disabled states
RG-615 — Add loading transitions
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно:

```txt
Phase 36 заканчивается на RG-597.
Phase 37 занимает RG-598 → RG-615.
Phase 38 начинается с RG-616 и делает Browser Smoke Tests.
Phase 39 начинается с RG-626 и делает Visual Regression Foundation.
```

Значит Phase 37 не должна добавлять browser tests, screenshot commands, screenshot baselines или Playwright/Cypress infrastructure. Это только UI polish pass.
---

# 2. Цель Phase 37

Phase 37 приводит готовый интерфейс RateGuru к изначальному prototype/design contract.

После Phase 37:

```txt
- feed spacing выглядит как в прототипе;
- card radius совпадает с дизайн-контрактом;
- dark background стабилен на всех ключевых экранах;
- accent purple используется последовательно;
- header layout выровнен;
- upload button выглядит как primary CTA;
- vote buttons имеют понятные normal/active/hover/focus/disabled/loading states;
- origin vote pills выглядят единообразно;
- cuisine chips выглядят единообразно;
- desktop drawer имеет правильную ширину;
- drawer animation не выглядит сырой;
- modal backdrop совпадает с прототипом;
- mobile cards не разваливаются;
- desktop layout использует two-column composition там, где нужно;
- hover/focus/disabled/loading states существуют и согласованы.
```

Это не фаза добавления функциональности. Это фаза визуальной консистентности.
---

# 3. Scope Phase 37

## Входит

```txt
- CSS/design token cleanup;
- Tailwind class cleanup;
- Blade component class refinements;
- Livewire state class refinements;
- PostCard visual polish;
- FeedPage/PostFeed spacing;
- Header layout polish;
- Upload button polish;
- Voting UI polish;
- Origin/Cuisine voting UI polish;
- Drawer dimensions and animation polish;
- Modal backdrop polish;
- mobile card layout polish;
- desktop two-column layout polish;
- hover/focus/disabled/loading state polish;
- UI kit updates;
- manual design review notes.
```

## Не входит

```txt
- новые backend features;
- новые migrations;
- новые Livewire features;
- новые Filament resources;
- browser smoke tests;
- visual regression automation;
- screenshot baseline generation;
- Playwright/Cypress installation;
- design system rewrite;
- React/Vue/Inertia;
- new public pages;
- SEO/OpenGraph changes;
- API changes.
```

Если в Phase 37 появляется новая бизнес-логика — это ошибка.
---

# 4. Critical Decisions

## 4.1. Polish должен идти через tokens/components, не через хаотичные class patches

Неправильно:

```blade
<div class="bg-[#111118] rounded-[22px] p-[17px]">
```

разбросанное по десяткам views.

Правильно:

```txt
- theme tokens;
- Tailwind theme mapping;
- reusable Blade components;
- shared utility classes;
- минимальные local overrides только там, где это реально layout-specific.
```

Цель:

```txt
один визуальный язык, а не 20 похожих, но разных карточек.
```

## 4.2. Prototype is source of visual truth

Phase 1 уже должна была создать design contract. Phase 37 должна сверяться с:

```txt
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
original prototype HTML/CSS
```

Если design contract расходится с оригинальным prototype, фиксировать это в review notes, а не молча выбирать случайный вариант.

## 4.3. No screenshot baseline yet

Phase 39 отвечает за:

```txt
screenshot command
baseline screenshots
visual regression foundation
```

Phase 37 может создавать manual review docs, но не должна создавать approved screenshot baselines.

Допустимо:

```txt
docs/design/phase-37-ui-polish-review.md
```

Недопустимо:

```txt
tests/Visual/baselines/feed-desktop.png
```

## 4.4. Browser tests are Phase 38

Phase 37 может использовать ручную проверку браузером, но не добавляет browser test suite.

Недопустимо:

```txt
RG-598 adds Dusk/Playwright test
```

Правильно:

```txt
RG-598 updates spacing and documents manual check.
```

## 4.5. Accessibility states matter

Hover/focus/disabled/loading states — не украшение.

Минимум:

```txt
- focus-visible ring;
- disabled opacity/cursor;
- aria-disabled or disabled attribute where appropriate;
- loading state does not allow double submit/click;
- hover state not only color, but also border/surface if needed.
```

Не надо делать полноценный WCAG audit в Phase 37, но базовые интерактивные состояния обязаны быть.

## 4.6. Mobile first

Polish должен проверять минимум:

```txt
375px mobile
768px tablet-ish
1280px desktop
```

Если правка улучшает desktop, но ломает mobile card layout — задача не завершена.

## 4.7. UI kit must reflect final polished components

Если меняются button/card/vote/chip/modal/drawer states, `/dev/ui-kit` должен показывать актуальные состояния.

Phase 37 должна поддерживать UI kit как living reference.

## 4.8. No content changes just to make UI look better

Не менять seed data, titles, counters, reports, statuses ради visual polish.

Для визуальной проверки использовать seed data из Phase 36, но не менять его без отдельной причины.

## 4.9. Preserve Livewire behavior

Polish не должен ломать:

```txt
- upload modal open/close;
- drawer open/close;
- vote submit;
- comments submit;
- report modal;
- notifications dropdown;
- inline moderation actions.
```

После UI polish нужно прогнать:

```bash
composer test
npm run build
```

и руками проверить ключевые flows.
---

# 5. Design Token Targets

Ориентиры из prototype/design contract:

```txt
background:
- app dark background
- card dark surface
- elevated surface
- border subtle gray/blue-gray

text:
- primary near-white
- secondary muted gray
- tertiary muted darker gray

accent:
- purple primary
- purple hover
- soft purple background

states:
- good/success green
- warning amber
- danger red

shape:
- large rounded cards
- consistent pill radius for chips/votes
- modal/drawer rounded corners where appropriate

motion:
- quick, subtle transitions
- no bouncy/slow amateur animation
```

Рекомендуемые CSS tokens, если ещё не нормализованы:

```css
:root {
    --rg-bg: #0b0b10;
    --rg-surface: #15151d;
    --rg-surface-elevated: #1b1b25;
    --rg-border: #2e2e3a;
    --rg-text: #e8e8ee;
    --rg-text-muted: #c2c2cc;
    --rg-text-subtle: #7d7d8c;
    --rg-accent: #a855f7;
    --rg-accent-hover: #c084fc;
    --rg-accent-soft: rgba(168, 85, 247, 0.15);
}
```

Если Phase 1 уже создала другие token names, не плодить новые. Использовать существующие и привести значения к прототипу.
---

# 6. Files Likely Touched Across Phase 37

CSS / theme:

```txt
resources/css/app.css
resources/css/theme.css
tailwind.config.js
```

Base UI components:

```txt
resources/views/components/ui/button.blade.php
resources/views/components/ui/card.blade.php
resources/views/components/ui/badge.blade.php
resources/views/components/ui/modal-shell.blade.php
resources/views/components/ui/drawer-shell.blade.php
resources/views/components/ui/dropdown-shell.blade.php
resources/views/components/ui/loading-skeleton.blade.php
resources/views/components/ui/error-message.blade.php
resources/views/components/ui/avatar.blade.php
```

Product components:

```txt
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/views/livewire/post/post-show.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/livewire/comments/comment-form.blade.php
resources/views/livewire/comments/comment-item.blade.php
resources/views/livewire/reports/report-modal.blade.php
resources/views/livewire/notifications/notification-bell.blade.php
```

Layouts:

```txt
resources/views/layouts/app.blade.php
resources/views/layouts/navigation.blade.php
resources/views/components/layout/header.blade.php
```

Docs:

```txt
docs/design/phase-37-ui-polish-review.md
docs/design/ui-review-checklist.md
```
---

# 7. GitFlow для Phase 37

## Base branch

Все задачи Phase 37 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-598-align-feed-spacing-with-prototype
feature/RG-604-align-vote-button-states
feature/RG-615-add-loading-transitions
```

## Commit format

```txt
RG-598: Align feed spacing with prototype
RG-604: Align vote button states
RG-615: Add loading transitions
```

## Release branch

После выполнения `RG-598`–`RG-615`:

```txt
release/v0.2.18-phase37-ui-polish-pass
```

## Tag

После merge release branch в `main`:

```txt
v0.2.18-phase37-ui-polish-pass
```

Почему `v0.2.18`: Phase 36 использует `v0.2.17`, Phase 37 следующий release.
---

# 8. TDD / Review Rules for Phase 37

## Для visual-only задач

Многие задачи Phase 37 не имеют нормального unit test.

Правильный формат:

```txt
TDD step: No direct unit test — visual polish task.
Verification:
- npm run build
- component renders in /dev/ui-kit
- manual checklist completed
- relevant Blade/Livewire render test still passes
```

## Для interactive states

Тестировать можно частично:

```txt
- rendered markup contains disabled attribute;
- rendered markup contains wire:loading.attr="disabled";
- focus-visible class exists;
- loading skeleton exists;
- aria attributes exist.
```

## Для layout tasks

Проверять:

```txt
- desktop render smoke;
- mobile class presence;
- no hidden/pending content leak;
- no functional tests broken.
```

## Обязательный финальный review

В конце Phase 37 должен быть файл:

```txt
docs/design/phase-37-ui-polish-review.md
```

С чеклистом:

```txt
- feed spacing checked;
- card radius checked;
- background checked;
- accent purple checked;
- header checked;
- upload button checked;
- vote states checked;
- origin/cuisine controls checked;
- drawer checked;
- modal checked;
- mobile card checked;
- desktop two-column checked;
- hover/focus/disabled/loading states checked;
```
---

# 9. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Design / Polish / Components
Type: UI / Refactor / Visual / State / Docs
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно визуально измениться.

TDD step:
Какой тест пишем первым. Если это чистый visual polish:
No direct unit test — visual polish task.

Implementation:
Что именно меняем.

Verification:
- npm run build
- composer test или relevant test group
- проверка в /dev/ui-kit
- desktop manual check
- mobile manual check
- design checklist updated

Acceptance criteria:
- Проверяемый результат 1
- Проверяемый результат 2
- Проверяемый результат 3

Definition of Done:
- Тест написан первым, если задача тестируемая
- Визуальная проверка выполнена, если unit test невозможен
- UI kit обновлён, если менялся базовый компонент
- Mobile не сломан
- Desktop не сломан
- Нет новых business features
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 10. Phase 37 Atomic Tasks
---

## RG-598 — Align Feed Spacing With Prototype

**Area:** UI / Feed / Spacing  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-598-align-feed-spacing-with-prototype`  
**Base branch:** develop
**Depends on:** RG-597

### Goal

Привести spacing feed page к прототипу: outer container, top controls, card gaps, section spacing.

### TDD step

No direct unit test — visual polish task.

Optional smoke/render test:

```php
it('renders feed page with feed layout container', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-page"', false);
});
```

### Implementation

Проверить и выровнять:

```txt
- max-width feed container;
- horizontal padding mobile/desktop;
- vertical spacing between header/search/categories/feed;
- gap between PostCard items;
- empty/loading state spacing;
- pagination spacing if exists.
```

Recommended layout targets:

```txt
mobile:
- px-4
- py-4/6
- gap-4 between cards

desktop:
- max-w-7xl or prototype-equivalent
- px-6/8
- py-6/8
- gap-5/6 between cards
```

Update:

```txt
FeedPage
PostFeed
PostCard wrapper
empty/loading states
```

Do not change feed query or sorting.

### Verification

```bash
npm run build
composer test --filter=Feed
```

Manual:

```txt
- /feed desktop 1280px
- /feed mobile 375px
- /dev/ui-kit PostCard section
```

### Acceptance criteria

- Feed spacing matches prototype/design contract.
- Cards do not feel cramped.
- Search/categories/sort controls align with feed grid.
- Empty/loading states align with normal feed container.
- Mobile spacing is not desktop-compressed.
- No feed behavior changed.

### Definition of Done

- Visual check completed.
- UI kit feed/card sample still useful.
- Relevant tests pass.
- Коммит: `RG-598: Align feed spacing with prototype`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/components/feed/post-card.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-599 — Align Card Radius With Prototype

**Area:** UI / Components / Card  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-599-align-card-radius-with-prototype`  
**Base branch:** develop
**Depends on:** RG-598

### Goal

Привести border-radius карточек к единому prototype-consistent виду.

### TDD step

No direct unit test — visual polish task.

Optional component markup test:

```php
it('renders base card with rounded design token class', function () {
    $view = Blade::render('<x-ui.card>Card</x-ui.card>');

    expect($view)->toContain('rounded-');
});
```

### Implementation

Проверить:

```txt
- x-ui.card;
- PostCard;
- modal content;
- drawer surface;
- comment item;
- report modal;
- notification dropdown;
- Filament not required unless public UI shares component.
```

Rule:

```txt
Use shared card radius token/class.
```

Recommended:

```txt
cards: rounded-2xl
large surfaces/drawer/modal: rounded-3xl or prototype-specific
small chips/buttons: rounded-full or rounded-xl
```

Не смешивать:

```txt
rounded-lg
rounded-xl
rounded-2xl
rounded-[20px]
```

случайно на одинаковых card surfaces.

### Verification

```bash
npm run build
```

Manual:

```txt
- /dev/ui-kit card samples
- feed PostCard
- post drawer
- upload modal
- comments section
```

### Acceptance criteria

- Base Card radius matches prototype.
- PostCard uses same card radius.
- Modal/drawer radius looks intentionally related, not random.
- No inline arbitrary radius unless documented.
- UI kit shows updated radius.
- No layout shift breaks mobile.

### Definition of Done

- Shared radius normalized.
- UI kit checked.
- Коммит: `RG-599: Align card radius with prototype`

### Files likely touched

```txt
resources/views/components/ui/card.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/components/ui/modal-shell.blade.php
resources/views/components/ui/drawer-shell.blade.php
resources/views/dev/ui-kit.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-600 — Align Dark Background With Prototype

**Area:** UI / Theme / Background  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-600-align-dark-background-with-prototype`  
**Base branch:** develop
**Depends on:** RG-599

### Goal

Привести app background, surface background и elevated surface к прототипу.

### TDD step

No direct unit test — visual polish task.

Optional token test:

```php
it('defines dark background css variables', function () {
    $css = file_get_contents(resource_path('css/theme.css'));

    expect($css)->toContain('--rg-bg');
    expect($css)->toContain('--rg-surface');
});
```

### Implementation

Проверить tokens:

```txt
--rg-bg
--rg-surface
--rg-surface-elevated
--rg-border
--rg-text
--rg-text-muted
```

Tailwind mapping:

```txt
bg-rg-bg
bg-rg-surface
bg-rg-surface-elevated
border-rg-border
text-rg-text
text-rg-muted
```

Apply consistently to:

```txt
body/app layout
feed background
cards
header
drawer
modal
dropdowns
inputs
comment surfaces
notification dropdown
```

Do not accidentally introduce pure black/pure white.

### Verification

```bash
npm run build
```

Manual:

```txt
- feed
- post drawer
- post show
- upload modal
- report modal
- notifications dropdown
- profile page
```

### Acceptance criteria

- App background matches prototype dark base.
- Cards/surfaces are visually separated but not too bright.
- Borders are subtle and consistent.
- Text contrast remains readable.
- No random black/gray backgrounds remain on public UI.
- UI kit updated if tokens changed.

### Definition of Done

- Tokens checked/updated.
- Key screens manually checked.
- Коммит: `RG-600: Align dark background with prototype`

### Files likely touched

```txt
resources/css/theme.css
resources/css/app.css
tailwind.config.js
resources/views/layouts/app.blade.php
resources/views/components/ui/card.blade.php
resources/views/components/ui/modal-shell.blade.php
resources/views/components/ui/drawer-shell.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-601 — Align Accent Purple Usage

**Area:** UI / Theme / Accent  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-601-align-accent-purple-usage`  
**Base branch:** develop
**Depends on:** RG-600

### Goal

Привести использование accent purple к единой системе.

### TDD step

No direct unit test — visual polish task.

Optional token test:

```php
it('defines accent purple css variables', function () {
    $css = file_get_contents(resource_path('css/theme.css'));

    expect($css)->toContain('--rg-accent');
    expect($css)->toContain('--rg-accent-soft');
});
```

### Implementation

Проверить и унифицировать accent usage:

```txt
primary buttons
upload button
active votes
selected origin pills
selected cuisine chips
focus rings
links/actions
badges where accent is appropriate
```

Rules:

```txt
- accent purple for primary action/selected state;
- danger red only for destructive actions;
- success green only for success/positive status;
- do not use purple for every random label;
- soft purple background for selected pills/chips if prototype uses it.
```

Replace random classes:

```txt
text-purple-400
bg-violet-500
border-fuchsia-500
```

with token classes:

```txt
text-rg-accent
bg-rg-accent
bg-rg-accent-soft
border-rg-accent
focus-visible:ring-rg-accent
```

if Tailwind mapping supports them.

### Verification

```bash
npm run build
```

Manual:

```txt
- upload CTA
- vote buttons selected state
- origin/cuisine selected state
- focus rings
- links
- UI kit buttons/chips
```

### Acceptance criteria

- Accent purple value matches prototype.
- Selected states use same accent language.
- Focus rings are visible and consistent.
- Destructive actions remain red, not purple.
- No random purple/violet/fuchsia drift.
- UI kit updated.

### Definition of Done

- Accent token usage normalized.
- UI kit checked.
- Коммит: `RG-601: Align accent purple usage`

### Files likely touched

```txt
resources/css/theme.css
tailwind.config.js
resources/views/components/ui/button.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
resources/views/dev/ui-kit.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-602 — Align Header Layout

**Area:** UI / Layout / Header  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-602-align-header-layout`  
**Base branch:** develop
**Depends on:** RG-601

### Goal

Выровнять header layout с прототипом: brand, nav, search/controls if present, upload CTA, auth actions, notification bell.

### TDD step

No direct unit test — visual polish task.

Optional render test:

```php
it('renders main header', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="app-header"', false);
});
```

### Implementation

Проверить:

```txt
- header height;
- horizontal padding;
- brand position;
- nav alignment;
- upload button position;
- notification bell position;
- auth avatar/menu position;
- mobile header wrapping/collapsing;
- sticky behavior if exists.
```

Recommended:

```txt
desktop:
- single row
- brand left
- primary navigation/controls center or near left depending prototype
- upload + notification + user controls right

mobile:
- brand left
- compact actions right
- search/categories can move below if needed
```

Do not add new nav features.

### Verification

```bash
npm run build
composer test --filter=Navigation
```

Manual:

```txt
- guest header
- authenticated user header
- moderator/admin header if different
- mobile 375px
- desktop 1280px
```

### Acceptance criteria

- Header spacing matches prototype.
- Header does not wrap awkwardly on mobile.
- Upload CTA remains visible for authenticated user.
- Notification bell does not break layout.
- Guest/auth states are both clean.
- No navigation behavior changed.

### Definition of Done

- Header visually checked.
- Mobile checked.
- Коммит: `RG-602: Align header layout`

### Files likely touched

```txt
resources/views/layouts/navigation.blade.php
resources/views/components/layout/header.blade.php
resources/views/layouts/app.blade.php
resources/views/livewire/notifications/notification-bell.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-603 — Align Upload Button Style

**Area:** UI / Upload / Button  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-603-align-upload-button-style`  
**Base branch:** develop
**Depends on:** RG-602

### Goal

Привести upload button к prototype primary CTA style.

### TDD step

No direct unit test — visual polish task.

Optional markup test:

```php
it('renders upload button with stable test id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertSee('data-testid="open-upload-button"', false);
});
```

### Implementation

Проверить upload entry points:

```txt
header upload button
feed upload button if exists
empty state upload button
mobile upload button
```

Style:

```txt
- accent purple primary background;
- strong hover state;
- focus-visible ring;
- disabled/loading state if upload modal opening can be disabled;
- icon/text alignment;
- mobile compact variant if needed.
```

Use base `x-ui.button` variant:

```blade
<x-ui.button variant="primary" data-testid="open-upload-button">
    Upload
</x-ui.button>
```

Do not create separate one-off upload button CSS unless necessary.

### Verification

```bash
npm run build
```

Manual:

```txt
- guest should not see upload CTA if auth required
- authenticated user sees CTA
- mobile header
- upload modal opens as before
```

### Acceptance criteria

- Upload button visually matches primary CTA.
- Button uses accent purple consistently.
- Hover/focus/disabled/loading states exist or inherit from base button.
- Mobile version is not oversized.
- Upload modal behavior still works.
- No upload backend changed.

### Definition of Done

- Upload button style aligned.
- Behavior manually checked.
- Коммит: `RG-603: Align upload button style`

### Files likely touched

```txt
resources/views/components/ui/button.blade.php
resources/views/layouts/navigation.blade.php
resources/views/components/layout/header.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-604 — Align Vote Button States

**Area:** UI / Voting / Post Votes  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-604-align-vote-button-states`  
**Base branch:** develop
**Depends on:** RG-603

### Goal

Привести up/down vote buttons к единым states: normal, hover, active, focus, disabled, loading.

### TDD step

Partial Livewire/render tests are useful.

```php
it('renders post voting buttons with loading disabled attributes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(PostVoting::class, ['post' => $post])
        ->assertSee('wire:loading.attr="disabled"', false);
});
```

If current component API differs, adapt.

### Implementation

For `PostVoting`:

```txt
normal up/down:
- subtle border/surface;
- muted icon/text;

hover:
- brighter border/surface;

active upvote:
- accent/success depending prototype decision;
- selected indicator clear;

active downvote:
- danger/negative accent if prototype supports;
- otherwise muted destructive state;

focus:
- focus-visible ring;

disabled/loading:
- disabled attribute;
- opacity/cursor;
- no double-click spam.
```

Prefer base button variants or shared vote button partial.

Do not change voting action behavior.

### Verification

```bash
npm run build
composer test --filter=PostVoting
```

Manual:

```txt
- PostCard voting
- PostDrawer voting
- PostShow voting
- guest state
- authenticated state
- selected state
- loading click state
```

### Acceptance criteria

- Up/down buttons have consistent normal state.
- Active selected state is obvious.
- Hover state exists.
- Focus-visible state exists.
- Loading/disabled state prevents double interaction.
- Same component looks consistent in card/drawer/show.
- Tests pass.

### Definition of Done

- Relevant tests added/updated if practical.
- Vote button states aligned.
- Behavior unchanged.
- Коммит: `RG-604: Align vote button states`

### Files likely touched

```txt
resources/views/livewire/voting/post-voting.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/views/livewire/post/post-show.blade.php
resources/views/dev/ui-kit.blade.php
tests/Feature/Livewire/PostVotingTest.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-605 — Align Origin Vote Pills

**Area:** UI / Voting / Origin  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-605-align-origin-vote-pills`  
**Base branch:** develop
**Depends on:** RG-604

### Goal

Привести Homemade/Restaurant vote pills к prototype style.

### TDD step

Partial render test:

```php
it('renders origin voting pills', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(OriginVoting::class, ['post' => $post])
        ->assertSee('Homemade')
        ->assertSee('Restaurant');
});
```

Optional selected state markup test if possible.

### Implementation

Polish:

```txt
- pill radius;
- selected background/border;
- unselected background/border;
- hover state;
- focus-visible state;
- disabled/loading state;
- icon/emoji/text alignment if used;
- distribution bar alignment if nearby.
```

Selected state should use accent system consistently:

```txt
selected: bg-rg-accent-soft + border-rg-accent + text-rg-text
```

Do not change origin voting semantics.

### Verification

```bash
npm run build
composer test --filter=OriginVoting
```

Manual:

```txt
- origin pills in PostCard if present
- origin panel in drawer
- origin panel in post show
- selected state
- loading state
- mobile wrapping
```

### Acceptance criteria

- Homemade/Restaurant pills match prototype.
- Active state is obvious.
- Pills do not overflow on mobile.
- Distribution bar remains aligned.
- Loading/disabled states exist.
- Behavior unchanged.

### Definition of Done

- Origin vote UI polished.
- Relevant tests pass.
- Коммит: `RG-605: Align origin vote pills`

### Files likely touched

```txt
resources/views/livewire/voting/origin-voting.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/views/livewire/post/post-show.blade.php
resources/views/dev/ui-kit.blade.php
tests/Feature/Livewire/OriginVotingTest.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-606 — Align Cuisine Vote Chips

**Area:** UI / Voting / Cuisine  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-606-align-cuisine-vote-chips`  
**Base branch:** develop
**Depends on:** RG-605

### Goal

Привести cuisine vote chips к prototype style.

### TDD step

Partial render test:

```php
it('renders cuisine voting chips', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    Livewire::actingAs($user)
        ->test(CuisineVoting::class, ['post' => $post])
        ->assertSee('Italian')
        ->assertSee('Asian')
        ->assertSee('American')
        ->assertSee('Mexican')
        ->assertSee('Other');
});
```

### Implementation

Polish:

```txt
- chip spacing;
- chip radius;
- selected state;
- hover state;
- focus-visible state;
- disabled/loading state;
- wrapping behavior on mobile;
- density in drawer/post show.
```

Cuisine chips likely need smaller density than origin pills:

```txt
- compact padding;
- rounded-full or rounded-xl;
- consistent min-height;
```

Do not change cuisine vote list or voting semantics.

### Verification

```bash
npm run build
composer test --filter=CuisineVoting
```

Manual:

```txt
- drawer cuisine panel
- post show cuisine panel
- selected state
- mobile wrapping
- many chips layout
```

### Acceptance criteria

- Cuisine chips match prototype visual language.
- Selected chip is obvious.
- Chips wrap cleanly on mobile.
- Hover/focus/disabled/loading states exist.
- Distribution panel remains readable.
- Behavior unchanged.

### Definition of Done

- Cuisine vote UI polished.
- Relevant tests pass.
- Коммит: `RG-606: Align cuisine vote chips`

### Files likely touched

```txt
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/views/livewire/post/post-show.blade.php
resources/views/dev/ui-kit.blade.php
tests/Feature/Livewire/CuisineVotingTest.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-607 — Align Drawer Width Desktop

**Area:** UI / Drawer / Desktop  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-607-align-drawer-width-desktop`  
**Base branch:** develop
**Depends on:** RG-606

### Goal

Привести desktop drawer width к prototype/design contract.

### TDD step

No direct unit test — visual polish task.

Optional markup test:

```php
it('renders drawer shell with desktop width class', function () {
    $view = Blade::render('<x-ui.drawer-shell open>Drawer</x-ui.drawer-shell>');

    expect($view)->toContain('max-w');
});
```

### Implementation

Check:

```txt
x-ui.drawer-shell
PostDrawer
drawer overlay layout
large image area inside drawer
comments/votes spacing inside drawer
```

Recommended desktop behavior:

```txt
- right side drawer;
- width around 480–640px depending prototype;
- full height;
- content scroll inside drawer;
- backdrop covers page;
- drawer does not exceed viewport;
```

Tailwind example:

```txt
w-full sm:max-w-xl lg:max-w-2xl
```

If prototype drawer is narrower, use:

```txt
sm:max-w-lg
```

Do not change mobile drawer behavior here unless unavoidable.

### Verification

```bash
npm run build
```

Manual:

```txt
- desktop 1280px
- desktop 1440px
- long content scroll
- comments inside drawer
- close button location
```

### Acceptance criteria

- Desktop drawer width matches prototype.
- Drawer does not feel too narrow/wide.
- Content scroll works inside drawer.
- Image and voting panels fit cleanly.
- Backdrop still covers page.
- Mobile behavior not broken.

### Definition of Done

- Drawer checked on desktop and mobile.
- Коммит: `RG-607: Align drawer width desktop`

### Files likely touched

```txt
resources/views/components/ui/drawer-shell.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/views/dev/ui-kit.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-608 — Align Drawer Animation

**Area:** UI / Drawer / Motion  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-608-align-drawer-animation`  
**Base branch:** develop
**Depends on:** RG-607

### Goal

Привести drawer open/close animation к prototype-quality motion.

### TDD step

No direct unit test — visual/motion polish task.

Optional markup test:

```php
it('renders drawer with transition classes', function () {
    $view = Blade::render('<x-ui.drawer-shell open>Drawer</x-ui.drawer-shell>');

    expect($view)->toContain('transition');
});
```

### Implementation

Use Alpine transition classes:

```blade
x-transition:enter="transform transition ease-out duration-200"
x-transition:enter-start="translate-x-full opacity-0"
x-transition:enter-end="translate-x-0 opacity-100"
x-transition:leave="transform transition ease-in duration-150"
x-transition:leave-start="translate-x-0 opacity-100"
x-transition:leave-end="translate-x-full opacity-0"
```

Backdrop transition:

```txt
opacity transition 150–200ms
```

Rules:

```txt
- no slow 500ms drawer;
- no bouncy overshoot;
- closing should feel responsive;
- respect reduced motion if easy.
```

Optional:

```css
@media (prefers-reduced-motion: reduce) {
    .motion-safe\:transition-none { ... }
}
```

Tailwind has `motion-safe:` / `motion-reduce:` utilities.

### Verification

```bash
npm run build
```

Manual:

```txt
- open drawer from card
- close by button
- close by escape
- close by backdrop if supported
- mobile drawer behavior
```

### Acceptance criteria

- Drawer opens smoothly.
- Drawer closes quickly.
- Backdrop fades consistently.
- Animation does not lag.
- Escape/close behavior still works.
- Reduced motion not made worse.
- No Livewire behavior broken.

### Definition of Done

- Motion manually checked.
- Коммит: `RG-608: Align drawer animation`

### Files likely touched

```txt
resources/views/components/ui/drawer-shell.blade.php
resources/views/livewire/post/post-drawer.blade.php
resources/css/app.css
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-609 — Align Modal Backdrop

**Area:** UI / Modal / Backdrop  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-609-align-modal-backdrop`  
**Base branch:** develop
**Depends on:** RG-608

### Goal

Привести modal backdrop к prototype style для upload/report/confirm modals.

### TDD step

No direct unit test — visual polish task.

Optional component test:

```php
it('renders modal shell with backdrop test id', function () {
    $view = Blade::render('<x-ui.modal-shell open>Modal</x-ui.modal-shell>');

    expect($view)->toContain('data-testid="modal-backdrop"');
});
```

### Implementation

Update `x-ui.modal-shell` backdrop:

```txt
- dark overlay;
- subtle blur if prototype uses it;
- consistent opacity;
- smooth fade transition;
- z-index above app/header but below modal content.
```

Recommended:

```txt
fixed inset-0 bg-black/70 backdrop-blur-sm
```

But don't over-blur if prototype is simpler.

Apply to:

```txt
upload modal
report modal
hide confirmation modal
any confirmation modals
```

Do not create separate backdrop styles per modal.

### Verification

```bash
npm run build
```

Manual:

```txt
- upload modal
- report modal
- hide confirmation modal
- mobile modal
- close behavior
```

### Acceptance criteria

- Backdrop matches prototype.
- Backdrop opacity consistent across modals.
- Modal content remains readable.
- Header/feed behind modal is visually de-emphasized.
- Modal transitions still work.
- No z-index bugs.

### Definition of Done

- All modals checked.
- UI kit modal sample updated.
- Коммит: `RG-609: Align modal backdrop`

### Files likely touched

```txt
resources/views/components/ui/modal-shell.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
resources/views/livewire/reports/report-modal.blade.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
resources/views/dev/ui-kit.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-610 — Align Mobile Card Layout

**Area:** UI / Mobile / PostCard  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-610-align-mobile-card-layout`  
**Base branch:** develop
**Depends on:** RG-609

### Goal

Привести mobile PostCard layout к prototype/mobile design.

### TDD step

No direct unit test — visual polish task.

Optional render test:

```php
it('renders post card mobile-safe structure', function () {
    $post = Post::factory()->published()->create();

    $view = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($view)->toContain('data-testid="post-card"');
});
```

### Implementation

Check mobile:

```txt
- image aspect ratio;
- title line clamp;
- author row;
- stats row;
- vote buttons row;
- origin/cuisine controls if present;
- card padding;
- card gap;
- overflow behavior.
```

Recommended:

```txt
image full width, stable aspect ratio
content padding 4
controls wrap below content
no horizontal scrolling
tap targets >= 40px where practical
```

Avoid:

```txt
- tiny vote buttons;
- cramped tags;
- title overlay unreadable;
- controls overflowing.
```

### Verification

```bash
npm run build
```

Manual:

```txt
- 375px mobile feed
- 390px iPhone-ish
- long title
- no image/placeholder
- logged out/logged in
```

### Acceptance criteria

- PostCard fits 375px width without horizontal scroll.
- Image ratio stable.
- Title/metadata readable.
- Voting controls usable.
- Tags/chips wrap cleanly.
- Empty/loading card states still align.
- Desktop not broken.

### Definition of Done

- Mobile manually checked.
- Коммит: `RG-610: Align mobile card layout`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/components/ui/image-placeholder.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-611 — Align Desktop Two-Column Layout

**Area:** UI / Desktop / Layout  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-611-align-desktop-two-column-layout`  
**Base branch:** develop
**Depends on:** RG-610

### Goal

Привести desktop two-column layout к прототипу там, где он нужен.

### TDD step

No direct unit test — visual polish task.

Optional smoke test:

```php
it('renders desktop layout container markers on feed', function () {
    $this->get(route('feed'))
        ->assertOk()
        ->assertSee('data-testid="feed-layout"', false);
});
```

### Implementation

Potential two-column areas:

```txt
feed page:
- main feed column
- side column for upload CTA/trending/tags/profile summary if already exists

post show:
- main post content
- side panel for voting/share/metadata/related placeholder

profile:
- header full width
- posts grid below
```

Important:

```txt
Do not invent new sidebar content.
```

If sidebar exists, align it.  
If no sidebar exists, two-column means:

```txt
post show content + side voting/share panel
```

Use responsive classes:

```txt
grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-6
```

or prototype equivalent.

### Verification

```bash
npm run build
```

Manual:

```txt
- feed desktop 1280px
- post show desktop
- drawer should remain independent
- mobile remains single column
```

### Acceptance criteria

- Desktop layout uses clean two-column structure where intended.
- Side column does not collapse badly.
- Main column max width readable.
- Mobile remains single column.
- No new content added just to fill sidebar.
- Existing interactions still work.

### Definition of Done

- Desktop and mobile checked.
- Коммит: `RG-611: Align desktop two-column layout`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/livewire/post/post-show.blade.php
resources/views/livewire/profile/profile-page.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-612 — Add Hover States

**Area:** UI / Interaction States  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-612-add-hover-states`  
**Base branch:** develop
**Depends on:** RG-611

### Goal

Добавить/нормализовать hover states для интерактивных элементов.

### TDD step

Partial component markup tests:

```php
it('renders ui button with hover classes', function () {
    $view = Blade::render('<x-ui.button>Click</x-ui.button>');

    expect($view)->toContain('hover:');
});
```

No need to test every component.

### Implementation

Add hover states to:

```txt
buttons
links
PostCard clickable area
vote buttons
origin pills
cuisine chips
dropdown items
notification items
report/menu items
modal/drawer close buttons
admin inline moderation buttons
```

Rules:

```txt
- hover should be subtle;
- hover should not look like active selected;
- destructive hover should remain danger-coded;
- disabled elements should not have misleading hover.
```

Use `motion-safe:transition-colors` or shared transition classes.

### Verification

```bash
npm run build
composer test --filter=Button
```

Manual:

```txt
- desktop hover through key controls
- no hover-only information
- disabled hover does not look clickable
```

### Acceptance criteria

- Primary clickable elements have hover states.
- Hover states are consistent with accent/surface tokens.
- Disabled elements do not look active on hover.
- Hover does not break selected states.
- UI kit shows hoverable components.
- Tests/build pass.

### Definition of Done

- Hover states added.
- UI kit checked.
- Коммит: `RG-612: Add hover states`

### Files likely touched

```txt
resources/views/components/ui/button.blade.php
resources/views/components/ui/dropdown-shell.blade.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/livewire/notifications/notification-bell.blade.php
resources/views/dev/ui-kit.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-613 — Add Focus States

**Area:** UI / Accessibility / Focus  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-613-add-focus-states`  
**Base branch:** develop
**Depends on:** RG-612

### Goal

Добавить consistent focus-visible states для keyboard navigation.

### TDD step

Component markup test:

```php
it('renders ui button with focus visible styles', function () {
    $view = Blade::render('<x-ui.button>Click</x-ui.button>');

    expect($view)->toContain('focus-visible:');
});
```

Input focus test:

```php
it('renders ui input with focus visible styles', function () {
    $view = Blade::render('<x-ui.input name="title" />');

    expect($view)->toContain('focus');
});
```

### Implementation

Add focus-visible states to:

```txt
buttons
inputs
textarea
select/listbox if exists
links
vote buttons
origin pills
cuisine chips
dropdown items
modal close buttons
drawer close buttons
notification actions
```

Recommended:

```txt
focus-visible:outline-none
focus-visible:ring-2
focus-visible:ring-rg-accent
focus-visible:ring-offset-2
focus-visible:ring-offset-rg-bg
```

For cards/links:

```txt
focus-visible:ring on container
```

Do not remove native focus without replacement.

### Verification

```bash
npm run build
composer test --filter=Focus
```

Manual keyboard:

```txt
Tab through header
Tab through upload modal
Tab through vote controls
Tab through report modal
Tab through drawer close button
```

### Acceptance criteria

- Keyboard focus is visible.
- Focus color uses accent token.
- Focus ring works on dark background.
- Focus is not clipped by overflow/rounded containers.
- Inputs and buttons are covered.
- Tests/build pass.

### Definition of Done

- Focus states added.
- Keyboard manual check done.
- Коммит: `RG-613: Add focus states`

### Files likely touched

```txt
resources/views/components/ui/button.blade.php
resources/views/components/ui/input.blade.php
resources/views/components/ui/textarea.blade.php
resources/views/components/ui/dropdown-shell.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/components/ui/modal-shell.blade.php
resources/views/components/ui/drawer-shell.blade.php
resources/views/dev/ui-kit.blade.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-614 — Add Disabled States

**Area:** UI / Interaction States  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-614-add-disabled-states`  
**Base branch:** develop
**Depends on:** RG-613

### Goal

Добавить consistent disabled states для buttons/forms/voting controls.

### TDD step

Component markup tests:

```php
it('renders ui button disabled styles', function () {
    $view = Blade::render('<x-ui.button disabled>Disabled</x-ui.button>');

    expect($view)->toContain('disabled:');
    expect($view)->toContain('disabled');
});
```

Livewire loading disabled test for submit buttons if practical:

```php
it('upload submit button disables while saving', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->assertSee('wire:loading.attr="disabled"', false);
});
```

### Implementation

Normalize disabled states:

```txt
x-ui.button:
- disabled:opacity-50
- disabled:cursor-not-allowed
- disabled:pointer-events-none if safe

inputs:
- disabled:bg-rg-surface-muted
- disabled:text-rg-muted
- disabled:cursor-not-allowed

vote buttons:
- disabled state during loading or unauthenticated if rendered disabled
```

Add to:

```txt
upload submit
comment submit
report submit
vote buttons
origin/cuisine buttons
mark as read button
moderation buttons
```

Do not fake disabled with only CSS if action can still fire. Use actual `disabled` or Livewire loading attr.

### Verification

```bash
npm run build
composer test --filter=disabled
```

Manual:

```txt
- upload form loading
- comment submit loading
- report submit loading
- vote loading
- disabled placeholder buttons
```

### Acceptance criteria

- Disabled buttons look disabled.
- Disabled buttons cannot be clicked.
- Loading submit buttons disable correctly.
- Disabled inputs look non-editable.
- Placeholder buttons from earlier phases look intentionally disabled.
- Tests/build pass.

### Definition of Done

- Disabled states added.
- Relevant Livewire controls checked.
- Коммит: `RG-614: Add disabled states`

### Files likely touched

```txt
resources/views/components/ui/button.blade.php
resources/views/components/ui/input.blade.php
resources/views/components/ui/textarea.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
resources/views/livewire/comments/comment-form.blade.php
resources/views/livewire/reports/report-modal.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/livewire/profile/profile-page.blade.php
resources/views/dev/ui-kit.blade.php
tests/Feature/Livewire/UploadPostFormTest.php
docs/design/phase-37-ui-polish-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-615 — Add Loading Transitions

**Area:** UI / Loading / Motion  
**Type:** Visual Polish  
**Priority:** P0  
**Branch:** `feature/RG-615-add-loading-transitions`  
**Base branch:** develop
**Depends on:** RG-614

### Goal

Добавить smooth loading transitions для ключевых Livewire interactions.

### TDD step

Partial render tests:

```php
it('renders loading state in upload form', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->assertSee('wire:loading', false);
});
```

PostFeed loading skeleton test if exists:

```php
it('renders post feed loading skeleton marker', function () {
    Livewire::test(PostFeed::class)
        ->assertSee('data-testid="post-feed-loading"', false);
});
```

### Implementation

Add/normalize loading transitions for:

```txt
PostFeed loading skeleton
UploadPostForm submit
CommentForm submit
ReportModal submit
PostVoting click
OriginVoting click
CuisineVoting click
NotificationBell mark as read
Inline moderation actions
```

Use Livewire:

```blade
wire:loading
wire:target="submit"
wire:loading.attr="disabled"
wire:loading.class="opacity-60"
```

Use CSS transitions:

```txt
transition-opacity
duration-150/200
motion-safe
```

Skeleton:

```txt
pulse subtle, not aggressive
```

Do not introduce new spinners everywhere if skeletons exist. Use consistent loader language.

Add final review doc:

```txt
docs/design/phase-37-ui-polish-review.md
```

with completed checklist.

### Verification

```bash
npm run build
composer test
```

Manual:

```txt
- feed loading
- upload submit
- comment submit
- report submit
- vote click
- drawer open/close
- modal open/close
```

### Acceptance criteria

- Key Livewire actions have visible loading feedback.
- Submit buttons disable during loading.
- Loading transitions are subtle and consistent.
- Skeletons do not cause layout jumps.
- No double-submit obvious path remains in polished UI.
- Final Phase 37 review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Loading states/transitions added.
- Review doc completed.
- Tests/build pass.
- Коммит: `RG-615: Add loading transitions`

### Files likely touched

```txt
resources/views/components/ui/loading-skeleton.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/livewire/upload/upload-post-form.blade.php
resources/views/livewire/comments/comment-form.blade.php
resources/views/livewire/reports/report-modal.blade.php
resources/views/livewire/voting/post-voting.blade.php
resources/views/livewire/voting/origin-voting.blade.php
resources/views/livewire/voting/cuisine-voting.blade.php
resources/views/livewire/notifications/notification-bell.blade.php
resources/views/livewire/moderation/inline-post-moderation.blade.php
docs/design/phase-37-ui-polish-review.md
tests/Feature/Livewire/*Test.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 11. Phase 37 Completion Criteria

Phase 37 завершена, когда:

```txt
- RG-598–RG-615 выполнены;
- feed spacing matches prototype;
- card radius normalized;
- dark background matches prototype/design contract;
- accent purple usage normalized;
- header layout aligned;
- upload button styled as primary CTA;
- vote button states complete;
- origin vote pills aligned;
- cuisine vote chips aligned;
- desktop drawer width aligned;
- drawer animation polished;
- modal backdrop aligned;
- mobile card layout clean;
- desktop two-column layout clean;
- hover states exist;
- focus states exist;
- disabled states exist;
- loading transitions exist;
- /dev/ui-kit reflects updated components;
- docs/design/phase-37-ui-polish-review.md exists;
- no browser smoke tests added;
- no screenshot baselines added;
- no new business features added;
- composer test passes;
- npm run build passes.
```
---

# 12. Что нельзя делать в Phase 37

Без отдельной задачи нельзя:

```txt
- добавлять browser smoke tests;
- добавлять Playwright/Cypress/Dusk infrastructure;
- добавлять screenshot baseline files;
- добавлять visual regression commands;
- менять backend actions;
- менять database schema;
- добавлять новые statuses;
- менять seed data;
- добавлять new public pages;
- добавлять new admin resources;
- добавлять API endpoints;
- добавлять SEO/OpenGraph changes;
- добавлять React/Vue/Inertia.
```
---

# 13. Recommended Execution Order

```txt
RG-598 Align feed spacing with prototype
RG-599 Align card radius with prototype
RG-600 Align dark background with prototype
RG-601 Align accent purple usage
RG-602 Align header layout
RG-603 Align upload button style
RG-604 Align vote button states
RG-605 Align origin vote pills
RG-606 Align cuisine vote chips
RG-607 Align drawer width desktop
RG-608 Align drawer animation
RG-609 Align modal backdrop
RG-610 Align mobile card layout
RG-611 Align desktop two-column layout
RG-612 Add hover states
RG-613 Add focus states
RG-614 Add disabled states
RG-615 Add loading transitions
```
---

# 14. Release

После завершения Phase 37:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh --seed

git checkout -b release/v0.2.18-phase37-ui-polish-pass
git push -u origin release/v0.2.18-phase37-ui-polish-pass
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.18-phase37-ui-polish-pass -m "RateGuru Phase 37 UI Polish Pass"
git push origin v0.2.18-phase37-ui-polish-pass
```
