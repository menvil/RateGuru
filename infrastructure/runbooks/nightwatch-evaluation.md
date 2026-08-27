# Laravel Nightwatch — Phase 6B staging evaluation

Phase 6A put Sentry into staging and production-shaped operation, and it is
accepted. Phase 6B does not replace it. It installs Laravel Nightwatch
**alongside** an unchanged Sentry, on `staging-main` only, so that Phase 6C can
choose between three options with evidence instead of opinion:

```text
Sentry only        Nightwatch only        Sentry + Nightwatch
```

Nothing on this page decides that. Its job is to get both products running on
the same real traffic, safely, and to say exactly what to look at.

Everything Sentry-related — `config/sentry.php`, `DeploymentMetadata`, the
release identity, the GitHub Sentry release action, sampling, the PII policy,
the SQL policy, the deployment marker — is frozen for the duration. See
[sentry-observability.md](sentry-observability.md).

---

## Architecture

```text
                       RateGuru staging-main
                                │
                ┌───────────────┴───────────────┐
                │                               │
              Sentry                        Nightwatch
         (HTTPS to sentry.io)                    │
                                    127.0.0.1:2407 (loopback only)
                                                 │
                                    rateguru-staging-nightwatch
                                       (Supervisor program,
                                        artisan nightwatch:agent)
                                                 │
                                     HTTPS to nightwatch.laravel.com
```

Nightwatch is a two-part product. The package instruments the application and
writes events to a **local agent** over a loopback socket; the agent batches
them and forwards them to Nightwatch. The application never talks to
Nightwatch's servers directly, and the agent is never reachable from off-box.

| Fact | Value |
| --- | --- |
| Package | `laravel/nightwatch` |
| Supervisor program | `rateguru-staging-nightwatch` |
| Program file (committed) | `infrastructure/config/supervisor/rateguru-staging-nightwatch.conf` |
| Program file (installed) | `/etc/supervisor/conf.d/rateguru-staging-nightwatch.conf`, `root:root 0644` |
| Runtime user | `rateguru-staging` |
| Working directory | `/home/www/rateguru/staging/current` |
| Command | `/usr/bin/php8.5 artisan nightwatch:agent` |
| Agent log | `/home/www/rateguru/staging/shared/storage/logs/nightwatch-agent.log` |
| Ingest endpoint | `127.0.0.1:2407` |
| Installer | `infrastructure/scripts/install-nightwatch-agent` |
| Token location | `/home/www/rateguru/staging/shared/.env`, `NIGHTWATCH_TOKEN` |

### Why a standalone installer

`install-bootstrap-services` (Phase 5.4) describes what a RateGuru host must
have in order to serve traffic. A trial APM agent is not that. Wiring
Nightwatch into that contract would make `--verify` fail on any host that
simply has not opted into the evaluation, and would have to be unwound again if
Phase 6C rejects Nightwatch. So the agent has its own installer, following the
same conventions — root-gated, registry-derived, transactional, `--check` /
`--apply` / `--verify` — plus a `--remove` mode that the other installers have
no need for.

### Which targets may run an agent

A closed allowlist, `target_nightwatch_program()` in
`infrastructure/scripts/common`. `staging-main` is the only entry.

This is what makes "staging only" structural rather than procedural: no
production target can be activated by editing a `.env`, a registry file, or an
argument to any script, because no production target has a program to install.
`tits-guru`, `food-guru` and `animals-guru` are untouched by this phase in
every sense — no token, no environment, no `.env` key, no registry field, no
agent.

### Deployment markers are deliberately not wired

The package ships an `artisan nightwatch:deploy` command that posts deployment
metadata to Nightwatch's API, and Phase 6B does not use it.

Nothing depends on it: every Nightwatch event already carries the canonical
release in its native `deploy` field, plus `release`, `commit` and
`deployment_target` in its context — which is everything the comparison against
Sentry needs. Wiring the command would mean putting a second Nightwatch
credential somewhere CI can reach it, and answering the question of what a
"deployment" is a second time, for a product that may be removed in Phase 6C.

If Nightwatch is retained, the marker is a small, well-defined follow-up: the
command takes the deploy identity as an argument, so it can be handed
`config('nightwatch.deployment')` — the same release.json value everything else
uses — from the same post-deploy step that already creates the Sentry marker,
with no second deployment identity invented.

### One agent, one environment, one port

This is a real constraint to carry into Phase 6C, not a detail:

```text
one Nightwatch agent
  = one Nightwatch environment token
  = one local ingest port
```

An agent authenticates with a single environment token and forwards everything
it receives to that environment. Two targets on one VPS therefore cannot share
an agent: their events would merge into one environment and become
uninterpretable. Each target needs its own agent process **and its own loopback
port**, conceptually:

```text
staging-main → 2407
tits-guru    → 2408
food-guru    → 2409
```

Those ports are deliberately **not** in the target registry yet. Adding a
required registry field for a product that may be removed in Phase 6C would
ripple through every target, every registry fixture in the test suite and the
planned production targets, and then have to be unwound. If Nightwatch is
retained, a per-target ingest port becomes a registry field at that point, with
`targets validate`'s collision checking applied to it — the same treatment
`.php_fpm.socket` already gets.

---

## Privacy posture

The rule for this phase is simple: **Nightwatch must match or exceed the Sentry
baseline that is already accepted.** Sentry runs with `send_default_pii` off,
the internal user ID only, no SQL bindings, no request payloads, no
`Authorization` or `Cookie` header, and no IP address.

Nightwatch's defaults are broader than Sentry's in three specific places, so
those three are closed explicitly rather than assumed. Everything below is
implemented in `App\Support\Observability\NightwatchPrivacy` and
`config/nightwatch.php`, and covered by tests.

| Surface | Nightwatch default | RateGuru | Where |
| --- | --- | --- | --- |
| Authenticated user | `id`, `name` (User::$name), `username` (User::$email) | **`id` only** | `Nightwatch::user()` returns `[]` |
| Request IP | captured | **removed** (`''`) | `redactRequests` |
| Forwarded-IP headers | captured with every other header | **redacted** | `NIGHTWATCH_REDACT_HEADERS` |
| Request URL | full, with query string | scheme/host/path + **parameter names without values** | `redactRequests` |
| Token-bearing paths | concrete path | **route pattern** (`/reset-password/{token}`) | `redactRequests` |
| Request payload | only on a 500, with 3 fields redacted | **disabled entirely** | `NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD=false` |
| SQL | `QueryExecuted::$sql` — parameterized, no bindings | unchanged, **proven by a sentinel test** | see below |
| Cache keys | every key | **allowlist of known-safe shapes**, everything else dropped | `rejectCacheEvents` |
| Outgoing HTTP URLs | full, with query string | **query string stripped** | `redactOutgoingRequests` |
| Artisan command line | full | `--email=` / `--username=` / `--name=` **values redacted** | `redactCommands` |
| Exception messages | full | unique-constraint values **redacted** | `redactExceptions` |
| Mail | captured (recipients, subject) | **off** | `NIGHTWATCH_IGNORE_MAIL=true` |
| Notifications | captured (recipient, channel) | **off** | `NIGHTWATCH_IGNORE_NOTIFICATIONS=true` |
| Laravel logs | opt-in channel | **not added to the log stack** | `config/logging.php` unchanged |

Four of those deserve their reason written down.

**Cache keys are an allowlist, not a blocklist.** Laravel's `RateLimiter` does
not hash the keys it is handed — only the `ThrottleRequests` middleware does,
before calling it — and RateGuru's login throttle key is literally
`<email>|<ip>` (`App\Actions\Auth\AuthenticateUserAction::throttleKey()`). A
blocklist would have to anticipate that; an allowlist cannot miss it. The cost
is that a cache key shape added to RateGuru later stays invisible to Nightwatch
until someone adds it to `NightwatchPrivacy::ALLOWED_CACHE_KEYS` deliberately.
That is the intended trade.

**Query strings keep their parameter names.** RateGuru puts the feed search
term in `?search=`, and that is free text a user typed — frequently another
user's name. The values go. The names stay, because they are RateGuru's own
closed vocabulary and "which filters were in play" is exactly the diagnostic
signal a slow feed request needs. Names that do not match RateGuru's shape are
dropped too: a name is still attacker-controlled text.

**Outgoing URLs lose the whole query string, names included.** The asymmetry is
deliberate. The only outbound requests RateGuru makes are user-pasted import
URLs and the redirect hops they resolve to, so both names and values there are
arbitrary third-party strings — presigned-URL credentials, share tokens,
tracking identifiers.

**Laravel logs are not shipped.** Nightwatch's log channel captures log records
as written, and RateGuru's redaction (`SensitiveDataRedactor`, seven exact
keys) only covers `DomainLogger`. Twenty-six direct `Log::` calls bypass it, two
of them log a raw `$exception->getMessage()`, and `SlowActionLogger` does not
redact at all. Until that is uniform, log ingestion stays off. This is a
deliberate decision, not an oversight, and `NIGHTWATCH_LOG_LEVEL=warning` is set
so that if a target ever does add the channel it starts conservative.

`rateguru:observability:health` reports every one of these, and never prints
the token.

---

## 1. Nightwatch account setup (manual, once)

Do this in the Nightwatch dashboard at <https://nightwatch.laravel.com>.

1. **Organization** — use, or create, the existing RateGuru / `menvil`
   organization. Do not create a second one for staging.
2. **Application** — create one application named exactly:

   ```text
   RateGuru Backend
   ```

   One application for the whole backend. Do **not** create separate
   applications called `RateGuru staging`, `tits.guru` or `food.guru` — brands
   and environments are Nightwatch *environments*, not applications. If the
   product turns out to make that impossible, stop and report the constraint
   rather than silently changing the architecture.
3. **Data region** — **EU**. Choose it at application-creation time; it cannot
   be changed afterwards.
4. **Environment** — create one environment:

   ```text
   Name: staging-main
   Type: Staging
   ```

   The name matches `APP_DEPLOYMENT_TARGET`, so a Nightwatch environment and a
   Sentry `deployment_target` tag always name the same thing.
5. **Token** — the environment page shows an environment token. That is
   `NIGHTWATCH_TOKEN`. It authenticates the agent for **that environment only**.

   If Nightwatch survives Phase 6C, the future mapping is one environment and
   one token per deployment target:

   | Deployment target | Nightwatch environment | Type |
   | --- | --- | --- |
   | `staging-main` | `staging-main` | Staging |
   | `tits-guru` | `tits-guru` | Production |
   | `food-guru` | `food-guru` | Production |
   | `animals-guru` | `animals-guru` | Production |

   Never one token for every target.
6. **Spend protection** — before generating traffic, set the account's
   additional-event / spending cap to a value you are willing to pay, or leave
   the account on the free allowance with overage disabled. Nightwatch bills
   **observed events**, not requests: a single request also produces its
   queries, cache events, outgoing requests, jobs and exceptions. Staging being
   low-traffic is not a reason to skip this.

Unlike the Sentry release integration, Nightwatch needs no repository-level CI
token for this evaluation. Do not add Nightwatch secrets to GitHub Environments.

---

## 2. Staging environment configuration

The token is a runtime secret and belongs in one place only:

```text
/home/www/rateguru/staging/shared/.env
```

Never in the repository, `.env.example`, `deployment-targets.json`, GitHub
Actions logs, a Supervisor config, or a shell command argument.

```bash
sudo -u rateguru-staging vim /home/www/rateguru/staging/shared/.env
```

Set, for the **controlled acceptance window**:

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=<the staging-main environment token>
NIGHTWATCH_INGEST_URI=127.0.0.1:2407

# 1.0 for acceptance only, so every request below is guaranteed to appear.
NIGHTWATCH_REQUEST_SAMPLE_RATE=1.0
NIGHTWATCH_COMMAND_SAMPLE_RATE=1.0
NIGHTWATCH_EXCEPTION_SAMPLE_RATE=1.0

NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD=false
NIGHTWATCH_IGNORE_MAIL=true
NIGHTWATCH_IGNORE_NOTIFICATIONS=true
NIGHTWATCH_LOG_LEVEL=warning
```

`infrastructure/templates/environment/staging.env.example` carries the same key
set with `NIGHTWATCH_ENABLED=false` and an empty token, which is the correct
committed default.

After acceptance is complete, change one line and redeploy:

```env
NIGHTWATCH_REQUEST_SAMPLE_RATE=0.10
```

Everything else stays. Exceptions and commands remain at `1.0`: they are
low-volume and each one matters.

### Why a redeploy is required

RateGuru caches Laravel configuration **inside each immutable release**
(`deploy` runs `artisan config:cache` before switching `current`). Editing
shared `.env` therefore changes nothing for the release that is already
serving: it reads its own baked config cache.

Deploy normally:

```text
merge to develop → the deploy-staging workflow builds an artifact → deploy
```

Do **not** run `artisan config:cache` by hand inside a `current` release. It
mutates an immutable release directory, and nothing in the existing runbooks
supports it.

---

## 3. Install the agent

From a RateGuru checkout on the VPS, as root:

```bash
sudo infrastructure/scripts/install-nightwatch-agent --check  --target staging-main
sudo infrastructure/scripts/install-nightwatch-agent --apply  --target staging-main
sudo infrastructure/scripts/install-nightwatch-agent --verify --target staging-main
```

`--check` is strictly read-only. `--apply` installs the committed program file
transactionally, validates it with `supervisorctl reread`, applies it with
`supervisorctl update`, waits for the program to be stably `RUNNING`, proves the
listener is loopback-only, and finally runs `artisan nightwatch:status` as
`rateguru-staging`. If any of that fails, the previous program file is restored
and re-applied.

`--apply` refuses to run when:

* the target is not `staging-main` (nothing else is on the allowlist);
* `current` does not resolve — deploy first, the agent's working directory
  follows the deployment symlink;
* `NIGHTWATCH_ENABLED` is not `true` in shared `.env`;
* `NIGHTWATCH_TOKEN` is missing or empty (the value is never printed);
* `NIGHTWATCH_INGEST_URI` is not a loopback address;
* the committed program file has drifted from the registry — wrong program
  name, wrong user, wrong working directory, wrong log destination, not the
  official `artisan nightwatch:agent` command, or a `NIGHTWATCH_TOKEN`
  reference anywhere in it.

Confirm the program by name:

```bash
sudo supervisorctl status rateguru-staging-nightwatch:*
```

Expected:

```text
rateguru-staging-nightwatch:rateguru-staging-nightwatch_00   RUNNING   pid 12345, uptime 0:00:12
```

Both Supervisor programs should now be present:

```text
rateguru-staging-queue:rateguru-staging-queue_00             RUNNING
rateguru-staging-nightwatch:rateguru-staging-nightwatch_00   RUNNING
```

### Agent health

The official command, run as the runtime user from the deployed release. There
is deliberately no RateGuru replacement for it:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan nightwatch:status'
echo "exit=$?"
```

Expected: `The Nightwatch agent is running and accepting connections`, exit `0`.
Non-zero means Nightwatch is disabled in configuration, or the application
cannot reach the local agent.

`rateguru:observability:health` is the different question — what this release
*would* send, and where — and never duplicates the connectivity check:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan rateguru:observability:health'
```

### Port verification

```bash
sudo ss -ltnp 'sport = :2407'
```

Expected — a single listener bound to loopback:

```text
LISTEN 0  128  127.0.0.1:2407  0.0.0.0:*  users:(("php8.5",pid=12345,fd=7))
```

**Acceptance fails** if the address is `0.0.0.0:2407`, `*:2407`, `[::]:2407` or
the public IP. `install-nightwatch-agent --verify` enforces exactly this, so
prefer re-running it over reading the output by eye. Do not open a firewall
rule for 2407 under any circumstances.

### After every deployment

`deploy` restarts the agent automatically, right after the queue transition, so
it follows the new `current`:

```text
release extracted → current switched → PHP-FPM reloaded → health check
  → queue transition → nightwatch agent restarted → success recorded
```

That step is deliberately **fail-open**: if the agent cannot be restarted, the
deployment logs a `WARNING` and still succeeds. A healthy RateGuru release is
never rolled back because a monitoring sidecar had a bad minute — the same
policy the Sentry deployment marker already follows. A restart failure is
therefore something to notice in the deploy log and resolve with
`install-nightwatch-agent --verify`, not a deployment failure.

`rollback` does **not** restart the agent — Phase 5's rollback path is
untouched by this evaluation. After a rollback, restart it by hand:

```bash
sudo supervisorctl restart rateguru-staging-nightwatch:*
```

---

## 4. Staging acceptance

Run these in order, with `NIGHTWATCH_REQUEST_SAMPLE_RATE=1.0` in effect, and
record what appears in **both** products. Note the Nightwatch account's event
usage before you start and again at the end.

Every Nightwatch event should carry `deploy` = the canonical RateGuru release
ID, and a `context` block with `app`, `environment`, `deployment_target`,
`release`, `commit` and — for anything originating in a web request —
`request_id`.

### A. What the server thinks it is running

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan rateguru:observability:health'
```

Confirm: `release.json: present`, `Target: staging-main`, Sentry `Enabled: yes`,
Nightwatch `Installed: yes` / `Enabled: yes` / `Token configured: yes`, and
`Ingest URI: 127.0.0.1:2407 (loopback)`. The Sentry release, the Nightwatch
`Deploy` and the `current` symlink must all name the same release.

### B. Requests

Generate traffic server-locally through Nginx, with the correct `Host` header,
so no internet variance is involved:

```bash
for i in $(seq 1 30); do
  curl -s -o /dev/null -w '%{http_code} %{time_total}\n' \
    --resolve rateguru.staging.myprojects.pp.ua:443:127.0.0.1 \
    https://rateguru.staging.myprojects.pp.ua/
done
```

Then a DB-heavy read, including a search term chosen as a sentinel:

```bash
curl -s -o /dev/null \
  --resolve rateguru.staging.myprojects.pp.ua:443:127.0.0.1 \
  'https://rateguru.staging.myprojects.pp.ua/?search=NW_PRIVATE_SENTINEL_123&sort=top'
```

In the Nightwatch **Requests** view verify:

- [ ] the route appears, with status and duration;
- [ ] the execution timeline is populated (bootstrap / middleware / action /
      render / terminating);
- [ ] the deployment context is present (`deploy`, and `release`/`commit`/
      `deployment_target` in the context block);
- [ ] **no precise IP** — the `ip` field is empty, and no `X-Forwarded-For` or
      `X-Real-IP` value is visible in the headers;
- [ ] **no sensitive query string** — the URL shows `?search&sort`, with **no**
      `NW_PRIVATE_SENTINEL_123` anywhere;
- [ ] `Authorization`, `Cookie` and `Referer` show as `[N bytes redacted]`;
- [ ] the user is an ID with no name and no email.

### C. Queries

The same requests produce query events. Verify:

- [ ] queries are visible with duration and connection;
- [ ] the SQL shows `?` placeholders;
- [ ] **no binding values** — search the query text for
      `NW_PRIVATE_SENTINEL_123`; it must not be there.

For a second sentinel that is unambiguously a value, not a route segment:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="\App\Models\User::where(\"email\", \"nightwatch-secret@example.invalid\")->first();"'
```

Neither `nightwatch-secret@example.invalid` nor `NW_PRIVATE_SENTINEL_123` may
appear in any Nightwatch event. If either does, stop and disable query capture
(`NIGHTWATCH_IGNORE_QUERIES=true`) before continuing — useful query monitoring
is not worth leaking values.

### D. Commands

A read-only application command:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan rateguru:observability:health'
```

- [ ] the command appears in Nightwatch with duration and exit code;
- [ ] framework/vendor commands do **not** flood the view (RateGuru does not
      call `captureDefaultVendorCommands()`).

### E. A successful queue job

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="\App\Jobs\RunMediaAuditJob::dispatch();"'
```

- [ ] job class, queue name, duration and outcome are visible;
- [ ] the surrounding timeline (queries, cache) is attached to the job;
- [ ] the deployment context survived the queue boundary.

### F. Cache

Only if the privacy audit left cache capture on, which it did:

- [ ] `sidebar-nav-categories` / `sidebar-nav-top-tags` hits and misses appear;
- [ ] **no cache key containing an email address or an IP** appears — in
      particular, attempt a failed login and confirm nothing resembling
      `someone@example.com|203.0.113.9` reaches Nightwatch.

### G. Outgoing HTTP

Only exercisable if URL import is enabled on staging. Import a URL that carries
a query string and verify:

- [ ] the outgoing request appears with method, host, path, status, duration;
- [ ] **no query string at all** on the recorded URL.

If URL import is not reachable on staging, record this as **not exercised**
rather than inventing traffic.

### H. Mail and notifications

- [ ] neither category produces events. This is intentional, not a gap.

### I. Logs

- [ ] no Laravel log records appear in Nightwatch. Also intentional.

### J. Scheduled tasks

The four daily retention commands (`media:purge`, `posts:purge`,
`comments:purge-deleted`, `moderation:purge-content`) run on the staging
scheduler. Do **not** trigger them by hand to generate telemetry — they are
destructive. Either wait for a natural scheduler window inside the evaluation
period and then verify, or record scheduler validation as **pending**.

### K. A controlled queue failure — in both products

This repeats the Phase 6A scenario against current code. `RunMediaAuditJob`
takes one global lock on the `database` cache store, non-blocking; a second job
dispatched while the lock is held throws `MediaAuditAlreadyRunningException`,
and with `$tries = 1` it goes straight to `failed_jobs`. Verify that is still
true in the deployed release before relying on it:

```bash
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && grep -n "media-audit:full\|tries\|MediaAuditAlreadyRunning" app/Jobs/RunMediaAuditJob.php'
```

Then:

```bash
# 1. Confirm there are no pre-existing failures, so the count is meaningful.
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan queue:failed'

# 2. Take the lock. Keep this shell open — the lock is held by this process's
#    owner token, and 3660s is far longer than the experiment needs.
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="
  \$lock = \Illuminate\Support\Facades\Cache::store(\"database\")->getStore()->lock(\"media-audit:full\", 3660);
  var_dump(\$lock->get());
"'

# 3. Dispatch exactly one job.
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="\App\Jobs\RunMediaAuditJob::dispatch();"'

# 4. Wait for the worker to pick it up and fail it.
sleep 20
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan queue:failed'
```

Expect **exactly one** new failed job, `App\Jobs\RunMediaAuditJob`, with
`A full media audit is already running.`

Then verify, and record for the Phase 6C comparison:

| | Sentry | Nightwatch |
| --- | --- | --- |
| Issue grouping | | |
| Stack trace | | |
| Release / commit / deployment target | | |
| Exception context | | |
| Job attempt and queue name | | |
| Job duration | | |
| Timeline around the failure (queries, cache) | | |
| Number of events for this one failure | | |

One event per provider is correct. Two Nightwatch events for the same exception
is not — RateGuru never calls `Nightwatch::report()`, and both products use
their own native Laravel integration.

Clean up, in this order:

```bash
# 5. Release the lock. forceRelease is correct here: the owner token lives in
#    the tinker process from step 2, which has exited.
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan tinker --execute="
  \Illuminate\Support\Facades\Cache::store(\"database\")->getStore()->lock(\"media-audit:full\")->forceRelease();
"'

# 6. Remove ONLY the job this experiment generated, by its UUID.
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan queue:forget <uuid-from-step-4>'

# 7. Prove nothing is left behind.
sudo -u rateguru-staging bash -c 'cd /home/www/rateguru/staging/current && php8.5 artisan queue:failed'
```

Never `queue:flush` — it would delete unrelated failures.

---

## 5. Event volume

Nightwatch bills observed events. Record, for Phase 6C:

| Measurement | Value |
| --- | --- |
| Account event usage before the run | |
| Account event usage after the run | |
| HTTP requests generated | |
| Nightwatch events produced | |
| Events per representative request | |
| Request sample rate in effect | `1.0` during acceptance |
| Projected daily events at `0.10` | |

The account's usage page is the authoritative number; the per-request ratio is
the one that matters for a production estimate, because production request
volume is the variable and the ratio is roughly constant.

One known asymmetry to record rather than fix here: Sentry drops `/up`
transactions (`ignore_transactions` in `config/sentry.php`), and Nightwatch
does not. RateGuru's only health endpoint is probed by `infrastructure/scripts/
health-check` on each deployment, so the volume is a handful of requests per
deploy and not worth route surgery during an evaluation. If Nightwatch is
retained, the package's own `Sample::never()` route middleware is the way to
close it, and Phase 6C should decide that alongside the sampling rates.

---

## 6. Overhead comparison

A small, reproducible, **non-destructive** measurement. No load-testing
framework, no concurrency, nothing that could hurt staging.

Run server-locally through Nginx with the correct `Host` header, so network
variance does not dominate:

```bash
measure() {
  local url="$1" n="${2:-50}"
  for _ in $(seq 1 "$n"); do
    curl -s -o /dev/null -w '%{time_total}\n' \
      --resolve rateguru.staging.myprojects.pp.ua:443:127.0.0.1 "$url"
  done | sort -n | awk '
    { v[NR] = $1 }
    END {
      printf "n=%d p50=%.4f p95=%.4f max=%.4f\n",
        NR, v[int(NR*0.50)+0], v[int(NR*0.95)+0], v[NR]
    }'
}

measure 'https://rateguru.staging.myprojects.pp.ua/' 50
measure 'https://rateguru.staging.myprojects.pp.ua/?sort=top' 50
```

Take the **Sentry-only baseline before enabling Nightwatch**. If that moment
has passed, use the last pre-Nightwatch release explicitly and say so — do not
present a post-hoc number as a baseline.

Then, with Nightwatch enabled, repeat identically and also record:

```bash
# Agent memory and CPU
ps -o pid,rss,%cpu,etime,cmd -C php8.5 | grep nightwatch:agent

# PHP-FPM pool memory, for a rough before/after
sudo ps -o rss= -C php-fpm8.5 | awk '{s+=$1} END {printf "php-fpm RSS total: %.1f MB\n", s/1024}'
```

| Measurement | Sentry only | Sentry + Nightwatch |
| --- | --- | --- |
| Homepage p50 / p95 / max | | |
| DB-heavy route p50 / p95 / max | | |
| PHP-FPM total RSS | | |
| Nightwatch agent RSS | n/a | |
| Nightwatch agent CPU during the sample | n/a | |

Report **ranges and uncertainty**. Fifty sequential curls on a shared VPS do not
support a claim like "Nightwatch overhead is 2.37%". They comfortably support
"no change we can distinguish from noise", or "p95 moved by roughly X ms", and
that is the honest form the Phase 6C report should take.

---

## 7. After acceptance

1. Set `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.10` in shared `.env`.
2. Deploy `develop` to staging normally so the release picks it up.
3. Confirm with `rateguru:observability:health` that the request sample rate
   now reads `0.1`.
4. Let it collect. **Two to four weeks** is the useful window: long enough to
   include several deployments, a few natural failures, at least one full
   scheduler cycle, and a real weekday/weekend traffic shape. Anything shorter
   compares dashboards rather than products.

---

## 8. Disabling and removal

Three levels, smallest first.

### Stop telemetry

```env
NIGHTWATCH_ENABLED=false
```

in `/home/www/rateguru/staging/shared/.env`, then deploy normally so the
release's config cache picks it up. The application then registers no
Nightwatch hooks and sends nothing. The agent may keep running harmlessly, but
`nightwatch:status` will correctly report Nightwatch as disabled.

### Stop the agent

```bash
sudo supervisorctl stop rateguru-staging-nightwatch:*
```

Or remove it entirely — stop, uninstall the Supervisor program, and confirm the
port is closed:

```bash
sudo infrastructure/scripts/install-nightwatch-agent --remove --target staging-main
```

`--remove` leaves the Composer package, `config/nightwatch.php` and the target's
`.env` untouched. It stops telemetry infrastructure; it does not un-evaluate
Nightwatch.

Do **not** uninstall the Composer package to stop telemetry. With
`NIGHTWATCH_ENABLED=false` the package is inert, and removing a dependency from
a running staging host is a far larger change than the problem requires.

### Full removal, if Phase 6C rejects Nightwatch

Nothing here is done during Phase 6B. This is the list a future PR would act on:

```text
composer remove laravel/nightwatch
  composer.json, composer.lock

delete:
  config/nightwatch.php
  app/Support/Observability/NightwatchPrivacy.php
  infrastructure/config/supervisor/rateguru-staging-nightwatch.conf
  infrastructure/scripts/install-nightwatch-agent
  infrastructure/runbooks/nightwatch-evaluation.md
  tests/Feature/Observability/Nightwatch*Test.php
  tests/Feature/Architecture/NightwatchAgentInstallerTest.php
  tests/Feature/Docs/Phase6BNightwatchRunbookTest.php

revert:
  app/Providers/ObservabilityServiceProvider.php   (configureNightwatch)
  app/Console/Commands/ObservabilityHealthCommand.php (checkNightwatch)
  infrastructure/scripts/common                    (target_nightwatch_program)
  infrastructure/scripts/deploy                    (nightwatch agent transition)
  infrastructure/config/required-clis.txt          (install-nightwatch-agent)
  infrastructure/templates/environment/staging.env.example (NIGHTWATCH_*)
  .env.example                                     (NIGHTWATCH_*)
  phpunit.xml                                      (NIGHTWATCH_ENABLED, if desired)

on the server:
  install-nightwatch-agent --remove --target staging-main
  remove NIGHTWATCH_* from /home/www/rateguru/staging/shared/.env
  delete the Nightwatch application in the dashboard

keep:
  the deployment context in ObservabilityServiceProvider and AttachRequestId —
  Laravel Context is framework machinery, useful to logs on its own, and not
  Nightwatch's.
```

Note what is *not* on that list: nothing Sentry-related. That is the point of
keeping the two integrations at their own vendor boundaries with no shared
abstraction between them.

---

## Failure semantics

| Situation | Effect |
| --- | --- |
| `NIGHTWATCH_ENABLED=false` | The package registers no hooks. Application unchanged. |
| No `NIGHTWATCH_TOKEN` | The application boots and serves normally; the agent refuses to start, and `install-nightwatch-agent` refuses to install without one. |
| Agent not running | The application's writes to the loopback socket fail fast (0.5 s connect timeout) and are dropped. No request fails. |
| Nightwatch SaaS unreachable | The agent buffers and retries. No request fails, no deployment fails. |
| Agent restart fails during a deploy | `WARNING` in the deploy log. The deployment succeeds. |
| Supervisor program misconfigured | `install-nightwatch-agent --apply` / `--verify` fails loudly, and `--apply` restores the previous file. |
| Ingest bound to a non-loopback address | `--apply` / `--verify` fails, and `rateguru:observability:health` warns. |

The distinction that matters: **a third-party observability service being
unavailable is never a RateGuru availability problem**, but **our own
integration being broken is a problem, and it surfaces at install and verify
time — where a human is already looking — not by failing a deployment.**

---

## Related

- [sentry-observability.md](sentry-observability.md) — the Phase 6A integration
  this is being compared against, frozen for the duration.
- [bootstrap-services.md](bootstrap-services.md) — the Phase 5.4 service
  contract that owns the queue worker, PHP-FPM, Nginx and cron. Nightwatch is
  deliberately not part of it.
- [deployment-targets.md](deployment-targets.md) — what a target is, and the
  registry that defines one.
