# RateGuru infrastructure roadmap

This roadmap tracks the versioned, vertical-slice evolution of RateGuru
infrastructure. Each phase is a self-contained, reviewable increment that does
not reorganize unrelated infrastructure.

| # | Phase | Status |
|---|-------|--------|
| 1 | VPS / deployment / backup foundation | ✅ completed |
| 2 | Versioned infrastructure baseline | ✅ completed |
| 3 | Staging mail capture | 🚧 current |
| 4 | Multi-target production model | ⏳ planned |
| 5 | Infrastructure installer and clean-VPS bootstrap | ⏳ planned |
| 6 | Sentry observability activation | ⏳ planned |
| 7 | Recovery and release rehearsal | ⏳ planned |
| 8 | tits.guru production launch | ⏳ planned |
| 9 | Additional production targets | ⏳ planned |
| 10 | Optional Nightwatch / PostHog / advanced dashboards | ⏳ planned |

## 1. VPS / deployment / backup foundation — completed

Single-VPS staging with atomic release deploys, rollback, local + offsite
(Backblaze B2) backups, restore tests, and hardened SSH/sudoers.

## 2. Versioned infrastructure baseline — completed

`infrastructure/` as the source of truth: Nginx vhosts, PHP-FPM pools,
Supervisor queue workers, cron, environment templates, and runbooks — all
non-secret and committed.

## 3. Staging mail capture — current

Loopback-only staging mail capture:

- **Mailpit** — canonical SMTP capture (`127.0.0.1:1025` SMTP, `127.0.0.1:8025`
  HTTP/API), persistent SQLite, 14-day / 5000-message retention.
- **Mailtrap Local** — secondary experimental mirror (`127.0.0.1:3535` SMTP,
  `127.0.0.1:3550` HTTP/API), persistent SQLite, 5000-message cap.
- Mailpit **relay-all** best-effort mirrors every captured message to Mailtrap
  Local; a mirror failure never blocks Laravel SMTP delivery and never stops
  Mailpit.
- Pinned, checksum-verified binaries; hardened systemd units; HTTPS Nginx
  vhosts with Basic Auth; `install`/`verify`/`status` scripts.

See `runbooks/mail-capture.md`.

## 4. Multi-target production model — planned

Generalize the single-target deploy model to multiple production targets
(shared code, per-target environment, backups, and release history).

## 5. Infrastructure installer and clean-VPS bootstrap — planned

One-shot bootstrap of a clean VPS from committed infrastructure: base packages,
users, Nginx/PHP-FPM/Redis, deploy accounts, and the mail-capture slice.

## 6. Sentry observability activation — planned

Wire the existing observability foundation (DomainLogger, exception context) to
Sentry for staging and production, with release tagging and PII redaction.

## 7. Recovery and release rehearsal — planned

Rehearse recovery end to end. This phase explicitly distinguishes four distinct
activities that must never be conflated:

1. **Backup creation** — producing a verified local + offsite backup artifact
   (database dump, storage, environment, server-configuration snapshot,
   checksums). *Proves a backup exists.*
2. **Restore-test** — restoring the latest backup into a throwaway/scratch
   database and asserting integrity (e.g. migrations table row count). *Proves
   the backup is restorable.* Runs on the existing server; does not rebuild the
   host.
3. **Clean-server recovery rehearsal** — provisioning a brand-new empty VPS from
   committed infrastructure (Phase 5 bootstrap), then restoring a backup onto
   it and bringing the app up. *Proves we can rebuild the whole host from
   scratch + backups*, not just the database.
4. **Production disaster recovery** — the real, timed, documented procedure for
   recovering the live production target after data loss or host loss, with
   RPO/RTO targets, DNS/TLS cutover, and a communications checklist. *The real
   event, not a drill.*

## 8. tits.guru production launch — planned

First production target go-live on `tits.guru`: production environment, TLS,
backups, monitoring, and the disaster-recovery procedure from Phase 7.

## 9. Additional production targets — planned

Onboard further production targets on the multi-target model from Phase 4.

## 10. Optional Nightwatch / PostHog / advanced dashboards — planned

Optional analytics and advanced operational dashboards, evaluated after the
core production and recovery phases are stable.
