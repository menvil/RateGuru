# Mail gateway

The host-global Postfix gateway every target submits its mail to, rendered from
the reviewed mail routing policy. The policy, and why the submission port is a
target's identity, are in [`mail-routing.md`](mail-routing.md); this runbook is
how the gateway is installed, verified and accepted, and how its direct outbound
transport works and stays switched off.

## Status

| What | State |
|------|-------|
| `install-mail-gateway` / `verify-mail-gateway` / `status-mail-gateway` | **Implemented**, part of host bootstrap |
| Gateway on the staging host | **Installed and accepted** — Postfix 3.6.4, configuration exactly the reviewed render, verified on the real host |
| Real-host acceptance (`verify-mail-gateway --e2e`) | **Passed** on the real staging host — see [Real-host acceptance](#real-host-acceptance) |
| Staging application mail | **Through the gateway**: the host's `shared/.env` says `MAIL_PORT=2525` (Laravel → gateway → Mailpit → Mailtrap Local) |
| `tits-guru` | `lifecycle=planned`, `delivery_mode=held`; its listener exists and **holds** everything; nothing is delivered |
| DKIM signing of `tits-guru`'s listener | **Implemented — production acceptance pending**: its listener hands each message to the host's DKIM signer before it is held — see [Signing](#signing-held-mail-is-signed-and-still-held) and [`mail-signing.md`](mail-signing.md) |
| Direct outbound transport (`delivery_mode=outbound`, `outbound.kind=direct`) | **Implemented, not activated**: no target uses it, and `config/mail-outbound.json` keeps direct delivery **disabled** on the host, so no outbound route can be rendered or installed |
| Production outbound delivery | **None**: no route to the Internet exists on any host |

## What is installed

```
Laravel staging ──SMTP──▶ 127.0.0.1:2525 ─┐
                                          │  Postfix gateway (one per host)
tits-guru (not deployed) ─▶ 127.0.0.1:2526 ┤    listener = routing identity
                                          │    sender domain = authorization
                                          ▼
              2525 (capture) → Postfix queue → smtp:[127.0.0.1]:1025 Mailpit → Mailtrap Local mirror
              2526 (held)    → HOLD queue    → no route, ever
              anything else  → error(8)      → never delivered
```

That is the whole gateway on the real host today. The direct outbound route
described [below](#direct-outbound-implemented-switched-off) is implemented but
appears in no target's policy and is disabled for the host, so nothing in it is
rendered.

One Postfix instance for the host, owned end to end by
`infrastructure/scripts/install-mail-gateway`:

- **Listeners.** One `smtpd` per target, on exactly the loopback endpoint its
  policy names (`127.0.0.1:2525`, `127.0.0.1:2526`). There is no `smtp`
  (port 25), `submission` (587) or `smtps` (465) listener, nothing binds
  `0.0.0.0`, `::` or a public address, and Postfix runs IPv4-only. A firewall is
  never what makes this safe: Postfix itself binds nothing public.
- **Capture is store-and-forward.** The staging listener sets a content filter
  naming its own transport and its capture destination. Postfix **accepts and
  queues** the message, then delivers it to `[127.0.0.1]:1025`. If Mailpit is
  down, the message is **deferred and retried**, not lost, and Laravel never
  depended on Mailpit being up at that moment. Mailpit then mirrors to Mailtrap
  Local exactly as [`mail-capture.md`](mail-capture.md) describes.
- **Held means HOLD.** The `tits-guru` listener accepts a message from its own
  domain and places it on Postfix's hold queue — DKIM-signed first, see
  [below](#signing-held-mail-is-signed-and-still-held). Nothing names a route for it:
  no Mailpit, no other target's transport, no relayhost, no DNS delivery. A
  held message released by hand still has nowhere to go — it bounces into the
  error transport.
- **Fail closed.** `default_transport`, `relay_transport`, `local_transport`
  and `virtual_transport` are all `error:`; `relayhost`, `mydestination`,
  `relay_domains` and `transport_maps` are empty; there is no generic `smtp`,
  `relay`, `local`, `virtual` or `lmtp` delivery agent. Mail with no rendered
  route — a local `sendmail`, a cron report, anything — is undeliverable. The
  only smtp clients are the ones the plan's own listeners name, and on the real
  host today that is the staging capture transport alone: there is no Internet
  delivery in it at all.
- **Sender policy.** The port chooses the target; the envelope sender is then
  checked against that target's domain, **exactly** (a subdomain is a different
  identity): `2525` accepts only `@staging.invalid`, `2526` only `@tits.guru`.
  An empty sender (`<>`) is accepted. A wrong domain is refused at `MAIL FROM`
  with `554 5.7.1`. There is no SMTP AUTH and no TLS on these loopback ports:
  the boundary is the host and the target's own port.

The rendered files are `/etc/postfix/main.cf` and `/etc/postfix/master.cf`,
`root:root 0644`, byte-for-byte functions of `mail-routing render-plan`.
Nothing else under `/etc/postfix` is RateGuru's. The routes are never restated
in the installer: it runs `infrastructure/scripts/mail-routing render-plan`
from the same bundle and only spells the plan in Postfix syntax, and a delivery
mode it has no spelling for is a refusal.

## Signing: held mail is signed, and still held

A listener whose target has a reviewed signing identity — the targets
`mail-identity render-signing-plan` lists, today `tits-guru` alone — hands every
message it accepts to the host's DKIM signer before Postfix queues it:

```
127.0.0.1:2526 inet n - n - - smtpd
  ...
  -o smtpd_recipient_restrictions=check_client_access,static:HOLD,permit_mynetworks,reject
  -o content_filter=
  -o smtpd_milters=inet:127.0.0.1:8891
  -o milter_protocol=6
  -o milter_default_action=tempfail
```

- **Only the signed listeners.** The staging capture listener names no milter,
  and neither `main.cf` nor locally submitted mail does: `smtpd_milters` and
  `non_smtpd_milters` stay empty globally. With no signing identity the render
  is byte for byte the gateway staging accepted.
- **The endpoint is the signer's.** The gateway asks
  `install-mail-signing --milter-endpoint` — in the same bundle — where the
  signer listens, refuses anything but a loopback `inet:` endpoint, and spells
  no address or port of its own.
- **Never unsigned.** `milter_default_action=tempfail`: when the signer is down
  or cannot sign, the listener defers the message (`451 4.7.1`) rather than
  accept it unsigned. Proved on a real Ubuntu 22.04 host.
- **Signing routes nothing.** The held listener still has no content filter and
  still holds everything; the milter only adds a `DKIM-Signature` header.
- **Verified.** `--verify` reads the wiring back through Postfix — the exact
  endpoint, protocol 6 and `tempfail` on each signed listener, no milter on any
  other, none globally — and requires the signer listening.

The signer itself — the OpenDKIM package, its configuration and its access to
each key — is installed, verified and accepted as
[`mail-signing.md`](mail-signing.md) describes, before the gateway in host
bootstrap.

## Direct outbound (implemented, switched off)

A production target's mail is eventually delivered by the gateway itself,
straight to each recipient's mail servers — self-hosted direct SMTP, with no
provider, relay host, smart host or credential. The capability exists; it is
not active anywhere.

```
Laravel ─▶ 127.0.0.1:<target port> ─▶ Postfix queue ─▶ rateguru-outbound-<target> (smtp)
                                                         │  next hop = recipient's own domain
                                                         ▼
                                              recipient domain MX ─▶ Internet
```

**Two contracts, and both must agree.**

1. The target's policy says `delivery_mode: "outbound"` with
   `outbound: {"kind": "direct"}`, beside the same reviewed identity a held
   policy carries (`mail_domain`, `default_from`, `bounce_domain`,
   `reply_domain`). Only a production target may use it, and `direct` is the
   only kind: a relay through a provider is added only by the change that
   implements one. See [`mail-routing.md`](mail-routing.md).
2. The **host's** outbound contract, `infrastructure/config/mail-outbound.json`:

   ```json
   {
     "schema_version": 1,
     "direct": {
       "enabled": false,
       "mta_hostname": "mta1.tits.guru"
     }
   }
   ```

   Every brand on a host shares its source IP address, and an address has
   exactly one PTR name. So the name direct delivery greets receiving servers
   with (`HELO`/`EHLO`) is the **host's** physical MTA identity, kept here once,
   and never a target's `From` domain or anything in a target's policy.
   `enabled: false` means direct delivery does not exist on this host;
   `mta_hostname` may then be empty, or — as now — name the reviewed identity
   ahead of its enablement, which renders nothing. `enabled: true` requires a lowercase,
   fully qualified public hostname — never under `.invalid`, `.test`,
   `.localhost`, `.example`, `.local`, `.localdomain`, `.internal`, `.alt`,
   `.onion` or `.arpa`, because a receiving server compares it with the PTR of
   the sending address. The file holds no credential; there is nowhere in it to
   put one.

**Fail closed before anything changes.** `install-mail-gateway` judges the host
contract in every mode, before it renders. A plan with a direct route on a host
whose contract says `enabled: false` — or `enabled: true` with an empty or
invalid hostname — is refused by `--check`, `--apply` and `--verify` alike,
before a file is written or a service touched. So changing a target's policy
from `held` to `outbound` can never by itself put mail on the Internet: the
host's identity has to be enabled deliberately as well. Host bootstrap runs
`--check` before its first mutation, so Prepare Host stops there too.

**What is rendered when both agree**, per outbound target:

- its listener sets `content_filter = rateguru-outbound-<target>:` — its own
  transport, and **no next hop**;
- `main.cf` sets `default_filter_nexthop =` (empty). Postfix's queue manager
  then uses each recipient's own domain as the next hop of a filter that names
  none (`qmgr_message.c` in Postfix 3.6.4: an empty filter next hop falls back
  to `default_filter_nexthop`, then to the recipient's domain, and only for a
  recipient with no domain to `$myhostname`). The smtp client therefore looks up
  the **recipient domain's MX** and delivers there, one queue per domain,
  deferring and retrying like any Postfix delivery;
- one dedicated `rateguru-outbound-<target>` smtp(8) service in `master.cf`,
  named by that listener and by nothing else:
  - `smtp_helo_name` = the host's `mta_hostname`;
  - `smtp_tls_security_level = may` — opportunistic STARTTLS whenever the
    receiving server offers it, never required, because many legitimate MX
    servers do not offer authenticated TLS (`smtp_tls_loglevel = 1` logs it);
  - `smtp_sasl_auth_enable = no` and an empty `smtp_fallback_relay` — no
    SMTP AUTH and no relay of any kind.

Nothing else moves: `relayhost` stays empty, every fallback transport stays
`error:`, the generic `smtp` and `relay` services stay absent or `error`, the
listeners stay on loopback and IPv4 (`inet_protocols = ipv4` makes the outbound
client IPv4 too), and unclassified mail stays undeliverable. A plan with no
outbound target renders **byte for byte** what it rendered before outbound
routes existed, so the real host does not drift.

**Not in this capability** — each needs its own reviewed change before any
target is switched to `outbound`: DKIM signing, published and verified DNS
(the reviewed MTA hostname, its PTR, SPF, DKIM and DMARC — see
[`mail-identity.md`](mail-identity.md)), the production Return-Path and bounce
reception, reply routing, a support mailbox, the production `MAIL_*` values, a
controlled real canary delivery, header verification at the large mailbox
providers, and sender reputation warm-up. Until then the real
`mail-outbound.json` stays `enabled: false` and `tits-guru` stays `held`.

The contract is judged by `infrastructure/scripts/mail-identity`
(`check-outbound`), the one judge of the host's and the targets' mail
identity; this installer asks it and restates none of its rules.

## Ownership and the package

- `--apply` installs Ubuntu 22.04's `postfix` package with debconf preseeded
  **No configuration**: the package writes no `main.cf` of its own and never
  manages one, so the daemon cannot start at all until RateGuru's
  configuration is in place (its unit is conditional on `main.cf`). A
  `policy-rc.d` is in place for the installation as well, so the maintainer
  scripts start nothing; one that already existed is preserved and put back.
- The package's own `master.cf` repair (`fix_master`) runs on every upgrade and
  appends any internal service it finds missing — including a `relay` service
  that would be a working smtp client. The rendered `master.cf` already carries
  every one of them, with `relay` as the error transport, so a postfix upgrade
  changes nothing and `--verify` does not drift.
- Before the package goes in, an ownership marker is written to
  `/var/lib/rateguru-mail-gateway/ownership` (`state=installing`, then
  `state=installed` once the gateway is verified). It is non-secret.
- **A Postfix without that marker is not RateGuru's.** If Postfix (or its
  `/etc/postfix`) exists without the marker, or another mail transport agent is
  installed, every mode fails closed before changing anything. Nothing is taken
  over, rewritten or removed.
- An interrupted apply leaves `state=installing`; the next `--apply` recognises
  it as RateGuru's partial installation and resumes it, first undoing any
  `policy-rc.d` the interrupted run left behind.

## Installation

The gateway is part of host bootstrap: **Prepare staging host** installs and
converges it, after mail capture (its capture destination must exist before the
gateway is accepted as healthy). On the host:

```bash
# Read-only: would --apply converge? Reports what it would install or change.
sudo infrastructure/scripts/install-mail-gateway --check

# Install or converge. Transactional: validated by Postfix before it is
# installed, rolled back (files and service state) on any failure.
sudo infrastructure/scripts/install-mail-gateway --apply

# Read-only: the authoritative contract.
sudo infrastructure/scripts/install-mail-gateway --verify
```

`--apply` renders into a private directory, has Postfix validate the candidate
(`postconf -n`, `postconf -M`, `postfix check`, and the fail-closed values read
back), records the managed files and the service's boot and running state,
installs complete files atomically, starts or reloads only after that, and
verifies the running gateway before it commits. Any failure restores the
previous files and service state; a rollback that cannot finish says
`rollback INCOMPLETE` and fails. Configuration backups are kept under
`/var/backups/rateguru-mail-gateway/<timestamp>/` — configuration only, never
the queue and never a message.

Target-scoped operations (Repair Target, `provision-target --provisioning`)
never install, verify or rewrite the gateway: it belongs to the host.

## Verification

```bash
# Strictly read-only — what host bootstrap uses.
sudo infrastructure/scripts/verify-mail-gateway --read-only

# Mutating operator acceptance.
sudo infrastructure/scripts/verify-mail-gateway --e2e
```

`--read-only` is `install-mail-gateway --verify`: installed files equal the
current render, Postfix parses them, `postfix.service` is enabled and
`postfix@-.service` stably running, the listeners are exactly the plan's
loopback endpoints, nothing listens on 25/465/587, no gateway port is bound off
loopback, every capture destination is listening, held mail has no route and
unclassified mail cannot be delivered. The smtp clients are exactly the plan's
transports, and for an outbound target its transport is the only one its
listener names, greets with the host's `mta_hostname`, uses `may` TLS, has no
SMTP AUTH and no fallback relay, and `default_filter_nexthop` is empty. No SMTP,
no queue change, no reload.

`--e2e` (root) runs all of that, then, for every listener in the plan:

- **A — capture:** a message from the listener's own domain is queued by
  Postfix and reaches Mailpit (canonical) and Mailtrap Local (mirror);
- **B — sender isolation:** a foreign domain, and every other listener's
  domain, is refused at `MAIL FROM`. The probe stops there — `RSET` and `QUIT`
  whatever the reply — so it never names a recipient or sends a message, even
  to a gateway that wrongly accepted the sender;
- **C — held:** a message from `noreply@tits.guru` is accepted, its exact queue
  entry is in HOLD, it is not in Mailpit, and that one entry is then removed;
- **D — outage:** Mailpit is stopped, a staging message is accepted and
  deferred, Mailpit is started again, that exact message is retried
  (`postqueue -i <id>`) and reaches Mailpit and its mirror;
- **E — outbound:** nothing is submitted. A message an outbound listener
  accepted would leave the host for the Internet, so B is its whole acceptance
  here, and `smtp_submit` refuses any endpoint that is not a capture or held
  listener before it opens a connection. A real outbound delivery is a
  deliberate production canary, never this diagnostic.

It removes only what it created — its Mailpit/Mailtrap messages by their unique
token, its queue entries by their exact queue ID — and never flushes or empties
the queue. Mailpit is put back the way it was on every exit.

`--e2e` is a low-level specialist primitive, run on the host as root when a
deep check of the gateway is wanted. There is no GitHub workflow for it: the
one-time manual workflow that ran it for the real-host acceptance has done its
job and was removed. Ordinary Prepare never runs the mutating acceptance.

From GitHub, **Verify staging infrastructure** and **Verify production
infrastructure** run `verify-mail-gateway --read-only` as part of the whole
target's read-only verification — never `--e2e`. See
[`infrastructure-verification.md`](infrastructure-verification.md).

## Status

```bash
sudo infrastructure/scripts/status-mail-gateway
```

Read-only: package and version, ownership marker, service state, listeners,
each listener's route as Postfix reads it from the installed configuration —
an outbound one as `outbound, queued -> direct SMTP -> recipient MX` with its
transport, HELO name and TLS level — queue counts by queue (counts only — no
address, no body), and the last hour of gateway warnings. Logs:
`journalctl -u postfix@-.service`.

## Staging cutover

**Done.** The committed staging environment contract names the gateway
(`MAIL_PORT=2525` in `staging.env.example`), and the real staging host runs it.
The host's own `shared/.env` is operator-owned and **is never changed by code**;
the cutover was an operator action, in this order:

1. **Prepare staging host** installed and verified the gateway.
2. The end-to-end acceptance (`verify-mail-gateway --e2e`) passed on the host.
3. The operator changed the host's `shared/.env`:
   `MAIL_PORT=1025` → `MAIL_PORT=2525` (`MAIL_MAILER=smtp`,
   `MAIL_HOST=127.0.0.1` and `MAIL_FROM_ADDRESS=noreply@staging.invalid` were
   unchanged).
4. **Deploy to staging** ran again, so deploy's `artisan config:cache` read the
   new value.
5. A real application-generated staging email — a Laravel password reset — was
   sent.
6. It arrived in Mailpit and in Mailtrap Local.

**Rollback:** set the host's `shared/.env` back to `MAIL_PORT=1025` and deploy
staging again. The application then bypasses the gateway and returns to the
earlier direct-to-Mailpit path; the gateway can stay installed.

## Real-host acceptance

The gateway was accepted on the real staging host:

- the RateGuru-owned Postfix 3.6.4 package, the configuration exactly the
  reviewed render, and the service, all verified;
- no public SMTP listener;
- capture: `127.0.0.1:2525` → Postfix queue → `127.0.0.1:1025` Mailpit →
  Mailtrap Local mirror;
- sender isolation on every listener;
- `tits-guru` on `127.0.0.1:2526` → HOLD;
- Mailpit stopped → the message deferred in Postfix's queue → retried and
  delivered once Mailpit was back;
- the staging cutover above, ending in a real Laravel password-reset email that
  arrived in Mailpit and in Mailtrap Local.

That acceptance covered capture and hold only. The direct outbound transport
has had no real-host acceptance, and cannot have one while it is disabled: its
first real delivery is a controlled production canary, after the production
mail identity exists.

## Troubleshooting

- **`--apply` refuses an unmanaged Postfix:** a Postfix, its `/etc/postfix`, or
  another MTA was on the host before RateGuru. Decide what it is for; RateGuru
  will not take it over.
- **Staging mail queues but does not arrive:** Mailpit is down or not on
  `127.0.0.1:1025` — `status-mail-gateway` shows the deferred count and
  `verify-mail-capture --read-only` the capture side. Deferred mail is retried
  automatically, or one message at a time with `postqueue -i <queue-id>`.
- **A message is refused with `554 5.7.1 ... Sender address rejected`:** the
  envelope sender is not exactly the listener's domain.
- **`--check` refuses with "direct outbound delivery is not enabled on this
  host":** a target's policy says `outbound` with `kind: direct`, and
  `config/mail-outbound.json` still says `enabled: false`. That refusal is the
  safety interlock, not a fault: enable direct delivery only once the host's
  public MTA identity — its hostname and PTR — exists.
- **Never** run `postqueue -f`, `postsuper -d ALL` or `postsuper -H ALL` to
  "fix" a held queue: held mail has no route by design.
