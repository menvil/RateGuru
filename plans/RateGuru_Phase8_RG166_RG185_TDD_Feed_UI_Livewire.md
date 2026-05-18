# RateGuru — Phase 8 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 8 — Feed UI with Livewire**  
Диапазон задач: **RG-166 → RG-185**  
Основа нумерации: исходный atomic backlog, где Phase 8 начинается с задачи 166 и заканчивается задачей 185.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**

---

# 1. Главная фиксация

Phase 8 соответствует исходному блоку:

```txt
Phase 8 — Feed UI with Livewire
```

Правильный диапазон Phase 8:

```txt
RG-166 — Create FeedPage Livewire component
RG-167 — Test FeedPage renders
RG-168 — Add feed route
RG-169 — Render base feed layout
RG-170 — Create PostFeed Livewire component
RG-171 — Test PostFeed shows published post title
RG-172 — Render published posts in PostFeed
RG-173 — Create PostCard Blade component
RG-174 — Render PostCard in UI kit
RG-175 — Render PostCard in feed
RG-176 — Add image area to PostCard
RG-177 — Add author area to PostCard
RG-178 — Add title area to PostCard
RG-179 — Add stats area to PostCard
RG-180 — Add empty feed state
RG-181 — Test empty feed state renders
RG-182 — Add loading skeleton to PostFeed
RG-183 — Add mobile feed layout pass
RG-184 — Add desktop feed layout pass
RG-185 — Compare feed UI with design checklist
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

---

# 2. Цель Phase 8

Phase 8 превращает backend-ленту из Phase 7 в первую публичную Livewire-страницу.

После Phase 8 должно быть готово:

```txt
- FeedPage Livewire component;
- route `/` на FeedPage;
- базовый layout главной ленты;
- PostFeed Livewire component;
- PostCard Blade component;
- отображение published posts из FeedQuery;
- image area;
- author area;
- title area;
- stats area;
- empty state;
- loading skeleton;
- mobile layout pass;
- desktop layout pass;
- проверка соответствия design contract.
```

Phase 8 — это первая реальная публичная страница RateGuru, но ещё без интерактивной продуктовой логики.

---

# 3. Scope Phase 8

## Входит

```txt
- Livewire FeedPage;
- Livewire PostFeed;
- Blade PostCard component;
- использование FeedQuery;
- отображение карточек published posts;
- empty/loading states;
- responsive layout;
- UI Kit preview для PostCard;
- visual/design checklist pass.
```

## Не входит

```txt
- search input;
- category tabs;
- sort dropdown;
- pagination UI controls;
- infinite scroll;
- voting;
- Homemade/Restaurant voting;
- cuisine voting;
- comments;
- upload modal;
- post drawer;
- report modal;
- inline moderation;
- notifications;
- API endpoint.
```

Поиск, фильтры и сортировка UI будут в Phase 9.  
Upload UI будет в Phase 10.  
Post drawer будет в Phase 11.  
Voting будет в Phase 13+.

---

# 4. Design Constraints

Phase 8 обязана опираться на Phase 1 design reference.

Перед любой UI-задачей агент должен проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
```

Если этих файлов нет — это проблема предыдущих фаз, которую нельзя молча игнорировать. Нужно остановиться и явно зафиксировать missing prerequisite.

## Визуальная цель Phase 8

Лента должна быть близка к исходному PlateRate-настроению:

```txt
- dark-first background;
- карточная лента;
- rounded dark cards;
- purple accent;
- food image area;
- compact author/meta row;
- stats row;
- mobile-first layout;
- desktop centered/max-width layout.
```

Нельзя делать дефолтный светлый Laravel/Breeze UI.

---

# 5. Architecture Rules

## 5.1. FeedPage is page shell

`FeedPage` отвечает за:

```txt
- page title/header;
- container layout;
- подключение PostFeed;
- будущие места для search/category/sort/upload controls.
```

`FeedPage` не должен напрямую делать сложный query, если есть `PostFeed`.

## 5.2. PostFeed owns feed loading

`PostFeed` отвечает за:

```txt
- вызов FeedQuery;
- получение posts;
- empty state;
- loading skeleton;
- передачу post в PostCard.
```

## 5.3. PostCard is dumb view component

`PostCard` не должен:

```txt
- голосовать;
- открывать drawer;
- делать actions;
- менять post;
- вызывать FeedQuery;
- содержать бизнес-логику.
```

Он только отображает данные.

## 5.4. FeedQuery remains source of read data

Нельзя в Livewire заново писать:

```php
Post::where('status', 'published')->...
```

Нужно использовать `FeedQuery` из Phase 7.

## 5.5. UI components first

PostCard должен использовать базовые UI-компоненты Phase 1:

```txt
x-ui.card
x-ui.badge
x-ui.avatar
x-ui.image-placeholder
x-ui.empty-state
x-ui.skeleton
```

Если компонент отсутствует — не писать хаотичный HTML, а либо использовать существующий, либо зафиксировать missing prerequisite.

---

# 6. GitFlow для Phase 8

## Base branch

Все задачи Phase 8 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-166-create-feed-page-livewire-component
feature/RG-173-create-post-card-blade-component
feature/RG-185-compare-feed-ui-with-design-checklist
```

## Commit format

```txt
RG-166: Create FeedPage Livewire component
RG-173: Create PostCard Blade component
RG-185: Compare feed UI with design checklist
```


## Release branch

После выполнения `RG-166`–`RG-185`:

```txt
release/v0.0.9-phase8-feed-ui-livewire
```

## Tag

После merge release branch в `main`:

```txt
v0.0.9-phase8-feed-ui-livewire
```

---

# 7. TDD Rules for Phase 8

## Для Livewire components

Пишем Livewire tests:

```txt
- component renders;
- component sees expected text;
- component uses expected child components where possible;
- component handles empty state.
```

## Для route

Пишем HTTP feature test:

```txt
GET / returns 200
GET / contains RateGuru/feed marker
```

## Для Blade PostCard

Пишем render tests:

```txt
- title renders;
- image area renders;
- author area renders;
- stats area renders.
```

## Для UI layout pass

Тестами полностью не поймать визуал.  
Обязательно:

```txt
- добавить/обновить UI Kit preview;
- пройти docs/design/ui-review-checklist.md;
- проверить mobile width;
- проверить desktop width;
- сделать manual notes в docs/phase-8-ui-review.md.
```

---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Tests
Type: Test / Feature / Component / Layout / Docs
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
- UI добавлен в UI Kit, если это reusable component
- Проверен design checklist, если это visual task
- Нет логики вне scope задачи
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```

---

# 9. Phase 8 Atomic Tasks

---

## RG-166 — Create FeedPage Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-166-create-feed-page-livewire-component`  
**Base branch:** develop  
**Depends on:** RG-165

### Goal

Создать Livewire-компонент `FeedPage`, который будет page shell для главной ленты.

### TDD step

Сначала Livewire test:

```php
it('can render feed page component', function () {
    Livewire::test(FeedPage::class)
        ->assertStatus(200);
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать компонент:

```bash
php artisan make:livewire Feed/FeedPage
```

Файлы:

```txt
app/Livewire/Feed/FeedPage.php
resources/views/livewire/feed/feed-page.blade.php
```

Минимальный view:

```blade
<div>
    <h1>RateGuru</h1>
</div>
```

Не подключать PostFeed пока. Это будет позже.

### Acceptance criteria

- `FeedPage` Livewire component существует.
- Компонент рендерится.
- View содержит `RateGuru`.
- Нет query logic.
- Нет feed route logic в этой задаче.
- Livewire test проходит.

### Definition of Done

- Тест написан первым.
- Компонент создан.
- Тест проходит.
- Коммит: `RG-166: Create FeedPage Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/FeedPage.php
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-167 — Test FeedPage Renders

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-167-test-feed-page-renders`  
**Base branch:** develop  
**Depends on:** RG-166

### Goal

Добавить более конкретный regression test для `FeedPage`: компонент должен показывать базовый shell ленты.

### TDD step

Livewire test:

```php
it('renders the feed page shell', function () {
    Livewire::test(FeedPage::class)
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});
```

Если текста `Discover dishes` ещё нет, тест должен упасть до обновления view.

### Implementation

Обновить view `feed-page.blade.php`:

```txt
- title: RateGuru
- subtitle: Discover dishes
```

Текст может быть другой, но должен быть стабильным и проверяемым.

### Acceptance criteria

- FeedPage показывает `RateGuru`.
- FeedPage показывает subtitle/intro.
- Livewire test проходит.
- UI остаётся тёмным через app layout, если компонент уже рендерится внутри layout.

### Definition of Done

- Тест добавлен.
- View обновлён минимально.
- Тест проходит.
- Коммит: `RG-167: Test FeedPage renders`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-168 — Add Feed Route

**Area:** Routing / Livewire / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-168-add-feed-route`  
**Base branch:** develop  
**Depends on:** RG-167

### Goal

Подключить главную route `/` к `FeedPage`.

Если в Phase 2 был placeholder route/view, заменить его на Livewire FeedPage.

### TDD step

HTTP feature test:

```php
it('serves feed page on home route', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('RateGuru')
        ->assertSee('Discover dishes');
});
```

Тест должен упасть, если route всё ещё ведёт на старый placeholder или не содержит нужный текст.

### Implementation

В `routes/web.php`:

```php
use App\Livewire\Feed\FeedPage;

Route::get('/', FeedPage::class)->name('feed');
```

Если route `feed` уже был, не создавать дубль. Аккуратно заменить implementation.

### Acceptance criteria

- `GET /` возвращает 200.
- Route name = `feed`.
- Страница рендерит FeedPage.
- Старый placeholder route удалён или заменён.
- Guest может открыть `/`.
- Authenticated user тоже может открыть `/`.

### Definition of Done

- Route test написан.
- Route подключён.
- Тест проходит.
- Коммит: `RG-168: Add feed route`

### Files likely touched

```txt
routes/web.php
tests/Feature/Routes/FeedRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-169 — Render Base Feed Layout

**Area:** UI / Livewire  
**Type:** Layout  
**Priority:** P0  
**Branch:** `feature/RG-169-render-base-feed-layout`  
**Base branch:** develop  
**Depends on:** RG-168

### Goal

Сделать базовый layout главной ленты: header, container, feed area.

### TDD step

Livewire/HTTP test проверяет наличие layout markers:

```php
$this->get('/')
    ->assertSee('Latest dishes')
    ->assertSee('RateGuru');
```

### Implementation

Обновить `feed-page.blade.php`.

Структура:

```blade
<div class="min-h-screen bg-rg-bg text-rg-text">
    <section class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
        <header>
            <h1>RateGuru</h1>
            <p>Discover dishes</p>
        </header>

        <main>
            <section>
                <h2>Latest dishes</h2>
                {{-- PostFeed later --}}
            </section>
        </main>
    </section>
</div>
```

Использовать RateGuru tokens/classes из Phase 1.

Не добавлять search/sort/upload controls.

### Acceptance criteria

- Feed page имеет max-width container.
- Есть header.
- Есть main feed section.
- Есть текст `Latest dishes` или другой стабильный section title.
- Используются dark UI classes.
- Нет PostFeed ещё, если не реализован.
- Тест проходит.

### Definition of Done

- Тест добавлен/обновлён.
- Layout реализован.
- Проверен mobile baseline вручную.
- Коммит: `RG-169: Render base feed layout`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
tests/Feature/Routes/FeedRouteTest.php
tests/Feature/Livewire/FeedPageTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-170 — Create PostFeed Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-170-create-post-feed-livewire-component`  
**Base branch:** develop  
**Depends on:** RG-169

### Goal

Создать Livewire-компонент `PostFeed`, который будет отвечать за получение и отображение списка постов.

### TDD step

Livewire test:

```php
it('can render post feed component', function () {
    Livewire::test(PostFeed::class)
        ->assertStatus(200);
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать:

```bash
php artisan make:livewire Feed/PostFeed
```

Файлы:

```txt
app/Livewire/Feed/PostFeed.php
resources/views/livewire/feed/post-feed.blade.php
```

Skeleton view:

```blade
<div>
    <span>Post feed</span>
</div>
```

Не подключать FeedQuery пока. Это будет RG-172 после теста RG-171.

### Acceptance criteria

- `PostFeed` Livewire component существует.
- Компонент рендерится.
- Пока нет query logic.
- Livewire test проходит.

### Definition of Done

- Тест написан первым.
- Компонент создан.
- Тест проходит.
- Коммит: `RG-170: Create PostFeed Livewire component`

### Files likely touched

```txt
app/Livewire/Feed/PostFeed.php
resources/views/livewire/feed/post-feed.blade.php
tests/Feature/Livewire/PostFeedTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-171 — Test PostFeed Shows Published Post Title

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-171-test-post-feed-shows-published-post-title`  
**Base branch:** develop  
**Depends on:** RG-170

### Goal

Написать падающий тест: `PostFeed` показывает title опубликованного поста.

### TDD step

Livewire test:

```php
it('shows published post title', function () {
    Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
    ]);

    Livewire::test(PostFeed::class)
        ->assertSee('Homemade Carbonara');
});
```

Добавить проверку, что pending post не виден:

```php
Post::factory()->pending()->create([
    'title' => 'Pending Dish',
]);

Livewire::test(PostFeed::class)
    ->assertDontSee('Pending Dish');
```

Тест должен упасть до RG-172.

### Implementation

Только добавить тест.  
Не реализовывать FeedQuery вызов в этой задаче.

### Acceptance criteria

- Тест существует.
- Тест создаёт published post.
- Тест проверяет title.
- Тест создаёт pending post.
- Тест проверяет, что pending title не виден.
- Тест падает до RG-172.

### Definition of Done

- Тест добавлен.
- Тест ожидаемо падает.
- Коммит: `RG-171: Test PostFeed shows published post title`

### Files likely touched

```txt
tests/Feature/Livewire/PostFeedTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

## RG-172 — Render Published Posts In PostFeed

**Area:** Livewire / Backend Integration  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-172-render-published-posts-in-post-feed`  
**Base branch:** develop  
**Depends on:** RG-171

### Goal

Подключить `FeedQuery` к `PostFeed` и отрендерить published posts.

### TDD step

Использовать падающий тест из RG-171.

### Implementation

В `PostFeed`:

```php
use App\Queries\Feed\FeedQuery;

public function render(FeedQuery $feedQuery)
{
    return view('livewire.feed.post-feed', [
        'posts' => $feedQuery->get(sort: 'newest'),
    ]);
}
```

В view:

```blade
<div>
    @foreach($posts as $post)
        <article>
            {{ $post->title }}
        </article>
    @endforeach
</div>
```

Пока не использовать PostCard. Это RG-175.

### Acceptance criteria

- PostFeed использует FeedQuery.
- Published post title виден.
- Pending/hidden/rejected не видны.
- Нет raw query в Livewire.
- Тест RG-171 проходит.

### Definition of Done

- FeedQuery подключён.
- Тест проходит.
- Коммит: `RG-172: Render published posts in PostFeed`

### Files likely touched

```txt
app/Livewire/Feed/PostFeed.php
resources/views/livewire/feed/post-feed.blade.php
tests/Feature/Livewire/PostFeedTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

## RG-173 — Create PostCard Blade Component

**Area:** UI / Tests  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-173-create-post-card-blade-component`  
**Base branch:** develop  
**Depends on:** RG-172

### Goal

Создать reusable Blade component `x-post-card` или `x-feed.post-card` для отображения поста.

### TDD step

Blade render test:

```php
it('renders post card title', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Homemade Carbonara',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', [
        'post' => $post,
    ]);

    expect($html)->toContain('Homemade Carbonara');
});
```

Тест должен упасть до создания компонента.

### Implementation

Создать:

```txt
resources/views/components/feed/post-card.blade.php
```

API:

```blade
<x-feed.post-card :post="$post" />
```

Первичная структура:

```blade
<x-ui.card variant="interactive" padding="md">
    <h3>{{ $post->title }}</h3>
</x-ui.card>
```

Компонент должен принимать `App\Models\Post`.

Не добавлять voting buttons.  
Не добавлять drawer open behavior.  
Не добавлять comment form.

### Acceptance criteria

- `x-feed.post-card` существует.
- Принимает `post`.
- Рендерит title.
- Использует `x-ui.card`.
- Render test проходит.

### Definition of Done

- Тест написан первым.
- Компонент создан.
- Тест проходит.
- Коммит: `RG-173: Create PostCard Blade component`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-174 — Render PostCard In UI Kit

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-174-render-post-card-in-ui-kit`  
**Base branch:** develop  
**Depends on:** RG-173

### Goal

Добавить реальный `PostCard` в `/dev/ui-kit`, чтобы визуально сравнивать его с design reference.

### TDD step

Feature test:

```php
it('renders post card example in ui kit', function () {
    $this->get('/dev/ui-kit')
        ->assertOk()
        ->assertSee('Post Card')
        ->assertSee('Homemade Carbonara');
});
```

Если `/dev/ui-kit` доступен только local/testing, тест должен работать в testing env.

### Implementation

В `resources/views/dev/ui-kit.blade.php` добавить секцию:

```txt
Feed Components
```

И пример:

```php
@php
    $post = new \App\Models\Post([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta preview',
        'upvotes_count' => 128,
        'downvotes_count' => 12,
        'comments_count' => 24,
        'homemade_votes_count' => 70,
        'restaurant_votes_count' => 30,
        'image_url' => null,
    ]);
    $post->setRelation('user', new \App\Models\User([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]));
@endphp

<x-feed.post-card :post="$post" />
```

Если создавать model object в Blade выглядит грязно, сделать dev helper partial. Но не использовать database в UI Kit.

### Acceptance criteria

- UI Kit показывает PostCard section.
- UI Kit example не требует database.
- PostCard можно визуально сравнить с reference.
- Feature test проходит.

### Definition of Done

- UI Kit обновлён.
- Тест проходит.
- Коммит: `RG-174: Render PostCard in UI kit`

### Files likely touched

```txt
resources/views/dev/ui-kit.blade.php
tests/Feature/DevUiKitRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-175 — Render PostCard In Feed

**Area:** Livewire / UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-175-render-post-card-in-feed`  
**Base branch:** develop  
**Depends on:** RG-173, RG-174

### Goal

Заменить временный article markup в PostFeed на `x-feed.post-card`.

### TDD step

Livewire test:

```php
it('renders post cards in feed', function () {
    Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
    ]);

    Livewire::test(PostFeed::class)
        ->assertSee('Homemade Carbonara');
});
```

Если нужно проверить компонентный HTML — добавить data-testid в PostCard:

```html
data-testid="post-card"
```

И assert:

```php
->assertSee('data-testid="post-card"', false)
```

### Implementation

В `post-feed.blade.php`:

```blade
<div class="grid gap-4">
    @foreach($posts as $post)
        <x-feed.post-card :post="$post" :key="$post->id" />
    @endforeach
</div>
```

Не добавлять masonry/complex layout.

### Acceptance criteria

- PostFeed использует `x-feed.post-card`.
- Published post title виден.
- Pending posts не видны.
- Markup не дублирует PostCard internals.
- Тест проходит.

### Definition of Done

- PostFeed обновлён.
- Тест проходит.
- Коммит: `RG-175: Render PostCard in feed`

### Files likely touched

```txt
resources/views/livewire/feed/post-feed.blade.php
tests/Feature/Livewire/PostFeedTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-176 — Add Image Area To PostCard

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-176-add-image-area-to-post-card`  
**Base branch:** develop  
**Depends on:** RG-175

### Goal

Добавить image area в PostCard.

### TDD step

Render tests:

```php
it('renders post image when image url exists', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('/storage/posts/1/dish.jpg');
});
```

И fallback:

```php
it('renders image placeholder when image url is missing', function () {
    $post = Post::factory()->published()->make(['image_url' => null]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Food image');
});
```

Текст placeholder зависит от `x-ui.image-placeholder`.

### Implementation

В PostCard:

```blade
@if($post->image_url)
    <img
        src="{{ $post->image_url }}"
        alt="{{ $post->title }}"
        class="aspect-[4/3] w-full rounded-... object-cover"
    >
@else
    <x-ui.image-placeholder label="Food image" ratio="video" />
@endif
```

Использовать dark/rounded style из design contract.

### Acceptance criteria

- Если `image_url` есть — рендерится img.
- `alt` содержит title.
- Если `image_url` нет — рендерится placeholder.
- Image area имеет fixed ratio.
- Тесты проходят.

### Definition of Done

- Тесты написаны.
- Image area добавлена.
- UI Kit PostCard обновлён автоматически, если использует тот же component.
- Коммит: `RG-176: Add image area to PostCard`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

## RG-177 — Add Author Area To PostCard

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-177-add-author-area-to-post-card`  
**Base branch:** develop  
**Depends on:** RG-176

### Goal

Добавить author/meta area в PostCard.

### TDD step

Render test:

```php
it('renders post author area', function () {
    $user = User::factory()->make([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $post = Post::factory()->published()->make(['title' => 'Dish']);
    $post->setRelation('user', $user);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Demo Chef');
    expect($html)->toContain('@demo_chef');
});
```

### Implementation

В PostCard добавить:

```blade
<div class="flex items-center gap-3">
    <x-ui.avatar :src="$post->user?->avatar_url" :name="$post->user?->name ?? 'User'" size="sm" />

    <div>
        <div>{{ $post->user?->name ?? 'Unknown user' }}</div>
        @if($post->user?->username)
            <div>@{{ $post->user->username }}</div>
        @endif
    </div>
</div>
```

Нужно быть осторожным с Blade `@` перед username: использовать `{{ '@' . $post->user->username }}`.

### Acceptance criteria

- Author name отображается.
- Username отображается, если есть.
- Avatar component используется.
- Если user relation отсутствует, компонент не падает.
- Тест проходит.

### Definition of Done

- Тест написан.
- Author area добавлена.
- Компонент устойчив к missing relation.
- Коммит: `RG-177: Add author area to PostCard`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 



---

## RG-178 — Add Title Area To PostCard

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-178-add-title-area-to-post-card`  
**Base branch:** develop  
**Depends on:** RG-177

### Goal

Оформить title/description area в PostCard.

### TDD step

Render test:

```php
it('renders post title and description', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Homemade Carbonara');
    expect($html)->toContain('Creamy pasta with pepper');
});
```

И optional description:

```php
it('does not break when description is missing', function () {
    $post = Post::factory()->published()->make([
        'title' => 'Dish',
        'description' => null,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('Dish');
});
```

### Implementation

В PostCard добавить:

```blade
<div>
    <h3>{{ $post->title }}</h3>

    @if($post->description)
        <p>{{ Str::limit($post->description, 140) }}</p>
    @endif
</div>
```

Если используется `Str`, добавить namespace или `\Illuminate\Support\Str::limit`.

### Acceptance criteria

- Title виден.
- Description виден, если есть.
- Missing description не ломает карточку.
- Description ограничен по длине.
- Стиль соответствует card density.

### Definition of Done

- Тесты написаны.
- Title area добавлена.
- Тесты проходят.
- Коммит: `RG-178: Add title area to PostCard`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

## RG-179 — Add Stats Area To PostCard

**Area:** UI / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-179-add-stats-area-to-post-card`  
**Base branch:** develop  
**Depends on:** RG-178

### Goal

Добавить stats area в PostCard: score, comments, origin vote counts.

Это только read-only отображение. Никаких кнопок голосования в Phase 8.

### TDD step

Render test:

```php
it('renders post stats area', function () {
    $post = Post::factory()->published()->make([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
        'comments_count' => 5,
        'homemade_votes_count' => 7,
        'restaurant_votes_count' => 4,
    ]);

    $html = Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]);

    expect($html)->toContain('9'); // net score
    expect($html)->toContain('5 comments');
    expect($html)->toContain('Homemade');
    expect($html)->toContain('Restaurant');
});
```

Может быть хрупко из-за чисел. Лучше использовать explicit labels:

```txt
Score
5 comments
```

### Implementation

В PostCard добавить read-only блок:

```blade
@php
    $score = $post->upvotes_count - $post->downvotes_count;
@endphp

<div class="flex items-center justify-between">
    <div>
        <span>Score</span>
        <span>{{ $score }}</span>
    </div>

    <div>
        <span>{{ $post->comments_count }} comments</span>
    </div>
</div>

<div>
    <x-ui.badge>Homemade {{ $post->homemade_votes_count }}</x-ui.badge>
    <x-ui.badge>Restaurant {{ $post->restaurant_votes_count }}</x-ui.badge>
</div>
```

Не добавлять interactive buttons.

### Acceptance criteria

- Net score отображается.
- Comments count отображается.
- Homemade/Restaurant counts отображаются.
- Stats read-only.
- Используются UI badge/typography classes.
- Тест проходит.

### Definition of Done

- Тест написан.
- Stats area добавлена.
- Тест проходит.
- Коммит: `RG-179: Add stats area to PostCard`

### Files likely touched

```txt
resources/views/components/feed/post-card.blade.php
tests/Feature/ViewComponents/PostCardComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

## RG-180 — Add Empty Feed State

**Area:** Livewire / UI  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-180-add-empty-feed-state`  
**Base branch:** develop  
**Depends on:** RG-175

### Goal

Добавить empty state в PostFeed, когда published posts отсутствуют.

### TDD step

Можно сразу сделать тест в RG-181, но здесь задача реализации. Чтобы сохранить TDD, сначала добавить минимальный Livewire test в этой задаче или выполнить RG-181 перед RG-180. Так как исходный порядок ставит RG-180 перед RG-181, здесь допустимо сделать implementation + RG-181 закрепит тест. Но лучше всё равно написать test первым в этой же задаче:

```php
it('shows empty feed state when no published posts exist', function () {
    Livewire::test(PostFeed::class)
        ->assertSee('No dishes yet');
});
```

### Implementation

В `post-feed.blade.php`:

```blade
@if($posts->isEmpty())
    <x-ui.empty-state
        title="No dishes yet"
        description="Published dishes will appear here."
    />
@else
    ...
@endif
```

Если `$posts` paginator в будущем, учитывать `count($posts)` или `$posts->isEmpty()` для collection.

### Acceptance criteria

- Empty state показывается, когда нет published posts.
- Используется `x-ui.empty-state`.
- Empty state не показывается, когда posts есть.
- Тест проходит.

### Definition of Done

- Empty state добавлен.
- Тест добавлен или будет закреплён в RG-181.
- Коммит: `RG-180: Add empty feed state`

### Files likely touched

```txt
resources/views/livewire/feed/post-feed.blade.php
tests/Feature/Livewire/PostFeedTest.php
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

## RG-181 — Test Empty Feed State Renders

**Area:** Livewire / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-181-test-empty-feed-state-renders`  
**Base branch:** develop  
**Depends on:** RG-180

### Goal

Закрепить regression test для empty feed state.

### TDD step

Livewire test:

```php
it('renders empty feed state when there are no published posts', function () {
    Post::factory()->pending()->create([
        'title' => 'Pending Dish',
    ]);

    Livewire::test(PostFeed::class)
        ->assertSee('No dishes yet')
        ->assertDontSee('Pending Dish');
});
```

### Implementation

Если empty state уже реализован в RG-180, только добавить/расширить тест.  
Если тест падает, исправить empty state.

### Acceptance criteria

- Тест существует.
- Pending post не скрывает empty state.
- Empty state текст виден.
- Тест проходит.

### Definition of Done

- Regression test добавлен.
- Тест проходит.
- Коммит: `RG-181: Test empty feed state renders`

### Files likely touched

```txt
tests/Feature/Livewire/PostFeedTest.php
resources/views/livewire/feed/post-feed.blade.php
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-182 — Add Loading Skeleton To PostFeed

**Area:** Livewire / UI  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-182-add-loading-skeleton-to-post-feed`  
**Base branch:** develop  
**Depends on:** RG-181

### Goal

Добавить loading skeleton markup для PostFeed, который Livewire сможет показывать при обновлениях.

### TDD step

Render/Livewire test может проверить наличие `wire:loading` или skeleton marker:

```php
it('has loading skeleton markup', function () {
    Livewire::test(PostFeed::class)
        ->assertSee('data-testid="post-feed-loading"', false);
});
```

### Implementation

В `post-feed.blade.php` добавить:

```blade
<div wire:loading class="grid gap-4" data-testid="post-feed-loading">
    <x-ui.card padding="md">
        <x-ui.skeleton shape="block" height="12rem" />
        <x-ui.skeleton shape="line" width="70%" />
        <x-ui.skeleton shape="line" width="40%" />
    </x-ui.card>
</div>
```

Основной контент можно обернуть:

```blade
<div wire:loading.remove>
    ...
</div>
```

### Acceptance criteria

- Есть loading skeleton markup.
- Используется `x-ui.skeleton`.
- Есть `wire:loading`.
- Есть `wire:loading.remove` для основного контента или эквивалент.
- Тест проходит.

### Definition of Done

- Skeleton добавлен.
- Тест проходит.
- Коммит: `RG-182: Add loading skeleton to PostFeed`

### Files likely touched

```txt
resources/views/livewire/feed/post-feed.blade.php
tests/Feature/Livewire/PostFeedTest.php
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-183 — Add Mobile Feed Layout Pass

**Area:** UI / Layout  
**Type:** Layout  
**Priority:** P0  
**Branch:** `feature/RG-183-add-mobile-feed-layout-pass`  
**Base branch:** develop  
**Depends on:** RG-182

### Goal

Проверить и доработать mobile layout ленты.

### TDD step

No direct reliable unit test for visual mobile layout.

Добавить lightweight markup test:

```php
it('uses mobile-safe feed layout classes', function () {
    $this->get('/')
        ->assertSee('px-4', false)
        ->assertSee('max-w-', false);
});
```

Но такой тест может быть хрупким. Основная проверка — manual UI checklist.

### Implementation

Проверить и поправить:

```txt
- body не имеет horizontal overflow;
- feed container использует px-4;
- PostCard занимает full width;
- image area не ломает пропорции;
- stats wrap на маленькой ширине;
- typography не слишком крупная;
- spacing не слишком тесный.
```

Возможные классы:

```txt
px-4
py-4
grid gap-4
w-full
max-w-full
sm:px-6
```

Создать/обновить документ:

```txt
docs/design/phase-8-feed-ui-review.md
```

Добавить секцию `Mobile pass`.

### Acceptance criteria

- Главная лента usable на mobile width.
- Нет горизонтального overflow.
- PostCard выглядит как карточка, а не таблица.
- Stats area не ломается.
- Manual notes добавлены в `docs/design/phase-8-feed-ui-review.md`.

### Definition of Done

- Mobile pass выполнен.
- Документ с заметками обновлён.
- `npm run build` проходит.
- Коммит: `RG-183: Add mobile feed layout pass`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/components/feed/post-card.blade.php
docs/design/phase-8-feed-ui-review.md
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 
---

## RG-184 — Add Desktop Feed Layout Pass

**Area:** UI / Layout  
**Type:** Layout  
**Priority:** P0  
**Branch:** `feature/RG-184-add-desktop-feed-layout-pass`  
**Base branch:** develop  
**Depends on:** RG-183

### Goal

Проверить и доработать desktop layout ленты.

### TDD step

No direct reliable unit test for visual desktop layout.

Можно добавить markup test на desktop-oriented classes, но не делать его слишком хрупким.

### Implementation

Проверить и поправить:

```txt
- feed container max width;
- desktop spacing;
- card width/density;
- возможно grid layout:
  - one column centered
  - или responsive two-column, если design reference это поддерживает
- header alignment;
- card image ratio;
- visual rhythm.
```

Рекомендация для первой версии:

```txt
desktop: max-w-5xl / max-w-6xl
feed: grid gap-5
cards: one-column или two-column только если карточка достаточно компактна
```

Не делать masonry.  
Не делать complex infinite feed layout.

Обновить:

```txt
docs/design/phase-8-feed-ui-review.md
```

секцией `Desktop pass`.

### Acceptance criteria

- Desktop лента имеет ограниченную ширину.
- Контент не растягивается на весь экран.
- Cards выглядят близко к reference dark-card style.
- Header/feed spacing аккуратные.
- Manual notes добавлены.
- `npm run build` проходит.

### Definition of Done

- Desktop pass выполнен.
- Документ с заметками обновлён.
- Коммит: `RG-184: Add desktop feed layout pass`

### Files likely touched

```txt
resources/views/livewire/feed/feed-page.blade.php
resources/views/livewire/feed/post-feed.blade.php
resources/views/components/feed/post-card.blade.php
docs/design/phase-8-feed-ui-review.md
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 

---

## RG-185 — Compare Feed UI With Design Checklist

**Area:** UI / Docs  
**Type:** Docs / QA  
**Priority:** P0  
**Branch:** `feature/RG-185-compare-feed-ui-with-design-checklist`  
**Base branch:** develop  
**Depends on:** RG-184

### Goal

Финально сверить Phase 8 feed UI с design contract, исходным reference и UI checklist.

### TDD step

No direct test — visual QA/documentation task.

Проверить:

```bash
composer test
npm run build
```

Ручная проверка:

```txt
/
 /dev/ui-kit
```

### Implementation

Обновить или создать:

```txt
docs/design/phase-8-feed-ui-review.md
```

Содержимое:

```md
# Phase 8 Feed UI Review

## Reference checked
- [ ] docs/design/reference/original/PlateRate.html
- [ ] docs/design/reference/screenshots/
- [ ] docs/design/design-contract.md
- [ ] docs/design/ui-review-checklist.md
- [ ] /dev/ui-kit

## Feed page
- [ ] Dark background preserved
- [ ] Header matches RateGuru direction
- [ ] Feed container width is controlled
- [ ] Mobile layout checked
- [ ] Desktop layout checked

## PostCard
- [ ] Uses x-ui.card
- [ ] Image area exists
- [ ] Author area exists
- [ ] Title area exists
- [ ] Stats area exists
- [ ] Missing image fallback works
- [ ] Missing description does not break layout

## States
- [ ] Empty feed state exists
- [ ] Loading skeleton exists

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

- `docs/design/phase-8-feed-ui-review.md` существует.
- Все relevant checklist пункты отмечены.
- Known deviations явно описаны.
- `/` проверен вручную.
- `/dev/ui-kit` проверен вручную.
- `composer test` проходит.
- `npm run build` проходит.

### Definition of Done

- UI review документ создан/обновлён.
- Все тесты проходят.
- Build проходит.
- Коммит: `RG-185: Compare feed UI with design checklist`

### Files likely touched

```txt
docs/design/phase-8-feed-ui-review.md
```
После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально 


---

# 10. Phase 8 Completion Criteria

Phase 8 завершена, когда:

```txt
- RG-166–RG-185 выполнены;
- FeedPage Livewire component существует;
- `/` route ведёт на FeedPage;
- PostFeed Livewire component существует;
- PostFeed использует FeedQuery;
- published posts отображаются;
- pending/hidden/rejected posts не отображаются;
- PostCard Blade component существует;
- PostCard отображает image area;
- PostCard отображает author area;
- PostCard отображает title area;
- PostCard отображает stats area;
- PostCard добавлен в UI Kit;
- empty state работает;
- loading skeleton есть;
- mobile layout pass выполнен;
- desktop layout pass выполнен;
- phase-8 UI review document создан;
- composer test проходит;
- npm run build проходит.
```

---

# 11. Что нельзя делать в Phase 8

Без отдельной задачи нельзя:

```txt
- добавлять search input;
- добавлять category tabs;
- добавлять sort dropdown;
- добавлять pagination UI;
- добавлять infinite scroll;
- добавлять up/down voting;
- добавлять Homemade/Restaurant voting;
- добавлять cuisine voting;
- добавлять comments;
- добавлять upload modal;
- добавлять post drawer;
- добавлять report modal;
- добавлять inline moderation;
- добавлять notifications;
- делать API endpoint;
- добавлять caching;
- добавлять Redis;
- добавлять Vue/React/Inertia;
- менять FeedQuery behavior вне необходимости UI.
```

---

# 12. Recommended Execution Order

```txt
RG-166 Create FeedPage Livewire component
RG-167 Test FeedPage renders
RG-168 Add feed route
RG-169 Render base feed layout
RG-170 Create PostFeed Livewire component
RG-171 Test PostFeed shows published post title
RG-172 Render published posts in PostFeed
RG-173 Create PostCard Blade component
RG-174 Render PostCard in UI kit
RG-175 Render PostCard in feed
RG-176 Add image area to PostCard
RG-177 Add author area to PostCard
RG-178 Add title area to PostCard
RG-179 Add stats area to PostCard
RG-180 Add empty feed state
RG-181 Test empty feed state renders
RG-182 Add loading skeleton to PostFeed
RG-183 Add mobile feed layout pass
RG-184 Add desktop feed layout pass
RG-185 Compare feed UI with design checklist
```

---

# 13. Release

После завершения Phase 8:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.0.9-phase8-feed-ui-livewire
git push -u origin release/v0.0.9-phase8-feed-ui-livewire
```


После этого делай MR в бранч main и останавливайся

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.0.9-phase8-feed-ui-livewire -m "RateGuru Phase 8 feed UI with Livewire"
git push origin v0.0.9-phase8-feed-ui-livewire
```
