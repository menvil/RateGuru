# Installing the target-aware operations

This runbook covers `infrastructure/scripts/install-target-operations`, which
installs the deployment target registry, the host-global `deployment.conf`,
and the full set of target-aware operational scripts onto the staging VPS:
`targets`, `common`, `health-check`, `status`, `cleanup`, `deploy`,
`rollback`, `backup`, `restore-test`, `offsite-backup`, `offsite-retention`,
`offsite-restore-test`, and `backup-cycle`.

For what the registry and the target-aware commands themselves are, see
[`deployment-targets.md`](deployment-targets.md). This document is only about
getting them onto the host safely.

## What this installer owns — and does not

Exactly fifteen files:

| Source (this repo) | Destination |
|---|---|
| `infrastructure/config/deployment-targets.json` | `/home/www/rateguru/config/deployment-targets.json` |
| `infrastructure/templates/deployment.conf.example` | `/home/www/rateguru/config/deployment.conf` |
| `infrastructure/scripts/targets` | `/home/www/rateguru/bin/targets` |
| `infrastructure/scripts/common` | `/home/www/rateguru/bin/common` |
| `infrastructure/scripts/health-check` | `/home/www/rateguru/bin/health-check` |
| `infrastructure/scripts/status` | `/home/www/rateguru/bin/status` |
| `infrastructure/scripts/cleanup` | `/home/www/rateguru/bin/cleanup` |
| `infrastructure/scripts/deploy` | `/home/www/rateguru/bin/deploy` |
| `infrastructure/scripts/rollback` | `/home/www/rateguru/bin/rollback` |
| `infrastructure/scripts/backup` | `/home/www/rateguru/bin/backup` |
| `infrastructure/scripts/restore-test` | `/home/www/rateguru/bin/restore-test` |
| `infrastructure/scripts/offsite-backup` | `/home/www/rateguru/bin/offsite-backup` |
| `infrastructure/scripts/offsite-retention` | `/home/www/rateguru/bin/offsite-retention` |
| `infrastructure/scripts/offsite-restore-test` | `/home/www/rateguru/bin/offsite-restore-test` |
| `infrastructure/scripts/backup-cycle` | `/home/www/rateguru/bin/backup-cycle` |

These destinations are **fixed, hardcoded constants** in the installer — not
configurable by environment variable or CLI argument, on purpose. This
installer's entire job is putting these fifteen files in these fifteen
places with these exact permissions. Nothing else. It never sources or
evaluates `deployment.conf` as shell — it installs it as plain file content,
identically to every other file it manages.

### The two destination directories must already exist

`/home/www/rateguru/config` and `/home/www/rateguru/bin` are **not** owned by
this installer, and it never creates, `chown`s or `chmod`s either one — only
the fifteen files inside them. `--apply` validates both directories before
it creates a backup or changes anything: each must exist, be a real
directory (not a symlink), owned by `root:root`, and not group- or
other-writable. `--apply` refuses to proceed — before touching anything — if
either directory is missing or fails any of those checks. Provisioning those
two directories (ownership, mode, and everything else about the host they
live on) belongs to a VPS bootstrap step outside this installer's scope.

**Not touched, by this or any other change here:**

- cron, GitHub Actions workflows, sudoers rules, the three generic sudo
  wrappers — that perimeter is a separate installer,
  `infrastructure/scripts/install-target-perimeter`; see
  [`target-perimeter.md`](target-perimeter.md);
- systemd timers;
- any Nginx, PHP-FPM, Supervisor or cron configuration;
- the registry's own contents — `staging-main` and `tits-guru` keep the exact
  values committed to this repository.

### Why tits-guru remains planned

Installing `health-check`, `status`, `cleanup`, `deploy`, `rollback`,
`backup`, `restore-test`, `offsite-backup`, `offsite-retention`,
`offsite-restore-test` and `backup-cycle` on the VPS does **not** provision
`tits-guru`. It has `lifecycle: planned` in the registry, and every
target-aware command rejects it — before any URL is built, any `curl` runs,
or any filesystem path, database, lock or history is touched — via
`require_active_target`, which only lets `lifecycle: active` targets through.
`staging-main` is the only target with that lifecycle. Nothing in this
installer, or in the scripts it installs, creates a `tits-guru` directory,
database, service, or DNS record; those all belong to a future slice that
provisions the target for real, and only then would its lifecycle be
reviewed and changed to `active`.

### backup-cycle orchestration

`backup-cycle` never touches a database, filesystem, or Backblaze B2
directly — it only invokes the five scripts installed above (`backup`,
`restore-test`, `offsite-backup`, `offsite-retention --apply`,
`offsite-restore-test`), strictly in that order, stopping at the first
failure. Each of those five is already gated behind the same
`require_active_target` lifecycle check `cleanup`, `deploy` and `rollback`
rely on — checked immediately after root authorization, before any
filesystem, database, remote-listing or lock work — plus a shared backup
namespace/lock. `backup-cycle` itself carries the identical lifecycle check
and shares the same namespace/lock contract, and additionally appends one
compact JSON record per cycle to
`/home/www/rateguru/backups/backup-cycles.jsonl` — see
[Target-aware backup cycle](deployment-targets.md#target-aware-backup-cycle)
in `deployment-targets.md` for the full pipeline and history schema.

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

Validates the fifteen source files (exist, regular, not a symlink), runs
`bash -n` on the thirteen shell scripts (every source file except the
registry and `deployment.conf`, neither of which is shell), confirms `jq`
can parse the registry, runs the *committed* `targets` CLI against the
*committed* registry and confirms it both validates and lists `staging-main`
as `active`/`staging` and `tits-guru` as `planned`/`production`, and confirms
every required host tool is present (`bash`, `jq`, `curl`, `install`, `stat`,
`cmp`, `diff`, `awk`, `mv`, `cp`, `mktemp`, `readlink`, `tail`, `env`, `find`,
`sort`, `cut`, `sed`, `rm`, `flock` — the last six for `cleanup`). `rclone`
itself is **not** required on this list: this installer never calls it — the
offsite scripts' staged and runtime-parity checks below only reach `--help`
and the `lifecycle=planned` rejection path, both of which return before any
`rclone` config check or remote call. Makes no changes anywhere. Safe to run
from a laptop checkout, in CI, or on the VPS before ever touching it as root.

### `--apply` — requires root, transactional

```bash
sudo infrastructure/scripts/install-target-operations --apply
```

1. Everything `--check` does.
2. Both destination directories are validated — exist, are real directories,
   not symlinks, `root:root`-owned, not group- or other-writable (see
   [above](#the-two-destination-directories-must-already-exist)) — before a
   backup directory is created or a single destination file changes.
3. The **currently installed** `staging-main` health check is proven to work
   — `health-check --target staging-main`, with every `RATEGURU_*` test
   override explicitly unset — before a single destination file changes. If
   staging is already unhealthy, apply refuses to touch anything: there would
   be no way to tell whether a later failure was caused by this install or was
   already there.
4. The fifteen source files are copied into a private, root-only temporary
   staging directory, then run together there — using the `RATEGURU_*` test
   override contract, and **only** here — to prove the candidate set is
   internally consistent before anything real is touched: `targets validate`;
   every script's `--help` output is checked to mention `--target` and never
   the retired legacy selector; `health-check --target staging-main`;
   `status --target staging-main`; `cleanup --target staging-main --dry-run`;
   and that `health-check`, `cleanup --dry-run`, `deploy` (with a deliberately
   unusable release/artifact combination), `rollback` (with a deliberately
   unusable release ID), `backup`, `restore-test`, `offsite-backup`,
   `offsite-retention`, `offsite-restore-test` and `backup-cycle` all still
   correctly fail against `--target tits-guru` with `lifecycle=planned`.
   Every staged `cleanup` invocation here is `--dry-run` only, and neither
   `deploy`, `rollback`, `backup`, `restore-test`, `offsite-backup`,
   `offsite-retention`, `offsite-restore-test` nor `backup-cycle` is ever
   invoked for real (a deployment, a rollback, a database dump, a restore, a
   remote upload, a remote deletion, a remote restore test, or a full backup
   cycle), so this step never mutates the real staging target and never
   contacts Backblaze B2.
5. A timestamped backup directory is created (see below), and each
   destination is installed in dependency order — registry, `targets`,
   `common`, `health-check`, `status`, `cleanup`, `deploy`, `rollback`,
   `backup`, `restore-test`, `offsite-backup`, `offsite-retention`,
   `offsite-restore-test`, `backup-cycle`, then `deployment.conf` last — via
   stage-in-place-then-atomic-rename into a same-directory, `mktemp`-created
   temporary file, never a direct overwrite and never a predictable temporary
   path. `deployment.conf` is installed last so every script sourcing it
   either atomically sees the old config or the new one, never a
   half-installed bundle in between. An existing destination that is anything
   other than absent or a plain regular file — a symlink, directory, FIFO,
   socket or device — is refused outright, never followed, entered or
   silently replaced; a rejected destination is left untouched and is never
   backed up.
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
--- staging-main health ---
--- status ---
--- planned-target rejection ---
--- cleanup dry-run ---
--- cleanup planned-target rejection ---
--- deploy help ---
--- deploy planned-target rejection ---
--- rollback help ---
--- rollback planned-target rejection ---
--- backup help ---
--- backup planned-target rejection ---
--- restore-test help ---
--- restore-test planned-target rejection ---
--- offsite-backup help ---
--- offsite-backup planned-target rejection ---
--- offsite-retention help ---
--- offsite-retention planned-target rejection ---
--- offsite-restore-test help ---
--- offsite-restore-test planned-target rejection ---
--- backup-cycle help ---
--- backup-cycle planned-target rejection ---
--- final result ---
PASS: installed files and runtime behaviour verified
```

A failure at any phase stops there with a specific error and a final `FAIL`
line — `--verify` never claims success after a step it didn't actually pass.

## Ownership and modes

| | Owner | Mode | Notes |
|---|---|---|---|
| `deployment-targets.json` | `root:root` | `0640` | registry — non-secret, but not world-readable |
| `deployment.conf` | `root:root` | `0640` | host-global settings — non-secret, but not world-readable, same protection as the registry |
| `targets`, `health-check`, `status`, `cleanup`, `deploy`, `rollback`, `backup`, `restore-test`, `offsite-backup`, `offsite-retention`, `offsite-restore-test`, `backup-cycle` | `root:root` | `0755` | executable scripts |
| `common` | `root:root` | `0644` | sourced library, never a CLI — must never be executable |

None of the fifteen may be group- or world-writable, and none may be a
symlink — enforced both when installing and when verifying. Existing
destinations must also be a plain regular file or absent — a directory,
FIFO, socket or device is refused the same way a symlink is.

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
call this installer deliberately doesn't automate.

## Rollback

If anything fails after files start being touched — installation, installed-file
verification, or runtime parity — every destination this run touched is
restored: a destination that existed before is restored from its backup;
a destination that didn't exist before is removed. This happens automatically,
via a trap, without a second command.

After a rollback, the installer re-confirms `health-check --target
staging-main` still succeeds against the *restored* files, and reports that
confirmation explicitly — so a failed apply doesn't just claim "rolled
back," it proves the host is genuinely back to working order.

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

Repeat for each of the fifteen destinations that need restoring. Confirm with:

```bash
sudo infrastructure/scripts/install-target-operations --verify
```

If a destination has **no** corresponding path under a given backup directory,
it did not exist before that run — remove it rather than trying to restore it:

```bash
sudo rm -f /home/www/rateguru/bin/common
```

## Runtime parity checks

Every one of these runs with the full `RATEGURU_*` override set explicitly
unset — `RATEGURU_ALLOW_TEST_OVERRIDES`, `RATEGURU_COMMON_FILE`,
`RATEGURU_DEPLOYMENT_CONF_FILE`, `RATEGURU_TARGET_REGISTRY_FILE`,
`RATEGURU_TARGETS_CLI`, `RATEGURU_HEALTH_CHECK_CLI`, and every
script-specific binary-override variable each operational script defines —
this is what makes the check genuine proof of real host behaviour rather than
a rehearsal against overridden paths:

1. `health-check --target staging-main` succeeds;
2. `status --target staging-main` succeeds, and its output contains
   `Target: staging-main`, `Lifecycle: active`, `Environment class: staging`,
   exactly one occurrence each of the four standard sections (`Releases`,
   `Current release metadata`, `Health`, `Recent deployment history`), and
   `Status: healthy`;
3. `health-check --target tits-guru` **fails**, and its error names
   `lifecycle=planned` — nothing about `tits-guru` (no directory, database,
   service, or hostname) is created or contacted, because this rejects it
   before any of those would ever be touched;
4. `cleanup --target staging-main --dry-run` succeeds;
5. `cleanup --target tits-guru --dry-run` **fails**, and its error names
   `lifecycle=planned` — `cleanup` never contacts `tits-guru` either;
6. `deploy --help` succeeds;
7. `deploy --target tits-guru`, given a deliberately unusable release/artifact
   combination, **fails**, and its error names `lifecycle=planned` — proving
   the installed `deploy`'s ordering is root authorization →
   `lifecycle=planned` rejection → no artifact or filesystem validation ever
   reached. `deploy` is never invoked with a real artifact by this installer,
   at any point — a real deployment is always a separate, explicit,
   human-triggered action;
8. `rollback --help` succeeds;
9. `rollback --target tits-guru`, given a deliberately unusable release ID,
   **fails**, and its error names `lifecycle=planned` — proving the
   installed `rollback`'s ordering is root authorization →
   `lifecycle=planned` rejection → no filesystem, lock or history work ever
   reached. `rollback` is never invoked for a real rollback by this
   installer, at any point — a real rollback is always a separate,
   explicit, human-triggered action;
10. `backup --help` succeeds;
11. `backup --target tits-guru` **fails**, and its error names
    `lifecycle=planned` — proving the installed `backup`'s ordering is root
    authorization → `lifecycle=planned` rejection → no backup root, lock,
    database binary or filesystem work ever reached. `backup` is never
    invoked for a real backup by this installer, at any point;
12. `restore-test --help` succeeds;
13. `restore-test --target tits-guru` **fails**, and its error names
    `lifecycle=planned` — proving the installed `restore-test`'s ordering is
    root authorization → `lifecycle=planned` rejection → no filesystem, lock
    or database work ever reached. `restore-test` is never invoked for a
    real restore test by this installer, at any point — a real backup or
    restore test is always a separate, explicit, human-triggered action;
14. `offsite-backup --help` succeeds;
15. `offsite-backup --target tits-guru` **fails**, and its error names
    `lifecycle=planned` — proving the installed `offsite-backup`'s ordering
    is root authorization → `lifecycle=planned` rejection → no `rclone`
    config check, remote listing, local backup root, lock, temp directory or
    database work ever reached. `offsite-backup` is never invoked for a real
    upload by this installer, at any point, and never contacts Backblaze B2;
16. `offsite-retention --help` succeeds;
17. `offsite-retention --target tits-guru` **fails**, and its error names
    `lifecycle=planned` — proving the installed `offsite-retention`'s
    ordering is root authorization → `lifecycle=planned` rejection → no
    `rclone` config check, remote listing or lock work ever reached, in
    either its default dry-run mode or `--apply`. `offsite-retention` is
    never invoked for a real deletion by this installer, at any point, and
    never contacts Backblaze B2;
18. `offsite-restore-test --help` succeeds;
19. `offsite-restore-test --target tits-guru` **fails**, and its error names
    `lifecycle=planned` — proving the installed `offsite-restore-test`'s
    ordering is root authorization → `lifecycle=planned` rejection → no
    `rclone` config check, remote listing, lock, temp directory or database
    work ever reached. `offsite-restore-test` is never invoked for a real
    restore test by this installer, at any point, and never contacts
    Backblaze B2 — a real offsite backup, retention run, or offsite restore
    test is always a separate, explicit, human-triggered action;
20. `backup-cycle --help` succeeds;
21. `backup-cycle --target tits-guru` **fails**, and its error names
    `lifecycle=planned` — proving the installed `backup-cycle`'s ordering is
    root authorization → `lifecycle=planned` rejection → no lock root, lock
    file, or child-command work ever reached. `backup-cycle` is never
    invoked for a real cycle by this installer, at any point, and never
    contacts Backblaze B2 — a real backup cycle is always a separate,
    explicit, human- or cron-triggered action.

## Expected server commands

```bash
# From a checkout of this repository on the VPS, or copied there.
sudo infrastructure/scripts/install-target-operations --check
sudo infrastructure/scripts/install-target-operations --apply
sudo infrastructure/scripts/install-target-operations --verify

# Routine health check, any time after install:
sudo infrastructure/scripts/install-target-operations --verify

# cleanup dry-run — always safe, never mutates anything:
/home/www/rateguru/bin/cleanup --target staging-main --dry-run

# Read-only operations:
/home/www/rateguru/bin/health-check --target staging-main
/home/www/rateguru/bin/status --target staging-main

# Mutating operations:
/home/www/rateguru/bin/deploy --target staging-main --release ... --artifact ...
/home/www/rateguru/bin/rollback --target staging-main --previous
sudo /home/www/rateguru/bin/backup --target staging-main
sudo /home/www/rateguru/bin/restore-test --target staging-main
sudo /home/www/rateguru/bin/offsite-backup --target staging-main
sudo /home/www/rateguru/bin/offsite-retention --target staging-main
sudo /home/www/rateguru/bin/offsite-retention --target staging-main --apply
sudo /home/www/rateguru/bin/offsite-restore-test --target staging-main
sudo /home/www/rateguru/bin/backup-cycle --target staging-main
```

This installer never touches the GitHub Actions deploy workflow, sudoers, or
any server wrapper — see [Not touched](#what-this-installer-owns--and-does-not)
above. Real staging traffic reaches these binaries only through the generic
`/usr/local/sbin/rateguru-deploy`/`rateguru-rollback`/`rateguru-cleanup`
wrappers and the backup cron entries, installed and managed by a separate
installer; see
[`target-perimeter.md`](target-perimeter.md) for that perimeter's own
contract.

## Troubleshooting

- **`--apply` refuses immediately with a destination-directory error**:
  `/home/www/rateguru/config` or `/home/www/rateguru/bin` is missing, isn't a
  real directory, isn't `root:root`, or is group- or other-writable. Fix the
  directory itself on the host — this installer will not create, `chown` or
  `chmod` either one for you.
- **`--apply` refuses immediately with a health check failure**: staging is
  already unhealthy before this install touched anything. Fix that first —
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
  modified or has drifted from its source; `--- staging-main health ---`
  points at the application itself, not this installer.
