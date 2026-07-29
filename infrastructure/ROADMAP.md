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

Slices, in order. The first seven (through 7.1) are completed and accepted
on the real staging VPS; slice 7.2 is in progress. The phase stays
**current**.

1. **Deployment target registry — completed.** Non-secret JSON registry of
   deployable targets (`staging-main`, `tits-guru`), a validation CLI, and lazy
   read-only `target_*` helpers in `common`. Declared the model only: no
   operational script consumed it, and nothing was installed on the VPS. See
   `runbooks/deployment-targets.md`.
2. **Read-only target operations — completed.** `health-check` and `status`
   accept `--target TARGET` alongside `--environment`, gated to
   `lifecycle=active` targets via `require_active_target` — `tits-guru` stays
   rejected. `--environment` keeps its exact prior behaviour; nothing was
   installed on the VPS by this slice either.
3. **Install and verify read-only operations — completed.**
   `infrastructure/scripts/install-target-operations` installs the registry
   and the read-only scripts onto the staging VPS — transactional, with a
   staged pre-install check, automatic rollback on any failure, and
   runtime-parity verification against the real host with every test
   override explicitly unset. `deploy`/`rollback`/backup stay untouched and
   legacy-only; `tits-guru` stays unprovisioned. See
   `runbooks/install-target-operations.md`.

   **Accepted on the real staging VPS:** `staging-main` is `lifecycle=active`
   and environment class `staging`; `tits-guru` is `lifecycle=planned` and
   `production`; `health-check --environment staging` and
   `health-check --target staging-main` both work; `status` legacy/target
   parity passes; a planned `tits-guru` is rejected under both selectors;
   `install-target-operations --check`/`--apply`/`--verify` all pass; public
   Laravel storage is accessible through Nginx, and a real uploaded image
   returns HTTP 200.

   **Staging infrastructure defect fixes found during the first real VPS
   install**, both scoped to the existing `staging-main` target and not part
   of the slice progression above: a systemic executable-bit regression under
   `infrastructure/scripts/`, and `infrastructure/scripts/install-public-storage-access`,
   which grants `www-data` narrowly-scoped POSIX ACL traversal
   (`user:www-data:--x`) into a target's `shared`/`shared/storage`
   directories, fixing an HTTP 403 on every uploaded image. See
   `runbooks/public-storage-access.md`.
4. **Target-aware cleanup — completed.** `cleanup` accepts `--target TARGET`
   alongside the preserved `--environment` selector, adds an explicit
   `--dry-run` alias for its existing default, and is now installed
   transactionally by `install-target-operations` (six managed files, not
   five) — the first mutating operation that installer manages, gated behind
   the same `require_active_target` lifecycle check, the same deployment lock
   `deploy`/`rollback` use, and canonical path-containment validation on
   every deletion candidate. Also fixes a real dry-run side-effect bug (the
   prior implementation always touched and `chmod`'d `pinned-releases` and
   acquired the deployment lock even without `--apply`) and corrects the
   installed `common` library from `0755` to `0644` — a sourced library,
   never a CLI, so it must never be executable. `deploy`/`rollback`/backup
   stay untouched and legacy-only.

   **Accepted on the real staging VPS:** `install-target-operations
   --check`/`--apply`/`--verify` all passed against the six-file installer;
   `cleanup --environment staging --dry-run` and
   `cleanup --target staging-main --dry-run` selected the identical candidate
   release set; `cleanup --target tits-guru --dry-run` was rejected with
   `lifecycle=planned`.
5. **Target-aware deploy — completed.** `deploy` accepts `--target TARGET`
   alongside the preserved `--environment` selector, with the exact same
   `--release`/`--artifact`/`--checksum`/`--migrate` flags either way. The
   root-first contract is unchanged — `require_root` still runs before any
   argument parsing — and in target mode `require_active_target` runs
   immediately after root authorization, before any artifact, checksum,
   filesystem or lock work, so `tits-guru` stays rejected with
   `lifecycle=planned` before touching anything. One shared deployment
   pipeline handles both selectors past resolution; every existing
   protection (root-only execution, release ID validation, artifact/checksum
   containment within the selector's incoming directory, SHA-256
   verification, unsafe tar path rejection, the shared deployment lock,
   immutable release directories, atomic `current` switch, automatic
   recovery on failure, deployment history) is preserved unchanged.
   `install-target-operations` now manages seven files, not six.
   `rollback`/backup stay untouched and legacy-only; the GitHub Actions
   deploy workflow, its `/usr/local/sbin` wrapper, and sudoers keep calling
   `deploy --environment staging` — migrating that perimeter to
   `--target staging-main` is a separate future slice.

   **Accepted on the real staging VPS:** the seven-file
   `install-target-operations --check`/`--apply`/`--verify` all passed;
   the installed `deploy` is owned `root:root` mode `0755`;
   `deploy --target tits-guru` was rejected with `lifecycle=planned` before
   any artifact validation was reached; both
   `health-check --environment staging` and
   `health-check --target staging-main` passed.
6. **Target-aware rollback — completed.** `rollback` accepts `--target TARGET`
   alongside the preserved `--environment` selector, with the same
   `--release RELEASE_ID | --previous` destination contract either way.
   `install-target-operations` now manages eight files, not seven.

   **Accepted on the real staging VPS:** the eight-file
   `install-target-operations --check`/`--apply`/`--verify` all passed; the
   installed `rollback` is owned `root:root` mode `0755`;
   `rollback --target tits-guru` was rejected with `lifecycle=planned`; a
   legacy `rollback --environment staging --previous` completed
   successfully; a target `rollback --target staging-main --release ...`
   returned the release to its original state; the final post-rollback
   health check passed.
7. Backup path — local database/storage backups, remote Backblaze B2
   operations, and orchestration each carry different side effects, so this
   is split into three independently reviewable increments rather than one:
   1. **7.1 Local backup and local restore-test — completed.** `backup` and
      `restore-test` accept `--target TARGET` alongside `--environment`,
      sharing the existing `staging` local backup namespace with
      `staging-main` — no existing backup directory moves.
      `install-target-operations` now manages ten files, not eight.

      **Accepted on the real staging VPS:** the ten-file
      `install-target-operations --check`/`--apply`/`--verify` all passed;
      the installed `backup` and `restore-test` are owned `root:root` mode
      `0755`; `backup --target tits-guru` and
      `restore-test --target tits-guru` were both rejected with
      `lifecycle=planned`; a legacy `backup --environment staging` and a
      target `backup --target staging-main` both created a local backup in
      the shared `staging` namespace, and their checksums passed; a legacy
      `restore-test --environment staging` and a target
      `restore-test --target staging-main` both successfully restored
      PostgreSQL; the final health-check passed.
   2. **7.2 Offsite backup path — current.** `offsite-backup`,
      `offsite-retention` and `offsite-restore-test` accept `--target TARGET`
      alongside `--environment`, sharing the existing `staging` offsite (B2)
      namespace with `staging-main` — no existing remote backup moves.
      `install-target-operations` now manages thirteen files, not ten.
   3. **7.3 Backup-cycle orchestration — planned.** `backup-cycle` gains
      `--target`, once 7.1 and 7.2 have both landed.
8. Perimeter — workflows, sudoers, server wrappers.
9. Remove the `--environment` interface, only after `staging-main` parity is
   proven end to end across every slice above.

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
