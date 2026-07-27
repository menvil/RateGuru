# Staging mail capture

Loopback-only mail capture for the shared staging environment. Mail sent by any
staging app on the host is captured locally and never delivered to real
inboxes.

- **Mailpit** — the canonical capture service.
- **Mailtrap Local** — a secondary, experimental mirror.

The services are environment-owned, not project-owned: hostnames, systemd
units, system users and state directories are named `staging-*` / `*.staging.`
and are shared by every project deployed to the staging host. The committed
source of truth currently lives in this RateGuru repository under
`infrastructure/`, and moves out once a second project exists.

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
                              ▼  SMTP 127.0.0.2:3535
              ┌───────────────────────────────────┐
              │ Mailtrap Local (mirror)           │
              │   SMTP  127.0.0.2:3535            │
              │   HTTP  127.0.0.1:3550            │
              │   SQLite /var/lib/.../mailtrap-local │
              └───────────────────────────────────┘
```

Guarantees:

- **Mailpit always stores the canonical local copy.** Storage happens
  regardless of the relay outcome.
- **Mailtrap Local receives a best-effort mirrored copy** via Mailpit
  relay-all: `mailpit.env` enables the toggle (`MP_SMTP_RELAY_ALL=true`) and
  points at `mailpit-relay.yml`, which defines the loopback target
  (`host: 127.0.0.2`, `port: 3535`, `auth: none`).
- **A Mailtrap Local failure never fails Laravel SMTP delivery.** The relay
  config sets `forward-smtp-errors: false`, so a relay error is logged to
  journald but is **not** returned to the upstream SMTP client.
- **A Mailtrap Local failure never stops Mailpit.** The systemd unit uses
  `Wants=` (not `Requires=`) for `staging-mailtrap-local.service`.
- Mailtrap Local is independent and never depends on Mailpit.

### Why Mailtrap Local SMTP binds 127.0.0.2

Mailtrap Local 0.2.0 expands a `--smtp-listen 127.0.0.1:3535` bind into **both**
IPv4 `127.0.0.1:3535` and IPv6 `[::1]:3535`. On a host without IPv6 loopback the
service then fails to start:

```
listen [::1]:3535: bind: cannot assign requested address
```

and systemd drops it into a `activating (auto-restart)` loop. This was confirmed
on the first real VPS install.

Binding a **distinct IPv4 loopback address** sidesteps the IPv6 expansion
without enabling IPv6 anywhere. Mailtrap Local's SMTP listener therefore binds
`127.0.0.2:3535`, confirmed working on the VPS:

```
SMTP listening addrs=[127.0.0.2:3535]
HTTP listening addr=127.0.0.1:3550
```

- **SMTP:** `127.0.0.2:3535` (Mailpit's relay dials this exact address).
- **HTTP/API:** `127.0.0.1:3550` (unchanged; Nginx proxies to it).

`127.0.0.1` and `127.0.0.2` are both inside the IPv4 loopback range
`127.0.0.0/8`; neither is routable off-host and **must never** be publicly
exposed. IPv6 stays disabled. Mailpit's own listeners are unchanged
(`127.0.0.1:1025` SMTP, `127.0.0.1:8025` HTTP).

Mailtrap Local remains a **non-critical, best-effort mirror**: if it is stopped
or crash-looping, Mailpit still captures and stores every message and Laravel
delivery is unaffected (see the guarantees above).

## DNS and TLS prerequisites

Two DNS records must point at the staging host before requesting certificates:

- `mailpit.staging.myprojects.pp.ua`
- `mailtrap.staging.myprojects.pp.ua`

Both hostnames are one operational service, so they share **one** Certbot SAN
certificate under the lineage name `staging-mail-capture` — not two independent
certificate directories. Both committed vhosts are HTTPS-only and reference
`/etc/letsencrypt/live/staging-mail-capture/fullchain.pem` and `privkey.pem`, so
the certificate must exist **before** `install-mail-capture --apply` runs
`nginx -t` (otherwise the config test fails and apply rolls back).

Provision the certificate first, then apply:

```bash
# Obtain the cert before the HTTPS vhosts are active (standalone briefly binds :80).
sudo certbot certonly \
    --standalone \
    --cert-name staging-mail-capture \
    -d mailpit.staging.myprojects.pp.ua \
    -d mailtrap.staging.myprojects.pp.ua

sudo infrastructure/scripts/install-mail-capture --apply
```

`--cert-name` pins the lineage directory, so it stays `staging-mail-capture`
regardless of which hostname is listed first and across later `-d` changes.

`options-ssl-nginx.conf` and `ssl-dhparams.pem` are provided by the existing
Certbot install (same as the primary staging vhost). Renewals are handled by
the existing Certbot timer; `certbot renew` reloads Nginx automatically. Both
hosts reuse the existing staging password file
`/etc/nginx/rateguru-staging.htpasswd` for Basic Auth, so no new password file
and no new credentials are required.

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
   `config/mail-capture/SHA256SUMS`;
4. creates the `staging-mailpit` / `staging-mailtrap-local` system users and
   the state directories idempotently;
5. installs binaries atomically to `/usr/local/bin/`;
6. installs env/config to `/etc/staging-mail-capture/`;
7. installs the systemd units and Nginx vhosts (backing up any replaced file
   under `/var/backups/staging-mail-capture/<timestamp>/`);
8. runs `systemd-analyze verify` and `nginx -t`;
9. `daemon-reload`s only when a unit changed and restarts only changed
   services (mirror first, then Mailpit);
10. verifies **runtime health** before reporting success — each service must
    reach a stable `active` state and expose its listeners (`127.0.0.1:1025`,
    `127.0.0.1:8025`, `127.0.0.2:3535`, `127.0.0.1:3550`) and HTTP APIs within a
    bounded wait. A unit that is failed, inactive or stuck `activating
    (auto-restart)` fails the apply (non-zero) with `systemctl status`,
    `ActiveState`/`SubState`/`Result`/`ExecMainStatus`/`NRestarts`, and the
    recent unfiltered journal;
11. rolls the installed files back if apply fails before commit.

Installed layout:

| Path | Purpose |
|------|---------|
| `/usr/local/bin/staging-mailpit` | Mailpit binary |
| `/usr/local/bin/staging-mailtrap-local` | Mailtrap Local binary |
| `/etc/staging-mail-capture/mailpit.env` | Mailpit env (listeners, retention, relay toggle) |
| `/etc/staging-mail-capture/mailpit-relay.yml` | Mailpit relay/mirror target |
| `/etc/staging-mail-capture/mailtrap-local.yml` | Mailtrap Local storage config |
| `/etc/staging-mail-capture/versions.env` | Installed pinned versions |
| `/etc/systemd/system/staging-mailpit.service` | Mailpit unit |
| `/etc/systemd/system/staging-mailtrap-local.service` | Mailtrap Local unit |
| `/etc/nginx/sites-available/mailpit-staging` | Mailpit vhost |
| `/etc/nginx/sites-available/mailtrap-local-staging` | Mailtrap vhost |
| `/var/lib/staging-mail-capture/mailpit` | Mailpit SQLite |
| `/var/lib/staging-mail-capture/mailtrap-local` | Mailtrap Local SQLite |

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
journalctl -u staging-mailpit.service -f
journalctl -u staging-mailtrap-local.service -f
# relay errors (mirror down) show up as Mailpit errors:
journalctl -u staging-mailpit.service -p err --since '-1h'
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
2. Update the Linux `amd64` and `arm64` digests for the changed archives in
   `infrastructure/config/mail-capture/SHA256SUMS`. Never install an unverified
   binary; never use `latest`.
3. `sudo infrastructure/scripts/install-mail-capture --check` then `--apply`.
4. `sudo infrastructure/scripts/verify-mail-capture`.

The installer restarts only the service whose binary/config/unit actually
changed. Persistent SQLite data survives upgrades.

### Checksum provenance

`SHA256SUMS` pins the digest of every archive the installer may download, keyed
by archive filename; only Linux `amd64`/`arm64` are supported install targets.

- Mailtrap Local rows are copied verbatim (Linux only) from the upstream
  release `checksums.txt`
  (`https://github.com/mailtrap/mailtrap-local/releases/download/v<version>/checksums.txt`).
- Mailpit does not publish a `checksums.txt` asset, so its rows are computed
  with `sha256sum` from the official GitHub release archives
  (`https://github.com/axllent/mailpit/releases/tag/v<version>`).

## Rollback

- **Same run:** if `--apply` fails before it commits the on-disk state, it
  automatically restores every file it had replaced and removes files it
  created, then `daemon-reload`s if a unit had changed.
- **After a completed apply:** the previous versions of any replaced files are
  under `/var/backups/staging-mail-capture/<timestamp>/` (mirroring their
  absolute paths). To revert, re-point `versions.env` at the previous pinned
  version (with its checksum file) and re-run `--apply`, or restore the backed
  up unit/config files manually and `systemctl daemon-reload` + restart.

## Stopping Mailtrap Local independently

Mailtrap Local can be stopped without affecting Mailpit or Laravel:

```bash
sudo systemctl stop staging-mailtrap-local.service
# Mailpit keeps capturing; relay attempts are logged as errors and dropped.
sudo systemctl start staging-mailtrap-local.service   # mirroring resumes
```

To keep it stopped, mask it. `disable` is not enough: Mailpit `Wants=` the
mirror, so systemd starts the mirror again on the next Mailpit start or reboot
even with its `[Install]` symlinks removed.

```bash
sudo systemctl mask --now staging-mailtrap-local.service

# Bring the mirror back:
sudo systemctl unmask staging-mailtrap-local.service
sudo systemctl enable --now staging-mailtrap-local.service
```

Mailpit is unaffected either way, because it only `Wants=` the mirror: with the
mirror masked, relay attempts fail, are logged to journald, and are dropped —
the canonical local copy is still stored.

## Persistent storage

SQLite databases live under `/var/lib/staging-mail-capture/`:

- `mailpit/mailpit.db` — owned by `staging-mailpit`, mode `0750` dir.
- `mailtrap-local/mailtrap-local.sqlite3` — owned by `staging-mailtrap-local`,
  mode `0750` dir. This directory also holds the Mailtrap Local
  `secret.key` (generated by the binary at first start; never committed).

The shared parent `/var/lib/staging-mail-capture` stays root-owned; each
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
`/var/lib/staging-mail-capture`. Keep it that way: never add the mail-capture
state directories to any backup allowlist. If a full-disk backup mechanism is
ever introduced, add `/var/lib/staging-mail-capture` to its exclude list.

## Security model

- **Loopback only.** Every SMTP/HTTP listener binds the IPv4 loopback range
  `127.0.0.0/8`: `127.0.0.1:1025`, `127.0.0.1:8025`, `127.0.0.2:3535` (see
  above), `127.0.0.1:3550`. None is routable off-host. Nginx is the only public
  surface, on 443 (and 80 → 443).
- **Basic Auth + TLS** on both web UIs, reusing the existing staging password
  file `/etc/nginx/rateguru-staging.htpasswd` and Certbot certificates.
- **No public SMTP.** Ports 1025/3535/8025/3550 are never exposed by Nginx and
  have no public listener.
- **No secrets in Git.** The committed config (`versions.env`, `SHA256SUMS`,
  `mailpit.env`, `mailpit-relay.yml`, `mailtrap-local.yml`) holds only
  non-secret ports, paths, limits, mirror routing and pinned digests; the
  Mailtrap `secret.key` is generated on the server and gitignored.
- **Hardened systemd units:** `NoNewPrivileges`, `PrivateTmp`,
  `PrivateDevices`, `ProtectSystem=strict`, `ProtectHome`, kernel/cgroup
  protections, empty `CapabilityBoundingSet`, restricted address families, and
  an explicit single `ReadWritePaths` state directory per service.
- **Dedicated non-login users** (`staging-mailpit`,
  `staging-mailtrap-local`), each able to write only to its own state dir.

## Troubleshooting

- **Nothing captured:** confirm `MAIL_HOST=127.0.0.1` / `MAIL_PORT=1025` in the
  staging `.env`, `systemctl is-active staging-mailpit.service`, and
  `status-mail-capture` listener output.
- **Mirror empty but Mailpit has the message:** Mailtrap Local is down or
  relay failed — check `journalctl -u staging-mailpit.service` (unfiltered; the
  relay error may be logged below `err` priority). This is expected to be
  non-fatal; Mailpit keeps the canonical copy.
- **Mailtrap Local stuck in `activating (auto-restart)`:** almost always the
  IPv6 `[::1]:3535` bind failure described above. Confirm the unit binds
  `127.0.0.2:3535` (not `127.0.0.1:3535`) and look for `bind: cannot assign
  requested address` in `journalctl -u staging-mailtrap-local.service` (do not
  add `-p err`; the line is recorded below `err`). `install-mail-capture
  --apply` now fails with these diagnostics instead of reporting success while a
  service is restart-looping; `status-mail-capture` shows `NRestarts` and the
  raw journal.
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
