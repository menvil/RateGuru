# Phase 49 — Extended Sharing Review Checklist

## Architecture

- [x] ShareUrlBuilder exists (`app/Support/Sharing/ShareUrlBuilder.php`)
- [x] ShareProvider enum exists (`app/Enums/ShareProvider.php`)
- [x] ShareMetadata value object exists (`app/Support/Sharing/ShareMetadata.php`)
- [x] PostShareMetadata service exists (`app/Support/Sharing/PostShareMetadata.php`)
- [x] config/share.php exists with all 9 providers

## OpenGraph and Twitter Meta Tags

- [x] OpenGraph — `og:type`, `og:title`, `og:description`, `og:url`, `og:image` on post show
- [x] Twitter/X card — `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`
- [x] Canonical `<link rel="canonical">` tag on post show
- [x] `twitter:card` uses `summary_large_image` when image exists, `summary` otherwise
- [x] Image URL is absolute

## Web Share API

- [x] `resources/js/share.js` exports `window.rgNativeShare`
- [x] Native share button hidden when `navigator.share` not available
- [x] No platform SDK required
- [x] `data-testid="share-native"` present in component

## Copy Link

- [x] `copy-link-button.blade.php` has `data-testid="share-copy-link"`
- [x] Uses `navigator.clipboard` with `execCommand` fallback
- [x] Manual copy fallback input shown if clipboard fails
- [x] Success state shows translated "Copied" label

## Provider Links

- [x] Facebook: `facebook.com/sharer/sharer.php?u={url}`
- [x] X: `twitter.com/intent/tweet?url={url}&text={text}`
- [x] Telegram: `t.me/share/url?url={url}&text={text}`
- [x] WhatsApp: `wa.me/?text={text}+{url}`
- [x] Reddit: `reddit.com/submit?url={url}&title={title}`
- [x] Pinterest: `pinterest.com/pin/create/button` — only when image exists
- [x] Email: `mailto:?subject={title}&body={desc}+{url}`
- [x] All links have `target="_blank" rel="noopener noreferrer"`
- [x] All links use `data-testid="share-{provider}"`

## ShareButtons Component

- [x] `resources/views/components/sharing/share-buttons.blade.php` exists
- [x] `app/View/Components/Sharing/ShareButtons.php` exists
- [x] Uses PostShareMetadata to build metadata
- [x] Uses ShareUrlBuilder to build URLs
- [x] Respects `config/share.php` provider toggles
- [x] Skips Pinterest when no image
- [x] `data-testid="share-buttons"` present

## Integration

- [x] ShareButtons integrated into post show (guarded by feature flag)
- [x] ShareButtons integrated into post drawer modal
- [x] Post card has compact share entry (`data-testid="post-card-share"`)
- [x] Post card share modal uses ShareButtons

## Feature Flag

- [x] `show_share_buttons` flag hides share UI when `false`
- [x] OG/Twitter meta tags remain regardless of flag (SEO tags)
- [x] Feature flag respected on post show, drawer, and card

## Translations

- [x] `lang/en/sharing.php` exists with all keys
- [x] `lang/ru/sharing.php` exists with all keys
- [x] `lang/bg/sharing.php` exists with all keys
- [x] All provider labels use `__('sharing.{provider}')` — no hardcoded strings

## Mobile / Theme

- [x] ShareButtons uses `flex flex-wrap min-w-0` — no horizontal overflow
- [x] All buttons use `rg-*` CSS tokens (theme-safe)
- [x] Focus states use `focus-visible:ring-rg-accent`
- [x] Provider links use `min-h` for mobile tap target safety

## Tests

- [x] Audit doc exists (`docs/sharing/phase-49-sharing-audit.md`)
- [x] config/share.php tests pass
- [x] ShareProvider enum tests pass
- [x] PostShareMetadata tests pass
- [x] OG/Twitter meta tag tests pass
- [x] ShareUrlBuilder tests pass
- [x] Full provider URL tests pass
- [x] Copy link button component test passes
- [x] Native Web Share JS test passes
- [x] ShareButtons component test passes
- [x] Post show integration test passes
- [x] Post drawer integration test passes
- [x] Post card share entry test passes
- [x] Translation key tests pass for en/ru/bg
- [x] Mobile/theme polish tests pass
- [x] Browser smoke tests written (`tests/Browser/SharingSmokeTest.php`)

## Out of Scope — Explicitly Not Part of Phase 49

**external import is not part of Phase 49**

- External URL import (paste Instagram/Facebook/X link → create post)
- Official social APIs (Facebook Graph API, Twitter API v2, etc.)
- OAuth or social login
- Auto-posting to user social accounts
- Social media scraping
- Server-side share count tracking
- Analytics events pipeline
- Short link / URL shortener service
- QR code generation
- Dynamic OG image generation
- React / Vue / Inertia

## Release

```bash
git checkout develop && git pull origin develop
composer test
npm run build
git checkout -b release/v0.3.6-phase49-extended-sharing
git push -u origin release/v0.3.6-phase49-extended-sharing
```

PR this branch into `main`.
