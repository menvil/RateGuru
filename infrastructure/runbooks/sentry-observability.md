# Sentry observability and deployment correlation

RateGuru's backend error and performance monitoring. This runbook is the
operational source of truth for what Sentry sees, how a Sentry event is
correlated back to an exact deployment, which secrets live where, and what a
human still has to configure by hand.

It does not redesign anything from Phase 5. Deployment, rollback, backups and
bootstrap are unchanged; Sentry is told about a deployment *after* the existing
health checks have already declared it successful.

---

## The correlation this exists to make possible

From one Sentry issue:

```text
issue
  ↓ environment: staging            (Sentry event field, from SENTRY_ENVIRONMENT)
  ↓ deployment_target: staging-main (Sentry tag, from APP_DEPLOYMENT_TARGET)
  ↓ release: v0.0.0-20260826-120211-ca7d1c7
  ↓ commit:  ca7d1c75...             (Sentry tag, from the release artifact)
  ↓ transaction / queue job
  ↓
matching entry in the target's deployment history
  ↓
matching GitHub Actions deployment run
```

The release string is not a Sentry-specific version. It is the same immutable
RateGuru release ID that:

- the build job produced in `deploy-staging.yml` / `release.yml`;
- `infrastructure/scripts/deploy` created as a release directory;
- `current` points at;
- the deployment history recorded;
- `release.json` inside the deployed artifact carries.

There is exactly one release identity in this system, and no human-maintained
mapping between the two systems.

---

## Metadata model

| Concept | Value | Where it comes from |
|---|---|---|
| `environment` | `staging` or `production` | `SENTRY_ENVIRONMENT` in the target's shared `.env` |
| `deployment_target` | `staging-main`, `tits-guru`, … | `APP_DEPLOYMENT_TARGET` in the target's shared `.env` |
| `release` | `v0.0.0-20260826-120211-ca7d1c7` | `release.json` inside the deployed artifact |
| `commit` | full Git SHA | `release.json` (`source_sha`) inside the deployed artifact |

**The environment is the environment class, never the brand.** Every production
target reports `environment: production` and is distinguished by its
`deployment_target` tag. There is deliberately no `production-tits-guru`
environment: brands are data, and a new target needs a `.env` value, not a code
change or a new Sentry environment.

### Release and commit are read from the artifact, never from Git

`config/deployment.php` reads `release.json` from the application root through
`App\Support\Deployment\DeploymentMetadata`, and `config/sentry.php` takes the
release from the same place.

A deployed release directory contains no `.git`. Running `git rev-parse` at
runtime would either fail or — worse — report whatever repository happened to
be an ancestor of the deploy path. Nothing in the application shells out to Git
for release identity, and a regression test enforces that.

`SENTRY_RELEASE` is deliberately **not** used. It would have to be rewritten in
the shared `.env` on every single deploy, and the first time someone forgot,
Sentry would confidently attribute a new failure to an old release.

### When the metadata is missing or malformed

Observability never takes the application down.

| State | Behaviour |
|---|---|
| `release.json` absent (a working copy, or a pre-Phase-6 release directory) | Application boots normally. `release` and `commit` are `null`; Sentry records no release and the tags are simply absent. |
| `release.json` unreadable, truncated, not JSON, or out of contract | Same as above, and `rateguru:observability:health` prints a warning. |
| Only one of the two values is usable | The usable one is kept, the other is `null`, and the file is reported as `malformed`. |

Nothing is ever fabricated — no `latest`, no `unknown-current`, no timestamp, and
above all no substitution of a bare Git SHA for the canonical release ID.

---

## What Sentry observes

- unhandled backend exceptions from HTTP requests, Artisan commands and queue
  jobs;
- failed queue jobs, with job class, queue, attempt and connection;
- performance transactions and spans: HTTP routes, Livewire components, views,
  SQL queries, cache operations, outbound HTTP client calls, notifications, and
  queue jobs as their own transactions;
- breadcrumbs: Laravel logs, cache, Livewire, SQL query text, queue info,
  command info, HTTP client requests, notifications.

### Scheduler monitoring is deliberately not wired

The SDK offers `sentryMonitor()` check-ins for scheduled commands. Phase 6 does
not use them. RateGuru's schedule is four idempotent retention purges
(`media:purge`, `posts:purge`, `comments:purge-deleted`,
`moderation:purge-content`), each already safe to skip and each already failing
loudly; turning every one into a Sentry monitor would add cron-uptime alerting
nobody asked for and a per-monitor configuration burden in the Sentry UI.

What matters is met without it: an unhandled exception in a scheduled command
is an unhandled Artisan exception, and reaches Sentry through the same single
capture path as everything else. Host-level cron — backups in particular —
stays with the existing infrastructure and belongs to a later operational
dashboard, not here.

## What Sentry does not observe yet

Frontend JavaScript, Session Replay, browser performance, source maps, full log
storage, host CPU/RAM/disk, PostgreSQL infrastructure metrics, backup health,
mail delivery, Nightwatch, Datadog, PostHog. None of these are installed, and
none are stubbed behind an abstraction.

Local logging is unchanged and remains authoritative: `storage/logs`, the
operational logs, the deployment history and the backup logs all stay exactly
where they were. Sentry complements them; it does not replace them.

---

## Privacy posture

`send_default_pii` is `false` on every target. With PII off, the Sentry Laravel
SDK does not even subscribe to Laravel's authentication events, so no email,
username, display name, IP address, cookie or `Authorization` header is
collected.

The one identity field RateGuru adds back, deliberately, is the **internal user
ID** — attached by `App\Providers\ObservabilityServiceProvider` as the Sentry
user ID and nothing else. That is the maximum identity context this application
will ever send.

SQL **query text** is captured, because that is what makes a slow trace or an
error breadcrumb diagnosable. SQL **bindings** are hardcoded off in both
breadcrumbs and spans — not env-driven, so there is no switch an operator could
flip. Bindings routinely carry emails, display names, search text, moderation
text and tokens.

Tags are kept low-cardinality and boring: `deployment_target`, `commit`, `app`.
No URL, no query string, no request body, no exception message, no user
identity ever becomes a tag.

## Exception noise policy

There is no ignore list. Sentry only ever sees exceptions Laravel itself
considers reportable, and Laravel's own `dontReport` list already excludes
404s, validation failures, authentication and authorization failures,
CSRF/session expiry and rate limiting. Adding broad base classes on top of that
could only hide genuine 5xx failures.

Health probes are excluded from **performance monitoring** only:
`ignore_transactions` contains `/up`, the single health route this application
exposes and exactly the path `infrastructure/scripts/health-check` probes. The
SDK applies that list to transaction events, so a real exception raised while
serving `/up` is still reported.

---

## Secret model

| Secret | Lives in | Never lives in |
|---|---|---|
| `SENTRY_DSN` | the target's shared `.env` on the VPS | the repository, the target registry |
| `SENTRY_AUTH_TOKEN` | GitHub **Environment secrets** only | anywhere on the VPS |
| `SENTRY_ORG` / `SENTRY_PROJECT` | GitHub Environment **variables** | — |

**`SENTRY_AUTH_TOKEN` never belongs on the VPS.** The application only ever
needs its DSN in order to send events; the API token exists solely so GitHub
Actions can register a release and a deployment marker. A test enforces that no
infrastructure script, environment template, registry file or `.env.example`
mentions it, and the workflow passes it to the official action through the
environment rather than a command line, so it cannot leak through a process
list.

The target registry (`infrastructure/config/deployment-targets.json`) rejects
secret-like property names outright, including `sentry_dsn` — the DSN belongs
in the shared `.env`, not in committed configuration.

---

## Deployment correlation

### Where the marker is created

```text
build artifact (canonical release ID)
  → deploy: extract, migrate, switch current, reload PHP-FPM
  → HTTP health check                     ← existing, authoritative
  → queue worker transition
  → active release verified against release.json
  → deployment recorded as successful
  → Sentry release + deployment marker    ← Phase 6 adds only this
```

`.github/actions/sentry-release` is the single shared composite action, used by:

| Workflow | Job | Sentry environment |
|---|---|---|
| `deploy-staging.yml` | `observability` (needs `deploy`) | `staging` |
| `release.yml` | `deploy-staging` (after the deploy step) | `staging` |
| `release.yml` | `deploy-production` (after the deploy step) | `production` |
| `rollback-staging.yml` | `rollback` (after the wrapper call) | `staging` |

A failed RateGuru deployment can therefore never produce a Sentry deployment
marker: the marker step is unreachable unless the deployment already succeeded.

### Sentry outages never roll anything back

The Sentry step is `continue-on-error: true`. If the application deployed
successfully and passed its health check but sentry.io is unavailable, the
deployment stays successful and the job summary says so explicitly:

```text
Deployment succeeded, observability registration FAILED.
Sentry does not know about release <id> in <environment>;
the running application is unaffected and must not be rolled back for this.
```

If an environment has no Sentry credentials configured yet, the step is skipped
entirely and the summary records that too. Deployment is never blocked on
observability.

### Rollback correlation

`rollback-staging.yml` reads back the release the target actually landed on —
`basename "$(readlink -f <root>/current)"` — after the server-side wrapper has
completed, which it only does once its own health check passed. That immutable
old release is then recorded as newly deployed to `staging`:

```text
release B deployed
  ↓ rollback
release A serving again, health check passed
  ↓
Sentry deploy marker: release A deployed to staging
```

No synthetic `rollback` release is created, and release A is never mutated: no
commit re-association is performed on a rollback, because release A already
carries its own commits from the deployment that first created it.

A rollback performed manually on the server (`sudo rateguru-rollback` over SSH,
outside GitHub Actions) **cannot** create a Sentry deployment marker, and will
not be given the ability to: that would require a Sentry auth token on the VPS,
which the secret model forbids. After a manual rollback, either re-run the
rollback workflow for the same release, or accept that Sentry's deploy history
lags for that event. This is an accepted, documented limitation.

### Commit association

Every **event** carries the commit as a tag unconditionally, straight from the
artifact's own `release.json`. That correlation depends on nothing outside the
artifact and is what answers "which code was running?".

**Release-level** commit association — Sentry's "suspect commits" UI — is
deliberately **off** (`set_commits: skip`), and this is a load-bearing choice
rather than an oversight.

`getsentry/action-release` performs its steps in a fixed order:

```text
releases new  →  set-commits  →  deploys new  →  finalize
                     ↑                ↑
              fails here …      … and this never runs
```

Associating commits requires the Sentry ↔ GitHub repository integration to be
configured in the Sentry organization; without it sentry-cli cannot resolve the
repository and the call fails. Because a failing `set-commits` aborts the run
before `deploys new`, and because the step is `continue-on-error` so a Sentry
outage can never fail a healthy deployment, attempting it today would produce
green deployments with **no release marker at all** — silently losing the one
thing this integration exists to deliver. `ignore_missing` does not help: it
covers a missing previous-release commit, not an unknown repository.

To turn it on later, once the integration exists:

1. configure the Sentry ↔ GitHub integration and add this repository to it;
2. set `set_commits: manual` in `.github/actions/sentry-release/action.yml`,
   passing `repo`, `commit`, and `previous_commit` for a real range;
3. verify against the real API on staging **before** relying on it — the
   architecture tests can only check the YAML, never the API call itself.

Until then the runbook and the code agree: no release-level association is
attempted.

---

## Post-merge setup

### 1. GitHub Environment configuration (manual, once per environment)

Reuse the **existing** `staging` deployment environment — do not create a
separate environment for Sentry. Future production targets use the existing
`production` environment the same way.

In `Settings → Environments → staging`:

- **Secret** `SENTRY_AUTH_TOKEN` — a Sentry internal integration token with
  `project:releases` (and `org:read`) scope. Nothing wider.
- **Variable** `SENTRY_ORG` — the Sentry organization slug.
- **Variable** `SENTRY_PROJECT` — the Sentry project slug.

Until these exist, deployments succeed and simply record nothing.

### 2. Staging application configuration (manual, once)

Current target: `staging-main`. Application root: `/home/www/rateguru/staging`.
Shared Laravel environment file: **`/home/www/rateguru/staging/shared/.env`** —
the file `infrastructure/scripts/deploy` symlinks into each release as `.env`.

Edit it as root and add:

```env
APP_DEPLOYMENT_TARGET=staging-main

SENTRY_DSN=<the staging project DSN, pasted manually>
SENTRY_ENVIRONMENT=staging
SENTRY_SAMPLE_RATE=1.0
SENTRY_TRACES_SAMPLE_RATE=1.0
SENTRY_PROFILES_SAMPLE_RATE=0
SENTRY_SEND_DEFAULT_PII=false
SENTRY_ENABLE_LOGS=false
SENTRY_ENABLE_METRICS=false
```

These values are also present in
`infrastructure/templates/environment/staging.env.example` (with an empty DSN).
Never put a real DSN in the repository.

**A redeploy is required after changing any of these.** Deployments run
`php artisan config:cache`, and the cached configuration lives inside the
release directory. Editing the shared `.env` alone changes nothing until the
next deployment regenerates that cache. Re-run `Deploy to staging` (with
`run-migrations: false` if there is nothing to migrate).

Production targets use the same block with `SENTRY_ENVIRONMENT=production`,
their own `APP_DEPLOYMENT_TARGET`, and `SENTRY_TRACES_SAMPLE_RATE=0.10` as the
starting point — staging runs at 1.0 only because its traffic is negligible.

### 3. Sentry project configuration (manual, in the Sentry UI)

Code cannot set these.

**Data scrubbing** — in `Settings → Security & Privacy`:

- keep **Data Scrubber** and **Use Default Scrubbers** enabled;
- keep **Scrub IP Addresses** enabled;
- confirm the additional sensitive-fields list covers `password`,
  `password_confirmation`, `current_password`, `remember_token`, `token`,
  `authorization`, `cookie`;
- never add `Authorization`, `Cookie`, `password` or any token field to a
  safe-fields allowlist.

**Alerts** — create a small, useful set and nothing more:

1. a new unhandled issue in `environment:staging`;
2. an issue regression;
3. a high-frequency backend failure (an issue crossing a sensible events-per-
   hour threshold);
4. a failed queue job / fatal backend exception, where identifiable.

Do **not** configure performance alerts yet. Collect a baseline of real staging
traces first; thresholds invented before there is data only generate noise.

---

## Verifying a staging deployment

All read-only. Run as the staging runtime user from the current release.

### A. Confirm what the server thinks it is running

```bash
sudo -u rateguru-staging php /home/www/rateguru/staging/current/artisan about
sudo -u rateguru-staging php /home/www/rateguru/staging/current/artisan rateguru:observability:health
```

`artisan about` includes a Sentry section (Enabled / Environment / Release /
sample rates / Send Default PII) contributed by the SDK, and prints no DSN.

`rateguru:observability:health` prints the deployment identity and the Sentry
posture — target, release, commit, `release.json` state, environment, sample
rates, PII/logs/metrics/SQL-binding switches, and ignored transactions. It
**never prints the DSN**, so its output is safe to paste into a ticket.

Expected output on a correctly configured staging release:

```text
Deployment identity:
  Target: staging-main
  Release: v0.0.0-20260826-120211-ca7d1c7
  Commit: ca7d1c75...
  release.json: present

Sentry:
  Enabled: yes
  Environment: staging
  Error sample rate: 1
  Traces sample rate: 1
  Profiles sample rate: 0
  Send default PII: disabled
  Structured logs: disabled
  Metrics: disabled
  SQL bindings (breadcrumbs): disabled
  SQL bindings (tracing): disabled
  Ignored transactions: /up
```

Cross-check the release against the server's own view:

```bash
basename "$(readlink -f /home/www/rateguru/staging/current)"
sudo -u rateguru-staging jq . /home/www/rateguru/staging/current/release.json
```

All three must agree.

### B. Built-in SDK test event

```bash
sudo -u rateguru-staging php /home/www/rateguru/staging/current/artisan sentry:test
```

In Sentry, confirm the resulting event carries `environment: staging`,
`deployment_target: staging-main`, the correct release and the correct commit.

### C. HTTP exception

There is deliberately no crash route in the application. The automated
regression in `tests/Feature/Observability/SentryExceptionIntegrationTest.php`
covers the HTTP exception path end to end against an in-memory transport. On
staging, either wait for a genuine failure or trigger one through a temporary
Tinker call under the runtime user — never by adding a public route.

### D. Controlled queue failure

Confirm the worker is running, then dispatch one deliberately failing job from
Tinker under the runtime user and let the worker pick it up:

```bash
sudo supervisorctl status rateguru-staging-queue
```

Verify in Sentry:

- exactly one issue, not two;
- job class and queue identifiable;
- release, commit and `deployment_target` correct.

Then confirm the job landed in `failed_jobs` as it always did — observability
changed nothing about queue behaviour. Remove the failed job afterwards with
`artisan queue:forget <id>`. Never leave a deliberately failing job in place.

### E. Performance

Generate a handful of ordinary requests — the feed, a post detail page, a
Livewire interaction, a database-backed page — then check Sentry Performance:

- transactions present for the routes and Livewire components;
- SQL spans present with readable query text;
- **no SQL bindings anywhere**;
- no `/up` transactions at all.

### F. Deployment marker

After the next successful staging deployment, in Sentry `Releases`:

- the release exists under the exact canonical release ID;
- it is marked deployed to `staging`;
- the deploy timestamp is *after* the deployment succeeded, not before;
- the release page lists **no** associated commits — that is expected while
  `set_commits: skip` stands. Open any event from that release instead and
  confirm its `commit` tag matches the artifact's `source_sha`.

Compare against the target's deployment history — the same release ID must
appear in both, with no manual mapping.

---

## Failure semantics

Sentry is fail-open with respect to application availability:

- if an event cannot be sent, the request or job follows its normal failure
  behaviour and the original exception is never replaced by a transport error;
- no Sentry network call is made inside a database transaction;
- the SDK's own timeouts apply; nothing waits indefinitely;
- with no DSN configured the application boots and serves identically — that is
  the normal local and CI state.

## Related

- [`deployment-targets.md`](deployment-targets.md) — what a target is, and the
  release/deploy/rollback lifecycle Sentry is told about.
- [`bootstrap-host.md`](bootstrap-host.md) — clean-host reconstruction. Sentry
  adds no package, binary or service to a host; `sentry-cli` runs only inside
  GitHub Actions.
- `docs/observability/observability-foundation.md` — the request-ID, structured
  context and redaction foundation Sentry builds on.
