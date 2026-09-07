# Recovering a host from GitHub

This runbook covers the **operator layer** over the server-side Recover Host
primitive: the two workflows an operator actually clicks, what each one does,
and — the part that matters most — how a recovery is picked up again after the
runner driving it disappeared.

It is delivered by:

| | |
|---|---|
| `.github/workflows/recover-staging.yml` | **Recover staging host**, fixed to `staging-main` |
| `.github/workflows/recover-production.yml` | **Recover production host**, fixed to `tits-guru` |
| `.github/actions/prepare-rateguru-host` | the one host-preparation transport ([`prepare-host.md`](prepare-host.md)) |
| `.github/actions/recover-rateguru-host` | the one recovery transport |
| `.github/actions/build-rateguru` | the one build implementation |
| `.github/actions/deploy-rateguru` | the one deployment transport |
| `.github/actions/record-rateguru-deployment` | the one observability marker |
| `infrastructure/scripts/recover-host` | all the recovery logic ([`recover-host.md`](recover-host.md)) |

Nothing in this layer is a second implementation of anything. The workflows are
policy: which action runs, when, against which machine, at which fixed
identity. Every destructive decision belongs to `prepare-host` and
`recover-host` on the server.

---

## 1. Which operation is this?

| | REPAIR TARGET | RESTORE TARGET DATA | RECOVER HOST |
|---|---|---|---|
| What is wrong | the target's own infrastructure drifted | the data | the machine is gone |
| Host | alive | alive | a new, empty replacement machine |
| Operator surface | Repair staging / production | Restore staging / production | **Recover staging / production host** |
| Data | untouched | replaced from one backup | restored onto an empty host |
| Code | untouched | aligned if the backup needs it | built from the backup's own commit |
| Ends with | the target converged | the target serving, or HELD | the target serving on a new machine |

If the machine is still there, this is the wrong runbook: use
[`repair-target.md`](repair-target.md) or
[`github-restore.md`](github-restore.md). Recovery starts from the premise that
the old machine is not coming back, and the workflow **refuses** to run against
the host the target is currently bound to (§4).

---

## 2. There is no target dropdown, and no commit input

Two workflows, two names. An operator picks the one whose title names the
environment, and the target is a structural property of that file — never an
input, under any name.

The operator chooses a **backup** and a **machine**, never a commit. The
required commit flows:

```text
the chosen offsite backup
  -> its verified release.json.source_sha           (checked by verify-backup)
  -> the recovery operation's state + guard         (written by recover-host)
  -> RATEGURU_RECOVER_RESULT.required_source_sha    (read by recover-rateguru-host)
  -> the exact application checkout ref             (built by build-rateguru)
  -> re-checked on the SERVER before current appears
```

Nothing in that chain is typed by a person, and the last step is the one that
matters: `deploy --recovery-operation` reads the required commit out of the
server's own recovery documents and refuses any artifact built from anything
else. GitHub tells the server *which operation*; the server decides *which
commit*.

---

## 3. The one thing an operator does name: the replacement machine

A recovery exists because the machine the GitHub Environment names is gone. The
physical machine therefore cannot come from the environment — it is the one
fact about this operation that is not already known, so it is an input:

| input | meaning |
|---|---|
| `mode` | `start` (a new recovery) or `continue-held` (finish one already held) |
| `backup` | exact `YYYYMMDD-HHMMSS`; required for `start`, forbidden otherwise |
| `operation` | the recovery operation ID; required for `continue-held`, forbidden otherwise |
| `replacement-host` | hostname or IPv4 address of the **new** machine — always required |
| `replacement-port` | SSH port; defaults to `22` |
| `confirmation` | **production only** — must be exactly `RECOVER tits-guru` |

There is no `latest`, no `source` selector and no local option: a recovery
models the loss of the machine the local backups lived on, so **offsite is the
only source there is**. There is no `ref`, `branch`, `tag`, `release`,
`source_sha` or `run-migrations` input either.

`replacement-host` is treated as data, never as shell syntax. It must be a
sequence of DNS labels — which is what both an ordinary hostname and an IPv4
address are — so whitespace, control characters, shell metacharacters, a
leading `-`, `user@host`, a `:port` suffix, a path and SSH option syntax are
all rejected before anything happens. Pasted whitespace is stripped from every
ID and address first, exactly as the Restore workflows do it.

---

## 4. The replacement machine is never the current machine

Before Prepare Host is invoked — and before the recovery credential is used at
all — the workflow compares `replacement-host` against the target's current
`vars.DEPLOY_HOST` binding and **refuses if they are equal**:

```text
The replacement host is the host currently bound to this target.
Recover Host is only for a lost/replacement machine. Use Restore or Repair
for the existing host.
```

That comparison is the **only** place `vars.DEPLOY_HOST` is read in either
workflow. It is read to refuse, never to connect: no job in a recovery opens a
connection to the machine the target is currently bound to, and nothing in a
recovery reads, writes or repoints that binding.

The practical consequence, and the reason it matters: during a rehearsal the
long-lived staging host keeps serving, untouched, while a completely separate
disposable machine is recovered onto. Repointing DNS or `DEPLOY_HOST` at a
recovered machine is a separate, deliberate act that this runbook does not
cover and these workflows cannot perform.

---

## 5. GitHub configuration

### Recovery-specific bindings

A replacement machine is not the machine the environment already describes, so
it gets its own privileged credential. Configure these in each GitHub
Environment (`staging`, `production`) **before** the first recovery:

| variable | meaning |
|---|---|
| `RECOVERY_BOOTSTRAP_USER` | the privileged user on the replacement machine: `root`, or a passwordless sudoer |

| secret | meaning |
|---|---|
| `RECOVERY_BOOTSTRAP_SSH_KEY` | privileged SSH private key for the replacement machine |
| `RECOVERY_KNOWN_HOSTS` | the **verified** `known_hosts` entry for the replacement machine |
| `RECOVERY_RCLONE_CONFIG` | rclone configuration the replacement machine reads the offsite backup through |

**No TOFU, ever.** `RECOVERY_KNOWN_HOSTS` is host key material an operator
verified out of band — from the provider's console, or from the machine itself
over a channel they trust. There is no `ssh-keyscan` anywhere in the workflow,
no `StrictHostKeyChecking=no`, and no password fallback. If the host key is not
configured, the recovery fails; it never guesses.

**No fallback in either direction.** `RECOVERY_BOOTSTRAP_SSH_KEY` never falls
back to `BOOTSTRAP_SSH_KEY` (that key belongs to the machine that is gone) and
never falls back to `DEPLOY_SSH_KEY` (that key reaches only the narrow
`rateguru-deploy` / `rateguru-rollback` wrappers and cannot rebuild a host).
The separation runs the other way too: the controlled recovery deployment and
the Nightwatch marker use `DEPLOY_SSH_KEY` and never the privileged one.

| operation | credential | address | host key |
|---|---|---|---|
| Prepare Host | `RECOVERY_BOOTSTRAP_SSH_KEY` | `replacement-host` | `RECOVERY_KNOWN_HOSTS` |
| `recover-host --apply` / `--inspect` / `--resume` / `--verify` | `RECOVERY_BOOTSTRAP_SSH_KEY` | `replacement-host` | `RECOVERY_KNOWN_HOSTS` |
| controlled recovery deployment | `DEPLOY_SSH_KEY` | `replacement-host` | `RECOVERY_KNOWN_HOSTS` |
| Nightwatch deployment marker | `DEPLOY_SSH_KEY` | `replacement-host` | `RECOVERY_KNOWN_HOSTS` |

### Reused target material

Preparation of the replacement machine uses the target's existing external
material, unchanged, from the same secrets the ordinary Prepare workflows use:

`PREPARE_LARAVEL_ENV`, `PREPARE_DEPLOY_AUTHORIZED_KEYS`, `PREPARE_BASIC_AUTH`,
`PREPARE_TLS_CERTIFICATE`, `PREPARE_TLS_PRIVATE_KEY`, `PREPARE_TLS_DHPARAMS`,
`PREPARE_NGINX_TLS_OPTIONS`, `PREPARE_MAIL_TLS_CERTIFICATE`,
`PREPARE_MAIL_TLS_PRIVATE_KEY`.

`PREPARE_LARAVEL_ENV` matters more here than anywhere else. Recovery compares
the prepared `shared/.env` **byte-for-byte** against the environment file
inside the backup and fails closed on a difference, before any activation — so
the environment file configured in GitHub must be the one the backup was taken
under. See [`recover-host.md`](recover-host.md) §4.

### The one substitution: rclone

`rclone-config` is deliberately **not** `PREPARE_RCLONE_CONFIG`. It is
`RECOVERY_RCLONE_CONFIG`, and there is no silent fallback between them. See §9
for why.

### Restricted deployment identity

The controlled recovery deployment reuses the target's ordinary restricted
deployment identity — `DEPLOY_USER`, `DEPLOY_INCOMING`, `DEPLOY_WRAPPER`,
`DEPLOY_ROOT` and `DEPLOY_SSH_KEY` — which Prepare Host has just created on the
replacement machine. Only the **address** and the **host key** are the
replacement machine's.

### What the operator must arrange outside RateGuru

The replacement machine is expected to exist already, running a supported
Ubuntu, with the public half of `RECOVERY_BOOTSTRAP_SSH_KEY` accepted for
`RECOVERY_BOOTSTRAP_USER`. That first access is a provider concern: RateGuru
does not create machines, does not call a cloud API and holds no provider
credential. Nothing else is needed on it — Prepare Host does the rest.

---

## 6. Running a recovery: `mode=start`

```text
Recover staging host
  mode             = start
  backup           = 20260115-023000
  replacement-host = 203.0.113.24
  replacement-port = 22
```

What runs, in order:

```text
validate        request + target lifecycle          no environment, no secret
binding         replacement-host != DEPLOY_HOST     no connection
prepare         prepare-rateguru-host               --apply, then --verify
recover         recover-host --apply                one exact offsite backup
decide          read the server's own result        awaiting-code
build           the EXACT commit the backup names   no environment, no secret
deploy          deploy --recovery-operation         migrations forbidden, host stays held
resume          recover-host --resume               the only thing that ends a hold
verify          recover-host --verify               the final contract, independently
observability   record-rateguru-deployment          fail-open
report          the summary                         always
```

The end state of `--apply` is deliberately a host that is **not serving**: the
data is back, `current` is absent, the queue is stopped, the scheduler entry is
held aside, and a recovery guard is on disk. That is not a failure — it is the
point. Code has not arrived yet, and Laravel must not run against recovered
data before it does.

---

## 7. Continuing a recovery: `mode=continue-held`

A recovery routinely outlives the workflow run that started it. The historical
commit may no longer build, a runner may die, a run may be cancelled, or the
operator may come back the next day. None of that damages anything.

```text
Recover staging host
  mode             = continue-held
  operation        = 20260115-041203-9fa2c7
  replacement-host = 203.0.113.24
```

**No backup is supplied again**, and the workflow refuses one: the held
operation's own state records which backup it recovered, and starting a second
recovery over a held one would replace that data while a build for the first
one was still in flight.

**Preparation does not run again.** A continuation skips the Prepare job
entirely — and the server-side interlock would refuse it anyway, because
Prepare Host reconverges the target's Supervisor program and scheduler entry,
which is exactly what a recovery holds aside.

The workflow asks `recover-host --inspect` which of the two safe stages the
operation is at, and continues from the server's answer:

| the server says | what the workflow does |
|---|---|
| `awaiting-code` | historical build → controlled deployment → resume → verify |
| `ready-to-resume` | **no build, no deployment** → resume → verify |
| anything else | fails closed, and the recovery stays held |

`ready-to-resume` means a previous run already built and deployed the exact
commit and then lost its runner before resuming. Rebuilding and redeploying an
identical tree would be work with a risk and no purpose, so the continuation
goes straight to the resume — and the job graph is written so that the skipped
build and deployment jobs cannot skip the resume with them.

`--inspect` re-proves the hold every time it runs: it refuses outright if the
queue has been started, if the scheduler cron entry is back, or if the host is
serving code it should not be. Those two fields in its result describe what it
observed, never what it assumed.

### The three interruptions this covers

| what happened | where it stopped | what `continue-held` finds |
|---|---|---|
| the historical commit no longer builds | build failed | `awaiting-code` — fix the build, run again |
| the runner died after `--apply` | before the deployment | `awaiting-code` — build and deploy, then resume |
| the runner died after the deployment | before `--resume` | `ready-to-resume` — resume only |

In every case the operator re-runs the same button with the same
`replacement-host` and the operation ID from the failed run's summary. There is
no manual data manipulation, no hand-run SSH command on the host, and nothing
to clean up first.

---

## 8. Trust boundaries

### The historical build

The build job compiles an arbitrary commit out of this repository's past,
chosen by a *backup* rather than by a person. It therefore holds:

* **no GitHub Environment** — and so no recovery credential, no deployment key,
  no Sentry token, no B2 credential and no Prepare material;
* `permissions: contents: read`, and nothing else;
* **two separate checkouts**: the operational tooling always from `develop`,
  the application at the exact `required_source_sha`, in `application/`.

Loading `build-rateguru` out of the historical commit would let recovered data
decide what the operational tooling does, so it never happens. If the commit is
gone or no longer builds, the job fails and the replacement host simply stays
held. There is **no fallback** to `develop`, `main`, a tag, a nearby commit or
a nearby release — every one of those would install code the data does not
belong to.

The release ID keeps the **backup's** version prefix and gets a new timestamp
and suffix: this is a new build of the same commit, not a claim to be
byte-identical to a release that already exists. A backup release that is not a
canonical release ID fails the run rather than being patched over with an
invented version. The artifact is a short-lived GitHub Actions artifact (3
days) and nothing else — **there is deliberately no durable artifact archive**;
recovery rebuilds from the commit a backup already names. See
[`recover-host.md`](recover-host.md) §5.

### The controlled recovery deployment

The same `deploy-rateguru` action and the same server-side `deploy` every
ordinary release uses. The only difference is `recovery-operation`, and it
names the operation — never the commit.

It **never migrates**, and that is enforced three times independently: the
workflow passes `run-migrations: "false"` as a literal, the action refuses the
combination `recovery-operation` + `run-migrations: true` at the perimeter, and
the server refuses it too. Recovered data belongs to the schema of the commit
being installed; migrating it would change the very thing the recovery just put
back.

It also does not start the queue, does not restore the scheduler, does not
clear the recovery guard, and deliberately leaves `previous` **absent** — a
rebuilt host has no earlier release, and synthesising one would arm a rollback
that undoes the recovery.

### The resume, and the verification

`recover-host --resume` is the only thing that ends a hold. The workflow reads
the server's own result and requires `status=completed`, a non-empty current
release, a source SHA equal to the required one and `health=pass` — none of it
inferred from the build job.

A recovery is **not** successful because `--resume` exited 0. The workflow then
runs `recover-host --verify` as a separate, read-only question about the host
as it stands now: `current` canonical with valid release metadata, neither
guard present, database reachable and coherent, storage present, scheduler
present, queue RUNNING, health PASS. `previous` being absent is not a failure.

**No manual SSH command is part of the success path.**

### Observability

`record-rateguru-deployment` runs only after the deployment, the resume and the
verification, and it records what the **server** reported the host is serving —
not what the build produced. It is fail-open, exactly as it is everywhere else:
an unreachable Sentry or a Nightwatch outage never turns a successfully
recovered host into a failed recovery.

---

## 9. The disposable rehearsal, and offsite safety

A clean-host rehearsal is performed against a genuinely disposable machine —
never by destroying the long-lived staging host, and never by touching it.

For a rehearsal, back `RECOVERY_RCLONE_CONFIG` with a B2 application key that
**can list and read** the real staging backup namespace and **cannot write to,
delete from, or apply retention to it**.

That is the whole reason the credential is separate. The rehearsal host is a
fully configured RateGuru host: it has a backup cron, an offsite uploader and a
retention policy, and it believes it is `staging-main`. Given the namespace
owner's credential it would eventually upload its own backups into the real
staging namespace and prune the real staging history — which is exactly the
data the rehearsal exists to prove we can recover from.

So:

* the rehearsal host **may read** a real, verified staging offsite backup;
* the rehearsal host **must never** write to, delete from, apply retention to,
  or otherwise alter the real staging backup namespace.

There is no recovery-specific backup path, no namespace override and no second
backup format anywhere in this pipeline — the only thing that changes is the
credential's permissions.

**Do not delete or reuse the real staging backup namespace to make a rehearsal
look clean.**

For a real disaster, `RECOVERY_RCLONE_CONFIG` is configured with the
credentials appropriate for the recovered target, and the recovered host
resumes owning its namespace normally.

---

## 10. When a run fails

Every run ends with a summary that names the target, the replacement host, the
mode, the backup, the recovery operation, the server's status, the required
source SHA, whether a historical build was required, and the result of every
stage. It contains identity only — no environment file, no rclone
configuration, no key material, no credential fingerprint, no secret size or
digest — and the job that prints it holds no GitHub Environment.

If the recovery did not finish, the summary says so explicitly:

```text
Recovery remains held on the replacement host.
```

and, once the server has assigned one, the exact re-run:

```text
mode=continue-held
operation=<id>
replacement-host=<same host>
```

**Nothing is cleaned up to make a run look green.** The recovered data, the
recovery guard and the operation's own state stay on the machine, because that
is what makes the continuation possible. A recovery that is held is a recovery
that can be finished; a recovery that was tidied away is one that has to start
over.

---

## 11. Production

`Recover production host` exists now and is fixed to `tits-guru`, in the
`production` environment, in the `rateguru-production-release` concurrency
domain. It requires the exact confirmation `RECOVER tits-guru`.

**It fails closed today, and that is the point.** `tits-guru` is
`lifecycle=planned`. The validation job reads that lifecycle out of the
committed registry through the repository's own `targets` CLI, in a job that
holds no GitHub Environment — so a real run stops:

* before the production environment's approval is requested;
* before any production secret is loaded;
* before any SSH connection is opened;
* before any tooling is uploaded;
* before anything on any machine is prepared or changed.

The workflow exists now to prove production will be recovered by exactly the
same mechanism once the production launch activates and provisions the target,
rather than by a production-shaped procedure invented under pressure on the
worst day of the year. Nothing here activates anything.

---

## 12. Concurrency

The **whole** workflow shares one concurrency group with every other workflow
that mutates the same target — `rateguru-staging-deployment` for staging,
`rateguru-production-release` for production — with `cancel-in-progress: false`.

Prepare → recover → build → controlled deploy → resume → verify is one logical
mutation. An ordinary deploy, rollback, Restore or Repair slipping in between
two of its jobs would act on a target whose runtime is deliberately held and
cannot object. This holds even during a disposable rehearsal: the rehearsal is
logically an operation on `staging-main`, whatever machine it runs against.

GitHub concurrency is orchestration on top of, never a replacement for, the
server-side locks and guards — a hold outlives the workflow that created it,
and the guard is what actually protects the host.

---

## 13. What this never does

* **No DNS.** No DNS API, no DNS record, no Cloudflare or Route 53 integration,
  no public routing change of any kind.
* **No `DEPLOY_HOST` update.** The logical target stays bound to whatever
  machine it was bound to. Repointing it is a separate deliberate act.
* **No public cutover.** The recovered host is verified through the target's
  own health contract; it is not made public by recovering it.
* **No provisioning.** RateGuru creates no machine and holds no provider
  credential.
* **No target activation.** A `planned` target stays planned.
* **No durable artifact archive.** See §8.
* **No RPO/RTO claim.** Measuring real recovery duration is separate work.

---

## 14. Clean-host acceptance checklist

CI proves the structure, the trust boundaries and every refusal path. Only a
real, genuinely disposable replacement machine proves the pipeline. Run this
end to end, in order, and collect the evidence as you go:

1. Create a genuinely new disposable VPS on a supported Ubuntu release.
2. Install only the provider/bootstrap SSH public key GitHub needs to reach it
   — the public half of `RECOVERY_BOOTSTRAP_SSH_KEY`, for
   `RECOVERY_BOOTSTRAP_USER`. No RateGuru setup by hand.
3. Record its host key, verified out of band.
4. Configure `RECOVERY_BOOTSTRAP_USER`, `RECOVERY_BOOTSTRAP_SSH_KEY`,
   `RECOVERY_KNOWN_HOSTS` and `RECOVERY_RCLONE_CONFIG` in the `staging`
   environment, with the read-only offsite credential of §9.
5. Confirm the long-lived staging host is healthy, and note its current release
   and source SHA so a comparison is possible afterwards.
6. Create or choose a fresh, exact staging offsite backup.
7. Ideally plant a database sentinel and a storage/media sentinel **before**
   that backup is taken, so §16–17 can prove the data is the backup's.
8. Dispatch **Recover staging host** with `mode=start`, that backup, and the
   disposable machine as `replacement-host`.
9. Prove Prepare Host runs from a genuinely clean machine and its verification
   passes.
10. Prove the recovery fetched the exact named offsite backup.
11. Prove the build is of the backup's own `source_sha`, and of nothing else.
12. Prove `run-migrations` was `false` throughout.
13. Prove the controlled deployment left the runtime held: no queue, no
    scheduler entry, guard still present, `current` present.
14. Prove `--resume` restored the runtime.
15. Prove the final `--verify` passed.
16. Verify the database sentinel is present, with the backup's data.
17. Verify the storage/media sentinel is present and served.
18. Verify the queue is RUNNING.
19. Verify the scheduler cron entry is PRESENT.
20. Verify `current`'s `release.json.source_sha` equals the backup's
    `source_sha`.
21. Verify `previous` is ABSENT.
22. Verify neither the restore guard nor the recovery guard remains.
23. Verify the ordinary staging VPS is unchanged: same release, same source
    SHA, same data, still healthy, and no connection was made to it.
24. Verify the real staging B2 namespace was neither written to nor pruned by
    the rehearsal.
25. If practical, run one continuation exercise: interrupt a run after
    `--apply` or after the controlled deployment, and finish it with
    `mode=continue-held`.
26. Destroy the disposable VPS once the evidence is collected. Destruction is a
    deliberate operator act; nothing automates it.

Only after a real run of the above may Recover Host and this operator surface
be recorded as accepted. A green CI run is not a clean-host recovery, and must
never be reported as one.

---

## See also

* [`recover-host.md`](recover-host.md) — the server-side recovery state
  machine, its guard, its refusals and its final contract
* [`prepare-host.md`](prepare-host.md) — producing the prepared machine a
  recovery requires
* [`github-restore.md`](github-restore.md) — the operator surface for restoring
  a **live** target's data, whose shape this follows
* [`repair-target.md`](repair-target.md) — converging one live target's own
  infrastructure
* [`backups.md`](backups.md) — the backup format this reads, and why there is
  no artifact archive
