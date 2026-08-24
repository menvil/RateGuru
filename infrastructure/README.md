# RateGuru infrastructure

Project-specific infrastructure for RateGuru.

One slice is an exception: the staging mail capture is owned by the shared
staging environment rather than by RateGuru (its hostnames, systemd units,
system users and state directories are named `staging-*`). It is committed here
because this repository is the temporary source of truth for staging
infrastructure, and moves out once a second project exists.

## Contents

- clean-VPS bootstrap preflight (read-only host contract inspection) and the
  base/runtime package installer (Ubuntu 22.04 baseline, PHP 8.5, PostgreSQL
  18; Node.js/Composer intentionally absent — GitHub Actions builds the
  immutable artifact; rclone managed as a verified, pinned external runtime
  binary rather than an Ubuntu package) — see
  [`runbooks/bootstrap-host.md`](runbooks/bootstrap-host.md);
- deployment and rollback scripts;
- local and offsite backup scripts;
- shared staging mail capture (Mailpit + Mailtrap Local) — see
  [`runbooks/mail-capture.md`](runbooks/mail-capture.md);
- Nginx configuration;
- PHP-FPM pools;
- Supervisor queue workers;
- cron configuration;
- sudoers and SSH restrictions;
- environment variable templates;
- operational runbooks;
- the phased [`ROADMAP.md`](ROADMAP.md).

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
