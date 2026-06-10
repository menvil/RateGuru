# URL Import — Developer Guide

## Overview

Phase 50 adds inbound import from URL. Users paste a link; the system fetches
title, description, and image where publicly available; the upload form is
pre-filled; the user confirms before the post is created.

---

## Supported Import Types

### Direct image URL

When a URL points directly to an image file (detected by file extension or
`Content-Type` header), the `DirectImageImportAdapter` is used.

Supported MIME types (configurable in `config/import.php`):

- `image/jpeg`
- `image/png`
- `image/webp`

The URL is set as the preview image. The file is not downloaded until the user
confirms the post.

### OpenGraph import

For generic public web pages, the `OpenGraphImportAdapter` fetches the HTML
and `OpenGraphParser` extracts:

- `og:title` / `twitter:title` / `<title>`
- `og:description` / `twitter:description` / `meta[name=description]`
- `og:image` / `og:image:secure_url` / `twitter:image`

Relative image URLs are resolved against the page URL.

---

## Social Provider Limitations

### Facebook

- Unauthenticated OG scraping works for some public pages.
- Many URLs return 403 or redirect to a login wall without cookies.
- **Phase 50 behaviour:** best-effort OG attempt; graceful unsupported fallback on failure.

### Instagram

- Most content requires authentication.
- Instagram actively blocks unauthenticated HTTP fetchers.
- **Phase 50 behaviour:** best-effort OG attempt; graceful unsupported fallback on failure.

### X / Twitter

- Public tweet OG cards may be accessible in some configurations.
- `x.com` may rate-limit or block unauthenticated requests.
- **Phase 50 behaviour:** best-effort OG attempt; graceful unsupported fallback on failure.

### Pinterest

- Some public pins expose OG meta.
- **Phase 50 behaviour:** best-effort OG attempt.

---

## Unsupported Providers

When a social provider blocks the request, an `ImportPreview` with
`unsupportedReason` is returned. The UI shows:

> This URL cannot be imported automatically. Download the image and upload it
> manually.

No exception is thrown to the user — it is a graceful degradation.

---

## SSRF Protections

`UrlImportValidator` blocks requests to:

| Range | Reason |
|-------|--------|
| `localhost` | Loopback |
| `127.0.0.0/8` | IPv4 loopback |
| `::1` | IPv6 loopback |
| `10.0.0.0/8` | Private range A |
| `172.16.0.0/12` | Private range B |
| `192.168.0.0/16` | Private range C |
| `169.254.0.0/16` | Link-local (incl. AWS metadata) |
| `file://`, `ftp://`, non-HTTP(S) schemes | Protocol restriction |

Validation is applied to the original URL. Redirect destinations are validated
by `SafeImportHttpClient` via `maxRedirects`.

---

## Fetch Limits

Configured in `config/import.php`:

| Setting | Default |
|---------|---------|
| `timeout_seconds` | 5 |
| `connect_timeout_seconds` | 2 |
| `max_redirects` | 3 |
| `max_html_bytes` | 1 MB |
| `max_image_bytes` | 8 MB |

---

## Feature Flag

```php
// config/import.php
'enabled' => env('IMPORT_FROM_URL_ENABLED', true),
```

Also controlled via `ProjectSettings`:

```php
'allow_url_imports' => true // in feature_flags
```

When disabled:
- `ImportUrlForm` hides the import UI
- `ImportFromUrlAction` throws `UrlImportDisabledException`

---

## Key Classes

| Class | Responsibility |
|-------|---------------|
| `UrlImportValidator` | SSRF protection, scheme validation |
| `SafeImportHttpClient` | HTTP fetch with timeout/redirect/size limits |
| `ImportProviderDetector` | Detect provider from URL |
| `DirectImageImportAdapter` | Handle direct image URLs |
| `OpenGraphParser` | Parse OG/Twitter meta from HTML |
| `OpenGraphImportAdapter` | Fetch page and build ImportPreview |
| `ImportPreview` | DTO — preview result |
| `ImportFromUrlAction` | Orchestrator action |
| `StoreImportedImageAction` | Download image as UploadedFile |
| `ImportUrlForm` | Livewire form component |

---

## Manual Upload Fallback

When import fails for any reason, the user sees a clear message and the upload
form remains accessible for a normal file upload. No dead end.

---

## What is NOT in Phase 50

- OAuth flows for any platform
- Facebook Graph API / Instagram API / X API
- Login or cookie-based scraping
- Headless browser scraping
- Bypassing anti-bot protections
- Importing private/protected content
- Video import
- Batch import
- Background queue import
