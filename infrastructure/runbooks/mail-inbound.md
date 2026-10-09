# Mail inbound

How a production target will receive mail from the Internet: support mail,
delivery status notifications for the mail it sent, and people replying to it.
This runbook covers the reviewed **inbound contract**, the addresses it accepts
and refuses, the DNS it needs, and the requirements and order of everything that
comes after it. Nothing here receives mail yet: no receiver exists, nothing
listens on port 25, and no MX record is published.

Outbound mail is a separate path with its own runbooks —
[`mail-routing.md`](mail-routing.md), [`mail-gateway.md`](mail-gateway.md),
[`mail-identity.md`](mail-identity.md), [`mail-signing.md`](mail-signing.md) and
[`mail-outbound-activation.md`](mail-outbound-activation.md) — and none of it is
changed by this.

## Status

| What | State |
|------|-------|
| Inbound contract `infrastructure/config/mail-inbound.json` | **Implemented**, reviewed — schema 1 |
| `infrastructure/scripts/mail-inbound` (`validate`, `render-plan`, `render-dns`, `route`) | **Implemented**; repository tooling, read-only, never installed on a host |
| Public inbound SMTP | **Disabled** — the only state the contract has; no receiver exists and nothing listens on port 25 |
| Inbound receiver (its own Postfix instance) | **Not installed** — designed here, built and activated in 8.4B.5.2 |
| MX records for `tits.guru`, `bounce.tx.tits.guru`, `reply.tits.guru` | **Not published** — and not before the receiver is active |
| `mx1.tits.guru` A record | **Not published**; the receiver's address is never committed |
| Support, bounce and reply handling | **None yet** — every handler in the plan is `planned` |
| Envelope sender (Return-Path) of mail sent today | `noreply@tits.guru` — the bounce domain is not an envelope sender yet |
| `tits-guru` outbound mail | **Production-accepted** 2026-10-08, and untouched by any of this — see [`mail-outbound-activation.md`](mail-outbound-activation.md) |

The envelope sender of every message sent today is `noreply@tits.guru`. Nothing
here claims that sent mail already names the bounce domain: moving the
Return-Path there is the bounce slice's change (see [the sequence](#the-sequence)).

## A separate receiver, not the gateway

The mail gateway (`install-mail-gateway`) is a **submission** service. It listens
only on each target's loopback port, its port is the sender's identity, it holds
no public port, and its checks refuse anything listening on 25, 465 or 587
anywhere on the host. A public MX is the opposite trust boundary: it accepts
connections from anyone on the Internet, for recipients this host is
responsible for. Putting a public listener into the gateway would make one
process hold both boundaries, so the receiver is a **separate Postfix
instance**:

```
Internet ──SMTP :25──▶ inbound receiver          (own instance, own queue, own lifecycle)
                          │ RCPT TO judged by the inbound plan, before any data
                          ├─ support@ / postmaster@tits.guru        → support mailbox      (planned)
                          ├─ b-<identifier>@bounce.tx.tits.guru     → bounce correlation   (planned)
                          ├─ r-<identifier>@reply.tits.guru         → reply routing        (planned)
                          └─ anything else                          → refused at RCPT TO

applications ──▶ 127.0.0.1:2525 / :2526 ──▶ mail gateway (loopback only) ──▶ Mailpit / recipient MX
```

- **Its own configuration, queue and lifecycle**, apart from the gateway's
  instance: installing, upgrading or stopping one never touches the other.
- **Public TCP 25 only after its own guarded activation** — never as a side
  effect of a Prepare, a bootstrap or a repair.
- **No SMTP AUTH, and never a relay**: it accepts mail only for the addresses
  and domains of the inbound plan, and refuses everything else at `RCPT TO`.
- **It cannot send.** It has no path into the gateway's submission ports and no
  SMTP client of its own, so nothing that arrives can leave through production
  outbound delivery.
- **It touches nothing else**: not staging capture, Mailpit, OpenDKIM or the
  outbound queues.

## The contract

`infrastructure/config/mail-inbound.json`, schema 1, as committed:

```json
{
  "schema_version": 1,
  "receiver": {
    "public_smtp": "disabled"
  },
  "targets": {
    "tits-guru": {
      "mx_hostname": "mx1.tits.guru",
      "support": { "local_parts": ["postmaster", "support"] },
      "bounce":  { "prefix": "b", "identifier": "base32-128" },
      "reply":   { "prefix": "r", "identifier": "base32-128" }
    }
  }
}
```

**It holds only what is new.** Every other value has one owner, and the contract
reads it from there:

| Value | Owner |
|-------|-------|
| A target's mail domain, sender, bounce domain and reply domain | `config/mail-routing.json`, read through `mail-routing render-plan` |
| Its lifecycle and environment class | `config/deployment-targets.json`, through the same plan |
| The host's outbound MTA hostname (`mta1.tits.guru`) | `config/mail-outbound.json`, judged by `mail-identity validate` |
| Whether public SMTP exists; per target its MX host, support local parts, and bounce and reply address forms | `config/mail-inbound.json` |

So `tits.guru`, `bounce.tx.tits.guru` and `reply.tits.guru` appear nowhere in the
inbound contract: change a domain in the routing policy and the inbound plan
follows. One file holds every brand's inbound policy; there is no file per brand.

**`mail-inbound validate` refuses**, each with its own reason:

- a schema other than 1, more than one document, a duplicated key, a control
  character, a symlink, or any property it does not know — at every level;
- a credential-like property name, however deep;
- `public_smtp` other than `disabled` — the only state while no receiver
  exists; the change that installs and activates the receiver adds the next one;
- a target the registry does not have, a staging target (staging is never a
  public mail destination), or a production target without a reviewed mail
  identity in the routing policy;
- a property that would restate a domain (`mail_domain`, `bounce_domain`,
  `reply_domain`, `domain`, `domains`);
- an MX host that is not a lowercase host name **inside the target's own mail
  domain** — `mx1.tits.guru` for `tits.guru`, never a name in another brand's
  zone or a provider's;
- one name with two owners: an MX host that is a mail, bounce or reply domain,
  another target's name, or the outbound MTA hostname;
- a support list without `postmaster` (RFC 5321 §4.5.1, see below), or a
  support local part that is a wildcard (`*`, `%`, `?`, empty), an address
  (`@`), not a lowercase local part, listed twice, or the reviewed no-reply
  sender's (`noreply`);
- a bounce or reply prefix that is not a short lowercase name, an identifier
  format other than `base32-128`, or bounce and reply sharing a prefix;
- any catch-all (`catch_all`, `wildcard`, `default`, `luser_relay`), forwarding
  (`forward_to`, `aliases`, `redirect`, `relayhost`, `transport`, …) or relay
  setting (`mynetworks`, `relay_domains`, `smtp_auth`, `open_relay`, …): none
  exists to configure, so the contract cannot describe an open relay.

## Destinations and addresses

The recipient's domain alone says which destination a message is for:

| Destination | Domain | Accepts | The identifier names | Future handler |
|-------------|--------|---------|----------------------|----------------|
| support | `tits.guru` | exactly `support@tits.guru` and `postmaster@tits.guru` — and `postmaster@` the bounce and reply domains | — | `support-mailbox` |
| bounce | `bounce.tx.tits.guru` | `b-<identifier>@bounce.tx.tits.guru` | the outbound message | `bounce-correlation` |
| reply | `reply.tits.guru` | `r-<identifier>@reply.tits.guru` | the conversation | `reply-routing` |

- **Support is exact.** No catch-all, no wildcard, no subaddress
  (`support+x@` is not support), and `noreply@tits.guru` is never a mailbox.
- **Postmaster is required.** RFC 5321 §4.5.1: a server that delivers mail
  MUST accept the reserved mailbox `postmaster`, case-insensitively, at every
  domain it provides mail service for, and the bare `RCPT TO:<Postmaster>`
  with no domain as well. So `postmaster` is an entry of `support.local_parts`
  that `validate` requires, and the plan accepts `postmaster@` each of the
  target's three domains — `tits.guru`, `bounce.tx.tits.guru`,
  `reply.tits.guru` — into the same `support-mailbox` handler; the bounce and
  reply address spaces can never collide with it, since their addresses always
  carry a prefix and an identifier. `postmaster+x@`, `post.master@`,
  `hostmaster@` and `abuse@` are not it. `route` judges qualified addresses
  only: how the receiver qualifies and accepts the bare `<Postmaster>`, which
  RFC 5321 equally requires, is its own requirement (`postmaster-accepted`),
  proved in its stage. RFC 5321 also expects mail to postmaster from anywhere
  to be accepted with every reasonable effort, so no limit may refuse it more
  broadly than an attack in progress requires.
- **Bounce and reply never mix.** Each has its own domain and its own prefix,
  and the two prefixes must differ, so a bounce address never reads as a reply
  address, whichever domain it arrives at.
- **The identifier, `base32-128`**: 128 bits as exactly 26 characters of
  Crockford's base32 alphabet in lowercase — digits and letters without `i`,
  `l`, `o` and `u` — the first character `0` to `7`. Those four letters are
  refused rather than read as digits, so one address never names two
  identifiers. Addresses are compared case-insensitively, as a receiver
  compares them.
- **An identifier is a capability**: whoever knows it can report on or answer
  that message. It is drawn at random for every message and never derived from
  a sequential or time-ordered ID — a monotonic ULID increments within a
  millisecond, so its neighbours are guessable. The application stage that
  generates them inherits this rule.
- **Delivery status notifications arrive with an empty sender** (`MAIL FROM:<>`).
  The receiver accepts them and nothing ever answers one, so no bounce of a
  bounce is ever generated.

### `route`: the verdict for one recipient

`mail-inbound route --recipient ADDRESS` prints the verdict a receiver gives at
`RCPT TO`, as JSON: `accept` with the target, destination, identifier and
handler (exit 0), or `reject` with one of four reasons (exit 2):

| Rejection | When | Expected answer at `RCPT TO` |
|-----------|------|------------------------------|
| `malformed-address` | not one printable `local@domain` of at most 254 characters, an address literal, a trailing dot | `501 5.1.3` |
| `relay-denied` | no target receives mail at that domain — including subdomains, the MX host and the MTA host | `554 5.7.1` |
| `unknown-recipient` | a domain this host receives at, but not one of its addresses — including another destination's form | `550 5.1.1` |
| `invalid-identifier` | the right prefix, but not a well-formed `base32-128` identifier | `550 5.1.1` |

The SMTP answers are what the receiver is expected to give; the receiver's stage
fixes them with its own tests.

```console
$ infrastructure/scripts/mail-inbound route --recipient support@tits.guru
{ …, "destination": "support", "target": "tits-guru", "verdict": "accept" }

$ infrastructure/scripts/mail-inbound route --recipient r-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru
{ …, "rejection": "unknown-recipient",
  "reason": "r-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru is a reply address at the bounce domain of tits-guru: …",
  "verdict": "reject" }
```

## The plan

`mail-inbound render-plan` prints the whole inbound plan as JSON: `public_smtp`,
the receiver's requirements, and per target its MX host, its three domains and
its destinations — the exact support addresses, the bounce and reply address
spaces (`local_part_prefix`, `identifier`), what each identifier names, and the
handler each destination will be given to, every one `planned`. It holds names,
addresses and closed vocabulary only: no command, path, secret or message.
Rendered twice, or from the same documents in another key order, it is byte for
byte the same.

## DNS

`mail-inbound render-dns --target tits-guru [--ipv4 ADDRESS]` prints the inbound
DNS plan as JSON. Its records:

```
tits.guru             MX 10 mx1.tits.guru
bounce.tx.tits.guru   MX 10 mx1.tits.guru
reply.tits.guru       MX 10 mx1.tits.guru
mx1.tits.guru         A     <the receiver's IPv4 address>
```

- **The address is never committed.** It is given with `--ipv4` and must be
  globally reachable: refused, with the range named, in every IANA
  special-purpose range that is not — `0.0.0.0/8`, `10.0.0.0/8`,
  `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`,
  `192.0.0.0/24`, the documentation ranges `192.0.2.0/24`, `198.51.100.0/24`
  and `203.0.113.0/24` (RFC 5737), `192.168.0.0/16`, the benchmarking range
  `198.18.0.0/15` — and in the deprecated 6to4 relay anycast `192.88.99.0/24`,
  multicast `224.0.0.0/4` and reserved `240.0.0.0/4`. Special-purpose ranges
  IANA marks globally reachable (AS112, AMT) are accepted. This is stricter
  than the check `mail-identity` applies to the address a host sends from,
  which is unchanged: a published MX address is a claim every sender acts on.
  Without `--ipv4` the plan marks the address `not-provided`.
- **No MX record is published before the receiver is installed, verified and
  activated.** A published MX is a public promise that a server accepts mail
  there: a sender that finds one queues and retries for days when nothing
  answers, so a reply to a dead MX fails silently for days instead of at once.
  The A record of `mx1.tits.guru` may go first — nothing is sent to it until an
  MX names it — and the plan says so per record (`before-mx`,
  `after-receiver-activation`).
- **No AAAA.** The receiver will listen on IPv4 only, like the gateway; an AAAA
  record would send IPv6 senders to an address nothing listens on.
- **The outbound identity is untouched.** No record for `mta1.tits.guru`, no PTR,
  no SPF, DKIM or DMARC: the plan names the MTA hostname only to say its records
  are unchanged, and the MX host may never be that name. The SPF policy
  (`v=spf1 ip4:213.199.41.241 -all`) has no `mx` mechanism, so publishing MX
  records changes no SPF result.
- **Nothing is published by the tooling.** Records are published by the operator
  at the DNS provider; no API is called.

## Receiver requirements

The receiver may not exist until it meets every one of these. The plan carries
the list (`receiver.requirements`), so the receiver's own verification is held
to each entry.

| Requirement | What it means |
|-------------|---------------|
| `recipient-allowlist` | Mail is accepted only for the plan's exact support addresses and well-formed bounce and reply addresses at its domains; everything else is refused at `RCPT TO`, before any data. |
| `postmaster-accepted` | `postmaster` is accepted case-insensitively at every domain the receiver serves, and the bare `<Postmaster>` too (RFC 5321 §4.5.1), all reaching the target's support mailbox; no limit refuses it more broadly than an attack in progress requires. |
| `relay-refused-at-rcpt` | A recipient at any other domain is refused at `RCPT TO`, for every client, loopback included: no trusted network makes it a relay. |
| `no-smtp-auth` | No SMTP AUTH is offered on the public listener. |
| `no-relay-no-forwarding` | Accepted mail is never relayed or forwarded off the host; it reaches only its own destination's handler. |
| `message-size-limit` | A reviewed maximum message size. |
| `recipient-limit` | A reviewed maximum number of recipients per message. |
| `connection-limits` | Reviewed limits on concurrent connections and connection rate per client. |
| `queue-limits` | A bounded queue size and queue lifetime. |
| `isolated-queue` | Its own configuration directory, queue and service, never the gateway's. |
| `no-outbound-submission` | No path from the receiver into the gateway's submission ports and no SMTP client of its own: it cannot send mail through production outbound delivery. |
| `no-message-content-in-logs` | Message bodies, headers and attachments never reach logs, workflow output or GitHub Actions. |
| `content-never-executed` | No delivery to a command; nothing in a message is ever run, evaluated or used as a path. |
| `untrusted-sender-fields` | `From`, `Reply-To`, `Return-Path` and attachments are untrusted data, never identity or authorization. |
| `no-automatic-replies` | Nothing answers an unverified address: no auto-reply, and no bounce generated for mail with an empty sender. |
| `null-sender-accepted` | `MAIL FROM:<>` is accepted, so delivery status notifications reach the bounce domain. |
| `malformed-and-duplicate-safe` | Malformed mail is set aside without stopping the handler, and a repeated message is processed once. |
| `disk-exhaustion-guard` | A free-space floor for the queue, and bounded storage for every handler. |
| `single-public-port-owner` | Port 25 is bound only by the receiver's own service, which the gateway's checks recognize exactly; 465 and 587 stay closed. |
| `no-shared-mail-state` | Staging capture, Mailpit, OpenDKIM and the outbound queues are never touched. |

## Coexistence with the gateway's public-port checks

Today three checks refuse anything listening on 25, 465 or 587 anywhere on the
host: `install-mail-gateway --verify` (and with it Prepare, Verify and
`verify-mail-gateway --read-only`), and `activate-mail-outbound`'s read-back of
the live routes. They stay exactly as strict.
**`verify-mail-gateway` is never weakened to allow port 25 in general**, and
neither is any other check.

The receiver needs exactly one exception, and the receiver's stage makes it
concrete and checkable:

- the receiver runs as its own service with its own configuration directory and
  queue, installed by its own installer and started only by its own guarded
  activation;
- a listener on port 25 passes the gateway's checks only when the inbound
  contract enables public SMTP, the receiver's activation is recorded on the
  host, and the listening process belongs to the receiver's own service unit —
  identified by the unit it runs in, never by a process name; anything else on
  25, or 25 on any other address, fails exactly as it does today, and 465 and
  587 stay forbidden to everything;
- that owner is defined once and shared by every check, never restated in each;
- the gateway's rendered `main.cf` and `master.cf` are pinned byte for byte, so
  the receiver does not register itself in the gateway's configuration — if the
  way Postfix runs a second instance required that, it is a deliberate, tested
  change of that pin;
- opening TCP 25 in the host's firewall is part of the receiver's guarded
  activation and its rollback, never a separate manual step.

## The sequence

1. **The inbound contract** — the reviewed contract, its validation, the inbound
   plan, the address verdicts and the DNS plan. *This change (8.4B.5.1).*
2. **An isolated receiver, proved locally** — the separate Postfix instance, its
   installer, verifier and status, rendered from the inbound plan and installed
   with public SMTP still disabled; every requirement above proved against it
   on the host without a public listener.
3. **Guarded activation of public SMTP** — the `enabled` state, the port-25
   owner in the gateway's checks, the firewall, and a rollback that closes the
   port again. *8.4B.5.2 covers steps 2 and 3.*
4. **MX records** — only after step 3 verifies: the A record of `mx1.tits.guru`
   (it may go first), then the three MX records from `render-dns`, verified
   through public resolvers.
5. **An external test message to `support@tits.guru`** — sent from an outside
   mailbox, received, recorded, and nothing else accepted.
6. **Bounce reception and correlation** — the Return-Path moves to
   `b-<identifier>@bounce.tx.tits.guru`, delivery status notifications are
   received and matched to the message they report on. Moving the envelope
   sender to the bounce domain brings its own DNS: that domain needs an SPF
   record of its own, and with `aspf=s` SPF no longer aligns with the `From`
   domain, so DMARC then rests on DKIM (`d=tits.guru`, `adkim=s`) alone. *8.4B.5.3.*
7. **Replies** — `Reply-To` addresses on the reply domain, replies received and
   matched to their conversation, and the support mailbox. *8.4B.5.4.*
8. **Storage and processing** — the handlers hand what they received to the
   application's storage and processing, idempotently. *8.4B.5.3 and 8.4B.5.4.*
9. **Security and recovery** — the real-host acceptance from outside (relay,
   AUTH and limit probes, the external messages), and recovery on a replacement
   host with an inbound fence like the outbound one. *8.4B.5.5.*

| Slice | What | State |
|-------|------|-------|
| 8.4B.5.1 | Inbound contract and DNS plan | **Implemented**, nothing installed |
| 8.4B.5.2 | Isolated inbound SMTP receiver and guarded activation | Planned |
| 8.4B.5.3 | Bounce reception and correlation | Planned |
| 8.4B.5.4 | Reply routing and the support mailbox | Planned |
| 8.4B.5.5 | Real-host acceptance and recovery proof | Planned |

Inbound mail is not working until a message from outside has actually been
received.

## What the operator prepares for the receiver's stage

- **The receiver's public IPv4 address** — on today's shared host, the address
  outbound mail already leaves from, unless the receiver lives elsewhere — and
  confirmation that the provider lets inbound TCP 25 reach it.
- **The firewall**: where and how TCP 25 is opened, so the activation and its
  rollback can do it.
- **The limits**: maximum message size, recipients per message, connections per
  client, queue lifetime and the disk floor.
- **Which mailboxes support is**: `postmaster` and `support` today; whether
  `abuse` (RFC 2142) joins them, and how the receiver qualifies the bare
  `<Postmaster>`.
- **Where support mail goes and who reads it**, and how long received mail is
  kept.
- **TLS for `mx1.tits.guru`**: how its certificate is issued and renewed.
- **DNS access** to publish the A record and then the MX records, by hand, in
  that order.
- **An outside mailbox** for the external test message.

## What this does not do

It installs no receiver and changes no Postfix, OpenDKIM, firewall, port, DNS
record, routing policy, `mail-outbound.json`, lifecycle, environment file,
application setting or GitHub secret. It adds no workflow and no host-side
command; `mail-inbound` reads files and prints JSON. It sends and receives no
mail and processes no message.
