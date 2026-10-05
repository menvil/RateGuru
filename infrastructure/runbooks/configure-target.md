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

GitHub is **not** a copy of it and is never asked to resend it, and Configure
**requires it to already be there**. An absent `shared/.env` is a refusal naming
the path to create — not something Configure seeds, installs or overwrites, on a
first run or any other. A configure run that could bring the file into existence
would be a second way for the most sensitive file on the host to be written,
competing with the recovery path that restores it from a backup.

So the order is: create the file on the host, then configure.

```bash
# on the host, once, as root
install -o rateguru-tits-guru -g rateguru-tits-guru -m 0640 /dev/null \
    /home/www/rateguru/production/tits-guru/shared/.env
# then populate it from infrastructure/templates/environment/tits-guru.env.example
```

Its ownership, mode and metadata are then validated by
`install-target-prerequisites`, which is the authority on what a correct one
looks like. Configure only requires that there is something to validate.

This is not a new model. It is how staging already works.

## What Configure does send: the deploy public key

Exactly one piece of material reaches the host, and it is not a secret: the
target's deploy **public** key, installed as the deploy user's
`authorized_keys`.

It has to come from somewhere. Provisioning deliberately created the deploy
account without an `authorized_keys`, and nothing else in the pipeline installs
one — so without this step the target would stay permanently unreachable.

It is **derived, not pasted**. The action runs `ssh-keygen -y` against
`DEPLOY_SSH_KEY` on the GitHub runner and uploads only the public half; the
private key never leaves the runner and is deleted as soon as the public half
exists. Deriving it is what keeps the deploy identity single-sourced — a
separately pasted public key is a second source of truth that silently stops
matching the day the private key is rotated.

`--material-dir` therefore carries `deploy-authorized-keys` and nothing else. A
material directory containing `laravel-env` is refused by name.

## What it does not do

* no host bootstrap, and it refuses rather than becoming one;
* no deploy sudo authorization — the target stays undeployable through the
  existing wrappers even with its key in place;
* no lifecycle change, and the registry is never written;
* no TLS, no public `server_name`, no DNS;
* no mail transport or gateway;
* no deployment, no release, no `current`/`previous`, no migration;
* no queue worker started;
* no backup schedule, and no offsite credential — not its rotation, and not its
  creation either. `rclone-config` is root's own host-global Backblaze
  credential, shared by every target on the machine; it is an ordinary
  target-scope row for an active target whose backups need it, and under
  `--provisioning` it is not derived at all. A target that is not operating yet
  has no backup schedule to serve, and the narrowest operation on the host must
  not be the one that can create its broadest secret. An absent credential stays
  a prerequisite for the operation that owns it; an existing one is untouched
  either way.

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

That gate is not circular, although it looks circular at first: this operation requires
the canonical `shared/.env` to already exist, so by the time it runs the target is
past the provisioning phase. `provision-target --verify` is monotonic about
exactly that — it asks whether the structure is still provisioned, and an `.env`
does not un-provision it. `--apply` and `--check` *are* phase-bounded and do refuse
once the file exists, which is correct and is not what is called here. See
[provision-target.md](provision-target.md) for the three modes side by side.

The machine is claimed for the whole run through the shared host-infrastructure
lock: targets share a host, and a configuration that overlapped a preparation or
a repair of the same machine would be two operations converging one host.

## From GitHub

`Configure tits.guru` (`.github/workflows/configure-tits-guru.yml`) is the
operator surface: manual, no inputs, pinned to one target, tooling always from
`main` — the production control plane; see
[Branches](deployment-targets.md#branches-which-ref-is-trusted-for-what). It
uses the privileged **bootstrap** credential, never a deployment
key — creating a role and a database needs root, and the deploy key reaches only
the narrow wrappers.

It reads its credentials from the target's own GitHub Environment,
`production-tits-guru`, which must hold `DEPLOY_HOST`, `DEPLOY_PORT` and
`BOOTSTRAP_USER` as variables and `BOOTSTRAP_SSH_KEY`, `BOOTSTRAP_KNOWN_HOSTS`
and `DEPLOY_SSH_KEY` as secrets. That is a box of credentials, and it is not
the same thing as the environment **class**, which stays `production`.

`DEPLOY_SSH_KEY` is the one credential used for something other than connecting:
it is read on the runner so `ssh-keygen -y` can derive the public half that
becomes the target's `authorized_keys`. It is never used to connect from here
and never uploaded. The two credentials stay separate — creating a role and a
database needs root, and the deploy key reaches only the narrow wrappers.

## After configuring

The target can now run, and still cannot be reached. What remains, in order:

1. the deploy authorization and the production GitHub deployment credentials;
2. the mail gateway and the backup/offsite/retention policy;
3. TLS and the real public routing;
4. activation — a reviewed registry change — and then the first real deploy.
