# Provision Target

`infrastructure/scripts/provision-target` creates the **non-secret
infrastructure of one planned production target** on a host that is already a
RateGuru host.

```
provision-target --check  --target TARGET_ID
provision-target --apply  --target TARGET_ID
provision-target --verify --target TARGET_ID
provision-target --help
```

All three working modes require root.

## What it does

```
PLANNED PRODUCTION TARGET + ALREADY BOOTSTRAPPED HOST
  -> target-scoped, non-secret infrastructure created
  -> the target is STILL lifecycle=planned
  -> no application is deployed
  -> no public traffic is accepted
```

Concretely, for the one target named on the command line:

* **identities** — the runtime group, the code group, the deploy user's own
  private group, the runtime user, the deploy user, and the two required code
  group memberships (the runtime user, and `www-data`, both of which must read
  immutable release code without owning it);
* **filesystem** — the deploy home and its structural `.ssh` directory, the
  incoming artifacts directory, the root-owned application root, and
  `releases`, `shared`, `shared/storage`, `shared/storage/logs`, `locks` and
  `deployments`, each with the exact owner, group and mode the staging model
  already proved;
* **PHP-FPM** — a pool named by the registry, running as the registry runtime
  user, listening on the registry socket;
* **Nginx** — an **internal-only** vhost whose `server_name` is the registry's
  `nginx.internal_hostname`, answering on loopback only;
* **Supervisor** — the target's queue program configuration, validated and
  installed; the worker itself is deliberately **not started**;
* **scheduler** — the target's `cron.d` entry, running as the runtime user
  inside `<application_root>/current`;
* **public storage access** — the same narrow `user:www-data:--x` traversal
  ACL on `shared` and `shared/storage` that every target gets, granted by
  `install-public-storage-access` and nothing else.

## What it does not do

None of the following happens here, and each has its own operation:

* no production `.env` and no `APP_KEY`;
* no PostgreSQL database and no application role — no credential is even read;
* no `authorized_keys` content and no deploy sudo grant, so the target stays
  undeployable through the existing wrappers;
* no `rclone.conf`, no offsite credentials, no backup, no backup schedule;
* no TLS certificate, no Certbot, no public `server_name`, no redirect, no DNS;
* no mail transport, gateway or domain authentication;
* no deployment, no release, no `current`, no `previous`, no migration;
* no queue worker started;
* no change to the target registry, and therefore no lifecycle change.

Each of these appears in the operation's own report as a `DEFERRED` item, so a
reader can tell "not yet, and here is who owns it" from "forgotten".

## State after a successful run

```
TARGET INFRASTRUCTURE: PROVISIONED
LIFECYCLE: planned
APPLICATION: NOT DEPLOYED
PUBLIC TRAFFIC: NOT ACTIVATED
SECRETS: DEFERRED
DATABASE: DEFERRED
QUEUE: DEFERRED
```

This is deliberately **not** called "production ready", because it is not: the
target has no environment file, no database, no deploy authorization, no
backups and no public presence. Saying otherwise in a success line would be the
most dangerous sentence this operation could print.

A terminal success also prints exactly one machine-readable line:

```
RATEGURU_PROVISION_RESULT={"status":"infrastructure-provisioned","target":"…","lifecycle":"planned","environment_class":"production","application_state":"not-deployed","public_state":"not-activated"}
```

It carries identity and state only — never a secret, a credential, a hash of
one, or a path inside a private tree.

## Which operation to reach for

| Situation | Operation |
| --- | --- |
| A new **planned production target** on an existing host | **Provision Target** |
| A clean or new **whole host** | [Prepare Host](prepare-host.md) |
| An **active** target whose own infrastructure drifted | [Repair Target](repair-target.md) |
| The application code should change | `deploy` / `rollback` |
| The **whole server** is gone | [Recover Host](recover-host.md) |
| A target's **data** is wrong | [Restore Target](restore-target.md) |

## Lifecycle: why a provisioned target is still `planned`

Lifecycle records **permission to operate** a target, not whether its
directories exist. So a target whose infrastructure is complete is still
`lifecycle=planned` until someone deliberately changes the registry and that
change is reviewed on its own merits.

Nothing in this operation writes the registry, and `--apply` proves the target
is still `planned` at the end rather than assuming it — the case worth catching
is precisely a target that became deployable while nobody was looking.

Every mode refuses anything that is not a planned production target, and each
refusal is separate because the right next action differs:

* `lifecycle=active` — already in the operational lifecycle; use Prepare Host,
  Repair Target or Deploy;
* `lifecycle=disabled` — a deliberate registry state, not something to build
  around;
* a **planned staging** target — provisioning exists for a new production
  brand; staging is created by the host bootstrap;
* unknown — a typo, or a host whose installed registry predates the target.

## Host prerequisites

Provisioning runs on a host that is **already** a RateGuru host. It is not a
bootstrap, and it refuses rather than quietly becoming one. Every prerequisite
is answered by the mechanism that already owns it, never re-implemented here:

| Prerequisite | Owner |
| --- | --- |
| Supported OS, repositories, packages, binaries, PHP modules | `install-bootstrap-runtime --verify` |
| The RateGuru host roots and the package-created accounts | `install-bootstrap-host-layout`, as `HOST-REQ` in its own report |
| nginx / PHP-FPM / Supervisor / PostgreSQL / Redis enabled and running | `install-bootstrap-services`, as `HOST-REQ` in its own report |
| The installed operational bundle and its runtime registry | structural: this script sources the installed `common` and resolves the target through it |

When something is a host problem, the run refuses with

> this is a host prerequisite, not this target's: repair or prepare the host
> first

and prints the specific items the owning installer reported, so there is
nothing to guess at.

Provisioning never installs a runtime, never rewrites host-global SSH policy,
never touches mail capture, never reinstalls the operations bundle or the sudo
perimeter, and never starts a base service that happens to be down.

## New-target safety

Before any mutation, the run proves the target does not already look like an
application target somebody is using.

**Allowed, and both converge:**

* the target is entirely absent;
* the target was partly created by an earlier provisioning attempt that failed.
  A rerun after a failed attempt is the normal recovery path — a failed attempt
  leaves safely converged pieces converged, and a rerun resumes.

**Refused, before the first mutation:**

* `current` exists;
* `previous` exists;
* `releases` already contains an entry;
* `shared/.env` exists — the environment file is never created by
  infrastructure provisioning, so its presence means something else owns this
  target.

Nothing is ever deleted, moved or re-owned to make a target look new. There is
no `rm -rf` anywhere in this operation, no recursive `chown` or `chmod`, and no
"let me just move that aside". Unknown existing state fails closed.

## Where a production target's configuration comes from

A production target's Nginx vhost, PHP-FPM pool, Supervisor program and
scheduler cron are **rendered generically from
`infrastructure/config/deployment-targets.json`**. There is no committed
per-brand file, and no list of known brands anywhere in the installer — a
second, third or tenth production brand needs a registry entry and nothing
else.

Registry values are **data** in that rendering, never shell. Nothing is
`eval`'d, nothing is sourced, no `envsubst` runs, and every value that reaches
a rendered file has already been proved to match a closed format: by `targets
validate` at the registry boundary, and again by the installer immediately
before it renders. A value that cannot be rendered safely fails closed instead
of being escaped into submission.

The rendering is deterministic: the same registry renders the same bytes on
every host and in every mode. That is what makes provisioning a planned target
and later verifying it as an active one produce no drift — a byte of difference
between the two would make a correctly provisioned target report as damaged the
moment it was activated.

Two deliberate omissions in the rendered Nginx vhost:

* `public_hostnames` is **not** used;
* there is no TLS listener and no certificate path.

Provisioning a target must not be able to put it on the public internet, and
the way to guarantee that is for the renderer to have no notion of a public
hostname at all.

`environment_class=staging` keeps its existing committed sources, byte for
byte. That configuration is accepted on a real host, and migrating it for
symmetry would buy nothing and cost a second staging acceptance.

### The old shared-production config

`infrastructure/config/nginx/rateguru-production` describes the **old shared
production-root model** — one `/home/www/rateguru/production` tree for every
brand — which the target registry replaced. It is never the service authority
for a production target: sources are resolved by the target's own registry
names, and a production target's files are rendered rather than read.

It is still committed because `install-target-operations` installs it as part
of the operational bundle and verifies it byte for byte, and
`install-target-prerequisites` parses the installed copy when it builds a
host's external-prerequisite table. Removing it would break the recovery
prerequisite machinery, which is a different operation with its own acceptance.
Its final public/TLS role is normalized when the real public vhost is defined.

Regression coverage keeps it from silently becoming a fallback: neither
`provision-target` nor `install-bootstrap-services` may reference it.

## The queue worker, before the first release

The Supervisor program configuration is installed and validated
(`supervisorctl reread` parses it without applying anything), and the program
is deliberately **not** added to the running supervisor: a `PRE_DEPLOY` target
has no `current` for it to run in. The first real deployment activates it
through the existing deploy mechanism.

The installed configuration keeps `autostart=true`, exactly as the accepted
staging program has it — it is never rewritten into a fake shape. The residual
window is the documented one every target has had since the services slice: a
supervisord restart or host reboot **before** the first deployment would read
that configuration and fail-loop the program until a release exists. It closes
with the first deploy.

## Modes

**`--check`** — strictly read-only. Reports the target's identity and
lifecycle, the host prerequisites, the state of this target's infrastructure,
the mutations an `--apply` would make, and every deferred later-phase item.
Creates nothing, not even a lock file. Exit 0 **only** when this target's
infrastructure is already provisioned.

**`--apply`** — builds the whole plan first and refuses as a whole before the
first mutation if anything is unsafe, then converges through the authoritative
installers, verifies through them again, and proves the lifecycle is unchanged.
Convergent: a second `--apply` on a correct target performs zero meaningful
mutation and says `CHANGED: FALSE`.

A failed attempt is never destructively rolled back at the identity level:
accounts and groups are persistent host state, and a rerun resumes rather than
starting over. Service files are transactional and are restored by the owning
installer if their own parser rejects the candidate.

**`--verify`** — read-only, and the authoritative gate. Exit 0 only when the
infrastructure is provisioned, the target is still planned, no application is
deployed and no public traffic is activated.

## Running it

From a trusted infrastructure checkout on the host, as root:

```bash
cd /path/to/rateguru
./infrastructure/scripts/provision-target --check  --target TARGET_ID
./infrastructure/scripts/provision-target --apply  --target TARGET_ID
./infrastructure/scripts/provision-target --verify --target TARGET_ID
```

The installers it delegates to are resolved relative to the script itself, so a
run always uses one bundle's logic rather than a mixture of two.

## From GitHub

`.github/actions/provision-rateguru-target` is a reusable composite action that
transports a trusted bundle to the host over strict-host-key-checked SSH with
the privileged **bootstrap** credential (never a deployment key), runs
`provision-target --check`, `--apply` and `--verify`, reads the one
machine-readable result line, and removes the bundle on success and on failure
alike.

It is transport and invocation only: it accepts no environment file, no
database password, no deploy key, no rclone config, no TLS material, no mail
secret, no DNS token, no artifact, no source ref, no release and no migration
flag. Every decision belongs to the server-side primitive.

There is deliberately **no operator-facing workflow yet**: this slice ships the
reusable action and the server primitive, not a button that already changes a
production server.

## After provisioning

The target now has infrastructure and nothing else. What remains, in order:

1. the production environment file, the database and its role, the deploy
   authorization and the production GitHub credentials;
2. the production mail gateway and the backup/offsite/retention policy;
3. TLS and the real public routing;
4. the first real production deploy, rollback and operations acceptance.

Until those land, the target has no secrets, no data and no public presence —
which is exactly what a freshly provisioned target should be.
