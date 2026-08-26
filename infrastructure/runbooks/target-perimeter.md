# Target-aware perimeter

This runbook covers `infrastructure/scripts/install-target-perimeter`, the
three generic sudo wrappers it installs, the sudoers rule, the backup cron
entries, and the removal of the now-obsolete per-environment wrapper files
that predate them.

For what `--target` itself means, and for the operational scripts this
perimeter invokes (`deploy`, `rollback`, `cleanup`, `backup-cycle`,
`restore-test`, `offsite-restore-test`), see
[`deployment-targets.md`](deployment-targets.md#perimeter-wrappers-sudoers-cron-and-github-actions).
This document is only about the perimeter that calls them.

## Why the perimeter is its own installer

`deploy`, `rollback`, `cleanup`, `backup-cycle`, `restore-test` and
`offsite-restore-test` themselves are just scripts that accept `--target`.
What actually invokes them for real is a different concern: the sudo
wrappers, the sudoers rule, and the cron/CI configuration. A wrong sudoers
rule or wrapper bug is a real deployment or backup outage, which is a
different order of risk from a script accepting a flag — so the perimeter
gets its own installer, its own review, and its own transactional
install/verify/rollback contract, entirely separate from
[`install-target-operations`](install-target-operations.md).

## What this installs — and does not

Exactly five files:

| Source (this repo) | Destination | Owner:group | Mode |
|---|---|---|---|
| `infrastructure/config/wrappers/rateguru-deploy` | `/usr/local/sbin/rateguru-deploy` | `root:root` | `0755` |
| `infrastructure/config/wrappers/rateguru-rollback` | `/usr/local/sbin/rateguru-rollback` | `root:root` | `0755` |
| `infrastructure/config/wrappers/rateguru-cleanup` | `/usr/local/sbin/rateguru-cleanup` | `root:root` | `0755` |
| `infrastructure/config/sudoers/rateguru-deploy` | `/etc/sudoers.d/rateguru-deploy` | `root:root` | `0440` |
| `infrastructure/config/cron/rateguru-backups` | `/etc/cron.d/rateguru-backups` | `root:root` | `0644` |

`--apply` additionally **removes** six now-obsolete wrapper files at
`/usr/local/sbin` — one per operation (deploy, rollback, cleanup), for each
of the two per-environment identities the platform used to operate under
(staging, production) — that predate the three generic wrappers above and
are no longer referenced by anything. This removal is transactional, backed
up, and rolled back on failure exactly like every install step: see
[Legacy wrapper removal](#legacy-wrapper-removal) below.

**Not touched, by this or any other change here:**

- `deploy`, `rollback`, `cleanup`, `backup-cycle`, `restore-test`,
  `offsite-restore-test`, `common`, the target registry, or
  `deployment.conf` — all owned by `install-target-operations`, a
  **prerequisite** this installer depends on but never installs itself;
- `infrastructure/config/cron/rateguru-staging-scheduler` — the Laravel
  scheduler entry, unrelated to this perimeter;
- systemd timers, Nginx, PHP-FPM, Supervisor, Ansible, production
  provisioning, or `tits-guru`'s own (nonexistent) infrastructure.

### Why there is no sudoers rule for tits-guru

`tits-guru` is `lifecycle: planned` in the registry — declared, but not
provisioned: no directory, no database, no deploy user account, nothing.
Granting sudoers access for a deploy user that does not exist yet would be
meaningless at best. When `tits-guru` is actually provisioned and flipped to
`active`, its own sudoers rule is a reviewable one-line addition alongside
that provisioning work — not something this installer adds preemptively.

## The three generic wrappers

Each of `rateguru-deploy`, `rateguru-rollback`, `rateguru-cleanup`:

1. requires root (`require_root`, from `common`) — it is only ever reached
   through `sudo`, or invoked directly by real root for server
   administration;
2. accepts **exactly one** selector: `--target TARGET_ID`. A missing,
   duplicate, empty, or flag-shaped `--target` value is rejected. Every other
   long-form argument — `--release`, `--artifact`, `--checksum`, `--migrate`,
   `--keep`, `--dry-run`, `--apply`, `--previous`, or anything else the
   wrapper does not itself recognize — is collected untouched, in order, and
   passed straight through to the underlying operation, which handles its
   own unknown-argument rejection if the flag is invalid; a lone short flag
   other than `-h`/`--help` is rejected by the wrapper itself, since every
   real operation flag in this codebase is long-form;
3. authorizes the caller. `SUDO_USER` (set by `sudo` to the identity that
   invoked it) must exactly equal the target's own `deploy_user` from the
   registry (`target_deploy_user`), or the wrapper rejects the call with:

   ```text
   deploy user deploy-rateguru-staging is not authorized for target tits-guru
   ```

   An empty `SUDO_USER` (a real root shell calling the wrapper directly, no
   `sudo` involved) or `SUDO_USER=root` are both treated as server
   administration and always permitted;
4. calls `require_active_target TARGET_ID` — the identical lifecycle gate
   every other target-aware command already uses. A planned target
   (`tits-guru`) is rejected here, before the underlying binary is ever
   invoked;
5. execs into the real, unchanged binary at its generic installed path —
   `/home/www/rateguru/bin/deploy`, `.../rollback`, or `.../cleanup` — with
   `--target TARGET_ID` prepended exactly once, then every operation
   argument the caller gave, unmodified;
6. scrubs its own environment before that exec: `env -i` with only four
   variables — `PATH` (the same minimal production `PATH` the backup cron
   already uses), `HOME=/root`, `USER=root`, `LOGNAME=root`. Every
   `RATEGURU_*` test override, and any other variable a caller's shell might
   have set, is stripped unconditionally — there is no list of variable
   names to remember to unset, because nothing survives `env -i` except what
   is explicitly listed after it;
7. uses only Bash arrays and `exec` throughout — never `eval`, never
   `bash -c`, never a string-built command line.

`--help` prints the wrapper's own target-only usage form and exits
immediately, before any selector, authorization, or lifecycle work runs —
it never reaches the underlying operation.

### Test overrides

Gated behind `RATEGURU_ALLOW_TEST_OVERRIDES=true`, exactly like every other
operational script: `RATEGURU_COMMON_FILE` (which `common` to source) and
`RATEGURU_DEPLOY_BIN`/`RATEGURU_ROLLBACK_BIN`/`RATEGURU_CLEANUP_BIN` (which
binary to exec into). Without the allow flag, every override is ignored and
the wrapper falls back to its real, hardcoded production paths — even if a
`RATEGURU_*` variable happens to be present in the calling environment.

## Sudoers

```sudoers
deploy-rateguru-staging ALL=(root) NOPASSWD: \
    /usr/local/sbin/rateguru-deploy, \
    /usr/local/sbin/rateguru-rollback, \
    /usr/local/sbin/rateguru-cleanup
```

This is the only grant in the file: the staging deploy user's access to the
three generic wrappers, and nothing else. No rule for `tits-guru`'s
(unprovisioned) deploy user, and no rule for any per-environment identity —
those grants existed only temporarily, alongside the six obsolete wrapper
files, and were removed together with them.

## GitHub Actions

`.github/actions/deploy-rateguru/action.yml` has a required
`deployment-target` input, validated locally — before any SSH connection —
against `^[a-z0-9]+(-[a-z0-9]+)*$`. That single regex rejects empty,
uppercase, a slash, whitespace, shell metacharacters, and a flag-shaped
value in one closed character class. The remote command is built entirely
through `printf -v ... %q`:

```bash
sudo -n "${DEPLOY_WRAPPER}" \
  --target "${DEPLOYMENT_TARGET}" \
  --release "${RELEASE_ID}" \
  --artifact "${remote_artifact}" \
  --checksum "${remote_checksum}" \
  [--migrate]
```

never string concatenation of an unquoted target. The workflow log prints
`Deployment target: staging-main` — never the SSH key or `known_hosts`.

`.github/workflows/deploy-staging.yml` passes `deployment-target:
staging-main` explicitly, and the `DEPLOY_WRAPPER` GitHub variable points at
`/usr/local/sbin/rateguru-deploy`. The workflow otherwise stays exactly what
it was: a staging-specific workflow with `environment: staging` (the GitHub
Environment used for approval and secrets — an entirely different concept
from a *deployment target*, and not affected by this perimeter) and the
`rateguru-staging-deployment` concurrency group, both unchanged.

## Backup cron

`infrastructure/config/cron/rateguru-backups` keeps its exact schedule and
log paths:

```cron
30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
```

`infrastructure/config/cron/rateguru-staging-scheduler` (the Laravel
scheduler) is untouched — it is unrelated to this perimeter.

## Installer

```bash
infrastructure/scripts/install-target-perimeter --check
infrastructure/scripts/install-target-perimeter --apply
infrastructure/scripts/install-target-perimeter --verify
```

Same contract as `install-target-operations`, scoped to five installed
files (plus the six-file legacy removal) instead of sixteen.

### `--check` — read-only

Validates: the five source files exist and are regular files; the three
wrapper scripts and the installer itself are executable and pass `bash -n`;
`shellcheck`, when available on the host (never required — its absence
never blocks `--check`); that `deploy`, `rollback`, `cleanup`,
`backup-cycle`, `restore-test` and `offsite-restore-test` still declare
`--target` support in their own committed source; the committed registry
validates and lists `staging-main` active/staging and `tits-guru`
planned/production; the candidate sudoers file passes `visudo -cf` and
grants staging (never `tits-guru`, never any other identity) access to only
the three generic wrappers; the candidate cron file has exactly three
operational lines, all using `--target staging-main`, with schedule and log
paths unchanged; and — see
[Installed operations bundle staleness guard](#installed-operations-bundle-staleness-guard)
below — that the real, installed sixteen-file target operations bundle at
`/home/www/rateguru` is present, correctly owned and moded, and
byte-identical to this repository's own committed sources. `--check` also
reports, for each of the six legacy wrapper paths, whether it is currently
present (and so would be removed by `--apply`) or already absent — purely
informational; neither state is a `--check` failure. Makes no changes
anywhere.

### Installed operations bundle staleness guard

`install-target-perimeter` depends on `install-target-operations` having
already installed a fully current sixteen-file bundle — the registry,
`deployment.conf`, and `targets`/`common`/`health-check`/`status`/`cleanup`/
`deploy`/`rollback`/`backup`/`restore-test`/`offsite-backup`/
`offsite-retention`/`offsite-restore-test`/`backup-cycle`/
`verify-required-clis` — at
`/home/www/rateguru`. This installer never installs, modifies, or takes
ownership of any of those sixteen files; it only ever verifies them, for
`--check`, `--apply`'s own preflight, and `--verify` alike, before a staging
directory, a backup directory, or a single perimeter destination file is
ever touched.

For each of the sixteen files, the check confirms: it exists; it is a
regular file, never a symlink; its owner/mode match what
`install-target-operations` installs (registry and `deployment.conf` both
`root:root 0640`, `common` `root:root 0644`, every other file `root:root
0755`); and its content is byte-identical to this repository's own
committed source. Any single failure — missing, symlinked, wrong mode,
wrong ownership, or content drift — aborts with:

```text
installed target operations are stale or incomplete; run install-target-operations --apply first
```

This is exactly the situation an installed `backup-cycle` that predates its
own `--target` support would produce: it would answer an unrecognized-flag
error, even though the committed source in this repository already accepts
it. Installing the perimeter (wrappers that exec into these binaries) on top
of a bundle like that would silently wire the new perimeter to broken
operational scripts — this guard is what prevents that.

### `--apply` — requires root, transactional

1. Everything `--check` does.
2. The three destination directories (`/usr/local/sbin`,
   `/etc/sudoers.d`, `/etc/cron.d`) are validated — must already exist, be
   real directories, `root:root`-owned, not group- or other-writable. This
   installer never creates, `chown`s, or `chmod`s any of them.
3. The five source files are copied into a private, root-only staging
   directory, then verified together there: `bash -n` on the staged
   wrappers, each staged wrapper's `--help` and a bare
   `--target tits-guru` probe (proving the planned-target rejection — see
   [Safe probes, never a real operation](#safe-probes-never-a-real-operation)
   below), `visudo -cf` on the staged sudoers file, and the cron format
   check on the staged cron file.
4. A timestamped backup directory is created, and each destination is
   installed in order — the three wrappers, then the sudoers file (only
   after its own fresh `visudo -cf` pass, immediately before install), then
   cron — via stage-in-place-then-atomic-rename, never a direct overwrite.
   An existing destination that is not absent and not a plain regular file
   (a symlink, directory, FIFO, socket, device) is refused outright and
   never backed up.
5. The six legacy wrapper paths are removed — see
   [Legacy wrapper removal](#legacy-wrapper-removal) below.
6. The installed result is verified: exact ownership, exact mode,
   byte-for-byte content match, `bash -n`, that each installed wrapper
   references its generic installed operation path and contains no mention
   of the retired selector at all, that no wrapper contains `eval` or
   `bash -c`, that the installed sudoers passes `visudo -cf` and its content
   check, that the installed cron passes the same format check, and that all
   six legacy wrapper paths are now absent.
7. Runtime parity is verified — the same safe wrapper probes as step 3, now
   against the installed binaries.
8. Only once every check above passes is the change committed. Any failure
   before that point rolls back every destination this run touched,
   including any legacy wrapper already removed — see
   [Legacy wrapper removal](#legacy-wrapper-removal) below.

### Legacy wrapper removal

`--apply` backs up (using the identical backup mechanism every installed
destination above already uses) and removes each of the six legacy wrapper
paths that currently exists, one per operation for each of the two
per-environment identities the platform used to run under. A path that is
already absent before the run is simply recorded as "did not exist" — no
error, and nothing to back up.

This is fully transactional, sharing the same rollback path as the five
installed files: if anything fails later in the same `--apply` run — the
five-file installation, the post-install verification, or runtime parity —
every legacy wrapper this run removed is restored from its backup with its
original owner, group, mode and content, and a legacy wrapper that was
already absent before the run stays absent afterward. `--verify` — which
only ever runs after a successful `--apply` — fails closed if any of the six
paths still exists.

### Wrapper static contract (shared by source, staged, and installed)

Every one of the three wrappers is checked, by one shared function
(`verify_wrapper_static_contract`), for: a reference to its generic
installed operation path; the complete absence of any mention of the
retired per-environment flag anywhere in the wrapper's source — there is no
dedicated rejection branch for it to check instead, because the flag no
longer exists as a concept the wrapper needs to special-case, so an
unrecognized instance of it now simply falls through to the wrapper's own
generic unknown-argument handling like any other invalid flag; and no
*executable* `eval` or `bash -c` anywhere. The same function runs against
the source files (`--check`), the staged candidates (`--apply`'s own
preflight, before a backup directory or any destination file exists), and
the installed files (`--apply`'s post-install verification and `--verify`)
— so a defect in this check is caught at the earliest of those three
points, never only after destination files have already changed.

**Fixed defect, found on a real VPS bootstrap attempt:** an earlier version
of this check used a single whole-file `grep -Eq '...|bash -c'`, which does
not distinguish code from comments. Every wrapper's own doc comment reads,
verbatim, "no eval, no bash -c, no string-built command" — and that comment
text alone was enough to trip the check:

```text
ERROR: installed wrapper contains eval or bash -c: /usr/local/sbin/rateguru-deploy
apply failed (exit 1)
rollback complete: previous files restored
```

No real `eval` or `bash -c` was ever present; `--apply` correctly rolled
back and left the host in its prior working state, but the wrapper could
never actually install. Fixed by excluding comment-only lines (any line
that, after leading whitespace, starts with `#`) before scanning for
executable `eval`/`bash -c`, and by making that fixed check one function
shared by all three validation points instead of duplicated logic that
could drift.

### `--verify` — requires root, read-only

Re-runs the installed-file and runtime-parity checks against whatever is
currently installed, including confirming that all six legacy wrapper paths
remain absent. Makes no changes and creates no backup.

### Safe probes, never a real operation

This installer never runs a real deploy, rollback, cleanup apply,
backup-cycle, restore, or offsite operation. Its only two probes against
each wrapper are:

- `--help`, which exits before any selector or lifecycle work;
- a bare `--target tits-guru`, with no operation arguments at all, which
  the wrapper's own `require_active_target` rejects with
  `lifecycle=planned` before the underlying binary is ever reached.

`--target tits-guru --help` is deliberately **not** used for the second
probe: `--help` is intercepted in argument parsing before authorization or
lifecycle checks ever run, so it would prove nothing about rejection — the
bare `--target tits-guru` probe above is what actually exercises that path
safely.

Both probes run with `RATEGURU_COMMON_FILE`/`RATEGURU_TARGET_REGISTRY_FILE`/
`RATEGURU_TARGETS_CLI` pointed at this repository's own committed copies —
never at whatever may or may not already be installed at
`/home/www/rateguru` — so this installer's own checks are self-contained and
never depend on `install-target-operations` having run first on the exact
host being checked. `SUDO_USER` is explicitly cleared before every probe, so
these checks are never accidentally rejected on caller identity grounds
just because the installer itself happened to be invoked through `sudo`.

### Test overrides

Gated behind `RATEGURU_ALLOW_TEST_OVERRIDES=true`: `RATEGURU_PERIMETER_ROOT`
(prefixes all five destination paths, the six legacy wrapper paths, and the
backup root — the one deliberate seam that lets this installer's
transactional core, including legacy wrapper removal, be exercised end to
end against a private scratch tree), `RATEGURU_VISUDO_BIN` (which `visudo`
binary to use), and `RATEGURU_INSTALLED_OPERATIONS_ROOT` (prefixes the
sixteen-file operations bundle path this installer only ever verifies — a
second, independently gated seam, never conflated with
`RATEGURU_PERIMETER_ROOT`, since this bundle is a read-only dependency, not
something this installer owns or installs). Without the allow flag, all
three are ignored.

## History: the DEPLOY_WRAPPER cutover

The generic wrappers, the sudoers rule, and the target-based cron and GitHub
Actions configuration described above replaced an earlier arrangement where
every real staging operation went through a per-environment sudo wrapper
instead. Switching the live `DEPLOY_WRAPPER` GitHub variable to point at the
generic wrapper had to happen on the VPS, by hand, before the change that
introduced the `deployment-target` GitHub Actions input could be merged —
because the staging deploy job's "Checkout deployment action" step always
checks out the current `develop` action regardless of which ref is being
deployed, so merging first would have sent every subsequent deployment a
selector the still-installed wrapper didn't yet understand, with no way to
fix it remotely. That cutover has since completed and was accepted on the
real staging VPS; see `infrastructure/ROADMAP.md` (Phase 4) for the full,
step-by-step account.

The complete Phase 4 target-only cutover was subsequently accepted on the
real staging VPS: the fifteen-file operations bundle and the perimeter both
passed check/apply/verify; all six legacy wrappers were absent; target-only
rollback and the full backup-cycle completed successfully; and the final
staging health check passed. See `infrastructure/ROADMAP.md` (Phase 4) for
the acceptance record.

## Troubleshooting

- **`--apply` refuses immediately with a destination-directory error**:
  `/usr/local/sbin`, `/etc/sudoers.d`, or `/etc/cron.d` is missing, isn't a
  real directory, isn't `root:root`, or is group- or other-writable. Fix
  the directory itself — this installer will not create, `chown`, or
  `chmod` any of them.
- **`--apply` fails during staged candidate verification**: the candidate
  files in this repository don't work together correctly. Nothing on the
  host was touched.
- **`--apply` fails after installation and rolls back**: check the failed
  step in the log. The previous files — and any legacy wrapper this run
  removed — are already restored; re-run `--check` before trying `--apply`
  again.
- **A deployment through the wrapper fails with "deploy user ... is not
  authorized for target ..."**: the sudoers rule's `deploy-rateguru-staging`
  does not match `SUDO_USER` for this call, or the target's registered
  `deploy_user` in `infrastructure/config/deployment-targets.json` is wrong.
  Fix whichever is actually incorrect — never widen the wrapper's own
  authorization check to work around it.
- **`--verify` fails on an already-installed host**: a file was modified,
  had its ownership/mode changed, was replaced by a symlink outside this
  installer, or a legacy wrapper this installer already removed has
  reappeared. Re-run `--apply` to restore the committed state.
