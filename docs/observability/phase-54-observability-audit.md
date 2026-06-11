# Phase 54 — Observability Audit

Version: 1.0

---

## Current State

### Existing logs

All current logging uses unstructured `Log::error()` calls in failure paths only:

| Location | Event | Level |
|----------|-------|-------|
| `AddCommentAction` | Failed to send post commented notification | error |
| `CreatePostAction` | Failed to dispatch follower notification job | error |
| `ApprovePostAction` | Failed to send post approved notification | error |
| `ApprovePostAction` | Failed to dispatch follower notification job | error |
| `NotifyFollowersAboutNewPostAction` | Failed to send followed author posted notification | error |
| `RecalculateHotScoresCommand` | Failed to recalculate post hot score | error |

No structured context. No request id. No user id. No domain action names.

### Exception handling

Laravel default exception handler via `bootstrap/app.php`. No custom context added. Exceptions reported without request id or user context.

### No request id

No `X-Request-Id` header generated. No correlation id in logs. Impossible to trace a single request across multiple log lines.

### Missing domain action logs

The following state-changing actions produce no logs on success or failure:

- `SavePostAction` / `UnsavePostAction`
- `ToggleFollowAuthorAction`
- `VoteRatingOptionAction`
- `ImportFromUrlAction`
- `CreatePostFromImportedUrlAction`
- `EditProfileForm` (profile update, avatar upload)
- Rate limit hits in `ActionRateLimiter`
- SSRF-blocked URL attempts in `SafeImportHttpClient`

### sensitive data risks

Current `Log::error()` calls pass `post_id`, `moderator_id`, `follower_id` — safe.  
No passwords or tokens are currently logged.  
Risk: future developers adding `Log::info(request()->all())` would leak `_token`, `password`.  
No redaction layer exists.

### Log channels

Default channel: `stack` (env `LOG_CHANNEL`).  
No custom channels for security events or domain events.  
No structured JSON formatter forced in production config.

### External tool readiness

- No Sentry DSN configured.
- No Datadog agent configured.
- No Nightwatch subscription.
- No Pulse/Telescope installed.

---

## Gaps Identified

| Gap | Severity | Fix task |
|-----|----------|----------|
| No request id | High | RG-877 |
| No structured log context middleware | High | RG-879 |
| No LogContext service | High | RG-878 |
| No redaction layer | High | RG-880 |
| No domain action logs (save/follow/vote/import/upload) | High | RG-884–RG-887 |
| No security event logs | High | RG-888 |
| No exception context | High | RG-889 |
| No observability config | Medium | RG-876 |
| No slow action logging | Medium | RG-883 |
| No health command | Medium | RG-890 |
| No sensitive data test coverage | Medium | RG-881 |
| No external integration docs | Low | RG-891 |

---

## Target Architecture After Phase 54

```txt
Every web request:
  AttachRequestId middleware → generates/validates X-Request-Id
  AttachStructuredLogContext middleware → Log::withContext(base context)

Domain actions:
  DomainLogger::info('posts.created', [...])
  DomainLogger::security('security.unsafe_url_blocked', [...])

Slow actions:
  SlowActionLogger::measure('url_import.fetch', fn() => ..., thresholdMs: 1000)

Exceptions:
  ExceptionContextBuilder appends request_id + user_id to reported exceptions

Sensitive data:
  SensitiveDataRedactor::redact($context) before any log write
```

---

## What to NOT log

- passwords
- tokens (`_token`, `remember_token`, `Authorization` header)
- raw session data
- raw cookies
- uploaded file binary content
- private user message bodies
- full URLs with secrets in query string
