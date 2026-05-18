# RateGuru — Phase 11 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 11 — Post Detail / Drawer**  
Диапазон задач: **RG-226 → RG-241**  
Основа нумерации: исходный atomic backlog, где Phase 11 начинается с задачи 226 и заканчивается задачей 241.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 11 соответствует исходному блоку:

```txt
Phase 11 — Post Detail / Drawer
```

Правильный диапазон Phase 11:

```txt
RG-226 — Create PostDrawer Livewire component
RG-227 — Test PostDrawer renders selected post
RG-228 — Add Alpine drawer shell
RG-229 — Open drawer from PostCard click
RG-230 — Close drawer with close button
RG-231 — Close drawer with escape key
RG-232 — Render large post image in drawer
RG-233 — Render title and description in drawer
RG-234 — Render author metadata in drawer
RG-235 — Render vote summary in drawer
RG-236 — Render comments slot in drawer
RG-237 — Add drawer loading state
RG-238 — Add drawer not found state
RG-239 — Add mobile drawer behavior
RG-240 — Add desktop right-side drawer behavior
RG-241 — Compare drawer UI with design checklist
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 11

Phase 11 добавляет детальный просмотр поста в drawer-панели.

После Phase 11 пользователь должен уметь:

```txt
- кликнуть по PostCard;
- открыть drawer с деталями выбранного поста;
- увидеть большое изображение;
- увидеть title и description;
- увидеть author metadata;
- увидеть vote summary;
- увидеть placeholder/slot для comments;
- закрыть drawer кнопкой;
- закрыть drawer клавишей Escape;
- пользоваться drawer на mobile;
- пользоваться right-side drawer на desktop.
```

Phase 11 — это **detail presentation shell**, а не полноценная интерактивность.
---

# 3. Scope Phase 11

## Входит

```txt
- PostDrawer Livewire component;
- selected post rendering;
- Alpine drawer shell;
- open drawer from PostCard click;
- close button;
- Escape close;
- large post image;
- title/description;
- author metadata;
- read-only vote summary;
- comments slot/placeholder;
- loading state;
- not found state;
- mobile drawer behavior;
- desktop right-side drawer behavior;
- design checklist review.
```

## Не входит

```txt
- standalone post show route;
- voting actions;
- interactive up/down buttons;
- origin voting actions;
- cuisine voting actions;
- comment creation;
- comments backend;
- report modal;
- moderation buttons;
- share panel;
- SEO/OpenGraph;
- related posts;
- API endpoint.
```

Post show page будет Phase 12.  
Voting начнётся Phase 13+.  
Comments backend/UI — Phase 17/18.  
Reports — Phase 19/20.  
Share/URL behavior — Phase 32.
---

# 4. Architecture Rules

## 4.1. Drawer state should live near FeedPage

Для MVP состояние drawer лучше держать на уровне `FeedPage`:

```txt
selectedPostId
drawerOpen
```

Поток:

```txt
PostCard click
  ↓
FeedPage receives/selects post id
  ↓
PostDrawer receives selectedPostId/open state
  ↓
PostDrawer loads and renders post
```

Это лучше, чем заставлять каждый PostCard знать про drawer internals.

## 4.2. PostDrawer is read-only in Phase 11

`PostDrawer` не должен:

```txt
- голосовать;
- писать комментарии;
- отправлять reports;
- менять post;
- модерировать post;
- сохранять state в database.
```

Он только отображает выбранный published post.

## 4.3. Hidden/pending/rejected posts must not be visible

Drawer должен загружать только public visible post:

```txt
status = published
```

Если передан id hidden/pending/rejected post — показать not found state или 404-like state в drawer.  
Не показывать скрытый контент обычному пользователю.

Модераторский доступ к hidden posts — отдельные будущие задачи.

## 4.4. Comments slot is placeholder

Phase 11 содержит задачу:

```txt
RG-236 — Render comments slot in drawer
```

Но comments backend/UI ещё не готовы. Поэтому в Phase 11:

```txt
- рендерим slot/placeholder;
- не создаём CommentsSection;
- не создаём CommentForm;
- не показываем реальные comments list, если это потребует логики.
```

Допустимый текст:

```txt
Comments will appear here.
```

или slot area:

```blade
{{ $comments ?? '' }}
```

## 4.5. Vote summary is read-only

Phase 11 содержит:

```txt
RG-235 — Render vote summary in drawer
```

Это только отображение counters:

```txt
upvotes/downvotes/net score
homemade_votes_count / restaurant_votes_count
possibly cuisine_truth/cuisine votes placeholder
```

Interactive buttons будут позже.

## 4.6. Use existing UI components

Drawer должен использовать Phase 1 компоненты:

```txt
x-ui.drawer
x-ui.card
x-ui.button
x-ui.avatar
x-ui.badge
x-ui.skeleton
x-ui.error-message
x-ui.image-placeholder
```

Нельзя снова писать новый drawer shell вручную, если `x-ui.drawer` уже существует.
---

# 5. Design Constraints

Drawer должен быть похож на исходный PlateRate reference:

```txt
- dark right-side panel;
- large image top/hero;
- clear title/description block;
- compact author row;
- vote summary panels;
- comments area below;
- rounded dark surfaces;
- purple accents;
- mobile-safe full/bottom drawer behavior.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-8-feed-ui-review.md
docs/design/phase-10-upload-ui-review.md
```

В конце Phase 11 обязательно создать:

```txt
docs/design/phase-11-drawer-ui-review.md
```
---

# 6. GitFlow для Phase 11

## Base branch

Все задачи Phase 11 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-226-create-post-drawer-livewire-component
feature/RG-229-open-drawer-from-post-card-click
feature/RG-241-compare-drawer-ui-with-design-checklist
```

## Commit format

```txt
RG-226: Create PostDrawer Livewire component
RG-229: Open drawer from PostCard click
RG-241: Compare drawer UI with design checklist
```

## Release branch

После выполнения `RG-226`–`RG-241`:

```txt
release/v0.1.2-phase11-post-detail-drawer
```

## Tag

После merge release branch в `main`:

```txt
v0.1.2-phase11-post-detail-drawer
```
---

# 7. TDD Rules for Phase 11

## Для Livewire drawer

Пишем Livewire tests:

```txt
- component renders;
- selected published post renders;
- hidden/pending post does not render;
- not found state renders.
```

## Для PostCard click

Пишем Livewire/Blade tests:

```txt
- PostCard has clickable trigger;
- click dispatches/selects post id;
- FeedPage opens drawer state.
```

## Для Alpine drawer

Unit-тестом проверяем markup:

```txt
- x-data;
- x-show;
- @keydown.escape.window;
- close button;
- data-testid markers.
```

Фактическое open/close проверяется вручную.

## Для visual layout

Обязательно:

```txt
- mobile manual check;
- desktop manual check;
- design checklist document.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Tests
Type: Test / Feature / Component / Wiring / Layout / Docs
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
---

# 9. Phase 11 Atomic Tasks
---

## RG-226 — Create PostDrawer Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-226-create-post-drawer-livewire-component`  
**Base branch:** develop
**Depends on:** RG-225

### Goal

Создать Livewire-компонент `PostDrawer`.

### TDD step

Livewire test:

```php
it('can render post drawer component', function () {
    Livewire::test(PostDrawer::class)
        ->assertStatus(200);
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать:

```bash
php artisan make:livewire Feed/PostDrawer
```

Файлы:

```txt
app/Livewire/Feed/PostDrawer.php
resources/views/livewire/feed/post-drawer.blade.php
```

Минимальный class:

```php
final class PostDrawer extends Component
{
    public ?int $postId = null;

    public function render(): View
    {
        return view('livewire.feed.post-drawer', [
            'post' => null,
        ]);
    }
}
```

Минимальный view:

```blade
<div data-testid="post-drawer">
    Post drawer
</div>
```

Пока не загружать post. Это RG-227.

### Acceptance criteria

- `PostDrawer` component существует.
- Component рендерится.
- Есть public `postId`.
- View содержит stable marker `data-testid="post-drawer"`.
- Нет query/render logic кроме skeleton.
- Тест проходит.

### Definition of Done

- Тест написан первым.
- Component создан.
- Тест проходит.
- Коммит: `RG-226: Create PostDrawer Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/PostDrawer.php
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-227 — Test PostDrawer Renders Selected Post

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-227-test-post-drawer-renders-selected-post`  
**Base branch:** develop
**Depends on:** RG-226

### Goal

Написать тест: PostDrawer рендерит выбранный published post.

### TDD step

Livewire test:

```php
it('renders selected published post', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});
```

Добавить safety test:

```php
it('does not render hidden post', function () {
    $post = Post::factory()->hidden()->create([
        'title' => 'Hidden Dish',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('Hidden Dish')
        ->assertSee('Post not found');
});
```

### Implementation

В `PostDrawer` render:

```php
$post = null;

if ($this->postId !== null) {
    $post = Post::query()
        ->published()
        ->with(['user', 'tags'])
        ->find($this->postId);
}
```

View:

```blade
@if($post)
    {{ $post->title }}
    {{ $post->description }}
@elseif($postId)
    Post not found
@else
    Select a post
@endif
```

Не использовать raw status condition в view. Query должен быть в component.

### Acceptance criteria

- Published selected post рендерится.
- Hidden/pending/rejected post не рендерится.
- Not found state виден для invalid/hidden id.
- User/tags eager loaded.
- Тесты проходят.

### Definition of Done

- Тест написан первым.
- Query/render logic добавлены.
- Тесты проходят.
- Коммит: `RG-227: Test PostDrawer renders selected post`

### Files likely touched

```txt
app/Livewire/Feed/PostDrawer.php
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-228 — Add Alpine Drawer Shell

**Area:** UI / Alpine / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-228-add-alpine-drawer-shell`  
**Base branch:** develop
**Depends on:** RG-227

### Goal

Добавить Alpine drawer shell на FeedPage и встроить туда PostDrawer.

### TDD step

HTTP/markup test:

```php
it('renders alpine drawer shell on feed page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="post-detail-drawer-shell"', false)
        ->assertSee('x-data', false)
        ->assertSee('drawerOpen', false)
        ->assertSee('x-show', false);
});
```

### Implementation

В `feed-page.blade.php` добавить shell:

```blade
<div
    x-data="{ drawerOpen: false }"
    data-testid="post-detail-drawer-shell"
>
    {{-- feed content here --}}

    <div x-show="drawerOpen" x-cloak>
        <x-ui.drawer title="Dish details">
            <livewire:feed.post-drawer :post-id="$selectedPostId ?? null" />
        </x-ui.drawer>
    </div>
</div>
```

Но state `selectedPostId` лучше держать в FeedPage class:

```php
public ?int $selectedPostId = null;
```

В этой задаче можно добавить property, но не wiring click yet.

### Acceptance criteria

- FeedPage содержит drawer shell.
- Drawer shell использует Alpine `x-data`.
- Drawer shell uses `x-show`.
- Drawer использует `x-ui.drawer`.
- PostDrawer встроен внутрь shell.
- Shell не открыт по умолчанию.
- Markup test проходит.

### Definition of Done

- Тест написан.
- Drawer shell добавлен.
- Тест проходит.
- Коммит: `RG-228: Add Alpine drawer shell`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-229 — Open Drawer From PostCard Click

**Area:** UI / Livewire / Alpine  
**Type:** Wiring  
**Priority:** P0  
**Branch:** `feature/RG-229-open-drawer-from-post-card-click`  
**Base branch:** develop
**Depends on:** RG-228

### Goal

Клик по PostCard должен открывать drawer выбранного post.

### TDD step

Livewire/Blade tests:

```php
it('post card dispatches open drawer event with post id', function () {
    $post = Post::factory()->published()->make(['id' => 123]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', [
        'post' => $post,
    ]);

    expect($html)->toContain('open-post-drawer');
    expect($html)->toContain('123');
});
```

Page-level test:

```php
it('selects post for drawer on feed page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Drawer Dish',
    ]);

    Livewire::test(FeedPage::class)
        ->call('openPostDrawer', $post->id)
        ->assertSet('selectedPostId', $post->id)
        ->assertDispatched('post-drawer-opened');
});
```

### Implementation

В `FeedPage` class:

```php
public ?int $selectedPostId = null;

public function openPostDrawer(int $postId): void
{
    $this->selectedPostId = $postId;
    $this->dispatch('post-drawer-opened');
}
```

В `PostCard` добавить click trigger:

```blade
<article
    role="button"
    tabindex="0"
    wire:click="$parent.openPostDrawer({{ $post->id }})"
>
```

Но `wire:click="$parent..."` из Blade component может быть хрупким, если component используется вне Livewire.

Лучше PostCard dispatches browser/Livewire event:

```blade
<button
    type="button"
    wire:click="$dispatch('open-post-drawer', { postId: {{ $post->id }} })"
>
```

В FeedPage listen:

```php
#[On('open-post-drawer')]
public function openPostDrawer(int $postId): void
{
    ...
}
```

В Alpine shell:

```blade
@post-drawer-opened.window="drawerOpen = true"
```

### Acceptance criteria

- PostCard clickable.
- Click passes post id.
- FeedPage selectedPostId updates.
- Drawer opens after event.
- PostCard remains accessible by keyboard if role/button used.
- Тесты проходят.

### Definition of Done

- Tests написаны.
- Click wiring реализован.
- Drawer opens manually.
- Коммит: `RG-229: Open drawer from PostCard click`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
resources/views/components/feed/post-card.blade.php
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-230 — Close Drawer With Close Button

**Area:** UI / Alpine  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-230-close-drawer-with-close-button`  
**Base branch:** develop
**Depends on:** RG-229

### Goal

Добавить close button для drawer.

### TDD step

Markup test:

```php
it('renders drawer close button', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="post-drawer-close"', false)
        ->assertSee('drawerOpen = false', false);
});
```

### Implementation

Если `x-ui.drawer` уже имеет стандартную close button slot, использовать его.

Пример:

```blade
<x-ui.drawer title="Dish details">
    <x-slot:close>
        <button
            type="button"
            data-testid="post-drawer-close"
            @click="drawerOpen = false"
        >
            Close
        </button>
    </x-slot:close>

    <livewire:feed.post-drawer :post-id="$selectedPostId" :key="'drawer-'.$selectedPostId" />
</x-ui.drawer>
```

Если `x-ui.drawer` не поддерживает close slot, добавить close button рядом в shell.

### Acceptance criteria

- Drawer имеет close button.
- Close button sets `drawerOpen = false`.
- Button accessible label exists.
- Test проходит.
- Manual click closes drawer.

### Definition of Done

- Close button добавлен.
- Тест проходит.
- Manual check выполнен.
- Коммит: `RG-230: Close drawer with close button`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
resources/views/components/ui/drawer.blade.php
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-231 — Close Drawer With Escape Key

**Area:** UI / Alpine  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-231-close-drawer-with-escape-key`  
**Base branch:** develop
**Depends on:** RG-230

### Goal

Закрывать drawer по клавише Escape.

### TDD step

Markup test:

```php
it('closes drawer with escape key markup', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('@keydown.escape.window', false)
        ->assertSee('drawerOpen = false', false);
});
```

### Implementation

На wrapper drawer shell:

```blade
<div
    x-data="{ drawerOpen: false }"
    @keydown.escape.window="drawerOpen = false"
>
```

Если нужно сбрасывать selectedPostId — можно dispatch Livewire call позже, но в Phase 11 достаточно закрыть UI.

Опционально:

```blade
@keydown.escape.window="
    drawerOpen = false;
    $wire.closePostDrawer();
"
```

и в FeedPage:

```php
public function closePostDrawer(): void
{
    $this->selectedPostId = null;
}
```

Но это может быть лишним.  
Рекомендация: добавить `closePostDrawer()` и использовать его, чтобы selected state не висел бесконечно.

### Acceptance criteria

- Escape listener есть.
- Escape closes drawer.
- selectedPostId сбрасывается, если реализован closePostDrawer.
- Test проходит.
- Manual check выполнен.

### Definition of Done

- Escape behavior добавлен.
- Тест проходит.
- Коммит: `RG-231: Close drawer with escape key`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Routes/FeedRouteTest.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-232 — Render Large Post Image In Drawer

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-232-render-large-post-image-in-drawer`  
**Base branch:** develop
**Depends on:** RG-227

### Goal

Отобразить большое изображение поста в drawer.

### TDD step

Livewire test:

```php
it('renders large post image in drawer', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('/storage/posts/1/dish.jpg')
        ->assertSee('alt="Dish"', false);
});
```

Fallback test:

```php
it('renders image placeholder when drawer post has no image', function () {
    $post = Post::factory()->published()->create([
        'image_url' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Image preview');
});
```

### Implementation

В `post-drawer.blade.php`:

```blade
@if($post->image_url)
    <img
        src="{{ $post->image_url }}"
        alt="{{ $post->title }}"
        class="aspect-[4/3] w-full rounded-rgCard object-cover"
    >
@else
    <x-ui.image-placeholder label="Image preview" ratio="video" />
@endif
```

Drawer image должен быть крупнее, чем PostCard image.

### Acceptance criteria

- Large image рендерится, если image_url есть.
- Alt = title.
- Placeholder рендерится, если image_url отсутствует.
- Image has stable ratio.
- Тесты проходят.

### Definition of Done

- Тесты написаны.
- Image area добавлена.
- Тесты проходят.
- Коммит: `RG-232: Render large post image in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-233 — Render Title And Description In Drawer

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-233-render-title-and-description-in-drawer`  
**Base branch:** develop
**Depends on:** RG-232

### Goal

Отобразить title и description в drawer.

### TDD step

Livewire test:

```php
it('renders drawer post title and description', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});
```

Optional description test:

```php
it('does not break when drawer post description is missing', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'description' => null,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Dish')
        ->assertDontSee('Creamy pasta');
});
```

### Implementation

В drawer view:

```blade
<section>
    <h2>{{ $post->title }}</h2>

    @if($post->description)
        <p>{{ $post->description }}</p>
    @endif
</section>
```

В drawer можно показывать полное description, не `Str::limit`, потому что это detail view.

### Acceptance criteria

- Title отображается.
- Description отображается, если есть.
- Missing description не ломает UI.
- Текст читаем на dark background.
- Тесты проходят.

### Definition of Done

- Тесты написаны.
- Title/description block добавлен.
- Тесты проходят.
- Коммит: `RG-233: Render title and description in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-234 — Render Author Metadata In Drawer

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-234-render-author-metadata-in-drawer`  
**Base branch:** develop
**Depends on:** RG-233

### Goal

Отобразить author metadata в drawer.

### TDD step

Livewire test:

```php
it('renders drawer author metadata', function () {
    $user = User::factory()->create([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->for($user)->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Demo Chef')
        ->assertSee('@demo_chef');
});
```

Также можно проверить date:

```php
->assertSee($post->published_at->format('M'))
```

Но date formatting может быть хрупким. Лучше добавить stable label, если нужно.

### Implementation

В drawer view:

```blade
<div class="flex items-center gap-3">
    <x-ui.avatar :src="$post->user?->avatar_url" :name="$post->user?->name ?? 'User'" />

    <div>
        <div>{{ $post->user?->name ?? 'Unknown user' }}</div>

        @if($post->user?->username)
            <div>{{ '@' . $post->user->username }}</div>
        @endif

        @if($post->published_at)
            <div>{{ $post->published_at->diffForHumans() }}</div>
        @endif
    </div>
</div>
```

Не создавать profile link, если profile page ещё нет. Можно оставить placeholder без ссылки.

### Acceptance criteria

- Author name отображается.
- Username отображается, если есть.
- Avatar component используется.
- Published timestamp отображается, если есть.
- Missing user relation не ломает drawer.
- Тест проходит.

### Definition of Done

- Тест написан.
- Author metadata block добавлен.
- Тест проходит.
- Коммит: `RG-234: Render author metadata in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-235 — Render Vote Summary In Drawer

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-235-render-vote-summary-in-drawer`  
**Base branch:** develop
**Depends on:** RG-234

### Goal

Отобразить read-only vote summary в drawer.

### TDD step

Livewire test:

```php
it('renders drawer vote summary', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
        'homemade_votes_count' => 7,
        'restaurant_votes_count' => 5,
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Score')
        ->assertSee('9')
        ->assertSee('Homemade')
        ->assertSee('7')
        ->assertSee('Restaurant')
        ->assertSee('5');
});
```

### Implementation

В drawer view добавить summary cards:

```blade
@php
    $score = $post->upvotes_count - $post->downvotes_count;
@endphp

<x-ui.card padding="sm">
    <span>Score</span>
    <strong>{{ $score }}</strong>
</x-ui.card>

<x-ui.card padding="sm">
    <span>Homemade</span>
    <strong>{{ $post->homemade_votes_count }}</strong>
</x-ui.card>

<x-ui.card padding="sm">
    <span>Restaurant</span>
    <strong>{{ $post->restaurant_votes_count }}</strong>
</x-ui.card>
```

Можно добавить comments_count display, но comments slot будет RG-236.

Важно: это read-only. Не добавлять buttons.

### Acceptance criteria

- Net score отображается.
- Up/down counters или net score понятны.
- Homemade count отображается.
- Restaurant count отображается.
- Нет interactive vote buttons.
- Тест проходит.

### Definition of Done

- Тест написан.
- Vote summary block добавлен.
- Тест проходит.
- Коммит: `RG-235: Render vote summary in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-236 — Render Comments Slot In Drawer

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-236-render-comments-slot-in-drawer`  
**Base branch:** develop
**Depends on:** RG-235

### Goal

Добавить comments slot/placeholder в drawer.

### TDD step

Livewire test:

```php
it('renders comments slot placeholder in drawer', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Comments')
        ->assertSee('Comments will appear here');
});
```

### Implementation

В drawer view:

```blade
<section data-testid="drawer-comments-slot">
    <div class="flex items-center justify-between">
        <h3>Comments</h3>
        <span>{{ $post->comments_count }}</span>
    </div>

    <x-ui.empty-state
        title="Comments will appear here"
        description="Comment UI will be added in a later phase."
    />
</section>
```

Не создавать `CommentsSection`.  
Не выводить реальные comments, если это тянет Phase 18.  
Можно показать только `comments_count`.

### Acceptance criteria

- Comments section visible.
- Comments count visible.
- Placeholder честно указывает, что UI будет позже.
- Не создаётся comments backend/UI.
- Тест проходит.

### Definition of Done

- Тест написан.
- Comments placeholder добавлен.
- Тест проходит.
- Коммит: `RG-236: Render comments slot in drawer`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-237 — Add Drawer Loading State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-237-add-drawer-loading-state`  
**Base branch:** develop
**Depends on:** RG-236

### Goal

Добавить loading skeleton для drawer.

### TDD step

Markup/Livewire test:

```php
it('has drawer loading state markup', function () {
    Livewire::test(PostDrawer::class)
        ->assertSee('data-testid="post-drawer-loading"', false)
        ->assertSee('wire:loading', false);
});
```

### Implementation

В drawer view:

```blade
<div wire:loading data-testid="post-drawer-loading">
    <x-ui.skeleton shape="block" height="16rem" />
    <x-ui.skeleton shape="line" width="70%" />
    <x-ui.skeleton shape="line" width="45%" />
</div>

<div wire:loading.remove>
    {{-- existing drawer content --}}
</div>
```

Если PostDrawer не делает async actions, loading state всё равно будет полезен при future updates/open events.

### Acceptance criteria

- Loading skeleton markup есть.
- Используется `x-ui.skeleton`.
- Есть `wire:loading`.
- Основной контент скрывается при loading или не конфликтует.
- Тест проходит.

### Definition of Done

- Loading state добавлен.
- Тест проходит.
- Коммит: `RG-237: Add drawer loading state`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-238 — Add Drawer Not Found State

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-238-add-drawer-not-found-state`  
**Base branch:** develop
**Depends on:** RG-237

### Goal

Добавить явный not found state для invalid/unpublished post id.

### TDD step

Livewire test:

```php
it('renders not found state for missing post', function () {
    Livewire::test(PostDrawer::class, ['postId' => 999999])
        ->assertSee('Post not found')
        ->assertSee('This post is unavailable');
});
```

Unpublished test:

```php
it('renders not found state for pending post', function () {
    $post = Post::factory()->pending()->create([
        'title' => 'Pending Hidden',
    ]);

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('Post not found')
        ->assertDontSee('Pending Hidden');
});
```

### Implementation

В drawer view:

```blade
@elseif($postId)
    <x-ui.error-message
        title="Post not found"
        message="This post is unavailable or no longer public."
    />
@else
    <x-ui.empty-state
        title="Select a post"
        description="Post details will appear here."
    />
@endif
```

Не показывать real title hidden/pending post.

### Acceptance criteria

- Invalid id показывает not found.
- Pending/hidden/rejected id показывает not found.
- Hidden title не leakage.
- Empty selected state отдельно от not found.
- Тесты проходят.

### Definition of Done

- Тесты написаны.
- Not found state добавлен.
- Тесты проходят.
- Коммит: `RG-238: Add drawer not found state`

### Files likely touched

```txt
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-239 — Add Mobile Drawer Behavior

**Area:** UI / Layout  
**Type:** Layout  
**Priority:** P0  
**Branch:** `feature/RG-239-add-mobile-drawer-behavior`  
**Base branch:** develop
**Depends on:** RG-238

### Goal

Сделать drawer usable на mobile.

### TDD step

No reliable direct test for mobile visual behavior.

Добавить markup test на responsive classes:

```php
it('has mobile drawer behavior classes', function () {
    $this->get('/')
        ->assertSee('data-testid="post-detail-drawer-shell"', false)
        ->assertSee('bottom-0', false);
});
```

Не делать тест слишком хрупким, но `data-testid` и ключевые классы допустимы.

### Implementation

Проверить/обновить `x-ui.drawer` или shell:

Mobile behavior:

```txt
- fixed inset-x-0 bottom-0;
- max-height примерно 90vh;
- rounded top corners;
- overflow-y-auto;
- safe padding;
- backdrop behind;
- close button reachable.
```

Пример классов:

```txt
fixed inset-x-0 bottom-0 z-50 max-h-[90vh] overflow-y-auto rounded-t-rgCard bg-rg-surface
md:...
```

Если `x-ui.drawer` уже поддерживает responsive mode, использовать props/classes.

Обновить:

```txt
docs/design/phase-11-drawer-ui-review.md
```

секцией `Mobile pass`.

### Acceptance criteria

- Drawer usable на mobile width.
- Не выходит за экран.
- Content scrolls внутри drawer.
- Close button доступен.
- Backdrop есть.
- Manual mobile check documented.
- `npm run build` проходит.

### Definition of Done

- Mobile behavior добавлен.
- Manual check записан.
- Коммит: `RG-239: Add mobile drawer behavior`

### Files likely touched

```txt
resources/views/components/ui/drawer.blade.php
resources/views/livewire/feed/feed-page.blade.php
docs/design/phase-11-drawer-ui-review.md
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-240 — Add Desktop Right-Side Drawer Behavior

**Area:** UI / Layout  
**Type:** Layout  
**Priority:** P0  
**Branch:** `feature/RG-240-add-desktop-right-side-drawer-behavior`  
**Base branch:** develop
**Depends on:** RG-239

### Goal

Сделать desktop drawer правой боковой панелью.

### TDD step

Markup test на desktop classes:

```php
it('has desktop right side drawer behavior classes', function () {
    $this->get('/')
        ->assertSee('md:right-0', false)
        ->assertSee('md:inset-y-0', false);
});
```

Если классы отличаются, тестировать `data-testid` и одну-две stable classes.

### Implementation

Desktop behavior:

```txt
- fixed right-0 top/bottom;
- width md:w-[28rem] / lg:w-[32rem];
- full height;
- border-left;
- overflow-y-auto;
- backdrop remains;
```

Пример:

```txt
md:inset-y-0 md:right-0 md:left-auto md:h-full md:max-h-none md:w-[30rem] md:rounded-none md:border-l
```

Не делать draggable/resizable drawer.

Обновить:

```txt
docs/design/phase-11-drawer-ui-review.md
```

секцией `Desktop pass`.

### Acceptance criteria

- Desktop drawer appears from right side.
- Width controlled.
- Content scrolls.
- Feed remains behind backdrop.
- No horizontal overflow.
- Manual desktop check documented.
- `npm run build` проходит.

### Definition of Done

- Desktop behavior добавлен.
- Manual check записан.
- Коммит: `RG-240: Add desktop right-side drawer behavior`

### Files likely touched

```txt
resources/views/components/ui/drawer.blade.php
resources/views/livewire/feed/feed-page.blade.php
docs/design/phase-11-drawer-ui-review.md
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-241 — Compare Drawer UI With Design Checklist

**Area:** UI / Docs / QA  
**Type:** Docs / QA  
**Priority:** P0  
**Branch:** `feature/RG-241-compare-drawer-ui-with-design-checklist`  
**Base branch:** develop
**Depends on:** RG-240

### Goal

Финально сверить drawer UI с design contract, исходным reference и checklist.

### TDD step

No direct test — visual QA/documentation task.

Запустить:

```bash
composer test
npm run build
```

Проверить вручную:

```txt
/
 /dev/ui-kit
click PostCard
open drawer
close button
escape close
large image
missing image fallback
hidden post not found state
mobile drawer
desktop right-side drawer
loading state markup
comments placeholder
```

### Implementation

Создать/обновить:

```txt
docs/design/phase-11-drawer-ui-review.md
```

Содержимое:

```md
# Phase 11 Drawer UI Review

## Reference checked
- [ ] docs/design/reference/original/PlateRate.html
- [ ] docs/design/reference/screenshots/
- [ ] docs/design/design-contract.md
- [ ] docs/design/ui-review-checklist.md
- [ ] /dev/ui-kit
- [ ] docs/design/phase-8-feed-ui-review.md
- [ ] docs/design/phase-10-upload-ui-review.md

## Drawer shell
- [ ] Dark surface preserved
- [ ] Backdrop exists
- [ ] Close button exists
- [ ] Escape close works
- [ ] Mobile drawer checked
- [ ] Desktop right-side drawer checked

## Content
- [ ] Large image renders
- [ ] Missing image placeholder works
- [ ] Title renders
- [ ] Description renders
- [ ] Author metadata renders
- [ ] Vote summary renders read-only
- [ ] Comments placeholder renders
- [ ] Not found state works
- [ ] Loading state exists

## Known deviations
- ...
```

Если есть отклонения от исходного макета, записать честно:

```txt
- что отличается;
- почему допустимо;
- нужна ли будущая задача.
```

### Acceptance criteria

- `docs/design/phase-11-drawer-ui-review.md` существует.
- Checklist заполнен.
- Known deviations описаны.
- `/` проверен вручную.
- `/dev/ui-kit` проверен вручную.
- `composer test` проходит.
- `npm run build` проходит.

### Definition of Done

- UI review документ создан.
- Все tests/build проходят.
- Коммит: `RG-241: Compare drawer UI with design checklist`

### Files likely touched

```txt
docs/design/phase-11-drawer-ui-review.md
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

# 10. Phase 11 Completion Criteria

Phase 11 завершена, когда:

```txt
- RG-226–RG-241 выполнены;
- PostDrawer Livewire component существует;
- selected published post рендерится;
- hidden/pending/rejected posts не показываются;
- drawer shell встроен в FeedPage;
- PostCard click открывает drawer;
- close button закрывает drawer;
- Escape закрывает drawer;
- large image отображается;
- missing image placeholder работает;
- title/description отображаются;
- author metadata отображается;
- vote summary отображается read-only;
- comments placeholder отображается;
- loading state есть;
- not found state есть;
- mobile drawer behavior проверен;
- desktop right-side drawer behavior проверен;
- design checklist review создан;
- composer test проходит;
- npm run build проходит.
```
---

# 11. Что нельзя делать в Phase 11

Без отдельной задачи нельзя:

```txt
- добавлять posts.show route;
- делать standalone post page;
- делать voting actions;
- делать interactive vote buttons;
- делать origin/cuisine voting;
- делать comments backend;
- делать comment form;
- делать comments list component;
- делать report modal;
- делать moderation buttons;
- делать share panel;
- делать SEO/OpenGraph;
- делать related posts;
- делать API endpoint;
- добавлять Redis/cache layer;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-226 Create PostDrawer Livewire component
RG-227 Test PostDrawer renders selected post
RG-228 Add Alpine drawer shell
RG-229 Open drawer from PostCard click
RG-230 Close drawer with close button
RG-231 Close drawer with escape key
RG-232 Render large post image in drawer
RG-233 Render title and description in drawer
RG-234 Render author metadata in drawer
RG-235 Render vote summary in drawer
RG-236 Render comments slot in drawer
RG-237 Add drawer loading state
RG-238 Add drawer not found state
RG-239 Add mobile drawer behavior
RG-240 Add desktop right-side drawer behavior
RG-241 Compare drawer UI with design checklist
```
---

# 13. Release

После завершения Phase 11:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.2-phase11-post-detail-drawer
git push -u origin release/v0.1.2-phase11-post-detail-drawer
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.2-phase11-post-detail-drawer -m "RateGuru Phase 11 post detail drawer"
git push origin v0.1.2-phase11-post-detail-drawer
```
