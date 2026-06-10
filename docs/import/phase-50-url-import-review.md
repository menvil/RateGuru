# Phase 50 — URL Import Review Checklist

## Core Infrastructure

- [x] `docs/import/phase-50-url-import-audit.md` exists
- [x] `config/import.php` exists with safe defaults (timeouts, byte limits, schemes, providers)
- [x] `app/Enums/ImportProvider.php` exists (direct_image, open_graph, facebook, instagram, x, pinterest, unsupported)

## Security

- [x] `UrlImportValidator` exists — blocks localhost, private IPs, link-local, loopback, file:// ftp://
- [x] SSRF protection tests pass (11 tests covering all private ranges)
- [x] `SafeImportHttpClient` exists — wraps UrlImportValidator, enforces timeout/connect_timeout/max_redirects/max_bytes
- [x] Imported image validation uses existing MIME/size guards (same as normal upload)
- [x] `StoreImportedImageAction` downloads image only on confirm, not during preview

## Import Adapters

- [x] `DirectImageImportAdapter` exists — handles direct image URLs by Content-Type
- [x] `OpenGraphParser` exists — parses og:title, og:description, og:image, twitter fallbacks, resolves relative URLs
- [x] `OpenGraphMetadata` DTO exists
- [x] `OpenGraphImportAdapter` exists — fetches page, builds ImportPreview, validates image URL
- [x] `ImportProviderDetector` exists — detects direct_image / instagram / facebook / x / pinterest / open_graph

## Domain

- [x] `ImportPreview` DTO exists — provider, sourceUrl, title, description, imageUrl, warnings, unsupportedReason
- [x] `ImportPreview::hasImage()` works
- [x] `ImportPreview::isSupported()` works

## Action

- [x] `ImportFromUrlAction` exists — orchestrates validate → detect → adapt → preview
- [x] Generic OG import works
- [x] Direct image import works
- [x] Social providers handled best-effort; return unsupported preview on failure
- [x] Unsafe URL throws `UnsafeImportUrlException`

## Feature Flag

- [x] `allow_url_imports` flag added to `ProjectSettingsManager::DEFAULTS`
- [x] `UrlImportDisabledException` exists
- [x] `ImportFromUrlAction` checks flag before proceeding
- [x] Default is `true`

## UI

- [x] `ImportUrlForm` Livewire component exists
- [x] Form hidden when `allow_url_imports` flag is disabled
- [x] URL input, import button, preview section, use-preview button
- [x] Error messages shown (unsafe, timeout, unsupported)
- [x] Manual upload hint shown on unsupported errors
- [x] `import-preview-selected` event dispatched when user clicks "Use this"

## Upload Form Integration

- [x] `UploadPostForm` has `activeTab` property (upload / import)
- [x] `UploadPostForm` has `importedImageUrl` property
- [x] `UploadPostForm::applyImportPreview()` fills title/description/sourceUrl/importedImageUrl
- [x] Upload tab and import tab rendered when `allow_url_imports` is enabled
- [x] `livewire:import.import-url-form` embedded in upload form

## Storage

- [x] `StoreImportedImageAction` downloads URL as `UploadedFile`
- [x] MIME type validated against `config/import.allowed_image_mimes`
- [x] Size validated against `config/import.max_image_bytes`
- [x] Unsafe image URLs rejected via `UrlImportValidator`

## Error Handling

- [x] `UnsafeImportUrlException` — for SSRF/invalid scheme
- [x] `ImportFetchException` — for HTTP errors, timeout, size limit
- [x] `UrlImportDisabledException` — for disabled feature flag
- [x] All exceptions mapped to user-friendly messages in `ImportUrlForm`
- [x] Internal exception class names never exposed in HTML

## Translations

- [x] `lang/en/import.php` exists
- [x] `lang/ru/import.php` exists
- [x] `lang/bg/import.php` exists
- [x] All required keys present: from_url, paste_url, import, preview, use_this, cancel, loading, errors.*, manual_upload_hint

## Browser Smoke Tests

- [x] `tests/Browser/ImportUrlBrowserTest.php` exists
- [x] Import tab present in upload modal
- [x] Import URL form visible after clicking import tab
- [x] Import URL input present

## Documentation

- [x] `docs/import/url-import.md` exists
- [x] Security limits documented
- [x] Social provider limitations documented honestly
- [x] Manual fallback documented

## Test Suite

- [x] `composer test` passes (all 1757+ tests green)
- [x] `npm run build` passes
- [x] Pint clean on all new files
- [x] RawColorGuard passes (no forbidden color classes in new views)

## Explicit "Not Done" (by design)

- no OAuth added
- no headless browser scraping added
- no Facebook Graph API integration
- no Instagram API integration
- no X (Twitter) API integration
- no login/cookie scraping
- no bypassing anti-bot protections
- no importing private/protected content
- no video import
- no batch import
- no background queue import
