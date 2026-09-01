# Bootstrap host

This runbook covers the clean-VPS bootstrap scripts:

- `infrastructure/scripts/bootstrap-host` — Phase 5 slice 5.5, the one
  canonical host-bootstrap entry point (see its own section below);
- `infrastructure/scripts/bootstrap-host-preflight` — Phase 5 slice 5.1,
  the read-only host contract inspection;
- `infrastructure/scripts/install-bootstrap-runtime` — Phase 5 slice 5.2,
  the reproducible base/runtime package installation (see its own section
  below);
- `infrastructure/scripts/install-bootstrap-host-layout` — Phase 5 slice
  5.3, the users/groups/filesystem bootstrap (see its own section below).

## Slice 5.5: bootstrap-host — the canonical entry point

`infrastructure/scripts/bootstrap-host` sequences the authoritative Phase 5
slices in their only valid dependency order:

```text
clean/prepared Ubuntu 22.04 host
  → 5.2 install-bootstrap-runtime      (runtime/packages)
  → 5.3 install-bootstrap-host-layout  (users/groups/filesystem)
  → 5.4 install-bootstrap-services     (services/configuration)
  → bootstrap-host-preflight --check   (final bootstrap readiness)
```

The operator no longer needs to know which individual slice installer to
run next. Each child installer remains authoritative for its own contract —
`bootstrap-host` owns **orchestration only**: ordering, per-slice status,
fail-fast behavior and the final readiness aggregation. There is no
`--force`, no `--skip`, no `--continue-on-error`: if a host is
unsafe/incompatible, the owning child fails and the orchestrator stops.

It is executed **from the bootstrap repository checkout** used to construct
the host (children are resolved as canonical siblings of the script's own
location) — never from `/home/www/rateguru/current` (a clean server has no
deployment) and never from `/home/www/rateguru/bin` (which only exists
after 5.4 installs the operational bundle). It is deliberately **not**
installed into the operational bundle and the deploy user gets no sudo
path to it: only root/operator runs it, from the checkout.

### Real staging acceptance — passed

The staging host was already largely compliant, so this acceptance
exercised the convergent path rather than a build: `bootstrap-host`
detected operational-bundle drift left by deployment changes that had
landed since the bundle was last installed, and `bootstrap-host --apply`
converged only that slice — 5.2 and 5.3 SKIPped on their own passing
`--verify`, 5.4 applied and then verified. The final
`bootstrap-host-preflight` passed, `bootstrap-host --verify` passed, and a
second `bootstrap-host --apply` was fully idempotent: every slice SKIPped,
nothing mutated. Staging stayed healthy throughout.

The clean-host half of the proof is slice 5.6, below.

### Usage

All modes require root.

**Existing host** (e.g. today's compliant staging) — the whole flow:

```bash
sudo infrastructure/scripts/bootstrap-host --check
sudo infrastructure/scripts/bootstrap-host --apply
sudo infrastructure/scripts/bootstrap-host --verify
```

**Clean host** — the same three commands, with one operator step in the
middle: `--check` first tells you how far the host is from the full
pipeline (see the dependency-aware output below), you supply the external
prerequisites the report and [`bootstrap-services.md`](bootstrap-services.md)
name (TLS material, the Basic Auth htpasswd), then `--apply` converges
5.2 → 5.3 → 5.4 and `--verify` gates the result. Nothing about the command
sequence changes between a clean and an existing host — that is the point
of the orchestrator.

- `--check` — strictly read-only and **dependency-aware**: "how far is this
  host from satisfying the full bootstrap pipeline?". Runs each child's own
  `--check` in order and reports `PASS` / `NEEDS_APPLY` / `BLOCKED` per
  slice. On a completely clean host that reads:

  ```text
  PHASE 5.2 runtime/packages
    NEEDS_APPLY — slice contract not satisfied
  PHASE 5.3 users/groups/filesystem
    BLOCKED until Phase 5.2 is satisfied
  PHASE 5.4 services/configuration
    BLOCKED until Phase 5.3 is satisfied
  FINAL PREFLIGHT
    BLOCKED until Phase 5.4 is satisfied

  BOOTSTRAP READY: NO
  ```

  A slice whose prerequisite is unsatisfied is `BLOCKED` rather than being
  misjudged on a host its prerequisite has not prepared. The first
  unsatisfied slice's own concise summary is shown (with a pointer to the
  child's full report) — the failing slice is never hidden.
- `--apply` — **convergent, not one giant transaction**. Per slice: run the
  authoritative child `--verify`; if it already passes, `SKIP` (no child
  apply); otherwise run the child's own `--apply` (its transaction, its
  rollback, its streamed diagnostics) and require its `--verify` to pass
  before the next slice. On today's compliant staging this skips all three
  slices, mutates nothing, and ends with a passing preflight — twice.
- `--verify` — read-only: every child `--verify` must pass, then the final
  preflight must pass.

`--check` and `--verify` are read-only all the way down, not just at this
level. In particular the Phase 5.4 child verifies mail capture through
`verify-mail-capture --read-only`, never its default `--e2e` mode — which
sends real mail, deletes messages and stops/starts
`staging-mailtrap-local.service`. Running the full mail acceptance is an
explicit operator command (see [`mail-capture.md`](mail-capture.md)); no
`bootstrap-host` mode ever triggers it, including `--apply`.

### Safe interruption and re-run

Any child failure stops the run immediately — later slices are never
attempted — and safely converged earlier slices are **never rolled back**
(there is deliberately no destructive global rollback; installed packages
and created identities are persistent host state). Fix the surfaced
problem, then re-run `bootstrap-host --apply`: already-satisfied slices are
skipped and the run resumes at the failing slice. Example: 5.2 and 5.3
converge, then 5.4 stops because a TLS prerequisite is missing
(`EXTERNAL PREREQUISITE MISSING`) — supply the material, re-run, and the
apply skips 5.2/5.3 and completes 5.4.

Ctrl+C terminates the orchestration without continuing downstream slices;
the interrupted child's signal-derived exit status is propagated, never
reinterpreted as a contract failure. Long child verifications announce
themselves first (the 5.4 verification runs several child contract verifies
and takes noticeable time — all of them read-only).

### External material is never generated

`bootstrap-host` never creates `.env`, `authorized_keys`, `rclone.conf`,
TLS private keys/certificates, htpasswd files or database passwords — and
never provisions databases/roles or planned targets (`tits-guru` stays
untouched). When a child requires external material, the child's own
failure is surfaced and the bootstrap is safely re-runnable after the
operator supplies it. The documented supply points are in
[`bootstrap-services.md`](bootstrap-services.md).

### PRE_DEPLOY: host bootstrap completes before the first release

A correctly bootstrapped host that has never received a release is in the
legitimate **PRE_DEPLOY** state (see the shared model in
[`bootstrap-services.md`](bootstrap-services.md)): `bootstrap-host
--verify` and the final preflight pass with the absent `current` and the
deploy-time secret material reported `DEFERRED`, never `MISSING`.
`bootstrap-host` does **not** deploy an application — no artifact, no
release, no `current` switch, no migration. The separate existing `deploy`
operation performs the PRE_DEPLOY → DEPLOYED transition, and since slice 5.5
it also owns the queue transition, immediately after the atomic `current`
switch and the HTTP health check and before the success record. Which action
is correct depends on the worker's state *before* the deployment:

- **The worker was not running** (first deploy after a PRE_DEPLOY bootstrap,
  or one an operator stopped): the registry-declared program is activated via
  `supervisorctl reread`/`update` (plus `start` when needed), scoped to
  exactly that program group, and must reach RUNNING. No `queue:restart`
  follows — the process was just started against the new `current`, so it
  already booted the new release.
- **The worker was already running** (every normal deployment): no
  reread/update/start churn merely because a deployment happened. Instead
  `php artisan queue:restart` is **mandatory** — Laravel workers are
  long-lived and keep the code they booted with, so a worker that never
  receives the signal keeps serving the old release indefinitely. A restart
  signal that cannot be written fails the deployment; it is not a warning,
  and no success history is recorded.

Immediate PID turnover is deliberately **not** a deployment condition:
Laravel's restart is graceful, so a worker finishes its current job before
exiting, and **Supervisor performs the eventual process replacement**. What
is required is that the signal was written and the program is still
operational.

Recovery stays coherent with whichever of those happened. A failed first
deployment stops the worker it activated, so nothing runs against a `current`
recovery removed. A failure after a successful restart signal restores the
old `current` and then re-issues the signal *from the restored release*, so
the workers end up matching the release actually being served. Unrelated
Supervisor programs are never touched. Phase 5.6 exercises all of it, in
sequence, on a real clean VPS.

## Slice 5.1: bootstrap-host-preflight

**This slice cannot bootstrap a server.** It is strictly read-only: it never
installs packages, never adds repositories or GPG keys, never creates users
or directories, never `chown`s/`chmod`s, never writes configuration, never
starts/stops/reloads services, never touches firewall rules, and never calls
any of the existing mutating installers. Its whole job is to define and
validate the host contract that later Phase 5 slices (5.2–5.6, see
`ROADMAP.md`) will satisfy.

## Usage

```bash
# Gate: exit 0 only when the host already satisfies every mandatory
# prerequisite (no MISSING, no CONFLICT items).
infrastructure/scripts/bootstrap-host-preflight --check

# Inventory: the same full host state, plus the intended bootstrap action
# for every unsatisfied item. Exits 0 even on a completely clean host.
infrastructure/scripts/bootstrap-host-preflight --report
```

Both modes print the same grouped report — `HOST`, `TOOLS`, `SERVICES`,
`USERS/GROUPS`, `FILESYSTEM`, `NETWORK`, `SECRETS REQUIRED LATER`,
`SUMMARY` — with each item classified `PASS`, `MISSING`, `WARN`,
`CONFLICT` or `DEFERRED`, ending with:

```text
Bootstrap host preflight:
PASS: 28
MISSING: 14
WARN: 3
CONFLICT: 0
DEFERRED: 5

HOST BOOTSTRAP READY: NO
APPLICATION READY: DEFERRED — no release has been deployed (PRE_DEPLOY)
```

That is the summary shape of a host with no release yet — a clean host has
no `current`, so it is PRE_DEPLOY and the five deferrals are the absent
`current` plus the four deploy-time secrets. A DEPLOYED host prints the
single strict `HOST READY: YES/NO` line instead; see the section below.

Either shape uses the same gate: readiness requires zero `MISSING` and zero
`CONFLICT` items, and `--check` exits 0 exactly when that holds. `WARN` and
`DEFERRED` never block. A missing prerequisite is never individually fatal —
every section always prints, so a clean host produces a complete inventory of
what Phase 5 has to build rather than an early abort.

### PRE_DEPLOY vs DEPLOYED (slice 5.5)

The preflight applies the exact target-state model slice 5.4 established
(see [`bootstrap-services.md`](bootstrap-services.md)) — never a second
slightly-different interpretation. For an active target:

- **PRE_DEPLOY** — `TARGET_ROOT/current` is truly absent: legitimate before
  the first application release. The absent `current` is `DEFERRED`
  ("first immutable deployment has not happened yet"), **not** `MISSING`,
  and the deliberately-external deploy-time material (`shared/.env` with
  its database credentials, the deploy `authorized_keys`, `rclone.conf`)
  is `DEFERRED — REQUIRED BEFORE FIRST DEPLOY`. Host bootstrap readiness
  is judged separately from first-deploy readiness, and the summary reads:

  ```text
  HOST BOOTSTRAP READY: YES
  APPLICATION READY: DEFERRED — no release has been deployed (PRE_DEPLOY)
  ```

  `--check` exits 0 for a correctly bootstrapped PRE_DEPLOY host whose only
  remaining items are legitimate deferrals. External material Phase 5.4
  itself requires to activate committed host configuration — TLS
  certificates and the Basic Auth htpasswd — remains a hard prerequisite:
  `MISSING` when absent, in every state.
- **DEPLOYED** — `current` resolves to a valid immutable release directly
  under `releases/`: the strict `HOST READY: YES/NO` contract applies,
  unchanged from what today's staging host produces (absent secret material
  is `MISSING` again).
- **BROKEN** — a dangling `current`, a non-symlink `current`, one resolving
  outside `releases/` or to a non-directory is `CONFLICT` in both modes —
  `DEFERRED` never hides a broken present `current`, and no fake
  release/current is ever fabricated.

Root is not required, but some root-only paths (`/root/.config/rclone`,
Let's Encrypt keys) cannot be distinguished from absent without it — those
items degrade to `WARN` when not running as root. Run as root on a real host
for the complete picture.

## The two situations it serves

- **Clean host:** almost everything reports `MISSING`; `--report` doubles as
  the work list for slices 5.2–5.4.
- **Current staging host:** the existing installation is recognized as
  present (`PASS`) — running services, occupied ports owned by those
  services, existing users, the installed operations bundle — never
  misreported as conflicts.

## Supported host contract

Only the RateGuru staging VPS baseline, exactly: **Ubuntu 22.04** with
apt/dpkg and systemd. Any other OS family **and any other Ubuntu release**
are both `CONFLICT` — the supported set is never silently expanded. Moving
the baseline is a deliberate edit to `SUPPORTED_OS_VERSION_ID` in the
script (and its test fixtures). This script deliberately does not pretend
to support arbitrary Linux distributions.

The preflight itself has no hard startup dependency beyond bash and the
POSIX base tools: on a clean host without `jq` the report still runs to
completion — `tool:jq` is reported `MISSING`, the target-derived parts of
the contract (per-target users/groups, filesystem paths, PHP-FPM sockets,
secret material) are explicitly reported as not evaluable until `jq` is
installed, and everything that needs no registry parsing still runs. Target
values are never invented.

## What is inspected

- **HOST** — os-release ID/version, architecture, effective UID, systemd,
  apt/dpkg, hostname, kernel, available disk for `/`, `/home`, `/var`
  (where distinct mounts), memory, swap, timezone (UTC expected, never
  changed), IPv4 loopback, DNS resolution.
- **TOOLS** — the canonical CLI inventory derived from the committed
  scripts' actual usage (`REQUIRED_TOOLS` arrays and `*_BIN` defaults in
  deploy, rollback, cleanup, backup, restore-test, the offsite scripts,
  backup-cycle, and every installer). Each is classified required base,
  runtime/service, or optional development/validation — ShellCheck and
  actionlint are never production runtime requirements and only ever `WARN`.
  `tool:rclone` remains required (absence is `MISSING`) but is probed as the
  slice 5.2 **managed external runtime binary** pinned by
  `infrastructure/config/external-runtimes/versions.env` — Ubuntu/dpkg
  package ownership is deliberately never required, and remediation is the
  slice 5.2 installer's verified-download path, never apt.
- **SERVICES** — nginx, the PHP-FPM service named by the committed
  `deployment.conf` template, PostgreSQL, Redis, Supervisor: each reported
  `missing` / `installed-stopped` / `installed-running`, never started or
  reloaded. The staging mail capture (`staging-mailpit`,
  `staging-mailtrap-local`) is detected separately and labeled
  `shared-host-service` — it belongs to the shared staging environment, not
  to a RateGuru target.
- **USERS/GROUPS** — root, www-data, postgres, the per-target runtime and
  deploy accounts from the source registry, the mail-capture accounts, the
  runtime/code groups, and the two repo-required membership relations —
  both readers of immutable release code, which is owned
  `deploy_user:code_group` mode `0750`/`0640` and therefore reachable only
  through that group: the **runtime user** (PHP-FPM and the queue worker
  execute the application) and **www-data** (Nginx workers traverse the
  release tree and stat/read `public/index.php` themselves, before FastCGI
  is involved, because the vhost serves `root <target>/current/public` with
  `try_files`). A host missing the second relation answers every request
  with 404 and logs `stat() ... failed (13: Permission denied)`.
  The managed per-target accounts additionally carry the slice 5.3 identity
  metadata contract, asserted in tested parity with
  `install-bootstrap-host-layout`: the runtime account must hold the
  non-login `/usr/sbin/nologin` shell (historic home not asserted), and the
  deploy account must hold its exact canonical `/home/<deploy_user>` home
  plus `/bin/bash`. Drift is `CONFLICT` (operator review — never mutated by
  preflight). Names and relations are the contract — no accidental numeric
  UID/GID is ever asserted.
- **FILESYSTEM** — the runtime tree (`/home/www/rateguru` and its
  config/bin/backups/run subtrees), the sixteen files
  `install-target-operations` manages, the per-target tree derived from the
  **source registry** (application root, `releases`, `shared`,
  `shared/storage`, the `current` symlink, `locks`, `deployments`, the
  deploy home and its `.ssh`, incoming artifacts — never an assumed
  `/home/www/rateguru/current`), `/var/log/rateguru`, the perimeter
  files (wrappers, sudoers, cron, sshd config), and installed service
  configuration. The per-target owners, groups and modes are asserted
  **authoritatively as the slice 5.3 structural contract** (target root
  `root:root 0755`; `releases`/`locks`/`deployments`
  `deploy_user:code_group 2750`; `shared` and `shared/storage`
  `runtime_user:runtime_group 2770`; incoming `deploy_user:deploy_user
  0750`; deploy home owned by the deploy user with no group/other write;
  `.ssh` `0700`; `/var/log/rateguru` `root:root 0750`), so the preflight
  and `install-bootstrap-host-layout` can never disagree. `current` stays
  deployment-owned and reports as `DEFERRED` (PRE_DEPLOY) until a real
  deployment creates it — a broken present `current` is `CONFLICT`.
  Each item: absent/present, type, owner/group, mode, and
  symlink state where relevant. Large backup/storage trees are never
  recursively scanned — one `stat` per path.
- **NETWORK** — listeners on 80/443, PostgreSQL, Redis, the Mailpit and
  Mailtrap Local SMTP/HTTP ports, the per-target PHP-FPM socket and the
  Supervisor control socket. `free` and `occupied by the expected running
  service` both `PASS`; anything else occupying an expected port is
  `CONFLICT` (`occupied/unknown`). Processes are never killed.
- **SECRETS REQUIRED LATER** — whether later bootstrap will need manual
  secret material: the per-target `shared/.env` (which also carries the DB
  credentials for the registry's application role), the deploy user's
  `authorized_keys`, `rclone.conf`, the Basic Auth htpasswd, and TLS
  certificates. **Presence by `stat` only — content is never read,
  validated, or printed.** The first three are deploy-time material —
  `DEFERRED` on a PRE_DEPLOY host, `MISSING` on a DEPLOYED one — while the
  TLS/Basic Auth material Phase 5.4 hard-requires stays `MISSING` when
  absent in every state (see the PRE_DEPLOY section above).

## Source vs runtime registry

The repository's `infrastructure/config/deployment-targets.json` is the
bootstrap **source of truth**, validated through the standalone `targets`
CLI (which needs no installed `deployment.conf` — the preflight never
sources `common` for the same reason: `common` aborts on a host where
nothing is installed yet). If the runtime registry
(`/home/www/rateguru/config/deployment-targets.json`) exists it is compared
byte-for-byte: parity is `PASS`, drift is `WARN`, and the runtime file is
never modified. The committed `deployment.conf.example` template gets the
identical treatment against the installed `deployment.conf`.

## Test overrides

Every host probe (os-release, meminfo, passwd/group, timezone, the systemd
runtime directory, the tool lookup PATH, and the systemctl/ss/df/ip/getent/
stat binaries) can be redirected through `RATEGURU_PREFLIGHT_*` environment
variables — but only alongside `RATEGURU_ALLOW_TEST_OVERRIDES=true`, the
same gate `common` and every operational script already enforce. Production
execution ignores all of them. This is what lets
`tests/Feature/Architecture/BootstrapHostPreflightTest.php` prove clean-host,
compliant-host, wrong-OS, port-conflict and drift behavior without the CI
host running nginx, PostgreSQL or even systemd.

## Slice 5.2: install-bootstrap-runtime

`infrastructure/scripts/install-bootstrap-runtime` reproducibly installs the
base/runtime package layer on the exact supported baseline. Its scope is
repositories and packages only — nothing else.

### Usage

```bash
# Read-only contract validation plus the intended --apply action for every
# unsatisfied item. Never runs apt-get. Exit 0 only when already satisfied.
infrastructure/scripts/install-bootstrap-runtime --check

# Root only. Idempotently configures the two RateGuru-owned repositories,
# installs missing required packages, ends with the full --verify report.
infrastructure/scripts/install-bootstrap-runtime --apply

# Read-only contract gate: exit 0 only when every repository, package,
# binary, PHP module and client-version requirement holds.
infrastructure/scripts/install-bootstrap-runtime --verify
```

### The proven staging baseline

The contract reproduces what the real staging VPS runs, inspected directly:

- **Ubuntu 22.04 (jammy), x86_64** — the exact OS pin is shared with the
  preflight (architecture tests keep the two scripts in parity); any other
  family, release or architecture fails closed before any mutation.
- **PHP 8.5 (CLI + FPM)** from the Ondřej Surý PPA
  (`ppa.launchpadcontent.net/ondrej/php/ubuntu jammy`) — Ubuntu 22.04's
  base repository has no PHP 8.5.
- **PostgreSQL 18** from PGDG (`apt.postgresql.org jammy-pgdg`) — the
  staging packages identify as `18.x-1.pgdg22.04+1`.
- **Nginx, Redis, Supervisor** and every base utility (including `unzip`,
  which extracts the pinned rclone release archive, and `procps`, whose
  `pgrep` slice 5.4 uses to find the running Nginx workers whose
  supplementary groups it verifies) from the Ubuntu 22.04 distribution
  repository.
- **rclone as a managed external runtime binary** — not an Ubuntu package;
  see the dedicated section below.

Exact patch versions (PHP 8.5.8, PostgreSQL 18.4, nginx 1.18.0, redis
6.0.16, supervisor 4.2.1 as observed) deliberately **float with security
updates**: the contract is PHP series 8.5 and PostgreSQL major 18, and the
Ubuntu jammy package family for everything else. No external repository is
ever introduced to chase an incidental patch version.

**Node.js, npm and Composer are intentionally absent.** GitHub Actions
builds the immutable application artifact (`composer install --no-dev`,
`npm ci`, `npm run build`; `vendor/` and `public/build` ship inside the
artifact), so the application host never builds anything and the bootstrap
contract excludes those toolchains on purpose.

### Repository ownership and key handling

The installer manages exactly two apt sources, written as deb822
`.sources` files with dedicated keyrings:

- `/etc/apt/sources.list.d/rateguru-php.sources` +
  `/etc/apt/keyrings/rateguru-php.gpg`
- `/etc/apt/sources.list.d/rateguru-pgdg.sources` +
  `/etc/apt/keyrings/rateguru-pgdg.gpg`

Keys are fetched over HTTPS only and installed only after the downloaded
material contains exactly one primary key whose fingerprint matches the pin
embedded in the script; keyring and sources files are staged and renamed
into place atomically, and a failed repository aborts the run before any
package installation. `apt-key` is never used.

Because a genuinely minimal Ubuntu 22.04 host has neither curl nor gnupg,
`--apply` first installs the bootstrap repository tooling
(`ca-certificates`, `curl`, `gnupg`) from the host's **existing Ubuntu
sources** — strictly before any external repository work — and only then
fetches and validates the PHP/PGDG keys.

`--check` and `--verify` validate installer-owned repository files
authoritatively and read-only: the `.sources` file must be byte-exact
against the expected deb822 content, and the installed keyring must be a
valid OpenPGP keyring holding exactly one primary key with the pinned
fingerprint (the same local gpg validation `--apply` applies to freshly
fetched material — never a network call). Any drift, garbage or
wrong-fingerprint keyring is `CONFLICT` and fails `--verify`.

A repository already provided by a pre-existing apt source — however the
operator configured it, like the classic `add-apt-repository` `.list` on
the current staging host — is recognized and left untouched. Unrelated
host-wide repositories (NodeSource, ClickHouse, Datadog/Vector, anything
else) are never inspected, managed, removed or required absent: `--check`
and `--verify` on the current staging host pass with them present.

### Managed external runtime: rclone (Phase 5.2.1 corrective fix)

Acceptance on the real staging VPS falsified the original slice 5.2
assumption that rclone is an Ubuntu apt package: the host runs a standalone
`/usr/bin/rclone` (v1.74.4 at inspection time) that **no dpkg package
owns**, and the Ubuntu 22.04 candidate package is the far older
1.53.3-4ubuntu1.22.04.x. The corrected contract:

- **Ubuntu packages are OS/runtime dependencies. rclone is a verified,
  pinned external runtime binary** — never an apt package requirement, and
  no third-party apt repository is added for it. dpkg ownership of the
  binary is deliberately not required, so the current standalone staging
  binary is a fully legitimate installation shape.
- The canonical contract lives in the committed
  `infrastructure/config/external-runtimes/versions.env`: the exact pinned
  release version, `linux-amd64` platform, `/usr/bin/rclone`, `root:root`,
  mode `0755`, and the official rclone release-signing key fingerprint
  (`FBF737ECE9F8AB18604BD2AC93935E02FF3B54FA`). The matching public key is
  committed next to it. Exact external versions are intentionally pinned
  and updated explicitly — never `latest`, never a dynamic lookup: an
  upgrade is its own reviewed change to that contract file.
- `--check`/`--verify` report rclone in their own `EXTERNAL RUNTIME`
  section, separate from `PACKAGES`. A compliant binary is
  `PASS rclone — v<pin>, /usr/bin/rclone, root:root 0755`; an absent binary
  is `MISSING` with the action `install verified rclone v<pin>`; version
  drift (like the current staging v1.74.4), a wrong owner/group or mode, an
  unexpected path, or a binary that cannot report a version are `CONFLICT`
  with the action `replace with verified rclone v<pin>`. An apt action for
  rclone is never proposed.
- `--apply` converges drift through verification only: download the exact
  versioned release archive and its `SHA256SUMS` from the official
  `downloads.rclone.org` origin (HTTPS only), verify the clearsigned
  `SHA256SUMS` with the committed release-signing key — which is itself
  accepted only after it dearmors to exactly one primary key matching the
  pinned fingerprint (a key is never trusted merely because of where it was
  downloaded from) — extract the expected digest for the exact artifact,
  verify the archive checksum, extract with `unzip`, confirm the extracted
  binary reports exactly the pinned version, then stage the candidate with
  final ownership and mode in the destination directory and rename it over
  `/usr/bin/rclone` atomically. Any failure before that rename leaves the
  currently working binary untouched; temporary material is cleaned via
  trap. `rclone selfupdate` and the upstream pipe-to-shell installer are
  never used.
- When the exact pinned rclone already exists with the correct path, owner,
  group and mode, `--apply` performs **no network request, no download and
  no file replacement** for rclone.
- The operator's `/root/.config/rclone/rclone.conf` (Backblaze B2
  credentials) is never read, changed, moved, chmodded or recreated, and
  `rclone config` is never run.

**Existing staging migration:** after this fix deploys, `--check` on the
current staging host reports exactly one unsatisfied item — the rclone
version drift (installed v1.74.4, required pinned version) — and proposes
the verified replacement, never `apt-get install rclone`. `--apply` then
performs the signature- and checksum-verified atomic upgrade.

### apt policy and idempotency

`--check`/`--verify` never run apt-get at all (package state is read via
`dpkg-query`). `--apply` runs `apt-get update` only when needed: once
before installing missing bootstrap repository tooling, and once after
repository configuration changed (or when runtime packages must be
installed and the indexes were not just refreshed) — so a first clean-host
apply performs at most two updates, and package installation is one
deterministic `DEBIAN_FRONTEND=noninteractive apt-get install -y
--no-install-recommends` of the missing set per phase. `apt-get upgrade`
in any form is never run, and packages are never removed. A second
`--apply` on a satisfied host performs no apt call, fetches no key and
rewrites no file (its only gpg activity is the local read-only keyring
validation).

### Package post-install side effects

Installing the packages lets their Ubuntu maintainer scripts do what they
always do: `postgresql-18` creates the default `main` cluster (via
`postgresql-common`) and starts `postgresql.service`; `nginx` starts with
the distribution default site; `redis-server` starts bound to localhost;
`php8.5-fpm` starts with the distribution `www` pool; `supervisor` starts
with an empty `conf.d`. None of that is RateGuru configuration — vhosts,
pools, workers, databases, roles, `pg_hba.conf`, users, directories and TLS
all belong to slices 5.3/5.4. Certbot is installed as base tooling only:
no certificate is requested, ACME is never contacted, and no renewal hook
is configured (TLS issuance is slice 5.4/5.6).

Deliberate module/package decisions: `php8.5-igbinary` arrives as a
dependency of `php8.5-redis` and is not an independent requirement;
`php8.5-readline` is an interactive convenience, not contract; `exif` and
`pcntl` ship inside `php8.5-common`/`php8.5-cli` and are verified as loaded
modules (composer.json requires `ext-exif`; queue workers use pcntl);
SQLite is not installed (tests support it, the runtime contract is
PostgreSQL); `openssh-server` is required because the deploy pipeline
delivers artifacts over SSH; shellcheck/actionlint/wget are never runtime
requirements.

### Verification depth

`--verify` (and the closing report of every `--apply`) checks more than
dpkg state: `php8.5 -v` and `php-fpm8.5 -v` must report series 8.5,
`php8.5 -m` must list bcmath, curl, exif, gd, intl, mbstring, pcntl,
pdo_pgsql, pgsql, redis, xml and zip, and `psql`/`pg_dump`/`pg_restore`
must report major 18 (`createdb`/`dropdb` present). Slice 5.2 verification
deliberately does **not** require `bootstrap-host-preflight --check` to
pass: a clean host naturally lacks the slice 5.3/5.4 resources the
preflight also inspects.

### Known staging drift (5.3 remediation, not 5.2)

The real staging preflight reported one conflict:
`/home/www/rateguru/staging` is owned
`deploy-rateguru-staging:rateguru-staging-code` while the Phase 5 contract
expects the top-level application root to be root-owned. Slice 5.2 never
`chown`s anything — the remediation belongs to
`install-bootstrap-host-layout` (slice 5.3, below), which converges exactly
that directory entry and nothing beneath it.

### Test overrides

Like the preflight, every probe and mutation path can be redirected through
`RATEGURU_BOOTSTRAP_*` environment variables — honored only alongside
`RATEGURU_ALLOW_TEST_OVERRIDES=true`. This is what lets
`tests/Feature/Architecture/InstallBootstrapRuntimeTest.php` prove clean-host
bootstrap, staging compatibility, repository failure safety, idempotency and
the full check/apply/verify matrix without the CI runner ever running apt.

## Slice 5.3: install-bootstrap-host-layout

`infrastructure/scripts/install-bootstrap-host-layout` provisions the
identity and filesystem layer required before service/configuration
installation. Its scope is users, groups, memberships and the structural
directory tree only — nothing else.

### Usage

All modes require root.

```bash
# Read-only contract validation plus the intended --apply action for every
# unsatisfied item. Exit 0 only when already satisfied.
infrastructure/scripts/install-bootstrap-host-layout --check

# Validates the entire plan before the first mutation, then idempotently
# converges the slice 5.3 identities and directories, ending with the full
# --verify report.
infrastructure/scripts/install-bootstrap-host-layout --apply

# Read-only contract gate: exit 0 only when every identity, membership and
# structural directory ownership/mode requirement holds exactly.
infrastructure/scripts/install-bootstrap-host-layout --verify
```

The slice gate is `--verify` — deliberately **not**
`bootstrap-host-preflight --check`, because the Phase 5.4 resources
(runtime registry, installed CLIs, service configuration, secrets) are
legitimately still missing at this point and the preflight rightly reports
them.

### Source of truth and target selection

The repository's `infrastructure/config/deployment-targets.json` is the
bootstrap source of truth, validated through the standalone `targets` CLI —
never re-implemented. The runtime registry
(`/home/www/rateguru/config/deployment-targets.json`) does not exist on a
clean host (`install-target-operations` installs it in slice 5.4) and is
never read. Only `lifecycle=active` targets are provisioned: `tits-guru`
is `lifecycle=planned` and causes **zero** user, group or filesystem
mutation — no production runtime/deploy users, groups, application root or
incoming directory are ever created for it.

### The identity model

- **deploy user** (`deploy-rateguru-staging`) — owns the incoming artifact
  directory and creates immutable releases. Its passwd metadata is a hard
  contract, for existing accounts too: primary group is its own private
  group, home is exactly the canonical `/home/deploy-rateguru-staging`
  (the same directory this installer manages, with its `.ssh` and
  `incoming`), and the shell is `/bin/bash` — the GitHub Actions SSH
  deployment flow must be able to log in, so `/usr/sbin/nologin` or
  `/bin/false` is a CONFLICT, and so is a home pointing anywhere else
  (that would silently break `authorized_keys` lookup and every ownership
  expectation). An incompatible existing account is never automatically
  `usermod`ed — it fails `--apply` closed before any mutation and requires
  operator review. The home carries a structural `.ssh` (`0700`); no
  `authorized_keys` is ever created, read or modified — the GitHub deploy
  public key is external secret material provisioned in Phase 5.4. No sudo
  is granted here (restricted sudoers stays with
  `install-target-perimeter`).
- **code group** (`rateguru-staging-code`) — the read-only immutable-code
  group. Releases are owned `deploy_user:code_group` with files normalized
  `0750`/`0640`, so nothing outside that group can read them, and it has
  exactly **two required members**, both appended (never replacing
  unrelated memberships):
  1. the **runtime user** — PHP-FPM and the queue worker execute Laravel as
     it;
  2. **www-data** — Nginx workers serve `root <target>/current/public` and
     evaluate `try_files $uri $uri/ /index.php?$query_string`, so they must
     traverse the release tree and stat/read `public/index.php` themselves,
     before FastCGI is involved. Without this the host answers every
     request with 404 and logs `stat() ... failed (13: Permission denied)`.

  This is the whole of www-data's target-group access: immutable code,
  read-only. It never joins a runtime group, so shared mutable state stays
  out of reach, and public storage traversal remains the separate narrow
  ACL. Note that adding the membership does not affect Nginx workers that
  are already running — see the reload contract in
  [`bootstrap-services.md`](bootstrap-services.md).
- **runtime user** (`rateguru-staging`) — runs Laravel/PHP-FPM/queue
  processes and owns the shared mutable application state. Created as a
  system account with no password login. Its primary group and the
  non-login `/usr/sbin/nologin` shell are hard contract (an interactive
  shell on the runtime service account is a CONFLICT, never auto-fixed);
  its historic home is deliberately **not** contract — the runtime home is
  not operationally significant to PHP-FPM/queue execution the way the
  deploy home is critical to SSH, so incidental home metadata never fails
  a healthy host. A compliant existing account is left untouched.
- **www-data** — joins each active target's **code group** and nothing
  else. That is read-only access to immutable release code, which Nginx
  workers genuinely need: they serve `root <target>/current/public` and
  evaluate `try_files $uri $uri/ /index.php?$query_string` themselves, so
  they must traverse and stat the 0750/0640 release tree before FastCGI is
  ever reached. It is **not** added to any runtime group, and no runtime
  account joins www-data — shared mutable state stays out of reach. Public
  mutable storage traversal remains a separate, independent boundary: the
  narrow POSIX ACL (`user:www-data:--x`)
  `install-public-storage-access` grants in slice 5.4 on `shared` and
  `shared/storage`. The two boundaries are never conflated, and neither is
  ever widened to solve the other.

  Because supplementary groups are fixed when a process is created, adding
  this membership does **not** affect Nginx workers that are already
  running. Slice 5.4 therefore verifies the live workers separately and
  reloads Nginx (never restarts it) when they are stale — see
  [`bootstrap-services.md`](bootstrap-services.md).
- **root** — owns the target namespace root and the host operational and
  configuration roots.

No numeric UID/GID is ever part of the contract — names and relationships
are. Accounts are never deleted, renumbered or recreated; an existing
account with an incompatible primary group is a CONFLICT that stops the
run before any filesystem mutation.

### The filesystem contract

Host roots: `/home/www/rateguru`, `config`, `bin` (`root:root 0755` —
contents belong to slice 5.4), `backups`, `run` (`root:root 0700`),
`/var/log/rateguru` (`root:root 0750`). Per active target:

| Path | Owner | Mode |
|---|---|---|
| `<root>` (e.g. `/home/www/rateguru/staging`) | `root:root` | `0755` |
| `<root>/releases` | `deploy_user:code_group` | `2750` |
| `<root>/shared` | `runtime_user:runtime_group` | `2770` |
| `<root>/shared/storage` | `runtime_user:runtime_group` | `2770` |
| `<root>/locks` | `deploy_user:code_group` | `2750` |
| `<root>/deployments` | `deploy_user:code_group` | `2750` |
| deploy home | `deploy_user:deploy_user` | `0750` on create; any non-group/other-writable mode accepted |
| deploy home `/.ssh` | `deploy_user:deploy_user` | `0700` |
| deploy home `/incoming` | `deploy_user:deploy_user` | `0750` |

**Why the target root is `root:root 0755` while `releases` belongs to the
deploy user and `shared` to the runtime user:** the boundary prevents the
deploy identity from controlling the entire target namespace (it could
otherwise replace `shared`, `locks` or the parent of the `current`
symlink) while still letting the deployment pipeline create immutable
releases inside `releases/` — the setgid `2750` keeps new releases in the
code group — and letting the runtime user own only the mutable state it
actually writes.

**Exact directory modes (GNU chmod semantics).** On the supported GNU
coreutils baseline, `chmod` on a **directory** preserves existing
setuid/setgid bits when given a plain numeric mode — `chmod 0755` on a
`2750` directory yields `2755`, not `0755` (clearing directory special
bits requires explicit replacement semantics). The installer therefore
converges every exact-mode directory entry with the GNU operator numeric
form (`chmod =0755`, `chmod =2750`, …), which replaces the complete mode
in both directions — clearing an unwanted setgid and setting an
intentional one — and pins the same explicit `=MODE` after creating a
directory, so a child born under a setgid parent never inherits special
bits the contract does not require. The deploy-home `nw` remediation uses
`=0750` for the same reason, without changing what `nw` accepts. This is
the real staging acceptance regression: the pre-apply target root was
`deploy:code 2750`, the original plain `chmod 0755` left it `2755`, and
the closing `--verify` correctly failed — the fix is in the installer,
never a server-side workaround.

The setgid bits are intentional: new releases created by the deploy
account inherit the code group; new shared/storage content inherits the
runtime group. `shared/storage` is created as a structural root only — the
Laravel-specific descendants (framework caches, logs, `app/public`) remain
the deploy pipeline's responsibility, and no `.env` is ever created, read
or changed. `current`/`previous` are deployment-owned: never fabricated on
a clean host, never rewritten, never followed for any operation, and
reported by the preflight as absent until a real deployment creates them.

### Existing-data and symlink safety

Reconciliation touches only the exact directory entry whose owner/group/
mode is part of the contract. There is **no recursive chown/chmod
anywhere**, no `rm -rf`, and existing nested data (immutable releases,
shared storage uploads, `.env`, `authorized_keys`, backup history,
deployment history) is never re-owned, re-moded or rewritten through a
parent remediation. A managed path that exists as anything but a real
directory — regular file, symlink, socket — is a CONFLICT: the plan
validation collects every such conflict and fails the apply closed before
any mutation, and conflicting paths are never deleted, replaced or
followed. A registry whose application root escapes `/home/www/rateguru`,
or whose incoming directory is inconsistent with the deploy user's home,
fails closed the same way.

### The known real staging drift

The real staging host reported one conflict: `/home/www/rateguru/staging`
owned `deploy-rateguru-staging:rateguru-staging-code` instead of
`root:root 0755`. This is the one existing-host remediation this slice
deliberately carries. The expected real-host sequence:

```bash
install-bootstrap-host-layout --check    # shows the target-root ownership drift
install-bootstrap-host-layout --apply    # chowns ONLY /home/www/rateguru/staging itself
install-bootstrap-host-layout --verify   # slice 5.3 satisfied
```

No application outage is required: `current`, `releases`, `shared`,
storage and deployment history remain operational and untouched beneath
the reconciled parent.

### Idempotency

A second `--apply` on a compliant host performs no meaningful mutation:
no `groupadd`/`useradd`/`usermod`, no `install -d`, no `chown`, no
`chmod`. Convergence is fail-safe and re-runnable; identity creation is
persistent host state and is never rolled back destructively.

### Test overrides

Like the other bootstrap scripts, every probe and mutation path can be
redirected through `RATEGURU_HOSTLAYOUT_*` environment variables — honored
only alongside `RATEGURU_ALLOW_TEST_OVERRIDES=true`. This is what lets
`tests/Feature/Architecture/InstallBootstrapHostLayoutTest.php` prove
clean-host bootstrap, the staging drift remediation, existing-data safety,
planned-target protection, fail-closed conflicts and idempotency without
the CI runner ever creating a real account or root-owned directory.

## Slice 5.4: install-bootstrap-services

Services and committed host configuration — the slice that turns the
prepared 5.2/5.3 host into the configured RateGuru service host required
for deployment. It has its own runbook: see
[`bootstrap-services.md`](bootstrap-services.md) for the ownership
boundaries (coordinator vs. the child installers it invokes), the
PRE_DEPLOY/DEPLOYED distinction, the external-prerequisite contract and
the real staging acceptance sequence.

## Slice 5.6: the clean-VPS acceptance — passed

Slice 5.5 built the machinery; 5.6 proved it in reality, on a disposable
Ubuntu 22.04 x86_64 VPS that started with nothing: no RateGuru directories
or accounts, and no PHP, PostgreSQL, Nginx, Redis, Supervisor or rclone.

| Step | Result |
|---|---|
| `bootstrap-host-preflight` on the untouched host | correctly reported a clean, unready host |
| `bootstrap-host --check` | 5.2 NEEDS_APPLY, 5.3 BLOCKED, 5.4 BLOCKED — dependency-aware, nothing misjudged |
| first `bootstrap-host --apply` | 5.2 and 5.3 installed from scratch; 5.4 failed **closed before mutating** on the absent external TLS/Basic Auth material |
| external prerequisites supplied, bootstrap re-run | **resumed at 5.4** — the converged 5.2/5.3 were not rebuilt |
| PRE_DEPLOY | accepted as a terminal state; no `current`/`previous` was fabricated to satisfy a check |
| first real immutable deploy | worked, including the first-deploy Supervisor queue activation |
| health, PHP-FPM, PostgreSQL, Redis, Nginx, queue worker, scheduler path | all exercised |
| `rollback` | worked against real immutable release history |
| local backup + `restore-test`, B2 offsite upload + `offsite-restore-test`, full `backup-cycle` | all worked |
| final verification and repeat `--apply` | idempotent |

The canonical entry point for bringing up a host is `bootstrap-host` (see
its section at the top), with the manual external prerequisites documented
in [`bootstrap-services.md`](bootstrap-services.md).

### The two defects only a clean host could find

Both were **historical-state dependencies**: the staging host had satisfied
them long ago by accident of history, so neither CI nor a re-run against an
already-compliant host could ever have surfaced them. Finding this class of
bug is the entire reason 5.6 existed as a separate gate.

- **An unmanaged trusted helper (fixed in PR #1124).** `deploy` depended on
  the installed `/home/www/rateguru/bin/verify-required-clis`, but
  `install-target-operations` did not manage or install it — so a freshly
  bootstrapped host simply did not have it. It is now a first-class
  root-owned managed operational CLI, with the same transactional
  install/verify/rollback coverage as every other file in the bundle.
- **An identity the clean host never had (fixed in PR #1125).** Immutable
  releases are `deploy_user:code_group` `0750`/`0640` and Nginx runs as
  `www-data`, but clean bootstrap had never added `www-data` to the active
  target's code group. Nginx evaluates `try_files` against `current/public`
  itself, before FastCGI is involved, so the host answered every request
  with HTTP 404 while its error log showed `Permission denied` stat'ing
  `current/public` and `public/index.php`. `www-data` → active-target
  `code_group` is now an explicit bootstrap identity contract, and because
  supplementary groups are fixed at process creation, already-running Nginx
  workers are converged to the new state without unconditional reloads —
  see [`bootstrap-services.md`](bootstrap-services.md).

Neither fix widened the security model: `www-data` gained read-only access
to immutable *code* and nothing else. It is still not in any runtime group,
shared mutable storage is still reached only through the separate narrow
POSIX ACL, releases remain `0750`/`0640`, and no world-readable workaround
was introduced.

### What this does not prove

Phase 5 proves that a brand-new supported VPS can be reproducibly
bootstrapped into a working RateGuru application host. It does **not**
prove disaster recovery. The offsite `restore-test` that passed here shows
a backup is restorable — a different question from reconstructing a lost
application — and closes nothing in Phase 7, which still owns rebuilding a
lost application from the exact `source_sha` its backup's own `release.json`
already carries, restoring application state on a clean server,
application-level verification after recovery, and the timed drill.
