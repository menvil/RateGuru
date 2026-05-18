# RateGuru — Phase 12 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 12 — Post Show Page**  
Диапазон задач: **RG-242 → RG-253**  
Основа нумерации: исходный atomic backlog, где Phase 12 начинается с задачи 242 и заканчивается задачей 253.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 12 соответствует исходному блоку:

```txt
Phase 12 — Post Show Page
```

Правильный диапазон Phase 12:

```txt
RG-242 — Add posts.show route
RG-243 — Create PostShow Livewire component
RG-244 — Test published post show page renders
RG-245 — Test hidden post is not visible to normal user
RG-246 — Render post hero image
RG-247 — Render post metadata
RG-248 — Render voting panels on post page
RG-249 — Render comments section on post page
RG-250 — Render share panel on post page
RG-251 — Render related posts placeholder
RG-252 — Add SEO title for post page
RG-253 — Add open graph metadata placeholder
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.
---

# 2. Цель Phase 12

Phase 12 добавляет отдельную публичную страницу поста.

После Phase 12 у каждого опубликованного поста должна быть standalone страница:

```txt
/posts/{post}
```

На странице должно быть:

```txt
- hero image;
- title;
- description;
- author metadata;
- read-only voting panels;
- comments section placeholder;
- share panel placeholder;
- related posts placeholder;
- SEO title;
- Open Graph metadata placeholder.
```

Это не замена drawer.  
Drawer остаётся быстрым просмотром внутри feed.  
Post show page нужна для:

```txt
- прямых ссылок;
- шаринга;
- будущего SEO;
- будущего Open Graph;
- полноценного detail layout;
- страницы, куда можно попасть из уведомлений/профиля/админки.
```
---

# 3. Scope Phase 12

## Входит

```txt
- route posts.show;
- PostShow Livewire component;
- published post rendering;
- защита hidden/pending/rejected posts;
- hero image;
- metadata;
- read-only voting panels;
- comments placeholder/slot;
- share panel placeholder;
- related posts placeholder;
- SEO title;
- Open Graph placeholder metadata.
```

## Не входит

```txt
- voting actions;
- interactive vote buttons;
- comments backend;
- comments list;
- comment form;
- report modal;
- moderation controls;
- actual share/copy behavior;
- related posts algorithm;
- canonical URL helper;
- dynamic Open Graph image generation;
- SSR cache;
- API endpoint.
```

Voting начнётся в Phase 13.  
Comments backend/UI — Phase 17/18.  
Reports — Phase 19/20.  
Share behavior — Phase 32.
---

# 4. Architecture Rules

## 4.1. PostShow loads only published posts

Public show page не должна показывать:

```txt
draft
pending
hidden
rejected
deleted
```

Обычный пользователь и гость видят только:

```txt
status = published
```

Если пост не published:

```txt
404
```

или equivalent not found response.

Для Phase 12 лучше использовать HTTP 404, потому что это standalone URL.

## 4.2. PostShow is read-only in Phase 12

PostShow не должен:

```txt
- голосовать;
- добавлять комментарии;
- отправлять report;
- изменять post;
- пересчитывать counters;
- создавать notifications.
```

Все panels в этой фазе — read-only/placeholders.

## 4.3. Reuse drawer presentation logic carefully

PostShow может визуально повторять PostDrawer, но не надо копировать Blade-мусор.

Лучше:

```txt
- вынести reusable partials/components, если они уже очевидны;
- либо аккуратно повторить минимальный layout, если extraction преждевременна.
```

Не делать огромный абстрактный `PostDetailView` без необходимости.

## 4.4. Route model binding must not leak unpublished posts

Нельзя полагаться на обычный `{post}` binding и потом забыть проверить status.

Допустимые варианты:

```php
Route::get('/posts/{post}', PostShow::class)->name('posts.show');
```

и в component:

```php
Post::published()->findOrFail($postId)
```

Или кастомный scoped binding, но для Phase 12 это лишнее.

## 4.5. Comments/share/related are placeholders

Phase 12 содержит:

```txt
RG-249 — Render comments section on post page
RG-250 — Render share panel on post page
RG-251 — Render related posts placeholder
```

Это не значит, что надо реализовать comments/share/related systems.

Правильно:

```txt
- comments block с title, count и placeholder;
- share block с disabled/future buttons или copy link placeholder;
- related posts block с "Coming soon".
```

Неправильно:

```txt
- создавать CommentsSection;
- создавать CommentForm;
- писать share JS;
- строить recommendation query.
```
---

# 5. Design Constraints

Post show page должен соответствовать visual language RateGuru:

```txt
- dark background;
- centered content;
- large hero image;
- rounded dark cards;
- purple accent;
- readable metadata;
- mobile-first;
- desktop max-width;
- content density близкая к drawer, но более просторная.
```

Перед UI-задачами проверить:

```txt
docs/design/reference/original/PlateRate.html
docs/design/reference/screenshots/
docs/design/design-contract.md
docs/design/ui-review-checklist.md
/dev/ui-kit
docs/design/phase-8-feed-ui-review.md
docs/design/phase-11-drawer-ui-review.md
```

Если `phase-11-drawer-ui-review.md` отсутствует, это missing prerequisite.
---

# 6. GitFlow для Phase 12

## Base branch

Все задачи Phase 12 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-242-add-posts-show-route
feature/RG-243-create-post-show-livewire-component
feature/RG-253-add-open-graph-metadata-placeholder
```

## Commit format

```txt
RG-242: Add posts.show route
RG-243: Create PostShow Livewire component
RG-253: Add open graph metadata placeholder
```

## Release branch

После выполнения `RG-242`–`RG-253`:

```txt
release/v0.1.3-phase12-post-show-page
```

## Tag

После merge release branch в `main`:

```txt
v0.1.3-phase12-post-show-page
```
---

# 7. TDD Rules for Phase 12

## Для route

Сначала HTTP feature tests:

```txt
- published post show route returns 200;
- hidden/pending/rejected route returns 404.
```

## Для Livewire PostShow

Писать Livewire tests:

```txt
- renders selected published post;
- renders not found/404 for unavailable post;
- shows expected sections.
```

## Для UI blocks

Писать rendered-output tests:

```txt
- hero image present;
- metadata present;
- voting panels read-only;
- comments placeholder present;
- share placeholder present;
- related placeholder present.
```

## Для SEO/OG

Писать HTTP output tests:

```txt
- title tag contains post title;
- og:title placeholder exists;
- og:description placeholder exists;
- og:image placeholder exists or safely omitted when image missing.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: UI / Livewire / Routing / SEO / Tests
Type: Test / Feature / Component / Layout / Metadata
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

# 9. Phase 12 Atomic Tasks
---

## RG-242 — Add Posts Show Route

**Area:** Routing / Tests  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-242-add-posts-show-route`  
**Base branch:** develop
**Depends on:** RG-241

### Goal

Добавить именованный route для standalone страницы поста.

### TDD step

Сначала route test:

```php
it('has posts show route', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk();
});
```

На момент этой задачи `PostShow` ещё не создан, поэтому тест может падать до RG-243.  
Чтобы сделать задачу атомарной, можно временно route вести на placeholder view, но лучше не плодить placeholder route, если сразу следом создаётся component.

Рекомендуемый порядок:

```txt
RG-242: route registration + temporary 501/placeholder acceptable
RG-243: route points to PostShow
RG-244: full published render test
```

### Implementation

В `routes/web.php` добавить:

```php
Route::get('/posts/{post}', PostShow::class)
    ->name('posts.show');
```

Если `PostShow` ещё не существует, можно:
- создать route после RG-243; или
- создать минимальный class stub вместе с RG-242.

Но исходный backlog ставит route перед component. Значит, в RG-242 допустим минимальный временный route placeholder:

```php
Route::get('/posts/{post}', fn () => 'Post show')
    ->name('posts.show');
```

Однако это создаёт лишнюю замену. Более чисто: RG-242 добавляет route test и TODO, RG-243 делает route рабочим.

### Acceptance criteria

- Route name `posts.show` зарезервирован.
- URL pattern `/posts/{post}`.
- Нет API route.
- Нет SEO/OG logic.
- Route будет подключён к PostShow в RG-243.

### Definition of Done

- Route/test добавлены в минимально рабочем виде.
- Коммит: `RG-242: Add posts.show route`

### Files likely touched

```txt
routes/web.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-243 — Create PostShow Livewire Component

**Area:** Livewire / UI  
**Type:** Component  
**Priority:** P0  
**Branch:** `feature/RG-243-create-post-show-livewire-component`  
**Base branch:** develop
**Depends on:** RG-242

### Goal

Создать `PostShow` Livewire component и подключить route к нему.

### TDD step

Livewire test:

```php
it('can render post show component for published post', function () {
    $post = Post::factory()->published()->create();

    Livewire::test(PostShow::class, ['post' => $post])
        ->assertStatus(200);
});
```

Если Livewire route receives model/id differently, тест адаптировать под реальную сигнатуру.

### Implementation

Создать компонент:

```bash
php artisan make:livewire Posts/PostShow
```

Файлы:

```txt
app/Livewire/Posts/PostShow.php
resources/views/livewire/posts/post-show.blade.php
```

Class:

```php
final class PostShow extends Component
{
    public int $postId;

    public function mount(Post $post): void
    {
        $this->postId = $post->id;
    }

    public function getPostProperty(): Post
    {
        return Post::query()
            ->published()
            ->with(['user', 'tags'])
            ->findOrFail($this->postId);
    }

    public function render(): View
    {
        return view('livewire.posts.post-show', [
            'post' => $this->post,
        ]);
    }
}
```

Route:

```php
Route::get('/posts/{post}', PostShow::class)
    ->name('posts.show');
```

Минимальный view:

```blade
<div>
    {{ $post->title }}
</div>
```

### Acceptance criteria

- `PostShow` component существует.
- Route `/posts/{post}` ведёт на `PostShow`.
- Component загружает только published post.
- Eager loads user/tags.
- Минимальный render работает.
- Tests проходят.

### Definition of Done

- Тест написан.
- Component создан.
- Route подключён к component.
- Тест проходит.
- Коммит: `RG-243: Create PostShow Livewire component`

### Files likely touched

```txt
app/Livewire/Posts/PostShow.php
resources/views/livewire/posts/post-show.blade.php
routes/web.php
tests/Feature/Livewire/PostShowTest.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-244 — Test Published Post Show Page Renders

**Area:** Tests / Livewire  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-244-test-published-post-show-page-renders`  
**Base branch:** develop
**Depends on:** RG-243

### Goal

Закрепить regression test: published post show page открывается и показывает контент.

### TDD step

HTTP feature test:

```php
it('renders published post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Homemade Carbonara')
        ->assertSee('Creamy pasta with pepper');
});
```

### Implementation

Если тест падает, обновить `PostShow` view, чтобы он показывал title/description.  
Но не делать hero/metadata panels — они идут дальше.

### Acceptance criteria

- Published post route returns 200.
- Title visible.
- Description visible if present.
- Test проходит.

### Definition of Done

- Test добавлен.
- Minimal render подтверждён.
- Коммит: `RG-244: Test published post show page renders`

### Files likely touched

```txt
tests/Feature/Routes/PostShowRouteTest.php
resources/views/livewire/posts/post-show.blade.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-245 — Test Hidden Post Is Not Visible To Normal User

**Area:** Routing / Security / Tests  
**Type:** Test  
**Priority:** P0  
**Branch:** `feature/RG-245-test-hidden-post-is-not-visible-to-normal-user`  
**Base branch:** develop
**Depends on:** RG-244

### Goal

Зафиксировать, что hidden/pending/rejected posts не видны обычному пользователю и гостю на standalone URL.

### TDD step

Feature tests:

```php
it('does not show hidden post to guest', function () {
    $post = Post::factory()->hidden()->create([
        'title' => 'Hidden Dish',
    ]);

    $this->get(route('posts.show', $post))
        ->assertNotFound()
        ->assertDontSee('Hidden Dish');
});
```

```php
it('does not show pending post to normal user', function () {
    $user = User::factory()->create();

    $post = Post::factory()->pending()->create([
        'title' => 'Pending Dish',
    ]);

    $this->actingAs($user)
        ->get(route('posts.show', $post))
        ->assertNotFound()
        ->assertDontSee('Pending Dish');
});
```

### Implementation

В `PostShow` loading logic убедиться:

```php
Post::published()->findOrFail($this->postId)
```

Если route model binding сам загружает hidden post, не использовать его напрямую для render. Использовать только id.

### Acceptance criteria

- Hidden post returns 404.
- Pending post returns 404.
- Rejected post returns 404, если добавлен тест.
- Hidden title не появляется в response.
- Normal user не получает special access.
- Tests проходят.

### Definition of Done

- Tests написаны.
- Access guard подтверждён.
- Коммит: `RG-245: Test hidden post is not visible to normal user`

### Files likely touched

```txt
app/Livewire/Posts/PostShow.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-246 — Render Post Hero Image

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-246-render-post-hero-image`  
**Base branch:** develop
**Depends on:** RG-245

### Goal

Отобразить hero image на PostShow странице.

### TDD step

HTTP/Livewire test:

```php
it('renders post hero image', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Dish',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('/storage/posts/1/dish.jpg')
        ->assertSee('alt="Dish"', false);
});
```

Fallback test:

```php
it('renders hero image placeholder when image is missing', function () {
    $post = Post::factory()->published()->create([
        'image_url' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Image preview');
});
```

### Implementation

В `post-show.blade.php`:

```blade
@if($post->image_url)
    <img
        src="{{ $post->image_url }}"
        alt="{{ $post->title }}"
        class="aspect-[16/10] w-full rounded-rgCard object-cover"
    >
@else
    <x-ui.image-placeholder label="Image preview" ratio="video" />
@endif
```

Hero image должен быть больше, чем в PostCard.

### Acceptance criteria

- Hero image отображается, если image_url есть.
- Alt содержит title.
- Placeholder отображается, если image_url отсутствует.
- Image area responsive.
- Tests проходят.

### Definition of Done

- Tests написаны.
- Hero image block добавлен.
- Tests проходят.
- Коммит: `RG-246: Render post hero image`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-247 — Render Post Metadata

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-247-render-post-metadata`  
**Base branch:** develop
**Depends on:** RG-246

### Goal

Отобразить metadata поста: author, username, published date, tags, source_url если есть.

### TDD step

Feature test:

```php
it('renders post metadata', function () {
    $user = User::factory()->create([
        'name' => 'Demo Chef',
        'username' => 'demo_chef',
    ]);

    $tag = Tag::factory()->create([
        'name' => 'Pasta',
        'slug' => 'pasta',
    ]);

    $post = Post::factory()->published()->for($user)->create([
        'source_url' => 'https://example.com/original',
    ]);

    $post->tags()->attach($tag);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Demo Chef')
        ->assertSee('@demo_chef')
        ->assertSee('Pasta')
        ->assertSee('Source');
});
```

### Implementation

В view добавить metadata block:

```blade
<section>
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

    @if($post->tags->isNotEmpty())
        @foreach($post->tags as $tag)
            <x-ui.badge>{{ $tag->name }}</x-ui.badge>
        @endforeach
    @endif

    @if($post->source_url)
        <a href="{{ $post->source_url }}" rel="nofollow noopener" target="_blank">
            Source
        </a>
    @endif
</section>
```

Не создавать profile links, если profile route ещё не готов.

### Acceptance criteria

- Author name visible.
- Username visible if exists.
- Published date visible if exists.
- Tags visible.
- Source link visible if source_url exists.
- Source link uses `rel="nofollow noopener"` and target blank.
- Tests проходят.

### Definition of Done

- Test написан.
- Metadata block добавлен.
- Tests проходят.
- Коммит: `RG-247: Render post metadata`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-248 — Render Voting Panels On Post Page

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-248-render-voting-panels-on-post-page`  
**Base branch:** develop
**Depends on:** RG-247

### Goal

Отобразить read-only voting panels на post show page.

Это не интерактивное голосование.  
Настоящие voting actions начинаются в Phase 13.

### TDD step

Feature test:

```php
it('renders read only voting panels on post page', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 12,
        'downvotes_count' => 3,
        'homemade_votes_count' => 7,
        'restaurant_votes_count' => 5,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Score')
        ->assertSee('9')
        ->assertSee('Homemade')
        ->assertSee('7')
        ->assertSee('Restaurant')
        ->assertSee('5');
});
```

### Implementation

Добавить panels:

```blade
<section aria-label="Voting summary">
    @php($score = $post->upvotes_count - $post->downvotes_count)

    <x-ui.card>
        <span>Score</span>
        <strong>{{ $score }}</strong>
    </x-ui.card>

    <x-ui.card>
        <span>Homemade</span>
        <strong>{{ $post->homemade_votes_count }}</strong>
    </x-ui.card>

    <x-ui.card>
        <span>Restaurant</span>
        <strong>{{ $post->restaurant_votes_count }}</strong>
    </x-ui.card>
</section>
```

Можно добавить cuisine placeholder:

```txt
Cuisine voting coming soon
```

Но не интерактивные buttons.

### Acceptance criteria

- Score panel visible.
- Homemade panel visible.
- Restaurant panel visible.
- Panels read-only.
- Нет `wire:click` voting.
- Test проходит.

### Definition of Done

- Test написан.
- Voting panels добавлены.
- Test проходит.
- Коммит: `RG-248: Render voting panels on post page`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-249 — Render Comments Section On Post Page

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-249-render-comments-section-on-post-page`  
**Base branch:** develop
**Depends on:** RG-248

### Goal

Отобразить comments section placeholder на post show page.

Настоящие comments backend/UI будут позже.  
Здесь нужен только shell/placeholder.

### TDD step

Feature test:

```php
it('renders comments section placeholder on post page', function () {
    $post = Post::factory()->published()->create([
        'comments_count' => 3,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Comments')
        ->assertSee('3')
        ->assertSee('Comments will appear here');
});
```

### Implementation

В view:

```blade
<section data-testid="post-show-comments">
    <div>
        <h2>Comments</h2>
        <span>{{ $post->comments_count }}</span>
    </div>

    <x-ui.empty-state
        title="Comments will appear here"
        description="Comment UI will be added in a later phase."
    />
</section>
```

Не создавать CommentsSection component.  
Не выводить реальный comments list.  
Не делать form.

### Acceptance criteria

- Comments heading visible.
- comments_count visible.
- Placeholder visible.
- No comment form.
- No comments backend logic.
- Test проходит.

### Definition of Done

- Test написан.
- Comments placeholder добавлен.
- Test проходит.
- Коммит: `RG-249: Render comments section on post page`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-250 — Render Share Panel On Post Page

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-250-render-share-panel-on-post-page`  
**Base branch:** develop
**Depends on:** RG-249

### Goal

Отобразить share panel placeholder на post show page.

Настоящее copy-to-clipboard/share behavior будет Phase 32.

### TDD step

Feature test:

```php
it('renders share panel placeholder on post page', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Share')
        ->assertSee(route('posts.show', $post));
});
```

### Implementation

В view:

```blade
<section data-testid="post-show-share">
    <h2>Share</h2>

    <x-ui.card>
        <p>Share this post</p>
        <code>{{ route('posts.show', $post) }}</code>
        <p class="text-rg-muted">Copy link behavior will be added later.</p>
    </x-ui.card>
</section>
```

Не добавлять actual clipboard JS.  
Не добавлять social share buttons, если они не работают.

### Acceptance criteria

- Share heading visible.
- Canonical-ish route URL visible.
- Clearly placeholder behavior.
- No clipboard JS.
- Test проходит.

### Definition of Done

- Test написан.
- Share panel placeholder добавлен.
- Test проходит.
- Коммит: `RG-250: Render share panel on post page`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-251 — Render Related Posts Placeholder

**Area:** UI / Livewire  
**Type:** Feature  
**Priority:** P1  
**Branch:** `feature/RG-251-render-related-posts-placeholder`  
**Base branch:** develop
**Depends on:** RG-250

### Goal

Добавить placeholder для related posts.

Настоящий related posts query/algorithm не входит в Phase 12.

### TDD step

Feature test:

```php
it('renders related posts placeholder', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('Related posts')
        ->assertSee('Related dishes will appear here');
});
```

### Implementation

В view:

```blade
<section data-testid="post-show-related">
    <h2>Related posts</h2>

    <x-ui.empty-state
        title="Related dishes will appear here"
        description="Related post recommendations will be added later."
    />
</section>
```

Не делать query похожих постов.  
Не делать random posts.  
Не делать tags-based recommendations.

### Acceptance criteria

- Related posts heading visible.
- Placeholder visible.
- No related query.
- No random posts.
- Test проходит.

### Definition of Done

- Test написан.
- Placeholder добавлен.
- Test проходит.
- Коммит: `RG-251: Render related posts placeholder`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-252 — Add SEO Title For Post Page

**Area:** SEO / UI / Tests  
**Type:** Metadata  
**Priority:** P0  
**Branch:** `feature/RG-252-add-seo-title-for-post-page`  
**Base branch:** develop
**Depends on:** RG-251

### Goal

Добавить SEO title для post show page.

### TDD step

HTTP test:

```php
it('renders seo title for post page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<title>Homemade Carbonara · RateGuru</title>', false);
});
```

Если layout title implementation не рендерит raw title в response, адаптировать под проектный layout.

### Implementation

В зависимости от layout:

## Вариант A — Blade section

В post show view:

```blade
@section('title', $post->title . ' · RateGuru')
```

В layout:

```blade
<title>@yield('title', 'RateGuru')</title>
```

## Вариант B — Livewire layout title

Если Livewire supports layout/title attribute:

```php
use Livewire\Attributes\Title;

#[Title('...')]
```

Но dynamic title удобнее через view/layout data.

Рекомендация: использовать Blade section/layout variable, потому что это проще и прозрачно.

### Acceptance criteria

- `<title>` содержит post title.
- Title suffix = RateGuru.
- Missing/long title безопасно escaped.
- Test проходит.

### Definition of Done

- Test написан.
- SEO title добавлен.
- Test проходит.
- Коммит: `RG-252: Add SEO title for post page`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
resources/views/layouts/app.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально
---

## RG-253 — Add Open Graph Metadata Placeholder

**Area:** SEO / Metadata / Tests  
**Type:** Metadata  
**Priority:** P0  
**Branch:** `feature/RG-253-add-open-graph-metadata-placeholder`  
**Base branch:** develop
**Depends on:** RG-252

### Goal

Добавить Open Graph metadata placeholder для post show page.

Это не полноценная OG image generation.  
Нужны безопасные meta tags, которые позже можно улучшить.

### TDD step

HTTP test:

```php
it('renders open graph metadata placeholder for post page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Homemade Carbonara',
        'description' => 'Creamy pasta with pepper',
        'image_url' => '/storage/posts/1/dish.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('property="og:title"', false)
        ->assertSee('content="Homemade Carbonara"', false)
        ->assertSee('property="og:description"', false)
        ->assertSee('Creamy pasta with pepper', false)
        ->assertSee('property="og:type"', false);
});
```

Optional image test:

```php
->assertSee('property="og:image"', false)
```

но только если app can generate absolute URL reliably.

### Implementation

В layout добавить stack:

```blade
@stack('meta')
```

В `post-show.blade.php`:

```blade
@push('meta')
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $post->title }}">
    <meta property="og:description" content="{{ Str::limit($post->description ?? 'Rate this dish on RateGuru.', 160) }}">
    <meta property="og:url" content="{{ route('posts.show', $post) }}">

    @if($post->image_url)
        <meta property="og:image" content="{{ url($post->image_url) }}">
    @endif
@endpush
```

Также можно добавить Twitter placeholder:

```html
<meta name="twitter:card" content="summary_large_image">
```

но не обязательно.

Важно:

```txt
- не генерировать OG image;
- не добавлять external service;
- не делать screenshot generation.
```

### Acceptance criteria

- `@stack('meta')` есть в layout.
- og:type есть.
- og:title есть.
- og:description есть.
- og:url есть.
- og:image есть, если image_url есть и absolute URL можно сформировать.
- Metadata escaped.
- Tests проходят.

### Definition of Done

- Tests написаны.
- OG placeholder tags добавлены.
- `composer test` проходит.
- `npm run build` проходит.
- Коммит: `RG-253: Add open graph metadata placeholder`

### Files likely touched

```txt
resources/views/layouts/app.blade.php
resources/views/livewire/posts/post-show.blade.php
tests/Feature/Routes/PostShowRouteTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 12 Completion Criteria

Phase 12 завершена, когда:

```txt
- RG-242–RG-253 выполнены;
- route posts.show существует;
- PostShow Livewire component существует;
- published post page returns 200;
- hidden/pending/rejected post page returns 404 for normal users/guests;
- hero image renders;
- missing image placeholder works;
- metadata renders;
- read-only voting panels render;
- comments placeholder renders;
- share panel placeholder renders;
- related posts placeholder renders;
- SEO title renders;
- Open Graph metadata placeholder renders;
- composer test проходит;
- npm run build проходит.
```
---

# 11. Что нельзя делать в Phase 12

Без отдельной задачи нельзя:

```txt
- делать voting actions;
- делать interactive vote buttons;
- делать origin/cuisine voting;
- делать comments backend;
- делать comments list;
- делать comment form;
- делать report modal;
- делать moderation controls;
- делать real share/copy behavior;
- делать related posts algorithm;
- делать canonical URL helper abstraction;
- делать dynamic Open Graph image generation;
- делать API endpoint;
- добавлять Redis/cache layer;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-242 Add posts.show route
RG-243 Create PostShow Livewire component
RG-244 Test published post show page renders
RG-245 Test hidden post is not visible to normal user
RG-246 Render post hero image
RG-247 Render post metadata
RG-248 Render voting panels on post page
RG-249 Render comments section on post page
RG-250 Render share panel on post page
RG-251 Render related posts placeholder
RG-252 Add SEO title for post page
RG-253 Add open graph metadata placeholder
```
---

# 13. Release

После завершения Phase 12:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.1.3-phase12-post-show-page
git push -u origin release/v0.1.3-phase12-post-show-page
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.1.3-phase12-post-show-page -m "RateGuru Phase 12 post show page"
git push origin v0.1.3-phase12-post-show-page
```
