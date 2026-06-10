# Extended Sharing System

## Overview

Phase 49 adds full outbound sharing for posts via share URLs, Web Share API, and copy link.

## Provider List

| Provider | Type | URL Template |
|----------|------|-------------|
| copy_link | Clipboard | `navigator.clipboard` |
| native | Web Share API | `navigator.share()` |
| facebook | URL | `facebook.com/sharer/sharer.php?u={url}` |
| x | URL | `twitter.com/intent/tweet?url={url}&text={text}` |
| telegram | URL | `t.me/share/url?url={url}&text={text}` |
| whatsapp | URL | `wa.me/?text={text}+{url}` |
| reddit | URL | `reddit.com/submit?url={url}&title={title}` |
| pinterest | URL (image required) | `pinterest.com/pin/create/button/?url={url}&media={imageUrl}&description={desc}` |
| email | mailto | `mailto:?subject={title}&body={desc}+{url}` |

## Architecture

### ShareProvider Enum

`app/Enums/ShareProvider.php` — PHP enum with all 9 providers. Use `ShareProvider::isValid($string)` to check validity and `ShareProvider::urlProviders()` for providers that generate URLs.

### ShareMetadata Value Object

`app/Support/Sharing/ShareMetadata.php` — Immutable value object holding `title`, `description`, `url`, `imageUrl`, `siteName`.

### PostShareMetadata Service

`app/Support/Sharing/PostShareMetadata.php` — Builds `ShareMetadata` from a `Post` model. Uses `PostOpenGraph` for title/description and `PostUrl` for canonical URL. Image URL is absolutified if relative.

### ShareUrlBuilder Service

`app/Support/Sharing/ShareUrlBuilder.php` — Takes `ShareProvider` enum + `ShareMetadata`, returns the share URL string. Returns `null` for `copy_link`, `native`, and `pinterest` without image.

### ShareButtons Component

`app/View/Components/Sharing/ShareButtons.php` + `resources/views/components/sharing/share-buttons.blade.php`

Assembles all share pieces into one reusable component. Reads `config/share.php` for provider enable/disable toggles.

Usage:
```blade
<x-sharing.share-buttons :post="$post" />
```

### Native Web Share

`resources/js/share.js` — exports `window.rgNativeShare({title, text, url})` Alpine.js component data. The `<x-share.native-share-button>` component wraps it and shows only when `navigator.share` is available.

## Why Share URLs instead of Platform SDKs

- No JavaScript SDKs (Facebook SDK, Twitter widgets) required
- No API keys needed
- No third-party tracking scripts
- Simpler, faster, more privacy-respecting
- Works offline-first (links still render)

## Web Share API Behavior

- Shows only on devices/browsers where `navigator.share` is supported
- On unsupported browsers: button is hidden via Alpine `x-show="supported"` + `x-cloak`
- No forced fallback — the regular provider links are always available

## Copy Link Fallback

1. `navigator.clipboard.writeText()` — modern browsers on HTTPS
2. `document.execCommand('copy')` — legacy fallback
3. Manual: if both fail, shows input selected for manual copy

## OG / Twitter Meta Tags

Post show page (`resources/views/livewire/posts/post-show.blade.php`) pushes to `@stack('meta')`:
- `<link rel="canonical">`
- `og:type`, `og:title`, `og:description`, `og:url`, `og:image`
- `twitter:card` (summary if no image, summary_large_image if image exists)
- `twitter:title`, `twitter:description`, `twitter:image`

Served by `PostOpenGraph` service (`app/Support/Seo/PostOpenGraph.php`).

## Pinterest Image Requirement

Pinterest requires a media (image) URL. `ShareUrlBuilder::build(pinterest, ...)` returns `null` when `$metadata->imageUrl` is null. The `ShareButtons` component skips the Pinterest link when the URL is null.

## Feature Flag Behavior

`show_share_buttons` in `ProjectSettings` / `ProjectSettingsManager`:
- `true` (default): share buttons shown on post-show, post-drawer, post-card
- `false`: all share UI hidden
- OG/Twitter meta tags remain even when flag is off (they're SEO tags, not UI)

## Mobile / Theme

- `ShareButtons` container uses `flex flex-wrap min-w-0 gap-2` — buttons wrap on narrow screens
- All buttons use `rg-*` CSS tokens — compatible with light/dark themes
- Focus states use `focus-visible:ring-rg-accent` for keyboard accessibility
- `min-h-[34px]` instead of fixed `h-[34px]` on provider links for text wrapping safety

## i18n

All labels come from `lang/{en,ru,bg}/sharing.php`. No hardcoded English strings in share components.

## external import is not part of Phase 49

Phase 49 is outbound sharing only. The following are explicitly out of scope:
- External URL import (paste Instagram/Facebook/X link → create post)
- Official social APIs (Facebook Graph API, Twitter API, etc.)
- OAuth / social login
- Auto-posting to user social accounts
- Social scraping
- Share count analytics pipeline
- Short link service
- QR code generation
- Dynamic OG image generation

These belong in a separate Phase 50 if needed.
