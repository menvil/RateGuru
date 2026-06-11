# Observability Foundation — Developer Reference

Phase 54 — Version 1.0

---

## Overview

Phase 54 adds a structured observability foundation. Every web request gets a `request id`. Domain actions emit structured logs with consistent event names. Sensitive data is redacted before any log write. Exceptions carry context.

---

## Request ID

Every web request receives an `X-Request-Id` header via `AttachRequestId` middleware.

- If an incoming `X-Request-Id` header is valid (alphanumeric/dash/dot, max 128 chars) it is preserved.
- Otherwise a new UUID is generated.
- The request id is bound in the container as `app('request_id')`.
- The response always includes `X-Request-Id`.

Use this to correlate log lines, exceptions, and metrics for a single request.

---

## Structured Logs

`AttachStructuredLogContext` middleware calls `Log::withContext()` on every request with:

```txt
request_id
app_env
locale
route_name (if available)
user_id (if authenticated)
theme_preference (if set)
```

All subsequent log writes in the same request automatically include this context.

---

## LogContext

`App\Support\Observability\LogContext` provides:

```php
base(): array            // base request context
forPost(Post): array     // adds post_id
forUser(User): array     // adds user_id, username
forImport(?url, ?provider): array  // adds source_host, provider
merge(array ...$contexts): array   // merges multiple contexts
```

---

## DomainLogger

`App\Support\Observability\DomainLogger` is the primary logging entrypoint for domain events.

```php
app(DomainLogger::class)->info('posts.created', ['post_id' => $post->id]);
app(DomainLogger::class)->warning('url_import.preview.failed', [...]);
app(DomainLogger::class)->error('notifications.failed', [...]);
app(DomainLogger::class)->security('security.unsafe_url_blocked', [...]);
```

Each call:
1. Merges `LogContext::base()` into the context.
2. Applies `SensitiveDataRedactor` to the merged context.
3. Calls the appropriate `Log::*` method.

`security()` calls `Log::warning()` and adds `event_type => 'security'`.

---

## SlowActionLogger

`App\Support\Observability\SlowActionLogger` measures callable duration:

```php
$result = app(SlowActionLogger::class)->measure(
    name: 'url_import.fetch',
    callback: fn() => $this->client->fetch($url),
    thresholdMs: 1000,
);
```

If duration >= threshold, logs `{name}.slow` with `duration_ms` and `threshold_ms`.

Callback result is always returned. Exceptions are rethrown after the timing block.

Default threshold: `config('observability.slow_actions.default_threshold_ms')` (500ms).

---

## SensitiveDataRedactor

`App\Support\Observability\SensitiveDataRedactor` recursively replaces configured keys with `[redacted]`.

Keys (case-insensitive): `password`, `password_confirmation`, `token`, `authorization`, `cookie`, `remember_token`, `_token`.

Configure in `config/observability.php` under `redaction.keys`.

---

## Event Naming Convention

Format: `domain.action` or `domain.sub_domain.action`

Rules:
- Lowercase only
- Dot-separated segments
- Letters, numbers, underscores within segments
- No spaces

Examples:
```txt
posts.created
url_import.preview.started
url_import.preview.failed
url_import.unsafe_url_blocked
saved_posts.saved
follows.followed
notifications.followed_author_posted.sent
notifications.followed_author_posted.duplicate_skipped
security.unsafe_url_blocked
security.feature_disabled_action_attempted
profile.updated
profile.avatar.updated
```

---

## What to Log

- State-changing domain actions (save, follow, vote, create post)
- Failures (import failed, notification failed)
- Security denials (unsafe URL, feature disabled)
- Slow external fetches
- Exception context (auto via `bootstrap/app.php`)

## What NOT to Log

- Passwords, tokens, cookies, CSRF tokens (`_token`)
- Raw session data
- Raw uploaded file binary content
- Full URLs with secrets in query string
- Every page render or Livewire hydration
- Successful read queries

---

## Security Events

Security events use `DomainLogger::security()` which:
- Logs at `warning` level
- Adds `event_type => 'security'` to context

Current security events:
- `security.unsafe_url_blocked` — blocked SSRF/private URL attempt
- `security.feature_disabled_action_attempted` — backend action attempted when feature is off

---

## Exception Context

`ExceptionContextBuilder` is registered in `bootstrap/app.php`:

```php
$exceptions->context(function (Throwable $e) {
    return app(ExceptionContextBuilder::class)->build($e);
});
```

This attaches `request_id`, `user_id`, `locale`, `app_env`, and `exception_class` to every reported exception — compatible with Sentry, Datadog, and `Log::critical()`.

---

## Local Troubleshooting

1. Run `php artisan rateguru:observability:health` to check config.
2. Set `LOG_CHANNEL=stderr` locally to see structured context in terminal.
3. Use `X-Request-Id` header in requests to trace a specific request in logs.
4. Check `storage/logs/laravel.log` for domain events.
