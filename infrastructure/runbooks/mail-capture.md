# RateGuru staging mail capture

Loopback-only staging mail capture for RateGuru. Mail sent by the staging
Laravel app is captured locally and never delivered to real inboxes.

- **Mailpit** — the canonical capture service.
- **Mailtrap Local** — a secondary, experimental mirror.

This slice adds **no** production SMTP delivery, inbound mail, DKIM/SPF/DMARC,
bounce processing, support mailboxes, Postfix, Docker, or any public SMTP
listener.

## Design and mirror semantics

```
              staging Laravel (MAIL_HOST=127.0.0.1 MAIL_PORT=1025)
                                 │  SMTP
                                 ▼
              ┌───────────────────────────────────┐
              │ Mailpit (canonical)               │
              │   SMTP  127.0.0.1:1025            │
              │   HTTP  127.0.0.1:8025            │
              │   SQLite /var/lib/.../mailpit     │
              └───────────────┬───────────────────┘
             stores local copy│ then best-effort relay-all
                              ▼  SMTP 127.0.0.1:3535
              ┌───────────────────────────────────┐
              │ Mailtrap Local (mirror)           │
              │   SMTP  127.0.0.1:3535            │
              │   HTTP  127.0.0.1:3550            │
              │   SQLite /var/lib/.../mailtrap-local │
              └───────────────────────────────────┘
```

Guarantees:

- **Mailpit always stores the canonical local copy.** Storage happens
  regardless of the relay outcome.
- **Mailtrap Local receives a best-effort mirrored copy** via Mailpit
  relay-all (`MP_SMTP_RELAY_ALL=true`, target `127.0.0.1:3535`).
- **A Mailtrap Local failure never fails Laravel SMTP delivery.** Mailpit is
  configured *without* `MP_SMTP_RELAY_FWD_SMTP_ERRORS`, so a relay error is
  logged to journald but is **not** returned to the upstream SMTP client.
- **A Mailtrap Local failure never stops Mailpit.** The systemd unit uses
  `Wants=` (not `Requires=`) for `rateguru-mailtrap-local.service`.
- Mailtrap Local is independent and never depends on Mailpit.

## DNS and TLS prerequisites

Two DNS records must point at the staging host before requesting certificates:

- `mailpit.rateguru.staging.myprojects.pp.ua`
- `mailtrap.rateguru.staging.myprojects.pp.ua`

Certificates are Certbot-managed, matching the primary staging vhost. The
committed vhosts are HTTPS-only and reference
`/etc/letsencrypt/live/<host>/fullchain.pem` and `privkey.pem`, so the
certificates must exist **before** `install-mail-capture --apply` runs
`nginx -t` (otherwise the config test fails and apply rolls back).

Provision the certificates first, then apply:

```bash
# Obtain certs before the HTTPS vhosts are active (standalone briefly binds :80).
sudo certbot certonly --standalone \
    -d mailpit.rateguru.staging.myprojects.pp.ua \
    -d mailtrap.rateguru.staging.myprojects.pp.ua

sudo infrastructure/scripts/install-mail-capture --apply
```

`options-ssl-nginx.conf` and `ssl-dhparams.pem` are provided by the existing
Certbot install (same as the primary staging vhost). Renewals are handled by
the existing Certbot timer; `certbot renew` reloads Nginx automatically. Both
hosts reuse `/etc/nginx/rateguru-staging.htpasswd` for Basic Auth, so no new
password file is required.

## Installation

The installer is idempotent and has two modes.

```bash
# Validate committed config, pinned versions, checksums, architecture.
# Read-only: no downloads, users, files, or service changes.
sudo infrastructure/scripts/install-mail-capture --check

# Install + activate. Downloads pinned, checksum-verified binaries.
sudo infrastructure/scripts/install-mail-capture --apply
```

What `--apply` does:

1. requires root and a supported Linux architecture (amd64 / arm64);
2. validates committed configuration and pinned checksums;
3. downloads the pinned release archives and verifies their SHA-256 against
   `config/mail-capture/checksums/`;
4. creates the `rateguru-mailpit` / `rateguru-mailtrap-local` system users and
   the state directories idempotently;
5. installs binaries atomically to `/usr/local/bin/`;
6. installs env/config to `/etc/rateguru/mail-capture/`;
7. installs the systemd units and Nginx vhosts (backing up any replaced file
   under `/var/backups/rateguru-mail-capture/<timestamp>/`);
8. runs `systemd-analyze verify` and `nginx -t`;
9. `daemon-reload`s only when a unit changed and restarts only changed
   services (mirror first, then Mailpit);
10. rolls the installed files back if apply fails before commit.

Installed layout:

| Path | Purpose |
|------|---------|
| `/usr/local/bin/rateguru-mailpit` | Mailpit binary |
| `/usr/local/bin/rateguru-mailtrap-local` | Mailtrap Local binary |
| `/etc/rateguru/mail-capture/mailpit.env` | Mailpit env (loopback + relay + retention) |
| `/etc/rateguru/mail-capture/mailtrap-local.yml` | Mailtrap Local storage config |
| `/etc/rateguru/mail-capture/versions.env` | Installed pinned versions |
| `/etc/systemd/system/rateguru-mailpit.service` | Mailpit unit |
| `/etc/systemd/system/rateguru-mailtrap-local.service` | Mailtrap Local unit |
| `/etc/nginx/sites-available/rateguru-mailpit-staging` | Mailpit vhost |
| `/etc/nginx/sites-available/rateguru-mailtrap-local-staging` | Mailtrap vhost |
| `/var/lib/rateguru-mail-capture/mailpit` | Mailpit SQLite |
| `/var/lib/rateguru-mail-capture/mailtrap-local` | Mailtrap Local SQLite |

## Laravel staging configuration

`infrastructure/templates/environment/staging.env.example` points staging mail
at Mailpit:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=noreply@staging.invalid
MAIL_FROM_NAME="${APP_NAME}"
```

Production mail settings are intentionally left unchanged.

## Verification

```bash
sudo infrastructure/scripts/verify-mail-capture
```

It confirms both services, all four loopback listeners, both APIs, an SMTP
submission with a unique identifier, the canonical Mailpit copy, the mirrored
Mailtrap Local copy, that Mailpit keeps accepting mail while Mailtrap Local is
stopped, and that mirroring resumes after Mailtrap Local restarts. All test
messages are deleted again on exit and Mailtrap Local is restarted if the
script stopped it, so no uncontrolled test mail is left behind.

## Status

```bash
infrastructure/scripts/status-mail-capture
```

Read-only: installed versions, systemd status, listener status, storage sizes,
message/database counts, Nginx presence, and recent journald errors. It never
changes anything.

## journald logs

```bash
journalctl -u rateguru-mailpit.service -f
journalctl -u rateguru-mailtrap-local.service -f
# relay errors (mirror down) show up as Mailpit errors:
journalctl -u rateguru-mailpit.service -p err --since '-1h'
```

Both services log to journald only; there are no separate log files.

## Retention

- **Mailpit:** at most 5000 messages, and nothing older than 14 days
  (`MP_MAX_MESSAGES=5000`, `MP_MAX_AGE=14d`).
- **Mailtrap Local:** at most 5000 messages (`storage.max_messages: 5000`);
  the oldest are evicted past the cap.

Retention is enforced by the services themselves — no cron job is involved.

## Binary upgrades

1. Update `MAILPIT_VERSION` / `MAILTRAP_LOCAL_VERSION` in
   `infrastructure/config/mail-capture/versions.env`.
2. Add a matching checksum file under
   `infrastructure/config/mail-capture/checksums/` with the official Linux
   `amd64` and `arm64` SHA-256 digests (see that directory's `README.md` for
   provenance). Never install an unverified binary; never use `latest`.
3. `sudo install-mail-capture --check` then `--apply`.
4. `sudo verify-mail-capture`.

The installer restarts only the service whose binary/config/unit actually
changed. Persistent SQLite data survives upgrades.

## Rollback

- **Same run:** if `--apply` fails before it commits the on-disk state, it
  automatically restores every file it had replaced and removes files it
  created, then `daemon-reload`s if a unit had changed.
- **After a completed apply:** the previous versions of any replaced files are
  under `/var/backups/rateguru-mail-capture/<timestamp>/` (mirroring their
  absolute paths). To revert, re-point `versions.env` at the previous pinned
  version (with its checksum file) and re-run `--apply`, or restore the backed
  up unit/config files manually and `systemctl daemon-reload` + restart.

## Stopping Mailtrap Local independently

Mailtrap Local can be stopped without affecting Mailpit or Laravel:

```bash
sudo systemctl stop rateguru-mailtrap-local.service
# Mailpit keeps capturing; relay attempts are logged as errors and dropped.
sudo systemctl start rateguru-mailtrap-local.service   # mirroring resumes
```

To keep it stopped across reboots: `sudo systemctl disable --now
rateguru-mailtrap-local.service`. Mailpit is unaffected because it only
`Wants=` the mirror.

## Persistent storage

SQLite databases live under `/var/lib/rateguru-mail-capture/`:

- `mailpit/mailpit.db` — owned by `rateguru-mailpit`, mode `0750` dir.
- `mailtrap-local/mailtrap-local.sqlite3` — owned by `rateguru-mailtrap-local`,
  mode `0750` dir. This directory also holds the Mailtrap Local
  `secret.key` (generated by the binary at first start; never committed).

The shared parent `/var/lib/rateguru-mail-capture` stays root-owned; each
service can write only to its own subdirectory (enforced by both filesystem
ownership and the unit's `ReadWritePaths=`).

## Excluding captured staging mail from disaster-recovery backups

Captured staging mail is **transient test data** and must be **excluded** from
disaster-recovery backups:

- It contains no production data and no business value.
- It can contain volatile test tokens and staging-only content.
- Restoring it onto a recovered host is meaningless and only inflates backups.

The backup tooling (`infrastructure/scripts/backup`) snapshots an explicit
allowlist of server-configuration paths and does **not** include
`/var/lib/rateguru-mail-capture`. Keep it that way: never add the mail-capture
state directories to any backup allowlist. If a full-disk backup mechanism is
ever introduced, add `/var/lib/rateguru-mail-capture` to its exclude list.

## Security model

- **Loopback only.** Every SMTP/HTTP listener binds `127.0.0.1` (1025, 8025,
  3535, 3550). Nginx is the only public surface, on 443 (and 80 → 443).
- **Basic Auth + TLS** on both web UIs, reusing
  `/etc/nginx/rateguru-staging.htpasswd` and Certbot certificates.
- **No public SMTP.** Ports 1025/3535/8025/3550 are never exposed by Nginx and
  have no public listener.
- **No secrets in Git.** `versions.env` and `mailpit.env` are committed but
  contain only non-secret loopback settings; the Mailtrap `secret.key` is
  generated on the server and gitignored.
- **Hardened systemd units:** `NoNewPrivileges`, `PrivateTmp`,
  `PrivateDevices`, `ProtectSystem=strict`, `ProtectHome`, kernel/cgroup
  protections, empty `CapabilityBoundingSet`, restricted address families, and
  an explicit single `ReadWritePaths` state directory per service.
- **Dedicated non-login users** (`rateguru-mailpit`,
  `rateguru-mailtrap-local`), each able to write only to its own state dir.

## Troubleshooting

- **Nothing captured:** confirm `MAIL_HOST=127.0.0.1` / `MAIL_PORT=1025` in the
  staging `.env`, `systemctl is-active rateguru-mailpit.service`, and
  `status-mail-capture` listener output.
- **Mirror empty but Mailpit has the message:** Mailtrap Local is down or
  relay failed — check `journalctl -u rateguru-mailpit.service -p err`. This is
  expected to be non-fatal; Mailpit keeps the canonical copy.
- **`nginx -t` fails during apply:** the installer stops before activating and
  rolls back; fix DNS/cert paths and re-run `--apply`.
- **`systemd-analyze verify` warnings:** ensure the binaries are installed at
  `/usr/local/bin/` and the state directories exist (the installer creates
  them).
- **Service fails to start with a memory/exec error:** on unusual kernels the
  `MemoryDenyWriteExecute=true` hardening can be incompatible with the Go
  runtime; check `journalctl -xeu <service>` and, if needed, relax that single
  directive in the committed unit and re-apply.
- **Port already in use:** another Mailpit/Mailtrap instance is running —
  `ss -ltnp | grep -E ':(1025|8025|3535|3550)'`.
