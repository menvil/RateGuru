# RateGuru — Phase 32 Implementation Plan

Версия: 1.0  
Проект: **RateGuru**  
Репозиторий: `https://github.com/menvil/rateguru`  
Фаза: **Phase 32 — Share & URL Behavior**  
Диапазон задач: **RG-545 → RG-552**  
Основа нумерации: исходный atomic backlog, где Phase 32 начинается с задачи 545 и заканчивается задачей 552.  
Язык заголовков задач: **English**  
Язык описаний задач: **русский**
---

# 1. Главная фиксация

Phase 32 соответствует исходному блоку:

```txt
Phase 32 — Share & URL Behavior
```

Правильный диапазон Phase 32:

```txt
RG-545 — Add canonical post URL helper
RG-546 — Add copy link button component
RG-547 — Add Alpine copy-to-clipboard behavior
RG-548 — Add share panel to PostDrawer
RG-549 — Add share panel to PostShow
RG-550 — Add Open Graph image placeholder
RG-551 — Add Open Graph title for posts
RG-552 — Add Open Graph description for posts
```

Префикс `RG-` используется вместо `PLR-`, потому что проект называется **RateGuru**.  
Номера остаются синхронизированными с исходным планом.

Важно: Phase 33 начинается с `RG-553` и делает **Hot Score & Ranking**. Поэтому Phase 32 не должна менять ranking/feed sorting/hot_score.
---

# 2. Цель Phase 32

Phase 32 добавляет стабильное поведение URL и share-слой для постов.

После Phase 32 должно быть готово:

```txt
- canonical post URL helper;
- reusable copy link button;
- Alpine copy-to-clipboard behavior;
- share panel в PostDrawer;
- share panel в PostShow;
- Open Graph image placeholder;
- Open Graph title для post pages;
- Open Graph description для post pages.
```

Это не social network integration phase. Здесь нужен базовый, надёжный URL/copy/metadata слой.
---

# 3. Scope Phase 32

## Входит

```txt
- единый helper/cast/accessor для canonical post URL;
- reusable Blade component для copy link button;
- Alpine clipboard behavior;
- fallback behavior when Clipboard API unavailable;
- share panel in PostDrawer;
- share panel in PostShow;
- OG image placeholder;
- OG title for post show pages;
- OG description for post show pages;
- tests for helper, component output, PostDrawer/PostShow rendering, metadata rendering.
```

## Не входит

```txt
- social SDK integrations;
- Facebook/Twitter/X/Telegram share widgets with external scripts;
- generated OG images;
- dynamic image composition;
- short links;
- tracking params / UTM builder;
- share analytics;
- QR codes;
- public API;
- ranking/hot score changes;
- SEO sitemap;
- robots.txt;
- canonical tags for every page type.
```

Если нужен красивый generated OG image, это отдельная image-generation/SEO phase, не Phase 32.
---

# 4. Product / UX Decisions

## 4.1. Canonical post URL is the source of truth

Все места должны использовать один источник:

```txt
canonical post URL helper
```

Нельзя в разных местах собирать URL руками:

```php
'/posts/' . $post->id
url('/post/' . $post->slug)
route('posts.show', $post)
```

Иначе через несколько фаз начнётся рассинхрон: copy button копирует одно, OG даёт другое, drawer ведёт третье.

## 4.2. Canonical URL should use existing posts.show route

Ранее Phase 12 добавляла:

```txt
posts.show route
PostShow Livewire component
```

Phase 32 должна опираться на этот route.

Recommended helper:

```php
canonical_post_url(Post $post): string
```

или service:

```php
PostUrl::canonical($post): string
```

Рекомендация: сервис-класс лучше, чем глобальный helper, потому что тестируется чище:

```txt
app/Support/Urls/PostUrl.php
```

Но backlog говорит helper. Компромисс:

```txt
- создать PostUrl service;
- добавить thin helper canonical_post_url($post), который вызывает service.
```

## 4.3. Absolute URL, not relative URL

Canonical URL должен быть absolute:

```txt
https://rateguru.example/posts/123
```

Не relative:

```txt
/posts/123
```

Почему:

```txt
- clipboard share должен работать вне сайта;
- Open Graph requires absolute URLs;
- future notifications can reuse URL.
```

Tests should set app URL:

```php
config(['app.url' => 'https://rateguru.test']);
```

## 4.4. Hidden/pending posts should not expose shareable URLs to normal users

Helper может технически вернуть URL для любого post, но UI share panels должны быть доступны только для publicly visible posts:

```txt
published post → share panel visible
pending/hidden/rejected post → share panel hidden for normal public UI
```

Moderators/admin can still open admin resources. Public share URL remains post show URL, which should already block hidden posts from normal users.

## 4.5. Copy link button should not depend on a social platform

Button behavior:

```txt
click → copy canonical URL to clipboard → show “Copied” state
```

No external SDKs.  
No tracking script.  
No redirect service.

## 4.6. Clipboard fallback

Modern browsers support:

```js
navigator.clipboard.writeText(url)
```

Fallback:

```txt
select hidden input + document.execCommand('copy')
```

If fallback fails, show safe error state:

```txt
Could not copy. Select and copy the link manually.
```

Do not crash Alpine.

## 4.7. Share panel contents

MVP share panel should contain:

```txt
- label: Share this post;
- readonly canonical URL input;
- copy link button;
- optional direct open link.
```

Do not add full social share buttons unless simple `<a>` links are explicitly needed later.

## 4.8. Open Graph image placeholder

Backlog says:

```txt
Add Open Graph image placeholder
```

That does not mean dynamic OG images.

Correct Phase 32 implementation:

```txt
- use post image if it is public absolute URL and safe;
- otherwise use static RateGuru OG placeholder image;
- ensure og:image is absolute URL.
```

Recommended static file:

```txt
public/images/og/rateguru-post-placeholder.png
```

If real image asset is not available yet, create doc/TODO and use existing logo/placeholder path.

Do not generate images server-side.

## 4.9. Open Graph title

For post show page:

```txt
og:title = {post.title} · RateGuru
```

Also set regular page title if Phase 12 did not finish it:

```txt
<title>{post.title} · RateGuru</title>
```

But do not rewrite global SEO system.

## 4.10. Open Graph description

Description should be safe and short:

```txt
first 140–180 chars of post.description, stripped of HTML
```

Fallback:

```txt
See and rate this post on RateGuru.
```

Do not expose moderation data, reports count, admin notes, or hidden statuses.
---

# 5. Architecture Rules

## 5.1. Centralize URL generation

Create one central URL generator:

```txt
app/Support/Urls/PostUrl.php
```

Example:

```php
final class PostUrl
{
    public function canonical(Post $post): string
    {
        return route('posts.show', $post, absolute: true);
    }
}
```

If route expects slug/id manually, adapt:

```php
route('posts.show', ['post' => $post->getRouteKey()], absolute: true)
```

## 5.2. Helper is thin

If adding global helper:

```txt
app/Support/helpers.php
```

Then helper should only delegate:

```php
function canonical_post_url(Post $post): string
{
    return app(PostUrl::class)->canonical($post);
}
```

Do not put route logic in multiple Blade files.

## 5.3. CopyLinkButton is reusable

Create Blade component:

```txt
resources/views/components/share/copy-link-button.blade.php
```

Props:

```txt
url required
label optional default Copy link
copiedLabel optional default Copied
```

Do not hardcode post-specific logic inside the button.

## 5.4. SharePanel can be a Blade component

Recommended:

```txt
resources/views/components/share/post-share-panel.blade.php
```

Props:

```txt
post
url optional
```

It can call `canonical_post_url($post)` internally, but better pass URL from parent/component if available.

## 5.5. Open Graph metadata should be page-scoped

PostShow should push metadata to layout using existing stack/section pattern.

Common Laravel patterns:

```blade
@section('title', $title)
@push('meta') ... @endpush
```

or layout component props:

```blade
<x-app-layout :title="$title" :meta="$meta">
```

Use existing app layout convention. Do not invent a second layout system.

## 5.6. Escape all metadata

OG title/description must be escaped.

Do not output raw user-generated content:

```blade
<meta property="og:title" content="{!! $post->title !!}">
```

Correct:

```blade
<meta property="og:title" content="{{ $ogTitle }}">
```

## 5.7. No dependency on current request host except app.url

Canonical URL should use `config('app.url')`/route absolute URL.

Do not generate canonical URL from arbitrary `Host` header for security reasons.
---

# 6. GitFlow для Phase 32

## Base branch

Все задачи Phase 32 создаются от:

```txt
develop
```

## Branch format

```txt
feature/RG-545-add-canonical-post-url-helper
feature/RG-548-add-share-panel-to-post-drawer
feature/RG-552-add-open-graph-description-for-posts
```

## Commit format

```txt
RG-545: Add canonical post URL helper
RG-548: Add share panel to PostDrawer
RG-552: Add Open Graph description for posts
```

## Release branch

После выполнения `RG-545`–`RG-552`:

```txt
release/v0.2.13-phase32-share-url-behavior
```

## Tag

После merge release branch в `main`:

```txt
v0.2.13-phase32-share-url-behavior
```
---

# 7. TDD Rules for Phase 32

## Для canonical URL

Тестировать:

```txt
- helper/service returns absolute URL;
- URL uses posts.show route;
- URL is stable across components;
- no relative URL.
```

## Для copy link button

Тестировать rendered markup:

```txt
- button renders url;
- Alpine data exists;
- copied state text exists;
- fallback input exists if used.
```

Browser clipboard behavior manually checked; unit tests only verify markup.

## Для share panels

Тестировать:

```txt
- PostDrawer renders share panel for published post;
- PostShow renders share panel for published post;
- share panel includes canonical URL;
- hidden/pending posts do not expose share panel to normal user.
```

## Для Open Graph

Тестировать response HTML:

```txt
- og:image exists and is absolute;
- og:title includes post title;
- og:description uses sanitized/truncated description;
- fallback description works.
```
---

# 8. Universal Task Template

```txt
ID: RG-XXX
Title: English title
Area: URLs / Share / SEO / UI / Tests
Type: Test / Feature / Component / Metadata / Layout
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
- URL generation centralized
- No social SDK / external script scope creep
- Коммит содержит ID задачи

Files likely touched:
- path/to/file
```
---

# 9. Phase 32 Atomic Tasks
---

## RG-545 — Add Canonical Post URL Helper

**Area:** URLs / Backend  
**Type:** Feature / Helper  
**Priority:** P0  
**Branch:** `feature/RG-545-add-canonical-post-url-helper`  
**Base branch:** develop
**Depends on:** RG-544

### Goal

Добавить единый способ получить canonical absolute URL для post.

### TDD step

Unit test for service/helper:

```php
it('returns absolute canonical post url', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $url = app(PostUrl::class)->canonical($post);

    expect($url)->toStartWith('https://rateguru.test');
    expect($url)->toBe(route('posts.show', $post, absolute: true));
});
```

Helper test:

```php
it('canonical_post_url helper delegates to post url service', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    expect(canonical_post_url($post))
        ->toBe(app(PostUrl::class)->canonical($post));
});
```

Если проект не использует global helper files, можно тестировать только service.

### Implementation

Создать:

```txt
app/Support/Urls/PostUrl.php
```

Implementation:

```php
namespace App\Support\Urls;

use App\Models\Post;

final class PostUrl
{
    public function canonical(Post $post): string
    {
        return route('posts.show', $post, absolute: true);
    }
}
```

Если route model binding uses slug:

```php
return route('posts.show', ['post' => $post->getRouteKey()], absolute: true);
```

Optional helper:

```txt
app/Support/helpers.php
```

```php
use App\Models\Post;
use App\Support\Urls\PostUrl;

function canonical_post_url(Post $post): string
{
    return app(PostUrl::class)->canonical($post);
}
```

If helper file is added, register it in `composer.json` autoload files and run:

```bash
composer dump-autoload
```

Do not scatter URL logic into Blade files.

### Acceptance criteria

- `PostUrl` service exists.
- Canonical URL is absolute.
- Canonical URL uses `posts.show` route.
- Optional `canonical_post_url()` helper exists and delegates to service.
- Tests pass.
- No share UI added yet.

### Definition of Done

- Tests written.
- Service/helper implemented.
- Autoload updated if helper file added.
- Tests pass.
- Коммит: `RG-545: Add canonical post URL helper`

### Files likely touched

```txt
app/Support/Urls/PostUrl.php
app/Support/helpers.php
composer.json
tests/Unit/Support/PostUrlTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-546 — Add Copy Link Button Component

**Area:** UI / Share  
**Type:** Blade Component  
**Priority:** P0  
**Branch:** `feature/RG-546-add-copy-link-button-component`  
**Base branch:** develop
**Depends on:** RG-545

### Goal

Создать reusable component для copy link button.

### TDD step

Blade component render test:

```php
it('renders copy link button with url', function () {
    $html = Blade::render(
        '<x-share.copy-link-button url="https://rateguru.test/posts/1" />'
    );

    expect($html)->toContain('Copy link');
    expect($html)->toContain('https://rateguru.test/posts/1');
    expect($html)->toContain('data-testid="copy-link-button"');
});
```

Custom label test:

```php
it('renders copy link button with custom label', function () {
    $html = Blade::render(
        '<x-share.copy-link-button url="https://rateguru.test/posts/1" label="Share" />'
    );

    expect($html)->toContain('Share');
});
```

### Implementation

Create Blade component:

```txt
resources/views/components/share/copy-link-button.blade.php
```

Initial markup:

```blade
@props([
    'url',
    'label' => 'Copy link',
    'copiedLabel' => 'Copied',
])

<div data-testid="copy-link-button" data-copy-url="{{ $url }}">
    <button type="button">
        {{ $label }}
    </button>

    <input
        type="text"
        readonly
        value="{{ $url }}"
        class="sr-only"
        data-testid="copy-link-url"
    >
</div>
```

Alpine behavior comes in RG-547. For now, the component is present and testable.

### Acceptance criteria

- Component exists at `x-share.copy-link-button`.
- Requires/provides URL.
- Renders default label.
- Supports custom label.
- Includes stable test ids.
- Does not implement clipboard behavior yet.
- Tests pass.

### Definition of Done

- Tests written.
- Component created.
- Tests pass.
- Коммит: `RG-546: Add copy link button component`

### Files likely touched

```txt
resources/views/components/share/copy-link-button.blade.php
tests/Feature/ViewComponents/CopyLinkButtonComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-547 — Add Alpine Copy-To-Clipboard Behavior

**Area:** UI / Alpine / Share  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-547-add-alpine-copy-to-clipboard-behavior`  
**Base branch:** develop
**Depends on:** RG-546

### Goal

Добавить Alpine behavior для копирования ссылки в clipboard.

### TDD step

Markup test:

```php
it('renders alpine copy to clipboard behavior', function () {
    $html = Blade::render(
        '<x-share.copy-link-button url="https://rateguru.test/posts/1" />'
    );

    expect($html)->toContain('x-data');
    expect($html)->toContain('copyToClipboard');
    expect($html)->toContain('navigator.clipboard');
    expect($html)->toContain('Copied');
});
```

Fallback markup test:

```php
it('renders manual copy fallback input', function () {
    $html = Blade::render(
        '<x-share.copy-link-button url="https://rateguru.test/posts/1" />'
    );

    expect($html)->toContain('data-testid="copy-link-fallback-input"');
});
```

### Implementation

Update `copy-link-button.blade.php`:

```blade
@props([
    'url',
    'label' => 'Copy link',
    'copiedLabel' => 'Copied',
])

<div
    x-data="{
        copied: false,
        failed: false,
        async copyToClipboard() {
            this.failed = false;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(this.$refs.copyInput.value);
                } else {
                    this.$refs.copyInput.select();
                    document.execCommand('copy');
                }

                this.copied = true;
                setTimeout(() => this.copied = false, 1600);
            } catch (error) {
                this.failed = true;
            }
        }
    }"
    data-testid="copy-link-button"
>
    <input
        x-ref="copyInput"
        data-testid="copy-link-fallback-input"
        type="text"
        readonly
        value="{{ $url }}"
        class="sr-only"
    >

    <button type="button" @click="copyToClipboard">
        <span x-show="! copied">{{ $label }}</span>
        <span x-show="copied" x-cloak>{{ $copiedLabel }}</span>
    </button>

    <p x-show="failed" x-cloak data-testid="copy-link-error">
        Could not copy. Select and copy the link manually.
    </p>
</div>
```

Keep styling consistent with RateGuru UI button styles.

### Acceptance criteria

- Button uses Alpine `x-data`.
- Uses Clipboard API when available.
- Has fallback input.
- Shows copied state.
- Shows safe error state on failure.
- No external JS package added.
- Markup tests pass.
- Manual browser check completed.

### Definition of Done

- Tests written.
- Alpine behavior added.
- Manual check done.
- Tests pass.
- Коммит: `RG-547: Add Alpine copy-to-clipboard behavior`

### Files likely touched

```txt
resources/views/components/share/copy-link-button.blade.php
tests/Feature/ViewComponents/CopyLinkButtonComponentTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-548 — Add Share Panel To PostDrawer

**Area:** UI / PostDrawer / Share  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-548-add-share-panel-to-post-drawer`  
**Base branch:** develop
**Depends on:** RG-547

### Goal

Добавить share panel в PostDrawer.

### TDD step

Livewire render test:

```php
it('renders share panel in post drawer for published post', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertSee('data-testid="post-drawer-share-panel"', false)
        ->assertSee(app(PostUrl::class)->canonical($post));
});
```

Hidden/pending guard test if drawer can be rendered by status:

```php
it('does not render public share panel in drawer for hidden post', function () {
    $post = Post::factory()->hidden()->create();

    Livewire::test(PostDrawer::class, ['postId' => $post->id])
        ->assertDontSee('data-testid="post-drawer-share-panel"', false);
});
```

Adapt if PostDrawer already rejects hidden posts before rendering.

### Implementation

Recommended shared component:

```txt
resources/views/components/share/post-share-panel.blade.php
```

Component:

```blade
@props(['post', 'url' => null])

@php
    $shareUrl = $url ?? canonical_post_url($post);
@endphp

<section data-testid="post-share-panel">
    <h3>Share this post</h3>

    <input
        type="text"
        readonly
        value="{{ $shareUrl }}"
        data-testid="post-share-url"
    >

    <x-share.copy-link-button :url="$shareUrl" />
</section>
```

In PostDrawer view:

```blade
@if($post->status === \App\Enums\PostStatus::Published)
    <div data-testid="post-drawer-share-panel">
        <x-share.post-share-panel :post="$post" />
    </div>
@endif
```

If PostDrawer only ever receives published posts, still keep status guard for safety.

### Acceptance criteria

- PostDrawer renders share panel for published post.
- Panel includes canonical absolute URL.
- Panel includes copy link button.
- Hidden/pending/rejected posts do not expose public share panel.
- Uses `PostUrl`/helper, no manual URL string.
- Tests pass.

### Definition of Done

- Tests written.
- Share panel component created if useful.
- PostDrawer integration added.
- Tests pass.
- Коммит: `RG-548: Add share panel to PostDrawer`

### Files likely touched

```txt
resources/views/components/share/post-share-panel.blade.php
resources/views/livewire/feed/post-drawer.blade.php
tests/Feature/Livewire/PostDrawerTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-549 — Add Share Panel To PostShow

**Area:** UI / PostShow / Share  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-549-add-share-panel-to-post-show`  
**Base branch:** develop
**Depends on:** RG-548

### Goal

Добавить share panel на full PostShow page.

### TDD step

Route/Livewire test:

```php
it('renders share panel on post show page', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('data-testid="post-show-share-panel"', false)
        ->assertSee(app(PostUrl::class)->canonical($post));
});
```

If PostShow is tested as Livewire component:

```php
Livewire::test(PostShow::class, ['post' => $post->getRouteKey()])
```

### Implementation

In PostShow view:

```blade
@if($post->status === \App\Enums\PostStatus::Published)
    <div data-testid="post-show-share-panel">
        <x-share.post-share-panel :post="$post" />
    </div>
@endif
```

Remove/replace old placeholder from Phase 12 if it exists:

```txt
PLR-250 — Render share panel on post page
```

If Phase 12 already added a placeholder, replace placeholder with real share panel instead of duplicating sections.

### Acceptance criteria

- PostShow renders share panel.
- Panel includes canonical absolute URL.
- Panel includes copy link button.
- Existing placeholder is replaced, not duplicated.
- Hidden post show behavior remains protected.
- Tests pass.

### Definition of Done

- Tests written.
- PostShow integration added.
- Tests pass.
- Коммит: `RG-549: Add share panel to PostShow`

### Files likely touched

```txt
resources/views/livewire/posts/post-show.blade.php
resources/views/components/share/post-share-panel.blade.php
tests/Feature/PostShowTest.php
tests/Feature/Livewire/PostShowTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-550 — Add Open Graph Image Placeholder

**Area:** SEO / Metadata  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-550-add-open-graph-image-placeholder`  
**Base branch:** develop
**Depends on:** RG-549

### Goal

Добавить `og:image` для post pages с безопасным placeholder fallback.

### TDD step

Feature response test:

```php
it('renders open graph image for post show page', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_path' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('https://rateguru.test/images/og/rateguru-post-placeholder.png', false);
});
```

If post image URL exists:

```php
it('uses post image as open graph image when available', function () {
    config(['app.url' => 'https://rateguru.test']);

    $post = Post::factory()->published()->create([
        'image_path' => 'posts/demo.jpg',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:image"', false);
});
```

Exact image URL depends on storage implementation; do not overfit if image URL accessor exists.

### Implementation

Create metadata helper/service if needed:

```txt
app/Support/Seo/PostOpenGraph.php
```

Or simple computed methods in PostShow:

```php
public function getOgImageProperty(): string
{
    if ($this->post->image_url) {
        return $this->post->image_url;
    }

    return asset('images/og/rateguru-post-placeholder.png');
}
```

Add static placeholder asset path:

```txt
public/images/og/rateguru-post-placeholder.png
```

If no real image asset can be committed now, add a small placeholder SVG/PNG or use existing logo asset. Do not link to non-existing file.

In layout/meta stack:

```blade
<meta property="og:image" content="{{ $ogImage }}">
<meta name="twitter:image" content="{{ $ogImage }}">
```

If layout has no `@stack('meta')`, add one to base layout:

```blade
@stack('meta')
```

### Acceptance criteria

- PostShow response includes `og:image`.
- Image URL is absolute.
- Placeholder exists and is not broken.
- If post image URL is available, it can be used.
- Adds twitter image tag if consistent with metadata pattern.
- Tests pass.

### Definition of Done

- Tests written.
- Placeholder asset/path added.
- Metadata rendered.
- Tests pass.
- Коммит: `RG-550: Add Open Graph image placeholder`

### Files likely touched

```txt
app/Livewire/Posts/PostShow.php
app/Support/Seo/PostOpenGraph.php
resources/views/livewire/posts/post-show.blade.php
resources/views/layouts/app.blade.php
public/images/og/rateguru-post-placeholder.png
tests/Feature/PostOpenGraphTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-551 — Add Open Graph Title For Posts

**Area:** SEO / Metadata  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-551-add-open-graph-title-for-posts`  
**Base branch:** develop
**Depends on:** RG-550

### Goal

Добавить `og:title` для post pages.

### TDD step

Feature response test:

```php
it('renders open graph title for post show page', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Best Pasta in Sofia',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Best Pasta in Sofia · RateGuru">', false);
});
```

Escaping test:

```php
it('escapes open graph title content', function () {
    $post = Post::factory()->published()->create([
        'title' => 'Pasta "Special" <script>',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('<script>', false);
});
```

### Implementation

In PostShow component/service:

```php
public function getOgTitleProperty(): string
{
    return trim($this->post->title) . ' · RateGuru';
}
```

In meta stack:

```blade
<title>{{ $ogTitle }}</title>
<meta property="og:title" content="{{ $ogTitle }}">
<meta name="twitter:title" content="{{ $ogTitle }}">
```

If layout already handles title via `@section('title')`, use existing pattern.

Do not use raw output.

### Acceptance criteria

- PostShow response includes `og:title`.
- Title includes post title and RateGuru brand.
- Title is escaped.
- Regular page title is consistent if layout supports it.
- No global SEO rewrite.
- Tests pass.

### Definition of Done

- Tests written.
- OG title added.
- Escaping verified.
- Tests pass.
- Коммит: `RG-551: Add Open Graph title for posts`

### Files likely touched

```txt
app/Livewire/Posts/PostShow.php
app/Support/Seo/PostOpenGraph.php
resources/views/livewire/posts/post-show.blade.php
resources/views/layouts/app.blade.php
tests/Feature/PostOpenGraphTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

## RG-552 — Add Open Graph Description For Posts

**Area:** SEO / Metadata  
**Type:** Feature  
**Priority:** P0  
**Branch:** `feature/RG-552-add-open-graph-description-for-posts`  
**Base branch:** develop
**Depends on:** RG-551

### Goal

Добавить `og:description` для post pages.

### TDD step

Description test:

```php
it('renders open graph description for post show page', function () {
    $post = Post::factory()->published()->create([
        'description' => 'A detailed review of a handmade pasta dish in Sofia.',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('<meta property="og:description" content="A detailed review of a handmade pasta dish in Sofia.">', false);
});
```

Fallback test:

```php
it('renders fallback open graph description when post has no description', function () {
    $post = Post::factory()->published()->create([
        'description' => null,
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee('See and rate this post on RateGuru.', false);
});
```

HTML stripping/truncation test:

```php
it('strips html and truncates open graph description', function () {
    $post = Post::factory()->published()->create([
        'description' => '<b>' . str_repeat('Long text ', 40) . '</b>',
    ]);

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertDontSee('<b>', false);
});
```

### Implementation

In PostShow component/service:

```php
public function getOgDescriptionProperty(): string
{
    $description = trim(strip_tags((string) $this->post->description));

    if ($description === '') {
        return 'See and rate this post on RateGuru.';
    }

    return Str::limit($description, 160, '');
}
```

In meta stack:

```blade
<meta property="og:description" content="{{ $ogDescription }}">
<meta name="description" content="{{ $ogDescription }}">
<meta name="twitter:description" content="{{ $ogDescription }}">
```

Add final review doc:

```txt
docs/design/phase-32-share-url-behavior-review.md
```

Checklist:

```txt
- canonical URL helper returns absolute URL;
- copy button works in browser;
- copied state visible;
- PostDrawer share panel checked;
- PostShow share panel checked;
- og:image absolute and not broken;
- og:title escaped;
- og:description stripped/truncated;
- no social SDKs added.
```

### Acceptance criteria

- `og:description` exists.
- Description uses post description when present.
- Fallback description works.
- HTML is stripped.
- Description is truncated to safe length.
- Metadata is escaped.
- Review doc exists.
- `composer test` passes.
- `npm run build` passes.

### Definition of Done

- Tests written.
- OG description added.
- Review note added.
- Tests/build pass.
- Коммит: `RG-552: Add Open Graph description for posts`

### Files likely touched

```txt
app/Livewire/Posts/PostShow.php
app/Support/Seo/PostOpenGraph.php
resources/views/livewire/posts/post-show.blade.php
resources/views/layouts/app.blade.php
docs/design/phase-32-share-url-behavior-review.md
tests/Feature/PostOpenGraphTest.php
```

После этого сделай MR в бранч develop как только сделается MR сразу мержи его на гитхабе и обновляй бранч develop локально

---

# 10. Phase 32 Completion Criteria

Phase 32 завершена, когда:

```txt
- RG-545–RG-552 выполнены;
- canonical post URL helper/service exists;
- canonical post URL is absolute;
- canonical post URL uses posts.show route;
- copy link button component exists;
- copy link button has Alpine clipboard behavior;
- copy fallback exists;
- PostDrawer has share panel;
- PostShow has share panel;
- share panels use canonical URL helper/service;
- share panels do not expose hidden/pending/rejected posts in public UI;
- PostShow renders og:image;
- og:image is absolute and has fallback placeholder;
- PostShow renders og:title;
- og:title is escaped;
- PostShow renders og:description;
- og:description is stripped/truncated/escaped;
- no social SDKs or external share scripts added;
- no hot_score/ranking changes added;
- composer test passes;
- npm run build passes.
```
---

# 11. Что нельзя делать в Phase 32

Без отдельной задачи нельзя:

```txt
- добавлять generated OG images;
- добавлять social SDK scripts;
- добавлять share analytics;
- добавлять short links;
- добавлять UTM builder;
- добавлять QR codes;
- добавлять sitemap;
- добавлять robots.txt;
- менять FeedQuery sorting;
- добавлять hot_score;
- добавлять ranking logic;
- добавлять API endpoint;
- добавлять Vue/React/Inertia.
```
---

# 12. Recommended Execution Order

```txt
RG-545 Add canonical post URL helper
RG-546 Add copy link button component
RG-547 Add Alpine copy-to-clipboard behavior
RG-548 Add share panel to PostDrawer
RG-549 Add share panel to PostShow
RG-550 Add Open Graph image placeholder
RG-551 Add Open Graph title for posts
RG-552 Add Open Graph description for posts
```
---

# 13. Release

После завершения Phase 32:

```bash
git checkout develop
git pull origin develop

composer test
npm run build
php artisan migrate:fresh

git checkout -b release/v0.2.13-phase32-share-url-behavior
git push -u origin release/v0.2.13-phase32-share-url-behavior
```

После этого шага сделай MR в main branch и после этого остановись

После review и merge в `main`:

```bash
git checkout main
git pull origin main

git tag -a v0.2.13-phase32-share-url-behavior -m "RateGuru Phase 32 Share & URL Behavior"
git push origin v0.2.13-phase32-share-url-behavior
```
