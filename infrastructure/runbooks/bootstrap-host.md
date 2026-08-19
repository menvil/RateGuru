# Bootstrap host preflight

This runbook covers `infrastructure/scripts/bootstrap-host-preflight` —
Phase 5 slice 5.1, the read-only host contract inspection for the clean-VPS
bootstrap.

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

```
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

Only the RateGuru staging VPS baseline: **Ubuntu** with apt/dpkg and
systemd. Any other OS family is a `CONFLICT`; a different Ubuntu release
than the pinned baseline (`SUPPORTED_OS_VERSION_ID` in the script) is a
`WARN`, so the report stays honest if the staging baseline moves before the
pin is updated. This script deliberately does not pretend to support
arbitrary Linux distributions.

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

## What later slices do (and this one does not)

| Slice | Mutation |
|---|---|
| 5.2 | base/runtime packages |
| 5.3 | users, groups, filesystem tree |
| 5.4 | service configuration + existing installers |
| 5.5 | one-shot orchestrator ending in `--check` passing |
| 5.6 | real clean-VPS acceptance |

Until those land, bringing up a new host remains the manual procedure spread
across the existing runbooks (`install-target-operations.md`,
`target-perimeter.md`, `public-storage-access.md`, `mail-capture.md`,
`backups.md`).
