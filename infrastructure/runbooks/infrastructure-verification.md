# Infrastructure verification

Two permanent operator commands verify everything a target depends on, on its
host, without changing anything:

| Workflow | Target | Trusted tooling | GitHub Environment |
|----------|--------|-----------------|--------------------|
| **Verify staging infrastructure** | `staging-main` | `develop` | `staging` |
| **Verify production infrastructure** | `tits-guru` | `main` | `production-tits-guru` |

They are read-only and repeatable: run them ten times in a row and the host is
exactly as it was. Nothing is chosen when they run — no target, environment or
ref dropdown — and there is no separate button per subsystem: no "Verify host",
"Verify database", "Verify backup" or "Verify mail". A new subsystem extends the
shared verifier, never the list of workflows.

## The operator model

| Operation | Purpose |
|-----------|---------|
| **Verify infrastructure** | diagnose all currently managed infrastructure, read-only |
| **Prepare Host** | converge the host and its global preparation state |
| **Repair Target** | converge the infrastructure around an already-live release |
| **Configure Target** | establish a planned production target's material and database |
| **Deep acceptance** | deliberately active or disruptive tests: mail end-to-end, restore tests |

What to do with a finding:

- A host or preparation finding → **Prepare Host** (Prepare staging host while
  staging-main is the active target on the shared machine).
- A live target finding → **Repair Target**.
- An incomplete planned production target → **Configure Target** (Configure
  tits.guru), or the onboarding step it still needs.

Verify itself never repairs anything.

## The contract

If RateGuru knows how to install, configure, provision or repair a piece of
infrastructure, Verify proves — read-only — that the state the target's
**current** lifecycle requires is in place: packages and runtime, users and
groups, the directory layout with its owners, modes and ACLs, sudoers and the
operational wrappers, cron, Nginx, PHP-FPM, Redis, Supervisor, PostgreSQL, the
target's prerequisites and database, its service configuration, queue and
scheduler, the public-storage ACL, the release structure, the backup perimeter,
mail capture, the mail gateway, mail identity and readiness, and the
application's health.

It proves it by calling the primitive that already owns each contract — never by
a copy of its rules.

## How it is built

```
contract owners (unchanged, still usable on their own)
  prepare-host --verify --target T          bootstrap-host --verify
  repair-target --verify --target T         configure-target --verify --target T
  bootstrap-host-preflight --report         install-target-perimeter --verify
  verify-mail-capture --read-only           verify-mail-gateway --read-only
  mail-identity validate | dkim-key | check-key | readiness
  activate-mail-inbound --verify --target T
  health-check --target T
        │
        ▼
infrastructure/scripts/verify-infrastructure --target T     (read-only orchestration)
        │
        ▼
.github/actions/verify-rateguru-infrastructure              (transport only)
        │
        ▼
Verify staging infrastructure / Verify production infrastructure
```

`verify-infrastructure` is repository tooling: the action packages the trusted
`infrastructure/` tree, uploads it into a root-only temporary directory on the
host, runs `verify-infrastructure --target T` as root (the bootstrap credential
— never the deploy key), prints the whole report, checks there is exactly one
well-formed `RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=<json>` line, writes its
group table into the job summary, and removes the upload whether the run passed
or failed. Every contract owner runs from that same bundle; one missing from it
stops the run before anything is verified.

It decides only which contract the target's **current reviewed state**
requires — its environment class and lifecycle from the registry, its mail
delivery mode from `mail-routing render-plan`, and whether the host enables
direct delivery from `mail-outbound.json`. It keeps no package list, user, mode,
service, database, sudoers, cron or layout rule of its own; it keeps no
hand-written list of host tools either. The one tool it needs before it can
resolve a target is `jq`; every other tool belongs to the host runtime contract,
which `prepare-host` and `bootstrap-host` verify through
`install-bootstrap-runtime --verify`.

## Groups and verdicts

Each item is `PASS`, `FAIL`, `DEFERRED` or `N/A`, and each group is its worst
item:

| Verdict | Meaning |
|---------|---------|
| `PASS` / `FAIL` | A check the target's current state requires. Any `FAIL` fails the run, with a non-zero exit. |
| `DEFERRED` | Deliberately not required in the target's current reviewed lifecycle or routing — reported with what it says today, never silently skipped. |
| `N/A` | Does not apply to this kind of target at all. |

A child's exit status is its verdict. A child that could not be executed, or
that a signal ended, is a `FAIL` that says so — never a `PASS` or `DEFERRED`.
One failing group does not stop the others: every read-only group still runs,
so one run diagnoses as much as it can. Only an unresolvable target or an
incomplete bundle stops it.

The result line carries `target`, `environment_class`, `lifecycle`,
`delivery_mode`, `status`, the totals `pass`, `fail`, `deferred` and
`not_applicable`, and `groups` — one entry per group, each with its `id`,
`title`, `status` and counts. Never a secret, and never a key path.

## What each lifecycle is held to

**An active target** — `staging-main` today:

| Group | Primitive | |
|-------|-----------|---|
| Preparation contract | `prepare-host --verify --target staging-main` | required: runtime → host prerequisites → host bootstrap → target prerequisites → target database → prepared |
| *Host inventory* | `bootstrap-host-preflight --report` | in the log only — never a verdict |
| Live target contract | `repair-target --verify --target staging-main` | required: layout, ownership, Nginx, PHP-FPM, Supervisor, scheduler, public-storage ACL, release structure, environment, queue — Repair would change nothing |
| Operations & backup perimeter | `install-target-perimeter --verify` | required |
| Mail capture | `verify-mail-capture --read-only` | required (staging) |
| Mail gateway | `verify-mail-gateway --read-only` | required |
| Mail identity | `mail-identity …` | `N/A` for staging: it captures its mail and never signs it |
| Mail inbound | — | `N/A` for staging: it never receives public mail; who may listen on TCP 25 is proved in the mail gateway group |
| Application | `health-check --target staging-main` | required |

Expected today: `VERIFY INFRASTRUCTURE: PASS`.

**A planned production target** — `tits-guru` today. `prepare-host` and
`repair-target` refuse a target that is not active, and that gate is kept: the
host and the target are judged by the contracts that apply to a planned target.

| Group | Primitive | Today (planned, held, direct disabled) | Once active and outbound |
|-------|-----------|----------------------------------------|--------------------------|
| Host bootstrap | `bootstrap-host --verify` | required: the physical host baseline | (then: Preparation contract) |
| *Host inventory* | `bootstrap-host-preflight --report` | in the log only | in the log only |
| Planned target contract | `configure-target --verify --target tits-guru` | required: provisioned, material and database in place, still planned | (then: Live target contract) |
| Live target contract | `repair-target` | `DEFERRED` — never called | required |
| Operations & backup perimeter | `install-target-perimeter --verify` | required | required |
| Mail capture | — | `N/A` | `N/A` |
| Mail gateway | `verify-mail-gateway --read-only` | required: 2526 → HOLD, no outbound route | required: 2526 → its own direct transport |
| Mail identity | `mail-identity validate`, `dkim-key`, `check-key`, `readiness` | contract required; key absent → `DEFERRED`, installed → judged; readiness `DEFERRED`, shown in full — its signing condition is `verify-mail-signing --read-only` | key and `OUTBOUND READY: YES` required |
| Mail inbound | `activate-mail-inbound --verify --target tits-guru` | `enabled-verified` → `PASS`; not installed or installed and disabled while the contract requests public SMTP → `DEFERRED` (Activate tits.guru inbound SMTP is the guarded step); drift, or no state at all → `FAIL` — see [`mail-inbound.md`](mail-inbound.md) | the same |
| Application | `health-check` | `DEFERRED` — never called | required |

Expected today: `VERIFY INFRASTRUCTURE: PASS` with deferred items. **PASS means
the CURRENT reviewed planned state is correct. It does NOT mean production is
live.** The activation slice makes `verify-dns` and full readiness hard
prerequisites before it switches the target to outbound; from then on every
ordinary run of Verify production infrastructure requires them.

A disabled target is judged like a planned one on the host side, and its live
target and application are `N/A`.

**Production is judged against `main`.** tits-guru shares its machine with
staging today, and Verify production infrastructure holds the host-global
contracts — bootstrap, perimeter, gateway — to the tooling on `main`. While
`develop` carries host changes that have not been promoted, those groups can
report drift on the production run that the staging run does not: promote
`develop` to `main`, then verify production again.

## Deliberately repeated, never duplicated

The mail primitives and `health-check` are also reached inside the preparation
and live-target contracts. Verify calls them again in sections of their own, so
a failure names its subsystem — the same primitive twice, never a second copy of
its rules.

## What ordinary Verify never does

No `--apply` of anything, no preparation, repair or configuration, no package
installation, no `chmod`, `chown` or `setfacl`, no service start, stop, restart
or reload, no database change, no deploy or rollback, no backup, no restore, no
`restore-test` or `offsite-restore-test`, no SMTP submission and no Postfix
queue change, no DNS change, no key generation or rotation, no `.env` change.
The upload and removal of its own temporary bundle are its only writes.

Out of scope, as deep acceptance or operations: mail end-to-end
(`verify-mail-gateway --e2e`, `verify-mail-capture --e2e`), a real Internet mail
canary, running a backup cycle, restore tests, destructive recovery rehearsals,
the application test suite and generic security scanning. The configuration that
makes them possible is what Verify covers.

## Concurrency

Both workflows join `rateguru-staging-deployment`, the domain every mutation of
the shared host uses (deploy, rollback, Prepare, repair, restore, recover,
Configure tits.guru), so a verification never observes the host halfway
through one. They wait; they never cancel. tits-guru shares the staging host
today; when it moves to its own host, its concurrency moves with it.

## From merge to the first production verification

1. merge the pull request into `develop`.
2. Run **Prepare staging host**, so the host converges onto the merged
   tooling — host-global runtime dependencies such as `bind9-dnsutils`
   included.
3. Run **Verify staging infrastructure** (from `develop`). It must pass.
4. Promote `develop` to `main` through the normal reviewed pull request.
   Production workflows only ever run `main`.
5. Add `MAIL_DKIM_PRIVATE_KEY` to the `production-tits-guru` GitHub Environment
   (see [`mail-identity.md`](mail-identity.md)).
6. Run **Configure tits.guru** (from `main`). It installs the key and prints the
   PUBLIC DNS publication plan in its summary.
7. Read that plan.
8. Publish A, PTR (at the provider that owns the address), SPF (replacing any
   existing one), DKIM and DMARC.
9. Run **Verify production infrastructure** repeatedly while DNS propagates; the
   outbound-readiness section shows each record's state while it is still
   deferred.
10. When the identity checks are ready, the signing foundation: after it is
    merged, **Prepare staging host** installs OpenDKIM on the shared host and
    wires `tits-guru`'s held listener to it; **Verify production
    infrastructure** then shows `signing PASS` in readiness, and **Verify
    production mail signing** proves a held message is signed — see
    [`mail-signing.md`](mail-signing.md).
11. Then the activation slice: `tits-guru` from held to outbound,
    `direct.enabled`, the production `MAIL_*` values and a controlled canary.

A change to Verify itself needs no preparation: it uploads its own bundle each
run. After merging one, running **Verify staging infrastructure** again is the
whole real-host acceptance.

Nothing here weakens the main-only production control plane: the production
workflow refuses any ref but `main` in a job that holds no Environment, before
any job with production credentials starts.
