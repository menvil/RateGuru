# Phase 50 — URL Import Requirements Audit

## Target UX

1. User opens upload form and selects "Import from URL".
2. User pastes a URL.
3. System fetches title, description, and image where available.
4. Preview is shown; user can edit all fields.
5. User confirms — post is created.
6. If import fails, a clear message is shown and manual upload is suggested.

---

## Supported URL Types (MVP)

| Type | Support |
|------|---------|
| Direct image URL (JPEG, PNG, WebP) | Full |
| Generic public page with OpenGraph meta | Full |
| Public Facebook page with OG meta | Best-effort |
| Public Instagram post with OG meta | Best-effort |
| Public X/Twitter post with OG meta | Best-effort |
| Pinterest pin with OG meta | Best-effort |

---

## Unsupported URL Types

- Private/login-gated content on any platform
- Pages without any OG/Twitter meta and no extractable title
- Video URLs
- PDF/document URLs
- `file://`, `ftp://`, or non-HTTP schemes
- Internal/private network addresses
- URLs requiring cookies or session tokens

---

## SSRF Risks

URL import is an attack vector if not guarded. The following must be blocked:

- `localhost` and `127.0.0.0/8`
- IPv6 loopback `::1`
- Private ranges: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`
- Link-local: `169.254.0.0/16` (includes AWS metadata endpoint `169.254.169.254`)
- Any non-HTTP/HTTPS scheme (`file://`, `ftp://`, etc.)
- Redirect targets that resolve to any of the above

Validation must happen on the original URL **and** on each redirect destination.

---

## Social Provider Limitations

### Facebook

- Logged-out OG scraping works for some public pages.
- Many post URLs return 403 or redirect to login without cookies.
- Facebook frequently updates its HTML and may break scrapers.
- **Phase 50 behaviour:** try OG, fail gracefully with "unsupported" message.

### Instagram

- Most content requires authentication.
- Public embed OG meta may be present for some posts, but is unreliable.
- Instagram actively blocks headless and unauthenticated fetchers.
- **Phase 50 behaviour:** try OG, fail gracefully.

### X / Twitter

- Cards/OG meta present on public tweets in some configurations.
- `x.com` and `twitter.com` may rate-limit or block unauthenticated fetchers.
- **Phase 50 behaviour:** try OG, fail gracefully.

---

## Image Download Risks

- Content-Type must be validated — do not trust the URL extension alone.
- Response size must be capped (default: 8 MB).
- MIME must be in the allowed list (JPEG, PNG, WebP).
- Images must go through the same upload validation pipeline as manual uploads.
- Do not store the image during preview — store only on user confirm.

---

## Copyright / User Responsibility

- The system does not verify ownership or licensing of imported content.
- A confirmation step (user submits the post) puts responsibility on the user.
- The UI should not suggest that imported content is free to use.
- Terms of Service should cover user responsibility for uploaded/imported content.

---

## Fallback Manual Upload Flow

When import fails, the user sees:

> This URL cannot be imported automatically. Download the image and upload it manually.

The upload form remains accessible so the user can complete the post via normal file upload.

---

## What is NOT in Phase 50

- OAuth flows for any platform
- Facebook Graph API / Instagram API / X API integration
- Login or cookie-based scraping
- Headless browser scraping
- Bypassing anti-bot protections
- Importing private/protected content
- Video import
- Batch import
- Background queue import jobs
