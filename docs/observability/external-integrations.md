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

**Status: installed since Phase 6B, and disabled everywhere except staging-main.**

Laravel-native monitoring with request tracing, query timelines, job visibility
and error grouping, installed alongside Sentry as a time-boxed side-by-side
evaluation. Phase 6C decides whether RateGuru keeps Sentry only, Nightwatch
only, or both — this phase deliberately does not.

**Requirements:** a Nightwatch environment token, plus a long-running local
agent process. The agent is Supervisor-managed and runs on `staging-main`
alone.

**Environment:**
```env
NIGHTWATCH_ENABLED=false
NIGHTWATCH_TOKEN=
```

`NIGHTWATCH_ENABLED` defaults to `false` in `config/nightwatch.php` (the vendor
default is `true`), and `phpunit.xml` pins it to `false` on top of that, so
neither a developer checkout nor CI can transmit.

**Not required** for local development: with Nightwatch disabled the package
registers nothing, needs no token, and the application behaves identically.

**See:** `infrastructure/runbooks/nightwatch-evaluation.md` for the account
setup, the privacy posture, the agent installer, the acceptance matrix and the
removal procedure.

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

Datadog, PostHog, Prometheus, Grafana, OpenTelemetry collectors and Elastic APM
are **not** installed, and there is no provider abstraction waiting for them.

There are now two monitoring providers — Sentry and, for the Phase 6B
evaluation, Nightwatch — and each is used directly through its own official
SDK. There is deliberately still no `ObservabilityProviderInterface`, no
`APMManager` and no vendor-agnostic tracer: one of the two is expected to be
removed in Phase 6C, and permanent architecture is not built around a trial.
