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

`app/Enums/ShareProvider.php` — PHP enum with all 9 providers. Use `ShareProvider::tryFrom($string) !== null` to check validity and `ShareProvider::urlProviders()` for providers that generate URLs.

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

### Social buttons link through our own redirect

Ad blockers' social lists hide every link to a provider's share page (`facebook.com/sharer`, `twitter.com/intent/tweet`, `t.me/share`, `wa.me`, `reddit.com/submit`, `pinterest.com/pin/create`). A visitor with one sees only copy link and email. So the social buttons link to `GET /posts/{post}/share/{provider}` (`posts.share`, `app/Http/Controllers/Sharing/SharePostController.php`) instead, and that answers with a redirect to the URL `ShareUrlBuilder` builds. A link on our own domain is left alone.

`ShareProvider::sharesThroughRedirect()` decides which providers go this way. Email stays a plain `mailto:` link: no blocker hides it.

### X, Telegram, WhatsApp and email get the picture itself

A share link (`x.com/intent/post`, `t.me/share/url`, `wa.me`, `mailto:`) carries text and nothing else. The app sees the picture only as its own preview of our page, if it builds one at all. So for the providers where `ShareProvider::takesPicture()` holds, the button hands over the post's picture as a file. That picture is `PostImagePresenter::shareUrl()`: the whole picture at the detail size, not the Open Graph crop. The text and the link go with it as the caption.

`rgSharePicture` in `resources/js/share.js` fetches the picture once the buttons come into view. A feed renders a share panel for every post, and most are never opened. A click then does one of three things:

- **Share sheet.** With a coarse pointer (a phone), and where `navigator.canShare` accepts the file, the button opens the system share sheet with the picture, captioned with the text and the link. The sheet is where the apps are on a phone.
- **Clipboard.** Elsewhere, where a page may put a picture on the clipboard, the button copies the picture as PNG. It then shows "Picture copied. Open X and paste it in." with a link that opens the app. A desktop's share sheet rarely lists X or WhatsApp.
- **Plain link.** Wherever neither works, or the picture isn't fetched yet, the button stays the plain link it always was.

The others keep their link:
- **Facebook** drops any text a share brings along, so a picture would reach it without the link back to the post. Its preview of the link carries the picture anyway.
- **Reddit** shows the picture as a link post's thumbnail.
- **Pinterest** makes the pin from the picture already.

The native share button shares the same picture file when the sheet takes files.

### The redirect's 404s

The redirect only knows posts their own page would show (`PublishedPostDetailsQuery`). It answers 404 for a pending, rejected or hidden post, for a provider switched off in `config/share.php`, and for one without a share page.

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

## External Import Is Not Part of Phase 49

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
