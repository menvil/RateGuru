# Phase 49 — Sharing Audit

## Current State (before Phase 49)

### canonical URL

`canonical_post_url(Post $post)` helper exists in `app/Support/helpers.php`.
Calls `PostUrl::canonical($post)` which returns `config('app.url') + route('posts.show', $post)`.

Route name: `posts.show` (defined in `routes/web.php`).

canonical link tag is NOT present in the layout head — only OG/Twitter meta tags push to `@stack('meta')`.

### OpenGraph meta tags

Present in `resources/views/livewire/posts/post-show.blade.php` via `@push('meta')`:
- `og:type`
- `og:title`
- `og:description`
- `og:url`
- `og:image`
- `meta name="description"`
- `twitter:card`
- `twitter:title`
- `twitter:description`
- `twitter:image`

Served by `PostOpenGraph` service (`app/Support/Seo/PostOpenGraph.php`).

Image falls back to `images/og/rateguru-post-placeholder.svg` when no post image.

Missing: canonical `<link rel="canonical">` tag.

### Share buttons

Feature flag: `show_share_buttons` in `ProjectSettingsManager` — default `true`.

**Post card** (`resources/views/components/feed/post-card.blade.php`):
- Share icon button opens Alpine `shareOpen` modal.
- Modal renders `post-share-panel.blade.php` (copy link only).

**Post drawer** (`resources/views/livewire/feed/post-drawer.blade.php`):
- Share icon button opens Alpine `shareOpen` modal.
- Modal renders `post-share-panel.blade.php` (copy link only).

**Post show** (`resources/views/livewire/posts/post-show.blade.php`):
- NO share buttons at all.

### Existing share components

`resources/views/components/share/post-share-panel.blade.php`:
- Renders a URL input + copy button (clipboard API).
- Hardcoded "Copy the public post URL." label.
- No social platform providers.

`resources/views/components/share/copy-link-button.blade.php`:
- Reusable copy button with clipboard API + execCommand fallback.
- Alpine.js for state.

### Missing providers

All social providers are absent:
- Facebook
- X / Twitter
- Telegram
- WhatsApp
- Reddit
- Pinterest
- Email

Native Web Share API is not implemented.

### Feature flag behaviour

`@if($projectSettings->featureEnabled('show_share_buttons'))` guards both card and drawer share UI.
Post show has no share UI at all regardless of flag.

### Mobile state

Post card and drawer share modals render inside standard `<x-ui.modal>` — responsive but limited.
No compact share entry optimised for mobile.
No Web Share API for native mobile sheet.

### Dark / Light theme state

Existing share components use `rg-*` CSS token classes — compatible with both themes.

### i18n labels

Partial keys exist in `lang/*/ui.php`:
- `ui.share.title` — "Share" / "Поделиться" / "Сподели"
- `ui.post.share_title` — "Share this post" / ...

Dedicated `lang/*/sharing.php` files do NOT exist.
Copy/Copied labels are hardcoded in English in the components.

## Risks and Target Tasks

| Risk | Target task |
|------|------------|
| No social providers | RG-780 ShareUrlBuilder, RG-784–RG-786 provider UIs |
| No reusable ShareButtons | RG-787 |
| Post show has no share UI | RG-788 |
| No canonical link tag | RG-779 |
| Hardcoded labels | RG-791 sharing.php translations |
| No Web Share API | RG-783 |
| No share config | RG-776 |
| No ShareProvider enum | RG-777 |
| No PostShareMetadata service | RG-778 |
| No browser sharing tests | RG-793 |
