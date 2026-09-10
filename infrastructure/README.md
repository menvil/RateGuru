# RateGuru infrastructure

Project-specific infrastructure for RateGuru.

One slice is an exception: the staging mail capture is owned by the shared
staging environment rather than by RateGuru (its hostnames, systemd units,
system users and state directories are named `staging-*`). It is committed here
because this repository is the temporary source of truth for staging
infrastructure, and moves out once a second project exists.

## Contents

- host bootstrap: `infrastructure/scripts/bootstrap-host` — the one
  canonical entry point that sequences the bootstrap slices (runtime →
  identities/filesystem → services/configuration → final preflight) on a
  clean or existing host, run by root from the repository checkout — see
  [`runbooks/bootstrap-host.md`](runbooks/bootstrap-host.md);
- clean-VPS bootstrap preflight (read-only host contract inspection), the
  base/runtime package installer (Ubuntu 22.04 baseline, PHP 8.5, PostgreSQL
  18; Node.js/Composer intentionally absent — GitHub Actions builds the
  immutable artifact; rclone managed as a verified, pinned external runtime
  binary rather than an Ubuntu package), and the users/groups/filesystem
  bootstrap installer (`install-bootstrap-host-layout` — per-target deploy/
  runtime identities, code-group membership, and the root-owned namespace
  plus setgid release/shared trees, active targets only) — see
  [`runbooks/bootstrap-host.md`](runbooks/bootstrap-host.md);
- the services/configuration bootstrap installer
  (`install-bootstrap-services` — coordinates the target operations,
  perimeter, public-storage-ACL and mail-capture installers as
  authoritative owners, and directly owns the active-target Nginx/PHP-FPM/
  Supervisor/cron files plus the host-global SSH restriction; PRE_DEPLOY
  vs DEPLOYED aware, external secrets never generated) — see
  [`runbooks/bootstrap-services.md`](runbooks/bootstrap-services.md);
- host preparation: `infrastructure/scripts/prepare-host` — one resumable
  operation that turns a clean supported VPS into infrastructure ready to
  host one target, orchestrating `bootstrap-host` together with the external
  material installer (`install-target-prerequisites`, which delivers
  operator-supplied secrets and never generates or overwrites any) and the
  target database installer (`install-target-database`, which never drops,
  recreates, migrates or rotates anything). It deploys no application, and a
  prepared target legitimately has no release. Given an exact offsite backup
  (`--recovery-backup`), it prepares a clean replacement machine FROM that
  backup instead: `fetch-recovery-material` takes the environment file and
  every host-scope prerequisite out of the backup's own recovery material,
  and the host-global offsite-write hold is placed before the offsite
  credential exists — see
  [`runbooks/prepare-host.md`](runbooks/prepare-host.md);
- deployment and rollback scripts;
- backend observability: Sentry error/performance monitoring correlated to the
  canonical release ID, the deployment target and the Git commit, with the
  deployment marker recorded only after the existing health checks pass — see
  [`runbooks/sentry-observability.md`](runbooks/sentry-observability.md);
- the Phase 6B Laravel Nightwatch evaluation: a Supervisor-managed local agent
  on staging-main only, installed and removed by
  `scripts/install-nightwatch-agent`, running side by side with Sentry so the
  two can be compared on real traffic before either is chosen; the same
  installer owns the narrow server-side deployment-marker primitive
  (`scripts/record-nightwatch-deployment`) that gives Nightwatch the same
  deploy/rollback timeline Sentry has — see
  [`runbooks/nightwatch-evaluation.md`](runbooks/nightwatch-evaluation.md);
- local and offsite backup scripts;
- target data restore: `infrastructure/scripts/restore-target` and the four
  primitives it drives (`fetch-backup`, `verify-backup`, `restore-database`,
  `restore-storage`) — a live target's database and storage restored from one
  exact, fully re-verified backup, staged entirely before any downtime, with a
  verified emergency pre-restore backup taken first and a compensating undo for
  every live step. It restores data only: never `.env`, never server
  configuration, never a release switch, and never a migration — see
  [`runbooks/restore-target.md`](runbooks/restore-target.md);
- operator-facing restore from GitHub: the `Restore staging` and
  `Restore production` workflows, the shared
  `.github/actions/restore-rateguru` transport and the generic
  `/usr/local/sbin/rateguru-restore` server wrapper. When the chosen backup's
  data belongs to an older commit, the same pipeline builds THAT exact commit,
  deploys it through the ordinary `deploy` in its controlled-alignment mode —
  which keeps the target held, runs no migration and resumes nothing — and
  then lets `restore-target --resume` be the only thing that brings the target
  back. There is no target dropdown and no commit input: the operator chooses
  a backup, and the server decides the commit — see
  [`runbooks/github-restore.md`](runbooks/github-restore.md);
- target-scoped repair: `infrastructure/scripts/repair-target`, the optional
  `--target` mode both bootstrap installers gained, the
  `.github/actions/repair-rateguru-target` transport and the `Repair staging
  target` / `Repair production target` workflows. When the host is healthy and
  only one target's own infrastructure has drifted, it converges that target
  back onto what is committed and proves afterwards that the release, the
  rollback pointer, `shared/.env` and the shared-storage structure are
  unchanged. It is orchestration only — every convergence is delegated to the
  installer that owns the contract — and it carries no secret material at all,
  runs no migration and never touches the database or the code — see
  [`runbooks/repair-target.md`](runbooks/repair-target.md);
- new-target provisioning: `infrastructure/scripts/provision-target`, the
  `--provisioning` authorization both bootstrap installers and
  `install-public-storage-access` gained, and the reusable
  `.github/actions/provision-rateguru-target` transport. On a host that is
  already a RateGuru host, it creates the non-secret infrastructure of ONE
  `lifecycle=planned`, `environment_class=production` target — identities,
  filesystem, PHP-FPM pool, an internal-only Nginx vhost, the Supervisor queue
  program, the scheduler cron and the public-storage ACL — and stops there. A
  production target's service configuration is RENDERED generically from
  `deployment-targets.json`, so a second or tenth brand needs a registry entry
  and no committed per-brand file. The target stays `planned`, no application
  is deployed, no queue worker is started, and no environment file, database,
  deploy authorization, TLS, DNS, mail or backup is created — each of those is
  a later operation, and each is named as `DEFERRED` in the report rather than
  silently absent — see
  [`runbooks/provision-target.md`](runbooks/provision-target.md);
- host recovery: `infrastructure/scripts/recover-host` rebuilds one lost target
  onto a prepared, empty replacement machine from one exact offsite backup, and
  leaves the machine deliberately not serving until the exact commit that
  backup names has been deployed — see
  [`runbooks/recover-host.md`](runbooks/recover-host.md);
- operator-facing recovery from GitHub: the `Recover staging host` and
  `Recover production host` workflows. The operator names the REPLACEMENT
  machine and one exact offsite backup — a schema 3 backup, the format that
  carries the target's recovery material — and nothing else: no `PREPARE_*`
  secret is read and no file is copied by hand. The workflow derives the
  deploy public key on the runner, prepares that machine from the backup,
  recovers the data onto it, builds the exact commit the SERVER read out of
  the backup, deploys it through the ordinary `deploy` in its
  controlled-recovery mode — which keeps the host held and runs no migration
  — then lets `recover-host --resume` end the hold and `recover-host --verify`
  decide whether it worked. The replacement machine is refused if it is the
  machine the target is currently bound to; its offsite writers (backup cron,
  uploader, pruner) stay held until it is deliberately adopted; and nothing
  on the current machine is touched, repointed or cut over — see
  [`runbooks/github-recover.md`](runbooks/github-recover.md). The operator
  runbook for the whole thing — what to prepare on a new VPS, what to
  configure, how to run, verify and continue — is
  [`runbooks/clean-host-recovery.md`](runbooks/clean-host-recovery.md), and
  `infrastructure/scripts/recovery-host-preflight` is the read-only proof,
  run by the workflow before Prepare Host, that the replacement machine is a
  supported and genuinely clean host (`--operator-guide` prints the compact
  instruction);
- shared staging mail capture (Mailpit + Mailtrap Local) — see
  [`runbooks/mail-capture.md`](runbooks/mail-capture.md);
- Nginx configuration;
- PHP-FPM pools;
- Supervisor queue workers;
- cron configuration;
- sudoers and SSH restrictions;
- environment variable templates;
- operational runbooks;
- the phased [`ROADMAP.md`](ROADMAP.md) — Phase 5 (clean-VPS bootstrap) and
  Phase 7 (disaster recovery and release rehearsal) are completed, the latter
  accepted on a real replacement machine on both the uninterrupted and the
  interrupted recovery path; Phase 6 (Sentry observability) is current and
  Phase 8 (first production launch) is next. The roadmap records every slice,
  the three distinct rehearsal gates and the disposable-rehearsal policy —
  including which parts of disaster recovery were accepted for real and which
  are covered by automated tests only.

## Committed non-secret config exception

`infrastructure/**/*.env` is gitignored by default so secret env files are
never committed. Three files are explicitly re-included because they are
non-secret:

- `config/mail-capture/versions.env` — pinned upstream release versions only;
- `config/mail-capture/mailpit.env` — loopback-only bind addresses, retention,
  and the loopback relay target;
- `config/external-runtimes/versions.env` — the pinned rclone release, its
  install contract and the official release-signing key fingerprint (the
  matching public key is committed next to it as
  `config/external-runtimes/rclone-release-signing-key.asc`).

Ubuntu packages are OS/runtime dependencies; rclone is a verified, pinned
external runtime binary managed by `install-bootstrap-runtime`. Exact external
versions are intentionally pinned and only ever move through an explicit
repository change.

## Secrets are not stored here

Never commit:

- real `.env` files;
- PostgreSQL passwords;
- private SSH keys;
- `authorized_keys`;
- Backblaze credentials;
- `rclone.conf`;
- Basic Auth password files;
- PostgreSQL dumps;
- uploaded media.

## Deployment configuration

Install the non-secret deployment configuration before installing or running
the scripts that source `scripts/common`:

```bash
sudo install -d -o root -g root -m 0755 /home/www/rateguru/config
sudo install -o root -g root -m 0640 \
    infrastructure/templates/deployment.conf.example \
    /home/www/rateguru/config/deployment.conf
```

The runtime configuration must be a regular file owned by root:root and must
not be writable by group or others. Modes such as `0600`, `0640`, and `0644`
are accepted.
