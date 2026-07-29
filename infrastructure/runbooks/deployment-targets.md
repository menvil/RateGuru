# Deployment targets

The deployment target registry is the source of truth for **which application
instances exist** and **what belongs to each of them**.

This document describes the registry itself. It does **not** describe how to
deploy: no operational script consumes the registry yet. See
[Migration sequence](#migration-sequence).

## Target versus environment class

Today infrastructure identifies a deployment by one flag:

```bash
--environment staging|production
```

That single word carries two unrelated meanings at once:

1. **which instance** to act on — its root, users, database, backup path;
2. **what kind** of instance it is — how strict retention is, whether debug is
   allowed, how cautious tooling should be.

Those are different things, and conflating them caps the platform at exactly one
production instance. A second production brand on the same codebase has no way
to exist: `production` is already taken as an identity.

The registry separates them.

- **Target ID** — the identity of one independently deployable application
  instance. Stable, unique, and used to look up every concrete value.
  Examples: `staging-main`, `tits-guru`.
- **Environment class** — metadata *about* a target: `staging` or `production`.
  Many targets may share a class. It never identifies anything.

`staging-main` rather than `staging` is deliberate: the name must not read as a
class, because one day there may be a second staging instance.

## Why staging-main and tits-guru are separate targets

They share the RateGuru codebase and nothing else. Each has its own application
root, Linux users, database and role, PHP-FPM pool and socket, queue worker and
queue name, scheduler entry, Nginx site, public hostnames, and backup namespace
with its own retention.

Modelling them as one "production" slot with swapped values would mean every
operation implicitly acts on whichever set of values happens to be configured —
exactly the ambiguity that makes a second brand impossible. Separate targets
make the instance an explicit argument.

## Registry location

| Role | Path |
|------|------|
| Source of truth (committed) | `infrastructure/config/deployment-targets.json` |
| Runtime destination (not yet installed) | `/home/www/rateguru/config/deployment-targets.json` |

The runtime path is **documented, not created**. This slice installs nothing on
the VPS.

Both consumers honour the same sources in the same order. They differ only in
*how* they reach `TARGET_REGISTRY_FILE`, and the CLI adds `--file` on top.

`common` — sources `deployment.conf`, so the setting is already a shell
variable by the time a helper runs:

1. `RATEGURU_TARGET_REGISTRY_FILE` — tests and controlled tooling;
2. `TARGET_REGISTRY_FILE`, loaded from `deployment.conf`;
3. `/home/www/rateguru/config/deployment-targets.json`.

`scripts/targets` — does not source `common` or `deployment.conf`, because it
must run in the repository during CI where no `deployment.conf` exists. It
still honours `TARGET_REGISTRY_FILE`, reading it from the file when it is not
already in the environment:

1. `--file PATH`, when given — an explicit empty value is rejected, never
   silently replaced by a fallback;
2. `RATEGURU_TARGET_REGISTRY_FILE`;
3. `TARGET_REGISTRY_FILE` exported in the environment;
4. `TARGET_REGISTRY_FILE` parsed out of `deployment.conf`;
5. `/home/www/rateguru/config/deployment-targets.json`.

Levels 3 and 4 exist together because `deployment.conf` assigns **without**
`export`. A parent shell that sourced it does not pass the value to a child
process, so relying on the environment alone would leave the CLI reading a
different registry than `common` — on the same host, at the same moment.

The CLI **never sources or evaluates `deployment.conf`.** It reads a single
assignment with `sed`, strips one layer of surrounding quotes, and rejects any
value still containing shell metacharacters (`$`, backtick, `;`, `&`, `|`, `<`,
`>`, parentheses). The file is only consulted at all when it passes the same
safety checks `common` applies to it: a regular file, not a symlink, and — at
the installed path — owned `root:root` and not group- or other-writable.
Anything that fails those checks is ignored and resolution falls through to the
default path, rather than being trusted.

### Registry versus deployment.conf

`deployment.conf` keeps settings that are **global to the host** and are not a
property of any single target:

- `RELEASE_ID_REGEX`
- `PHP_BIN`
- `PHP_FPM_SERVICE`

Everything that differs *per instance* belongs in the registry. The two files
are also different kinds of thing: `deployment.conf` is shell that gets sourced;
the registry is data that is only ever parsed.

### Why JSON, and why it is never executed

The registry is JSON parsed with `jq`. It is never `source`d, never `eval`ed,
never expanded through command substitution, and never concatenated into a `jq`
program. Target IDs are passed with `--arg`.

This matters because the registry names Linux users, database roles and
filesystem paths. Were it shell, every one of those values would be a code
execution path, and a single stray backtick in a config file would be a host
compromise. As data, the worst a malformed value can do is fail validation.

YAML was rejected for a narrower reason: it would add `yq` as a runtime
dependency on every deployment host, and `jq` is already required.

## Lifecycle

| Lifecycle | Meaning |
|-----------|---------|
| `active` | Real, provisioned, currently deployable. |
| `planned` | Declared and validated, but nothing is provisioned. Not deployable. |
| `disabled` | Previously real, deliberately taken out of service. Not deployable. |

Validation currently permits `active` only for `staging-main`. Every other
target must stay `planned` or `disabled` until its own migration slice lands and
its infrastructure genuinely exists.

### Why a planned target cannot be deployed

`tits-guru` is a complete, valid declaration of a target that **does not exist
yet**. Its directories, users, database, socket, queue worker, cron entry, Nginx
site and TLS certificate have not been created.

The declaration is intentionally written before provisioning so the plan is
reviewable and collision-checked in advance. But a description is not an
instance, and treating one as deployable would run a deploy against a root that
does not exist, as a user that does not exist.

Two independent things prevent that:

- the `active`-allowlist above keeps `tits-guru` at `planned` in validation;
- no operational script reads the registry at all in this slice.

When `tits-guru` is genuinely provisioned, flipping it to `active` is a
reviewable one-line change with the allowlist updated alongside it.

## What is non-secret

Everything in the registry is non-secret and safe to commit: identifiers,
filesystem paths, Linux account and group names, database *names and role
names*, hostnames, retention counts, and service unit names.

Validation actively rejects property names suggesting otherwise —
`password`, `secret`, `token`, `private_key`, `credential`, `dsn`, `api_key`,
`access_key` — recursively, at any depth.

### Where secrets stay

Secrets do not move into the registry and are not affected by this work:

| Secret | Location |
|--------|----------|
| Database password, `APP_KEY`, SMTP credentials, Sentry DSN | per-target `.env`, deployed out of band, never committed |
| SSH deploy keys | `~/.ssh/authorized_keys` of each deploy user |
| B2 / rclone credentials | `/root/.config/rclone/rclone.conf` |
| Basic Auth passwords | `/etc/nginx/*.htpasswd` |

The registry names *which* database a target uses. It never says how to
authenticate to it.

## Validation

```bash
infrastructure/scripts/targets validate --file infrastructure/config/deployment-targets.json
infrastructure/scripts/targets list     --file infrastructure/config/deployment-targets.json
infrastructure/scripts/targets show --target staging-main --file infrastructure/config/deployment-targets.json
```

`validate` reports every problem it finds, not just the first, and exits
non-zero if any remain.

Beyond per-field rules, validation rejects collisions across targets on every
value where sharing would be actively unsafe:

- `application_root`, `incoming_artifacts`;
- `runtime_user`, `runtime_group`, `deploy_user`, `code_group`;
- `database.name`, `database.application_role`;
- `health.host_header`;
- `backup.namespace`;
- `php_fpm.pool`, `php_fpm.socket`;
- `supervisor.program`, `supervisor.queue`;
- `scheduler.name`;
- `nginx.site_name`, `nginx.internal_hostname`;
- `public_hostnames`, compared element-wise across all targets rather than
  whole-array, so a single overlapping hostname is caught.

Two targets sharing a socket or a queue name would silently cross-serve traffic
and jobs; sharing an incoming directory would let one target's artifact be
deployed to the other; sharing a public hostname would collide in Nginx and in
certificate issuance.

### Verifying runtime parity

Repository tests prove the registry agrees with the **committed** configuration
in this repository. They cannot prove it agrees with the **running VPS** —
nothing in CI can reach that host.

The two do drift. `STAGING_CODE_GROUP` in `deployment.conf.example` read
`rateguru-staging` while the installed `/home/www/rateguru/config/
deployment.conf` said `rateguru-staging-code`, and release files were group
owned by the latter. Repository parity was green throughout, because both sides
of the comparison were wrong together.

Note that `rateguru-staging` and `rateguru-staging-code` are two distinct
groups with distinct jobs: the first is the runtime user's own group, the
second is the shared group that owns release files and includes the deploy user
and `www-data`. Do not collapse them.

Every target follows the same four-account contract, and the registry records
each part separately so the two group roles can never be conflated:

| Field | Role | staging-main | tits-guru |
|-------|------|--------------|-----------|
| `runtime_user` | runs PHP-FPM and the queue worker | `rateguru-staging` | `rateguru-tits-guru` |
| `runtime_group` | that user's own group | `rateguru-staging` | `rateguru-tits-guru` |
| `deploy_user` | owns release files, receives artifacts | `deploy-rateguru-staging` | `deploy-rateguru-tits-guru` |
| `code_group` | shared group owning release files | `rateguru-staging-code` | `rateguru-tits-guru-code` |

`runtime_group` matching `runtime_user` is the normal Linux convention for a
user's primary group. `code_group` must always be a distinct `-code` group:
making it the runtime user's own group would give the runtime user ownership of
its own code, which is exactly the separation the staging model exists to keep.

Re-run this on the VPS before a target is used for a real deployment, and after
any change to users or groups:

```bash
echo "Installed deployment.conf:"
grep -E '^STAGING_(RUNTIME_USER|CODE_GROUP|DEPLOY_USER)=' \
    /home/www/rateguru/config/deployment.conf

echo
echo "Current release ownership:"
stat -Lc '%U:%G %a %n' \
    /home/www/rateguru/staging/current \
    /home/www/rateguru/staging/current/artisan

echo
echo "Groups:"
getent group rateguru-staging
getent group rateguru-staging-code
```

Compare the output against `staging-main` in the registry:

| Registry field | Must match |
|----------------|------------|
| `runtime_user` | `STAGING_RUNTIME_USER`, and the pool's `user =` |
| `deploy_user` | `STAGING_DEPLOY_USER`, and the owner of the current release |
| `code_group` | `STAGING_CODE_GROUP`, and the group of the current release |

Any mismatch means the registry describes a host that does not exist. Fix the
registry and `deployment.conf.example` together — a green test suite is not
evidence here.

### Repository versus runtime checks

Validating the committed file requires no special ownership — CI runs as an
ordinary user.

When the path being validated is the installed runtime default, validation
additionally requires the file to be a regular file, not a symlink, owned
`root:root`, and neither group- nor world-writable — the same protection
`deployment.conf` already gets. A symlink is refused everywhere, because it lets
the validated path and the read path diverge.

## Read-only target-aware operations (slice 2, completed)

`health-check` and `status` now accept exactly one selector:

```bash
health-check --target staging-main
health-check --environment staging

status --target staging-main
status --environment staging
```

Both commands still support `--environment staging|production` with **no
change to that path's behaviour** — same functions, same output, same exit
codes. `--target` and `--environment` cannot be combined, exactly one is
required, and `--help` documents both forms.

### Only active targets may be operated on

Every target-mode entry point calls `require_active_target TARGET` before
doing anything else: it validates the target ID, validates the whole registry,
confirms the target exists, and confirms `lifecycle == active` — all before a
URL is built, `curl` runs, or an application path is touched. `staging-main` is
the only target with `lifecycle: active`; `tits-guru` stays `planned` and is
rejected by both commands with a message naming its lifecycle. This is a
promise, not an implementation detail: `targets validate` continues to reject
`lifecycle: active` on any target other than `staging-main` (see
[Validation](#validation) above), so a target cannot become operable by
mistake — flipping it requires a reviewed registry change.

### tits-guru is still not deployable

`tits-guru` has no directories, users, database, socket, queue worker, cron
entry, or Nginx site; rejecting it at `lifecycle=planned` is exactly what keeps
a *declared* target from being mistaken for a *deployable* one. That stays
true across every slice below, installed or not.

### What is deliberately still legacy-only

`cleanup` graduated to target-awareness in slice 4, `deploy` in slice 5,
`rollback` in slice 6, `backup`/`restore-test` in slice 7.1,
`offsite-backup`/`offsite-retention`/`offsite-restore-test` in slice 7.2, and
`backup-cycle` in slice 7.3; the perimeter that invokes them (cron, sudoers,
server wrappers, the GitHub Actions deploy workflow) migrated in slice 8;
see [Target-aware cleanup](#target-aware-cleanup-slice-4-completed),
[Target-aware deploy](#target-aware-deploy-slice-5-completed),
[Target-aware rollback](#target-aware-rollback-slice-6-completed),
[Target-aware local backup](#target-aware-local-backup-slice-71-completed),
[Target-aware offsite backup](#target-aware-offsite-backup-slice-72-completed),
[Target-aware backup cycle](#target-aware-backup-cycle-slice-73-completed)
and
[Target-aware perimeter](#target-aware-perimeter-slice-8-current)
below, and the migration sequence. Systemd timers and internal
`--environment` support are the only legacy-only pieces left, until a future
legacy-removal slice.

## Installing on the VPS (slice 2b)

Slice 2 changed only files in this repository — nothing was installed on the
VPS, and the current deploy workflow kept running
`health-check --environment staging` exactly as before, unaffected. This slice
adds `infrastructure/scripts/install-target-operations`, which at the time
installed **only** the read-only subset onto the real host: the registry and
the four read-only scripts (`targets`, `common`, `health-check`, `status`) —
nothing that writes to a target's filesystem, database, or running service
state. Slice 4 (below) later expands the same installer to also manage
`cleanup` — the installer itself, and this section's description of what it
does, evolve together; see slice 4 for the current scope.

Full detail — modes, ownership/mode requirements, backup and rollback
behaviour, manual recovery, expected server commands — is in
[`install-target-operations.md`](install-target-operations.md). In short:

- `--check` validates the committed source files and host tooling; read-only,
  no root, safe anywhere;
- `--apply` verifies a staged candidate copy, installs transactionally, then
  verifies the installed result against the real host — with every
  `RATEGURU_*` test override explicitly unset — before committing; any
  failure after files start changing rolls everything back automatically;
- `--verify` re-runs the installed-file and runtime-parity checks against
  whatever is currently installed, with no changes and no backup.

Installing this tooling does not provision `tits-guru` and does not touch
`rollback`, any backup script, workflows, sudoers, or server wrappers — see
the two subsections above, which hold regardless of whether this slice has
run on the VPS yet.

## Target-aware cleanup (slice 4, completed)

`cleanup` now accepts the same selector contract as `health-check` and
`status`:

```bash
cleanup --target staging-main [--keep NUMBER] [--dry-run|--apply]
cleanup --environment staging [--keep NUMBER] [--dry-run|--apply]
```

Omitting both `--dry-run` and `--apply` performs a dry run — this is the
existing, preserved default. `--dry-run` is a new, readable alias for that
same default; `--apply` is required to actually delete anything. `--target`
and `--environment` are mutually exclusive, exactly one is required, and
`--dry-run`/`--apply` are mutually exclusive with each other.

Default retention is read from **independent sources per selector**, not one
derived from the other:

- `--environment staging|production` uses the legacy, host-level
  `STAGING_RELEASE_RETENTION`/`PRODUCTION_RELEASE_RETENTION` from
  `deployment.conf`, exactly as before this slice;
- `--target TARGET` uses that target's own `release_retention` field in the
  registry (`target_release_retention`, already an existing accessor from
  the registry foundation slice) — the same field
  [Registry versus deployment.conf](#registry-versus-deploymentconf) above
  already documents as belonging in the registry because it differs *per
  instance*.

`--target staging-main` and `--environment staging` currently resolve the
identical default `--keep` only because `staging-main`'s registry
`release_retention` and `STAGING_RELEASE_RETENTION` happen to carry the same
number today — proven by dedicated legacy/target dry-run parity tests against
the real, committed registry values, and separately by a test that
deliberately sets them to *different* values and proves each selector reads
its own source, not the other's. A future second production target is free to
carry its own `release_retention`, independent of any other target sharing
its `environment_class`.

`require_active_target` gates target mode exactly as it does for
`health-check`/`status`: a planned target (`tits-guru`) is rejected — clearly
naming its lifecycle — before any lock is acquired, any path is scanned, or
`pinned-releases`/history is touched.

### Dry-run is genuinely side-effect free

Earlier `cleanup` always touched and `chmod`'d `deployments/pinned-releases`
and acquired the deployment lock, even without `--apply`. This is fixed:
dry-run now acquires no lock, creates no lock file, never creates or modifies
`pinned-releases` (a missing one is treated as empty), never appends
deployment history, and never invokes `rm`. Retention, protection
(current/previous/pinned releases are never candidates), and
candidate-selection semantics are otherwise unchanged from the prior
implementation.

### Apply mode: locking, recomputation, and path safety

`--apply` requires root, acquires the same exclusive deployment lock
`deploy`/`rollback` use, and only *then* computes the candidate set — never
before the lock, and never reused stale afterward. Every deletion candidate is
validated by canonical containment under the releases root (`readlink -f`
comparison, not a string prefix) both when first selected and again
immediately before deletion; a release-looking symlink, or any object that
isn't a real, non-symlink directory, fails the whole run closed rather than
being silently skipped or deleted. Deletion stops on the first failure, and
`release-cleaned` history is appended only after a real, successful deletion.

### The `pinned-releases` ownership contract

Before this slice, `pinned-releases` (like `deployments/history.jsonl` and the
deployment lock file) had no explicit ownership contract anywhere in this
repository: it simply inherited whatever identity the invoking process
happened to have, which was `root:root` in practice only because `cleanup` is
invoked exclusively via `infrastructure/config/sudoers/rateguru-deploy` as
`(root)`. `cleanup` now makes this explicit: when apply mode creates a missing
`pinned-releases`, it installs it `root:root 0640` — the same contract already
enforced for `deployment.conf` and the target registry — via a single
`install -o root -g root -m 0640` call, never a bare `touch`+`chmod`. A
pre-existing `pinned-releases` file, valid or not, is never touched — content,
mode, and mtime all survive a `cleanup --apply` run byte-for-byte.

**Accepted on the real staging VPS:** `install-target-operations
--check`/`--apply`/`--verify` all passed against the six-file installer;
`cleanup --environment staging --dry-run` and
`cleanup --target staging-main --dry-run` selected the identical candidate
release set; `cleanup --target tits-guru --dry-run` was rejected with
`lifecycle=planned`.

## Target-aware deploy (slice 5, completed)

`deploy` now accepts the same selector contract as `health-check`, `status`
and `cleanup`, with the exact same operational flags either way:

```bash
deploy --target staging-main --release RELEASE_ID --artifact PATH [--checksum PATH] [--migrate]
deploy --environment staging --release RELEASE_ID --artifact PATH [--checksum PATH] [--migrate]
```

`--target` and `--environment` remain mutually exclusive, exactly one is
required, an empty value or a duplicate flag is rejected, and `--help`
documents both forms.

### Root authorization still runs first, unconditionally

Unlike `cleanup`, `deploy` runs privileged filesystem operations (writing
release directories, changing ownership, switching the `current` symlink) on
every invocation, not only under `--apply`. Its root-first contract is
therefore preserved **exactly**: `require_root` is still the first
substantive action, before any argument parsing. Only after root
authorization succeeds does `require_active_target` run — immediately,
before artifact/checksum canonicalization, the artifact-existence check, the
incoming directory, the target filesystem root, the deployment lock, or any
mutation. So the full ordering for `--target` is: root authorization →
`lifecycle=planned` rejection → (only if active) artifact/filesystem
validation. `tits-guru` is rejected at the lifecycle gate before any artifact
path is ever touched, exactly like `health-check`, `status` and `cleanup`.

### One shared deployment pipeline

Selector resolution (`resolve_target`) populates the same set of variables —
application root, runtime user, deploy user, code group, incoming-artifacts
directory, canonical public hostname (a new `target_primary_public_hostname`
accessor, following the existing fail-closed `_target_property` contract —
no new registry field), and the health-check selector to use after the
switch — from either the registry (`target_*` helpers) or `deployment.conf`
(`environment_*` helpers), depending on which selector was given. Everything
after resolution — checksum verification, unsafe-path rejection, the
disk-space check, extraction, symlinks, ownership/permission normalization,
`verify-required-clis`, Laravel cache preparation, optional migrations,
`rateguru:sharing:verify`, the atomic `current` switch, PHP-FPM reload,
health-check, automatic recovery of `current`/`previous` on failure,
deployment history, and queue restart — is one single pipeline, unchanged
from before this slice, with no second target-specific code path.

### Preserved exactly

Every existing protection carries over unchanged: root-only execution,
release ID validation, artifact/checksum containment within the
selector-specific incoming directory, SHA-256 verification, unsafe tar path
rejection, the disk-space check, the shared deployment lock (the same one
`cleanup`/`rollback` use), immutable release directories, the temporary
extraction directory, `.env`/`storage`/`public/storage` symlinks,
ownership/permission normalization, `verify-required-clis`, Laravel cache
preparation, optional migrations, `rateguru:sharing:verify`, the atomic
`current` switch, PHP-FPM reload, health-check, automatic recovery of
`current`/`previous` after a failure, failed/success deployment history,
queue restart, and deletion of a failed release directory after successful
recovery.

### Installed by install-target-operations (seven files, not six)

`install-target-operations` now manages `deploy` alongside the six files
slice 4 left it with. Consistent with `deploy` never being safe to run for
real during an install/verify pass (no artifact exists to deploy, and doing
so would mutate the real staging target), the installer only ever proves
`deploy --help` succeeds and `deploy --target tits-guru` (given a
deliberately unusable release/artifact combination) fails with
`lifecycle=planned` — both staged, before anything is installed, and again
against the installed binary during `--apply`'s post-install check and every
`--verify` run. See
[`install-target-operations.md`](install-target-operations.md) for the full
seven-file contract.

### What is deliberately still legacy-only

The GitHub Actions deploy workflow, its `/usr/local/sbin` wrapper, and
sudoers keep calling `deploy --environment staging` — this slice does not
touch workflows, sudoers, or server wrappers. Migrating that perimeter to
`--target staging-main` is a separate future slice (see the migration
sequence below). The backup family remains `--environment`-only until its
own slice. `tits-guru` remains `lifecycle=planned` and undeployable —
installing this tooling does not provision it.

**Accepted on the real staging VPS:** the seven-file
`install-target-operations --check`/`--apply`/`--verify` all passed; the
installed `deploy` is owned `root:root` mode `0755`;
`deploy --target tits-guru` (given a deliberately unusable release/artifact
combination) was rejected with `lifecycle=planned` before any artifact
validation was reached; both `health-check --environment staging` and
`health-check --target staging-main` passed.

## Target-aware rollback (slice 6, completed)

`rollback` now accepts the same selector contract as `health-check`,
`status`, `cleanup` and `deploy`:

```bash
rollback --target staging-main (--release RELEASE_ID | --previous)
rollback --environment staging (--release RELEASE_ID | --previous)
```

`--target` and `--environment` remain mutually exclusive, exactly one is
required, and `--help` documents both forms. Exactly one rollback
destination is required: `--release RELEASE_ID` or `--previous` — the two
together are rejected as an ambiguous invocation, a fail-closed correction of
what used to be accepted (silently preferring one over the other). This
changes no valid legacy invocation's behaviour: every existing
`rollback --environment ... --release ...` or
`rollback --environment ... --previous` command keeps working exactly as
before.

### Root authorization still runs first, unconditionally

Like `deploy`, `rollback` runs privileged filesystem operations (switching
the `current`/`previous` symlinks) on every invocation, not only under a
dedicated flag. Its root-first contract is therefore preserved exactly:
`require_root` is still the first substantive action, before any argument
parsing. Only after root authorization succeeds does `require_active_target`
run — immediately, before `target_root`, before the `current`/`previous`
symlinks are read, before the releases directory or the deployment lock is
touched, before history is written, before `systemctl` or health-check runs,
and before any mutation. So the full ordering for `--target` is: root
authorization → `lifecycle=planned` rejection → (only if active)
filesystem/lock work. `tits-guru` is rejected at the lifecycle gate before
any release path is ever touched.

### One shared rollback pipeline

Selector resolution (`resolve_target`) populates the same set of variables —
the target's filesystem root and the health-check selector to use — from
either the registry (`target_root`) or `deployment.conf`
(`environment_root`), depending on which selector was given. Everything
after resolution — the shared deployment lock, requiring `current` to exist,
capturing the original `current`/`previous`, choosing the explicit release or
`previous`, refusing a rollback onto the already-current release, history,
the atomic `previous`/`current` switch, PHP-FPM reload, post-switch
health-check, automatic restoration of the original `current`/`previous` on
failure, and cleanup of temporary symlinks — is one single pipeline,
unchanged in semantics from before this slice, with no second
target-specific code path. Both the normal and the recovery health-check call
use the identical selector rollback was invoked with
(`"${HEALTH_CHECK_BIN}" "${HEALTH_SELECTOR[@]}"`), so target mode and legacy
mode are verified identically on both paths.

### Release path safety

Every release path this script reads as the current release, the previous
release, or an explicit `--release` target is validated, fail-closed, before
any symlink is switched: it must exist, be a real directory, not be a
symlink itself, be a direct child of the releases root, have a basename that
passes release ID validation, and — after `readlink -f` — resolve to exactly
itself. `current`/`previous` themselves remain ordinary symlinks, as always;
what is refused is an unsafe *target* of that symlink — escaping the
releases root, a nested path, a release directory that is itself a symlink,
an invalid release ID, or a missing target — never the fact that
`current`/`previous` are symlinks in the first place.

### Installed by install-target-operations (eight files, not seven)

`install-target-operations` now manages `rollback` alongside the seven files
slice 5 left it with. Consistent with `rollback` never being safe to run for
real during an install/verify pass (there is no throwaway release to roll
back to on the real staging target), the installer only ever proves
`rollback --help` succeeds and `rollback --target tits-guru` (given a
deliberately unusable release ID) fails with `lifecycle=planned` — both
staged, before anything is installed, and again against the installed binary
during `--apply`'s post-install check and every `--verify` run. See
[`install-target-operations.md`](install-target-operations.md) for the full
eight-file contract.

### What is deliberately still legacy-only

The GitHub Actions deploy workflow, its `/usr/local/sbin` wrapper, and
sudoers are untouched by this slice and keep calling `deploy --environment
staging`. The backup family remains `--environment`-only until its own
slices (see
[Target-aware local backup](#target-aware-local-backup-slice-71-completed)
and
[Target-aware offsite backup](#target-aware-offsite-backup-slice-72-completed)
below). `tits-guru` remains `lifecycle=planned` and undeployable.

**Accepted on the real staging VPS:** the eight-file
`install-target-operations --check`/`--apply`/`--verify` all passed; the
installed `rollback` is owned `root:root` mode `0755`;
`rollback --target tits-guru` was rejected with `lifecycle=planned`; a
legacy `rollback --environment staging --previous` completed successfully; a
target `rollback --target staging-main --release ...` returned the release
to its original state; the final post-rollback health check passed.

## Target-aware local backup (slice 7.1, completed)

`backup` and `restore-test` now accept the same selector contract as
`health-check`, `status`, `cleanup`, `deploy` and `rollback`:

```bash
backup --target staging-main
backup --environment staging

restore-test --target staging-main
restore-test --environment staging
```

`--target` and `--environment` remain mutually exclusive, exactly one is
required, and `--help` documents both forms.

The backup family is split into three independently reviewable increments —
7.1 (this slice, local only), 7.2 (offsite/B2), 7.3 (`backup-cycle`
orchestration) — because local PostgreSQL/storage backup, remote B2
operations, and orchestration each carry different side effects and
deserve separate scrutiny. `backup-cycle`, `offsite-backup`,
`offsite-retention` and `offsite-restore-test` are untouched by this slice
and remain `--environment`-only.

### Root authorization still runs first, unconditionally

Like `deploy` and `rollback`, both scripts run privileged filesystem/database
operations on every invocation, not only under a dedicated flag. Their
root-first contract mirrors `rollback` exactly: `require_root` is still the
first substantive action, before any argument parsing. Only after root
authorization succeeds does `require_active_target` run — immediately, before
any backup root, lock, database binary, `rclone`, or filesystem work — so
`tits-guru` stays rejected with `lifecycle=planned` before anything is
touched.

### Legacy and target selectors share one namespace, root and lock

`backup --environment staging` and `backup --target staging-main` (and the
equivalent pair for `restore-test`) resolve to the **identical** backup
namespace, root and lock file:

```text
backup namespace = staging
backup root      = /home/www/rateguru/backups/staging
lock             = /home/www/rateguru/run/backup-staging.lock
```

The lock filename is built from the resolved backup namespace, never from
the selector label — this is what makes the two selectors mutually
exclusive against the same namespace rather than able to write concurrently.
No existing backup directory moves.

Target mode reads `DATABASE_NAME`/`BACKUP_NAMESPACE`/`RETENTION_DAYS` from
the registry (`target_database_name`, `target_backup_namespace`,
`target_local_backup_retention` — all pre-existing accessors from the
registry foundation slice); legacy mode reads them from three new `common`
helpers, `environment_database_name`/`environment_backup_namespace`/
`environment_local_backup_retention`, following the exact contract every
other `environment_*` helper already has (the caller runs
`validate_environment` first; no isolated defensive default).

### Manifest: schema 2, backward compatible with schema 1

Every backup `backup` produces from this slice onward carries a
`manifest_schema_version: 2` manifest recording `selector`, `target` (`null`
for a legacy-mode backup), `environment`, and `backup_namespace` alongside
the pre-existing fields. `restore-test` validates whichever schema it finds:
`project`/`environment`/`database` are always required; `backup_namespace`
is required only for schema 2; a schema 2 backup with a non-null `target`
must match `restore-test`'s own `--target`, if that selector is in use. A
schema 1 backup — everything produced before this slice, with none of the
new fields — remains fully restorable through both
`restore-test --environment staging` and
`restore-test --target staging-main`. Manifest validation, like checksum and
storage-archive validation, always completes before the temporary database
is created.

### Target-specific server configuration snapshot

In target mode, the server-configuration archive contains only that
target's own Nginx site, PHP-FPM pool, Supervisor unit, cron entry, and
deploy account's `authorized_keys` — never another target's. Legacy mode
keeps its existing, byte-for-byte-preserved path list covering both staging
and production files, unchanged.

### Installed by install-target-operations (ten files, not eight)

`install-target-operations` now manages `backup` and `restore-test`
alongside the eight files slice 6 left it with. Consistent with neither
script being safe to run for real during an install/verify pass, the
installer only ever proves `backup --help`/`restore-test --help` succeed and
`backup --target tits-guru`/`restore-test --target tits-guru` (both
correctly) fail with `lifecycle=planned` — staged, before anything is
installed, and again against the installed binaries during `--apply`'s
post-install check and every `--verify` run. See
[`install-target-operations.md`](install-target-operations.md) for the full
ten-file contract.

### What is deliberately still legacy-only

`backup-cycle`, `offsite-backup`, `offsite-retention` and
`offsite-restore-test` are untouched by this slice and remain
`--environment`-only until slices 7.2 and 7.3. The GitHub Actions deploy
workflow, its `/usr/local/sbin` wrapper, and sudoers are untouched.
`tits-guru` remains `lifecycle=planned` and undeployable.

**Accepted on the real staging VPS:** the ten-file
`install-target-operations --check`/`--apply`/`--verify` all passed; the
installed `backup` and `restore-test` are owned `root:root` mode `0755`;
`backup --target tits-guru` and `restore-test --target tits-guru` were both
rejected with `lifecycle=planned`; a legacy `backup --environment staging`
and a target `backup --target staging-main` both created a local backup in
the shared `staging` namespace, and their checksums passed; a legacy
`restore-test --environment staging` and a target
`restore-test --target staging-main` both successfully restored PostgreSQL;
the final health-check passed.

## Target-aware offsite backup (slice 7.2, completed)

`offsite-backup`, `offsite-retention` and `offsite-restore-test` now accept
the same selector contract as `backup`/`restore-test`:

```bash
offsite-backup --target staging-main
offsite-backup --environment staging

offsite-retention --target staging-main [--apply]
offsite-retention --environment staging [--apply]

offsite-restore-test --target staging-main
offsite-restore-test --environment staging
```

`--target` and `--environment` remain mutually exclusive, exactly one is
required, and `--help` documents both forms. `offsite-backup` and
`offsite-restore-test` require root unconditionally, as the first action of
every invocation, matching `backup`/`restore-test`'s exact contract.
`offsite-retention` requires root unconditionally too — its dry-run mode
simply never acts on that privilege (see below).

### Legacy and target selectors share one remote namespace, root and lock

All three scripts resolve `--environment staging` and `--target
staging-main` to the **identical** remote namespace, base and lock file:

```text
backup namespace = staging
remote root       = rateguru-b2:rateguru-database-backups/rateguru/staging
lock (offsite-backup)        = /home/www/rateguru/run/offsite-backup-staging.lock
lock (offsite-retention)     = /home/www/rateguru/run/offsite-retention-staging.lock
lock (offsite-restore-test)  = /home/www/rateguru/run/offsite-restore-test-staging.lock
```

Every remote root and lock filename is built from `BACKUP_NAMESPACE`, never
from the selector label — proven for each script by a dedicated concurrent-
lock test. No existing remote backup moves.

Target mode reads `BACKUP_NAMESPACE`/`ENVIRONMENT_CLASS` from the registry
(`target_backup_namespace`, `target_environment_class` — both pre-existing
accessors), and `offsite-retention` additionally reads
`target_offsite_backup_retention`. Legacy mode reads the equivalent values
from `environment_backup_namespace` (existing, from slice 7.1) and the new
`environment_offsite_backup_retention` — following the exact contract every
other `environment_*` helper already has.

### Manifest validation reuses the same strict schema contract

`offsite-backup` validates the local backup's manifest before ever calling
`rclone`; `offsite-restore-test` validates the downloaded manifest before
`createdb` — both using the identical strict, type-based
`manifest_schema_version` classification `restore-test` established (and
fixed a fail-open bug in) in slice 7.1: absent or JSON `null` is schema 1;
a JSON *number* equal to `2` is schema 2 (additionally checking
`backup_namespace`, and, under `--target`, a non-null manifest `target`);
any other value — `3`, `0`, the JSON *string* `"2"`, an array, an object, a
boolean — is rejected outright, before any mutation, with `unsupported
backup manifest schema_version: ...` naming the offending value. The
classifier itself (`manifest_schema_classify`) is shared, in `common`,
between `offsite-backup` and `offsite-restore-test` — local `restore-test`
is untouched and keeps its own, contractually identical inline copy. Neither
offsite script has an independently resolved database name to compare
against (unlike local `restore-test`) — `database` is only required to be
present and non-empty.

### Retention: side-effect-free dry-run, locked and re-listed apply

`offsite-retention`'s dry-run (the default, no `--apply`) is genuinely
side-effect free: no lock is acquired, and — unlike the pre-slice-7.2
implementation — `rclone purge` is never invoked in any form, not even with
rclone's own `--dry-run` flag. Candidates are computed purely from a
read-only `rclone lsf` listing.

`--apply` lists remote backups twice: an unlocked preview pass (purely for
operator visibility, never used to decide what gets purged), then the lock
is acquired and the listing and candidate computation both run again, fresh
— protecting against a backup uploaded concurrently between the two passes.
`rclone purge` only ever acts on the second, locked computation. The latest
backup is always protected, regardless of age; backups within the resolved
retention window are protected; only backups older than the cutoff and not
the latest become candidates.

Target retention (`target_offsite_backup_retention`) is read independently
of the legacy value (`environment_offsite_backup_retention`) — proven by a
dedicated test using a deliberately different target retention (17 days)
against legacy staging's 30, confirming each selector computes its own
cutoff while still sharing the same remote namespace and lock.

### Installed by install-target-operations (thirteen files, not ten)

`install-target-operations` now manages `offsite-backup`,
`offsite-retention` and `offsite-restore-test` alongside the ten files slice
7.1 left it with. Consistent with none of the three being safe to run for
real during an install/verify pass (no B2 access, no remote listing, no
local backup mutation, no database work), the installer only ever proves
`--help` succeeds and `--target tits-guru` (correctly) fails with
`lifecycle=planned` — staged, before anything is installed, and again
against the installed binaries during `--apply`'s post-install check and
every `--verify` run. See
[`install-target-operations.md`](install-target-operations.md) for the full
thirteen-file contract.

### What is deliberately still legacy-only

`backup-cycle` was untouched by this slice and graduated to target-awareness
in slice 7.3 (below). The GitHub Actions deploy workflow, its
`/usr/local/sbin` wrapper, cron, systemd timers, and sudoers are untouched.
`tits-guru` remains `lifecycle=planned` and undeployable.

**Accepted on the real staging VPS (`PHASE 4 SLICE 7.2 ACCEPTED`):** the
thirteen-file `install-target-operations --check`/`--apply`/`--verify` all
passed; the installed `offsite-backup`, `offsite-retention` and
`offsite-restore-test` are owned `root:root` mode `0755`; a real target
upload (`offsite-backup --target staging-main`) succeeded; a target
`offsite-retention --target staging-main` dry-run succeeded;
`offsite-restore-test --target staging-main` succeeded; `--target tits-guru`
was rejected with `lifecycle=planned` for all three scripts; the final
health-check passed.

## Target-aware backup cycle (slice 7.3, completed)

`backup-cycle` now accepts the same selector contract as every other
backup-path script:

```bash
backup-cycle --target staging-main
backup-cycle --environment staging
```

`--target` and `--environment` remain mutually exclusive, exactly one is
required, and `--help` documents both forms. This is the last of the three
backup-path increments (7.1 local backup/restore-test, 7.2 offsite
backup/retention/restore-test, 7.3 this orchestrator) — kept as its own
slice because orchestration carries different review concerns from the
scripts it drives: lock/history semantics and fail-fast ordering across five
child invocations, rather than a single script's own selector resolution.

### Root authorization still runs first, unconditionally

`backup-cycle`'s `main()` mirrors every other mutating operational script
exactly: `require_root`, then `parse_backup_cycle_args`, then
`resolve_backup_cycle_subject` (where `require_active_target` runs
immediately, in the `--target` branch, before the cycle lock, the history
file, or any child command), then `perform_backup_cycle`. `tits-guru` is
rejected with `lifecycle=planned` before any of those are ever touched.

### One shared namespace and cycle lock

`backup-cycle --environment staging` and `backup-cycle --target
staging-main` resolve to the **identical** backup namespace and lock file —
the same namespace `backup`, `restore-test`, `offsite-backup`,
`offsite-retention` and `offsite-restore-test` already share:

```text
backup namespace = staging
cycle lock        = /home/www/rateguru/run/backup-cycle-staging.lock
history file      = /home/www/rateguru/backups/backup-cycles.jsonl
```

The lock filename is built from the resolved backup namespace, never from
the selector label or the selector type, so the legacy and target selectors
of one namespace can never run concurrently, while two different namespaces
never contend with each other.

### The five-step pipeline is strictly sequential and fail-fast

```text
1. backup
2. restore-test
3. offsite-backup
4. offsite-retention --apply
5. offsite-restore-test
```

Every step receives the exact selector the cycle itself was given — never a
mix of `--target` and `--environment` within one cycle — and its real
stdout/stderr passes straight through, unsuppressed. Each step only runs if
the previous one exited `0`. The first non-zero exit stops the cycle
immediately: no later step runs, the cycle's own exit code is that child's
exit code unmodified, and `offsite-retention --apply` in particular is only
ever reached once local backup, local restore-test and the offsite upload
have all already succeeded — old B2 backups are never purged on the strength
of a local backup or upload that did not actually happen. If retention
itself succeeds but the subsequent offsite restore-test fails, the cycle is
still reported failed; the retention deletion is not rolled back. This slice
does not add local backup retention — `backup` keeps its own, unchanged
local pruning.

### Cycle history: one compact JSON record per started cycle

Every cycle that gets past the lock appends exactly one `jq -cn`-generated,
compact-JSON line to `/home/www/rateguru/backups/backup-cycles.jsonl`
(`0600`, inside a `0700` root-owned directory that is shared with every
namespace — never per-namespace, mirroring `offsite-backup`'s own
`offsite-history.jsonl`). A single `printf ... >>` is one `write(2)` call,
atomic up to `PIPE_BUF` on an `O_APPEND`-opened file, which is what keeps two
different namespaces' concurrent cycles from interleaving their records —
the per-namespace lock only serializes writers within the same namespace.

A success record carries `completed_steps` (all five, in order) and
`failed_step: null`, and never carries an `exit_code` field at all. A failure
record's `completed_steps` lists only the steps that actually finished, plus
`failed_step` and the failing child's own `exit_code`. `target` is `null` for
the legacy selector, exactly like every other backup-path history record. A
`lifecycle=planned` rejection or lock contention writes no history record —
only a cycle that genuinely started, past the lock, ever gets one. A history
write failure after a fully successful pipeline still turns the cycle into a
reported failure; a history write failure on the failure path is logged but
never replaces the original child's own exit code.

### Installed by install-target-operations (fourteen files, not thirteen)

`install-target-operations` now manages `backup-cycle` alongside the
thirteen files slice 7.2 left it with. Consistent with a real cycle being
unsafe to run during an install/verify pass (it would create a local backup,
run a restore test, upload offsite and apply real retention against the real
staging-main target), the installer only ever proves `backup-cycle --help`
succeeds and `backup-cycle --target tits-guru` (correctly) fails with
`lifecycle=planned` — staged, before anything is installed, and again
against the installed binary during `--apply`'s post-install check and every
`--verify` run. See [`install-target-operations.md`](install-target-operations.md)
for the full fourteen-file contract.

### What is deliberately still legacy-only

Cron, systemd timers, the GitHub Actions deploy workflow, its
`/usr/local/sbin` wrapper, and sudoers were untouched by this slice — the
`rateguru-backups` cron entry kept calling `backup-cycle --environment
staging` until slice 8 (below) switched it to `--target staging-main`.
`tits-guru` remains `lifecycle=planned` and undeployable. `--environment`
itself is kept working, temporarily, alongside `--target` across every
backup-path script; it is removed only at the end of the migration
sequence, once `--target` parity is proven end to end.

**Accepted on the real staging VPS:** the fourteen-file
`install-target-operations --check`/`--apply`/`--verify` all passed;
`/home/www/rateguru/bin/backup-cycle` was updated; a real
`backup-cycle --target staging-main` ran the full five-step pipeline to
completion (local backup, local restore-test, offsite upload, offsite
retention apply, offsite restore-test all succeeded); the cycle was
recorded in `/home/www/rateguru/backups/backup-cycles.jsonl`; the final
staging health-check passed.

## Target-aware perimeter (slice 8, current)

Every real staging operation now goes through target-based invocation, end
to end:

```text
GitHub Actions -> SSH -> sudo wrapper -> deploy --target staging-main
cron            -> backup-cycle/restore-test/offsite-restore-test --target staging-main
```

`deploy`, `rollback`, `cleanup`, `backup-cycle`, `restore-test` and
`offsite-restore-test` themselves are unchanged by this slice — every one of
them already accepted `--target` from an earlier slice (5, 6, 4, 7.3, 7.1
and 7.2 respectively). What changes here is exclusively the *perimeter*
that invokes them: the server-side sudo wrappers, the sudoers rule, the
GitHub Actions deployment path, and the backup cron entries.

### Three generic, target-aware sudo wrappers

`infrastructure/config/wrappers/rateguru-{deploy,rollback,cleanup}`, sourced
from this repository and installed at
`/usr/local/sbin/rateguru-{deploy,rollback,cleanup}` (`root:root 0755`),
replace the legacy, per-environment
`rateguru-staging-{deploy,rollback,cleanup}` /
`rateguru-production-{deploy,rollback,cleanup}` wrappers for staging. Each
generic wrapper:

- requires root (it is only ever reached via `sudo`, or by real root
  directly for server administration);
- accepts exactly one selector, `--target TARGET_ID` — `--environment`, a
  missing/duplicate/empty/flag-shaped `--target`, and any lone short flag
  other than `-h`/`--help` are all rejected before anything else runs;
- authorizes the caller: when invoked through `sudo`, `SUDO_USER` must
  exactly equal the target's own registered `deploy_user` from the
  registry, or the caller is rejected before the underlying operation is
  ever reached; a direct invocation by real root (no `SUDO_USER`, or
  `SUDO_USER=root`) is always permitted;
- calls `require_active_target` before the underlying operation, so a
  planned target (`tits-guru`) is rejected the same way every other
  target-aware command already rejects it — before any operation-specific
  argument, filesystem path, lock, or database is touched;
- execs into the real, unchanged `deploy`/`rollback`/`cleanup` binary at its
  generic installed path (`/home/www/rateguru/bin/deploy`, `.../rollback`,
  `.../cleanup`) with `--target TARGET_ID` prepended exactly once, followed
  by every other argument the caller gave, untouched and in order — the
  wrapper never interprets `--release`/`--artifact`/`--checksum`/`--migrate`/
  `--keep`/`--dry-run`/`--apply`/`--previous` itself;
- scrubs its own process environment before that exec: `env -i` with only
  `PATH`, `HOME=/root`, `USER=root`, `LOGNAME=root` — every `RATEGURU_*` test
  override and any other caller-supplied variable is stripped
  unconditionally, not enumerated and unset one at a time;
- uses only Bash arrays and `exec` — never `eval`, never `bash -c`, never a
  string-built command.

`--help` prints the wrapper's own target-only usage form and exits before
any selector, authorization, or lifecycle work — it never runs the
underlying operation.

### Sudoers: generic wrappers for staging, no entry for tits-guru

`infrastructure/config/sudoers/rateguru-deploy` grants
`deploy-rateguru-staging` `NOPASSWD` access to the three generic wrappers.
No rule is added for `tits-guru`'s own (unprovisioned) deploy user —
`tits-guru` stays `lifecycle=planned`, so nothing in the sudoers file grants
it any access at all. The prior per-environment rules
(`rateguru-staging-*`, `rateguru-production-*`) remain, explicitly marked as
temporary legacy compatibility: the GitHub Actions workflow no longer
invokes them, and a dedicated future legacy-removal slice deletes them from
both the server and this file.

### GitHub Actions: a required, locally-validated deployment-target input

`.github/actions/deploy-rateguru/action.yml` gained a required
`deployment-target` input, validated locally (`^[a-z0-9]+(-[a-z0-9]+)*$` —
rejecting empty, uppercase, slash, whitespace, shell metacharacters, and a
flag-shaped value) before any SSH connection is made. The remote deploy
command becomes `sudo -n "${DEPLOY_WRAPPER}" --target "${DEPLOYMENT_TARGET}"
--release ... --artifact ... --checksum ... [--migrate]`, built entirely
through `printf -v ... %q` — never string concatenation of an unquoted
value. `.github/workflows/deploy-staging.yml` passes
`deployment-target: staging-main` explicitly; the GitHub Environment
`staging` (the approval/secrets boundary) and the `rateguru-staging-deployment`
concurrency group are both unchanged — they were never a legacy operational
selector and are not renamed by this slice. After this slice merges, the
`DEPLOY_WRAPPER` GitHub variable must be switched, by hand, to
`/usr/local/sbin/rateguru-deploy` — see
[Post-merge real-VPS acceptance](#post-merge-real-vps-acceptance-slice-8)
below.

### Backup cron: the same schedules and log paths, now target-based

`infrastructure/config/cron/rateguru-backups` keeps its exact schedule
(`30 2 * * *` nightly, `10 4`/`40 4 * * 0` weekly) and log paths unchanged;
only the selector on each of the three operational lines changed from
`--environment staging`/`--environment production` to
`--target staging-main`. The Laravel scheduler cron entry
(`rateguru-staging-scheduler`) is untouched — it never used the legacy
selector and is unrelated to this migration.

### Transactional installer

`infrastructure/scripts/install-target-perimeter` installs the five files
above (three wrappers, the sudoers rule, the cron file) with the same
`--check`/`--apply`/`--verify` contract, staged pre-install verification,
timestamped backup, and automatic rollback-on-failure that
`install-target-operations` already established — see
[`target-perimeter.md`](target-perimeter.md) for the full contract. It never
runs a real deploy, rollback, cleanup apply, backup-cycle, or restore
operation: its only runtime probes are each wrapper's `--help` and a bare
`--target tits-guru` (which the wrapper itself rejects with
`lifecycle=planned` before any underlying binary is ever reached).

### What is deliberately still legacy-only

Internal `--environment` support in `deploy`/`rollback`/`cleanup`/
`backup-cycle`/`restore-test`/`offsite-restore-test` is untouched and stays
working. The temporary legacy per-environment wrappers and sudoers rules
remain installed, for rollback safety, until a dedicated future
legacy-removal slice deletes both `--environment` support and the legacy
wrappers/sudoers rules together. `tits-guru` remains `lifecycle=planned` and
undeployable — this slice does not provision it.

### Post-merge real-VPS acceptance (slice 8)

1. Deploy the latest `develop` to staging (through the still-legacy
   perimeter, one last time).
2. Run `install-target-perimeter --check`, then `--apply`, then `--verify`
   on the VPS.
3. Update the GitHub variable `DEPLOY_WRAPPER` to
   `/usr/local/sbin/rateguru-deploy`.
4. Confirm the installed cron and sudoers with
   `install-target-perimeter --verify`.
5. Run the GitHub Actions staging deployment workflow for real.
6. Verify deploy history and the post-deploy health check.
7. Inspect the installed `/etc/cron.d/rateguru-backups` on the VPS.
8. Confirm no active perimeter command (deploy, cron) still uses
   `--environment`.

## Compatibility with the current --environment interface

`validate_environment`, `environment_root`, `environment_runtime_user`,
`environment_code_group`, `environment_deploy_user`,
`environment_incoming_artifacts`, `environment_url` and
`environment_host_header` are untouched and keep their exact behaviour. Every
operational script, sudoers rule, server wrapper and GitHub Actions workflow
continues to call them unchanged.

The `target_*` helpers **read the registry only when one of them is actually
called**. Sourcing `common` does not touch the registry. This is what makes
each slice safe to merge before the registry is installed: the scripts
currently on the VPS gain the functions but do not have to invoke them, so
nothing can fail on a host where the registry is still absent — proven for
slice 2 specifically by running `health-check --environment staging` and
`status --environment staging` with the registry file missing or malformed and
confirming both still succeed.

`--environment` is removed only at the end of the sequence below, and only once
`staging-main` has demonstrated parity through the registry.

## Migration sequence

1. **Registry foundation** — the registry, validation CLI, lazy `target_*`
   helpers, docs and tests. *(completed)*
2. **Read-only target operations** — `health-check` and `status` accept
   `--target` alongside `--environment`, gated to `lifecycle=active` targets
   only. *(completed — see the section above)*
3. **Install and verify read-only operations** — a transactional installer
   places the registry and the read-only scripts on the staging VPS and
   proves runtime parity against the real host. *(completed and accepted on
   the real staging VPS — see
   [Installing on the VPS](#installing-on-the-vps-slice-2b) above and
   [`install-target-operations.md`](install-target-operations.md))*
4. **Target-aware cleanup** — `cleanup` accepts `--target` alongside
   `--environment`, dry-run is genuinely side-effect free, and the installer
   now manages it transactionally too. *(completed and accepted on the real
   staging VPS — see
   [Target-aware cleanup](#target-aware-cleanup-slice-4-completed) above)*
5. **Target-aware deploy** — `deploy` accepts `--target`, preserving its
   root-first contract and every existing protection unchanged; the installer
   now manages it too. *(completed and accepted on the real staging VPS —
   see [Target-aware deploy](#target-aware-deploy-slice-5-completed) above)*
6. **Target-aware rollback** — `rollback` accepts `--target`, preserving its
   root-first contract, adding fail-closed release path safety, and reusing
   the identical health-check selector on both the normal and recovery path;
   the installer now manages it too. *(completed and accepted on the real
   staging VPS — see
   [Target-aware rollback](#target-aware-rollback-slice-6-completed) above)*
7. **Backup path**, split into three independently reviewable increments —
   local database/storage backup, remote B2 operations, and orchestration
   each carry different side effects:
   1. **7.1 Local backup and local restore-test** — `backup` and
      `restore-test` accept `--target`, preserving the existing `staging`
      backup namespace so no existing local backup directory moves; the
      installer now manages them too. *(completed and accepted on the real
      staging VPS — see
      [Target-aware local backup](#target-aware-local-backup-slice-71-completed)
      above)*
   2. **7.2 Offsite backup path** — `offsite-backup`, `offsite-retention`,
      `offsite-restore-test` accept `--target`, preserving the existing
      `staging` offsite (B2) namespace so no existing remote backup moves;
      the installer now manages them too. *(completed and accepted on the
      real staging VPS, `PHASE 4 SLICE 7.2 ACCEPTED` — see
      [Target-aware offsite backup](#target-aware-offsite-backup-slice-72-completed)
      above)*
   3. **7.3 Backup-cycle orchestration** — `backup-cycle` accepts `--target`,
      sharing the same namespace/lock as the five scripts it orchestrates,
      and appends a compact JSONL history record per cycle. *(completed and
      accepted on the real staging VPS — see
      [Target-aware backup cycle](#target-aware-backup-cycle-slice-73-completed)
      above)*
8. **Perimeter** — three generic sudo wrappers, a sudoers rule for the
   staging deploy user, and the GitHub Actions deploy workflow and backup
   cron switched from `--environment staging` to `--target staging-main`.
   *(current — see
   [Target-aware perimeter](#target-aware-perimeter-slice-8-current) above
   and [`target-perimeter.md`](target-perimeter.md); real-VPS acceptance is
   a post-merge follow-up)*
9. **Remove compatibility** — drop `--environment` and the temporary legacy
   per-environment wrappers/sudoers rules, only after `staging-main` parity
   is proven end to end across every slice above.

Each step is independently reviewable and revertible. `staging-main` deliberately
mirrors current staging exactly, so parity in the last step is a comparison
against committed values rather than a judgement call.

Read-only operations (health-check, status) were split from mutating ones
(deploy, rollback, cleanup, backup) deliberately: a target-aware read never
risks the running host, so it is the safer half of "deploy path" to land
first, ahead of anything that writes to a target's filesystem, database, or
service state.

## Adding a target

1. Add the object to `infrastructure/config/deployment-targets.json` with
   `lifecycle: "planned"`.
2. Run `targets validate` and fix every reported problem.
3. Provision the real infrastructure in its own reviewed change.
4. Flip to `active` and extend the validation allowlist in the same change.

Never flip a target to `active` before step 3.
