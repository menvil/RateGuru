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

The two consumers resolve the path differently, because only one of them has
`deployment.conf` available.

`common` — sources `deployment.conf`, so it can honour a setting from there:

1. `RATEGURU_TARGET_REGISTRY_FILE` — tests and controlled tooling;
2. `TARGET_REGISTRY_FILE` from `deployment.conf`, when set;
3. `/home/www/rateguru/config/deployment-targets.json`.

`scripts/targets` — deliberately does **not** source `common` or
`deployment.conf` (it must run in the repository during CI, where no
`deployment.conf` exists), so `TARGET_REGISTRY_FILE` is not consulted at all:

1. `--file PATH`, when given — an explicit empty value is rejected, never
   silently replaced by a fallback;
2. `RATEGURU_TARGET_REGISTRY_FILE`;
3. `/home/www/rateguru/config/deployment-targets.json`.

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
- `runtime_user`, `deploy_user`, `code_group`;
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

## Compatibility with the current --environment interface

**Nothing about `--environment` changes in this slice.**

`validate_environment`, `environment_root`, `environment_runtime_user`,
`environment_code_group`, `environment_deploy_user`,
`environment_incoming_artifacts`, `environment_url` and
`environment_host_header` are untouched and keep their exact behaviour. Every
operational script, sudoers rule, server wrapper and GitHub Actions workflow
continues to call them unchanged.

The new `target_*` helpers live alongside them and **read the registry only when
one of them is actually called**. Sourcing `common` does not touch the registry.
This is what makes the slice safe to merge before the registry is installed: the
scripts currently on the VPS gain the functions but never invoke them, so
nothing can fail on a host where the registry is still absent.

`--environment` is removed only at the end of the sequence below, and only once
`staging-main` has demonstrated parity through the registry.

## Migration sequence

1. **Registry foundation** — the registry, validation CLI, lazy `target_*`
   helpers, docs and tests. *(this slice)*
2. **Deploy path** — `deploy`, `rollback`, `health-check`, `status`, `cleanup`
   accept `--target` alongside `--environment`.
3. **Backup path** — `backup`, `backup-cycle`, `offsite-backup`,
   `offsite-retention`, `restore-test`, `offsite-restore-test`, preserving the
   existing `staging` backup namespace so no existing local or B2 path moves.
4. **Perimeter** — GitHub Actions workflows, sudoers rules, server wrappers.
5. **Install** — place and validate the registry on the staging VPS.
6. **Remove compatibility** — drop `--environment` only after `staging-main`
   parity is proven end to end.

Each step is independently reviewable and revertible. `staging-main` deliberately
mirrors current staging exactly, so parity in step 6 is a comparison against
committed values rather than a judgement call.

## Adding a target

1. Add the object to `infrastructure/config/deployment-targets.json` with
   `lifecycle: "planned"`.
2. Run `targets validate` and fix every reported problem.
3. Provision the real infrastructure in its own reviewed change.
4. Flip to `active` and extend the validation allowlist in the same change.

Never flip a target to `active` before step 3.
