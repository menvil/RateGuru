# Mail outbound activation

How a planned production target's mail goes from **held** to **direct outbound
delivery**, how that switch is undone before the target goes live, and how the
first real message is sent and accepted. The gateway itself is
[`mail-gateway.md`](mail-gateway.md); the signer is
[`mail-signing.md`](mail-signing.md); the identity and DNS it depends on are
[`mail-identity.md`](mail-identity.md).

Merging this tooling activates nothing. The committed configuration keeps
`tits-guru` held with direct delivery disabled, so every workflow below refuses
before it changes anything until a separate, reviewed activation change is
merged into `main`.

## Status

| What | State |
|------|-------|
| `activate-mail-outbound` (`--check`, `--apply`, `--verify`, `--rollback`) | **Implemented** — repository tooling run from a trusted bundle |
| `send-mail-canary` (`--check`, `--send`) | **Implemented** — repository tooling run from a trusted bundle |
| `smtp-submission` | **Implemented** — the one SMTP conversation the signing acceptance and the canary share |
| Workflows **Activate tits.guru outbound mail**, **Rollback tits.guru outbound mail activation**, **Send tits.guru production mail canary** | **Implemented**, `main` only |
| `tits-guru` mail | **Held**, direct delivery **disabled**, `lifecycle=planned` — production activation **pending** |
| First real delivery | **Pending** — accepted only once a real canary was received and its raw headers inspected |
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
  be reverted on `main` before the next Prepare or Verify** — until it is,
  Verify reports the difference and Prepare refuses to cross back to outbound;
  only another guarded activation does.

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
  as both envelope sender and `From`, through `127.0.0.1:2526`.
- **Before any connection**: `tits-guru` must be production, planned, outbound
  by direct delivery, and `activate-mail-outbound --verify` must pass with
  `OUTBOUND READY: YES`.
- **One message**: plain text, with a unique non-secret canary ID in its
  subject, body and `Message-ID` (`<rgcanary-…@tits.guru>`).
- **Its own queue ID only**: the canary follows the queue ID Postfix returned
  through the mail log until its delivery status. `status=sent` passes;
  `bounced` or `expired` fails; still deferred after 15 minutes fails and
  deletes that one queue entry so it does not retry for days. An interrupted
  run deletes only its own entry, while it is still queued. Nothing is flushed
  or released, and no other entry is touched.

`RATEGURU_MAIL_CANARY_RESULT={"target", "mode", "status", "canary_id",
"message_id", "queue_id", "recipient_domain", "smtp_delivery", "dsn",
"deleted"}` — never the recipient's local part.

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
| `From` | `noreply@tits.guru` |
| DKIM signature | `d=tits.guru`, `s=rg1`, `a=rsa-sha256` |
| Sending source IP | `213.199.41.241` |
| Sending MTA / HELO | `mta1.tits.guru` |
| PTR of the source IP | `mta1.tits.guru` |

Nothing local stands in for this proof, and a local `status=sent` is never
reported as it.

## Rollout

1. Merge the tooling pull request into `develop`.
2. Run **Prepare staging host**.
3. Run **Verify staging infrastructure**.
4. Promote `develop` → `main`.
5. Run **Verify production infrastructure**.
6. Run **Verify production mail signing**.

   Production is still held, direct delivery disabled, `tits-guru` planned.

   A separate, tiny **activation pull request directly against `main`** changes
   exactly two files and nothing else:

   - `infrastructure/config/mail-routing.json` — for `tits-guru`,
     `"delivery_mode": "held"` → `"delivery_mode": "outbound"`, and
     `"outbound": {"kind": "direct"}` added;
   - `infrastructure/config/mail-outbound.json` — `"enabled": false` →
     `"enabled": true`.

   It is reviewed on its own before merge. It goes to `main` directly on
   purpose: staging and production share one machine today, so the same change
   in `develop` would let an ordinary **Prepare staging host** change the real
   production gateway before the controlled cutover.

7. After that pull request is merged: run **Activate tits.guru outbound mail**.
   A **Prepare production host** in between cannot activate anything: the
   gateway refuses held → outbound without the activation's authorization, and
   the host stays held.
8. Run **Verify production infrastructure**.
9. It must report full outbound readiness — `OUTBOUND READY: YES` — while the
   target's lifecycle and application stay deferred, because `tits-guru` is
   still planned.
10. Add `MAIL_CANARY_RECIPIENT` to the `production-tits-guru` GitHub
    Environment.
11. Run **Send tits.guru production mail canary**.
12. Confirm its result: the remote MX accepted the exact canary
    (`status=sent` for its own queue ID).
13. Inspect the received message's raw headers against the table above.
14. Only after that acceptance, synchronize `main` → `develop`, so both
    branches carry the activated routing and outbound policy.
15. Run **Verify staging infrastructure** against the synchronized `develop`.
16. Then the activation and first delivery may be recorded as
    production-accepted.

If anything is wrong before go-live: **Rollback tits.guru outbound mail
activation**, then revert the activation pull request on `main` before the next
Prepare or Verify.

Between Activate and step 14, `develop` still holds `tits-guru`'s mail. A
**Prepare staging host** in that window cannot deactivate production mail: the
gateway refuses outbound → held without a rollback authorization, and
production keeps delivering. **Verify staging infrastructure** reports that
difference as drift until `main` reaches `develop`.

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
