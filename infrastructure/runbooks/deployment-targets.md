# Deployment targets

The deployment target registry is the source of truth for **which application
instances exist** and **what belongs to each of them**.

This document describes the registry itself, and how every operational script
consumes it. `--target TARGET_ID` is the sole operational selector: every
script identifies which instance to act on this way, with no alternative
selector of any kind.

## Target versus environment class

Every operational script identifies a deployment by one flag:

```bash
--target TARGET_ID
```

A target ID carries exactly one meaning: **which instance** to act on — its
root, users, database, backup path. That is deliberately kept separate from
**what kind** of instance it is — how strict retention is, whether debug is
allowed, how cautious tooling should be. Conflating the two would cap the
platform at exactly one instance per kind: a second production brand on the
same codebase would have nowhere to go, because "production" would already be
taken as an identity rather than being one property among several.

The registry keeps them separate.

- **Target ID** — the identity of one independently deployable application
  instance. Stable, unique, and used to look up every concrete value.
  Examples: `staging-main`, `tits-guru`.
- **Environment class** — metadata *about* a target: `staging` or `production`.
  Many targets may share a class. It never identifies anything.

`staging-main` rather than `staging` is deliberate: the name must not read as a
class, because one day there may be a second staging instance.

## Branches: which ref is trusted for what

A target says *where*. A branch says *what is trusted to act there*, and the two
are separate questions.

```text
feature/*
    │ PR
    ▼
develop ──────────────► staging
    │                   (verify here)
    │ PR
    ▼
main  ────────────────► production control plane
    │
    │ v* tag on a commit contained in main
    ▼
Release to production
```

| Ref | What it is | What it may do |
|---|---|---|
| `develop` | the integration branch | staging: tooling and deployments |
| `main` | production-ready, and the production **control plane** | run production operational workflows |
| `v*` | a tag on a commit contained in `main` | the **only** way new application code reaches production |

### Why main is allowed in the production Environment

> **Prerequisite, not a description.** The `production-tits-guru` Environment's
> allowlist is GitHub configuration, which no pull request can change. It must be
> set by an operator — *Settings → Environments → production-tits-guru →
> Selected branches and tags* — to `main` and `v*`, with `develop` removed, plus a
> repository **tag ruleset protecting `v*`** (see below). Until the allowlist is
> set, a production operational workflow running from `main` is **rejected by
> GitHub before any job starts**. The order is: merge the `develop → main`
> promotion, set the allowlist, add the tag ruleset, then run the workflow.

`main` is allowed, and `develop` deliberately is not. That is not a convenience:
the emergency and onboarding operations have to be runnable against production,
and each of them is privileged infrastructure code rather than application code —

* **Prepare** — bootstrap or re-converge the host;
* **Repair** — re-converge a target's own surface;
* **Restore** — put back data and align code to it;
* **Recover** — rebuild a lost host;
* **Rollback** — step back one release;
* **Configure** / **Provision** — onboarding, while the target is still planned.

Each takes its tooling from `main`, so privileged code must pass a
`develop → main` pull request before it can act on production. Taking it from
`develop` would mean anything merged for staging could immediately touch
production, which is exactly the gap this model closes.

### Allowing main is not a deploy path

`main` being allowed does **not** mean `main`'s HEAD can be deployed. A new
application release reaches production only as a `v*` tag, and
`Release to production` proves, fail-closed, that the tagged commit is contained
in `main` before it builds anything. The artifact verified on staging in that run
is the byte-identical artifact promoted to production — there is no second build.

Two checkouts deliberately stay off the control-plane branch, and must:

* a **restore** or **recovery** builds the exact historical commit its backup
  names, because the whole point is putting back the release the data belongs to;
* a **release** builds the exact tagged commit, not a branch head.

Replacing either with `main` would silently install different code than the
operation promised. `tests/Feature/Architecture/ProductionControlPlaneTest.php`
enforces all of the above, including those two exceptions.

### One Environment, and a main-only gate in the repository

`v*` has to be in the allowlist: `release.yml`'s `deploy-production` job uses this
Environment and runs with `github.ref` at the tag, so without `v*` no release
could deploy. And GitHub matches an Environment's allowlist against the ref of the
**run**, while a `workflow_dispatch` ref may be a tag as well as a branch — so the
allowlist alone would also admit a manual dispatch of a production operational
workflow from a tag.

**The decision is one Environment permitting `main` and `v*`, with the main-only
rule enforced in the repository rather than by splitting the Environment.** Each of
the seven manual production workflows carries a fail-closed gate:

```text
validate-ref          no environment:  refuses any ref but refs/heads/main
      │
      ▼
<every job with environment: production-tits-guru>
```

The gate is a job of its own and holds **no** `environment:`, which is the load-
bearing detail: a refused run never has the Environment's credentials available to
it, because the job that owns them never begins. Every environment-bearing job in
those workflows is downstream of it, at any depth — in `repair`, `restore` and
`recover` the existing environment-free `validate` job depends on it, so the whole
graph below inherits the gate without any other `needs` edge changing.

`release.yml` is the deliberate exception and has no such gate: it runs from a
`v*` tag by design. What stands in for the gate there is the ancestry proof — the
tagged commit must be contained in `main`, checked fail-closed before anything is
built — which is what keeps `v*` from being an independent entrance to production.

### Staging has the same gate, and the same gap

The `staging` Environment restricts no refs, so the exposure is the same shape:
dispatching a staging operation from an arbitrary branch would run **that
branch's** YAML with staging's bootstrap and deploy credentials. Five workflows
therefore carry the same gate admitting `refs/heads/develop` —
`prepare-staging-host`, `repair-staging`, `rollback-staging`, `restore-staging`
and `recover-staging`.

`deploy-staging` deliberately has none: it takes a `ref` input, and deploying an
operator-selected ref to staging is the entire point of it.

**And the same limitation applies, more plainly here.** A gate written in a
workflow binds only refs whose workflow contains it, so dispatching a ref from
*before* the gate existed runs that ref's unguarded job with the Environment. No
check inside the YAML can prevent that — a job condition, a first failing step, a
reusable workflow called with `uses:` all live at the selected ref.

What closes it is the same thing that closes it for production: restricting the
Environment's allowed refs, so GitHub refuses the run before any job starts
whatever the selected ref says. In *Settings → Environments → staging →
Deployment branches and tags*, choose **Selected branches and tags** and add two
rules — the branch `develop`, and the tag pattern `v*`.

**Both rules, and the tag one is not optional.** `release.yml` triggers on `v*`,
and its staging verification job runs with `environment: staging` — so a
`develop`-only restriction would refuse every release at the point where the
artifact is verified on staging, before it could ever reach production. This is
the same shape as the production Environment, and for the same reason: the
operational workflows run from a branch, the release path runs from a tag, and
both need to reach their Environment.

Until the restriction is set, treat the staging gates as guard rails — they make
the ordinary mistake impossible, and they are not a boundary.

### What the gate is, and is not

Worth stating precisely, because an in-workflow check is easy to over-read.

It stops **accidental and casual misuse**: a tag selected in the *Run workflow*
dropdown, the habit of dispatching from `develop`, a copied URL with the wrong
ref. That is the realistic failure, and it is now impossible.

It is **not** a boundary against someone who can already create an arbitrary
trusted tag. That person would author the gate itself at that tag, so no check
living in the workflow can bind them. The boundary for that case is **who may
create `v*`**, which is a GitHub tag ruleset and a separate permission surface:

* protect `v*` with a repository ruleset, so creating one is at least as
  privileged as merging to `main`;
* and create a `v*` tag only on a commit already contained in `main` — which
  `release.yml` additionally proves for itself, so a tag off `main` cannot deploy
  even if it is created.

The two together are the real shape of this: the repository gate makes the common
mistake impossible, and the tag ruleset is what makes the privileged case
privileged. Neither is claimed to do the other's job.

## Why staging-main and tits-guru are separate targets

They share the RateGuru codebase and nothing else. Each has its own application
root, Linux users, database and role, PHP-FPM pool and socket, queue worker and
queue name, scheduler entry, Nginx site, public hostnames, and backup namespace
with its own retention.

Modelling them as one "production" slot with swapped values would mean every
operation implicitly acts on whichever set of values happens to be configured —
exactly the ambiguity the target model exists to avoid. Separate targets make
the instance an explicit argument.

## Registry location

| Role | Path |
|------|------|
| Source of truth (committed) | `infrastructure/config/deployment-targets.json` |
| Installed runtime location | `/home/www/rateguru/config/deployment-targets.json` |

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

Everything that differs *per instance* belongs in the registry instead —
application root, Linux users and groups, database name, backup namespace and
retention, public hostnames, and every other target-specific value. The two
files are also different kinds of thing: `deployment.conf` is shell that gets
sourced; the registry is data that is only ever parsed.

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

`tits-guru` is **structurally provisioned**: its directories, Linux identities,
PHP-FPM pool, Supervisor program, scheduler entry and internal Nginx site now
exist on the host. It is still `lifecycle=planned`, and that is not a
contradiction — lifecycle is permission to OPERATE a target, not a description
of how much of it exists.

What it still does not have is deliberate and separately owned: no deploy sudo
authorization, no application release, no public hostname, no TLS certificate,
no DNS record, and no mail transport. The declaration was written before
provisioning so the plan could be reviewed and collision-checked in advance, and
being provisioned is not what makes a target deployable — being activated is.

Two independent things prevent that:

- the `active`-allowlist above keeps `tits-guru` at `planned` in validation;
- every operational script calls `require_active_target` before touching a
  URL, the filesystem, a database, or a lock — a target that is not
  `lifecycle: active` is rejected before any of that, naming its lifecycle in
  the error.

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

Secrets do not move into the registry:

| Secret | Location |
|--------|----------|
| Database password, `APP_KEY`, SMTP credentials, Sentry DSN | per-target `.env`, deployed out of band, never committed |
| SSH deploy keys | `~/.ssh/authorized_keys` of each deploy user |
| B2 / rclone credentials | `/root/.config/rclone/rclone.conf` |
| Basic Auth passwords | `/etc/nginx/*.htpasswd` |

The registry names *which* database a target uses. It never says how to
authenticate to it.

### The environment contract

Each target declares an `environment_template` — the committed file that says
**which keys** its runtime `shared/.env` must carry. Every target's template
declares the same keys in the same order, enforced in CI, so the two differ only
in environment-specific values and can be diffed against each other.

The runtime file is canonical on the host and nothing ever writes it. That is
correct, and it is exactly why it can fall behind: a reviewed change adds a key
to the templates, and the host file does not know. So the key names are compared
before anything happens:

```bash
verify-environment-contract --target TARGET_ID
```

Read-only, for any target in any lifecycle. **Key names only** — no value is
read, printed, hashed, measured or compared, and the file is read rather than
sourced, because it is operator-authored root-owned material. Keys the template
does not declare are reported and allowed; a key declared twice fails, because
which value is in force is then unreadable. Nothing ever writes the runtime file:
the value of a key an operator has not supplied is the operator's to decide, and
an operation that invented one would make the drift permanent and invisible.

#### Whose contract applies, and when

There are three templates in play and they can legitimately disagree. Which one
decides depends on what is being asked.

| Operation | Contract it is judged against | Why |
|---|---|---|
| **Ordinary deploy** | the template inside the **candidate artifact** | the artifact is the code that will run, so its requirements are the ones that matter |
| **Configure Target** | the **repository** template of the trusted checkout | configuring prepares a host for the code that checkout describes; no release has been deployed yet |
| `verify-environment-contract` | the **installed** template under `/home/www/rateguru/config/environment/` | a standalone host-side question: does this host satisfy the contract its installed tooling declares? |
| **Restore / recovery alignment** | none — deliberately exempt | see below |

**An ordinary deploy must not be judged by the installed template**, and this is
the trap worth stating plainly. `install-target-operations` is not part of an
application deployment, so a host's operational bundle can be older than the
application being deployed onto it. A host with 70 keys installed, an artifact
expecting 71 and a `.env` carrying 70 would compare 70 against 70, pass, and
install an application that cannot read the key it needs. So deploy reads the
contract out of the artifact: its own target registry, then the template that
registry declares — after the lock is held, the checksum is verified and unsafe
tar paths are rejected, and before the history row, any extraction, any migration
and the `current` switch. A refusal leaves no release directory and no history.

An artifact built before this feature declares nothing and deploys exactly as it
always did. An artifact that *declares* a contract it cannot produce fails
closed, with no fallback to the installed template.

So a new key travels a closed route:

```text
config/ starts reading a new setting
  -> CI fails: no record in environment-contract.json
  -> the key is classified once, target or excluded
  -> if target: render-environment-templates --write regenerates EVERY target
     template, so no second file has to be remembered
  -> it ships inside every artifact built from that point on
  -> the next ordinary deploy refuses on any host whose .env lacks it
  -> an operator adds it deliberately, and only then does the deploy proceed
```

#### Restore and recovery are exempt, on purpose

A controlled alignment — `--restore-operation` or `--recovery-operation` — puts
an **exact historical release** beside historical, restored state. The backup's
`environment.env` belongs to the source SHA it was taken from, and that release
may legitimately predate keys that exist today.

Enforcing today's contract there would make a valid disaster recovery
impossible, so it is not enforced. What those modes preserve is the
backup↔code contract: the artifact must be the exact commit the backup names,
which their own authorization and state guards already prove, strictly. The
environment contract is enforced at the next **ordinary promotion** of that
target — before it is moved forward, not while it is being put back.

The exemption is logged rather than silent, and the log says where the contract
is applied instead.

#### Two protections, and they fail differently

The gate above protects a **host** from an incomplete `.env`. It is only ever as
good as the template it compares against — and says nothing about a template that
has quietly forgotten a variable the application reads. That happened: a staging
deploy was correctly refused for a missing `MEDIA_PUBLIC_DISK`, and the template
had not been carrying it either, so nothing before the refusal had noticed.

So there are two independent checks, and it is worth knowing which one a failure
is:

```text
completeness   config/*.php  ->  target template or explicit policy  ->  CI
host drift     candidate template  ->  live shared/.env  ->  deploy / configure
```

**Completeness** is enforced in CI, by
`tests/Feature/Architecture/EnvironmentContractCompletenessTest.php`. Every
environment variable `config/*.php` reads must be accounted for by exactly one
record in the canonical contract, classified `target` or `excluded`. Adding an
`env()` and deciding nothing is the failure — and the inventory is closed in both
directions, so an exception nobody needs any more fails too.

#### One source, and generated templates

The target templates are **generated files**, not hand-maintained sources:

```text
infrastructure/config/environment-contract.json      the one reviewed source
            + infrastructure/config/deployment-targets.json
                          ↓
      infrastructure/scripts/render-environment-templates --write
                          ↓
   infrastructure/templates/environment/{staging,tits-guru}.env.example
```

The contract holds every key, its section and order, its classification, the
value each target renders it with, and the reason for every exclusion. Before
this, "every target declares the same keys" was a rule somebody had to remember:
add a key to staging, remember production, remember the next brand. A rule
enforced by memory is a rule that fails the week it matters, so the templates
agree by construction now.

The developer flow is therefore:

```text
someone adds env('NEW_SETTING') to config/
  -> CI fails: no record in the contract
  -> add it once, classified target or excluded
  -> if target: render-environment-templates --write
  -> BOTH templates change; CI verifies the output byte for byte
  -> the artifact carries the contract; the host deploy refuses until an
     operator deliberately updates shared/.env
```

Values come in four forms deliberately — a contract, not a templating language:
a literal shared by every target, `{"from": "target_id"}`,
`{"from": "environment_class"}`, and `{"by_environment_class": {...}}` for the
few settings that genuinely differ (staging's mail capture against production's
placeholders). A target's id and class are never written twice: they come from
the registry, which stays the single place a target is defined.

**The renderer can only write repository templates.** It takes no destination,
derives every path from the registry, and is deliberately absent from the
operational bundle — a target's `shared/.env` is canonical on its host and
operator-owned, and a generator able to write one would be a way for tooling to
invent a credential nobody chose. `--check` writes nothing anywhere.

Keys carrying a credential are declared and left **blank**, which CI asserts:
an honest default would be a committed secret.

The contract's categories exist so that "completeness" cannot be read as a reason
to require credentials for infrastructure this deployment does not use: Laravel's
generic configuration references AWS, Postmark, Resend, Slack, Memcached, SQS and
more, and none belongs in a target's contract merely because the framework
supports it. Each excluded key is classified individually — there are no wildcard
exceptions, so a future key cannot hide under one.

Two config files read `env()` through a local closure that normalizes a blank
value to unset. The key is still a literal at every call site, but the `env()`
call itself is not, so those closures are declared in the contract by name. Any
*other* dynamic `env()` fails the guard rather than escaping the inventory.

`.env.example` is deliberately **not** held to the same list: it serves local
development and legitimately carries local, build and provider settings a
deployed target has no use for. What it must not do is omit a setting the
application reads.

#### A separate gap, not solved here

The installed templates under `/home/www/rateguru/config/environment/` exist for
standalone inspection and are **not** what an ordinary deploy decides by. They
are installed by `install-target-operations` alongside the standalone validator.

That leaves a broader problem this section does not solve: an application deploy
executes `/home/www/rateguru/bin/deploy`, whose implementation may lag the
repository's. We have seen it happen — an installed deploy skipped a translation
backfill a release expected, because the installed script predated it. Reading
the environment contract from the artifact closes that one class of drift and
nothing more. RateGuru still needs an **operational-bundle compatibility
contract**, so a host can refuse to run a deployment its tooling is too old to
perform correctly. That is a dedicated follow-up, required before production
activation.

## Validation

```bash
infrastructure/scripts/targets validate --file infrastructure/config/deployment-targets.json
infrastructure/scripts/targets list     --file infrastructure/config/deployment-targets.json
infrastructure/scripts/targets show --target staging-main --file infrastructure/config/deployment-targets.json
```

`validate` reports every problem it finds, not just the first, and exits
non-zero if any remain.

`release_retention`, `backup.local_retention_days` and
`backup.offsite_retention_days` must be strict JSON *integers* (a numeric
string or a float is rejected) of at least 1. `backup.minimum_retained_backups`
is required on every target and must be a strict JSON integer of at least
**2**: age-based backup retention must never be able to reduce the backup
count below this minimum, so a registry that even permits fewer than two
retained backups is invalid.

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

The two have drifted before: the registry's own `code_group` value for
`staging-main` did not match the group actually owning release files on the
host, because the group name that was supposed to match it lived in a
different, since-retired configuration file that had silently fallen out of
sync. Repository parity was green throughout, because both sides of that
now-defunct comparison were wrong together — CI never reaches the real host,
so nothing there could catch it.

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
its own code, which is exactly the separation the model exists to keep.

Re-run this on the VPS before a target is used for a real deployment, and after
any change to users or groups. Since all per-target values now live only in the
installed registry, comparing it against the live host is a direct check —
there is no second, independent config file to cross-reference against it
anymore. Confirming that `/home/www/rateguru/config/deployment.conf` itself
carries none of these fields (only `RELEASE_ID_REGEX`, `PHP_BIN`,
`PHP_FPM_SERVICE` belong there) is worth doing alongside this, precisely
because a second, drifting copy of a per-target value is the failure mode
this whole section exists to catch:

```bash
echo "Installed deployment.conf (host-global only):"
cat /home/www/rateguru/config/deployment.conf

echo
echo "Installed registry, staging-main accounts:"
jq -r '.targets["staging-main"] | "runtime_user=\(.runtime_user) code_group=\(.code_group) deploy_user=\(.deploy_user)"' \
    /home/www/rateguru/config/deployment-targets.json

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
| `runtime_user` | the PHP-FPM pool's `user =` |
| `deploy_user` | the owner of the current release |
| `code_group` | the group of the current release |

Any mismatch means the registry describes a host that does not exist. Fix the
registry to match reality — a green test suite is not evidence here, since
nothing in CI can reach the real host.

### Repository versus runtime checks

Validating the committed file requires no special ownership — CI runs as an
ordinary user.

When the path being validated is the installed runtime default, validation
additionally requires the file to be a regular file, not a symlink, owned
`root:root`, and neither group- nor world-writable — the same protection
`deployment.conf` already gets. A symlink is refused everywhere, because it lets
the validated path and the read path diverge.

## The `--target` interface

`health-check`, `status`, `cleanup`, `deploy`, `rollback`, `backup`,
`restore-test`, `offsite-backup`, `offsite-retention`, `offsite-restore-test`
and `backup-cycle` all accept exactly one selector, `--target TARGET_ID`, and
nothing else identifies which instance to act on:

```bash
health-check --target TARGET_ID
status --target TARGET_ID
cleanup --target TARGET_ID [--keep NUMBER] [--dry-run|--apply]
deploy --target TARGET_ID --release RELEASE_ID --artifact PATH [--checksum PATH] [--migrate]
rollback --target TARGET_ID (--release RELEASE_ID | --previous)
backup --target TARGET_ID
restore-test --target TARGET_ID
offsite-backup --target TARGET_ID
offsite-retention --target TARGET_ID [--apply]
offsite-restore-test --target TARGET_ID
backup-cycle --target TARGET_ID
```

A missing, duplicate, empty, or flag-shaped `--target` value is rejected, and
`--help` documents the exact form for each command. This is true end to end:
every real caller — the sudo wrappers, cron, the GitHub Actions deploy
workflow — also resolves through this same interface; see
[Perimeter: wrappers, sudoers, cron and GitHub Actions](#perimeter-wrappers-sudoers-cron-and-github-actions)
below.

### Only active targets may be operated on

Every entry point calls `require_active_target TARGET` before doing anything
else: it validates the target ID, validates the whole registry, confirms the
target exists, and confirms `lifecycle == active` — all before a URL is built,
`curl` runs, or an application path is touched. `staging-main` is the only
target with `lifecycle: active`; `tits-guru` stays `planned` and is rejected
with a message naming its lifecycle. This is a promise, not an implementation
detail: `targets validate` continues to reject `lifecycle: active` on any
target other than `staging-main` (see [Validation](#validation) above), so a
target cannot become operable by mistake — flipping it requires a reviewed
registry change.

### tits-guru is still not deployable

`tits-guru` now has its directories, users, socket, queue worker, cron entry and
internal Nginx site, and it is still rejected at `lifecycle=planned`. That is
the point: rejecting it on lifecycle rather than on whether its parts happen to
exist is exactly what keeps a *provisioned* target from being mistaken for a
*deployable* one. Nothing about provisioning, or about configuring, moves that
gate — only a reviewed registry change does.

## Read-only operations: health-check and status

```bash
health-check --target staging-main
status --target staging-main
```

Neither command mutates anything. `require_active_target` runs before a URL
is built or `curl` runs, so `tits-guru` is rejected cleanly. `status` reports
the target's own header (`Target:`, `Lifecycle:`, `Environment class:`), then
four sections in order — `Releases`, `Current release metadata`, `Health`,
`Recent deployment history` — ending with `Status: healthy` when everything
it checked passed.

## Target-aware cleanup

`cleanup --target TARGET_ID [--keep NUMBER] [--dry-run|--apply]`. Omitting both
`--dry-run` and `--apply` performs a dry run — this is the default; `--dry-run`
is a readable, explicit alias for it. `--apply` is required to actually delete
anything. Default retention, when `--keep` is not given, comes from the
target's own `release_retention` field in the registry
(`target_release_retention`) — never a host-level default, since retention is
a property of the instance, not the host.

`require_active_target` gates `cleanup` exactly as it does `health-check`/
`status`: a planned target (`tits-guru`) is rejected — clearly naming its
lifecycle — before any lock is acquired, any path is scanned, or
`pinned-releases`/history is touched.

**Release retention is not backup retention.** `release_retention` counts
deployed release *directories*, with `current`/`previous` (and pinned
releases) always protected regardless of the number. The `backup` family has
its own, independent policies (`local_retention_days`,
`offsite_retention_days`, `minimum_retained_backups`) — see
[`backups.md`](backups.md), "Three retention concepts". Changing one never
affects the others.

### Dry-run is genuinely side-effect free

Dry-run acquires no lock, creates no lock file, never creates or modifies
`pinned-releases` (a missing one is treated as empty), never appends
deployment history, and never invokes `rm`. Retention, protection
(current/previous/pinned releases are never candidates), and
candidate-selection semantics are otherwise unaffected.

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

`pinned-releases` (like `deployments/history.jsonl` and the deployment lock
file) is owned `root:root 0640`: when apply mode creates a missing
`pinned-releases`, it installs it that way — the same contract already
enforced for `deployment.conf` and the target registry — via a single
`install -o root -g root -m 0640` call, never a bare `touch`+`chmod`. A
pre-existing `pinned-releases` file, valid or not, is never touched — content,
mode, and mtime all survive a `cleanup --apply` run byte-for-byte.

## Deploy

`deploy --target TARGET_ID --release RELEASE_ID --artifact PATH [--checksum PATH] [--migrate]`.

### Root authorization runs first, unconditionally

`deploy` runs privileged filesystem operations (writing release directories,
changing ownership, switching the `current` symlink) on every invocation.
`require_root` is the first substantive action, before any argument parsing.
Only after root authorization succeeds does `require_active_target` run —
immediately, before artifact/checksum canonicalization, the
artifact-existence check, the incoming directory, the target filesystem root,
the deployment lock, or any mutation. So the full ordering is: root
authorization → `lifecycle=planned` rejection → (only if active) artifact/
filesystem validation. `tits-guru` is rejected at the lifecycle gate before any
artifact path is ever touched.

### The deployment pipeline

Selector resolution (`resolve_target`) populates the application root, runtime
user, deploy user, code group, incoming-artifacts directory, canonical public
hostname (`target_primary_public_hostname`), and the health-check target to
use after the switch, all from the registry (`target_*` helpers). Everything
after resolution — checksum verification, unsafe-path rejection, the
disk-space check, extraction, symlinks, ownership/permission normalization,
`verify-required-clis`, Laravel cache preparation, optional migrations,
`rateguru:sharing:verify`, the atomic `current` switch, PHP-FPM reload,
health-check, automatic recovery of `current`/`previous` on failure,
deployment history, and queue restart — is preserved exactly as before this
model existed: release ID validation, artifact/checksum containment within the
incoming directory, SHA-256 verification, unsafe tar path rejection, the
disk-space check, the shared deployment lock (the same one `cleanup`/
`rollback` use), immutable release directories, the temporary extraction
directory, `.env`/`storage`/`public/storage` symlinks, ownership/permission
normalization, and everything downstream of it.

### Project translation backfill

After Laravel preparation — so after any `--migrate`, and against the new
release's schema — every normal deployment runs
`php artisan rateguru:translations:backfill` as the runtime user, before the
`current` switch. The database owns a project's content; the backfill only
lends it what the release ships and the database lacks (a language a release
adds, for content the project set up from a preset or the static page
defaults), and only where the project still shows the text the repository
translated. It never creates a category, tag, group or option, never
overwrites a translation — not even one the release now translates
differently — and never deletes one. The one thing it creates is a built-in
static page the project has no text for at all, such as a page the release
adds. Running it again does nothing, which is what makes it safe on every
deployment; its report line ends the step:

```text
backfilling repository-known project translations
Translation backfill: filled 0, already present 812, skipped as customized 3, skipped as unknown 9.
```

`filled 0` is a normal, successful run.

- **Historical releases.** `deploy` deploys older releases too, and they
  predate the command. It asks the release (`artisan list --raw`) instead of
  assuming: a release without the command logs that it is skipping the
  backfill and the deployment continues. A release that has the command and
  fails it fails preparation, like any other preparation step.
- **Restore and recovery alignments.** `--restore-operation` and
  `--recovery-operation` never run it: they install the exact code the
  restored data belongs to and change nothing else.
- **Rollback.** Nothing to undo. The backfill only adds language entries to
  translation JSON and missing static pages, which the previous release reads
  past, so a deployment that fails its health check and returns `current` to
  the previous release leaves a consistent database behind.

The rules for what it may fill are in `docs/i18n/project-translation-lifecycle.md`.

## Rollback

`rollback --target TARGET_ID (--release RELEASE_ID | --previous)`. Exactly one
rollback destination is required: `--release RELEASE_ID` or `--previous` — the
two together are rejected as an ambiguous invocation.

### Root authorization runs first, unconditionally

Like `deploy`, `rollback` runs privileged filesystem operations (switching the
`current`/`previous` symlinks) on every invocation. `require_root` is the
first substantive action, before any argument parsing. Only after root
authorization succeeds does `require_active_target` run — immediately, before
`target_root`, before the `current`/`previous` symlinks are read, before the
releases directory or the deployment lock is touched, before history is
written, before `systemctl` or health-check runs, and before any mutation.
`tits-guru` is rejected at the lifecycle gate before any release path is ever
touched.

### The rollback pipeline

Selector resolution (`resolve_target`) populates the target's filesystem root
(`target_root`) and the health-check target to use. Everything after
resolution — the shared deployment lock, requiring `current` to exist,
capturing the original `current`/`previous`, choosing the explicit release or
`previous`, refusing a rollback onto the already-current release, history, the
atomic `previous`/`current` switch, PHP-FPM reload, post-switch health-check,
automatic restoration of the original `current`/`previous` on failure, and
cleanup of temporary symlinks — is one single pipeline.

### Release path safety

Every release path this script reads as the current release, the previous
release, or an explicit `--release` target is validated, fail-closed, before
any symlink is switched: it must exist, be a real directory, not be a symlink
itself, be a direct child of the releases root, have a basename that passes
release ID validation, and — after `readlink -f` — resolve to exactly itself.
`current`/`previous` themselves remain ordinary symlinks, as always; what is
refused is an unsafe *target* of that symlink — escaping the releases root, a
nested path, a release directory that is itself a symlink, an invalid release
ID, or a missing target.

### Rolling back from the GitHub UI

Two operator-facing workflows wrap the exact same server-side pipeline for
operators without SSH access — one per environment, so nobody ever has to
select a target:

| Workflow | Deployment target | GitHub Environment | Concurrency group |
|---|---|---|---|
| **Rollback staging** (`.github/workflows/rollback-staging.yml`) | `staging-main` | `staging` | `rateguru-staging-deployment` |
| **Rollback tits.guru** (`.github/workflows/rollback-production.yml`) | `tits-guru` | `production-tits-guru` | `rateguru-production-release` |

Both are thin — a checkout and one action call each: the target and the
environment are hard-coded in the workflow and cannot be chosen at dispatch
time, and both call the single shared `.github/actions/rollback-rateguru`
composite action, which owns input validation, SSH material, the wrapper
invocation, the active-release read-back, the Sentry deployment marker and the
run summary. No rollback business logic exists in GitHub.

1. GitHub → **Actions** → **Rollback staging** (or **Rollback tits.guru**)
   → **Run workflow**.
2. Leave `mode` at `previous` (the default) to switch the target back to the
   previous release. `release-id` must stay empty in this mode.
3. To roll back to a specific release instead: set `mode` to `release` and
   enter the release ID in `release-id`. The workflow only checks that the
   field is non-empty — the server-side `rollback` script remains the single
   source of truth for release ID format, existence, and every safety rule
   above (lock, atomic switch, health check with automatic restore).

Both workflows are `workflow_dispatch`-only, take the same `DEPLOY_*`
variables and secrets from their own GitHub Environment, share their
environment's deployment concurrency group (a rollback never runs concurrently
with a deploy to the same target — it queues, nothing is cancelled; see
"Which operations serialize" below), and
execute exactly `sudo -n /usr/local/sbin/rateguru-rollback --target TARGET_ID
--previous|--release ID` over hardened SSH. An invalid input combination fails
before any SSH connection; a non-zero remote exit fails the job. The step
summary records the target, environment, mode, requested release and the
release the target ended up serving.

### Which operations serialize

Every GitHub operation that mutates a target sits in the concurrency group its
target belongs to, so two of them queue rather than race:

| Operation | Target | Concurrency group |
|---|---|---|
| `deploy-staging.yml` (whole workflow) | `staging-main` | `rateguru-staging-deployment` |
| `rollback-staging.yml` (whole workflow) | `staging-main` | `rateguru-staging-deployment` |
| `release.yml` → `deploy-staging` (job-level) | `staging-main` | `rateguru-staging-deployment` |
| `release.yml` (whole workflow) | `tits-guru` | `rateguru-production-release` |
| `rollback-production.yml` (whole workflow) | `tits-guru` | `rateguru-production-release` |

`release.yml` carries both: the workflow-level `rateguru-production-release`
group keeps a whole release serialized against a production rollback, and its
`deploy-staging` job additionally joins `rateguru-staging-deployment` because
that job mutates `staging-main`. Without the job-level group, a manual staging
deploy or rollback could overlap a release's staging verification.

None of this replaces the server-side deployment lock in
`infrastructure/scripts/common`, which is what actually protects a target's
integrity. GitHub concurrency exists so one workflow does not fail merely
because another already holds that lock. `cancel-in-progress` is `false`
everywhere: a deployment in flight is never cancelled.

**Rollback tits.guru fails closed today.** `tits-guru` is still
`lifecycle=planned`. That gate is enforced server-side by the wrapper, so the
workflow stops with an explicit diagnostic instead of touching anything —
whatever its GitHub Environment happens to hold. Its credentials, approvals and
protection rules live in `production-tits-guru`, the environment named per
target rather than per class; the environment *class* that reaches the server
stays `production`. Neither the workflow nor the shared action weakens the
lifecycle gate to make itself pass.

## Local backup and restore-test

`backup --target TARGET_ID` and `restore-test --target TARGET_ID`. Both scripts
require root unconditionally, as the first action of every invocation, matching
`deploy`/`rollback`'s exact contract. In target mode, `require_active_target`
runs immediately after root authorization — before the backup root, lock,
database binary, `rclone`, or any filesystem work — so a planned target
(`tits-guru`) is rejected before anything is touched.

Both scripts resolve `DATABASE_NAME`/`BACKUP_NAMESPACE`/`RETENTION_DAYS`/
`MINIMUM_RETAINED_BACKUPS` from the registry (`target_database_name`,
`target_backup_namespace`, `target_local_backup_retention`,
`target_minimum_retained_backups`). Local retention is deterministic and
count-aware — newest-first by directory name, the newest
`minimum_retained_backups` always kept regardless of age, only
`YYYYMMDD-HHMMSS` directories ever considered, and it runs only after a fully
successful, atomically finalized backup (see
[`backups.md`](backups.md)). `staging-main`'s namespace is `staging`, and
its backup root and lock file are unchanged from before the registry existed:

```text
backup namespace = staging
backup root      = /home/www/rateguru/backups/staging
lock             = /home/www/rateguru/run/backup-staging.lock
```

### Manifest: schema 3, backward compatible with schema 1 and 2

Every backup carries a `manifest_schema_version: 3` manifest recording
`target`, `environment`, and `backup_namespace` alongside the pre-existing
fields, and — the one thing schema 3 adds — the eighth backup file,
`recovery-material.tar.gz`, the target's host-scope external prerequisites by
logical name (see [`backups.md`](backups.md)). `restore-test` requires
`project`/`environment`/`database` always, `backup_namespace` and a matching
`target` for schema 2 and 3, and a safe recovery material archive for schema
3. A schema 1 backup — everything produced before the registry-based model
existed, with none of the new fields — and a schema 2 backup remain fully
restorable onto a live target; only a schema 3 backup can recover a clean
host. Manifest validation, like checksum and storage-archive validation,
always completes before the temporary database is created.

### Target-specific server configuration snapshot

The server-configuration archive contains only the target's own Nginx site,
PHP-FPM pool, Supervisor unit, cron entry, and deploy account's
`authorized_keys` — never another target's.

## Target-aware offsite backup path

`offsite-backup --target TARGET_ID`, `offsite-retention --target TARGET_ID
[--apply]`, and `offsite-restore-test --target TARGET_ID`. `offsite-backup`
and `offsite-restore-test` require root unconditionally, as the first action
of every invocation. `offsite-retention` requires root unconditionally too —
its dry-run mode simply never acts on that privilege.

All three resolve `BACKUP_NAMESPACE`/`ENVIRONMENT_CLASS` from the registry
(`target_backup_namespace`, `target_environment_class`), and
`offsite-retention` additionally reads `target_offsite_backup_retention`.
`staging-main`'s remote root and locks are unchanged from before the registry
existed:

```text
backup namespace = staging
remote root       = rateguru-b2:rateguru-database-backups/rateguru/staging
lock (offsite-backup)        = /home/www/rateguru/run/offsite-backup-staging.lock
lock (offsite-retention)     = /home/www/rateguru/run/offsite-retention-staging.lock
lock (offsite-restore-test)  = /home/www/rateguru/run/offsite-restore-test-staging.lock
```

### Manifest validation reuses the same strict schema contract

`offsite-backup` validates the local backup's manifest before ever calling
`rclone`; `offsite-restore-test` validates the downloaded manifest before
`createdb` — both using the identical strict, type-based
`manifest_schema_version` classification `restore-test` uses: absent or JSON
`null` is schema 1; a JSON *number* equal to `2` is schema 2 and a JSON
*number* equal to `3` is schema 3 (both additionally checking
`backup_namespace` and a non-null manifest `target`); any other value — `4`,
`0`, the JSON *string* `"2"` or `"3"`, an array, an object, a boolean — is
rejected outright, before any mutation, with `unsupported backup manifest
schema_version: ...` naming the offending value. The classifier
(`manifest_schema_classify`) and the closed per-schema file sets are shared,
in `common`, between `offsite-backup`, `offsite-restore-test` and the restore
primitives; local `restore-test` keeps its own, contractually identical
inline classifier. Neither offsite script has an independently resolved
database name to compare against (unlike local `restore-test`) — `database` is
only required to be present and non-empty.

### Retention: side-effect-free dry-run, locked and re-listed apply

`offsite-retention`'s dry-run (the default, no `--apply`) is genuinely
side-effect free: no lock is acquired, and `rclone purge` is never invoked in
any form, not even with rclone's own `--dry-run` flag. Candidates are computed
purely from a read-only `rclone lsf` listing.

`--apply` lists remote backups twice: an unlocked preview pass (purely for
operator visibility, never used to decide what gets purged), then the lock is
acquired and the listing and candidate computation both run again, fresh —
protecting against a backup uploaded concurrently between the two passes.
`rclone purge` only ever acts on the second, locked computation. The newest
`minimum_retained_backups` backups are always protected, regardless of age
(`KEEP minimum`); backups beyond the minimum are protected while inside the
resolved retention window (`KEEP recent`); only backups past the cutoff *and*
outside the protected minimum become candidates. Dry-run and apply share this
single candidate algorithm.

## Target-aware backup cycle

`backup-cycle --target TARGET_ID`. `require_root` is the first action of
`main()`, then `parse_backup_cycle_args`, then `resolve_backup_cycle_subject`
(where `require_active_target` runs immediately, before the cycle lock, the
history file, or any child command), then `perform_backup_cycle`. `tits-guru`
is rejected with `lifecycle=planned` before any of those are ever touched.

`staging-main`'s namespace, cycle lock and history file are unchanged from
before the registry existed:

```text
backup namespace = staging
cycle lock        = /home/www/rateguru/run/backup-cycle-staging.lock
history file      = /home/www/rateguru/backups/backup-cycles.jsonl
```

### The five-step pipeline is strictly sequential and fail-fast

```text
1. backup
2. restore-test
3. offsite-backup
4. offsite-retention --apply
5. offsite-restore-test
```

Every step receives the exact target the cycle itself was given, and its real
stdout/stderr passes straight through, unsuppressed. Each step only runs if
the previous one exited `0`. The first non-zero exit stops the cycle
immediately: no later step runs, the cycle's own exit code is that child's
exit code unmodified, and `offsite-retention --apply` in particular is only
ever reached once local backup, local restore-test and the offsite upload have
all already succeeded — old B2 backups are never purged on the strength of a
local backup or upload that did not actually happen. If retention itself
succeeds but the subsequent offsite restore-test fails, the cycle is still
reported failed; the retention deletion is not rolled back. `backup-cycle`
does not add local backup retention — `backup` keeps its own, unchanged local
pruning.

### Cycle history: one compact JSON record per started cycle

Every cycle that gets past the lock appends exactly one `jq -cn`-generated,
compact-JSON line to `/home/www/rateguru/backups/backup-cycles.jsonl` (`0600`,
inside a `0700` root-owned directory that is shared with every namespace). A
single `printf ... >>` is one `write(2)` call, atomic up to `PIPE_BUF` on an
`O_APPEND`-opened file, which is what keeps two different namespaces'
concurrent cycles from interleaving their records — the per-namespace lock
only serializes writers within the same namespace.

A success record carries `completed_steps` (all five, in order) and
`failed_step: null`, and never carries an `exit_code` field at all. A failure
record's `completed_steps` lists only the steps that actually finished, plus
`failed_step` and the failing child's own `exit_code`. A `lifecycle=planned`
rejection or lock contention writes no history record — only a cycle that
genuinely started, past the lock, ever gets one. A history write failure after
a fully successful pipeline still turns the cycle into a reported failure; a
history write failure on the failure path is logged but never replaces the
original child's own exit code.

## Perimeter: wrappers, sudoers, cron and GitHub Actions

Every real staging operation goes through target-based invocation, end to end:

```text
GitHub Actions -> SSH -> sudo wrapper -> deploy --target staging-main
cron            -> backup-cycle/restore-test/offsite-restore-test --target staging-main
```

Three generic, target-aware sudo wrappers —
`infrastructure/config/wrappers/rateguru-{deploy,rollback,cleanup}`, installed
at `/usr/local/sbin/rateguru-{deploy,rollback,cleanup}` (`root:root 0755`) —
are the only way `deploy`/`rollback`/`cleanup` are invoked through `sudo`. Each
generic wrapper:

- requires root (it is only ever reached via `sudo`, or by real root directly
  for server administration);
- accepts exactly one selector, `--target TARGET_ID` — a missing/duplicate/
  empty/flag-shaped `--target`, and any lone short flag other than `-h`/
  `--help`, are all rejected before anything else runs;
- authorizes the caller: when invoked through `sudo`, `SUDO_USER` must exactly
  equal the target's own registered `deploy_user` from the registry, or the
  caller is rejected before the underlying operation is ever reached; a direct
  invocation by real root (no `SUDO_USER`, or `SUDO_USER=root`) is always
  permitted;
- calls `require_active_target` before the underlying operation, so a planned
  target (`tits-guru`) is rejected the same way every other target-aware
  command already rejects it — before any operation-specific argument,
  filesystem path, lock, or database is touched;
- execs into the real, unchanged `deploy`/`rollback`/`cleanup` binary at its
  installed path (`/home/www/rateguru/bin/deploy`, `.../rollback`,
  `.../cleanup`) with `--target TARGET_ID` prepended exactly once, followed by
  every other argument the caller gave, untouched and in order;
- scrubs its own process environment before that exec: `env -i` with only
  `PATH`, `HOME=/root`, `USER=root`, `LOGNAME=root` — every `RATEGURU_*` test
  override and any other caller-supplied variable is stripped unconditionally;
- uses only Bash arrays and `exec` — never `eval`, never `bash -c`, never a
  string-built command.

`infrastructure/config/sudoers/rateguru-deploy` grants
`deploy-rateguru-staging` `NOPASSWD` access to the four generic wrappers, and
nothing else. No rule exists for `tits-guru`'s own deploy user: that account
exists now, and what withholds its grant is `lifecycle=planned`, not the
account being absent. The file is rendered from the registry, so a target's
perimeter appears when it is activated and never before.

`.github/actions/deploy-rateguru/action.yml` has a required
`deployment-target` input, validated locally
(`^[a-z0-9]+(-[a-z0-9]+)*$` — rejecting empty, uppercase, slash, whitespace,
shell metacharacters, and a flag-shaped value) before any SSH connection is
made. The remote deploy command is `sudo -n "${DEPLOY_WRAPPER}" --target
"${DEPLOYMENT_TARGET}" --release ... --artifact ... --checksum ...
[--migrate]`, built entirely through `printf -v ... %q` — never string
concatenation of an unquoted value. `.github/workflows/deploy-staging.yml`
passes `deployment-target: staging-main` explicitly; the GitHub Environment
`staging` (the approval/secrets boundary) and the
`rateguru-staging-deployment` concurrency group are both unrelated GitHub
concepts, not renamed or affected by this model.

`infrastructure/config/cron/rateguru-backups` calls all three operational
commands — the nightly `backup-cycle`, and the weekly `restore-test` /
`offsite-restore-test` — with `--target`, for every `lifecycle=active` target.
Like the sudoers rule it is rendered: the registry decides which targets are
scheduled, and `infrastructure/config/backup-schedules.json` holds each
target's reviewed times. Today that is `staging-main` alone; `tits-guru`'s
schedule is reviewed, and its jobs appear when it is activated.

See [`target-perimeter.md`](target-perimeter.md) for the full installer
contract (`install-target-perimeter`) that manages the three wrappers, the
sudoers rule and the cron file, and for how it removes any leftover legacy
per-environment wrapper files.

## History

The registry-based, target-only model replaced an earlier interface that
identified a deployment by an environment class (`staging` or `production`)
directly, with no separate instance identity. That interface was migrated off
script by script, over several reviewed increments — registry foundation,
read-only operations, cleanup, deploy, rollback, the backup family, and
finally the perimeter (sudo wrappers, sudoers, cron, the GitHub Actions deploy
workflow) — with `staging-main` parity proven end to end and accepted on the
real staging VPS at each step along the way. The legacy per-environment
selector, its supporting helper functions, its per-environment `deployment.conf`
constants, and its temporary per-environment sudo wrappers have since been
removed entirely; `--target TARGET_ID` is the only interface anywhere, and
`tests/Feature/Architecture/LegacyEnvironmentRemovalTest.php` guards against
any of it reappearing.

Physical values the old interface used to name — paths, database names,
backup namespaces, deploy account names — were preserved verbatim as
`staging-main`'s own registry values throughout; nothing physical was ever
renamed. Backup format and history compatibility (schema 1 manifests,
existing JSONL history files) is preserved unchanged and stays readable
regardless of which interface originally wrote it.

See `infrastructure/ROADMAP.md` (Phase 4) for the full, step-by-step history
of this migration, including what was verified on the real staging VPS at
each increment.

## Adding a target

1. Add the object to `infrastructure/config/deployment-targets.json` with
   `lifecycle: "planned"`.
2. Run `targets validate` and fix every reported problem.
3. Add the target's reviewed mail routing policy to
   `infrastructure/config/mail-routing.json` — its own loopback submission
   port, and `held` for a production target — and run
   `infrastructure/scripts/mail-routing validate`, which refuses a registry
   target without one. See [`mail-routing.md`](mail-routing.md).
4. Provision the real infrastructure in its own reviewed change.
5. Flip to `active` and extend the validation allowlist in the same change.
   A production target's mail must have a real route by then: `mail-routing
   validate` refuses an active target whose mail is still `held`.

Never flip a target to `active` before step 4.
