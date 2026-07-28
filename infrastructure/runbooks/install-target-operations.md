# Installing the target-aware read operations

Phase 4 slice 2b. This runbook covers `infrastructure/scripts/install-target-operations`,
which installs the deployment target registry and the four read-only
target-aware scripts on the staging VPS.

For what the registry and the target-aware commands themselves are, see
[`deployment-targets.md`](deployment-targets.md). This document is only about
getting them onto the host safely.

## What this installer owns — and does not

Exactly five files:

| Source (this repo) | Destination |
|---|---|
| `infrastructure/config/deployment-targets.json` | `/home/www/rateguru/config/deployment-targets.json` |
| `infrastructure/scripts/targets` | `/home/www/rateguru/bin/targets` |
| `infrastructure/scripts/common` | `/home/www/rateguru/bin/common` |
| `infrastructure/scripts/health-check` | `/home/www/rateguru/bin/health-check` |
| `infrastructure/scripts/status` | `/home/www/rateguru/bin/status` |

These destinations are **fixed, hardcoded constants** in the installer — not
configurable by environment variable or CLI argument, on purpose. This
installer's entire job is putting these five files in these five places with
these exact permissions. Nothing else.

### The two destination directories must already exist

`/home/www/rateguru/config` and `/home/www/rateguru/bin` are **not** owned by
this installer, and it never creates, `chown`s or `chmod`s either one — only
the five files inside them. `--apply` validates both directories before it
creates a backup or changes anything: each must exist, be a real directory
(not a symlink), owned by `root:root`, and not group- or other-writable.
`--apply` refuses to proceed — before touching anything — if either
directory is missing or fails any of those checks. Provisioning those two
directories (ownership, mode, and everything else about the host they live
on) belongs to a VPS bootstrap step outside this installer's scope.

**Not touched, by this or any other change in this slice:**

- `/home/www/rateguru/config/deployment.conf` — the target helpers already
  default to the registry's installed path above, so nothing there needs to
  change;
- `deploy`, `rollback`, `cleanup` — still `--environment`-only;
- `backup`, `backup-cycle`, `offsite-backup`, `offsite-retention`,
  `restore-test`, `offsite-restore-test` — untouched;
- GitHub Actions workflows, sudoers rules, server wrappers;
- any Nginx, PHP-FPM, Supervisor or cron configuration;
- the registry's own contents — `staging-main` and `tits-guru` keep the exact
  values slice 1 and slice 2 committed.

### Why tits-guru remains planned

Installing `health-check` and `status` on the VPS does **not** provision
`tits-guru`. It has `lifecycle: planned` in the registry, and every
target-aware command rejects it — before any URL is built, any `curl` runs, or
any filesystem path is touched — via `require_active_target`, which only
lets `lifecycle: active` targets through. `staging-main` is the only target
with that lifecycle. Nothing in this installer, or in the scripts it installs,
creates a `tits-guru` directory, database, service, or DNS record; those all
belong to a future slice that provisions the target for real, and only then
would its lifecycle be reviewed and changed to `active`.

### Why no deploy/rollback/cleanup/backup script is changed

Those scripts write to a target's filesystem, database, or running service
state. This slice is deliberately the *safe half*: every command it installs
is read-only — a health probe and a status report, nothing that could put the
host in a different state than it started in. Making the mutating commands
target-aware is later work, once this read-only foundation has run on the real
host and proven itself.

## Modes

```bash
infrastructure/scripts/install-target-operations --check
infrastructure/scripts/install-target-operations --apply
infrastructure/scripts/install-target-operations --verify
infrastructure/scripts/install-target-operations --help
```

Exactly one mode is required. `--check` and `--apply` together, `--apply`
twice, an unrecognized flag, or a stray positional argument all fail with a
clear error before anything else runs.

### `--check` — repository-only, no root

Validates the five source files (exist, regular, not a symlink), runs
`bash -n` on the four shell scripts, confirms `jq` can parse the registry,
runs the *committed* `targets` CLI against the *committed* registry and
confirms it both validates and lists `staging-main` as `active`/`staging` and
`tits-guru` as `planned`/`production`, and confirms every required host tool
is present (`bash`, `jq`, `curl`, `install`, `stat`, `cmp`, `mv`, `cp`,
`mktemp`, `readlink`, `tail`, `env`). Makes no changes anywhere. Safe to run
from a laptop checkout, in CI, or on the VPS before ever touching it as root.

### `--apply` — requires root, transactional

```bash
sudo infrastructure/scripts/install-target-operations --apply
```

1. Everything `--check` does, plus: the *installed* `deployment.conf` is
   validated (regular file, not a symlink, root-owned, not group- or
   other-writable) — this installer depends on that file's protection without
   ever touching its contents.
2. Both destination directories are validated — exist, are real directories,
   not symlinks, `root:root`-owned, not group- or other-writable (see
   [above](#the-two-destination-directories-must-already-exist)) — before a
   backup directory is created or a single destination file changes.
3. The **currently installed** legacy staging health check is proven to work
   — `health-check --environment staging`, with every `RATEGURU_*` test
   override explicitly unset — before a single destination file changes. If
   staging is already unhealthy, apply refuses to touch anything: there would
   be no way to tell whether a later failure was caused by this install or was
   already there.
4. The five source files are copied into a private, root-only temporary
   staging directory, then run together there — using the `RATEGURU_*` test
   override contract from slice 2, and **only** here — to prove the candidate
   set is internally consistent before anything real is touched: `targets
   validate`, `health-check --environment staging`,
   `health-check --target staging-main`, `status --environment staging`,
   `status --target staging-main`, and that `health-check --target tits-guru`
   still correctly fails with `lifecycle=planned`.
5. A timestamped backup directory is created (see below), and each
   destination is installed in dependency order — registry, `targets`,
   `common`, `health-check`, `status` — via stage-in-place-then-atomic-rename
   into a same-directory, `mktemp`-created temporary file, never a direct
   overwrite and never a predictable temporary path. An existing destination
   that is anything other than absent or a plain regular file — a symlink,
   directory, FIFO, socket or device — is refused outright, never followed,
   entered or silently replaced; a rejected destination is left untouched and
   is never backed up.
6. The installed result is verified: exact ownership, exact mode, byte-for-byte
   content match against the committed source, `bash -n`, and
   `targets validate`/`targets list` against the installed registry.
7. Runtime parity is verified against the real host, with **every**
   `RATEGURU_*` override explicitly unset (`env -u ...`) — see
   [Runtime parity](#runtime-parity-checks) below.
8. Only once every one of the above passes is the change committed. Before
   that point, any failure rolls back every file this run touched — see
   [Rollback](#rollback) below.

### `--verify` — requires root, read-only

```bash
sudo infrastructure/scripts/install-target-operations --verify
```

Re-runs the installed-file and runtime-parity checks against whatever is
*currently* installed. Makes no changes and creates no backup — safe to run
repeatedly, any time, including as a routine health check independent of any
install. Reports each phase separately:

```
--- source validation ---
--- installed-file validation ---
--- legacy staging health ---
--- target staging health ---
--- status parity ---
--- planned-target rejection ---
--- final result ---
PASS: installed files and runtime behaviour verified
```

A failure at any phase stops there with a specific error and a final `FAIL`
line — `--verify` never claims success after a step it didn't actually pass.

## Ownership and modes

| | Owner | Mode | Notes |
|---|---|---|---|
| `deployment-targets.json` | `root:root` | `0640` | registry — non-secret, but not world-readable |
| `targets`, `common`, `health-check`, `status` | `root:root` | `0755` | executable scripts |

None of the five may be group- or world-writable, and none may be a symlink —
enforced both when installing and when verifying. Existing destinations must
also be a plain regular file or absent — a directory, FIFO, socket or device
is refused the same way a symlink is.

The two containing directories, `/home/www/rateguru/config` and
`/home/www/rateguru/bin`, must be `root:root`, `0755` or stricter, and are
only ever read by this installer — never created, `chown`ed or `chmod`ed. See
[The two destination directories must already exist](#the-two-destination-directories-must-already-exist).

## Backup location

```
/var/backups/rateguru-target-operations/<UTC timestamp>/
```

One directory per `--apply` run, `0700`, root-only. Inside, each backed-up
file lives at its own destination's absolute path (so
`/home/www/rateguru/bin/common`'s prior version is backed up at
`<backup dir>/home/www/rateguru/bin/common`), copied with `cp -a` to preserve
its original ownership, mode and timestamps exactly.

**This installer never deletes old backup directories.** They accumulate
across every apply run and are left for an operator to prune manually if
disk space becomes a concern — deciding what's safe to discard is a judgement
call this slice deliberately doesn't automate.

## Rollback

If anything fails after files start being touched — installation, installed-file
verification, or runtime parity — every destination this run touched is
restored: a destination that existed before is restored from its backup;
a destination that didn't exist before is removed. This happens automatically,
via a trap, without a second command.

After a rollback, the installer re-confirms
`health-check --environment staging` still succeeds against the *restored*
files, and reports that confirmation explicitly — so a failed apply doesn't
just claim "rolled back," it proves the host is genuinely back to working
order.

**Rollback failure is reported, never hidden.** If any single file can't be
restored, the installer says so explicitly, names the backup directory, and
still exits with the original (non-zero) failure code — a broken rollback
never gets mistaken for a successful one, and never masks *why* the apply
failed in the first place.

### Manually restoring a backup, if automatic rollback itself fails

Find the run's backup directory (the apply log prints it, and they sort
chronologically):

```bash
ls -la /var/backups/rateguru-target-operations/
```

Restore one file by mirroring it back to its real location, preserving the
backup's recorded ownership and mode:

```bash
sudo cp -a \
    /var/backups/rateguru-target-operations/<timestamp>/home/www/rateguru/bin/common \
    /home/www/rateguru/bin/common
```

Repeat for each of the five destinations that need restoring. Confirm with:

```bash
sudo infrastructure/scripts/install-target-operations --verify
```

If a destination has **no** corresponding path under a given backup directory,
it did not exist before that run — remove it rather than trying to restore it:

```bash
sudo rm -f /home/www/rateguru/bin/common
```

## Runtime parity checks

Every one of these runs with `RATEGURU_ALLOW_TEST_OVERRIDES`,
`RATEGURU_COMMON_FILE`, `RATEGURU_DEPLOYMENT_CONF_FILE`,
`RATEGURU_TARGET_REGISTRY_FILE`, `RATEGURU_TARGETS_CLI` and
`RATEGURU_HEALTH_CHECK_CLI` all explicitly unset — this is what makes the
check genuine proof of real host behaviour rather than a rehearsal against
overridden paths:

1. `health-check --environment staging` succeeds;
2. `health-check --target staging-main` succeeds;
3. `status --environment staging` runs;
4. `status --target staging-main` runs;
5. the target status output contains `Target: staging-main`,
   `Lifecycle: active`, `Environment class: staging`;
6. the legacy status output contains `Environment: staging`;
7. both status modes report the **same** current release, previous release,
   `release.json` metadata, and deployment history — proven by comparing
   everything after each mode's own header, since both resolve the identical
   `application_root`. Timestamps and the header lines themselves differ by
   design and are excluded from that comparison;
8. `health-check --target tits-guru` **fails**, and its error names
   `lifecycle=planned`;
9. nothing about `tits-guru` — no directory, database, service, or hostname —
   is created or contacted, because step 8 rejects it before any of those
   would ever be touched.

## Expected server commands

```bash
# From a checkout of this repository on the VPS, or copied there.
sudo infrastructure/scripts/install-target-operations --check
sudo infrastructure/scripts/install-target-operations --apply
sudo infrastructure/scripts/install-target-operations --verify

# Routine health check, any time after install:
sudo infrastructure/scripts/install-target-operations --verify

# The legacy interface keeps working throughout and afterward, unchanged:
/home/www/rateguru/bin/health-check --environment staging
/home/www/rateguru/bin/status --environment staging

# The new interface becomes available once installed:
/home/www/rateguru/bin/health-check --target staging-main
/home/www/rateguru/bin/status --target staging-main
```

## Troubleshooting

- **`--apply` refuses immediately with a destination-directory error**:
  `/home/www/rateguru/config` or `/home/www/rateguru/bin` is missing, isn't a
  real directory, isn't `root:root`, or is group- or other-writable. Fix the
  directory itself on the host — this installer will not create, `chown` or
  `chmod` either one for you.
- **`--apply` refuses immediately with a legacy health check failure**: staging
  is already unhealthy before this install touched anything. Fix that first —
  `install-target-operations` will not proceed while it can't tell whether a
  later failure is its own doing.
- **`--apply` fails during staged candidate verification**: the *candidate*
  files (this repository's committed copies) don't work together correctly.
  Nothing on the host was touched — this failure happens before any
  destination file is written.
- **`--apply` fails after installation and rolls back**: check the specific
  failed step in the log (installed-file verification vs. runtime parity).
  The previous files are already restored; re-run `--check` to confirm the
  candidates are still valid before trying `--apply` again.
- **Rollback itself reports incomplete**: follow
  [Manually restoring a backup](#manually-restoring-a-backup-if-automatic-rollback-itself-fails)
  above, then re-run `--verify`.
- **`--verify` fails on an already-installed host**: read the phase label it
  stopped at — `--- installed-file validation ---` points at a file that was
  modified or has drifted from its source; `--- legacy staging health ---` or
  `--- target staging health ---` point at the application itself, not this
  installer.
