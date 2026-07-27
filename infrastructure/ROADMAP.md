# RateGuru infrastructure roadmap

This roadmap tracks the versioned, vertical-slice evolution of RateGuru
infrastructure. Each phase is a self-contained, reviewable increment that does
not reorganize unrelated infrastructure.

| # | Phase | Status |
|---|-------|--------|
| 1 | VPS / deployment / backup foundation | ✅ completed |
| 2 | Versioned infrastructure baseline | ✅ completed |
| 3 | Staging mail capture | ✅ completed |
| 4 | Multi-target production model | 🚧 current |
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

## 3. Staging mail capture — completed

Loopback-only mail capture owned by the shared staging environment, not by
RateGuru: it is published on `mailpit.staging.myprojects.pp.ua` /
`mailtrap.staging.myprojects.pp.ua` and installed as `staging-mailpit` /
`staging-mailtrap-local` (units, system users, `/etc/staging-mail-capture`,
`/var/lib/staging-mail-capture`). The committed source of truth stays in this
repository until a second project exists.

- **Mailpit** — canonical SMTP capture (`127.0.0.1:1025` SMTP, `127.0.0.1:8025`
  HTTP/API), persistent SQLite, 14-day / 5000-message retention.
- **Mailtrap Local** — secondary experimental mirror (`127.0.0.2:3535` SMTP,
  `127.0.0.1:3550` HTTP/API), persistent SQLite, 5000-message cap. The SMTP
  listener binds `127.0.0.2` on purpose — Mailtrap Local 0.2.0 expands a
  `127.0.0.1` SMTP bind onto `[::1]` and fails on hosts without IPv6 loopback;
  both addresses are inside `127.0.0.0/8`.
- Mailpit **relay-all** best-effort mirrors every captured message to Mailtrap
  Local; a mirror failure never blocks Laravel SMTP delivery and never stops
  Mailpit.
- Pinned, checksum-verified binaries; hardened systemd units; HTTPS Nginx
  vhosts with Basic Auth; `install`/`verify`/`status` scripts.

See `runbooks/mail-capture.md`.

## 4. Multi-target production model — current

Generalize the single-target deploy model to multiple production targets
(shared code, per-target environment, backups, and release history).

Slices, in order. Only the first has landed; the phase stays **current**.

1. **Deployment target registry — completed.** Non-secret JSON registry of
   deployable targets (`staging-main`, `tits-guru`), a validation CLI, and lazy
   read-only `target_*` helpers in `common`. Declared the model only: no
   operational script consumed it, and nothing was installed on the VPS. See
   `runbooks/deployment-targets.md`.
2. **Read-only target operations — in progress.** `health-check` and `status`
   accept `--target TARGET` alongside `--environment`, gated to
   `lifecycle=active` targets via `require_active_target` — `tits-guru` stays
   rejected. `--environment` keeps its exact prior behaviour; nothing is
   installed on the VPS by this slice either.
3. Deploy path — `deploy`, `rollback`, `cleanup`.
4. Backup path — `backup`, `backup-cycle`, `offsite-*`, `restore-test`.
5. Perimeter — workflows, sudoers, server wrappers.
6. Install and validate the registry on the staging VPS.
7. Remove the `--environment` interface, only after `staging-main` parity is
   proven end to end.

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
