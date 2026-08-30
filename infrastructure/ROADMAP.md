# RateGuru infrastructure roadmap

This roadmap tracks the versioned, vertical-slice evolution of RateGuru
infrastructure. Each phase is a self-contained, reviewable increment that does
not reorganize unrelated infrastructure.

| # | Phase | Status |
|---|-------|--------|
| 1 | VPS / deployment / backup foundation | ✅ completed |
| 2 | Versioned infrastructure baseline | ✅ completed |
| 3 | Staging mail capture | ✅ completed |
| 4 | Multi-target production model | ✅ completed |
| 5 | Infrastructure installer and clean-VPS bootstrap | ✅ completed |
| 6 | Sentry observability activation | 🚧 current |
| 7 | Disaster recovery and release rehearsal | ⏳ planned (7.1 implemented) |
| 8 | First production launch and target-provisioning proof | ⏳ planned |
| 9 | Repeatable production target onboarding | ⏳ planned |
| 10 | Advanced observability and product analytics | ⏳ planned / optional |

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

## 4. Multi-target production model — completed

Generalize the single-target deploy model to multiple production targets
(shared code, per-target environment, backups, and release history).

Slices, in order. All nine slices are completed. Every slice that deployed or
changed anything on a host was additionally accepted on the real staging VPS
(slices 1-2 declared the target registry and added read-only `--target`
support only — neither installed anything on the VPS). The operational
interface is now target-only: `--target TARGET_ID` is the sole selector for
every script, wrapper, cron entry and CI call. `staging-main` remains the
active staging target; `tits-guru` remains a planned target with no
provisioned infrastructure. The phase is **completed**.

<!-- legacy-environment-history:start -->
<!--
  Slices 1-8 below are a historical record of the migration while it ran the
  old and new operational selectors in parallel. They accurately describe
  what shipped at each step, including the legacy selector's own name; see
  slice 9 for its removal. Everything between the two markers around this
  block is exempted from LegacyEnvironmentRemovalTest.php as one explicitly
  marked historical entry; nothing outside it is.
-->

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
   2. **7.2 Offsite backup path — completed.** `offsite-backup`,
      `offsite-retention` and `offsite-restore-test` accept `--target TARGET`
      alongside `--environment`, sharing the existing `staging` offsite (B2)
      namespace with `staging-main` — no existing remote backup moves.
      `install-target-operations` now manages thirteen files, not ten.

      **Accepted on the real staging VPS (`PHASE 4 SLICE 7.2 ACCEPTED`):**
      the thirteen-file `install-target-operations --check`/`--apply`/
      `--verify` all passed; the installed `offsite-backup`,
      `offsite-retention` and `offsite-restore-test` are owned `root:root`
      mode `0755`; a real target upload (`offsite-backup --target
      staging-main`) succeeded; `offsite-retention --target staging-main`
      dry-run succeeded; `offsite-restore-test --target staging-main`
      succeeded; `offsite-backup --target tits-guru` (and the other two
      offsite scripts) were rejected with `lifecycle=planned`; the final
      health-check passed.
   3. **7.3 Backup-cycle orchestration — completed.** `backup-cycle` gains
      `--target` alongside the preserved `--environment` selector, sharing
      the identical namespace and cycle lock as every script above. Runs the
      full five-step pipeline — local backup, local restore-test, offsite
      upload, offsite retention (`--apply`), offsite restore-test — strictly
      in order, fail-fast, and appends one compact JSON record per cycle to
      `/home/www/rateguru/backups/backup-cycles.jsonl`. Retention is only
      ever applied once local backup, local restore-test and the offsite
      upload have all already succeeded; local backups are not pruned by
      this slice. `install-target-operations` now manages fourteen files,
      not thirteen.

      **Accepted on the real staging VPS:** the fourteen-file
      `install-target-operations --check`/`--apply`/`--verify` all passed;
      `/home/www/rateguru/bin/backup-cycle` was updated; a real
      `backup-cycle --target staging-main` ran the full five-step pipeline
      to completion — local backup, local restore-test, offsite (B2)
      upload, offsite retention apply, and offsite restore-test all
      succeeded; the cycle was recorded in
      `/home/www/rateguru/backups/backup-cycles.jsonl`; the final staging
      health-check passed.
8. **Perimeter — completed.** `infrastructure/scripts/install-target-perimeter`
   installs three generic, target-aware sudo wrappers
   (`rateguru-deploy`/`rateguru-rollback`/`rateguru-cleanup`), a sudoers rule
   granting the staging deploy user access to them, and switches the
   `rateguru-backups` cron entry and the GitHub Actions staging deploy
   workflow from the legacy per-environment selector to `--target
   staging-main`. `deploy`/`rollback`/`cleanup`/`backup-cycle`/`restore-test`/
   `offsite-restore-test` themselves are unchanged — they already support
   `--target` from earlier slices. The temporary legacy per-environment
   wrappers and sudoers rules remained installed, for rollback safety, until
   slice 9 below deletes them. See `runbooks/target-perimeter.md`.

   **Accepted on the real staging VPS:** `install-target-perimeter
   --check`/`--apply`/`--verify` all passed after fixing a static-wrapper
   validation false positive — five perimeter source files present, wrapper
   Bash syntax valid, target registry valid, the installed fourteen-file
   target operations bundle matched the committed repository sources, the
   sudoers candidate passed `visudo -cf`, the cron candidate passed
   validation, installation was transactional, and final verification
   passed. The three generic wrappers were installed
   `root:root 0755` at `/usr/local/sbin/rateguru-{deploy,rollback,cleanup}`;
   the sudoers file was installed `root:root 0440` at
   `/etc/sudoers.d/rateguru-deploy`, parsed clean, and granted the staging
   deploy user passwordless access to only those three wrappers; the cron
   file was installed `root:root 0644` at `/etc/cron.d/rateguru-backups`
   with its existing schedules and log paths preserved and every operational
   call switched to `--target staging-main`, with no per-environment selector
   left in the active cron. The staging deploy user successfully ran the
   generic cleanup wrapper (`--target staging-main --dry-run`) and got the
   expected release-cleanup plan without deleting anything; the same generic
   wrapper correctly rejected `tits-guru` with `lifecycle=planned, not
   active` before any mutation. The GitHub Actions staging deployment was
   switched to `DEPLOY_WRAPPER=/usr/local/sbin/rateguru-deploy` and
   `DEPLOY_INCOMING=/home/deploy-rateguru-staging/incoming`, and a real
   `develop` deployment completed end to end through the generic wrapper
   (`deploy --target staging-main`, `deployment-target: staging-main`). The
   final target-aware health check passed on the real staging VPS.

<!-- legacy-environment-history:end -->

9. **Complete legacy-selector removal — completed.** Removed the legacy
   per-environment operational selector entirely from every script, `common`,
   `deployment.conf`, and the perimeter, once `staging-main` parity had been
   proven end to end across every slice above and accepted on the real
   staging VPS. `--target TARGET_ID` is now the sole interface everywhere.
   `common` lost its selector-dispatch and per-selector helper functions;
   `deployment.conf` is host-global only (no more per-target fields); the six
   temporary per-environment sudo wrappers and their sudoers grants are
   deleted by `install-target-perimeter`, transactionally, with backup and
   rollback on failure. Physical values the legacy interface used to name
   (paths, database names, backup namespaces, deploy account names) are
   preserved verbatim as `staging-main`'s own registry values — nothing
   physical is renamed. Backup format and history compatibility (schema 1
   manifests, existing JSONL history files) is preserved unchanged and stays
   readable regardless of which selector originally wrote it.
   `LegacyEnvironmentRemovalTest.php` guards against the removed interface
   ever reappearing.

   **Accepted on the real staging VPS (`PHASE 4 TARGET-ONLY ACCEPTED`):**

   - *Operations bundle.* On a fresh `develop` release,
     `install-target-operations --check`/`--apply`/`--verify` all passed. The
     installed bundle is target-only and manages fifteen files, including the
     host-global `deployment.conf`. After installation,
     `/home/www/rateguru/bin/status --target staging-main` and
     `/home/www/rateguru/bin/health-check --target staging-main` both passed.
   - *Perimeter.* `install-target-perimeter --check`/`--apply`/`--verify` all
     passed. The three generic wrappers
     (`/usr/local/sbin/rateguru-deploy`, `rateguru-rollback`,
     `rateguru-cleanup`) stayed operational; the six legacy per-environment
     wrappers were removed and `--verify` confirmed their absence;
     `/etc/sudoers.d/rateguru-deploy` parsed clean under `visudo -cf` and
     grants the staging deploy user passwordless sudo for only those three
     generic wrappers; `/etc/cron.d/rateguru-backups` calls
     `--target staging-main`, and the active cron contains no legacy
     operational selector at all.
   - *Target-only CLI.* `targets list`, `status --target staging-main`,
     `health-check --target staging-main` and
     `cleanup --target staging-main --dry-run` all worked on the real VPS,
     with `staging-main` serving as the active staging target. The legacy
     per-environment invocation is no longer a supported interface: it is
     rejected through the generic unknown-argument path, and the removed
     selector is not an active compatibility API.
   - *Planned-target protection.* `health-check --target tits-guru` was
     correctly rejected with `lifecycle=planned`. No production
     infrastructure for `tits-guru` was activated in Phase 4.
   - *Real rollback.* A full rollback rehearsal ran on the staging VPS: the
     current release ID was recorded, the generic target-aware rollback
     switched `staging-main` to the previous release, the post-rollback
     health check passed, a rollback with an explicit release ID returned the
     newer release, and the final health check passed — proof that
     target-only rollback works against real immutable release history, not
     only syntactically.
   - *Full backup cycle.*
     `/home/www/rateguru/bin/backup-cycle --target staging-main` completed the
     entire pipeline — local backup, local restore-test, offsite B2 upload,
     offsite retention `--apply`, offsite restore-test — and the cycle was
     appended to the existing backup-cycle history. The final
     `health-check --target staging-main` passed.

Phase 4 is therefore complete with these guarantees: the target registry is
canonical; the operational CLI is target-only; GitHub staging deployment is
target-aware; the three generic sudo wrappers are installed and the legacy
wrappers are absent; cron is target-aware and sudoers exposes only the
generic wrappers; `staging-main` deploy, rollback and cleanup work; local and
offsite backup/restore work, as does the full backup-cycle orchestration;
planned targets fail closed; physical staging paths, database and backup
namespaces are unchanged; and historical backup formats and JSONL history
remain readable.

Next phase: Phase 5 — Infrastructure installer and clean-VPS bootstrap.

## 5. Infrastructure installer and clean-VPS bootstrap — completed

One-shot bootstrap of a clean VPS from committed infrastructure: base packages,
users, Nginx/PHP-FPM/Redis, deploy accounts, and the mail-capture slice.

Slices, in order. Like Phase 4, each slice is independently reviewable; a
slice that mutates a real host is only marked completed after acceptance on a
real VPS. Slice 5.1 is strictly read-only — the same category as Phase 4
slices 1–2, which installed nothing — so it completes on merge.

1. **5.1 Host contract and read-only bootstrap preflight — completed.**
   `infrastructure/scripts/bootstrap-host-preflight` defines and validates
   the host contract later slices must satisfy, without mutating anything:
   no packages, users, directories, permissions, configuration, services or
   firewall changes. `--check` exits 0 only when every mandatory
   prerequisite already holds; `--report` prints the full detected host
   state plus intended bootstrap actions and stays usable on a completely
   clean host — including one where jq is not installed yet, in which case
   the target-derived contract is explicitly reported as not evaluable
   rather than invented. It enforces the supported OS baseline as an exact
   contract (Ubuntu 22.04 only — the staging VPS baseline; any other family
   or release is a conflict, and no pretend multi-distro support), and
   inventories the canonical host tool set derived from the committed
   scripts, service states
   (missing/installed-stopped/installed-running, with the shared staging
   mail capture labeled `shared-host-service`), users/groups and required
   membership relations, the filesystem contract derived from the source
   registry and installers, listener/port conflicts, and which secret
   material later slices need — by presence only, never reading or printing
   secret content. The repository registry is the bootstrap source of
   truth; an installed runtime registry is reported for parity/drift and
   never modified. There is deliberately no `--apply` in this slice. See
   `runbooks/bootstrap-host.md`.
2. **5.2 Base packages and runtime — completed.**
   `infrastructure/scripts/install-bootstrap-runtime` reproducibly installs
   the base/runtime package layer on the exact supported baseline
   (`--check` read-only validation with intended actions, root-only
   `--apply`, read-only `--verify` gate; the real clean-VPS `--apply` run
   is re-proven end to end by the 5.6 acceptance). The contract reproduces
   the directly-inspected staging VPS: Ubuntu 22.04/x86_64 exactly (pins
   kept in tested parity with the preflight; any other family, release or
   architecture fails closed before mutation), PHP 8.5 CLI+FPM and
   extensions from the Ondřej Surý PPA, PostgreSQL 18 from PGDG, and
   Nginx/Redis/Supervisor plus the whole Phase 5.1 canonical tool
   inventory from the Ubuntu jammy distribution repository. Exact patch
   versions deliberately float with security updates — the contract is PHP
   series 8.5 and PostgreSQL major 18, verified through `php8.5 -m`/`-v`
   and the PostgreSQL client versions, not just dpkg state. The two
   RateGuru-owned repositories are deb822 `.sources` files with dedicated
   `/etc/apt/keyrings` keyrings, HTTPS-only key fetch validated against
   pinned fingerprints, staged-and-renamed atomically, failing closed
   before any package installation; `apt-key` is never used, pre-existing
   operator-configured sources for the same repositories are recognized
   and left untouched, and unrelated host repositories (NodeSource,
   ClickHouse, Datadog/Vector) are never inspected, managed or required
   absent. Because a minimal host has neither curl nor gnupg, `--apply`
   installs the bootstrap repository tooling (ca-certificates, curl,
   gnupg) from the existing Ubuntu sources before any external repository
   work, and `--check`/`--verify` validate installer-owned repository
   files authoritatively and read-only (byte-exact sources, keyring
   holding exactly the pinned key). apt runs noninteractively, updates
   only when needed (at most twice on a first clean-host apply), never
   upgrades, never removes; a second `--apply` performs no apt call and
   rewrites no file. Node.js/npm/Composer are intentionally absent (GitHub
   Actions builds the immutable artifact — the host never builds), SQLite
   is not installed, and no RateGuru users, directories, databases, roles,
   service configuration or TLS are touched (slices 5.3/5.4). See
   `runbooks/bootstrap-host.md`.
   *Corrective acceptance fix (5.2.1):* the real staging deployment
   falsified the assumption that rclone is an Ubuntu apt package — the
   host runs a standalone, dpkg-unowned `/usr/bin/rclone` far newer than
   the jammy candidate. rclone is therefore managed as a verified, pinned
   external runtime binary: the pin lives in
   `infrastructure/config/external-runtimes/versions.env` alongside the
   committed release-signing public key, `--check`/`--verify` report it in
   their own EXTERNAL RUNTIME section (never as a package), and `--apply`
   converges drift through a signature- and checksum-verified atomic
   replacement of the exact pinned release — never apt, never
   `rclone selfupdate`, never touching `/root/.config/rclone/rclone.conf`.
   `unzip` joined the base package contract to extract the release
   archive.
3. **5.3 Users, groups and filesystem — completed.**
   `infrastructure/scripts/install-bootstrap-host-layout` provisions the
   identity and filesystem layer required before service/configuration
   installation (root-only `--check` read-only validation with intended
   actions, root-only `--apply` that validates the entire plan before the
   first mutation, read-only `--verify` gate). The repository registry is
   the source of truth (validated through the standalone `targets` CLI —
   the runtime registry does not exist yet and is never read), and only
   `lifecycle=active` targets are provisioned: `tits-guru` stays planned
   and causes zero user, group or filesystem mutation. The identity model:
   the deploy user owns incoming artifacts and release creation
   (`releases`/`locks`/`deployments` are `deploy_user:code_group` setgid
   `2750`); the code group has exactly two required
   members, because releases are normalized `0750`/`0640` and both
   identities must read them: the runtime user (PHP-FPM and the queue
   worker execute Laravel as it) and `www-data` (Nginx workers evaluate
   `try_files` against `current/public` themselves, before FastCGI is
   involved — see slice 5.6's Defect B); the runtime
   user owns shared mutable state (`shared` and `shared/storage` are
   `runtime_user:runtime_group` setgid `2770`); `www-data` is never added
   to runtime groups, so shared mutable state stays out of reach and
   public storage traversal stays the narrow POSIX ACL from
   `install-public-storage-access` in 5.4; root owns the target
   namespace root (`root:root 0755`) and the host operational roots
   (`/home/www/rateguru` + `config`/`bin` `0755`, `backups`/`run` `0700`,
   `/var/log/rateguru` `0750`) — so a deploy identity can create immutable
   releases without ever controlling the target namespace itself. The
   package-created 5.2 accounts (root, www-data, postgres) are validated,
   never created; the mail-capture accounts stay owned by
   `install-mail-capture`. The deploy home gets a structural `.ssh`
   (`0700`) but never an `authorized_keys` (5.4 secret material), no
   sudoers, and `current`/`previous` stay deployment-owned (never
   fabricated, rewritten or followed). The managed-identity metadata is a
   hard contract for existing accounts too: the deploy account must hold
   its exact canonical home (`/home/<deploy_user>` — the same directory
   the installer manages) and `/bin/bash` (the GitHub Actions SSH flow
   must be able to log in), and the runtime account must hold the
   non-login `/usr/sbin/nologin` service shell; the runtime account's
   historic home is deliberately not contract. Incompatible existing
   metadata is CONFLICT and fails `--apply` closed before any mutation —
   an existing SSH identity is never automatically usermod'ed.
   *Corrective acceptance fix:* the first real staging `--apply` surfaced
   a GNU chmod semantic — on directories a plain numeric mode preserves
   existing set[ug]id bits, so remediating the `2750` target root with
   `chmod 0755` left `2755` and the closing `--verify` correctly failed.
   Exact-mode convergence (reconciliation, creation, and the `nw`
   remediation) now uses explicit operator-numeric replacement
   (`chmod =MODE`), regression-tested against the real pre-apply staging
   state (`deploy:code 2750` → exactly `root:root 0755`).
   Reconciliation is strictly
   per-directory-entry — no recursive chown/chmod, no `rm -rf`, no
   `userdel`/`groupdel`, no UID/GID renumbering; wrong-type paths and
   incompatible existing accounts fail closed before any mutation, and a
   second `--apply` on a compliant host performs zero mutation. The known
   real staging drift (the top-level `/home/www/rateguru/staging` owned
   `deploy-rateguru-staging:rateguru-staging-code` instead of root) is the
   one existing-host remediation this slice carries: `--apply` chowns
   exactly that directory entry to `root:root 0755`, leaving
   `releases`/`shared`/`current` and all application data untouched.
   `bootstrap-host-preflight` now asserts the 5.3 structural contract
   authoritatively (per-target owners, groups and setgid modes, deploy
   home/`.ssh`, `shared/storage`, `/var/log/rateguru`), so slices 5.1 and
   5.3 cannot disagree. See `runbooks/bootstrap-host.md`.

   **Accepted on the real staging VPS:** `install-bootstrap-host-layout
   --check` found only the known target-root drift; the first real
   `--apply` exposed the GNU chmod directory special-bit behaviour (a plain
   numeric mode preserves set[ug]id bits on directories), the corrective
   exact-mode (`chmod =MODE`) fix was merged and deployed, and the
   corrected `--apply` converged `/home/www/rateguru/staging` to exactly
   `root:root 0755`; `install-bootstrap-host-layout --verify` passed; a
   second `--apply` was idempotent (zero mutation); the `current`/
   `previous` release links remained unchanged; staging health remained
   healthy throughout; and planned `tits-guru` remained completely
   unprovisioned.
4. **5.4 Services and configuration — completed.**
   `infrastructure/scripts/install-bootstrap-services`
   reproducibly turns the prepared 5.2/5.3 host into the configured
   RateGuru service host required for deployment (root-only `--check`
   read-only validation with intended actions, root-only `--apply` that
   validates the entire plan before the first mutation, root-only
   read-only `--verify` gate). It coordinates the existing installers as
   authoritative owners — `install-target-operations` (runtime registry,
   `deployment.conf`, the operational bundle), `install-target-perimeter`
   (wrappers/sudoers/backup cron), `install-public-storage-access` (the
   narrow www-data ACL, active targets only) and `install-mail-capture`
   (the shared-host mail capture, verified through `verify-mail-capture`)
   — invoking each child's own `--apply` only when its own authoritative
   `--verify` does not already pass, and directly owns only the service
   files that had committed sources but no dedicated installer: the
   active-target Nginx site plus its `sites-enabled` symlink, the PHP-FPM
   pool, the Supervisor queue program, the scheduler cron, the host-global
   SSH deploy restriction (all `root:root 0644`, installed
   transactionally), and the exact service-support log directory the
   committed PHP-FPM/Supervisor configs write into
   (`TARGET_ROOT/shared/storage/logs`, runtime-owned setgid `2770`).
   Every configuration family is validated by its authoritative parser
   before any reload (`nginx -t`, `sshd -t`, the PHP 8.5 FPM config test,
   `supervisorctl reread`), a failed candidate is restored before any
   daemon could see it, and base services (nginx, php8.5-fpm, postgresql,
   redis-server, supervisor) are enabled/started without ever touching
   databases, roles, `pg_hba.conf` or Redis auth. The prerequisite gate is
   the authoritative `install-bootstrap-runtime --verify` plus
   `install-bootstrap-host-layout --verify` — deliberately not the full
   preflight, which expects things 5.4 itself creates. The slice
   introduces the explicit PRE_DEPLOY/DEPLOYED distinction: a clean host
   legitimately has no `current` release, so infrastructure bootstrap
   readiness and application runtime readiness are separate states —
   application-runtime probes (queue activation, HTTP health, the
   public-storage HTTP canary) are DEFERRED with explicit log lines on a
   PRE_DEPLOY host, never faked with fabricated releases, while every
   present-but-broken `current` shape stays a hard failure;
   `install-target-operations` and `install-public-storage-access` gained
   the same state split without weakening any check on a deployed host.
   External secret material (TLS certificates/keys, the Basic Auth
   htpasswd, `shared/.env`, `authorized_keys`, `rclone.conf`) is never
   generated, copied or read — a committed vhost being activated whose
   external files are missing fails closed with `EXTERNAL PREREQUISITE
   MISSING` naming only category and path. Planned `tits-guru` receives
   zero service configuration (its committed production config sources are
   ignored), and a second `--apply` on a compliant host performs zero
   meaningful mutation: no file rewrite, no reload/restart, no repeated
   child mutation. The integration gap the slice documented — `deploy` did
   not yet activate a Supervisor program the PRE_DEPLOY bootstrap deferred —
   was closed by slice 5.5's first-deploy activation. See
   `runbooks/bootstrap-services.md`.

   **Accepted on the real staging VPS:** `install-bootstrap-services
   --check` reported the contract already satisfied (PASS: 41, MISSING: 0,
   DRIFT: 0, WARN: 0, CONFLICT: 0, DEFERRED: 0 — `SLICE 5.4 CONTRACT:
   SATISFIED`); `--apply`, `--verify` and a second `--apply` all succeeded,
   with the second apply idempotent (zero meaningful mutation) and staging
   healthy throughout. Every child contract passed — target operations,
   perimeter, public-storage ACL, mail capture — and Nginx, PHP-FPM,
   PostgreSQL, Redis, Supervisor and the two staging mail-capture services
   remained active with the deployed `rateguru-staging-queue` worker
   RUNNING.
5. **5.5 Bootstrap orchestrator — completed.**
   `infrastructure/scripts/bootstrap-host` is the one canonical
   host-bootstrap entry point: clean/prepared Ubuntu host → 5.2 runtime →
   5.3 identities/filesystem → 5.4 services/configuration → final bootstrap
   preflight, executed from the bootstrap repository checkout (children are
   resolved as canonical siblings — never through an application deployment
   or the installed operational bundle, which do not exist on a clean
   host). It owns orchestration only — ordering, per-slice status,
   fail-fast, readiness aggregation — while every child installer stays
   authoritative for its own contract; there is no `--force`/`--skip`
   escape hatch, no application deploy, no database/role provisioning, no
   secret creation, and planned `tits-guru` remains untouched. `--check` is
   dependency-aware and strictly read-only (an unsatisfied earlier slice
   marks later slices BLOCKED rather than misjudging them); `--apply` is
   convergent, not one transaction: per slice, an already-passing
   authoritative `--verify` is SKIPped, otherwise the child's own `--apply`
   runs and its `--verify` must pass before the next slice — a failure
   (e.g. a missing external prerequisite 5.4 fails closed on) stops the run
   without rolling back safely converged earlier slices, and re-running
   resumes at the failing slice. SIGINT is never trapped or reinterpreted;
   child/signal exit statuses propagate verbatim. The slice also unified
   the PRE_DEPLOY/DEPLOYED/BROKEN target-state model across
   `bootstrap-host-preflight`, `install-bootstrap-services` and `deploy`:
   the preflight now classifies each active target's `current` exactly like
   slice 5.4 (an absent `current` and the deploy-time external material —
   `shared/.env`, database credentials, `authorized_keys`, `rclone.conf` —
   are DEFERRED "required before first deploy" on a PRE_DEPLOY host, with a
   `HOST BOOTSTRAP READY` / `APPLICATION READY: DEFERRED` summary and exit
   0 when only legitimate deferrals remain; every broken `current` shape
   stays CONFLICT, and the strict `HOST READY` contract on a DEPLOYED host
   is unchanged — 5.4-hard TLS/Basic Auth material stays MISSING in every
   state). And it closed the documented 5.4 first-deploy gap: `deploy` now
   ensures the registry-declared Supervisor queue program is RUNNING after
   the atomic `current` switch and HTTP health check — an already-RUNNING
   worker is never touched (zero supervisor churn on normal deployments),
   an inactive one is activated via `supervisorctl reread`/`update` (plus
   `start` when needed) scoped to exactly the target program group, and
   activation failure fails the deployment with recovery stopping a
   worker this deploy activated so nothing keeps running against a
   removed `current`. See `runbooks/bootstrap-host.md`.

   **Accepted on the real staging VPS:** the host was already largely
   compliant, so this acceptance exercised the orchestrator's convergent
   path rather than a build. `bootstrap-host` detected operational-bundle
   drift left by the deployment changes that had landed since the bundle
   was last installed; `bootstrap-host --apply` converged only that slice —
   5.2 and 5.3 were SKIPped on their own passing `--verify`, 5.4 applied
   and then verified. The final `bootstrap-host-preflight` passed, and so
   did `bootstrap-host --verify`. A second `bootstrap-host --apply` was
   idempotent: every slice SKIPped on an already-passing authoritative
   `--verify`, nothing was mutated. Staging remained healthy throughout.
6. **5.6 Clean-VPS acceptance — completed.** The rehearsal the whole phase
   existed for: a brand-new empty VPS bootstrapped end to end from the
   committed infrastructure, and accepted for real.

   **Accepted on a real clean VPS.** A disposable Ubuntu 22.04 x86_64 host
   with nothing on it — no RateGuru directories or accounts, and no PHP,
   PostgreSQL, Nginx, Redis, Supervisor or rclone. The progression, in
   order:

   - the initial `bootstrap-host-preflight` correctly reported a clean,
     unready host rather than mistaking emptiness for compliance;
   - `bootstrap-host --check` produced exactly the dependency-aware answer
     it should: 5.2 NEEDS_APPLY, 5.3 BLOCKED, 5.4 BLOCKED — an unsatisfied
     earlier slice marks later ones BLOCKED instead of misjudging them;
   - the first `bootstrap-host --apply` installed 5.2 and 5.3 from scratch;
   - 5.4 then failed **closed, before any mutation**, because the
     deliberately external TLS and Basic Auth material was absent — the
     external-prerequisite contract behaving exactly as designed, not a
     defect;
   - after those external prerequisites were provided, re-running bootstrap
     **resumed at 5.4** rather than rebuilding the already-converged 5.2
     and 5.3;
   - PRE_DEPLOY was accepted as a legitimate terminal state: no `current`
     or `previous` release was fabricated to make a check pass;
   - the first real immutable application deployment was then exercised on
     that host, including the first-deploy Supervisor queue activation;
   - application health, PHP-FPM, PostgreSQL, Redis, Nginx, the Supervisor
     queue worker and the scheduler path were all exercised;
   - `rollback` worked against real immutable release history;
   - local backup and `restore-test` worked; Backblaze B2 offsite upload
     and `offsite-restore-test` worked; the full target-aware
     `backup-cycle` worked;
   - final bootstrap verification and a repeat `--apply` were idempotent.

   **Two real bootstrap defects were found here and nowhere else.** Both
   were historical-state dependencies: the staging host had satisfied them
   years ago by accident of history, so no amount of CI or
   already-compliant-host testing could surface them. Exposing exactly this
   class of bug is why 5.6 existed.

   - **Defect A — an unmanaged trusted helper.** `deploy` depended on the
     installed `/home/www/rateguru/bin/verify-required-clis`, but
     `install-target-operations` did not manage or install that helper, so
     a freshly bootstrapped host did not have it. Fixed in **PR #1124**,
     which made `verify-required-clis` a first-class root-owned managed
     operational CLI with the same transactional install/verify/rollback
     coverage as the rest of the bundle.
   - **Defect B — an identity the clean host never had.** Immutable
     releases are `deploy_user:code_group` `0750`/`0640`, and Nginx runs as
     `www-data`; clean bootstrap had never made `www-data` a member of the
     active target's code group. Nginx workers evaluate
     `try_files $uri $uri/ /index.php?$query_string` against
     `current/public` themselves, before FastCGI is involved, so the host
     answered every request with HTTP 404 while the error log showed
     `Permission denied` stat'ing `current/public` and `public/index.php`.
     Fixed in **PR #1125**, which made `www-data` → active-target
     `code_group` an explicit bootstrap identity contract, and — because
     supplementary groups are fixed at process creation — ensured
     already-running Nginx workers converge to the new state without
     resorting to unconditional reloads.

   **The security model was preserved, not widened.** `deploy_user` owns
   immutable releases; `runtime_user` reads immutable code through
   `code_group`; `www-data` reads and traverses immutable *public* code
   through `code_group` and nothing else — it is **not** in
   `runtime_group`, so shared mutable storage stays out of reach, and
   `www-data`'s access to shared storage remains the separate narrow POSIX
   ACL from `install-public-storage-access`. Releases stay `0750`
   directories and `0640` files. No world-readable workaround was
   introduced.

   **What this does and does not prove.** Phase 5 proves that *a brand-new
   supported VPS can be reproducibly bootstrapped and made into a working
   RateGuru application host*. It does not prove disaster recovery, and
   nothing in Phase 7 is closed by it — the B2 offsite `restore-test` that
   passed here verifies that a backup is restorable, which is a different
   question from reconstructing a lost application. Phase 7 remains
   responsible for the durable release archive, the backup ↔ release
   mapping, rebuilding after server/data loss, restoring application state
   on a clean server from durable/offsite material, application-level
   verification after recovery, and the timed recovery drill. See the
   "Three distinct rehearsal gates" section below.

Phase 5 is therefore complete with these guarantees: a supported clean VPS
can be turned into a working RateGuru application host from committed
infrastructure alone, through one canonical entry point, with every slice
independently verifiable and idempotent; external secret material is never
generated, only required; a host that cannot be made safe fails closed
before mutating anything; PRE_DEPLOY is a first-class state rather than
something to fabricate around; and the first deployment, rollback, backup,
offsite backup and restore-test all work on a host that was empty the day
before.

Next phase: Phase 6 — Sentry observability activation.

## 6. Sentry observability activation — current

Wire the existing observability foundation (DomainLogger, exception context)
to Sentry for staging and production, with release tagging and PII redaction.
The phase overall proves: **we can see and diagnose failures before
production**.

This phase became **current** when Phase 5 closed. Slices 6.1–6.5 landed
together — code, configuration, workflows and runbook — and each records its
own acceptance state as it is met; the criteria below all require a real
staging deployment with a real DSN and a configured GitHub Environment, so
none of them can be met by CI alone. What landed:
`sentry/sentry-laravel` with the canonical release identity read from the
artifact's own `release.json` (never Git, never a second version string),
`environment` as the environment class with `deployment_target` as a separate
tag, one capture path through `Integration::handles()` covering HTTP, Artisan
and queue failures, `send_default_pii=false` with the internal user ID added
back deliberately, SQL bindings hardcoded off, profiling/structured
logs/metrics off, `/up` excluded from performance transactions, and a shared
`.github/actions/sentry-release` composite action that records the deployment
marker only after the existing health checks pass and can never fail a healthy
deployment. Slice 6.6 (alerts) is manual Sentry-UI work documented in
`runbooks/sentry-observability.md`, not code. Slices, in order:

1. **6.1 Sentry SDK, environments and release identity.** Connect Laravel to
   Sentry while preserving current application behavior, and establish the
   canonical Sentry identity/context: target ID, environment class, release
   ID, source SHA, and application/version identity. Staging and production
   must be distinguishable. *Acceptance:* a controlled staging exception is
   attributed in Sentry to the exact target, environment class, deployed
   release and source SHA — answering "can an exception be attributed to
   exactly what code and target was running?".
2. **6.2 Exceptions and application/domain context.** Connect application
   exception reporting and the existing DomainLogger/domain context to
   Sentry: correlation/request identity, target, release, domain operation,
   and authenticated-vs-anonymous state where safe. Expected
   validation/domain errors must not become high-severity incidents, and no
   PII may leak. *Acceptance:* representative Laravel/domain failures carry
   enough context to diagnose the actual operation that failed.
3. **6.3 Queue, Artisan and scheduler observability.** HTTP monitoring alone
   is insufficient — background failures can remain invisible. Instrument
   queue jobs, Artisan commands and scheduler executions; every event
   carries target, release and job/command identity. *Acceptance:* a
   controlled failed queue job plus a failed command/scheduled run appear
   correctly in Sentry.
4. **6.4 Performance and tracing.** Add controlled performance visibility
   only after error reporting works reliably: HTTP requests, database-heavy
   requests, queue jobs, outbound HTTP calls. Maximal trace collection is
   never enabled by default. *Acceptance:* useful traces exist on staging
   without unacceptable overhead or noise.
5. **6.5 PII, sampling and noise policy.** Make the Sentry configuration
   safe enough for production: request-body policy, headers/cookies policy,
   PII redaction, sampling, expected-error filtering, bot/noise
   suppression. *Acceptance:* synthetic sensitive values intentionally
   submitted during testing never appear in Sentry, and expected
   operational noise does not dominate incident signal.
6. **6.6 Alerts and staging acceptance.** Convert telemetry into actionable
   operations: alerts for new serious exceptions, error-rate spikes,
   queue/job failures, and meaningful performance regressions where
   justified. *Acceptance:* controlled staging failures verify the
   notification path end to end.

## 7. Disaster recovery and release rehearsal — planned (7.1 implemented)

Rehearse recovery end to end. The phase overall proves: **we can lose the
whole server and recover correctly**. It explicitly distinguishes four
distinct activities that must never be conflated:

1. **Backup creation** — producing a verified local + offsite backup artifact
   (database dump, storage, environment, server-configuration snapshot,
   checksums). *Proves a backup exists.*
2. **Restore-test** — restoring the latest backup into a throwaway/scratch
   database and asserting integrity (e.g. migrations table row count). *Proves
   the backup is restorable.* Runs on the existing server; does not rebuild the
   host. It is deliberately never turned into a live restore operation.
3. **Clean-server recovery rehearsal** — provisioning a brand-new empty VPS from
   committed infrastructure (Phase 5 bootstrap), then restoring a backup onto
   it and bringing the app up. *Proves we can rebuild the whole host from
   scratch + backups*, not just the database.
4. **Production disaster recovery** — the real, timed, documented procedure for
   recovering the live production target after data loss or host loss, with
   RPO/RTO targets, DNS/TLS cutover, and a communications checklist. *The real
   event, not a drill.*

**Slice 7.1 is implemented; the phase itself is not yet the current one.**
Phase 6 remains the single current phase until its manual Sentry alert
configuration and staging acceptance (slice 6.6) and the Phase 6B Nightwatch
evaluation close — neither is code, and neither is blocked by 7.1. 7.1 landed
ahead of that sequence because release-artifact durability is a standing
exposure on every deployment, not a rehearsal step: GitHub artifact retention
is three days, and until the archive existed, a VPS lost on day four took the
only copy of the running build with it.

**Rollback already exists and is not Phase 7 work.**
`.github/workflows/rollback-staging.yml` and
`infrastructure/scripts/rollback` were delivered in Phases 1–4 and work
today. No slice below reimplements, redesigns or replaces rollback: rolling
back to a previously deployed release on a live host is a different problem
from reconstructing a lost one, and the two must never be conflated.

Slices, in order:

1. **7.1 Durable immutable release artifact archive — implemented.** GitHub
   artifact retention is finite (3 days), and the data backup only tells us
   what data existed — total VPS loss also requires the exact application
   artifact that was running. Every release that is allowed to become active
   is now durably archived to Backblaze B2 at
   `rateguru-release-artifacts/rateguru/artifacts/<release-id>/`, holding the
   exact tarball, its SHA-256 sidecar and its own `release.json`. What
   landed: `infrastructure/scripts/archive-release-artifact` and
   `infrastructure/scripts/fetch-release-artifact` over a shared,
   self-contained `release-artifact-common` contract (neither sources
   `bin/common`, because their consumers are a build runner before any
   deployment and, later, a clean recovery host);
   `infrastructure/scripts/fetch-verified-rclone`, which installs the pinned,
   signature-verified rclone from the existing committed external-runtime
   contract without root; a `.github/actions/archive-release-artifact`
   composite action that is transport only; and an `archive` job wired
   between `build` and `deploy` in `deploy-staging.yml`.

   Design decisions, all deliberate:

   - **A separate bucket.** Release artifacts never live inside the
     `rateguru-database-backups` namespace, so backup retention and
     release-artifact retention can never affect each other.
   - **Archived from CI, before deployment.** The storage that protects us
     from host loss must not require the host being protected in order to
     create the durable archive, so the artifact is never uploaded to B2
     through the staging VPS.
   - **A hard precondition.** `deploy` `needs` `archive`; a failed archive
     means the deployment never starts. Nothing on that path is
     `continue-on-error` — unlike the Sentry marker, which is observability
     and may never fail a healthy deployment.
   - **Project-scoped, never target-scoped.** No `staging-main` or
     `tits-guru` segment exists in the archive path: the same immutable
     application artifact is a project artifact. `rateguru` is a fixed,
     trusted constant and never operator input, so no flag can archive a
     RateGuru artifact under another project's namespace — while leaving room
     for a future `cataloghub/artifacts/…` without collisions.
   - **One release.json.** The build copies the document it already froze
     into the tarball out beside it; both the archive and the retrieval
     require the two to be byte-identical, so recovery never reads metadata
     the artifact itself does not carry.
   - **Immutable semantics.** Absent → upload; identical → idempotent no-op;
     a strict subset with every present object identical → upload only what
     is missing; anything differing, or an unexpected object under the
     release ID → hard fail. No delete, no replace, no mutation, and **no
     retention or garbage collection at all** in this slice.
   - **GitHub's artifact stays.** It remains 3-day CI transport and
     debugging material; its retention is never raised as a substitute for
     B2.

   Credentials are the repository secrets `B2_ARTIFACT_KEY_ID` /
   `B2_ARTIFACT_APPLICATION_KEY` and the repository variable
   `B2_ARTIFACT_BUCKET`, with the B2 application key restricted to the
   release-artifact bucket. They are deliberately repository-level rather
   than bound to the `staging` GitHub Environment, never reach a VPS, and are
   written only into a `0600` temporary rclone configuration inside
   `RUNNER_TEMP` that is removed under `always()`. See
   `runbooks/release-artifact-archive.md`.

   *Acceptance:* an old release can be retrieved and checksum-verified
   without depending on GitHub artifact retention. **Pending on the real
   staging pipeline** — it requires the repository secrets/variable and one
   real staging deployment, so CI alone cannot meet it. The exact operator
   steps are in the runbook.
2. **7.2 Backup ↔ exact release / recovery-point mapping.** A data backup
   must deterministically identify the exact application release that
   belongs to it. Strengthen backup metadata: target, release ID, source
   SHA, artifact reference, artifact checksum, backup time/schema/version
   metadata, and the durable archive path from 7.1. *Acceptance:* starting
   from a backup manifest, recovery identifies the exact deployable artifact
   without guessing or rebuilding from source.
3. **7.3 Restore Target Data on an existing host.** The server remains
   alive; only its data is restored. Pre-restore safety backup;
   DB-only / storage-only / DB+storage modes; maintenance mode; queue and
   scheduler safety; verified backup selection; application verification
   afterwards. Explicitly distinct from the existing restore-test, which
   restores into a throwaway scratch database and stays that way.
   *Acceptance:* a target's data is restored in place, verified, and the
   application is healthy again, with a safety backup available throughout.
4. **7.4 GitHub Restore Target Data operator workflow.** Turn 7.3 into an
   operator-facing workflow: plan before mutation, staging execution,
   production approval gating later, and a structured result report.
   *Acceptance:* a staging data restore runs end to end from a workflow
   dispatch, with the plan visible before anything mutates.
5. **7.5 Repair Target.** The host remains alive, but a target's runtime,
   perimeter or release state is broken or drifted. Reconstruct that target
   — identities, filesystem, perimeter, services, current release — without
   rebuilding the whole VPS. *Acceptance:* a deliberately damaged staging
   target is repaired in place and verifies clean.
6. **7.6 Clean-host recovery orchestration / clean-host drill.** Prove
   recovery from total VPS loss: new Ubuntu VPS → Phase 5 bootstrap →
   retrieve the exact immutable artifact through 7.1's
   `fetch-release-artifact` → recover secrets/environment → restore database
   → restore storage → deploy the exact release → start the target → health
   check. Explicitly distinct from restore-test: restore-test answers "can
   this backup technically be restored?"; 7.6 answers "can the entire host
   disappear and the application be reconstructed?".
7. **7.7 Application-level recovery verification.** A successful pg_restore
   is not enough. Verify actual recovered application behavior: Laravel
   boots, DB queries work, migration state is coherent, storage/media works,
   queues work, the scheduler works, representative smoke tests pass.
   *Acceptance:* the recovered application behaves like a valid running
   target, not merely a restored PostgreSQL database.
8. **7.8 GitHub Recover Host workflow / disposable-host rehearsal.** Turn
   7.6 into a repeatable operator workflow driven against a disposable
   rehearsal host, under the disposable-rehearsal policy below.
   *Acceptance:* a disposable host is recovered end to end from a workflow
   dispatch, without hand-run commands.
9. **7.9 Full timed DR drill, RPO/RTO and final production recovery
   runbook.** Rehearse full host loss with backup selection, release
   selection, provisioning, restore, DNS/TLS implications, verification and
   fallback; measure real recovery duration; define RPO and RTO; produce the
   final disaster-recovery runbook.

## 8. First production launch and target-provisioning proof — planned

First production target go-live on `tits.guru`, launched through a generic,
rehearsed provisioning procedure rather than hand-built commands. The phase
overall proves: **we can launch the first production site using a rehearsed
procedure**. Slices, in order:

1. **8.1 Generic target provisioner.** A target described in
   `deployment-targets.json` must be provisionable reproducibly rather than
   through one-off manual commands. Build a generic target provisioning
   mechanism that will eventually create/configure target identities,
   filesystem, database and role, environment placement, PHP-FPM pool,
   Nginx vhost, Supervisor worker, scheduler, backup namespace, perimeter
   integration and health identity. Lifecycle gating remains mandatory: a
   `planned` target existing in the registry must NOT become publicly
   active just because provisioning tools exist. *Acceptance:* a temporary
   test target can be provisioned without hand-writing target-specific
   server commands.
2. **8.2 Disposable multi-site rehearsal.** Before real production, prove
   the architecture can create multiple independent new brand targets from
   scratch — on a separate disposable rehearsal VPS, never by destroying
   the long-lived staging host. Conceptual temporary targets (`tits-test`,
   `food-test`, `animals-test`) on isolated rehearsal DNS names (e.g.
   `tits.rehearsal.<technical-domain>`), each independently owning its
   application root, `.env`, database/role, FPM pool/socket, queue,
   scheduler, release history, storage, backup namespace and health
   identity. Exercise deploy, rollback, backup, restore and target
   isolation. After acceptance: destroy the rehearsal targets/VPS, then
   recreate them again from committed infrastructure. Use a separate B2
   rehearsal namespace — the real staging backup namespace is NEVER
   deleted to make this test clean. *Proves:* we know how to create new
   sites from scratch, not merely maintain staging.
3. **8.3 Provision real tits-guru target.** Create the first real
   production target using the generic mechanism already proven in
   8.1/8.2 — `tits-guru` must not become a hand-built exception. Provision
   production infrastructure while keeping public activation controlled.
4. **8.4 Production secrets, database, TLS, mail and backups.** Supply the
   production-only external material and services: production `.env`, DB
   credentials, deploy key, TLS, production backup credentials/policy,
   production mail delivery, inbound replies/bounces where applicable, and
   SPF/DKIM/DMARC domain authentication. No production secrets in Git.
   *Acceptance:* the target is internally functional, observable and backed
   up before public traffic is enabled.
5. **8.5 Production GitHub release/deploy flow.** Prove the real immutable
   production deployment path: a production GitHub Environment, a
   reviewed/tagged immutable artifact, and the same exact artifact carried
   through deployment. Application code is never rebuilt on the server.
   Test production rollback mechanics before public launch where safely
   possible.
6. **8.6 Exact production dress rehearsal.** Perform the production
   procedure one final time without exposing the real public service, on
   production-like configuration and isolated rehearsal DNS/traffic:
   provision → secrets → deploy → TLS → health → Sentry → backup →
   restore/recovery check → rollback. No infrastructure operation performed
   during the eventual real launch should be happening for the first time.
7. **8.7 tits.guru GO LIVE.** The actual first public production
   activation. Only final state changes remain: lifecycle activation where
   required, public DNS/routing, TLS/public health verification, monitoring
   confirmation. Immediately verify health, smoke tests, backup,
   queues/scheduler, Sentry, and mail where applicable.

## 9. Repeatable production target onboarding — planned

Turn the first production launch into a routine, repeatable procedure on the
multi-target model from Phase 4. The phase overall proves: **adding another
production site is routine**. Slices, in order:

1. **9.1 Formal target onboarding template.** Turn the successful first
   production launch into a formal reusable contract: a new site
   specification explicitly defines target ID, domains,
   branding/application configuration, environment, DB identity, backup
   retention, mail identity, monitoring identity, and the secrets
   required. *Acceptance:* a complete target specification can be reviewed
   before any server mutation.
2. **9.2 Second real production brand.** The second real production site
   proves the system is actually generic rather than merely generalized
   around tits.guru. Provision using the standard workflow, with no
   target-specific infrastructure exceptions.
3. **9.3 Independent deploy / rollback / backup proof.** Prove real target
   isolation: deploy target A → target B unchanged; rollback target B →
   target A unchanged; backup/retention in isolated namespaces; health is
   target-specific. *Acceptance:* operational changes to one production
   target do not mutate another.
4. **9.4 Third-target repeatability.** The second target may still expose
   assumptions and receive one-off fixes; the third target proves
   onboarding is routine. Measure the remaining manual host steps — the
   desired result is approximately zero manual server customization.
5. **9.5 Reconsider shared infrastructure extraction.** Only after 2+ real
   production brands/projects exist, reconsider moving shared
   infrastructure out of RateGuru, evaluated using actual duplication.
   Possible future forms: a separate infrastructure repository, a shared
   tooling/package, or a subtree/submodule/synchronization model.
   Infrastructure is NOT extracted merely because this roadmap slice
   exists.

## 10. Advanced observability and product analytics — planned / optional

Optional analytics and advanced operational dashboards, evaluated after the
core production and recovery phases are stable. The phase overall proves:
**we can efficiently operate and understand a mature multi-target
platform**. Slices, in order:

1. **10.1 Nightwatch evaluation.** After Sentry and production are stable,
   determine whether Laravel-native Nightwatch provides additional value
   rather than duplicating Sentry. Evaluate first; never install
   automatically. **Brought forward and in progress as Phase 6B**: the
   package is installed, disabled by default, and runs a Supervisor-managed
   agent on staging-main alone, side by side with an unchanged Sentry — see
   [`runbooks/nightwatch-evaluation.md`](runbooks/nightwatch-evaluation.md).
   Phase 6C decides between Sentry only, Nightwatch only, or both; production
   activation belongs after that decision, never before it.
2. **10.2 PostHog product analytics.** Separate product/user behavior
   analytics from operational error monitoring: Sentry answers "why did
   the application fail?", PostHog answers "how is the product being
   used?". Define the privacy/event/retention policy before any
   instrumentation.
3. **10.3 Internal infrastructure/admin dashboard.** Expose reliable
   operational state inside the RateGuru admin UI: server/target identity,
   current release, source SHA/tag, last deploy, last backup, last
   restore-test, last offsite backup, queue state, scheduler heartbeat,
   runtime versions, observability state. Prefer structured
   machine-readable operational state over parsing human logs.
4. **10.4 Aggregate host / target health.** Once multiple targets exist,
   provide an operational overview (conceptually: `staging-main` healthy,
   `tits-guru` healthy, `food-guru` degraded, `animals-guru` healthy).
   The aggregate dashboard does NOT replace per-target health/readiness
   gates — deployment remains target-specific.
5. **10.5 SLI/SLO and alert tuning.** After real production behavior is
   known, formalize meaningful service objectives — availability, latency,
   error rate, queue delay, backup freshness, restore-test freshness — and
   tune thresholds using real data rather than guessed pre-production
   values.

## Three distinct rehearsal gates

The roadmap deliberately contains three different infrastructure exams. They
test different failure domains and must never be collapsed into one generic
rehearsal:

- **Phase 5.6 — "Can we build a completely empty server?"** Proves host
  bootstrap and reproducibility: a brand-new VPS becomes a working host
  purely from committed infrastructure. **Passed** — see slice 5.6.
- **Phase 7.6 + 7.9 — "Can we recover after complete server/data loss?"**
  Proves disaster recovery: the host and its data disappear, and the
  application is reconstructed from offsite backups plus the archived exact
  release artifact, within measured RPO/RTO. **Still outstanding.** 5.6
  exercised an offsite `restore-test` — proof that a backup is restorable —
  which is a different question from reconstructing a lost application, and
  closes nothing here. Slice 7.1 landed the durable release-artifact archive
  and its verified retrieval primitive, which is the material 7.6 consumes;
  it closes no rehearsal gate on its own.
- **Phase 8.2 + 8.6 — "Can we repeatedly create new sites and execute the
  exact production launch procedure without first-time surprises?"** Proves
  target onboarding and production readiness: multiple independent targets
  from scratch, then a full production dress rehearsal so the real launch
  contains no first-time operations.

## Disposable rehearsal policy

The long-lived staging VPS is NOT destroyed merely to test clean bootstrap
or new-site provisioning. Destructive rehearsals use a disposable rehearsal
VPS instead, because:

- staging retains realistic accumulated state;
- staging retains useful deployment/release history;
- staging remains available during destructive testing;
- a genuinely empty machine is a stronger bootstrap test;
- a rehearsal host can be destroyed and recreated repeatedly.

Rehearsal resources must be isolated from real ones in every namespace:
target IDs, DNS names, databases, secrets, and the backup namespace. The
real staging B2 backup namespace is never reused or deleted for rehearsal.
Disposable rehearsal resources may be completely destroyed after
acceptance.
