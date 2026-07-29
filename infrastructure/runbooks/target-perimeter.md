# Target-aware perimeter

Phase 4 slice 8. This runbook covers
`infrastructure/scripts/install-target-perimeter`, the three generic sudo
wrappers it installs, the sudoers rule, and the GitHub Actions / cron
switch to `--target staging-main`.

For what `--target` itself means, and for the operational scripts this
perimeter invokes (`deploy`, `rollback`, `cleanup`, `backup-cycle`,
`restore-test`, `offsite-restore-test` — all unchanged by this slice), see
[`deployment-targets.md`](deployment-targets.md#target-aware-perimeter-slice-8-current).
This document is only about the perimeter that calls them.

> **Do not merge this slice before completing its
> [pre-merge bootstrap sequence](#pre-merge-bootstrap-sequence--do-not-merge-before-completing-this):
> deploy the feature-branch artifact, install and verify the target
> perimeter on the VPS, and switch `DEPLOY_WRAPPER` to the generic
> wrapper.** Merging first checks out the new deployment action onto
> `develop` before the VPS is ready for it, deadlocking every subsequent
> deployment.

## Why a perimeter slice, separate from the scripts themselves

Every mutating operational script already accepted `--target` before this
slice — `deploy` since slice 5, `rollback` since slice 6, `cleanup` since
slice 4, `backup`/`restore-test` since slice 7.1,
`offsite-backup`/`offsite-retention`/`offsite-restore-test` since slice 7.2,
`backup-cycle` since slice 7.3. But every *real* invocation still went
through `--environment staging`: GitHub Actions called a per-environment
sudo wrapper, and cron called `backup-cycle --environment staging`. Adding
`--target` support to a script was safe to merge on its own because nothing
real called it yet. Switching the actual callers is a different, riskier
change — a wrong sudoers rule or wrapper bug is a real deployment or backup
outage — so it gets its own slice, its own installer, and its own review.

## What this installs — and does not

Exactly five files:

| Source (this repo) | Destination | Owner:group | Mode |
|---|---|---|---|
| `infrastructure/config/wrappers/rateguru-deploy` | `/usr/local/sbin/rateguru-deploy` | `root:root` | `0755` |
| `infrastructure/config/wrappers/rateguru-rollback` | `/usr/local/sbin/rateguru-rollback` | `root:root` | `0755` |
| `infrastructure/config/wrappers/rateguru-cleanup` | `/usr/local/sbin/rateguru-cleanup` | `root:root` | `0755` |
| `infrastructure/config/sudoers/rateguru-deploy` | `/etc/sudoers.d/rateguru-deploy` | `root:root` | `0440` |
| `infrastructure/config/cron/rateguru-backups` | `/etc/cron.d/rateguru-backups` | `root:root` | `0644` |

**Not touched, by this or any other change here:**

- `deploy`, `rollback`, `cleanup`, `backup-cycle`, `restore-test`,
  `offsite-restore-test`, `common`, the target registry, or
  `deployment.conf` — all owned by `install-target-operations`, a
  **prerequisite** this installer depends on but never installs itself;
- `infrastructure/config/cron/rateguru-staging-scheduler` — the Laravel
  scheduler entry, unrelated to this migration;
- systemd timers, Nginx, PHP-FPM, Supervisor, Ansible, production
  provisioning, or `tits-guru`'s own (nonexistent) infrastructure;
- the temporary legacy per-environment wrappers
  (`rateguru-staging-{deploy,rollback,cleanup}`,
  `rateguru-production-{deploy,rollback,cleanup}`) or their sudoers rules —
  left installed, for rollback safety, until a dedicated future
  legacy-removal slice deletes them from the server and from
  `infrastructure/config/sudoers/rateguru-deploy`.

### Why there is no sudoers rule for tits-guru

`tits-guru` is `lifecycle: planned` in the registry — declared, but not
provisioned: no directory, no database, no deploy user account, nothing.
Granting sudoers access for a deploy user that does not exist yet would be
meaningless at best. When `tits-guru` is actually provisioned and flipped to
`active`, its own sudoers rule is a reviewable one-line addition alongside
that provisioning work — not something this installer, or any earlier
slice, adds preemptively.

## The three generic wrappers

Each of `rateguru-deploy`, `rateguru-rollback`, `rateguru-cleanup` replaces
a pair of legacy, per-environment wrappers
(`rateguru-staging-X`/`rateguru-production-X`) with a single wrapper that:

1. requires root (`require_root`, from `common`) — it is only ever reached
   through `sudo`, or invoked directly by real root for server
   administration;
2. accepts **exactly one** selector: `--target TARGET_ID`. `--environment`
   is explicitly rejected with a message naming the wrapper. A missing,
   duplicate, empty, or flag-shaped `--target` value is rejected. Every
   other argument — `--release`, `--artifact`, `--checksum`, `--migrate`,
   `--keep`, `--dry-run`, `--apply`, `--previous` — is collected untouched,
   in order, and passed straight through: the wrapper never interprets an
   operation-specific flag itself;
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

The temporary legacy rules for `rateguru-staging-*` and
`rateguru-production-*` remain in the same file, clearly marked as
deprecated compatibility only — GitHub Actions no longer calls any of them,
and no new caller should be added.

## GitHub Actions

`.github/actions/deploy-rateguru/action.yml` gained a required
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
staging-main` explicitly. It otherwise stays exactly what it was: a
staging-specific workflow with `environment: staging` (the GitHub
Environment used for approval and secrets — an entirely different concept
from a *deployment target*, and not renamed by this slice) and the
`rateguru-staging-deployment` concurrency group, both unchanged.

### DEPLOY_WRAPPER must be switched by hand, before merge

The live GitHub Actions variable `DEPLOY_WRAPPER` is never switched
automatically — an operator must update it by hand, and must do so
**before** this slice is merged into `develop`, not after. See
[Pre-merge bootstrap sequence](#pre-merge-bootstrap-sequence--do-not-merge-before-completing-this)
below for why merging first creates an unrecoverable deadlock.

## Backup cron

`infrastructure/config/cron/rateguru-backups` keeps its exact schedule and
log paths — only the selector changed:

```cron
30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
```

`infrastructure/config/cron/rateguru-staging-scheduler` (the Laravel
scheduler) is untouched — it never used `--environment`/`--target` and is
unrelated to this migration.

## Installer

```bash
infrastructure/scripts/install-target-perimeter --check
infrastructure/scripts/install-target-perimeter --apply
infrastructure/scripts/install-target-perimeter --verify
```

Same contract as `install-target-operations`, scoped to five files instead
of fourteen:

### `--check` — read-only

Validates: the five source files exist and are regular files; the three
wrapper scripts and the installer itself are executable and pass `bash -n`;
`shellcheck`, when available on the host (never required — its absence
never blocks `--check`); that `deploy`, `rollback`, `cleanup`,
`backup-cycle`, `restore-test` and `offsite-restore-test` still declare
`--target` support in their own committed source; the committed registry
validates and lists `staging-main` active/staging and `tits-guru`
planned/production; the candidate sudoers file passes `visudo -cf` and
grants staging (never `tits-guru`) access to the three generic wrappers;
the candidate cron file has exactly three operational lines, all using
`--target staging-main`, none using `--environment`, with schedule and log
paths unchanged; and — see
[Installed operations bundle staleness guard](#installed-operations-bundle-staleness-guard)
below — that the real, installed fourteen-file target operations bundle at
`/home/www/rateguru` is present, correctly owned and moded, and
byte-identical to this repository's own committed sources. Makes no changes
anywhere.

### Installed operations bundle staleness guard

`install-target-perimeter` depends on `install-target-operations` having
already installed a fully current fourteen-file bundle — the registry and
`targets`/`common`/`health-check`/`status`/`cleanup`/`deploy`/`rollback`/
`backup`/`restore-test`/`offsite-backup`/`offsite-retention`/
`offsite-restore-test`/`backup-cycle` — at `/home/www/rateguru`. This
installer never installs, modifies, or takes ownership of any of those
fourteen files; it only ever verifies them, for `--check`, `--apply`'s own
preflight, and `--verify` alike, before a staging directory, a backup
directory, or a single perimeter destination file is ever touched.

For each of the fourteen files, the check confirms: it exists; it is a
regular file, never a symlink; its owner/mode match what
`install-target-operations` installs (registry `root:root 0640`, `common`
`root:root 0644`, every other file `root:root 0755`); and its content is
byte-identical to this repository's own committed source. Any single
failure — missing, symlinked, wrong mode, wrong ownership, or content
drift — aborts with:

```text
installed target operations are stale or incomplete; run install-target-operations --apply first
```

This is exactly the situation an installed `backup-cycle` from before Phase
4 slice 7.3 would produce: it predates `--target` support and answers
`Unknown argument: --target`, even though the committed source in this
repository is already target-aware. Installing the perimeter (wrappers that
exec into these binaries) on top of a bundle like that would silently wire
the new perimeter to broken operational scripts — this guard is what
prevents that.

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
   cron last — via stage-in-place-then-atomic-rename, never a direct
   overwrite. An existing destination that is not absent and not a plain
   regular file (a symlink, directory, FIFO, socket, device) is refused
   outright and never backed up.
5. The installed result is verified: exact ownership, exact mode,
   byte-for-byte content match, `bash -n`, that each installed wrapper
   references its generic installed operation path and never
   `--environment`, that no wrapper contains `eval` or `bash -c`, that the
   installed sudoers passes `visudo -cf` and its content check, and that
   the installed cron passes the same format check.
6. Runtime parity is verified — the same safe wrapper probes as step 3, now
   against the installed binaries.
7. Only once every check above passes is the change committed. Any failure
   before that point rolls back every destination this run touched — a
   destination that existed before is restored from its backup; one that
   did not exist is removed.

### `--verify` — requires root, read-only

Re-runs the installed-file and runtime-parity checks against whatever is
currently installed. Makes no changes and creates no backup.

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
(prefixes all five destination paths and the backup root — the one
deliberate seam that lets this installer's transactional core be exercised
end to end against a private scratch tree), `RATEGURU_VISUDO_BIN` (which
`visudo` binary to use), and `RATEGURU_INSTALLED_OPERATIONS_ROOT` (prefixes
the fourteen-file operations bundle path this installer only ever verifies
— a second, independently gated seam, never conflated with
`RATEGURU_PERIMETER_ROOT`, since this bundle is a read-only dependency, not
something this installer owns or installs). Without the allow flag, all
three are ignored.

## Pre-merge bootstrap sequence — do not merge before completing this

**Merging this change before bootstrapping the VPS creates a deadlock that
cannot be recovered from remotely.** The staging deploy job's own "Checkout
deployment action" step always checks out `.github/actions/deploy-rateguru`
from `develop` — regardless of what ref is being *built and deployed* as
the artifact. Once `develop` has the new action (which requires
`deployment-target` and builds `--target ...` into the remote command),
*every* subsequent deployment sends `--target` to whatever `DEPLOY_WRAPPER`
currently points at. If that variable still names the legacy per-environment
wrapper — which does not understand `--target` at all — the deployment
fails immediately. And the only way to switch `DEPLOY_WRAPPER` to the new
generic wrapper is to run `install-target-perimeter --apply` on the VPS,
which requires `infrastructure/scripts/install-target-perimeter` to already
exist in a *deployed* release — which requires a successful deployment
first. Merging before that chain is complete locks out every future
deployment until someone fixes it by hand, out of band, on the VPS.

> **Do not merge before the feature-branch artifact has been deployed, the
> target perimeter installed and verified, and `DEPLOY_WRAPPER` switched to
> the generic wrapper.**

The sequence below breaks the deadlock by deploying this feature branch's
own artifact while `develop` — and therefore the deploy job's own checked-out
action — is still the *legacy* action, one last time, before merge:

1. Before merging, run the existing `Deploy to staging` GitHub Actions
   workflow (`workflow_dispatch`) with:

   ```text
   ref=infra/target-aware-perimeter
   run-migrations=false
   ```

2. Because the deploy job's action is checked out from the *current*
   `develop` — still the legacy action at this point, with no
   `deployment-target` input and no `--target` in its remote command — this
   feature-branch artifact deploys through the still-legacy wrapper exactly
   like any other deployment does today.
3. On the VPS, from the just-deployed feature-branch release, run:

   ```bash
   infrastructure/scripts/install-target-perimeter --check
   infrastructure/scripts/install-target-perimeter --apply
   infrastructure/scripts/install-target-perimeter --verify
   ```

4. Update the GitHub variable:

   ```text
   DEPLOY_WRAPPER=/usr/local/sbin/rateguru-deploy
   ```

5. **Only now** may this change be merged into `develop`.
6. After merging, run the normal deployment:

   ```text
   ref=develop
   run-migrations=false
   ```
7. Verify deploy history and the post-deploy health check.
8. Inspect the installed `/etc/cron.d/rateguru-backups` on the VPS.
9. Confirm no active perimeter command (deploy, cron) still uses
   `--environment`.

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
  step in the log. The previous files are already restored; re-run
  `--check` before trying `--apply` again.
- **A deployment through the new wrapper fails with "deploy user ... is not
  authorized for target ..."**: the sudoers rule's `deploy-rateguru-staging`
  does not match `SUDO_USER` for this call, or the target's registered
  `deploy_user` in `infrastructure/config/deployment-targets.json` is wrong.
  Fix whichever is actually incorrect — never widen the wrapper's own
  authorization check to work around it.
- **`--verify` fails on an already-installed host**: a file was modified,
  had its ownership/mode changed, or was replaced by a symlink outside this
  installer. Re-run `--apply` to restore the committed state.
