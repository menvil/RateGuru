# RateGuru — Phase 9 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 9 — Search, Categories, Sorting UI**  
Диапазон задач: **RG-186 → RG-201**  
Основа нумерации: исходный atomic backlog, где Phase 9 начинается с задачи 186 и заканчивается задачей 201.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 1. Главная фиксация

Phase 9 соответствует исходному блоку:

```txt
Phase 9 — Search, Categories, Sorting UI
```

Правильный диапазон Phase 9:

```txt
RG-186 — Create SearchBar Livewire component
RG-187 — Test SearchBar updates feed search state
RG-188 — Wire SearchBar to PostFeed
RG-189 — Add debounced search input
RG-190 — Create CategoryTabs Livewire component
RG-191 — Test category selection updates feed filter
RG-192 — Wire CategoryTabs to PostFeed
RG-193 — Render category tabs UI
RG-194 — Create SortDropdown Livewire component
RG-195 — Test sort selection updates feed sort
RG-196 — Wire SortDropdown to PostFeed
RG-197 — Render sort dropdown UI
RG-198 — Add Alpine dropdown behavior to SortDropdown
RG-199 — Add URL query string sync for search
RG-200 — Add URL query string sync for category
RG-201 — Add URL query string sync for sort
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 2. Цель Phase 9

Phase 9 добавляет интерактивное управление лентой:

```txt
- search input;
- category/tag tabs;
- sort dropdown;
- связь этих controls с PostFeed;
- debounced search;
- Alpine dropdown behavior;
- URL query string sync для search/category/sort.
```

После Phase 9 пользователь должен уметь:

```txt
- искать посты по title/description;
- фильтровать ленту по категории/tag slug;
- переключать сортировку newest/top/hot;
- обновить страницу и сохранить search/category/sort через URL.
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 3. Scope Phase 9

## Входит

```txt
- SearchBar Livewire component;
- CategoryTabs Livewire component;
- SortDropdown Livewire component;
- Livewire events / parent state wiring;
- PostFeed integration with FeedQuery search/tag/sort;
- debounced input;
- Alpine dropdown behavior for SortDropdown;
- URL query string sync.
```

## Не входит

```txt
- Upload modal;
- voting;
- comments;
- post drawer;
- report modal;
- pagination UI;
- infinite scroll;
- category management admin;
- tag creation UI;
- full-text search engine;
- autocomplete;
- search suggestions;
- saved filters;
- recommendations.
```

Upload UI будет в Phase 10.  
Post drawer будет в Phase 11.  
Voting начнётся в Phase 13.  
Pagination UI может быть отдельной задачей позже, если понадобится.

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 4. Architecture Rules

## 4.1. Feed state should live in FeedPage

`FeedPage` должен быть владельцем состояния:

```txt
search
category/tag
sort
```

Почему:

```txt
- SearchBar, CategoryTabs и SortDropdown — sibling controls;
- PostFeed должен получать готовые параметры;
- URL query string лучше синхронизировать на page-level;
- проще контролировать reset поведения.
```

Рекомендуемая структура:

```blade
<livewire:feed.search-bar :search="$search" />
<livewire:feed.category-tabs :selected="$category" />
<livewire:feed.sort-dropdown :sort="$sort" />
<livewire:feed.post-feed :search="$search" :tag="$category" :sort="$sort" />
```

или через events, если удобнее. Но state owner всё равно FeedPage.

## 4.2. PostFeed remains read-only

`PostFeed` не должен владеть UI controls.  
Он должен получать:

```php
public ?string $search = null;
public ?string $tag = null;
public string $sort = 'newest';
```

и передавать их в `FeedQuery`.

## 4.3. SearchBar should not call FeedQuery

SearchBar только меняет search state.  
Он не должен сам искать посты.

## 4.4. CategoryTabs should use tags/categories from DB or safe static fallback

На старте есть `Tag` model.  
Можно показывать top/available tags из БД. Но Phase 9 не содержит отдельного backend query для категорий.

Допустимый MVP:

```txt
- All tab;
- несколько tags из базы, если они есть;
- если tags нет — показывать только All.
```

Не создавать seeders в этой фазе.

## 4.5. Sort values must match FeedQuery

Разрешённые values:

```txt
newest
top
hot
```

SortDropdown не должен отправлять произвольные значения.

## 4.6. URL query string sync

URL должен выглядеть примерно так:

```txt
/?search=pasta&category=italian&sort=top
```

Внутренний FeedQuery параметр называется `tag`, но UI может называть это `category`.  
Важно не путать:

```txt
URL: category
FeedQuery: tag
DB: tags.slug
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 5. Design Constraints

Phase 9 должна визуально продолжать Phase 8:

```txt
- dark-first UI;
- compact controls;
- purple accent for active states;
- rounded input/dropdown/tabs;
- mobile-friendly stacking;
- desktop-friendly horizontal control bar.
```

Перед UI-задачами проверять:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-8-feed-ui-review.md
```

Нельзя делать светлый дефолтный form UI.

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 6. GitFlow для Phase 9

## Base branch

Все задачи Phase 9 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-186-create-search-bar-livewire-component
feature/RG-192-wire-category-tabs-to-post-feed
feature/RG-201-add-url-query-string-sync-for-sort
```

## Commit format

```txt
RG-186: Create SearchBar Livewire component
RG-192: Wire CategoryTabs to PostFeed
RG-201: Add URL query string sync for sort
```

## Release branch

После выполнения `RG-186`–`RG-201`:

```txt
release/v0.1.0-phase9-search-categories-sorting-ui
```

## Tag

После merge release branch в `main`:

```txt
v0.1.0-phase9-search-categories-sorting-ui
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 7. TDD Rules for Phase 9

## Для Livewire controls

Пишем Livewire tests:

```txt
- component renders;
- component accepts initial prop;
- component emits/dispatches event or updates parent state;
- component displays selected/active state.
```

## Для FeedPage wiring

Пишем Livewire tests на page-level:

```txt
- search update changes PostFeed results;
- category selection changes PostFeed results;
- sort selection changes order.
```

## Для URL sync

Пишем HTTP/Livewire tests:

```txt
- query string preloads state;
- updating state changes query string;
- default values do not pollute URL if possible.
```

## Для Alpine dropdown

Автоматически тестируем только presence of markup/attributes.  
Открытие/закрытие dropdown полностью unit-тестом не ловится. Нужен manual check.

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Tests
Type: Test / Feature / Component / Wiring / Layout
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
- Проверен design checklist, если это visual task
- Нет логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 9. Phase 9 Atomic Tasks

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-186 — Create SearchBar Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-186-create-search-bar-livewire-component`  
**Base branch:** develop
**Depends on:** RG-185

### Goal

Создать `SearchBar` Livewire component для ввода поискового запроса.

### TDD step

Livewire test:

```php
it('can render search bar component', function () {
    Livewire::test(SearchBar::class)
        ->assertStatus(200)
        ->assertSee('Search');
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать компонент:

```bash
php artisan make:livewire Feed/SearchBar
```

Файлы:

```txt
app/Livewire/Feed/SearchBar.php
resources/views/livewire/feed/search-bar.blade.php
```

Минимальный class:

```php
final class SearchBar extends Component
{
    public string $search = '';

    public function render(): View
    {
        return view('livewire.feed.search-bar');
    }
}
```

View:

```blade
<div>
    <x-ui.input
        name="search"
        placeholder="Search dishes..."
        wire:model="search"
    />
</div>
```

Пока не wiring к feed.

### Acceptance criteria

- `SearchBar` component существует.
- Component рендерится.
- Есть input.
- Используется `x-ui.input`.
- Placeholder понятный.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Компонент создан.
- Тест проходит.
- Коммит: `RG-186: Create SearchBar Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/SearchBar.php
resources/views/livewire/feed/search-bar.blade.php
tests/Feature/Livewire/SearchBarTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-187 — Test SearchBar Updates Feed Search State

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-187-test-search-bar-updates-feed-search-state`  
**Base branch:** develop
**Depends on:** RG-186

### Goal

Написать падающий тест: изменение SearchBar обновляет feed search state.

### TDD step

Есть два допустимых подхода.

## Подход A — event-based

SearchBar dispatches event:

```php
it('dispatches search updated event', function () {
    Livewire::test(SearchBar::class)
        ->set('search', 'pasta')
        ->assertDispatched('feed-search-updated', search: 'pasta');
});
```

## Подход B — parent-owned state

Если SearchBar будет nested в FeedPage, тестировать лучше FeedPage:

```php
it('updates feed page search state from search bar', function () {
    Livewire::test(FeedPage::class)
        ->set('search', 'pasta')
        ->assertSet('search', 'pasta');
});
```

Рекомендация: для Livewire проще и стабильнее использовать parent-owned state.  
Но так как задача называется SearchBar updates feed search state, можно зафиксировать event contract.

### Implementation

Только добавить тест.  
Не реализовывать wiring в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест фиксирует contract обновления search state.
- Тест падает до RG-188 или RG-189, если behavior ещё не реализован.
- Не меняется FeedQuery.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-187: Test SearchBar updates feed search state`

### Files likely touched

```txt
tests/Feature/Livewire/SearchBarTest.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-188 — Wire SearchBar To PostFeed

**Area:** Livewire / UI  
**Type:** Wiring  
**Priority:** P0  
**Branch:** `feature/RG-188-wire-search-bar-to-post-feed`  
**Base branch:** develop
**Depends on:** RG-187

### Goal

Связать SearchBar с PostFeed через FeedPage state, чтобы поиск менял результаты ленты.

### TDD step

Использовать тест из RG-187 и добавить интеграционный Livewire test:

```php
it('filters feed results when search changes', function () {
    Post::factory()->published()->create(['title' => 'Homemade Pasta']);
    Post::factory()->published()->create(['title' => 'Chocolate Cake']);

    Livewire::test(FeedPage::class)
        ->set('search', 'pasta')
        ->assertSee('Homemade Pasta')
        ->assertDontSee('Chocolate Cake');
});
```

### Implementation

В `FeedPage` добавить state:

```php
public string $search = '';
```

В view:

```blade
<livewire:feed.search-bar :search="$search" />

<livewire:feed.post-feed
    :search="$search"
    :tag="$category"
    :sort="$sort"
    :key="'feed-'.$search.'-'.$category.'-'.$sort"
/>
```

Но Livewire child prop updates могут требовать другой wiring. Более надёжно: пока сделать controls прямо в FeedPage или использовать modelable components.

Простой вариант:

```blade
<x-ui.input
    name="search"
    placeholder="Search dishes..."
    wire:model.live.debounce.500ms="search"
/>
```

Но это убьёт смысл SearchBar component. Поэтому лучше SearchBar сделать modelable:

```php
#[Modelable]
public string $search = '';
```

и в FeedPage:

```blade
<livewire:feed.search-bar wire:model.live="search" />
```

PostFeed:

```php
public string $search = '';
```

и render:

```php
$feedQuery->get(search: $this->search ?: null, tag: ..., sort: ...)
```

### Acceptance criteria

- SearchBar отображается на FeedPage.
- Изменение search state фильтрует PostFeed.
- PostFeed использует FeedQuery search parameter.
- Pending/hidden posts не появляются.
- Тест проходит.

### Definition of Done

- Wiring реализован.
- Интеграционный тест проходит.
- Коммит: `RG-188: Wire SearchBar to PostFeed`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
app/Livewire/Feed/SearchBar.php
app/Livewire/Feed/PostFeed.php
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/search-bar.blade.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-189 — Add Debounced Search Input

**Area:** Livewire / UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-189-add-debounced-search-input`  
**Base branch:** develop
**Depends on:** RG-188

### Goal

Добавить debounce к search input, чтобы Livewire не отправлял запрос на каждый символ мгновенно.

### TDD step

Markup test:

```php
it('uses debounced search binding', function () {
    Livewire::test(SearchBar::class)
        ->assertSee('wire:model.live.debounce.500ms', false);
});
```

Если SearchBar использует `wire:model.live.debounce.500ms="search"`.

Если используется `wire:model.debounce.500ms`, подстроить тест под Livewire-версию.

### Implementation

В `search-bar.blade.php`:

```blade
<x-ui.input
    name="search"
    placeholder="Search dishes..."
    wire:model.live.debounce.500ms="search"
/>
```

Если component modelable:

```blade
<input wire:model.live.debounce.500ms="search" ...>
```

Важно: если `x-ui.input` не пробрасывает attributes корректно, исправить `x-ui.input`, чтобы `$attributes` попадали на `<input>`.

### Acceptance criteria

- Search input использует debounce.
- Debounce value = 500ms или близко.
- Input всё ещё обновляет feed search.
- Тест проходит.
- Manual check: ввод не ломает UI.

### Definition of Done

- Debounce добавлен.
- Тест проходит.
- Коммит: `RG-189: Add debounced search input`

### Files likely touched

```txt
resources/views/livewire/feed/search-bar.blade.php
resources/views/components/ui/input.blade.php
tests/Feature/Livewire/SearchBarTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-190 — Create CategoryTabs Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-190-create-category-tabs-livewire-component`  
**Base branch:** develop
**Depends on:** RG-189

### Goal

Создать `CategoryTabs` Livewire component для выбора категории/tag.

### TDD step

Livewire test:

```php
it('can render category tabs component', function () {
    Livewire::test(CategoryTabs::class)
        ->assertStatus(200)
        ->assertSee('All');
});
```

### Implementation

Создать компонент:

```bash
php artisan make:livewire Feed/CategoryTabs
```

Class:

```php
final class CategoryTabs extends Component
{
    public ?string $selected = null;

    public function render(): View
    {
        return view('livewire.feed.category-tabs', [
            'tags' => Tag::query()->orderBy('name')->limit(10)->get(),
        ]);
    }
}
```

На старте допустимо грузить tags прямо здесь, но не добавлять отдельный TagQuery.  
Если tags нет, показать только `All`.

View:

```blade
<div>
    <button>All</button>

    @foreach($tags as $tag)
        <button>{{ $tag->name }}</button>
    @endforeach
</div>
```

Пока не wiring к FeedPage.

### Acceptance criteria

- `CategoryTabs` component существует.
- Component рендерится.
- Показывает `All`.
- Если tags есть — показывает их names.
- Нет создания tags.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Компонент создан.
- Тест проходит.
- Коммит: `RG-190: Create CategoryTabs Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/CategoryTabs.php
resources/views/livewire/feed/category-tabs.blade.php
tests/Feature/Livewire/CategoryTabsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-191 — Test Category Selection Updates Feed Filter

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-191-test-category-selection-updates-feed-filter`  
**Base branch:** develop
**Depends on:** RG-190

### Goal

Написать падающий тест: выбор категории/tag обновляет feed filter.

### TDD step

Интеграционный тест на FeedPage:

```php
it('filters feed when category is selected', function () {
    $pasta = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);
    $dessert = Tag::factory()->create(['name' => 'Dessert', 'slug' => 'dessert']);

    $matching = Post::factory()->published()->create(['title' => 'Pasta Dish']);
    $matching->tags()->attach($pasta);

    $other = Post::factory()->published()->create(['title' => 'Cake']);
    $other->tags()->attach($dessert);

    Livewire::test(FeedPage::class)
        ->set('category', 'pasta')
        ->assertSee('Pasta Dish')
        ->assertDontSee('Cake');
});
```

Тест должен упасть до RG-192.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест использует tag slug.
- Тест проверяет, что matching post виден.
- Тест проверяет, что non-matching post скрыт.
- Тест падает до RG-192.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-191: Test category selection updates feed filter`

### Files likely touched

```txt
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-192 — Wire CategoryTabs To PostFeed

**Area:** Livewire / UI  
**Type:** Wiring  
**Priority:** P0  
**Branch:** `feature/RG-192-wire-category-tabs-to-post-feed`  
**Base branch:** develop
**Depends on:** RG-191

### Goal

Связать CategoryTabs с PostFeed через FeedPage state.

### TDD step

Использовать падающий тест из RG-191.

### Implementation

В `FeedPage` добавить:

```php
public ?string $category = null;
```

В `CategoryTabs` сделать modelable selected state или dispatch event.

Рекомендуемый подход:

```php
#[Modelable]
public ?string $selected = null;
```

В FeedPage view:

```blade
<livewire:feed.category-tabs wire:model.live="category" />
```

В PostFeed props:

```php
public ?string $tag = null;
```

В FeedPage view:

```blade
<livewire:feed.post-feed
    :search="$search"
    :tag="$category"
    :sort="$sort"
    :key="'feed-'.$search.'-'.$category.'-'.$sort"
/>
```

В PostFeed render:

```php
$feedQuery->get(
    search: $this->search ?: null,
    tag: $this->tag ?: null,
    sort: $this->sort,
);
```

### Acceptance criteria

- CategoryTabs отображается на FeedPage.
- Выбор category/tag обновляет FeedPage category state.
- PostFeed получает tag slug.
- FeedQuery фильтрует по tag.
- Тест RG-191 проходит.

### Definition of Done

- Wiring реализован.
- Тест проходит.
- Коммит: `RG-192: Wire CategoryTabs to PostFeed`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
app/Livewire/Feed/CategoryTabs.php
app/Livewire/Feed/PostFeed.php
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/category-tabs.blade.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-193 — Render Category Tabs UI

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-193-render-category-tabs-ui`  
**Base branch:** develop
**Depends on:** RG-192

### Goal

Оформить CategoryTabs визуально в стиле RateGuru.

### TDD step

Livewire test:

```php
it('renders category tabs with active state', function () {
    Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    Livewire::test(CategoryTabs::class, ['selected' => 'pasta'])
        ->assertSee('All')
        ->assertSee('Pasta')
        ->assertSee('aria-selected="true"', false);
});
```

### Implementation

В `category-tabs.blade.php`:

```blade
<div class="flex gap-2 overflow-x-auto">
    <button
        type="button"
        wire:click="$set('selected', null)"
        aria-selected="{{ $selected === null ? 'true' : 'false' }}"
        class="..."
    >
        All
    </button>

    @foreach($tags as $tag)
        <button
            type="button"
            wire:click="$set('selected', '{{ $tag->slug }}')"
            aria-selected="{{ $selected === $tag->slug ? 'true' : 'false' }}"
            class="..."
        >
            {{ $tag->name }}
        </button>
    @endforeach
</div>
```

Использовать:

```txt
rounded-rgPill
bg-rg-accent for active
bg-rg-surface for inactive
border-rg-border
```

### Acceptance criteria

- Tabs визуально выглядят как pills.
- Active tab имеет accent style.
- `All` сбрасывает filter.
- Tags horizontal scroll на mobile.
- Есть aria-selected.
- Тест проходит.

### Definition of Done

- UI оформлен.
- Тест проходит.
- Manual mobile check выполнен.
- Коммит: `RG-193: Render category tabs UI`

### Files likely touched

```txt
resources/views/livewire/feed/category-tabs.blade.php
tests/Feature/Livewire/CategoryTabsTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-194 — Create SortDropdown Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-194-create-sort-dropdown-livewire-component`  
**Base branch:** develop
**Depends on:** RG-193

### Goal

Создать `SortDropdown` Livewire component для выбора сортировки.

### TDD step

Livewire test:

```php
it('can render sort dropdown component', function () {
    Livewire::test(SortDropdown::class)
        ->assertStatus(200)
        ->assertSee('Newest')
        ->assertSee('Top')
        ->assertSee('Hot');
});
```

### Implementation

Создать:

```bash
php artisan make:livewire Feed/SortDropdown
```

Class:

```php
final class SortDropdown extends Component
{
    public string $sort = 'newest';

    public function render(): View
    {
        return view('livewire.feed.sort-dropdown', [
            'options' => [
                'newest' => 'Newest',
                'top' => 'Top',
                'hot' => 'Hot',
            ],
        ]);
    }
}
```

Пока можно отрендерить обычные buttons/select.  
Alpine dropdown behavior будет RG-198.

### Acceptance criteria

- `SortDropdown` component существует.
- Показывает Newest/Top/Hot.
- Default sort = newest.
- Нет FeedQuery logic.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Компонент создан.
- Тест проходит.
- Коммит: `RG-194: Create SortDropdown Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/SortDropdown.php
resources/views/livewire/feed/sort-dropdown.blade.php
tests/Feature/Livewire/SortDropdownTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-195 — Test Sort Selection Updates Feed Sort

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-195-test-sort-selection-updates-feed-sort`  
**Base branch:** develop
**Depends on:** RG-194

### Goal

Написать падающий тест: выбор сортировки обновляет порядок PostFeed.

### TDD step

Интеграционный test на FeedPage:

```php
it('sorts feed when sort is changed', function () {
    $low = Post::factory()->published()->create([
        'title' => 'Low Score',
        'upvotes_count' => 1,
        'downvotes_count' => 0,
    ]);

    $high = Post::factory()->published()->create([
        'title' => 'High Score',
        'upvotes_count' => 10,
        'downvotes_count' => 0,
    ]);

    Livewire::test(FeedPage::class)
        ->set('sort', 'top')
        ->assertSeeInOrder(['High Score', 'Low Score']);
});
```

Тест должен упасть до RG-196.

### Implementation

Только добавить тест.

### Acceptance criteria

- Тест существует.
- Тест проверяет sort = top.
- Тест проверяет порядок через assertSeeInOrder.
- Тест падает до RG-196.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-195: Test sort selection updates feed sort`

### Files likely touched

```txt
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-196 — Wire SortDropdown To PostFeed

**Area:** Livewire / UI  
**Type:** Wiring  
**Priority:** P0  
**Branch:** `feature/RG-196-wire-sort-dropdown-to-post-feed`  
**Base branch:** develop
**Depends on:** RG-195

### Goal

Связать SortDropdown с PostFeed через FeedPage state.

### TDD step

Использовать падающий тест из RG-195.

### Implementation

В `FeedPage` добавить:

```php
public string $sort = 'newest';
```

В `SortDropdown` сделать modelable:

```php
#[Modelable]
public string $sort = 'newest';
```

В FeedPage view:

```blade
<livewire:feed.sort-dropdown wire:model.live="sort" />
```

В PostFeed:

```php
public string $sort = 'newest';
```

В render:

```php
$feedQuery->get(
    search: $this->search ?: null,
    tag: $this->tag ?: null,
    sort: $this->sort ?: 'newest',
);
```

Также добавить safety normalization:

```php
if (! in_array($this->sort, ['newest', 'top', 'hot'], true)) {
    $this->sort = 'newest';
}
```

Можно сделать метод `normalizedSort()`.

### Acceptance criteria

- SortDropdown отображается на FeedPage.
- Изменение sort меняет порядок PostFeed.
- Sort values ограничены newest/top/hot.
- Invalid sort fallback = newest.
- Тест проходит.

### Definition of Done

- Wiring реализован.
- Тест проходит.
- Коммит: `RG-196: Wire SortDropdown to PostFeed`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
app/Livewire/Feed/SortDropdown.php
app/Livewire/Feed/PostFeed.php
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/sort-dropdown.blade.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-197 — Render Sort Dropdown UI

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-197-render-sort-dropdown-ui`  
**Base branch:** develop
**Depends on:** RG-196

### Goal

Оформить SortDropdown визуально в стиле RateGuru.

### TDD step

Livewire test:

```php
it('renders selected sort label', function () {
    Livewire::test(SortDropdown::class, ['sort' => 'top'])
        ->assertSee('Top');
});
```

Также проверить options:

```php
->assertSee('Newest')
->assertSee('Hot')
```

### Implementation

В `sort-dropdown.blade.php` сделать UI:

```txt
- trigger button;
- current label;
- options list;
- active option style;
- dark surface;
- rounded control;
```

До RG-198 можно использовать простой always-visible list или select.  
Но лучше сразу structure для Alpine, а behavior добавить в RG-198.

Пример:

```blade
<div class="relative">
    <button type="button">
        Sort: {{ $currentLabel }}
    </button>

    <div>
        @foreach($options as $value => $label)
            <button wire:click="$set('sort', '{{ $value }}')">
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
```

### Acceptance criteria

- SortDropdown показывает current label.
- Options Newest/Top/Hot видны.
- Active option визуально отличается.
- Используются RateGuru tokens.
- Тест проходит.

### Definition of Done

- UI оформлен.
- Тест проходит.
- Коммит: `RG-197: Render sort dropdown UI`

### Files likely touched

```txt
resources/views/livewire/feed/sort-dropdown.blade.php
tests/Feature/Livewire/SortDropdownTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-198 — Add Alpine Dropdown Behavior To SortDropdown

**Area:** UI / Alpine  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-198-add-alpine-dropdown-behavior-to-sort-dropdown`  
**Base branch:** develop
**Depends on:** RG-197

### Goal

Добавить Alpine open/close поведение к SortDropdown.

### TDD step

Markup test:

```php
it('has alpine dropdown behavior', function () {
    Livewire::test(SortDropdown::class)
        ->assertSee('x-data', false)
        ->assertSee('x-show', false)
        ->assertSee('@click.outside', false);
});
```

Тест проверяет только наличие Alpine attributes. Поведение открыть/закрыть проверяется вручную.

### Implementation

В `sort-dropdown.blade.php`:

```blade
<div x-data="{ open: false }" class="relative">
    <button type="button" @click="open = ! open">
        Sort: {{ $currentLabel }}
    </button>

    <div
        x-show="open"
        @click.outside="open = false"
        x-transition
    >
        @foreach($options as $value => $label)
            <button
                type="button"
                wire:click="$set('sort', '{{ $value }}')"
                @click="open = false"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
```

Добавить keyboard basics, если просто:

```txt
Escape closes dropdown
```

можно через:

```blade
@keydown.escape.window="open = false"
```

### Acceptance criteria

- Dropdown uses Alpine `x-data`.
- Trigger toggles open.
- Menu uses `x-show`.
- Outside click closes menu.
- Option click closes menu.
- Escape closes menu, если добавлено.
- Тест проходит.
- Manual check выполнен.

### Definition of Done

- Alpine behavior добавлен.
- Тест проходит.
- Manual UI check выполнен.
- Коммит: `RG-198: Add Alpine dropdown behavior to SortDropdown`

### Files likely touched

```txt
resources/views/livewire/feed/sort-dropdown.blade.php
tests/Feature/Livewire/SortDropdownTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-199 — Add URL Query String Sync For Search

**Area:** Livewire / Routing  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-199-add-url-query-string-sync-for-search`  
**Base branch:** develop
**Depends on:** RG-188, RG-189

### Goal

Синхронизировать search state с URL query string.

URL example:

```txt
/?search=pasta
```

### TDD step

Livewire/HTTP test:

```php
it('hydrates search from query string', function () {
    Post::factory()->published()->create(['title' => 'Homemade Pasta']);
    Post::factory()->published()->create(['title' => 'Chocolate Cake']);

    $this->get('/?search=pasta')
        ->assertSee('Homemade Pasta')
        ->assertDontSee('Chocolate Cake');
});
```

Livewire property test:

```php
Livewire::withQueryParams(['search' => 'pasta'])
    ->test(FeedPage::class)
    ->assertSet('search', 'pasta');
```

### Implementation

В `FeedPage` добавить query string sync.

Для Livewire v3 можно использовать attribute:

```php
use Livewire\Attributes\Url;

#[Url(as: 'search', except: '')]
public string $search = '';
```

Если используется другой синтаксис Livewire, применить актуальный для версии.

### Acceptance criteria

- `/?search=pasta` устанавливает `$search = pasta`.
- Feed filtered by search.
- Empty search не должен загрязнять URL, если `except: ''` работает.
- Тест проходит.

### Definition of Done

- URL sync для search добавлен.
- Тест проходит.
- Коммит: `RG-199: Add URL query string sync for search`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
tests/Feature/Livewire/FeedPageQueryStringTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-200 — Add URL Query String Sync For Category

**Area:** Livewire / Routing  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-200-add-url-query-string-sync-for-category`  
**Base branch:** develop
**Depends on:** RG-192, RG-199

### Goal

Синхронизировать selected category/tag slug с URL query string.

URL example:

```txt
/?category=pasta
```

### TDD step

HTTP test:

```php
it('hydrates category from query string', function () {
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    $matching = Post::factory()->published()->create(['title' => 'Pasta Dish']);
    $matching->tags()->attach($tag);

    Post::factory()->published()->create(['title' => 'Cake']);

    $this->get('/?category=pasta')
        ->assertSee('Pasta Dish')
        ->assertDontSee('Cake');
});
```

Livewire property test:

```php
Livewire::withQueryParams(['category' => 'pasta'])
    ->test(FeedPage::class)
    ->assertSet('category', 'pasta');
```

### Implementation

В `FeedPage`:

```php
#[Url(as: 'category', except: '')]
public ?string $category = null;
```

Если `except: ''` не подходит для null, использовать подход, совместимый с версией Livewire.

Важно: FeedQuery параметр называется `tag`, но URL и UI state — `category`.

### Acceptance criteria

- `/?category=pasta` устанавливает category.
- PostFeed получает tag = category.
- Feed filtered by tag slug.
- Empty category не загрязняет URL.
- Тест проходит.

### Definition of Done

- URL sync для category добавлен.
- Тест проходит.
- Коммит: `RG-200: Add URL query string sync for category`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
tests/Feature/Livewire/FeedPageQueryStringTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-201 — Add URL Query String Sync For Sort

**Area:** Livewire / Routing  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-201-add-url-query-string-sync-for-sort`  
**Base branch:** develop
**Depends on:** RG-196, RG-200

### Goal

Синхронизировать sort state с URL query string.

URL example:

```txt
/?sort=top
```

### TDD step

HTTP test:

```php
it('hydrates sort from query string', function () {
    Post::factory()->published()->create([
        'title' => 'Low Score',
        'upvotes_count' => 1,
        'downvotes_count' => 0,
    ]);

    Post::factory()->published()->create([
        'title' => 'High Score',
        'upvotes_count' => 10,
        'downvotes_count' => 0,
    ]);

    $this->get('/?sort=top')
        ->assertSeeInOrder(['High Score', 'Low Score']);
});
```

Invalid sort test:

```php
it('falls back to newest for invalid sort query string', function () {
    Livewire::withQueryParams(['sort' => 'invalid'])
        ->test(FeedPage::class)
        ->assertSet('sort', 'newest');
});
```

### Implementation

В `FeedPage`:

```php
#[Url(as: 'sort', except: 'newest')]
public string $sort = 'newest';
```

Добавить normalization:

```php
public function mount(): void
{
    $this->normalizeSort();
}

public function updatedSort(): void
{
    $this->normalizeSort();
}

private function normalizeSort(): void
{
    if (! in_array($this->sort, ['newest', 'top', 'hot'], true)) {
        $this->sort = 'newest';
    }
}
```

Если Livewire lifecycle требует другой подход — использовать актуальный, но сохранить поведение.

### Acceptance criteria

- `/?sort=top` сортирует ленту по top.
- `/?sort=hot` сортирует ленту по hot.
- Default newest не должен обязательно появляться в URL.
- Invalid sort fallback = newest.
- Тесты проходят.
- Search/category/sort могут работать вместе.

### Definition of Done

- URL sync для sort добавлен.
- Invalid sort guard добавлен.
- Все Phase 9 tests проходят.
- `composer test` проходит.
- `npm run build` проходит.
- Коммит: `RG-201: Add URL query string sync for sort`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
tests/Feature/Livewire/FeedPageQueryStringTest.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 9 Completion Criteria

Phase 9 завершена, когда:

```txt
- RG-186–RG-201 выполнены;
- SearchBar component существует;
- SearchBar связан с FeedPage/PostFeed;
- search фильтрует feed через FeedQuery;
- search input debounced;
- CategoryTabs component существует;
- CategoryTabs связан с FeedPage/PostFeed;
- category фильтрует feed по tag slug;
- CategoryTabs UI оформлен;
- SortDropdown component существует;
- SortDropdown связан с FeedPage/PostFeed;
- sort меняет порядок feed;
- SortDropdown UI оформлен;
- Alpine dropdown behavior работает;
- search/category/sort синхронизируются с URL;
- invalid sort безопасно fallback-ится;
- search/category/sort могут работать вместе;
- composer test проходит;
- npm run build проходит.
```

---

# 11. Что нельзя делать в Phase 9

Без отдельной задачи нельзя:

```txt
- делать upload modal;
- делать voting;
- делать comments;
- делать post drawer;
- делать report modal;
- делать pagination UI;
- делать infinite scroll;
- делать category admin;
- создавать tags из UI;
- добавлять autocomplete;
- добавлять search suggestions;
- добавлять Scout/Meilisearch;
- добавлять Redis/cache layer;
- менять FeedQuery semantics без теста;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```

---

# 12. Recommended Execution Order

```txt
RG-186 Create SearchBar Livewire component
RG-187 Test SearchBar updates feed search state
RG-188 Wire SearchBar to PostFeed
RG-189 Add debounced search input
RG-190 Create CategoryTabs Livewire component
RG-191 Test category selection updates feed filter
RG-192 Wire CategoryTabs to PostFeed
RG-193 Render category tabs UI
RG-194 Create SortDropdown Livewire component
RG-195 Test sort selection updates feed sort
RG-196 Wire SortDropdown to PostFeed
RG-197 Render sort dropdown UI
RG-198 Add Alpine dropdown behavior to SortDropdown
RG-199 Add URL query string sync for search
RG-200 Add URL query string sync for category
RG-201 Add URL query string sync for sort
```

---

# 13. Release

После завершения Phase 9:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.0-phase9-search-categories-sorting-ui
git push -u origin release/v0.1.0-phase9-search-categories-sorting-ui
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.0-phase9-search-categories-sorting-ui -m "RateGuru Phase 9 search categories sorting UI"
git push origin v0.1.0-phase9-search-categories-sorting-ui
```
