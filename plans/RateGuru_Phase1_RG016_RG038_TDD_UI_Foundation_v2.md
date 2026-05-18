# RateGuru — Phase 1 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 1 — Design Contract & UI Foundation**  
Диапазон задач: **RG-016 → RG-038**  
Основа нумерации: исходный atomic backlog, где Phase 1 начинается с задачи 016 и заканчивается задачей 038.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 1 начинается не с `RG-027`.

Правильный диапазон Phase 1:

```txt
RG-016 — Extract prototype color tokens into CSS variables
RG-017 — Add Tailwind theme mapping for RateGuru colors
RG-018 — Create base UI button component
RG-019 — Create base UI card component
RG-020 — Create base UI badge component
RG-021 — Create base UI input component
RG-022 — Create base UI textarea component
RG-023 — Create base UI modal shell component
RG-024 — Create base UI drawer shell component
RG-025 — Create base UI dropdown shell component
RG-026 — Create base avatar component
RG-027 — Create base image placeholder component
RG-028 — Create base empty state component
RG-029 — Create base loading skeleton component
RG-030 — Create base error message component
RG-031 — Create dev-only UI kit route
RG-032 — Render buttons in UI kit
RG-033 — Render cards in UI kit
RG-034 — Render modal shell in UI kit
RG-035 — Render drawer shell in UI kit
RG-036 — Render form controls in UI kit
RG-037 — Add UI design checklist document
RG-038 — Add screenshot baseline folder structure
```

Префикс `RG-` используется вместо `PLR-`, потому что проект теперь называется **RateGuru**.  
Но номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 1

Phase 1 создаёт UI-фундамент RateGuru.

В эту фазу входит:

```txt
- перенос визуальных принципов из исходного PlateRate-прототипа;
- сохранение оригинального HTML-прототипа как design reference;
- извлечение цветовых токенов;
- создание CSS variables;
- Tailwind mapping;
- базовые Blade UI components;
- dev-only UI Kit;
- документация UI checklist;
- структура screenshot baselines.
```

В эту фазу НЕ входит:

```txt
- реальные модели Post/Comment/Vote;
- настоящая лента;
- upload backend;
- голосования;
- комментарии;
- репорты;
- модерация;
- Filament resources;
- React/Vue/Inertia;
- Redis;
- PostgreSQL;
- Cloudinary.
```

---

# 3. Ключевой риск Phase 1

Фраза “сделай как в исходном шаблоне” не работает, если агент не видит шаблон.

Поэтому в Phase 1 обязательно фиксируем визуальный источник истины:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
```

Агент перед любой UI-задачей обязан смотреть:

```txt
1. docs/design/reference/original/PlateRate.html
2. docs/design/reference/screenshots/*
3. docs/design/design-contract.md
4. docs/design/ui-review-checklist.md
5. /dev/ui-kit
```

Если исходный `PlateRate.html` ещё не добавлен в репозиторий, задача `RG-016` должна добавить его как reference artifact.

---

# 4. GitFlow для Phase 1

## Base branch

Все задачи Phase 1 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-016-extract-prototype-color-tokens
feature/RG-017-add-tailwind-theme-mapping
feature/RG-018-create-base-ui-button-component
```

## Commit format

```txt
RG-016: Extract prototype color tokens into CSS variables
RG-017: Add Tailwind theme mapping for RateGuru colors
RG-018: Create base UI button component
```

## Release branch

После выполнения `RG-016`–`RG-038`:

```txt
release/v0.0.2-phase1-ui-foundation
```

## Tag

После merge release branch в `main`:

```txt
v0.0.2-phase1-ui-foundation
```

---

# 5. Общие правила TDD для Phase 1

## Для CSS/config/docs задач

Если прямой тест бессмысленен:

```txt
TDD step: No direct test — configuration/documentation task.
```

Acceptance проверяется через:

```txt
- npm run build;
- composer test;
- наличие файлов;
- наличие ожидаемых CSS variables/classes;
- ручная проверка /dev/ui-kit.
```

## Для Blade-компонентов

Пишем render tests:

```txt
- component renders slot;
- component renders props;
- component renders expected classes/attributes;
- disabled/error states are visible in markup.
```

## Для UI Kit

Пишем feature tests:

```txt
- /dev/ui-kit доступен в local/testing;
- /dev/ui-kit недоступен в production-like environment;
- секции UI Kit содержат ожидаемые компоненты.
```

---

# 6. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Docs / Tests / Infra
Type: Test / Feature / Refactor / Config / Docs
Priority: P0 / P1 / P2
Branch: feature/RG-XXX-kebab-title
Depends on: RG-...

Goal:
Что должно появиться.

TDD step:
Какой тест пишем первым. Если тест напрямую невозможен:
No direct test — причина.

Implementation:
Что именно делаем.

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
- UI-компонент добавлен в UI Kit, если применимо
- Код отформатирован
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 7. Phase 1 Atomic Tasks

---

## RG-016 — Extract Prototype Color Tokens Into CSS Variables

**Area:** UI / Docs  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-016-extract-prototype-color-tokens`  
**Depends on task:** Phase 0 completed

### Goal

Добавить исходный прототип как visual reference и извлечь из него базовые цветовые токены RateGuru в CSS variables.

Эта задача критична: без неё агент будет делать “примерно тёмный UI”, а не UI, похожий на исходный макет.

### TDD step

No direct test — это CSS/reference foundation task.

Проверка:

```bash
npm run build
```

И вручную:

```txt
- reference HTML лежит в docs/design/reference/original/
- theme.css содержит основные токены
- app.css импортирует theme.css
```

### Implementation

Создать структуру:

```txt
docs/design/reference/original/
docs/design/reference/screenshots/
docs/design/
resources/css/theme.css
```

Добавить исходный HTML-прототип:

```txt
docs/design/reference/original/PlateRate.html
```

Если оригинальный файл уже есть локально, скопировать его в эту директорию.  
Если он не доступен в репозитории, получить его из исходного артефакта задачи и сохранить без изменений.

Создать:

```txt
resources/css/theme.css
```

Добавить CSS variables:

```css
:root {
    --rg-bg: #0a0a0f;
    --rg-surface: #12121a;
    --rg-surface-2: #181824;
    --rg-surface-3: #1f1f2e;

    --rg-border: rgba(255, 255, 255, 0.08);
    --rg-border-strong: rgba(255, 255, 255, 0.14);

    --rg-text: #f4f4f5;
    --rg-text-muted: #a1a1aa;
    --rg-text-soft: #71717a;

    --rg-accent: #a855f7;
    --rg-accent-hover: #9333ea;
    --rg-accent-soft: rgba(168, 85, 247, 0.14);

    --rg-danger: #ef4444;
    --rg-success: #22c55e;
    --rg-warning: #f59e0b;

    --rg-radius-card: 1.25rem;
    --rg-radius-control: 0.875rem;
    --rg-radius-pill: 9999px;

    --rg-shadow-card: 0 18px 60px rgba(0, 0, 0, 0.35);
}
```

Подключить `theme.css` в `resources/css/app.css`:

```css
@import './theme.css';
```

Создать начальный документ:

```txt
docs/design/design-contract.md
```

В нём зафиксировать:

```txt
- original source: docs/design/reference/original/PlateRate.html
- dark-first direction
- purple accent
- rounded cards
- card-based feed
- modal upload pattern
- right drawer detail pattern
```

### Acceptance criteria

- `docs/design/reference/original/PlateRate.html` существует.
- `resources/css/theme.css` существует.
- `resources/css/app.css` импортирует `theme.css`.
- CSS variables имеют префикс `--rg-`.
- Accent purple сохранён как основной акцент.
- Design contract ссылается на оригинальный HTML-прототип.
- `npm run build` проходит.

### Definition of Done

- Reference HTML добавлен.
- Theme tokens добавлены.
- Design contract создан.
- Build проходит.
- Коммит: `RG-016: Extract prototype color tokens into CSS variables`

### Files likely touched

```txt
docs/design/reference/original/PlateRate.html
docs/design/design-contract.md
resources/css/theme.css
resources/css/app.css
```

---

## RG-017 — Add Tailwind Theme Mapping For RateGuru Colors

**Area:** UI  
**Type:** Config  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-017-add-tailwind-theme-mapping`  
**Depends on task:** RG-016

### Goal

Добавить Tailwind mapping для CSS variables RateGuru, чтобы UI-компоненты использовали единые токены, а не случайные hex-цвета.

### TDD step

No direct test — Tailwind config task.

Проверка:

```bash
npm run build
```

### Implementation

Обновить Tailwind config:

```txt
tailwind.config.js
```

или актуальный config-файл проекта.

Добавить mapping:

```js
theme: {
  extend: {
    colors: {
      rg: {
        bg: 'var(--rg-bg)',
        surface: 'var(--rg-surface)',
        surface2: 'var(--rg-surface-2)',
        surface3: 'var(--rg-surface-3)',
        border: 'var(--rg-border)',
        borderStrong: 'var(--rg-border-strong)',
        text: 'var(--rg-text)',
        muted: 'var(--rg-text-muted)',
        soft: 'var(--rg-text-soft)',
        accent: 'var(--rg-accent)',
        accentHover: 'var(--rg-accent-hover)',
        accentSoft: 'var(--rg-accent-soft)',
        danger: 'var(--rg-danger)',
        success: 'var(--rg-success)',
        warning: 'var(--rg-warning)',
      },
    },
    borderRadius: {
      rgCard: 'var(--rg-radius-card)',
      rgControl: 'var(--rg-radius-control)',
      rgPill: 'var(--rg-radius-pill)',
    },
    boxShadow: {
      rgCard: 'var(--rg-shadow-card)',
    },
  },
}
```

### Acceptance criteria

- Tailwind config содержит `rg.*` colors.
- Tailwind config содержит RateGuru radius tokens.
- Tailwind config содержит `shadow-rgCard`.
- Можно использовать классы:
  - `bg-rg-bg`
  - `bg-rg-surface`
  - `text-rg-text`
  - `text-rg-muted`
  - `border-rg-border`
  - `text-rg-accent`
  - `rounded-rgCard`
- `npm run build` проходит.

### Definition of Done

- Tailwind mapping добавлен.
- Build проходит.
- Коммит: `RG-017: Add Tailwind theme mapping for RateGuru colors`

### Files likely touched

```txt
tailwind.config.js
tailwind.config.cjs
```

---

## RG-018 — Create Base UI Button Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-018-create-base-ui-button-component` 
**Depends on task:** RG-017

### Goal

Создать базовый Blade-компонент кнопки для RateGuru.

### TDD step

Сначала написать render-test:

```php
it('renders a UI button with slot content', function () {
    $html = Blade::render('<x-ui.button>Upload</x-ui.button>');

    expect($html)->toContain('Upload');
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать:

```txt
resources/views/components/ui/button.blade.php
```

Поддержать props:

```txt
variant: primary / secondary / ghost / danger
size: sm / md / lg
type: button / submit
disabled: bool
fullWidth: bool
```

Пример API:

```blade
<x-ui.button variant="primary" size="md">
    Upload
</x-ui.button>
```

Компонент должен использовать Tailwind tokens:

```txt
bg-rg-accent
bg-rg-surface
border-rg-border
text-rg-text
rounded-rgControl
```

### Acceptance criteria

- Компонент рендерит slot.
- Есть варианты `primary`, `secondary`, `ghost`, `danger`.
- Есть размеры `sm`, `md`, `lg`.
- `disabled` добавляет disabled attribute и disabled styling.
- `fullWidth` добавляет `w-full`.
- Есть focus-visible state.
- Компонент не содержит продуктовой логики.

### Definition of Done

- Render-test написан первым.
- Тест падает до реализации.
- Компонент реализован.
- Тест проходит.
- `npm run build` проходит.
- Коммит: `RG-018: Create base UI button component`

### Files likely touched

```txt
resources/views/components/ui/button.blade.php
tests/Feature/ViewComponents/ButtonComponentTest.php
```

---

## RG-019 — Create Base UI Card Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-019-create-base-ui-card-component` should be created from develop branch
**Depends on task:** RG-017

### Goal

Создать базовый Blade-компонент карточки для будущих post cards, panels и UI Kit sections.

### TDD step

Render-test:

```php
it('renders a UI card with slot content', function () {
    $html = Blade::render('<x-ui.card>Card content</x-ui.card>');

    expect($html)->toContain('Card content');
});
```

### Implementation

Создать:

```txt
resources/views/components/ui/card.blade.php
```

Props:

```txt
variant: default / elevated / interactive
padding: none / sm / md / lg
```

Пример:

```blade
<x-ui.card variant="interactive" padding="md">
    Content
</x-ui.card>
```

### Acceptance criteria

- Компонент рендерит slot.
- `default` variant использует dark surface, border, rounded card.
- `elevated` variant добавляет shadow.
- `interactive` variant добавляет hover state.
- Padding управляется prop.
- Компонент визуально соответствует rounded dark card из исходного прототипа.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-019: Create base UI card component`

### Files likely touched

```txt
resources/views/components/ui/card.blade.php
tests/Feature/ViewComponents/CardComponentTest.php
```

---

## RG-020 — Create Base UI Badge Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-020-create-base-ui-badge-component`   should be created from develop branch
**Depends on task:** RG-017

### Goal

Создать компонент badge/chip для тегов, статусов, cuisine labels и compact metadata.

### TDD step

Render-test:

```php
it('renders a UI badge with slot content', function () {
    $html = Blade::render('<x-ui.badge>Italian</x-ui.badge>');

    expect($html)->toContain('Italian');
});
```

### Implementation

Создать:

```txt
resources/views/components/ui/badge.blade.php
```

Props:

```txt
variant: neutral / accent / success / warning / danger
size: sm / md
```

### Acceptance criteria

- Badge рендерит slot.
- Есть `neutral`, `accent`, `success`, `warning`, `danger`.
- Есть `sm`, `md`.
- Badge использует pill style.
- Badge читается на тёмном фоне.
- Компонент не содержит продуктовой логики.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-020: Create base UI badge component`

### Files likely touched

```txt
resources/views/components/ui/badge.blade.php
tests/Feature/ViewComponents/BadgeComponentTest.php
```

---

## RG-021 — Create Base UI Input Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-021-create-base-ui-input-component`  
**Depends on task:** RG-017

### Goal

Создать базовый input-компонент для форм RateGuru.

### TDD step

Render-test проверяет `name`, `placeholder`, `value`.

### Implementation

Создать:

```txt
resources/views/components/ui/input.blade.php
```

Props:

```txt
name
type
value
placeholder
disabled
error
```

Пример:

```blade
<x-ui.input name="title" placeholder="Dish title" />
```

### Acceptance criteria

- Input рендерит `name`.
- Input поддерживает `type`.
- Input поддерживает `placeholder`.
- Input поддерживает `value`.
- Input поддерживает disabled state.
- Input поддерживает error state.
- Focus-visible state есть.
- Стиль соответствует dark UI.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-021: Create base UI input component`

### Files likely touched

```txt
resources/views/components/ui/input.blade.php
tests/Feature/ViewComponents/InputComponentTest.php
```

---

## RG-022 — Create Base UI Textarea Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-022-create-base-ui-textarea-component`    should be created from develop branch 
**Depends on task:** RG-017

### Goal

Создать textarea-компонент для descriptions, comments, report messages.

### TDD step

Render-test проверяет `name`, `placeholder`, slot/value.

### Implementation

Создать:

```txt
resources/views/components/ui/textarea.blade.php
```

Props:

```txt
name
placeholder
rows
disabled
error
```

Пример:

```blade
<x-ui.textarea name="description" placeholder="Describe the dish" rows="4" />
```

### Acceptance criteria

- Textarea рендерит `name`.
- Textarea поддерживает `rows`.
- Textarea поддерживает placeholder.
- Textarea поддерживает disabled state.
- Textarea поддерживает error state.
- Focus-visible state есть.
- Стиль совпадает с input component.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-022: Create base UI textarea component`

### Files likely touched

```txt
resources/views/components/ui/textarea.blade.php
tests/Feature/ViewComponents/TextareaComponentTest.php
```

---

## RG-023 — Create Base UI Modal Shell Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-023-create-base-ui-modal-shell-component`    should be created from develop branch
**Depends on task:** RG-018, RG-019

### Goal

Создать generic modal shell для upload/report/confirm сценариев.

### TDD step

Render-test проверяет, что modal рендерит title и slot.

### Implementation

Создать:

```txt
resources/views/components/ui/modal.blade.php
```

Props:

```txt
title
size: sm / md / lg / xl
```

Slots:

```txt
default
footer
```

Компонент должен быть Alpine-compatible:

```blade
<div x-show="open">
    ...
</div>
```

Добавить accessibility attributes:

```txt
role="dialog"
aria-modal="true"
aria-labelledby
```

### Acceptance criteria

- Modal рендерит title.
- Modal рендерит content slot.
- Modal рендерит footer slot.
- Есть backdrop.
- Есть close button или close slot.
- Есть `role="dialog"`.
- Есть `aria-modal="true"`.
- Компонент не содержит Livewire/business logic.
- Стиль похож на исходный upload modal: dark surface, rounded panel, purple accent.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-023: Create base UI modal shell component`

### Files likely touched

```txt
resources/views/components/ui/modal.blade.php
tests/Feature/ViewComponents/ModalComponentTest.php
```

---

## RG-024 — Create Base UI Drawer Shell Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-024-create-base-ui-drawer-shell-component`    should be created from develop branch
**Depends on task:** RG-018, RG-019

### Goal

Создать shell-компонент правой drawer-панели для будущего detail view поста.

### TDD step

Render-test проверяет title и slot.

### Implementation

Создать:

```txt
resources/views/components/ui/drawer.blade.php
```

Props:

```txt
title
side: right / left
size: md / lg / xl
```

Slots:

```txt
default
footer
```

Структура:

```txt
backdrop
fixed panel
header
content slot
footer slot
```

Alpine-compatible:

```txt
x-show
@click.outside
escape key support может быть добавлен через Alpine expression/атрибуты
```

### Acceptance criteria

- Drawer рендерит title.
- Drawer рендерит content slot.
- Drawer поддерживает right side.
- Drawer имеет dark surface.
- Drawer имеет desktop right-side behavior.
- Drawer имеет mobile-safe behavior.
- Компонент не содержит продуктовой логики.
- Стиль соответствует исходному правому detail drawer.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-024: Create base UI drawer shell component`

### Files likely touched

```txt
resources/views/components/ui/drawer.blade.php
tests/Feature/ViewComponents/DrawerComponentTest.php
```

---

## RG-025 — Create Base UI Dropdown Shell Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-025-create-base-ui-dropdown-shell-component`    should be created from develop branch
**Depends on task:** RG-018, RG-019

### Goal

Создать dropdown shell для user menu, sort menu и post actions.

### TDD step

Render-test проверяет trigger/content slots.

### Implementation

Создать:

```txt
resources/views/components/ui/dropdown.blade.php
```

Slots:

```txt
trigger
content
```

Alpine-compatible:

```blade
<div x-data="{ open: false }">
```

### Acceptance criteria

- Dropdown рендерит trigger slot.
- Dropdown рендерит content slot.
- Dropdown использует Alpine local state.
- Dropdown имеет dark surface styling.
- Dropdown закрывается по outside click.
- Компонент не содержит Livewire/business logic.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-025: Create base UI dropdown shell component`

### Files likely touched

```txt
resources/views/components/ui/dropdown.blade.php
tests/Feature/ViewComponents/DropdownComponentTest.php
```

---

## RG-026 — Create Base Avatar Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-026-create-base-avatar-component`    should be created from develop branch
**Depends on task:** RG-018, RG-019
**Depends on task:** RG-017

### Goal

Создать avatar-компонент для будущих авторов постов, комментариев и меню пользователя.

### TDD step

Render-test проверяет fallback initials.

### Implementation

Создать:

```txt
resources/views/components/ui/avatar.blade.php
```

Props:

```txt
src
name
size: sm / md / lg
```

Behavior:

```txt
if src exists → render image
if no src → render initials/fallback
```

### Acceptance criteria

- Avatar рендерит image при наличии `src`.
- Avatar рендерит fallback при отсутствии `src`.
- Есть размеры `sm`, `md`, `lg`.
- Fallback читаем на dark background.
- Компонент не зависит от User model.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-026: Create base avatar component`

### Files likely touched

```txt
resources/views/components/ui/avatar.blade.php
tests/Feature/ViewComponents/AvatarComponentTest.php
```

---

## RG-027 — Create Base Image Placeholder Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-027-create-base-image-placeholder-component`    should be created from develop branch
**Depends on task:** RG-018, RG-019
**Depends on task:** RG-017

### Goal

Создать placeholder-компонент для изображений блюд, пока реальные images/post entities ещё не реализованы.

### TDD step

Render-test проверяет label и ratio class/attribute.

### Implementation

Создать:

```txt
resources/views/components/ui/image-placeholder.blade.php
```

Props:

```txt
label
ratio: square / video / portrait
```

Визуально: dark block, subtle radial/linear gradient, accent hint, без реального изображения.

### Acceptance criteria

- Placeholder рендерится.
- Есть ratio variants.
- Стиль подходит для карточек еды.
- Компонент визуально отсылает к исходному PlateRate placeholder/mood.
- Компонент можно показать в UI Kit.
- Не используются реальные изображения.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-027: Create base image placeholder component`

### Files likely touched

```txt
resources/views/components/ui/image-placeholder.blade.php
tests/Feature/ViewComponents/ImagePlaceholderComponentTest.php
```

---

## RG-028 — Create Base Empty State Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-028-create-base-empty-state-component`     should be created from develop branch
**Depends on task:** RG-018, RG-019

### Goal

Создать компонент пустого состояния для будущей ленты, комментариев, поиска и модерации.

### TDD step

Render-test проверяет title и description.

### Implementation

Создать:

```txt
resources/views/components/ui/empty-state.blade.php
```

Props:

```txt
title
description
```

Slots:

```txt
action
```

### Acceptance criteria

- Empty state рендерит title.
- Empty state рендерит description.
- Empty state поддерживает action slot.
- Компонент выглядит как часть RateGuru dark UI.
- Компонент не зависит от продуктовых сущностей.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-028: Create base empty state component`

### Files likely touched

```txt
resources/views/components/ui/empty-state.blade.php
tests/Feature/ViewComponents/EmptyStateComponentTest.php
```

---

## RG-029 — Create Base Loading Skeleton Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-029-create-base-loading-skeleton-component`     should be created from develop branch
**Depends on task:** RG-017

### Goal

Создать skeleton-компонент для loading states.

### TDD step

Render-test проверяет наличие skeleton/pulse classes.

### Implementation

Создать:

```txt
resources/views/components/ui/skeleton.blade.php
```

Props:

```txt
shape: line / block / circle
width
height
```

### Acceptance criteria

- Skeleton рендерится.
- Есть `line`, `block`, `circle`.
- Есть animation/pulse class.
- Цвет не конфликтует с dark UI.
- Компонент можно переиспользовать в карточках, drawer и forms.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-029: Create base loading skeleton component`

### Files likely touched

```txt
resources/views/components/ui/skeleton.blade.php
tests/Feature/ViewComponents/SkeletonComponentTest.php
```

---

## RG-030 — Create Base Error Message Component

**Area:** UI  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-030-create-base-error-message-component`     should be created from develop branch
**Depends on task:** RG-018, RG-019

### Goal

Создать общий error-message компонент для failed actions, forbidden states и loading errors.

### TDD step

Render-test проверяет title/message.

### Implementation

Создать:

```txt
resources/views/components/ui/error-message.blade.php
```

Props:

```txt
title
message
```

Slots:

```txt
action
```

### Acceptance criteria

- Error component рендерит title.
- Error component рендерит message.
- Использует danger color аккуратно.
- Поддерживает action slot.
- Это не field-level validation error, а общий error block.

### Definition of Done

- Render-test написан.
- Компонент реализован.
- Тест проходит.
- Коммит: `RG-030: Create base error message component`

### Files likely touched

```txt
resources/views/components/ui/error-message.blade.php
tests/Feature/ViewComponents/ErrorMessageComponentTest.php
```

---

## RG-031 — Create Dev-Only UI Kit Route

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-031-create-dev-only-ui-kit-route`     should be created from develop branch
**Depends on task:** RG-018, RG-019, RG-020, RG-021, RG-022, RG-023, RG-024, RG-025, RG-026, RG-027, RG-028, RG-029, RG-030

### Goal

Создать dev-only route `/dev/ui-kit`, где будут визуально собираться все базовые UI-компоненты.

### TDD step

Feature-test:

```txt
- in local/testing environment /dev/ui-kit returns 200;
- in production-like environment /dev/ui-kit is unavailable or forbidden.
```

### Implementation

Добавить route:

```php
Route::view('/dev/ui-kit', 'dev.ui-kit')
    ->name('dev.ui-kit');
```

Защитить route:

```txt
только local/testing
```

Создать view:

```txt
resources/views/dev/ui-kit.blade.php
```

Минимальное содержимое:

```txt
RateGuru UI Kit
Buttons
Cards
Forms
Overlays
Feedback
Reference
```

### Acceptance criteria

- `/dev/ui-kit` открывается в local/testing.
- `/dev/ui-kit` недоступен в production-like env.
- View использует base layout.
- Страница содержит заголовок `RateGuru UI Kit`.
- Есть секции для base components.
- Есть секция `Reference` для будущих screenshots/reference links.

### Definition of Done

- Feature-test написан.
- Route создан.
- View создан.
- Тест проходит.
- Коммит: `RG-031: Create dev-only UI kit route`

### Files likely touched

```txt
routes/web.php
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

---

## RG-032 — Render Buttons In UI Kit

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-032-render-buttons-in-ui-kit`     should be created from develop branch
**Depends on task:** RG-018, RG-031

### Goal

Показать все варианты кнопок в UI Kit.

### TDD step

Feature-test проверяет, что `/dev/ui-kit` содержит:

```txt
Primary Button
Secondary Button
Ghost Button
Danger Button
Disabled Button
```

### Implementation

В секции `Buttons` отрендерить:

```blade
<x-ui.button variant="primary">Primary Button</x-ui.button>
<x-ui.button variant="secondary">Secondary Button</x-ui.button>
<x-ui.button variant="ghost">Ghost Button</x-ui.button>
<x-ui.button variant="danger">Danger Button</x-ui.button>
<x-ui.button disabled>Disabled Button</x-ui.button>
```

### Acceptance criteria

- Все button variants видны.
- Disabled state виден.
- Buttons выглядят как часть dark UI.
- Buttons используют токены RateGuru.
- Feature-test проходит.

### Definition of Done

- UI Kit обновлён.
- Тест проходит.
- Коммит: `RG-032: Render buttons in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

---

## RG-033 — Render Cards In UI Kit

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-033-render-cards-in-ui-kit`     should be created from develop branch
**Depends on task:** RG-019, RG-020, RG-027, RG-031

### Goal

Показать варианты карточек в UI Kit.

### TDD step

Feature-test проверяет наличие:

```txt
Default Card
Elevated Card
Interactive Card
Food Image Placeholder
```

### Implementation

В секции `Cards` отрендерить:

```txt
- default card;
- elevated card;
- interactive card;
- card with image placeholder;
- card with badge.
```

### Acceptance criteria

- Card variants видны.
- Image placeholder внутри card выглядит корректно.
- Badge внутри card выглядит корректно.
- Визуально карточки похожи на исходную dark-card эстетику PlateRate.
- Feature-test проходит.

### Definition of Done

- UI Kit обновлён.
- Тест проходит.
- Коммит: `RG-033: Render cards in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

---

## RG-034 — Render Modal Shell In UI Kit

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-034-render-modal-shell-in-ui-kit`     should be created from develop branch
**Depends on task:** RG-018, RG-021, RG-022, RG-023, RG-031

### Goal

Показать modal shell в UI Kit на примере upload-like modal.

### TDD step

Feature-test проверяет наличие:

```txt
Open Modal
Upload Dish Preview
```

### Implementation

В секции `Overlays` добавить Alpine-пример:

```txt
- button Open Modal;
- modal title: Upload Dish Preview;
- input Dish title;
- textarea Description;
- footer buttons Cancel / Continue.
```

Компонент не должен отправлять форму и не должен использовать Livewire.

### Acceptance criteria

- Modal можно открыть/закрыть.
- Modal содержит title.
- Modal содержит form-like content.
- Modal visually matches dark rounded upload pattern.
- Alpine local state работает.
- Feature-test проверяет наличие элементов.

### Definition of Done

- UI Kit обновлён.
- Modal preview работает вручную.
- Тест проходит.
- Коммит: `RG-034: Render modal shell in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

---

## RG-035 — Render Drawer Shell In UI Kit

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-035-render-drawer-shell-in-ui-kit`     should be created from develop branch
**Depends on task:** RG-019, RG-024, RG-027, RG-031

### Goal

Показать drawer shell в UI Kit на примере post-detail drawer.

### TDD step

Feature-test проверяет наличие:

```txt
Open Drawer
Dish Details Preview
Homemade or Restaurant?
```

### Implementation

В секции `Overlays` или отдельной секции `Drawer` добавить Alpine-пример:

```txt
- button Open Drawer;
- drawer title: Dish Details Preview;
- large image placeholder;
- fake post title;
- fake voting area;
- fake comments preview.
```

Компонент не должен зависеть от модели Post.

### Acceptance criteria

- Drawer можно открыть/закрыть.
- Drawer visually matches right-side detail pattern.
- Есть image placeholder.
- Есть fake detail content.
- Mobile behavior не ломает layout.
- Feature-test проходит.

### Definition of Done

- UI Kit обновлён.
- Drawer preview работает вручную.
- Тест проходит.
- Коммит: `RG-035: Render drawer shell in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

---

## RG-036 — Render Form Controls In UI Kit

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-036-render-form-controls-in-ui-kit`     should be created from develop branch
**Depends on task:** RG-021, RG-022, RG-030, RG-031

### Goal

Показать form controls в UI Kit.

### TDD step

Feature-test проверяет наличие:

```txt
Dish title
Description
Validation error example
Disabled input
```

### Implementation

В секции `Forms` отрендерить:

```txt
- normal input;
- disabled input;
- input with error;
- textarea;
- error message component;
```

### Acceptance criteria

- Form controls видны.
- Error state виден.
- Disabled state виден.
- Focus styles можно проверить вручную.
- Controls используют dark UI tokens.
- Feature-test проходит.

### Definition of Done

- UI Kit обновлён.
- Тест проходит.
- Коммит: `RG-036: Render form controls in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

---

## RG-037 — Add UI Design Checklist Document

**Area:** Docs  
**Type:** Docs  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-037-add-ui-design-checklist-document`     should be created from develop branch
**Depends on task:** RG-016, RG-031, RG-032, RG-033, RG-034, RG-035, RG-036

### Goal

Создать чеклист ручной проверки UI, чтобы каждый следующий UI-экран сверялся с исходным визуальным направлением и текущим UI Kit.

### TDD step

No direct test — документационная задача.

### Implementation

Создать:

```txt
docs/design/ui-review-checklist.md
```

Содержимое:

```md
# RateGuru UI Review Checklist

## Reference
- [ ] Checked original prototype: docs/design/reference/original/PlateRate.html
- [ ] Checked reference screenshots if available
- [ ] Checked docs/design/design-contract.md
- [ ] Checked /dev/ui-kit

## General
- [ ] Dark background is preserved
- [ ] Text contrast is acceptable
- [ ] Accent purple is used consistently
- [ ] Border radius matches RateGuru style
- [ ] Spacing is not cramped
- [ ] UI does not look like default Laravel

## Components
- [ ] Buttons use x-ui.button
- [ ] Cards use x-ui.card
- [ ] Inputs use x-ui.input / x-ui.textarea
- [ ] Modal uses x-ui.modal
- [ ] Drawer uses x-ui.drawer
- [ ] Dropdown uses x-ui.dropdown

## States
- [ ] Hover state exists
- [ ] Focus state exists
- [ ] Disabled state exists where needed
- [ ] Empty state exists where needed
- [ ] Loading state exists where needed
- [ ] Error state exists where needed

## Responsive
- [ ] Mobile layout does not overflow
- [ ] Desktop layout matches intended density
- [ ] Drawer/modal usable on mobile
```

Также обновить `AGENTS.md`:

```txt
Every UI task must check docs/design/ui-review-checklist.md.
Every reusable component must be rendered in /dev/ui-kit.
Original prototype reference must be checked before visual UI work.
```

### Acceptance criteria

- `docs/design/ui-review-checklist.md` существует.
- Чеклист содержит Reference section.
- Чеклист ссылается на original prototype.
- Чеклист ссылается на `/dev/ui-kit`.
- `AGENTS.md` обновлён UI-правилом.
- Документ можно использовать в PR review.

### Definition of Done

- Checklist создан.
- `AGENTS.md` обновлён.
- Коммит: `RG-037: Add UI design checklist document`

### Files likely touched

```txt
docs/design/ui-review-checklist.md
AGENTS.md
```

---

## RG-038 — Add Screenshot Baseline Folder Structure

**Area:** Docs / Tests  
**Type:** Config  
**Priority:** P0  
**Branch name created from develop branch:** `feature/RG-038-add-screenshot-baseline-folder-structure`     should be created from develop branch
**Depends on task:** RG-016, RG-031, RG-037

### Goal

Создать структуру для эталонных скриншотов и добавить инструкцию, как агент должен использовать screenshots для визуального соответствия.

### TDD step

No direct test — файловая структура и документация.

### Implementation

Создать структуру:

```txt
tests/Visual/baselines/
tests/Visual/current/
tests/Visual/diffs/

docs/design/reference/screenshots/
```

Добавить `.gitkeep`:

```txt
tests/Visual/baselines/.gitkeep
tests/Visual/current/.gitkeep
tests/Visual/diffs/.gitkeep
docs/design/reference/screenshots/.gitkeep
```

Создать документ:

```txt
docs/design/visual-baselines.md
```

Содержимое:

```md
# Visual Baselines

## Purpose

Visual baselines are used to keep RateGuru close to the original prototype direction.

## Reference files

Original HTML:
docs/design/reference/original/PlateRate.html

Reference screenshots:
docs/design/reference/screenshots/

Automated baseline structure:
tests/Visual/baselines/
tests/Visual/current/
tests/Visual/diffs/

## Required screenshots

When available, save:
- feed-desktop.png
- feed-mobile.png
- post-card-closeup.png
- upload-modal-desktop.png
- post-drawer-desktop.png
- ui-kit-desktop.png

## Phase 1 rule

Phase 1 creates folder structure and documentation.
Actual screenshot automation is a later task.
Manual comparison is required for now.
```

Если есть возможность сделать скриншоты из исходного `PlateRate.html`, сохранить их в:

```txt
docs/design/reference/screenshots/
```

Минимально допустимо на Phase 1 оставить `.gitkeep` и документацию, если автоматический screenshot tool ещё не подключён.

### Acceptance criteria

- `tests/Visual/baselines/` существует.
- `tests/Visual/current/` существует.
- `tests/Visual/diffs/` существует.
- `docs/design/reference/screenshots/` существует.
- `.gitkeep` добавлены.
- `docs/design/visual-baselines.md` существует.
- Документ объясняет, что Phase 1 не требует автоматической visual regression.
- Документ перечисляет обязательные будущие screenshots.

### Definition of Done

- Папки созданы.
- Документ создан.
- Коммит: `RG-038: Add screenshot baseline folder structure`

### Files likely touched

```txt
tests/Visual/baselines/.gitkeep
tests/Visual/current/.gitkeep
tests/Visual/diffs/.gitkeep
docs/design/reference/screenshots/.gitkeep
docs/design/visual-baselines.md
```

---

# 8. Phase 1 Completion Criteria

Phase 1 завершена, когда:

```txt
- RG-016–RG-038 выполнены;
- original PlateRate.html сохранён как reference artifact;
- CSS tokens существуют;
- Tailwind mapping существует;
- базовые UI components созданы и протестированы;
- /dev/ui-kit существует и защищён от production;
- UI Kit показывает buttons, cards, modal, drawer, form controls;
- UI checklist существует;
- visual baseline folders существуют;
- docs/design/visual-baselines.md существует;
- npm run build проходит;
- composer test проходит.
```

---

# 9. Что нельзя делать в Phase 1

Без отдельной задачи нельзя:

```txt
- создавать Post/Comment/Vote models;
- писать feed query;
- делать реальный upload;
- подключать Cloudinary;
- делать voting backend;
- делать comments backend;
- делать reports backend;
- делать Filament resources;
- добавлять Redis;
- переходить на PostgreSQL;
- добавлять Vue/React/Inertia;
- делать полноценную screenshot automation;
- менять GitFlow;
- менять auth stack.
```

---

# 10. Recommended Execution Order

```txt
RG-016 Extract Prototype Color Tokens Into CSS Variables
RG-017 Add Tailwind Theme Mapping For RateGuru Colors
RG-018 Create Base UI Button Component
RG-019 Create Base UI Card Component
RG-020 Create Base UI Badge Component
RG-021 Create Base UI Input Component
RG-022 Create Base UI Textarea Component
RG-023 Create Base UI Modal Shell Component
RG-024 Create Base UI Drawer Shell Component
RG-025 Create Base UI Dropdown Shell Component
RG-026 Create Base Avatar Component
RG-027 Create Base Image Placeholder Component
RG-028 Create Base Empty State Component
RG-029 Create Base Loading Skeleton Component
RG-030 Create Base Error Message Component
RG-031 Create Dev-Only UI Kit Route
RG-032 Render Buttons In UI Kit
RG-033 Render Cards In UI Kit
RG-034 Render Modal Shell In UI Kit
RG-035 Render Drawer Shell In UI Kit
RG-036 Render Form Controls In UI Kit
RG-037 Add UI Design Checklist Document
RG-038 Add Screenshot Baseline Folder Structure
```

---

# 11. Release

После завершения Phase 1:

```bash
git checkout develop
git pull origin develop

composer test
npm run build

git checkout -b release/v0.0.2-phase1-ui-foundation
git push -u origin release/v0.0.2-phase1-ui-foundation
```

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.2-phase1-ui-foundation -m "RateGuru Phase 1 UI foundation"
git push origin v0.0.2-phase1-ui-foundation
```
