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

## Security Architecture

### The core invariant

> Every outbound request to a user-controlled import target connects only to
> an IP that was resolved and validated for that exact hop.

A naive "resolve hostname → validate the IP → `Http::get($hostname)`" design
leaves a DNS rebinding / TOCTOU gap: the HTTP client performs its own,
independent DNS lookup at connection time, which can legitimately differ from
whatever the validator saw a moment earlier (an attacker's nameserver can
answer truthfully once and answer with a private/loopback address the next
time). Every fetch in this pipeline instead flows through:

```text
UrlImportValidator::validate($url)
    → parses/normalizes the URL, rejects unsafe schemes/userinfo/ports/
      ambiguous numeric hosts
    → resolves the host via HostResolver (real DNS in production, a fake
      map in tests — never live DNS in the test suite)
    → validates every returned IP as public, rejecting the whole hostname
      on any private/reserved answer or a mixed public+private answer set
    → returns a ResolvedImportTarget (url, scheme, host, port, ip)

PinnedImportHttpTransport::get($target, $policy)
    → connects to $target->ip via curl's CURLOPT_RESOLVE, pinning the TCP
      connection to exactly that IP
    → Host header / TLS SNI / certificate hostname verification all still
      use $target->host, never the IP — normal, correct HTTPS to the
      caller's eyes
    → TLS verification is never disabled anywhere in this pipeline
```

The transport never performs its own DNS lookup of the hostname — it only
ever connects to the IP the validator already vetted for that specific URL.
Proxying is explicitly disabled per request (`'proxy' => ''`), regardless of
any `http_proxy`/`https_proxy`/`all_proxy` environment variable an ambient
deployment might have set: per curl's own documentation, once a proxy is in
play *the proxy* performs the destination's DNS resolution, not this
machine, which would make the CURLOPT_RESOLVE pin silently ineffective —
verified empirically (a request through an env-configured proxy connects to
the proxy's own port; with proxying disabled, it connects directly to the
pinned target instead).

### Redirects

Each redirect hop is treated as a brand new fetch: the `Location` header is
resolved against the current URL using RFC 3986 reference resolution
(`GuzzleHttp\Psr7\UriResolver`, not a hand-rolled relative-path resolver),
then the resulting absolute URL goes back through the full
`UrlImportValidator::validate()` → `PinnedImportHttpTransport::get()` cycle —
independently resolved, independently validated, independently pinned. A
public → private redirect is rejected before any connection to the private
target is attempted. `import.max_redirects` bounds how many hops are followed.

### URL / scheme / port policy

`UrlImportValidator` rejects:

- Any scheme not in `import.allowed_schemes` (currently `https` only — not
  widened by this work).
- Userinfo in the URL (`user:pass@host` or bare `token@host`).
- CR/LF/NUL/other control bytes anywhere in the URL.
- A missing host, a malformed authority, or a malformed IPv6 literal.
- Any port not in `import.allowed_ports` (`[80, 443]` by default) — this
  pipeline does not double as a port scanner against internal services (SSH,
  SMTP, MySQL, PostgreSQL, Redis, Elasticsearch, Memcached, etc.).
- Ambiguous numeric hosts that some resolvers/HTTP stacks would interpret as
  a raw IP even though they don't look like one (`127.1`, `2130706433`,
  `0177.0.0.1`, `0x7f000001`) — rejected outright rather than "supported."

### IPv4 / IPv6 address policy

`App\Support\Import\Net\PublicIpClassifier` is a single, centralized
classifier used for every IP this pipeline ever sees (URL literals, DNS
answers, redirect targets). It uses numeric/binary CIDR containment checks —
`ip2long()`+bitmask for IPv4, `inet_pton()` byte comparison for IPv6 — never
a string-prefix match, which is fragile against non-canonical
representations.

Blocked IPv4 ranges: `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (carrier-grade
NAT), `127.0.0.0/8`, `169.254.0.0/16` (incl. the `169.254.169.254` cloud
metadata endpoint), `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24`
(documentation), `192.168.0.0/16`, `198.18.0.0/15` (benchmarking),
`198.51.100.0/24` (documentation), `203.0.113.0/24` (documentation),
`224.0.0.0/4` (multicast), `240.0.0.0/4` (reserved).

Blocked IPv6 ranges: `::/128`, `::1/128`, `fc00::/7` (unique-local),
`fe80::/10` (link-local), `ff00::/8` (multicast), `2001:db8::/32`
(documentation). Three RFC-defined forms that embed a full IPv4 address in
their low 32 bits — `::ffff:a.b.c.d` (RFC4291 IPv4-mapped), `::a.b.c.d`
(RFC4291 IPv4-compatible, `::/96`), and `64:ff9b::a.b.c.d` (RFC6052 NAT64
well-known prefix, `64:ff9b::/96`) — are all unwrapped and the embedded IPv4
address is classified through the same IPv4 table, so
`::ffff:169.254.169.254` and `64:ff9b::169.254.169.254` are both blocked
exactly like the bare metadata-endpoint address is.

### DNS resolution

`App\Support\Import\Dns\HostResolver` is a small interface
(`resolve(string $host): list<string>`) production-implemented by
`DnsHostResolver` over `dns_get_record()` (A + AAAA), bounded to
`import.dns_max_answers` (16 by default) raw records. `UrlImportValidator`
then: rejects a zero-answer resolution, validates every returned IP as
public, rejects the whole hostname on any private answer or a mixed
public+private answer set, normalizes duplicates, and deterministically picks
the first validated IP for pinning. Tests bind a fake `HostResolver` — the
test suite never performs a live DNS lookup.

### Response streaming and size limits

`PinnedImportHttpTransport` enforces the byte cap in three layers:

1. **`on_headers`** rejects immediately once an honest, oversized
   `Content-Length` is seen — before any body byte is read.
2. **`progress`** (curl's own progress callback) aborts a genuinely large,
   actively-streaming body within a curl-buffer-sized margin of the cap,
   without waiting for it to finish — verified empirically against a
   multi-gigabyte unbounded response: it aborts in well under a second.
3. **An exact `strlen()` check** on the completed body is the byte-precise
   source of truth, and is what actually catches a missing, lying-small, or
   chunked `Content-Length` once the (already-bounded) transfer completes.

A custom PSR-7 sink stream was deliberately not used for this: Laravel's
`Http::fake()` test-faking mechanism calls a raw `fwrite()` on whatever
"sink" option is set, which requires a real PHP stream resource — an object
implementing `StreamInterface` fails there with a `TypeError`. Since this
transport has to keep working under `Http::fake()` for the rest of the test
suite, the cap logic lives entirely in the three hooks above, none of which
`Http::fake()` touches.

Transparent gzip/br decompression is disabled (`decode_content: false`): the
bytes the cap counts are then exactly the bytes received on the wire, so a
small compressed payload can't expand past the cap during a decode step the
app never performs.

### Remote images: two separate trust boundaries

> The network layer validates WHERE bytes come from. `ImageIngestor`
> validates WHAT those bytes are.

A `Content-Type: image/jpeg` header is never trusted as proof of what the
downloaded bytes actually are. `StoreImportedImageAction::download()` writes
the fetched bytes to a private (`0600`), uniquely-named temp file and wraps
it as an `UploadedFile` — the *same* one every direct upload produces — which
then goes through the *same* `ImageUploadStorer` → `ImageIngestor` pipeline
as a normal upload. A URL ending in `.jpg` whose body is actually HTML fetches
successfully at the network layer and is only rejected once `ImageIngestor`
tries to decode it. The temp file is cleaned up in a `finally` block covering
every outcome — success, a later validation failure, or an `ImageIngestor`
failure — and the remote image download never fetches more than
`ImageIngestPolicy` would accept anyway
(`min(import.max_image_bytes, uploads.images.max_kilobytes * 1024)`), so an
oversized remote image is rejected at the download layer before it ever
reaches `ImageIngestor`.

### Threat model

| Threat | Mitigation |
|---|---|
| SSRF to an internal service | `PublicIpClassifier` + per-hop pinning |
| Cloud metadata SSRF (`169.254.169.254`) | Explicit block, incl. IPv4-mapped IPv6 form |
| DNS rebinding / TOCTOU | Connection pinned to the IP resolved *for that hop*, never re-resolved by the transport |
| Ambient proxy silently defeating the pin | `'proxy' => ''` disables `http_proxy`/`https_proxy`/`all_proxy` environment fallback per request |
| cURL extension unavailable at runtime | Fails closed instead of silently falling back to Guzzle's StreamHandler (which ignores the pin entirely) |
| Redirect-based SSRF | Every hop independently resolved, validated, and pinned |
| Scheme abuse (`file://`, `gopher://`, `data:`, ...) | `allowed_schemes` allowlist |
| Port scanning internal services | `allowed_ports` allowlist |
| Oversized / chunked response | Streaming cap (`on_headers` + `progress` + exact final check) |
| Decompression expansion | Transparent decompression disabled |
| Slow/hanging response | Bounded connect/read timeouts |

### Architecture boundary

`PinnedImportHttpTransport` is the only class in the application allowed to
open an outbound connection for a user-controlled import URL — every other
file under `app/Actions/Import`, `app/Support/Import`, and
`app/Livewire/Import` must go through `SafeImportHttpClient` instead of a raw
`Http::`/`file_get_contents()`/`fopen()`/`curl_*()` call. Enforced by a
grep-based regression test (`ImportArchitectureGuardTest`), not repo-wide —
other parts of the application may use the `Http` facade freely for
integrations that aren't fetching user-controlled URLs.

---

## Fetch Limits

Configured in `config/import.php`:

| Setting | Default |
|---------|---------|
| `timeout_seconds` | 5 |
| `connect_timeout_seconds` | 2 |
| `max_redirects` | 3 |
| `max_html_bytes` | 1 MB |
| `max_image_bytes` | 8 MB (capped further to whatever `ImageIngestPolicy` allows) |
| `allowed_ports` | `[80, 443]` |
| `dns_max_answers` | 16 |

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
| `UrlImportValidator` | Parses/validates a URL, resolves + validates its host, returns a `ResolvedImportTarget` |
| `Net\PublicIpClassifier` | Centralized public/private IPv4+IPv6 classification (binary CIDR, not string-prefix) |
| `Dns\HostResolver` / `Dns\DnsHostResolver` | DNS A/AAAA resolution abstraction (fake-able in tests) |
| `ResolvedImportTarget` | DTO: url/scheme/host/port + the validated, pinned IP for one hop |
| `ImportFetchPolicy` | DTO: byte cap + timeouts handed to the transport |
| `ImportHttpTransport` / `PinnedImportHttpTransport` | Fetches exactly one hop, connection pinned to the resolved IP |
| `ImportTransportResponse` | DTO: one hop's raw status/headers/body |
| `SafeImportResponse` | DTO: the final response `SafeImportHttpClient::get()` returns (status/headers/body/finalUrl) |
| `SafeImportHttpClient` | The one application boundary: orchestrates per-hop resolve→validate→pin→fetch and redirect handling |
| `ImportProviderDetector` | Detect provider from URL |
| `DirectImageImportAdapter` | Handle direct image URLs |
| `OpenGraphParser` | Parse OG/Twitter meta from HTML (RFC 3986 relative URL resolution) |
| `OpenGraphImportAdapter` | Fetch page and build ImportPreview |
| `ImportPreview` | DTO — preview result |
| `ImportFromUrlAction` | Orchestrator action |
| `StoreImportedImageAction` | Download a remote image to a private temp file as an UploadedFile; `cleanup()` removes it |
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
