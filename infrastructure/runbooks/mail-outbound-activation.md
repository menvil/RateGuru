# Mail outbound activation

How a planned production target's mail goes from **held** to **direct outbound
delivery**, how that switch is undone before the target goes live, and how the
first real message is sent and accepted. The gateway itself is
[`mail-gateway.md`](mail-gateway.md); the signer is
[`mail-signing.md`](mail-signing.md); the identity and DNS it depends on are
[`mail-identity.md`](mail-identity.md).

The committed configuration now **requests** `tits-guru`'s direct outbound
delivery. Requesting it changes no host: merging the request and promoting it
to `main` leaves the shared host **held** until **Activate tits.guru outbound
mail** crosses it, and nothing else can. Three states are kept apart below and
must never be confused:

1. **Code and policy** — what the repository requests. Once the activation
   change has reached `main`, the committed policy requests outbound delivery.
2. **The real server** — what the host applies and records. It stays held
   until Activate runs, whatever the repository requests.
3. **Production accepted** — recorded only after Activate, **Verify production
   infrastructure** reporting `OUTBOUND READY: YES`, and a real canary received
   and its raw headers inspected.

On 2026-10-08 all three came to agree: Activate ran, Verify production reported
`OUTBOUND READY: YES`, and the real canary was received and its headers
inspected — see [Activation rollout](#activation-rollout-done).

## Status

| What | State |
|------|-------|
| `activate-mail-outbound` (`--check`, `--apply`, `--verify`, `--rollback`) | **Implemented** — repository tooling run from a trusted bundle |
| `send-mail-canary` (`--check`, `--send`) | **Implemented** — repository tooling run from a trusted bundle |
| `smtp-submission` | **Implemented** — the one SMTP conversation the signing acceptance and the canary share |
| Workflows **Activate tits.guru outbound mail**, **Rollback tits.guru outbound mail activation**, **Send tits.guru production mail canary** | **Implemented**, `main` only |
| Tooling accepted on the shared host | **Yes**, 2026-10-08 — see [Tooling rollout](#tooling-rollout-done) |
| `tits-guru` mail — **committed policy** | **Outbound requested**: `delivery_mode` `outbound` with `{"kind": "direct"}`, `direct.enabled: true` as `mta1.tits.guru`; `lifecycle=planned` |
| `tits-guru` mail — **real host** | **Outbound**, activated on 2026-10-08 by **Activate tits.guru outbound mail** (run `37813433328`); **Verify production infrastructure** `OUTBOUND READY: YES` (run `37814215899`) |
| Production acceptance | **Accepted** on 2026-10-08 — a real canary received with SPF, DKIM and DMARC passing and its raw headers inspected; see [Activation rollout](#activation-rollout-done) |
| Production application `MAIL_*` | **Deliberately not set here** — see [Application mail transport](#application-mail-transport-is-not-part-of-this) |

## The activation

`infrastructure/scripts/activate-mail-outbound --apply --target tits-guru`, run
as root from a trusted bundle by **Activate tits.guru outbound mail**.

**What asks for it.** The trusted bundle, never the caller: its
`mail-routing.json` says `tits-guru`'s mail is `outbound` with
`"outbound": {"kind": "direct"}`, and its `mail-outbound.json` says
`direct.enabled: true`. Anything else is answered with *activation is not
requested by this trusted bundle*, and nothing is touched. No file, path, port,
domain, hostname or key is ever taken from the caller, and no production
override exists.

**The one transition.** From that request the script derives the only state
the host may be in before activation — the target's `delivery_mode` back to
`held`, its `outbound` removed, `direct.enabled` back to `false` — and proves
the request is exactly that transition and nothing else: the same submission
endpoint, mail domain, sender, bounce and reply domains, the same MTA hostname,
every other target and every identity unchanged.

**The pre-activation bundle.** The derived state is judged by the same code as
everything else. The script copies the trusted `infrastructure/` tree into a
root-only temporary directory, replaces only `config/mail-routing.json` and
`config/mail-outbound.json` with the derived documents, validates them with
that copy's own `mail-routing` and `mail-identity`, and runs that copy's
`install-mail-gateway` and `verify-mail-signing` against the host. No key and
no secret is in it; it is removed on every exit.

**The proof, under the host's infrastructure lock, before anything changes:**

| From | Check |
|------|-------|
| the pre-activation bundle | `install-mail-gateway --verify` — the listener held, no direct route, direct delivery disabled, no public SMTP listener, the From policy and the signing wiring, **and the gateway's record of the complete policy it last applied**: an activation request that also changes the target's sender, bounce or reply domain, submission endpoint, mail domain or another target derives a pre-activation state the host never accepted, and stops here |
| the live Postfix | a read-back: the target's listener holds its mail with no route, no direct transport exists, nothing listens on 25, 465 or 587 |
| the requested bundle | `mail-identity verify-dns --target tits-guru` — A, PTR, SPF, DKIM and DMARC as `1.1.1.1` and `8.8.8.8` both answer them, and the key usable |
| the requested bundle | `install-mail-signing --verify --target tits-guru` — OpenDKIM enabled and running on exactly `127.0.0.1:8891`, able to read the key |
| the pre-activation bundle | `verify-mail-signing --e2e --target tits-guru` — a foreign `From` refused before it is queued, a valid probe signed, held and deleted by its exact queue ID |
| the queue | the HOLD queue holds nothing — anything held is mail nobody reviewed; its queue IDs are reported, never its sender, recipient or content, and it is never released |

No connection leaves the host during the proof.

**The transaction.** Only then:

1. the rollback capsule `/var/lib/rateguru-mail-activation/tits-guru/`
   (root-only): the two pre-activation documents, and `capsule.json` with the
   target, the time and the SHA-256 of the four public documents. Never a key, a
   key's hash, an environment file, a credential or a message;
2. a one-use **ACTIVATE authorization** for the gateway, bound to `tits-guru`,
   the direction and the SHA-256 of the exact recorded and requested policies,
   then `install-mail-gateway --apply` from the requested bundle — the
   gateway's one owner, which refuses this transition to anyone without that
   authorization and consumes it; nothing about Postfix is spelled by the
   activation;
3. `install-mail-gateway --verify`;
4. `mail-identity readiness --target tits-guru`: every condition PASS —
   target, routing, direct, mta, identity, key, A, PTR, SPF, DKIM, DMARC,
   signing — and `OUTBOUND READY: YES`;
5. the live read-back again: `tits-guru`'s listener alone owns its direct
   route, staging still captures and has no direct transport, nothing listens
   on a public SMTP port, and the signer is still on loopback only.

**Any failure from step 2 on** — the installer refusing or failing half-way, a
verify that does not pass, readiness short of YES, an error or a signal —
returns the host to held: a one-use **ROLLBACK authorization** when the
gateway recorded the outbound policy, the capsule's pre-activation bundle's
`install-mail-gateway --apply`, its `--verify`, the held read-back and the
signing acceptance again. When even that cannot be proved the result is
**CRITICAL** and nothing broader is attempted. The queue is never flushed,
held mail is never released (`postsuper -H` is never run), and no unrelated
entry is requeued or deleted.

**Again.** On a host already activated exactly as requested and fully ready,
`--apply` is a no-op that succeeds; it writes no new capsule and sends nothing.

**Result.** One line, `RATEGURU_MAIL_OUTBOUND_ACTIVATION_RESULT={"target",
"mode", "status", "requested", "changed", "rolled_back", "outbound_ready"}`,
with `status` `pass`, `fail` or `critical`.

`--check` proves everything `--apply` does except the signing acceptance, which
submits a probe, and changes nothing. `--verify` is the read-only view of an
activated target: the gateway's and the signer's verifies, public DNS, full
readiness and the route read-back, ending in `OUTBOUND READY`.

## The initial-launch rollback

**Rollback tits.guru outbound mail activation** runs
`activate-mail-outbound --rollback --target tits-guru`. It is the rollback of
the *initial launch*, and nothing more:

- only while `tits-guru` is still `planned` — a live target's mail is never
  stopped by re-rendering it held;
- only with this activation's own capsule, untampered — a missing, foreign or
  edited capsule is refused and never used or removed;
- only while `main` still requests exactly the activation the capsule recorded
  — a configuration changed since then makes the capsule stale.

It re-applies the capsule's pre-activation (held) gateway through the
gateway's own installer, proves it held — its verify, the read-back and the
signing acceptance — and changes no repository file. Afterwards:

- the **runtime is held** again;
- the gateway's **recorded policy is held**, and the authorization is used up;
- the **committed configuration on `main` still requests outbound delivery**;
- the activation change to `mail-routing.json` and `mail-outbound.json` **must
  be reverted — a pull request into `develop`, promoted to `main` — before the
  next Prepare or Verify**; until it is, Verify reports the difference and
  Prepare refuses to cross back to outbound; only another guarded activation
  does;
- ordinary Prepare and Verify run again only once the committed policy and the
  host's applied policy match — held on both sides.

The permanent mail hold of a live production target, and its recovery-time
fence, are later work (8.4B.7), not this.

## The canary

**Send tits.guru production mail canary** runs
`send-mail-canary --send --target tits-guru --recipient-file FILE`.

- **The recipient** is the GitHub Environment secret `MAIL_CANARY_RECIPIENT` in
  `production-tits-guru`, and nothing else — the workflow has no input. Without
  it the workflow refuses before it touches the host. The secret is written to
  a `0600` file on the runner, copied into the root-only bundle directory on the
  host as a root-owned `0600` file, and named to the script by its path; it is
  never an argument and never echoed, and both copies are removed on every
  path. The file must hold exactly one public address and nothing else: no
  second line, space or control character, nothing under `.invalid`, `.test`,
  `.localhost`, `.local`, `.example` or the `example.*` domains. Output names
  the recipient's **domain** only.
- **The sender** is the reviewed one, from the routing plan: `noreply@tits.guru`
  as the envelope sender (`MAIL FROM`) and as the address in the `From`
  header, through `127.0.0.1:2526`. The header shows it as
  `From: TitsGuru <noreply@tits.guru>`; the display name is presentation only,
  and everything the gateway's From policy and the signer judge is the
  address. The application's own `MAIL_FROM_NAME` belongs to its first deploy,
  not to the canary.
- **Before any connection**: `tits-guru` must be production, planned, outbound
  by direct delivery, and `activate-mail-outbound --verify` must pass with
  `OUTBOUND READY: YES`.
- **One message**: plain text, with a unique non-secret canary ID —
  `rgcanary-YYYYMMDDTHHMMSSZ-` and 12 hex digits, the UTC time it was made —
  in its subject, body, `X-RateGuru-Mail-Canary` header and `Message-ID`
  (`<rgcanary-YYYYMMDDTHHMMSSZ-…@tits.guru>`).
- **Its own queue ID only**: the canary follows the queue ID Postfix returned
  through the mail log until its delivery status. `status=sent` passes;
  `bounced` or `expired` fails; still deferred after 15 minutes fails and
  deletes that one queue entry so it does not retry for days. An interrupted
  run deletes only its own entry, while it is still queued. Nothing is flushed
  or released, and no other entry is touched.

`RATEGURU_MAIL_CANARY_RESULT={"target", "mode", "status", "canary_id",
"message_id", "queue_id", "recipient_domain", "smtp_delivery", "dsn",
"deleted"}` — never the recipient's local part.

The workflow accepts that line only when it holds exactly those fields, for
`tits-guru`, in mode `send`, with a closed status and delivery status, a
recipient domain and never an address, a canary ID of the form above and a
`Message-ID` made from that same ID; a pass needs `smtp_delivery=sent`, its
queue ID and both IDs. The run then reports one of three things, never one as
another:

- **a failed delivery** — a checked result that is not a pass, shown with its
  delivery status;
- **a failed result check** — a result line that does not pass the check:
  nothing in it is shown, and the canary's own report in the job log says
  whether the message was accepted. Read it before sending another canary: a
  delivered message would be delivered again;
- **no result** — the canary printed none, so it did not complete.

The first real canary (run `37814715904`, 2026-10-08) was delivered —
`status=sent`, `dsn 2.0.0`, queue ID `346E9FC41DF`, received with SPF, DKIM and
DMARC passing — and its run still failed: the check then required a lowercase
`Message-ID`, while the canary ID carries the `T` and `Z` of its UTC time. The
check now follows the canary ID's own form.

### What `status=sent` is not

It is the local Postfix's record that the **remote MX accepted** the message.
It is not SPF, DKIM or DMARC acceptance at the receiver, and it says nothing
about where the message was filed. That is read by the operator from the
**raw headers of the message as received**, and the first real delivery is
accepted only when all of these hold:

| Header evidence | Required |
|-----------------|----------|
| SPF | `pass` |
| DKIM | `pass` |
| DMARC | `pass` |
| `From` | `TitsGuru <noreply@tits.guru>` (envelope sender `noreply@tits.guru`) |
| DKIM signature | `d=tits.guru`, `s=rg1`, `a=rsa-sha256` |
| Sending source IP | `213.199.41.241` |
| Sending MTA / HELO | `mta1.tits.guru` |
| PTR of the source IP | `mta1.tits.guru` |

Nothing local stands in for this proof, and a local `status=sent` is never
reported as it.

## Rollout

### Tooling rollout (done)

The tooling itself activated nothing; it was rolled out and accepted on the
shared host on 2026-10-08, with `tits-guru` still held and direct delivery
disabled:

| Step | Run | Result |
|------|-----|--------|
| Merge the tooling pull request into `develop`. | — | merged |
| Run **Prepare staging host** (`develop` `92252559`). | `37779425683` | PASS |
| Run **Verify staging infrastructure** (`develop` `92252559`). | `37780411751` | PASS |
| Promote `develop` → `main`. | — | promoted |
| Run **Verify production infrastructure** (`main` `771268e2`). | `37780729123` | PASS |
| Run **Verify production mail signing** (`main` `771268e2`). | `37781550331` | PASS |

The gateway recorded its applied policy on the host (held, direct delivery
disabled), and the signing acceptance found a real `d=tits.guru`, `s=rg1`,
`a=rsa-sha256` signature on a synthetic message in HOLD.

### The activation change

The activation change is an ordinary pull request **into `develop`**. It
changes the policy in exactly two files —

- `infrastructure/config/mail-routing.json` — for `tits-guru`,
  `"delivery_mode": "held"` → `"delivery_mode": "outbound"`, and
  `"outbound": {"kind": "direct"}` added;
- `infrastructure/config/mail-outbound.json` — `"enabled": false` →
  `"enabled": true`;

— together with the tests and documents that depended on the committed policy
being held. It is reviewed on its own, passes the complete CI, and reaches
`main` by the ordinary promotion. A pull request directly into `main`, and a
synchronization back from `main` into `develop`, are not part of this rollout.

### After the activation change is merged

1. The activation pull request into `develop`: the new policy, the tests and
   the documents.
2. The complete CI passes.
3. Merge it into `develop`.
4. **From here until step 11, run neither Prepare staging host nor Verify
   staging infrastructure.** Staging and production share one machine today,
   and it is still held while `develop` already requests outbound delivery:
   Verify would report that expected difference, and Prepare would be refused.
5. Promote `develop` → `main` the ordinary way.
6. Run **Activate tits.guru outbound mail** by hand, from `main`.
7. Run **Verify production infrastructure**. It must report full outbound
   readiness — `OUTBOUND READY: YES` — while the target's lifecycle and
   application stay deferred, because `tits-guru` is still planned.
8. Add `MAIL_CANARY_RECIPIENT` to the `production-tits-guru` GitHub
   Environment.
9. Run **Send tits.guru production mail canary**, and confirm its result: the
   remote MX accepted the exact canary (`status=sent` for its own queue ID).
10. Inspect the real received message and its raw headers against the table
    above.
11. Run **Verify staging infrastructure**. `develop` and `main` now request the
    activated routing and outbound policy the host applies.
12. Only then record the activation and the first delivery as
    production-accepted, from those actual results.

**Why no ordinary Prepare runs between the merge and Activate.** From step 3
the repository requests outbound delivery while the host is still held, so any
ordinary gateway apply — Prepare staging host, Prepare production host,
bootstrap or repair — meets the activation boundary. It must refuse held →
outbound without the activation's one-use authorization, and it does: the
gateway fails closed and the host stays held. That refusal is the interlock
working, not something to retry; only Activate crosses, after its own proof.
Verify, read-only, reports the same difference until Activate has run.

### Activation rollout (done)

The activation ran in that order on 2026-10-08 and was production-accepted from
its real results:

| Step | Run | Result |
|------|-----|--------|
| Run **Activate tits.guru outbound mail** (`main`). | `37813433328` | SUCCESS |
| Run **Verify production infrastructure**. | `37814215899` | SUCCESS — `OUTBOUND READY: YES` |
| Run **Send tits.guru production mail canary** to Gmail. | `37814715904` | delivered — `status=sent`, DSN `2.0.0`, SPF, DKIM and DMARC PASS — but the run failed its result check (see [The canary](#the-canary)) |
| Make the result check follow the canary ID's form, and show the sender as `TitsGuru`. | — | merged and promoted |
| Run **Send tits.guru production mail canary** to Gmail again. | `37821815403` | SUCCESS |
| Inspect the received message's raw headers. | — | `From: TitsGuru <noreply@tits.guru>`; SPF PASS, DKIM PASS (`d=tits.guru`, `s=rg1`), DMARC PASS; TLS 1.3; from `213.199.41.241` as `mta1.tits.guru` |
| Check with Mail-Tester. | `37822226157` | SUCCESS — SpamAssassin and blocklists pass; 7/10 for the missing MX of `tits.guru`, which is inbound mail |
| Run **Verify staging infrastructure**. | `37823079457` | SUCCESS — 6 PASS, 0 FAIL, 0 DEFERRED, 1 N/A; Mailpit, Mailtrap Local and staging's isolation confirmed |

The production application is still `lifecycle=planned` and undeployed: outbound
delivery was activated independently of it, and its own `MAIL_*` values belong
to its first deploy (see below). Mail sent today carries the envelope sender
`noreply@tits.guru`.

### If anything is wrong before go-live

1. Run **Rollback tits.guru outbound mail activation**. The runtime returns to
   held, and the gateway's recorded policy with it.
2. Return the policy to held with a separate pull request into `develop` —
   reverting the activation change — through CI, promoted to `main`.
3. Only once the committed policy and the host's applied policy match again
   run ordinary Verify and Prepare.

## The applied policy and the boundary

The gateway records the complete public routing and outbound policy it was last
applied from, and Verify fails on any difference — including fields Postfix
never reads, such as `default_from` or the bounce and reply domains.
Crossing between held and outbound is protected by the activation interlock:
Prepare converges a state but cannot cross that boundary, and a Prepare from a
stale branch fails closed instead of activating or deactivating mail. See
[`mail-gateway.md`](mail-gateway.md#the-applied-policy-and-the-activation-boundary).

The gateway now calls itself by the reviewed MTA hostname (`mta1.tits.guru`),
so production mail no longer carries `mail-gateway.rateguru.invalid` in its
`Received` hop.

## Application mail transport is not part of this

`tits-guru` is still planned and no application is deployed, so its
application mail settings change nothing yet and would only widen this
operation. Deliberately **not** changed here: the production `shared/.env`
(`/home/www/rateguru/production/tits-guru/shared/.env`), GitHub `LARAVEL_ENV`,
and the production environment template defaults.

Before the first production application deploy, a separately reviewed
operation sets and verifies the application's mail transport from the reviewed
mail routing plan:

- `MAIL_MAILER=smtp`
- `MAIL_HOST=127.0.0.1`
- `MAIL_PORT=` the target's reviewed submission port (`2526` for `tits-guru`)
- `MAIL_FROM_ADDRESS=` the reviewed `default_from` (`noreply@tits.guru`)
- no SMTP username or password: the submission is local, on loopback.

## What this does not do

No lifecycle activation (`tits-guru` stays `planned` in
`deployment-targets.json`), no deploy authorization, release, migration or
application service, no DNS or PTR change, no `/etc/hosts`, hostname or
resolver change, no public SMTP listener, no change to staging's capture route,
and no read, copy, hash or replacement of the DKIM private key.

Later, and not here: bounce reception, inbound SMTP, reply routing and the
support mailbox (8.4B.5); suppression, delivery and bounce state and per-target
metrics (8.4B.6); and 8.4B.7 — the DKIM private key in production backup and
recovery material, a recovery-time outbound fence, A/PTR/SPF re-acceptance
before mail resumes on a replacement host, and a host-scoped mail topology once
staging and production run on separate machines. Production web TLS and Nginx,
the first production deploy, production backup activation, the public cutover
and sender warm-up are 8.5–8.7.

## Rehearsed on a real host

The shipped scripts were run in an Ubuntu 22.04 systemd container with the real
Postfix 3.6.4 and OpenDKIM `2.11.0~beta2-6` packages and a rehearsal-only key.
The signer and the gateway were first installed by the previous gateway — the
one the shared host runs today, which records no policy — and the container was
then cut off from every network but two internal ones, on which a DNS fixture
answered as both `1.1.1.1` and `8.8.8.8` and an isolated Postfix played the
recipient's MX. No mail could leave, and none did.

| Step | Result |
|------|--------|
| the new gateway on that host, committed (held) bundle: `--check`, `--verify`, `--apply`, `--verify` | `MISSING policy:applied` (check passes, verify fails), then `applied-plan.json` and `applied-outbound.json` recorded (`root:root 644`, canonical, no key), verify 12 PASS; `myhostname = mta1.tits.guru` |
| ordinary `install-mail-gateway --apply` from a bundle requesting the activation | `CONFLICT policy:transition` in `--check`; `--apply` refused, nothing changed, still held |
| `activate-mail-outbound --check` / `--apply` from a request that also changes `default_from` | refused before any change: the recorded policy differs (`listeners.tits-guru.sender.default_from`) |
| `activate-mail-outbound --apply` | ACTIVATE authorization written and consumed by the gateway, readiness `OUTBOUND READY: YES`, `2526 → rateguru-outbound-tits-guru:` |
| canary to the isolated MX | `status=sent`; the stored message has `Received: from mail-canary … by mta1.tits.guru` and `Received: from mta1.tits.guru (mta1.tits.guru [1.1.1.10])`, `DKIM-Signature d=tits.guru s=rg1` (verified there by `opendkim-testmsg`), and no `mail-gateway.rateguru.invalid` anywhere |
| ordinary `install-mail-gateway --apply` from the committed (held) bundle | refused (outbound → held), nothing changed, still outbound |
| `activate-mail-outbound --rollback` | ROLLBACK authorization written and consumed, RUNTIME HELD AGAIN |
| ordinary apply of the outbound request after that | refused again; still held |
| `activate-mail-outbound --apply` again, then `--rollback` | DONE, then held; ledger: activate, rollback, activate, rollback |
| afterwards | committed bundle's gateway verify 12 PASS and signing acceptance PASS, queue empty, key SHA-256 unchanged (`root:opendkim 640`), no private key text in any log, no route to the Internet |

The first rehearsal of the activation tooling, on the same packages, also
proved `--check`, idempotence, `--verify`, the bounced and deferred canaries,
and the automatic return to held after a forced failure.
