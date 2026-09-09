# Clean-host recovery: the operator runbook

This is the one document an operator needs to recover `staging-main` onto a
brand-new machine, from nothing but a clean VPS and one offsite backup —
without reading the source and without remembering earlier work. It covers
what to prepare, what to configure, how to run the recovery, how to verify
the result without touching DNS, and what to do when a run stops half way.

It describes the operation as it exists today; nothing here changes it. The
mechanics live in [`github-recover.md`](github-recover.md) (the workflow),
[`recover-host.md`](recover-host.md) (the server-side state machine) and
[`prepare-host.md`](prepare-host.md) (the preparation). The compact form of
this runbook is printed by:

```bash
infrastructure/scripts/recovery-host-preflight --operator-guide --target staging-main
```

The public hostname of `staging-main` is `rateguru.staging.myprojects.pp.ua`
(registry: `public_hostnames`), and its GitHub Environment is `staging`.

---

## A. What to prepare on the new VPS

The replacement machine is a **genuinely clean replacement host**. It is not
the current staging host, not a machine that was recovered before, and not a
machine anything RateGuru-shaped was ever installed on.

| Requirement | Value |
|---|---|
| Operating system | Ubuntu **22.04** exactly (`ID=ubuntu`, `VERSION_ID="22.04"` in `/etc/os-release`) — the bootstrap installers refuse every other release |
| Architecture | **x86_64** (`amd64`) — the pinned runtime binaries and packages exist for it only |
| SSH access | the SSH server listening on the port you will pass as `replacement-port` (normally 22) |
| Recovery user | the account named by `RECOVERY_BOOTSTRAP_USER`: `root`, or a user with **passwordless** non-interactive `sudo` |
| Its key | the **public** half of `RECOVERY_BOOTSTRAP_SSH_KEY` in that user's `~/.ssh/authorized_keys` — the only thing you install |

**Install nothing else by hand.** No packages, no Nginx, no PostgreSQL, no
PHP, no Supervisor, no `.env`, no TLS certificate or key, no Basic Auth
file, no rclone configuration, no RateGuru user or directory. Prepare Host
installs the whole runtime, and it takes the environment file and every
host-scope prerequisite out of the backup itself. A machine that already
carries RateGuru state is refused, by name, before anything on it changes
(§G): the recovery would otherwise turn into a partial repair of unknown
state.

---

## B. Prove bootstrap access from your own machine

With the private half of the recovery key on your machine:

```bash
ssh -i <recovery key> -o IdentitiesOnly=yes -p 22 <RECOVERY_BOOTSTRAP_USER>@<IP> 'id -u'
```

`0` means the user is root. For any other user, this must succeed without a
prompt:

```bash
ssh -i <recovery key> -o IdentitiesOnly=yes -p 22 <RECOVERY_BOOTSTRAP_USER>@<IP> 'sudo -n true'
```

If either asks for a password or is refused, fix the machine first; the
workflow refuses on exactly the same conditions and names them.

---

## C. Record the SSH host key, verified out of band

The workflow never scans a host key and never trusts one on first use. You
record it, and you verify it over a channel you trust before you do.

1. From your machine:

   ```bash
   ssh-keyscan -t ed25519 -p 22 <IP>
   ```

   The output is one line: `<IP> ssh-ed25519 AAAA…`.

2. Verify it **out of band** — against the provider's console, or against
   `/etc/ssh/ssh_host_ed25519_key.pub` read on the machine itself over the
   console. The `ssh-ed25519` key must be identical.

3. Store exactly that one verified line as `RECOVERY_KNOWN_HOSTS`. Use the
   address or hostname you will pass as `replacement-host`, in the same form
   (if the port is not 22, the line is `[<IP>]:<port> ssh-ed25519 …`).

The workflow also compares this key with `DEPLOY_KNOWN_HOSTS`, the current
host's key: an identical `ssh-ed25519` key means you named the machine the
target is already bound to, and the run is refused before it connects
anywhere.

---

## D. GitHub Environment values

Everything below lives in the `staging` GitHub Environment (Settings →
Environments → staging). **The kind matters.** A value created under the
wrong heading does not exist to the workflow: the recovery then stops before
it connects anywhere, names the value, and names the heading to create it
under.

**Environment variables** (Settings → Environments → staging → Environment variables):

| Value | Meaning |
|---|---|
| `RECOVERY_BOOTSTRAP_USER` | the privileged user on the replacement machine (§A) |
| `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_ROOT`, `DEPLOY_INCOMING`, `DEPLOY_WRAPPER` | existing: the target's ordinary deployment identity, **unchanged** |

**Environment secrets** (Settings → Environments → staging → Environment secrets):

| Value | Meaning |
|---|---|
| `RECOVERY_BOOTSTRAP_SSH_KEY` | the complete OpenSSH private key for that user — recovery-only, never the deploy key |
| `RECOVERY_KNOWN_HOSTS` | the one verified `known_hosts` line from §C |
| `RECOVERY_RCLONE_CONFIG` | the complete recovery `rclone.conf`, whose key can **read** the staging backup namespace. A secret, never a variable: created as an Environment variable it is absent to the workflow, and the run stops before it connects anywhere |
| `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` | existing: the deployment private key and the current host's `known_hosts` line, **unchanged** |

The workflow proves all of them present — by presence only, never by reading
a value — before it connects anywhere (§G, step 2). The refusal reads, for
the case that actually happened once:

```text
RECOVERY ACTION REQUIRED
Cause: the staging GitHub Environment has no RECOVERY_RCLONE_CONFIG secret
Do:
  1. Settings -> Environments -> staging -> Environment secrets
  2. create RECOVERY_RCLONE_CONFIG
  3. paste the complete contents of the recovery rclone configuration file (rclone.conf)
  4. do not create it as an Environment variable
Then: re-run "Recover staging host" with mode=start and the same exact backup
```

Three things that are deliberately not on that list:

* **No `PREPARE_*` value is read by a recovery.** The environment file, TLS,
  Basic Auth and the shared Nginx material come out of the backup on the
  host; the deploy public key is derived on the runner from `DEPLOY_SSH_KEY`.
  Supplying any of them by hand is refused.
* **`DEPLOY_HOST` is not changed.** It keeps pointing at the machine the
  target is bound to now. Repointing it is a separate, deliberate act after
  the recovered machine is adopted.
* **DNS is not changed.** Nothing in the recovery touches a DNS record.

---

## E. Which backup qualifies

* **Schema 3** — written after the recovery material joined the backup
  format. Every backup `backup` writes today is schema 3; a backup written
  before is refused by name ("not clean-host-recovery-capable … requires
  schema 3"), with no fallback to hand-supplied material.
* Named by its **exact timestamp** `YYYYMMDD-HHMMSS`. There is no "latest".
* Carries `recovery-material.tar.gz` (the target's host-scope prerequisites
  under their logical names) and a `release.json` with a **full 40-character
  `source_sha`** — the exact commit the recovery rebuilds. A backup is only
  written for a deployed target that can name it.
* Has **passed the offsite restore test**: the nightly `offsite-restore-test`
  downloads the backup, verifies every checksum and certifies its recovery
  material against the installed prerequisite table. A backup it refused is
  one a recovery could not be prepared from.

To list what is in the namespace, with the recovery rclone configuration:

```bash
rclone --config <recovery rclone config> lsf rateguru-b2:rateguru-database-backups/rateguru/staging/
```

---

## F. Run it

Actions → **Recover staging host** → Run workflow:

```text
mode             = start
backup           = YYYYMMDD-HHMMSS
replacement-host = <IP>
replacement-port = 22
```

Leave `operation` empty: the server assigns one. That is the entire input.

---

## G. What the workflow does by itself

In order, and it stops at the first refusal:

1. **validate** — the request (mode, exact backup, address, port) and the
   target's lifecycle from the committed registry; no environment, no secret.
2. **values** — every value of §D is present in the `staging` Environment,
   judged by presence only; a missing one is named with the heading to
   create it under (variable or secret). No connection, nothing read.
3. **binding** — the replacement host is not the current host: by the
   literal `DEPLOY_HOST` value, and by the `ssh-ed25519` host key in
   `DEPLOY_KNOWN_HOSTS` versus `RECOVERY_KNOWN_HOSTS`. No connection.
4. **preflight** — connects as the recovery user, proves root or
   passwordless sudo, uploads the read-only `recovery-host-preflight` and
   runs it: Ubuntu 22.04 exactly, x86_64, and **no RateGuru state** — no
   `/home/www/rateguru`, no release, no guard, no RateGuru database or role
   where PostgreSQL exists, no RateGuru-managed Nginx site, PHP-FPM pool,
   Supervisor program, cron entry, systemd unit, sudo wrapper or sudoers
   grant, no RateGuru account. Absent PostgreSQL, Nginx and Supervisor are
   what a clean machine looks like and pass. A refusal changes nothing and
   names the reason; Prepare Host does not run.
5. **deploy-identity** — derives the deploy public key from `DEPLOY_SSH_KEY`
   on the runner; the private key never leaves it.
6. **prepare** — Prepare Host in its recovery form: runtime, then
   `fetch-recovery-material` takes the environment file and the host-scope
   material out of the backup (manifest first, so an older backup is refused
   by name), host material, bootstrap, the **offsite-write hold**, target
   material, database, then an independent verification that requires the
   hold.
7. **recover** — `recover-host --apply`: the same exact backup, verified
   from scratch, the prepared `shared/.env` compared byte-for-byte with the
   backup's, database and storage restored, the host left deliberately not
   serving, a recovery guard on disk. The server names the commit.
8. **build** — the exact `source_sha` from the backup's own `release.json`,
   with today's trusted tooling. No fallback ref.
9. **deploy** — the ordinary deploy in its controlled recovery mode:
   **migrations forbidden**, the host stays held.
10. **resume** — `recover-host --resume`: proves the deployed commit is the
   one the data belongs to and the migration count is unchanged, restores
   the scheduler entry and the queue, health check, clears the guard.
11. **verify** — `recover-host --verify`, read-only and independent: the
    final contract (§H), including `OFFSITE WRITES: HELD`.
12. **observability**, **report** — the deployment marker, and the summary.

---

## H. Verify the recovered host

On the replacement machine, as root:

| Check | Expect |
|---|---|
| `readlink -f /home/www/rateguru/staging/current` | a release directory under `releases/` |
| `jq -r .source_sha /home/www/rateguru/staging/current/release.json` | the backup's exact `source_sha` (the summary shows it) |
| `ls /home/www/rateguru/staging/previous` | **absent** — a freshly recovered host has had exactly one deployment |
| database | the backup's data (a sentinel row you planted before the backup, if any) |
| `/home/www/rateguru/staging/shared/storage/app` | the backup's files (a sentinel file, if any) |
| `supervisorctl status rateguru-staging-queue:*` | RUNNING |
| `ls /etc/cron.d/rateguru-staging-scheduler` | present |
| `curl -fsS -H 'Host: rateguru-staging.internal' http://127.0.0.1/up` | healthy |
| `ls /home/www/rateguru/run/restores/staging-main/ /home/www/rateguru/run/recoveries/staging-main/` | no `restore-guard`, no `recovery-guard` |
| `cat /home/www/rateguru/run/offsite-write-hold` | present: `{"hold":"offsite-writes", …}` |
| `sudo /home/www/rateguru/bin/backup-cycle --target staging-main` | refuses with `OFFSITE WRITES: HELD` |

Or in one command, from the operational bundle the recovery installed:

```bash
sudo /home/www/rateguru/bin/recover-host --verify --target staging-main
```

which prints `RECOVERED: YES`, `PREVIOUS: absent (normal for a freshly
recovered host)`, `HEALTH: PASS   QUEUE: RUNNING   SCHEDULER: PRESENT` and
`OFFSITE WRITES: HELD`.

`--verify`, `--inspect` and `--resume` need no bootstrap tooling and run from
that installed copy. `--check` and `--apply` do not: both prove the machine is
prepared by running the `prepare-host` **beside** them, and the operational
bundle deliberately carries none, so they run from the trusted infrastructure
bundle instead (§K). The installed copy says so rather than pretending.

---

## I. Check the site without changing DNS

DNS still points at the current host. Ask the recovered machine directly,
with the real hostname, so TLS and the vhost match:

```bash
curl --resolve rateguru.staging.myprojects.pp.ua:443:<IP> -fsS https://rateguru.staging.myprojects.pp.ua/up
curl --resolve rateguru.staging.myprojects.pp.ua:443:<IP> -sS -o /dev/null -w '%{http_code}\n' https://rateguru.staging.myprojects.pp.ua/
```

For a browser session, an entry in your own `/etc/hosts` does the same
thing, temporarily:

```text
<IP>  rateguru.staging.myprojects.pp.ua
```

Remove it afterwards. Nothing about the real DNS record changes either way.

---

## J. What counts as success

All of the following, and nothing less:

* TARGET is still `staging-main`; HOST is the replacement machine.
* The data, the storage and `shared/.env` are the exact backup's (schema 3).
* `current` is the backup's exact `source_sha`; `previous` is absent.
* No migration ran.
* Queue RUNNING, scheduler PRESENT, health PASS, no guard.
* `OFFSITE WRITES: HELD` on the recovered machine.
* `DEPLOY_HOST` unchanged, DNS unchanged, the old host untouched, the real
  offsite namespace neither written to nor pruned.

The run's summary states every one of those facts (no secrets).

---

## K. When a run stops half way

A recovery routinely outlives the run that started it, and nothing is
cleaned up to make a run look green. Read the summary:

* **Stopped before an operation ID exists** (request, environment values,
  binding, preflight, deploy identity, or Prepare Host): nothing is held on
  the machine. Fix
  what the `RECOVERY ACTION REQUIRED` block names and re-run with
  `mode=start`. If Prepare Host itself failed part way, the same clean
  machine can usually be prepared again — preparation is convergent — but a
  refused preflight means a **new** machine.
* **Stopped after `recover-host --apply` started** (an operation ID is in
  the summary): the data is restored and the host is held. Re-run with:

  ```text
  mode             = continue-held
  operation        = <the operation ID from the summary>
  replacement-host = <the same IP>
  ```

  The workflow asks the server which stage the recovery is at:
  `awaiting-code` rebuilds and redeploys the exact commit, `ready-to-resume`
  skips straight to the resume. It never prepares again and never takes a
  second backup.
* **Resume reported a failure but the final verify passed**: the lost-runner
  case — the server finished and cleared its guard before the runner read
  the result. The host is complete; do not re-run.
* **The offsite-write hold is missing**: `--inspect` and `--verify` refuse
  and name the remediation; `--resume` re-establishes it. See
  [`recover-host.md`](recover-host.md).
* **The recovery refused the prepared/EMPTY contract**
  (`does not satisfy the prepared/EMPTY recovery contract`): the recover job's
  log lists every problem it found, one line each, above that verdict — read
  those first, because the verdict alone says nothing actionable. Nothing was
  created or changed on the machine.

  Diagnose it read-only, from a checkout of `develop` **on the host**, which
  is the same trusted bundle the workflow uploads:

  ```bash
  sudo <checkout>/infrastructure/scripts/recover-host \
    --check --target staging-main --backup YYYYMMDD-HHMMSS
  ```

  Not `/home/www/rateguru/bin/recover-host --check`: the installed operational
  bundle carries no `prepare-host`, so that copy cannot prove a machine is
  prepared and refuses `--check` by name instead of guessing.

  **Delete nothing until the failed precondition is known.** §M is what a
  prepared, never-deployed host is supposed to look like; a machine that does
  not match it is either not prepared, or not the machine you think it is, and
  in both cases the answer is a new one — never a hand-cleaned one.

---

## L. Never

* Never change `DEPLOY_HOST` before the recovered machine is deliberately
  adopted; never change DNS for a rehearsal.
* Never delete a restore guard, a recovery guard or the offsite-write hold
  by hand to make a step pass.
* Never run a migration on the recovered host during the recovery: the exact
  commit the data belongs to is what gets deployed.
* Never copy `.env`, TLS material, Basic Auth hashes or an rclone
  configuration onto the host by hand; the backup and the runner supply
  them.
* Never weaken `StrictHostKeyChecking`, scan a host key from the workflow,
  or accept a key on first use.
* Never run `backup-cycle`, `offsite-backup` or `offsite-retention` on the
  recovered rehearsal host while the offsite-write hold exists — they refuse,
  and releasing the hold is part of adopting the machine, not of a rehearsal.
* Never reuse a machine that was refused by the preflight, or "clean it up"
  for a recovery: provision a new one.

---

## M. What a prepared, never-deployed host looks like

This is the PRE_DEPLOY state Prepare Host leaves behind, and the state
`recover-host --apply` requires. It is what "prepared and EMPTY" means:

| Thing | Expected |
|---|---|
| `current` | **absent** |
| `previous` | **absent** |
| `releases/` | **empty** |
| database | **exists**, with **0 tables** in the `public` schema |
| `shared/storage/app` | **absent**, or present and empty (an empty `public/` is allowed) |
| `shared/.env` | **present** — placed by Prepare Host from the backup |
| queue program configuration | **installed and valid** (`supervisorctl reread` parses it) |
| Supervisor runtime group | **may be absent** — see below |
| queue worker | definitely **not RUNNING** |
| scheduler | as Prepare Host installed it |
| offsite-write hold | **present** |
| restore guard, recovery guard | **absent** |

**A Supervisor runtime group that is absent is normal here, not an error.**
The services installer installs the queue program's configuration, validates
it with `supervisorctl reread` — which parses and adds nothing — and defers
`supervisorctl update` until a release exists, because the committed program
sets `autostart=true` and a prepared host has no application for a worker to
run. So a prepared, never-deployed host answers

```text
rateguru-staging-queue: ERROR (no such group)
```

about its own queue, with exit status 4. For a clean-host recovery that is the
normal PRE_DEPLOY state and holds the target more firmly than `STOPPED` does:
a group the running Supervisor does not know cannot have a process running in
it. The recovery accepts it only on that exact evidence — status 4, that one
line about this target's own group and nothing else, and the program
configuration installed on the machine — and refuses everything else,
including an unreachable Supervisor, which reports itself differently. The
recovery's `--resume` is what finally adds the group, from the configuration
already installed, at the moment the target legitimately starts serving.

A **live** target answering the same thing is a different matter entirely, and
Restore Target Data still fails closed on it: there, the group vanished from
under a running application.

---

## See also

* [`github-recover.md`](github-recover.md) — the workflow, its trust
  boundaries and the full acceptance checklist
* [`recover-host.md`](recover-host.md) — the server-side state machine, its
  guard, the hold and the final contract
* [`prepare-host.md`](prepare-host.md) — the preparation, including its
  recovery form
* [`backups.md`](backups.md) — the backup format, schema 3 and the offsite
  restore test
