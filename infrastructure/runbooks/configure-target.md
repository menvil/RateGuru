# Configure Target

`infrastructure/scripts/configure-target` gives **one already-provisioned,
still-planned production target** the three things it needs to be able to run,
and nothing else.

```
configure-target --check  --target TARGET_ID [--material-dir DIR]
configure-target --apply  --target TARGET_ID [--material-dir DIR]
configure-target --verify --target TARGET_ID
```

All three modes require root, and every mode refuses a target that is not
`lifecycle=planned` **and** `environment_class=production`.

## Where it sits

| Operation | Owns |
| --- | --- |
| **Provision Target** | structural, non-secret infrastructure |
| **Configure Target** | that target's own material and its database |
| **Activate Target** | a separate, reviewed registry change |

Provisioning leaves a target deliberately empty: directories, identities and
service configuration, no environment file, no database, no deploy key. This is
what fills those in — and the target is **still planned afterwards**, because
lifecycle is permission to *operate* a target, not a description of how much of
it exists.

## What it does

* **environment file** — accepted and validated where it already is;
* **deploy `authorized_keys`** — the same;
* **PostgreSQL role and database** — created if absent, from the registry's own
  `database.name` and `database.application_role`, using the credentials in the
  target's own environment file.

Each is delegated to the installer that owns it, under the explicit
`--provisioning` authorization:

```
install-target-prerequisites --apply --scope target --provisioning --target T
install-target-database      --apply --provisioning --target T
```

There is no second implementation of any of it here, and this file never opens
the environment file: it causes it to be used, and never reads a credential.

## Where the environment file lives, and who owns it

**The target's `shared/.env` is canonical on the host.** An operator creates it
there once, as `runtime_user:runtime_group` mode `0640`. From that moment it is
the sole runtime source of truth: backups carry it as `environment.env`, and a
recovery restores it from the selected backup.

GitHub is **not** a copy of it and is never asked to resend it. The Configure
workflow therefore carries no material input at all — not an environment file,
not `authorized_keys`, not a database password.

An existing file is accepted as it stands and **never overwritten**. Supplied
material can only ever *seed an absent file*; a file that already exists and
differs is a refusal, because rotating a credential is a separate deliberate
operation with its own review.

This is not a new model. It is how staging already works.

## What it does not do

* no host bootstrap, and it refuses rather than becoming one;
* no deploy sudo authorization — the target stays undeployable through the
  existing wrappers even with its key in place;
* no lifecycle change, and the registry is never written;
* no TLS, no public `server_name`, no DNS;
* no mail transport or gateway;
* no deployment, no release, no `current`/`previous`, no migration;
* no queue worker started;
* no backup schedule, and no offsite credential rotation.

Each appears in the operation's own report as a `DEFERRED` item, so a reader can
tell "not yet, and here is who owns it" from "forgotten".

## State after a successful run

```
TARGET CONFIGURATION: CONFIGURED
LIFECYCLE: planned
ENVIRONMENT: INSTALLED
DATABASE: READY
DEPLOY KEY: INSTALLED
DEPLOY AUTHORIZATION: NOT GRANTED
APPLICATION: NOT DEPLOYED
PUBLIC TRAFFIC: NOT ACTIVATED
```

A target that has just been given secrets and a database is exactly when
somebody assumes it is ready. It is not: it has no deploy authorization, no
release, no backups and no public presence.

A terminal success also prints exactly one machine-readable line:

```
RATEGURU_CONFIGURE_RESULT={"status":"target-configured","target":"…","lifecycle":"planned", …}
```

It carries identity and state only — never a secret, a path inside a private
tree, or a hash of either.

## Prerequisites

The structural contract is proved by the operation that owns it:

```
provision-target --verify --target TARGET_ID
```

A target that is not provisioned is refused **before any mutation**, because
material is installed *into* directories and accounts provisioning creates, and
a database is created *for* a runtime user it creates.

The machine is claimed for the whole run through the shared host-infrastructure
lock: targets share a host, and a configuration that overlapped a preparation or
a repair of the same machine would be two operations converging one host.

## From GitHub

`Configure tits.guru` (`.github/workflows/configure-tits-guru.yml`) is the
operator surface: manual, no inputs, pinned to one target, tooling always from
`develop`. It uses the privileged **bootstrap** credential, never a deployment
key — creating a role and a database needs root, and the deploy key reaches only
the narrow wrappers.

It reads its credentials from the target's own GitHub Environment,
`production-tits-guru`, which must hold `DEPLOY_HOST`, `DEPLOY_PORT` and
`BOOTSTRAP_USER` as variables and `BOOTSTRAP_SSH_KEY` and
`BOOTSTRAP_KNOWN_HOSTS` as secrets. That is a box of credentials, and it is not
the same thing as the environment **class**, which stays `production`.

## After configuring

The target can now run, and still cannot be reached. What remains, in order:

1. the deploy authorization and the production GitHub deployment credentials;
2. the mail gateway and the backup/offsite/retention policy;
3. TLS and the real public routing;
4. activation — a reviewed registry change — and then the first real deploy.
