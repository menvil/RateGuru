# Bootstrap services (Phase 5 slice 5.4)

`infrastructure/scripts/install-bootstrap-services` reproducibly turns a
prepared host (slice 5.2 runtime + slice 5.3 identities/filesystem) into the
configured RateGuru service host required for deployment. It answers the
Phase 5.4 question: *"can we reproducibly turn that prepared host into the
configured RateGuru service host required for deployment?"*

## Usage

```bash
# Read-only slice 5.4 contract validation plus the intended --apply action
# for every unsatisfied item. Runs the 5.2/5.3 prerequisite verifies,
# validates committed service configuration and the source registry, and
# inspects installed state and external prerequisites (presence only).
# Performs no mutation, no service reload/restart, no downloads.
sudo infrastructure/scripts/install-bootstrap-services --check

# Validates the entire plan before the first mutation, then converges the
# service configuration in dependency order, ending with the full --verify
# report.
sudo infrastructure/scripts/install-bootstrap-services --apply

# Read-only slice 5.4 contract gate: exit 0 only when every installed file
# matches its committed source, services are enabled/running, and every
# child installer contract holds for the current host state.
sudo infrastructure/scripts/install-bootstrap-services --verify
```

All modes require root: the prerequisite gates run other root-only
verifies, and even the read-only inspection must see root-owned state
exactly as the mutating path would.

## Ownership boundaries — never blurred

This installer is a coordinator plus the directly-owned service files that
had committed sources but no dedicated installer. It never absorbs a child
installer's logic:

| Component | Owns |
|---|---|
| `install-bootstrap-services` | Phase 5.4 coordination + the directly-owned service configs below |
| `install-target-operations` | `/home/www/rateguru/config/deployment-targets.json`, `deployment.conf`, the operational bundle under `/home/www/rateguru/bin` |
| `install-target-perimeter` | generic deploy/rollback/cleanup wrappers, sudoers, backup cron, legacy-wrapper absence |
| `install-public-storage-access` | the narrow `user:www-data:--x` POSIX ACL on `shared` and `shared/storage` (active targets only) |
| `install-mail-capture` (+ `verify-mail-capture --read-only`) | Mailpit/Mailtrap Local end to end: users, state, units, pinned binaries, their Nginx vhosts — a shared-host-service, never per-target |

Each child is invoked through its own `--apply` only when its own
authoritative `--verify` does not already pass — that is what makes a
second top-level `--apply` produce zero repeated child mutations. A child
apply failure aborts immediately, before any later component, relying on
the child's own transaction for its resources.

## Directly-owned Phase 5.4 files

For every `lifecycle=active` target (today: `staging-main`), installed
transactionally as `root:root 0644` from the committed sources, with the
authoritative parser validating the complete configuration before any
reload:

| Destination | Source | Validated by |
|---|---|---|
| `/etc/nginx/sites-available/rateguru-staging` (+ `sites-enabled` symlink) | `infrastructure/config/nginx/rateguru-staging` | `nginx -t` |
| `/etc/php/8.5/fpm/pool.d/rateguru-staging.conf` | `infrastructure/config/php-fpm/rateguru-staging.conf` | `php-fpm8.5 -t` |
| `/etc/supervisor/conf.d/rateguru-staging-queue.conf` | `infrastructure/config/supervisor/rateguru-staging-queue.conf` | `supervisorctl reread` (validation only — nothing applied) |
| `/etc/cron.d/rateguru-staging-scheduler` | `infrastructure/config/cron/rateguru-staging-scheduler` | structural cron validation + user/php-binary existence |
| `/etc/ssh/sshd_config.d/70-rateguru-deploy.conf` (host-global) | `infrastructure/config/ssh/70-rateguru-deploy.conf` | `sshd -t` |

Plus one directory: `TARGET_ROOT/shared/storage/logs`
(`runtime_user:runtime_group`, setgid `2770`) — the exact service-support
directory the committed PHP-FPM and Supervisor configs write logs into.
Slice 5.3 deliberately created only `shared` and `shared/storage`; this one
extra descendant exists because committed service configuration references
it. No other Laravel storage descendant (`.env`, `app/public`, `framework`,
uploads) is ever manufactured, and reconciliation touches only the
directory entry itself — existing log files are never recursively re-owned.

If a candidate fails its parser, the previous file/symlink is restored
before any daemon reload could ever see the bad candidate, the restored
configuration is re-validated, and the run fails. SSH is only ever
*reloaded* (never restarted) and only when the installed restriction
actually changed — the active SSH session is never touched.

## Prerequisite gate

Before any mutation, both must pass:

```bash
infrastructure/scripts/install-bootstrap-runtime --verify      # slice 5.2
infrastructure/scripts/install-bootstrap-host-layout --verify  # slice 5.3
```

Deliberately NOT `bootstrap-host-preflight --check`: Phase 5.4 is exactly
the slice that creates much of what the preflight expects, so gating on it
would deadlock a clean host. The preflight asserts the identical 5.4
contract *after* the fact (file modes, services, the public-storage ACL,
the service-support log directory), so the two can never disagree.

## PRE_DEPLOY vs DEPLOYED — the clean-VPS architecture note

A clean host before first deployment legitimately has no `current`
release. **Infrastructure bootstrap readiness and application runtime
readiness are distinct states.** Phase 5.4 configures the host so a first
deployment can occur; it must never fabricate an application release
merely to satisfy runtime checks.

- **PRE_DEPLOY** — `TARGET_ROOT/current` is truly absent. All service
  configuration is installed and validated; every static check runs; the
  application-runtime probes are **DEFERRED** with explicit log/report
  lines (never PASS, never faked): the Supervisor queue program is not
  added to the running supervisor (its `directory=current` does not exist
  yet — force-starting it would crash-loop), HTTP health probes do not
  run, and the public-storage HTTP canary is skipped. The supervisor base
  service is enabled/started *before* the program config is installed, so
  a clean host's supervisord never boots into the autostart crash loop.
  The scheduler cron file is installed (its output is discarded, and
  functional scheduler execution becomes testable only after a release
  exists).
- **DEPLOYED** — `current` resolves to a valid immutable release directly
  under `releases/`. The full runtime verification runs: the queue program
  must be stably RUNNING, health probes execute, and the child installers'
  strong HTTP-level checks apply unchanged.
- **BROKEN** — a dangling `current`, a `current` outside `releases/`, a
  non-symlink `current`, or one resolving to a non-directory is a hard
  failure in every mode. Broken release state is never treated as
  PRE_DEPLOY and never repaired by this installer.

`install-target-operations` and `install-public-storage-access` carry the
same split: on a PRE_DEPLOY host they install/verify everything static
(bundle parity, syntax, lifecycle rejection, cleanup dry-run, the ACL via
real `runuser` access checks) and defer only the application-health
probes; on a DEPLOYED host their existing strong verification is
preserved unchanged.

**First-deploy activation (the 5.5/5.6 integration fix — mechanism closed
in 5.5):** `deploy` now ensures the registry-declared Supervisor queue
program is RUNNING after the atomic `current` switch and HTTP health check
— an already-RUNNING worker is left completely untouched (zero supervisor
churn on normal deployments), while a program the PRE_DEPLOY bootstrap
deferred is activated via `supervisorctl reread`/`update` (plus `start`
when needed), scoped to exactly the target program group. Activation
failure fails the deployment, and recovery stops a worker that this deploy
activated so nothing keeps running against a removed `current` — see
`bootstrap-host.md` for the full PRE_DEPLOY → DEPLOYED transition. A
residual window remains while no release exists: the installed committed
config keeps `autostart=true` (it is never rewritten to a fake shape), so a
supervisord restart or host reboot would read it and fail-loop the program
until a release exists — harmless to the host, noisy in supervisor logs,
and ended by the first *successful* deployment. That window covers two
cases: before any deployment has been attempted, and after a **failed**
first deployment — recovery stops the worker it activated, but does not
unregister the program group, so the config's `autostart=true` still
applies on the next supervisord restart. Recovery deliberately does not
remove the program: it does not own the operator's supervisor
registration. The mechanism is exercised end to end by the 5.6 clean-VPS
acceptance.

## Nginx worker supplementary groups (the runtime half of the code group)

Slice 5.3 makes `www-data` a member of every active target's **code group**
so Nginx can traverse and read the immutable release tree
(`deploy_user:code_group`, `0750`/`0640`). That is an account-database fact.

**Supplementary groups are fixed when a process is created.** Adding the
membership therefore does nothing for Nginx workers that are already
running: a host can have a completely correct `/etc/group` and still answer
every request with HTTP 404, with `stat() ... failed (13: Permission
denied)` in the error log. The Phase 5.6 clean-VPS acceptance hit exactly
this, and it is also what a *partially* bootstrapped host looks like when
5.3 is re-run against long-lived Nginx workers.

This slice therefore inspects the two states separately:

| Question | Answered by | Owner |
|---|---|---|
| Is www-data a member of the code group? | the group database | slice 5.3 (`install-bootstrap-host-layout --verify`) |
| Do the *running* workers carry that GID? | `/proc/<pid>/status` of each www-data worker | this slice |

- **`--check` / `--verify`** report the runtime state read-only. Stale
  workers are `DRIFT` (safely remediable), never `CONFLICT`. **No service is
  reloaded, restarted or otherwise touched in either mode.**
- **`--apply`** reloads Nginx — **never restarts it** — when workers are
  stale, always after `nginx -t` has validated the complete installed
  configuration, then bounded-waits for replacement workers carrying every
  required GID and **fails closed** if they never appear.

Reload happens for exactly two reasons, and at most once: the committed
configuration changed (the existing rule), or the running workers are stale.
When neither holds there is no reload at all, so a second
`install-bootstrap-services --apply` — and a second `bootstrap-host --apply`
— stays mutation-free. The check is per active target code group, so a host
serving several active targets requires www-data to carry every one of them.

## External prerequisites — never generated, copied or read

The committed vhosts being activated reference external secret material.
Before the first mutation, every such file must exist; a missing one fails
closed as:

```text
EXTERNAL PREREQUISITE MISSING: <category> (<path>)
```

naming only the category and path — content is never read, validated or
printed. Categories and current paths:

- `basic-auth` — `/etc/nginx/rateguru-staging.htpasswd`
- `tls-certificate` / `tls-private-key` —
  `/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/{fullchain,privkey}.pem`
  and `/etc/letsencrypt/live/staging-mail-capture/{fullchain,privkey}.pem`
- `nginx-include` / `tls-dhparams` —
  `/etc/letsencrypt/options-ssl-nginx.conf`,
  `/etc/letsencrypt/ssl-dhparams.pem` (absolute `include` directives
  carrying a glob pattern are directory sweeps, not single external files,
  and are never treated as prerequisites)

This installer never creates fake certificates, dummy Basic Auth
credentials or self-signed material, and never calls certbot. Likewise,
`shared/.env`, the deploy `authorized_keys` and `/root/.config/rclone/
rclone.conf` are external secret material: reported by presence only
(WARN when absent — their absence never blocks service configuration on a
pre-deploy host), never created, modified or inspected. The actual
clean-VPS secret/DNS/TLS seeding procedure is exercised in slice 5.6 and
generalized in Phase 8.

## Apply order

1. `install-bootstrap-runtime --verify` (5.2 gate)
2. `install-bootstrap-host-layout --verify` (5.3 gate)
3. source registry validation (standalone `targets` CLI) + target-state
   classification + committed service-source validation (including
   registry↔config consistency: pool name, socket, program, runtime user,
   application root, log paths)
4. external prerequisites (fail closed before any mutation)
5. service-support log directory
6. `install-target-operations` (verify → skip | apply)
7. `install-target-perimeter` (verify → skip | apply; strictly after the
   operations bundle it validates)
8. SSH restriction (install → `sshd -t` → reload only if changed)
9. PHP-FPM pool (install → config test → enable/start → reload only if
   needed → socket verification) — healthy before Nginx runtime
   verification depends on it
10. active-target Nginx (install site + enabled symlink → `nginx -t` →
    reload if running/changed, enable+start on a clean host; unrelated
    sites, including the distro default, are never removed or disabled —
    a real conflict surfaces via `nginx -t` and is reported, not resolved
    by inventing cleanup policy)
11. Supervisor (base service enabled/started *before* the program config —
    see PRE_DEPLOY above; then install + reread validation; update/start
    the program only on a DEPLOYED target)
12. scheduler cron
13. `install-public-storage-access --apply --target <active>` (active
    targets only — never `tits-guru`)
14. mail capture (`verify-mail-capture --read-only` → skip |
    `install-mail-capture --apply`). Both the skip decision and the
    post-apply confirmation use `--read-only`: the verifier's default
    (`--e2e`) sends mail, deletes messages and bounces
    `staging-mailtrap-local.service`, so using it here would make every
    idempotent `--apply` — and every `--check`/`--verify` — mutating. The
    full acceptance run is an explicit operator command, documented in
    [`mail-capture.md`](mail-capture.md)
15. remaining base services: PostgreSQL and Redis get service enablement
    only — never databases, roles, passwords, `pg_hba.conf` or Redis
    auth/network changes
16. the full `--verify` report as the authoritative close

## Transaction and failure safety

Directly-owned files, symlinks, created directories and service
enable/start state changes are recorded per run (backups under
`/var/backups/rateguru-bootstrap-services/<timestamp>-<pid>`). On any
failure before commit, exactly those resources are restored — files and
links byte-identically, freshly-started services stopped again,
freshly-enabled ones disabled — and any service already reloaded with a
new config is best-effort re-validated and reloaded with the restored
one. Child-installer-owned resources are never rolled back here: each
child already performs its own transaction and rollback. There is no fake
"global transaction": packages are never uninstalled, 5.3 identities never
removed, application data never touched.

Application-release safety: `current`, `previous` and `releases/*` are
never modified, no artifact is deployed, no migration/composer/npm/asset
step runs.

## Report vocabulary

`--check` distinguishes:

- **PASS** — installed state byte-identical/compliant;
- **MISSING** — target state absent (`--apply` installs it);
- **DRIFT** — a safe regular installed file differs from its committed
  source (or holds the wrong owner/mode) and `--apply` replaces it
  transactionally;
- **CONFLICT** — wrong type, symlinked/unsafe destination, broken release
  state — operator resolution required, never auto-repaired;
- **DEFERRED** — a runtime application probe that cannot run because the
  target is PRE_DEPLOY (never used to hide a genuine conflict, which keeps
  its own CONFLICT status);
- **WARN** — informational (absent external secret material) — never
  blocks.

The contract is satisfied only with zero MISSING, DRIFT and CONFLICT.

## Idempotency

A second `--apply` on an already-compliant host performs zero meaningful
mutation: no file rewrite, no Nginx/PHP-FPM/SSH reload, no Supervisor
restart, no repeated child-installer mutation, no repeated ACL change, no
mail-capture service restart. The first real staging `--check` should
mostly report PASS — existing correct state is recognized, never
reconstructed gratuitously.

## Real staging acceptance sequence (to be executed, not invented)

```bash
sudo infrastructure/scripts/install-bootstrap-services --check
sudo infrastructure/scripts/install-bootstrap-services --apply
sudo infrastructure/scripts/install-bootstrap-services --verify
sudo infrastructure/scripts/install-bootstrap-services --apply   # second apply: zero mutation
sudo infrastructure/scripts/bootstrap-host-preflight --check
/home/www/rateguru/bin/health-check --target staging-main
```

The slice is only marked completed in `ROADMAP.md` after this sequence
passes on the real staging VPS. The final Phase 5 clean-VPS proof remains
slice 5.6.

## Test overrides

Every probe, tool, child installer and filesystem path can be redirected
through `RATEGURU_BOOTSTRAPSVC_*` environment variables — honored only
alongside `RATEGURU_ALLOW_TEST_OVERRIDES=true`, ignored in production.
`RATEGURU_BOOTSTRAPSVC_FS_ROOT` maps every canonical path onto a fixture
root, which is what lets
`tests/Feature/Architecture/InstallBootstrapServicesTest.php` prove the
clean-host convergence, the DEPLOYED recognition, every broken/drifted
shape, config-test rollback, planned-target protection and idempotency
without the CI runner ever touching a real service or `/etc`.
