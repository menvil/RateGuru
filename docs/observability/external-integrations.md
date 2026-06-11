# External Observability Integrations

Phase 54 — Version 1.0

External monitoring tools are **not required** for Phase 54. The observability foundation works without any SaaS vendor.

---

## Sentry

**When to install:** When you need real-time error alerting and stack trace grouping in production.

**Package:** `composer require sentry/sentry-laravel`

**Environment:**
```env
SENTRY_LARAVEL_DSN=https://xxx@oXXX.ingest.sentry.io/XXX
```

**What context will be attached:**
- `request_id` (from `LogContext`)
- `user_id` (from `LogContext`)
- `app_env`, `locale`, `route_name`
- `exception_class` (from `ExceptionContextBuilder`)

Sentry reads the `context()` callback registered in `bootstrap/app.php` — Phase 54 already sets this up.

**Not required** for local development or Phase 54 completion.

---

## Datadog

**When to install:** When you need APM tracing, metrics, log aggregation, or infrastructure monitoring.

**Requirements:** Datadog agent running on the server/container.

**Environment:**
```env
DD_AGENT_HOST=localhost
DD_TRACE_AGENT_PORT=8126
```

**Log shipping:** Datadog can ingest Laravel logs from the `stack` channel if the agent is configured to tail log files.

**Not required** for Phase 54. No agent is bundled with this project.

---

## Laravel Nightwatch

**When to install:** When you want Laravel-native monitoring with request tracing, slow query detection, and error grouping.

**Requirements:** Nightwatch subscription and token.

**Environment:**
```env
NIGHTWATCH_TOKEN=xxx
```

**What Nightwatch instruments:** Requests, queries, jobs, exceptions — automatically, using the existing Laravel tap points.

**Not required** for Phase 54 completion or local development.

---

## Laravel Pulse / Telescope

**Pulse:** Real-time application health dashboard (requests, exceptions, slow queries, jobs). Local and production use.

**Telescope:** Full request/response/job/query inspector for local development.

Neither is a replacement for Sentry or Datadog in production, but both are useful during development.

**Not required** for Phase 54.

---

## Integration Readiness Checklist (Phase 54)

When you're ready to add an external vendor, Phase 54 already provides:

- [x] `request_id` on every request (can be sent to Sentry/Datadog as tag)
- [x] `user_id` in context (Sentry user scope)
- [x] Exception context via `bootstrap/app.php` `context()` callback
- [x] Structured domain event names for log filtering
- [x] Sensitive data redacted before logging
- [x] `rateguru:observability:health` command to verify config
