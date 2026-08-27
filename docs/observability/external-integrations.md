# External Observability Integrations

Phase 54 — Version 1.1

The observability foundation works without any SaaS vendor. Of the vendors
below, **only Sentry is installed**; every other one is **not required**, is not
present, and nothing in the codebase is shaped around it.

---

## Sentry — installed

`sentry/sentry-laravel` is a real dependency and the project's one error and
performance monitoring provider.

Everything operational — the metadata model, the secret model, the deployment
and rollback correlation, the staging setup steps and the verification
procedure — lives in
[`infrastructure/runbooks/sentry-observability.md`](../../infrastructure/runbooks/sentry-observability.md).
That runbook is the source of truth; this section only records how it meets the
foundation described here.

**Configuration:** `config/sentry.php` (published from the package) and
`config/deployment.php` (the canonical release identity). Environment:

```env
APP_DEPLOYMENT_TARGET=staging-main
SENTRY_DSN=<per target, never committed>
SENTRY_ENVIRONMENT=staging
```

`SENTRY_RELEASE` is deliberately unused: the release comes from the deployed
artifact's own `release.json`, so it can never drift from what is running.

**What the Phase 54 foundation contributes to a Sentry event:**

- `request_id`, `user_id`, `app_env`, `locale`, `route_name` and
  `exception_class` — via the `context()` callback in `bootstrap/app.php`,
  which `ExceptionContextBuilder` builds and `SensitiveDataRedactor` redacts,
  and which the SDK attaches as `exception_context`;
- `user_id` again as the Sentry user ID — the maximum identity ever sent, with
  `send_default_pii` off so the SDK collects no email, username or IP itself.

**Local development and CI:** no DSN, so Sentry is inert and the application
behaves identically. Tests never reach the network.

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

The foundation Phase 54 built, and how Sentry now uses it:

- [x] `request_id` on every request (reaches Sentry inside `exception_context`)
- [x] `user_id` in context (also the Sentry user scope ID — and the only identity field sent)
- [x] Exception context via `bootstrap/app.php` `context()` callback
- [x] Structured domain event names for log filtering
- [x] Sensitive data redacted before logging
- [x] `rateguru:observability:health` command to verify config — now also reports
      the deployment target, release, commit and Sentry posture, and never the DSN

## Deliberately not installed

Datadog, Nightwatch, PostHog, Prometheus, Grafana, OpenTelemetry collectors and
Elastic APM are **not** installed, and there is no provider abstraction waiting
for them. There is one monitoring provider — Sentry — used directly through its
official SDK. Adding a second vendor later is a decision to be made then, on the
evidence, not a shape to build for now.
