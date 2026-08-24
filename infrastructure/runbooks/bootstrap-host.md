# Bootstrap host

This runbook covers the clean-VPS bootstrap scripts:

- `infrastructure/scripts/bootstrap-host-preflight` — Phase 5 slice 5.1,
  the read-only host contract inspection;
- `infrastructure/scripts/install-bootstrap-runtime` — Phase 5 slice 5.2,
  the reproducible base/runtime package installation (see its own section
  below).

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
`SUMMARY` — with each item classified `PASS`, `MISSING`, `WARN` or
`CONFLICT`, ending with:

```text
Bootstrap host preflight:
PASS: 28
MISSING: 14
WARN: 3
CONFLICT: 0

HOST READY: NO
```

`HOST READY: YES` (and `--check` exiting 0) requires zero `MISSING` and zero
`CONFLICT` items. `WARN` never blocks. A missing prerequisite is never
individually fatal — every section always prints, so a clean host produces a
complete inventory of what Phase 5 has to build rather than an early abort.

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
- **SERVICES** — nginx, the PHP-FPM service named by the committed
  `deployment.conf` template, PostgreSQL, Redis, Supervisor: each reported
  `missing` / `installed-stopped` / `installed-running`, never started or
  reloaded. The staging mail capture (`staging-mailpit`,
  `staging-mailtrap-local`) is detected separately and labeled
  `shared-host-service` — it belongs to the shared staging environment, not
  to a RateGuru target.
- **USERS/GROUPS** — root, www-data, postgres, the per-target runtime and
  deploy accounts from the source registry, the mail-capture accounts, the
  runtime/code groups, and the one repo-required membership relation: the
  runtime user must be in the code group (releases are
  `deploy_user:code_group` mode `0750`/`0640`, and PHP-FPM must read them).
  Names and relations are the contract — no accidental numeric UID/GID is
  ever asserted.
- **FILESYSTEM** — the runtime tree (`/home/www/rateguru` and its
  config/bin/backups/run subtrees), the fifteen files
  `install-target-operations` manages, the per-target tree derived from the
  **source registry** (application root, `releases`, `shared`, the
  `current` symlink, `locks`, `deployments`, incoming artifacts — never an
  assumed `/home/www/rateguru/current`), `/var/log/rateguru`, the perimeter
  files (wrappers, sudoers, cron, sshd config), and installed service
  configuration. Each item: absent/present, type, owner/group, mode, and
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
  validated, or printed.**

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
- **Nginx, Redis, Supervisor** and every base utility from the Ubuntu
  22.04 distribution repository.

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

A repository already provided by a pre-existing apt source — however the
operator configured it, like the classic `add-apt-repository` `.list` on
the current staging host — is recognized and left untouched. Unrelated
host-wide repositories (NodeSource, ClickHouse, Datadog/Vector, anything
else) are never inspected, managed, removed or required absent: `--check`
and `--verify` on the current staging host pass with them present.

### apt policy and idempotency

`--check`/`--verify` never run apt-get at all (package state is read via
`dpkg-query`). `--apply` runs `apt-get update` exactly once and only when
repository configuration changed or packages must be installed, then one
deterministic `DEBIAN_FRONTEND=noninteractive apt-get install -y
--no-install-recommends` of the missing set. `apt-get upgrade` in any form
is never run, and packages are never removed. A second `--apply` on a
satisfied host performs no apt call, fetches no key and rewrites no file.

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

The real staging preflight currently reports one conflict:
`/home/www/rateguru/staging` is owned
`deploy-rateguru-staging:rateguru-staging-code` while the Phase 5 contract
expects the top-level application root to be root-owned. Slice 5.2 never
`chown`s anything — this is recorded here as a slice 5.3 (users/groups/
filesystem) remediation.

### Test overrides

Like the preflight, every probe and mutation path can be redirected through
`RATEGURU_BOOTSTRAP_*` environment variables — honored only alongside
`RATEGURU_ALLOW_TEST_OVERRIDES=true`. This is what lets
`tests/Feature/Architecture/InstallBootstrapRuntimeTest.php` prove clean-host
bootstrap, staging compatibility, repository failure safety, idempotency and
the full check/apply/verify matrix without the CI runner ever running apt.

## What later slices do (and these do not)

| Slice | Mutation |
|---|---|
| 5.3 | users, groups, filesystem tree |
| 5.4 | service configuration + existing installers |
| 5.5 | one-shot orchestrator ending in `--check` passing |
| 5.6 | real clean-VPS acceptance |

Until those land, bringing up a new host remains the manual procedure spread
across the existing runbooks (`install-target-operations.md`,
`target-perimeter.md`, `public-storage-access.md`, `mail-capture.md`,
`backups.md`).
