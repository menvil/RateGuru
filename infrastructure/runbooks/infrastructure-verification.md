# Infrastructure verification

Two permanent operator commands verify everything a target depends on, on its
host, without changing anything:

| Workflow | Target | Trusted tooling | GitHub Environment |
|----------|--------|-----------------|--------------------|
| **Verify staging infrastructure** | `staging-main` | `develop` | `staging` |
| **Verify production infrastructure** | `tits-guru` | `main` | `production-tits-guru` |

They are read-only and repeatable: run them ten times in a row and the host is
exactly as it was. Nothing is chosen when they run — no target, environment or
ref dropdown — and there is no separate button per subsystem. A new subsystem
extends the shared verifier, never the list of workflows.

## How it is built

```
low-level primitives (unchanged, still usable on their own)
  verify-mail-capture --read-only | --e2e
  verify-mail-gateway --read-only | --e2e
  mail-identity validate | dkim-key | check-key | show-dns | verify-dns | readiness
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
`RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=<json>` line, and removes the upload
whether the run passed or failed.

It reimplements no check. It decides only which primitives the target's
**current reviewed state** requires — its environment class and lifecycle from
the registry, its mail delivery mode from `mail-routing render-plan`, and
whether the host enables direct delivery from `mail-outbound.json` — and runs
every one of them in its read-only mode. The mutating acceptances
(`verify-mail-gateway --e2e`, `verify-mail-capture --e2e`) stay on the host for
an explicit deep acceptance and are never part of Verify.

## Verdicts

| Verdict | Meaning |
|---------|---------|
| `PASS` / `FAIL` | A check the target's current state requires. Any `FAIL` fails the run, with a non-zero exit. |
| `DEFERRED` | Deliberately not required in the target's current reviewed lifecycle or routing — reported with what it says today, never silently skipped. |
| `N/A` | Does not apply to this kind of target at all. |

The result line carries `target`, `environment_class`, `lifecycle`,
`delivery_mode`, `status`, `pass`, `fail`, `deferred` and `not_applicable` —
never a secret.

## What each target is held to

**Host runtime (every target).** `jq`, `dig`, `openssl`, `curl`, `ss` and `ip`
must be present. They are part of the host's canonical runtime
(`install-bootstrap-runtime`: `bind9-dnsutils` supplies `dig`). A missing one is
a `FAIL` naming the cure — run Prepare Host, which converges the runtime —
because Verify never installs anything.

**staging-main** (staging, active):

- mail capture — `verify-mail-capture --read-only`: Mailpit and Mailtrap Local
  active, loopback listeners up, both APIs answering;
- mail gateway — `verify-mail-gateway --read-only`: the RateGuru-owned Postfix,
  configuration equal to the reviewed render, listeners equal to the policy, the
  2525 capture route and the 2526 hold, no public 25/465/587 listener, no
  fallback route, the capture destination listening;
- mail identity — `N/A`: staging captures its mail and never signs it;
- application — `health-check --target staging-main`, required because it is
  active.

Expected today: `VERIFY INFRASTRUCTURE: PASS`.

**tits-guru** (production) — the same command before and after its launch:

| Check | Today (planned, held, direct disabled) | Once active and outbound |
|-------|----------------------------------------|--------------------------|
| Mail gateway (`verify-mail-gateway --read-only`) | required: 2526 → HOLD, no outbound route | required: 2526 → its own direct transport, as the policy says |
| Identity contract (`mail-identity validate`) | required | required |
| DKIM key (`mail-identity dkim-key`, `check-key`) | absent → `DEFERRED`; installed → judged, `PASS` or `FAIL` | required: absent or unusable → `FAIL` |
| Outbound readiness (`mail-identity readiness`: A, PTR, SPF, DKIM, DMARC, key, signing) | `DEFERRED` — shown in full as the pre-activation picture | required: `OUTBOUND READY: YES` or `FAIL` |
| Application (`health-check`) | `DEFERRED` — never called: it refuses a planned target | required |
| Mail capture | `N/A` | `N/A` |

Expected today: `VERIFY INFRASTRUCTURE: PASS` with deferred items — which is
**not** "production is live". The activation slice makes `verify-dns` and full
readiness hard prerequisites before it switches the target to outbound; from
then on every ordinary run of Verify production infrastructure requires them.

## Concurrency

Both workflows join `rateguru-staging-deployment`, the domain every mutation of
the shared host uses (deploy, rollback, Prepare, repair, restore, recover,
Configure tits.guru), so a verification never observes the host halfway
through one. They wait; they never cancel. tits-guru shares the staging host
today; when it moves to its own host, its concurrency moves with it.

## From merge to the first production verification

1. merge the pull request into `develop`.
2. Run **Prepare staging host**, so new host-global runtime dependencies —
   `bind9-dnsutils` — converge on the host.
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
10. When the identity checks are ready, the signing and activation slice:
    OpenDKIM, signing, `tits-guru` from held to outbound, `direct.enabled`, the
    production `MAIL_*` values and a controlled canary.

Nothing here weakens the main-only production control plane: the production
workflow refuses any ref but `main` in a job that holds no Environment, before
any job with production credentials starts.
