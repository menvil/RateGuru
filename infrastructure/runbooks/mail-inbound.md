# Mail inbound

How a production target receives mail from the Internet: support mail,
delivery status notifications for the mail it sent, and people replying to it.

**One Postfix — independent listeners — one shared queue — a separate inbound
store — guarded activation of public SMTP.** The host's one Postfix, the mail
gateway, sends and receives. Inbound mail is a set of listeners and a delivery
route of that same Postfix, each with rules of its own, and its accepted mail is
stored in a store of its own. Public TCP 25 opens only through the guarded
activation, and closes again through its rollback.

The receiver is implemented in the repository. **On a host, nothing listens on
port 25 and no MX record is published until an operator runs Activate
tits.guru inbound SMTP and then publishes DNS by hand** — merging this changes
no host. On the production host that happened on 2026-10-12, and inbound SMTP
is production-accepted: see [Production acceptance,
2026-10-12](#production-acceptance-2026-10-12).

Outbound mail has its own runbooks — [`mail-routing.md`](mail-routing.md),
[`mail-gateway.md`](mail-gateway.md), [`mail-identity.md`](mail-identity.md),
[`mail-signing.md`](mail-signing.md) and
[`mail-outbound-activation.md`](mail-outbound-activation.md) — and its routes,
identity and signing are not changed by any of this.

## Status

| What | State |
|------|-------|
| Inbound contract `infrastructure/config/mail-inbound.json` | **Implemented**, reviewed — schema 2 |
| `infrastructure/scripts/mail-inbound` (`validate`, `render-plan`, `render-receiver`, `render-dns`, `route`) | **Implemented**; repository tooling, read-only |
| Inbound listeners and delivery, rendered by `install-mail-gateway` | **Installed on the production host** by Activate tits.guru inbound SMTP, 2026-10-12; installed on a host only by that activation |
| Inbound store `install-mail-inbound` | **Installed on the production host** by the same activation |
| Guarded activation `activate-mail-inbound` and its three workflows | **Run on the production host** 2026-10-12 (Activate, then Verify); never run automatically |
| Public inbound SMTP — committed request | **`enabled`** — a request, never the state of a host |
| Public inbound SMTP — real host | **Enabled** on `213.199.41.241:25`, `enabled-verified`, and reached from outside |
| MX records for `tits.guru`, `bounce.tx.tits.guru`, `reply.tits.guru` | **Published** — `MX 10 mx1.tits.guru`, after public SMTP was active and verified, confirmed by `1.1.1.1` and `8.8.8.8` |
| `mx1.tits.guru` A record | **Published** — `213.199.41.241`, confirmed by `1.1.1.1` and `8.8.8.8`; the receiver's address is never committed to the contract |
| TLS certificate for `mx1.tits.guru` | **Certbot's, on the host**, issued over HTTP-01 by the operator; copied into place and kept current on every renewal by Install tits.guru inbound mail TLS (`install-mail-inbound-tls`) — **installed on the production host** 2026-10-12 — see [below](#tls-for-mx1titsguru) |
| Support, bounce and reply handling | **Stored, not processed** — accepted mail is kept in the inbound store; every handler is `planned`, deferred until after the first production launch |
| Inbound mail health check, every six hours | **Implemented in the repository, not installed and not enabled**: no monitor access on the host, no monitor Environment, the schedule off. Its setup moves into RateGuru's future unified monitoring — see [the automatic monitor](#the-automatic-monitor) |
| 8.4B.5.2 — inbound SMTP and delivery into the store | **Production-accepted 2026-10-12** — see [Production acceptance](#production-acceptance-2026-10-12) |
| Envelope sender (Return-Path) of mail sent today | `noreply@tits.guru` — the bounce domain is not an envelope sender yet |
| `tits-guru` outbound mail | **Production-accepted** 2026-10-08, and untouched by any of this — see [`mail-outbound-activation.md`](mail-outbound-activation.md) |

The envelope sender of every message sent today is `noreply@tits.guru`. Nothing
here claims that sent mail already names the bounce domain: moving the
Return-Path there is the bounce slice's change (see [the sequence](#the-sequence)).

## Production acceptance, 2026-10-12

8.4B.5.2 rests on these points: public SMTP that is safe, DNS, TLS, and a
real message received and stored. Each was shown on the production host on
2026-10-12.

1. **TLS.** Install tits.guru inbound mail TLS, run
   [`38260303027`](https://github.com/menvil/RateGuru/actions/runs/38260303027),
   SUCCESS:
   - Certbot's `mx1.tits.guru` pair copied into
     `/etc/rateguru-mail-inbound/tls`;
   - the deploy hook installed;
   - `--verify` PASS.
2. **Activation.** Activate tits.guru inbound SMTP, run
   [`38260744608`](https://github.com/menvil/RateGuru/actions/runs/38260744608),
   SUCCESS. State `enabled-verified`: public SMTP on `213.199.41.241`, TCP 25
   opened in `ufw`.
3. **Independent verification.** Verify tits.guru inbound SMTP, run
   [`38261781978`](https://github.com/menvil/RateGuru/actions/runs/38261781978),
   SUCCESS. State `enabled-verified`, with no inbound mail waiting in the
   shared queue.
4. **Reachable from outside.** From an outside machine, a TCP connection to
   `213.199.41.241:25` succeeded.
5. **Public DNS.** Both public resolvers, `1.1.1.1` and `8.8.8.8`, answer:
   ```
   mx1.tits.guru          A     213.199.41.241
   tits.guru              MX 10 mx1.tits.guru
   bounce.tx.tits.guru    MX 10 mx1.tits.guru
   reply.tits.guru        MX 10 mx1.tits.guru
   ```
6. **A real message.** A real message from an outside mailbox to
   `support@tits.guru` was received and stored in
   `/var/lib/rateguru-mail-inbound/store/targets/tits-guru/support/new/`:
   one message file, owned by `rateguru-mail-inbound`, mode `0600`.

**Inbound SMTP and delivery into the Maildir work on the real host.**

What this acceptance does **not** claim:

- **Nothing processes received mail.** No Laravel model, handler or inbox
  reads the store. Support, bounce and reply handling are 8.4B.5.3 and
  8.4B.5.4, deferred until after the first production launch.
- **Nothing watches inbound mail unattended.** The automatic monitor is not
  installed and not enabled; see [the automatic
  monitor](#the-automatic-monitor). That no longer blocks this acceptance, and
  no check in the code was weakened for it.
- **Nothing was probed from outside.** The relay, AUTH and limit probes from
  outside, mail at the bounce and reply destinations, and recovery on a
  replacement host are 8.4B.5.5's extended trials, deferred.

Open before GO LIVE (ROADMAP 8.6):

- **The two infrastructure checks.** Verify production infrastructure and
  Verify staging infrastructure have not run since the activation.
- **Mail already accepted.** It lives on this host alone: the store volume
  and the shared Postfix queue. **No backup carries either of them** — the
  backup and its recovery material hold neither — and nothing here claims
  otherwise. Before GO LIVE, either they are protected and that is proved,
  or the loss boundary is stated and accepted.

## One Postfix, listeners of their own

| Listener | What for | Who may connect |
|----------|----------|-----------------|
| `127.0.0.1:2525` | staging → Mailpit | localhost only |
| `127.0.0.1:2526` | production → direct SMTP, signed by OpenDKIM | localhost only |
| `127.0.0.1:2580` | inbound, the activation's own proof | localhost only |
| `<public IPv4>:25` | inbound mail from the Internet | anyone — only after Activate |
| `465`, `587` | — | never |

One service (`postfix@-.service`), one configuration (`/etc/postfix`), one queue.
`install-mail-gateway` is the only writer of `/etc/postfix/main.cf`,
`/etc/postfix/master.cf` and the service; nothing else renders a Postfix
setting. What the inbound listeners accept comes from `mail-inbound
render-receiver`, and the gateway spells it in Postfix.

```
Internet ──SMTP :25──▶ ┐                         RCPT TO judged by the inbound table
          127.0.0.1:2580 ┤ inbound listeners ──▶ queue ──▶ virtual(8) ──▶ /var/lib/rateguru-mail-inbound/store
                         ┘                                                   targets/tits-guru/{support,bounce,reply}/
                                                                             host/postmaster/
applications ──▶ 127.0.0.1:2525 ──▶ queue ──▶ rateguru-capture-staging-main ──▶ Mailpit
             ──▶ 127.0.0.1:2526 ──▶ OpenDKIM ──▶ queue ──▶ rateguru-outbound-tits-guru ──▶ recipient MX
```

**Every rule of the inbound listeners is their own** — per-service overrides in
`master.cf`, so no submission listener changes:

- recipients judged at `RCPT TO` against the rendered table
  (`reject_unlisted_recipient`), then the store gate asked whether the store
  can take mail now (see [the reliability
  contract](#what-250-means-the-reliability-contract)), and relaying refused
  (`reject_unauth_destination`) for every client — loopback included: no
  `permit_mynetworks`, no trusted network;
- no SMTP AUTH, no ETRN, no milter, no content filter;
- the reviewed limits: 10 MB per message, one recipient, five concurrent
  sessions, 30 new sessions and 60 messages a minute per client, 20 sessions on
  the public listener in all, and a queue floor of their own;
- their own cleanup, which signs, checks and routes nothing, and their own
  address rewriting, which qualifies a bare `<Postmaster>` as the host
  postmaster (`postmaster@rateguru-mail-inbound.invalid`) — Postfix's own
  `myorigin` stays `mta1.tits.guru`;
- the name they greet with is `mx1.tits.guru`; Postfix's own name, its banner on
  the submission listeners, its HELO and its PTR stay `mta1.tits.guru`;
- STARTTLS on the public listener only, from the operator's certificate.

**The one change main.cf takes** is the delivery: `virtual_transport =
virtual` and `virtual_mailbox_*` for exactly the plan's domains. That cannot
reach the submission listeners: each of them names its own content filter,
which decides where its mail goes whatever the recipient's domain, and none of
them judges a recipient against the inbound table
(`smtpd_reject_unlisted_recipient=no`). So mail an application sends to
`support@tits.guru` still leaves through `rateguru-outbound-tits-guru` to the
domain's MX — like mail to any other domain — and never lands in the store.

**Nothing an inbound listener accepts can leave the host.** The default, relay
and local transports stay `error(8)`, and there is no generic SMTP client: not
even a bounce to a forged sender has a route off the host, so the receiver
cannot backscatter.

All of this is proved on a real Postfix: see [Tested on a real
Postfix](#tested-on-a-real-postfix).

## One shared queue, a separate store

There is one queue. Inbound and outbound mail are deliberately **not**
isolated from each other in the queue — one Postfix is simpler to run than two —
so the inbound listeners are kept from crowding it:

- **a queue floor of their own**: the inbound listeners refuse new mail,
  temporarily (`452`), while the queue's file system has less than
  `host_reserve_bytes` (2 GiB) free; the submission listeners keep Postfix's own
  floor, so outbound mail still has room;
- **flow limits** per client — sessions, new sessions a minute, messages a
  minute, one recipient — and 20 public sessions in all;
- **queue health**: Verify and the health check count accepted inbound mail
  still in the queue — by count and age only.

`virtual(8)` delivers accepted mail into **the inbound store**, a fixed-size
volume of its own (1 GiB) that holds nothing but the Maildirs — never the queue.
The store cannot grow past its volume, and cannot fill the host's file system;
it is mounted `nodev,nosuid,noexec`. `queue_minfree` guards the queue's file
system, never the store: the store is guarded by its volume, its gate and its
reserve check.

## What `250` means: the reliability contract

**`250` means the message is in Postfix's queue on disk — not that it is in the
Maildir.** Postfix answers `250` once the message is safely queued; `virtual(8)`
then moves it into the store, normally within a second. Between those two
moments the message is in the shared queue, and the guarantees are these:

1. **The store is checked before any `250`.** At `RCPT TO` every inbound
   listener asks the **store gate** — a Postfix policy service
   (`check_policy_service`, run by `spawn(8)` as `nobody`, rendered by the
   gateway into `/etc/postfix/rateguru-inbound-store-gate`) — whether the store
   can take mail right now. While the store volume is not mounted, is mounted
   read-only, or has less free space than its reserve (`storage_reserve_bytes`,
   100 MB) or fewer than 1024 free inodes, the answer is a temporary refusal —
   `451 4.3.0` (unavailable) or `452 4.3.1` (insufficient storage) — so the
   sender keeps the message and retries, and nothing is accepted that could
   only wait in the queue. A gate that cannot run is answered `451` by Postfix
   itself: it never fails open. The gate reads no message and no attribute of
   the request, logs nothing, and the submission listeners never ask it, so
   2525 and 2526 accept whatever the store does.
2. **What the gate cannot see waits in the queue.** A store that fails after
   the gate said yes — between `RCPT TO` and delivery, or through a fault the
   gate does not check, such as a mailbox directory with the wrong owner — makes
   `virtual(8)` defer the message. It stays in the queue and is retried; no
   false success is given and nothing is lost yet. While the volume is not
   mounted, its mount point belongs to root, so nothing is stored beside it.
3. **The queue keeps a message for `maximal_queue_lifetime`** — Postfix's
   default, five days, for inbound and outbound mail alike. A message the store
   cannot take for longer is given up, and since a bounce has no route off the
   host, **it is then lost**. That is the boundary of the guarantee: accepted
   mail survives any store outage shorter than the queue lifetime, and no
   longer.
4. **The risk is detected long before that.** The health check (below) counts
   every accepted inbound message still in the queue and fails as soon as one
   waits; a message waiting for more than a day is reported **AT RISK OF LOSS**
   with the count, the oldest one's age and how long Postfix still keeps it —
   four days in which to restore the store.

**Why the queue lifetime is not made unlimited.** `maximal_queue_lifetime` is
one setting of the whole Postfix — there is no per-transport lifetime — so
`0` (or a very long one) would keep undeliverable *outbound* mail in the shared
queue for ever too, and a long outage of a recipient domain would then fill the
queue the outbound listeners need. It stays Postfix's default; the outbound
routes are not changed.

**How the queue scan tells inbound from outbound.** Postfix records in each
message's envelope the address and port of the listener that accepted it
(`server_address`, `server_port`), and a submission listener's message carries
its content filter as well. The scan reads those envelope records with `postcat
-e` — never a header or a body — and counts a message as inbound only when the
inbound loopback listener, or port 25 on any address, accepted it and it names
no content filter. So an application's message to `support@tits.guru`, deferred
by its own route, is never counted, whatever its recipient. A recipient at an
inbound domain only chooses which envelopes are read at all. A message counts
as **waiting** once it is deferred or held, or has been in the queue for
15 minutes in any state; Postfix's own notices, which no listener accepted,
are never counted.

## No single address may stop Postfix

The public listener is bound to exactly one IPv4 address. Postfix binds every
listener when it starts, and one bind that fails stops all of them — were that
address missing at boot, the outbound listeners would be down with it. So the
store installer sets `net.ipv4.ip_nonlocal_bind = 1`
(`/etc/sysctl.d/60-rateguru-mail-inbound.conf`): Postfix always starts, the
public listener simply receives nothing until the address is back, and Verify
reports it. Tested on a real host reboot without the address.

## The contract

`infrastructure/config/mail-inbound.json`, schema 2, as committed:

```json
{
  "schema_version": 2,
  "receiver": {
    "public_smtp": "enabled",
    "loopback_port": 2580,
    "limits": {
      "message_size_bytes": 10485760,
      "recipients_per_message": 1,
      "connections_per_client": 5,
      "connection_rate_per_client": 30,
      "message_rate_per_client": 60,
      "concurrent_sessions": 20,
      "storage_bytes": 1073741824,
      "storage_reserve_bytes": 104857600,
      "host_reserve_bytes": 2147483648
    }
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
| Whether public SMTP is requested, the inbound loopback port and limits; per target its MX host, support local parts, and bounce and reply address forms | `config/mail-inbound.json` |
| Whether public SMTP is actually open on a host | the gateway's own record, `/var/lib/rateguru-mail-gateway/applied-inbound.json` — never the repository |

So `tits.guru`, `bounce.tx.tits.guru` and `reply.tits.guru` appear nowhere in the
inbound contract: change a domain in the routing policy and the inbound plan
follows. One file holds every brand's inbound policy; there is no file per brand.

**`public_smtp` is a request.** `enabled` says that the reviewed tooling may
open public SMTP when an operator activates it; `disabled` says it may not, and
then Activate refuses before it changes anything. Neither value opens or closes
anything on a host by itself: see [states and
transitions](#the-gateway-renders-it-states-and-transitions).

**`mail-inbound validate` refuses**, each with its own reason:

- a schema other than 2, more than one document, a duplicated key, a control
  character, a symlink, or any property it does not know — at every level;
- a credential-like property name, however deep;
- `public_smtp` other than `disabled` or `enabled`;
- a loopback port outside 1024–65535, or one the mail routing plan already uses
  for a listener or a capture route;
- a limit missing, unknown, not an integer or outside its reviewed range; fewer
  concurrent sessions than one client may open; a store reserve smaller than
  two messages, or larger than a quarter of the store;
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

| Destination | Domain | Accepts | The identifier names | Stored in | Future handler |
|-------------|--------|---------|----------------------|-----------|----------------|
| support | `tits.guru` | exactly `support@tits.guru` and `postmaster@tits.guru` — and `postmaster@` the bounce and reply domains | — | `targets/tits-guru/support/` | `support-mailbox` |
| bounce | `bounce.tx.tits.guru` | `b-<identifier>@bounce.tx.tits.guru` | the outbound message | `targets/tits-guru/bounce/` | `bounce-correlation` |
| reply | `reply.tits.guru` | `r-<identifier>@reply.tits.guru` | the conversation | `targets/tits-guru/reply/` | `reply-routing` |
| host-postmaster | — | the bare `<Postmaster>`, which names no domain | — | `host/postmaster/` | `host-postmaster` |

- **Support is exact.** No catch-all, no wildcard, no subaddress
  (`support+x@` is not support), and `noreply@tits.guru` is never a mailbox.
- **Postmaster is required.** RFC 5321 §4.5.1: a server that delivers mail
  MUST accept the reserved mailbox `postmaster`, case-insensitively, at every
  domain it provides mail service for, and the bare `RCPT TO:<Postmaster>`
  with no domain as well. So `postmaster` is an entry of `support.local_parts`
  that `validate` requires, and the plan accepts `postmaster@` each of the
  target's three domains into the same `support-mailbox` handler.
  `postmaster+x@`, `post.master@`, `hostmaster@` and `abuse@` are not it.
- **The bare `<Postmaster>` is the host's.** It names no domain, so on a host
  that receives for several brands it belongs to none of them: the inbound
  listeners' own address rewriting qualifies it to the reserved domain
  `rateguru-mail-inbound.invalid`, and it is stored in `host/postmaster/`, a
  mailbox of its own, never a brand's. Nothing else is accepted at that domain,
  and `postmaster@mta1.tits.guru` is not received at all: the MTA hostname is
  never an inbound domain.
- **Bounce and reply never mix.** Each has its own domain and its own prefix,
  and the two prefixes must differ.
- **The identifier, `base32-128`**: 128 bits as exactly 26 characters of
  Crockford's base32 alphabet in lowercase — digits and letters without `i`,
  `l`, `o` and `u` — the first character `0` to `7`. Addresses are compared
  case-insensitively, as a receiver compares them.
- **An identifier is a capability**: whoever knows it can report on or answer
  that message. It is drawn at random for every message and never derived from
  a sequential or time-ordered ID.
- **Delivery status notifications arrive with an empty sender** (`MAIL FROM:<>`).
  They are accepted, and nothing ever answers one.

### `route`: the verdict for one recipient

`mail-inbound route --recipient ADDRESS` prints the verdict the inbound
listeners give at `RCPT TO`, as JSON: `accept` with the target, destination,
identifier and handler (exit 0), or `reject` with one of four reasons (exit 2):

| Rejection | When | Answer at `RCPT TO` |
|-----------|------|---------------------|
| `malformed-address` | not one printable `local@domain` of at most 254 characters (the bare `Postmaster` aside), an address literal, a trailing dot | refused (`5xx`) |
| `relay-denied` | no target receives mail at that domain — including subdomains, the MX host and the MTA host | `554 5.7.1` |
| `unknown-recipient` | a domain this host receives at, but not one of its addresses — including another destination's form | `550 5.1.1` |
| `invalid-identifier` | the right prefix, but not a well-formed `base32-128` identifier | `550 5.1.1` |

The activation does not trust this table: it sends a corpus of addresses to the
live inbound listener and requires every answer to agree with `route`'s
verdict, and the real-Postfix scenario does the same on both inbound
listeners.

## The plan and the receiver render

`mail-inbound render-plan` prints the whole inbound plan as JSON: the receiver
(`public_smtp`, its loopback port, its limits, the host postmaster and its
requirements), and per target its MX host, its three domains and its
destinations. It holds names, addresses, numbers and closed vocabulary only: no
command, path, secret or message. Rendered twice, or from the same documents in
another key order, it is byte for byte the same.

`mail-inbound render-receiver` prints exactly what the inbound listeners
enforce, from that plan and nothing else: every allowed recipient as an
anchored, case-insensitive POSIX extended regular expression with the Maildir
it is stored in, the domains served, the MX host names the certificate must
cover, the loopback port and the limits. `install-mail-gateway` renders it into
`/etc/postfix/rateguru-inbound-recipients.regexp` and the inbound overrides, and
restates no rule — **there is one implementation of the address rules**.

## DNS

`mail-inbound render-dns --target tits-guru [--ipv4 ADDRESS]` prints the inbound
DNS plan as JSON. Its records:

```
tits.guru             MX 10 mx1.tits.guru
bounce.tx.tits.guru   MX 10 mx1.tits.guru
reply.tits.guru       MX 10 mx1.tits.guru
mx1.tits.guru         A     <the host's public IPv4 address>
```

- **The address is never committed.** It is given with `--ipv4` and must be
  globally reachable: refused, with the range named, in every IANA
  special-purpose range that is not — `0.0.0.0/8`, `10.0.0.0/8`,
  `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`,
  `192.0.0.0/24`, the documentation ranges `192.0.2.0/24`, `198.51.100.0/24`
  and `203.0.113.0/24` (RFC 5737), `192.168.0.0/16`, the benchmarking range
  `198.18.0.0/15` — and in the deprecated 6to4 relay anycast `192.88.99.0/24`,
  multicast `224.0.0.0/4` and reserved `240.0.0.0/4`. Special-purpose ranges
  IANA marks globally reachable (AS112, AMT) are accepted. The activation takes
  the address from the host's own route to the Internet and judges it with
  this same command.
- **No MX record is published before the receiver is installed, verified and
  activated.** A published MX is a public promise that a server accepts mail
  there: a sender that finds one queues and retries for days when nothing
  answers. The A record of `mx1.tits.guru` may go first — nothing is sent to it
  until an MX names it.
- **No AAAA.** The public listener is IPv4 only, on one address; an AAAA record
  would send IPv6 senders to an address nothing listens on.
- **The outbound identity is untouched.** No record for `mta1.tits.guru`, no PTR,
  no SPF, DKIM or DMARC change: `mx1.tits.guru` is an A record of the same
  address, and the MX host may never be the MTA hostname. The SPF policy
  (`v=spf1 ip4:213.199.41.241 -all`) has no `mx` mechanism, so publishing MX
  records changes no SPF result.
- **Nothing is published by the tooling.** Records are published by the operator
  at the DNS provider; no API is called, and no workflow publishes DNS.

## Receiver requirements

The receiver is held to every one of these. The plan carries the list
(`receiver.requirements`), and `install-mail-gateway --verify` reports each by
name against the configuration Postfix actually runs (`postconf -P`, `-M`,
`-h`), never against what was meant to be written. The real-Postfix scenario
runs the same read-back against the real `postconf`.

| Requirement | What it means |
|-------------|---------------|
| `recipient-allowlist` | Mail is accepted only for the plan's exact support addresses and well-formed bounce and reply addresses at its domains (the rendered table, `reject_unlisted_recipient`); everything else is refused at `RCPT TO`, before any data. |
| `postmaster-accepted` | `postmaster` is accepted case-insensitively at every domain received for, and the bare `<Postmaster>` too (RFC 5321 §4.5.1) — into the target's support mailbox, the bare one, through the inbound address rewriting, into the host postmaster's. |
| `relay-refused-at-rcpt` | A recipient at any other domain is refused at `RCPT TO` (`reject_unauth_destination`), for every client, loopback included: the inbound listeners trust no network. |
| `no-smtp-auth` | No SMTP AUTH is offered on an inbound listener. |
| `no-relay-no-forwarding` | No relay host, relay domain, transport map or address rewrite to another address: accepted mail reaches only its own Maildir. |
| `message-size-limit` | 10 MB on the inbound listeners and their cleanup, announced in `SIZE`, and no stored message larger — `virtual(8)` carries the same limit itself, since it refuses to start while the global one exceeds its mailbox limit. |
| `recipient-limit` | One recipient per message; a second is refused, temporarily. |
| `connection-limits` | Five concurrent and 30 new sessions a minute per client, no client exempt, 20 public sessions in all. |
| `queue-limits` | The inbound listeners refuse new mail, temporarily, while the shared queue's file system is below their reserve; the submission listeners keep their own. |
| `shared-queue-guard` | 60 messages a minute per client, so no sender fills the shared queue; Verify reports inbound mail waiting in it. |
| `no-outbound-submission` | No content filter and no milter on an inbound listener, their own cleanup, and no SMTP client but the plan's routes: inbound mail never reaches an outbound route. |
| `no-message-content-in-logs` | Nothing of a message is matched or logged (no header or body checks), no TLS session details, and no workflow prints a message. |
| `content-never-executed` | No delivery to a command, a local mailbox or LMTP — the one command master.cf runs is the store gate, which is never given a message; the store is mounted `noexec`, `nosuid`, `nodev`. |
| `untrusted-sender-fields` | The sender never authorizes or routes: no sender restriction on an inbound listener, and nothing reads `From`, `Reply-To` or attachments. |
| `no-automatic-replies` | No auto-reply, and a bounce to any sender — a forged one included — has no route off the host: the default and relay transports are `error(8)`. |
| `null-sender-accepted` | `MAIL FROM:<>` is accepted, so delivery status notifications reach the bounce domain. |
| `malformed-and-duplicate-safe` | Every message is a Maildir file of its own, never overwritten or merged. |
| `disk-exhaustion-guard` | The store is a fixed-size volume of its own, never the queue's file system; below its reserve Verify fails. |
| `store-checked-before-acceptance` | At `RCPT TO`, before any `250`, the inbound listeners ask the store gate: a store not mounted, mounted read-only, or below its reserve of space or inodes is answered `451`/`452`, temporarily. The gate runs as `nobody`, with exactly the store, its reserve and the kernel's mount table. |
| `single-public-port-owner` | The installed `master.cf` names no public listener while disabled, and exactly the recorded one while enabled; on port 25 only the host's own Postfix may listen. |
| `outbound-unaffected` | Every submission listener, route, signer, the hostname and the recorded policy are exactly what they are without inbound mail: no submission listener takes an inbound setting. |

## The gateway renders it: states and transitions

The host's inbound state is the gateway's own record,
`/var/lib/rateguru-mail-gateway/applied-inbound.json`:

| State | What Postfix runs |
|-------|-------------------|
| absent | exactly what it ran before inbound mail existed — byte for byte |
| disabled | the inbound loopback listener, its cleanup and rewriting, `virtual(8)` delivery to the store; no public listener |
| enabled on one address | all of that, and the public listener on that address, port 25 |

It moves only by one of three transitions, each with a **one-use
authorization** `activate-mail-inbound` writes after its own proof
(`/var/lib/rateguru-mail-gateway/inbound-transition-authorization.json`, root
`0600`, short-lived, with a nonce recorded once used) and `install-mail-gateway
--apply` consumes:

- `install` — absent to disabled;
- `enable` — disabled to enabled, on the address the activation proved;
- `disable` — enabled to disabled.

There is no way back to absent: mail an inbound listener accepted may still be
waiting in the queue, and only `virtual(8)` can deliver it.

So:

- a merge of `"enabled"` opens nothing: the host stays as it records;
- an ordinary `install-mail-gateway --apply` — Prepare, bootstrap, repair —
  keeps the recorded state, and installs, opens or closes nothing;
- **a bundle that no longer requests public SMTP refuses to touch a host where
  it is open**, rather than closing it behind the operator's back — only the
  rollback closes it;
- an authorization that is malformed, expired, reused, for another state or
  address, or not root's `0600` is never honoured, and changes nothing;
- a recorded state that cannot be trusted is refused, never guessed around.

One `install-mail-gateway --apply` runs at a time: it holds the gateway's state
directory locked for its whole run, and a second one refuses at once, so two
runs never both honour one authorization.

Every transition is a reload of the running Postfix (`systemctl reload
postfix@-.service`), never a stop or a restart: the submission listeners keep
taking mail throughout. The gateway's transaction backs up every file it
replaces and, on any failure, restores them with the previous record and
reloads.

## The store: `install-mail-inbound`

```bash
# Read-only: may --apply proceed, and what would it change?
sudo infrastructure/scripts/install-mail-inbound --check

# Create or converge the store.
sudo infrastructure/scripts/install-mail-inbound --apply

# Read-only: the store, how full it is, the certificate.
sudo infrastructure/scripts/install-mail-inbound --verify

# Read-only: can the installed certificate serve every MX host name?
sudo infrastructure/scripts/install-mail-inbound --tls-check
```

It owns what `virtual(8)` delivers into, and never a Postfix file:

| Path | What | Owner, mode |
|------|------|-------------|
| account `rateguru-mail-inbound` | a system account with no login; every stored message belongs to it | — |
| `/var/lib/rateguru-mail-inbound/store.img` | a fixed-size ext4 image (`storage_bytes`, 1 GiB), label `rgmailin` | `root:root 0600` |
| `/etc/systemd/system/var-lib-rateguru\x2dmail\x2dinbound-store.mount` | mounts it `loop,nodev,nosuid,noexec`, ordered before Postfix — never a dependency of it | `root:root 0644` |
| `/var/lib/rateguru-mail-inbound/store/` | the mounted volume: `targets/<target>/<destination>/`, `host/postmaster/` | `rateguru-mail-inbound 0700` |
| `/etc/sysctl.d/60-rateguru-mail-inbound.conf` | `net.ipv4.ip_nonlocal_bind = 1` — see [above](#no-single-address-may-stop-postfix) | `root:root 0644` |

It refuses an account of that name it did not create, and creating the store
without its volume and the host reserve free. `--verify` reports how many
messages each mailbox holds and how full the volume is — counts only, never a
byte of a message — and fails below the reserve.

To read stored mail on the host, as root — never into a log or a workflow:

```bash
sudo find /var/lib/rateguru-mail-inbound/store/targets/tits-guru/support/new -type f | wc -l
```

## Port 25 and every check: one judge

Every check that refuses a public SMTP listener — `install-mail-gateway
--verify` (and with it Prepare, `verify-mail-gateway --read-only`, Verify staging
and production infrastructure), `activate-mail-outbound`'s read-back,
`status-mail-gateway` and the inbound activation — asks one library,
`infrastructure/scripts/public-smtp-port`:

- **465 and 587 are refused to everything**, always;
- **25 is refused to everything**, unless all of these hold at once: the gateway
  is marked installed; its recorded inbound state says enabled on one address;
  the trusted bundle's contract requests enabled; the listener is on **exactly**
  that address; **every process holding the socket runs in
  `postfix@-.service`'s own cgroup** — the unit, never a process name like
  `master`; and the installed `master.cf` names exactly that one listener off
  loopback;
- a listener on 25 on another address, on every address or on IPv6, a second
  owner, a second public listener in `master.cf`, an unreadable socket table, a
  corrupt or missing record, or a contract that no longer requests it — each
  fails, by name.

**`verify-mail-gateway` is never weakened to allow port 25 in general**, and
neither is any other check: while public SMTP is disabled, every check refuses
25 exactly as before. The submission listeners are still proved loopback-only.

## The firewall

Opening TCP 25 is part of the activation and its rollback, never a separate
manual step on the host. `activate-mail-inbound` works with the firewall the
host actually has:

| Host firewall | What happens |
|---------------|--------------|
| **ufw, active** | One rule of RateGuru's own — `allow in proto tcp to <address> port 25`, comment `rateguru-mail-inbound` — added on activation and removed, alone, on rollback. ufw keeps it across a reboot. A ufw rule RateGuru did not write that already allows 25 refuses the activation: RateGuru's rule must be the only way in, so that its removal really closes the port. |
| **none** — ufw not active, and nothing in `nft list ruleset` or `iptables-save` drops or rejects | Nothing to change: TCP 25 is reachable while Postfix listens on it and closed when it does not, after a reboot too, since the listener exists only while the record says enabled. |
| **anything else** — rules that filter inbound traffic and are not ufw's, or a packet filter that cannot be read | **Refused**, before anything changes: RateGuru never edits a firewall it does not manage. |

SSH, HTTP, HTTPS, outbound traffic and every other rule are never touched, and
465, 587 and IPv6 are never opened. **The provider's firewall outside the
host** — for Contabo, a firewall configured in its customer control panel — is
invisible from the host: the activation prints that it is the operator's to
check and never claims it.

## TLS for `mx1.tits.guru`

The public listener offers STARTTLS (`may`, TLS 1.2 or later) with a **real
certificate for every MX host name** — `mx1.tits.guru` — read from the host
only:

```
/etc/rateguru-mail-inbound/tls/fullchain.pem   root:root 0644, a regular file
/etc/rateguru-mail-inbound/tls/privkey.pem     root:root 0600, a regular file
```

Nothing is generated or committed: no key in the repository or in any log, no
permanent self-signed certificate, and never the website's certificate. Public
SMTP is **enabled only with a certificate that** chains to a trusted
certificate authority (the system trust store), covers every MX host name, does
not expire within 14 days, and matches its key. `install-mail-inbound
--tls-check` says which of these fails, and never prints the certificate or the
key. The outbound path's TLS and DKIM are not touched.

### Where it comes from: Certbot, over HTTP-01

The certificate is the one **Certbot already holds on the host** for
`mx1.tits.guru`. The operator issues it once, by hand, through the HTTP-01
challenge, with the webroot the host's existing Nginx already serves on port
80:

```bash
sudo certbot certonly --webroot --webroot-path /var/www/html \
  --cert-name mx1.tits.guru -d mx1.tits.guru
```

That needs the A record of `mx1.tits.guru` to name the host (it may be
published before any MX record) and the existing Nginx to answer
`http://mx1.tits.guru/.well-known/acme-challenge/` from `/var/www/html`. No
Nginx vhost, no site certificate, no firewall rule and no DNS record other than
that A record is changed for it; no DNS API, no other ACME client and no Certbot
plugin is used.

Certbot renews it on its own, through its systemd timer, by the same method —
it is recorded in `/etc/letsencrypt/renewal/mx1.tits.guru.conf`. **Renewal
therefore depends on HTTP on port 80 staying reachable for `mx1.tits.guru`**:
should the A record, the Nginx server on port 80 or the provider's firewall for
TCP 80 change, renewals fail, and the certificate expires 90 days after the
last one that succeeded. A manual DNS-01 challenge (`--manual
--preferred-challenges dns`) is **not** a working method for this domain: it
cannot renew unattended.

The public site of `tits.guru` is built to keep that route. It lists its own
name exactly, is never a `default_server`, and is installed only when the
configuration proves `mx1.tits.guru` stays on the server that answers it
today. See [`public-site.md`](public-site.md#how-mx1titsguru-keeps-its-http-01-route).

### Installing it: Install tits.guru inbound mail TLS

On the production host this ran on 2026-10-12, run
[`38260303027`](https://github.com/menvil/RateGuru/actions/runs/38260303027):
`TLS: INSTALLED`, its verify PASS.

The pair is never copied by hand. The workflow **Install tits.guru inbound
mail TLS** — manual, from `main` only, behind the typed confirmation `INSTALL
tits-guru inbound TLS`, in the `production-tits-guru` Environment with the
bootstrap credential, waiting in the `rateguru-staging-deployment` group like
every change to that machine — uploads the trusted `infrastructure/` tree and
nothing else, and runs `activate-mail-inbound --install-tls --target
tits-guru` under the host's infrastructure lock. That runs
`install-mail-inbound-tls --apply`:

1. Certbot must be installed and hold a renewal configuration for
   `mx1.tits.guru`. Certbot is never run, and nothing is installed.
2. The pair in `/etc/letsencrypt/live/mx1.tits.guru` is judged **before
   anything changes**: every directory of Certbot's tree root-owned and
   writable by no one else; both live links leading to regular root-owned files
   directly in `/etc/letsencrypt/archive/mx1.tits.guru`, the key not readable
   by everyone; then the certificate — every MX host name, at least 14 days
   left, a chain to a trusted authority, the key matching.
3. Certbot's deploy hook `/etc/letsencrypt/renewal-hooks/deploy/rateguru-mail-inbound-tls`
   is installed — root's, 0755, a regular file rendered from `main`, with
   nothing in it that points back at the uploaded bundle.
4. That hook is run exactly as Certbot runs it after a renewal, so the first
   pair is copied by the same code as every renewal: staged in
   `/etc/rateguru-mail-inbound/.tls-incoming.*`, judged there, moved in, read
   back; on any failure the pair installed before is put back, and never is a
   mixed pair left. Postfix, when it runs, is reloaded. If the pair does not
   install, the hook this run installed does not stay either: the hook that
   was there before — or none — is put back.
5. `install-mail-inbound-tls --verify` — the hook as rendered, the pair
   exactly the one Certbot holds, as regular files root:root 0644 and 0600, in
   real root-owned directories no one else may write —
   and `install-mail-inbound --tls-check`, the listener's own judge, unchanged.

Run again, it changes nothing and reloads nothing. No certificate or key ever
passes through GitHub; the uploaded tree is removed at the end of the run.
Public SMTP, the listeners, routes, the queue and OpenDKIM are not touched.

### Every renewal from then on

```
certbot.timer ─► certbot renew ─► HTTP-01 through the existing Nginx on :80 (/var/www/html)
                    │
                    ├─ renewed mx1.tits.guru: live/ links now lead to archive/…N.pem
                    │    └─ deploy hook rateguru-mail-inbound-tls,
                    │       RENEWED_LINEAGE=/etc/letsencrypt/live/mx1.tits.guru
                    │         ├─ judge the renewed pair (paths, name, ≥ 14 days, chain, key)
                    │         ├─ stage beside the installed pair, move in, read back
                    │         │    any failure: the previous pair back, the hook fails,
                    │         │    Certbot logs it, Postfix is not touched
                    │         └─ Postfix running? ─► systemctl reload postfix@-.service
                    │                                (never a stop, never a restart)
                    │
                    └─ any other certificate renewed: the hook exits at once, changes nothing
```

**A run stopped halfway.** Before the installed pair is touched, the hook
keeps a verified copy of it in its staging directory and records there —
synced to disk — that the pair is being replaced, with each file's SHA-256.
Terminated, it puts that copy back on its way out; killed outright, or with
the host going down, the next run — Install tits.guru inbound mail TLS or
Certbot's next renewal — puts it back first, whatever Certbot holds by then,
and only then goes on. The copy is put back only once it matches what was
recorded, and the result is read back; when either fails, the hook says
CRITICAL, exits 2, keeps the copy where it is and reloads nothing, and
`install-mail-inbound-tls --verify` reports the unfinished installation until
it is put right.

A renewal the hook could not install leaves the previous pair serving; it fails
`install-mail-inbound --tls-check` once that pair is within 14 days of expiry,
and with it Verify tits.guru inbound SMTP and Verify production infrastructure
— Certbot renews 30 days before expiry, so there is time to act. Postfix that is
stopped is not started: it reads the new pair when it starts.

### Checking it

```bash
# Certbot can still renew it over HTTP-01. A dry run saves nothing, and Certbot
# runs no deploy hook on a dry run.
sudo certbot renew --dry-run --cert-name mx1.tits.guru

# The installed pair is exactly the certificate Certbot holds, as reviewed.
sudo /etc/letsencrypt/renewal-hooks/deploy/rateguru-mail-inbound-tls --verify

# What Certbot's next renewal will run — with nothing renewed, it changes nothing.
sudo env -i RENEWED_LINEAGE=/etc/letsencrypt/live/mx1.tits.guru \
  /etc/letsencrypt/renewal-hooks/deploy/rateguru-mail-inbound-tls
```

The TLS check itself, `install-mail-inbound --tls-check`, runs from a trusted
bundle: in Install tits.guru inbound mail TLS, in every activation's preflight,
and in Verify tits.guru inbound SMTP. Once public SMTP is open, from outside:
`openssl s_client -starttls smtp -connect mx1.tits.guru:25 -servername
mx1.tits.guru` shows the certificate the listener serves.

### On a new VPS

In this order:

1. The host prepared and the site's Nginx serving port 80 from `/var/www/html`,
   as before.
2. The A record of `mx1.tits.guru` naming the new host's address.
3. The certificate issued anew with the `certbot certonly --webroot` command
   [above](#where-it-comes-from-certbot-over-http-01) — never copied from the
   old host by hand, and never through GitHub.
4. **Install tits.guru inbound mail TLS**, which passes only once steps 1–3
   hold.
5. Then the inbound activation, as [the operator order](#the-operator-order)
   says: its preflight judges the certificate before TCP 25 opens.

## Activation, verification and rollback

`infrastructure/scripts/activate-mail-inbound --check|--apply|--verify|--rollback
--target tits-guru`, run as root from a trusted bundle by three workflows. Each
runs from `main` only, behind the same main-only gate as every production
operation, in the `production-tits-guru` Environment with the existing
bootstrap SSH credential — never on a merge or a schedule.

| Workflow | Confirmation | Runs |
|----------|--------------|------|
| **Activate tits.guru inbound SMTP** | `ACTIVATE tits-guru inbound SMTP` | `--apply` |
| **Verify tits.guru inbound SMTP** | — (read-only) | `--verify` |
| **Rollback tits.guru inbound SMTP** | `ROLLBACK tits-guru inbound SMTP` | `--rollback` |

The confirmation is judged in a job that holds no Environment and no secret:
a wrong phrase, or a dispatch from anything but `main`, ends the run before the
Environment's approval is even requested.

**`--apply`, under the host's infrastructure lock:**

1. **The request.** The bundle's contract must request `enabled`, and the
   target must be a production target its plan receives for.
2. **Already done?** A host whose public SMTP is enabled and verifies is a
   no-op.
3. **The proof before anything changes:** the host's public IPv4 address (its
   route to the Internet, judged by `render-dns`), a valid certificate, the
   gateway verifies, nothing foreign listens on 25, 465 or 587, the firewall is
   manageable, the store can be created, the queue's file system keeps its
   reserve, no inbound mail is stuck in the queue, and the proof on loopback
   fits the inbound listener's own per-client limits — which bind `127.0.0.1`
   like any client — asked a few addresses a session, below Postfix's error
   limits however many targets the plan has.
4. **The outbound path recorded**: everything Postfix reads but the inbound
   settings, and the gateway's recorded policy.
5. **The store** (`install-mail-inbound --apply`), then — on a host without
   inbound mail — the **install** transition: the loopback listener and the
   delivery, still no public listener.
6. **The proof on loopback:** the outbound path unchanged and every submission
   listener still taking its own sender (`MAIL FROM`, no message); the inbound
   listener answers a corpus of addresses exactly as `route` judges them, offers
   no AUTH and no ETRN, announces the size, enforces the recipient limit,
   accepts the null sender, and stores one probe — with a random token — in the
   host postmaster's Maildir, which is then removed.
7. **A rollback capsule**, TCP 25 opened for that address (ufw), and the
   **enable** transition: the gateway reloads Postfix with the public listener.
8. **The proof again:** the gateway verifies, port 25 is held by Postfix's own
   unit alone on that address, 465 and 587 are closed, the rule is there, the
   public listener offers STARTTLS and no AUTH and refuses to relay, the
   outbound path is exactly what it was.
9. **Any failure from step 7 on** returns the host to disabled — the disable
   transition and RateGuru's rule removed — and proves it. If even that cannot
   be proved, the result is `critical` and nothing broader is attempted:
   Postfix is never stopped and its queue never touched.

**`--verify`** changes nothing and says which state the host is in:

| State | Meaning |
|-------|---------|
| `not-installed` | no inbound mail on this host, nothing on 25 |
| `installed-disabled` | inbound delivery and the store verify, nothing on 25, no RateGuru firewall rule |
| `enabled-verified` | all of that, Postfix alone listens on the recorded address, which the host has, and the rule is there |
| `drift` | anything else — reported as a failure |

and in every state, that the mail gateway verifies and no accepted inbound
mail waits in the queue for its store. Verify production infrastructure runs it as its
**Mail inbound** group: `enabled-verified` is PASS, inbound mail requested but
not activated is DEFERRED, drift is FAIL.

Every run ends with exactly one line, which the workflows check and summarize:

```
RATEGURU_MAIL_INBOUND_ACTIVATION_RESULT={"target":"tits-guru","mode":"apply","status":"pass","requested":true,"changed":true,"rolled_back":false,"state":"enabled-verified","public_smtp":"enabled","address":"…","firewall":"ufw","queue":null}
```

It carries no message, header, address, certificate or secret; `queue` is the
last queue scan's counts and ages, when the run made one.

## The health check

`activate-mail-inbound --health --target tits-guru` — read-only, no lock, so it
may run at any time — answers two questions: **can the store take mail right
now**, and **is mail the host already accepted waiting in the shared queue**.

| Check | Fails when |
|-------|-----------|
| the store | `install-mail-inbound --verify` fails: the volume not mounted, a mailbox not as reviewed, free space below its reserve, or the certificate |
| the store gate | the installed gate refuses new mail now (`451` unavailable, `452` full), or is missing |
| the shared queue | any accepted inbound message waits — deferred or held for any reason, or in the queue for 15 minutes in any state; one accepted more than a day ago is reported **AT RISK OF LOSS** |
| the queue's file system | it is below the reserve the inbound listeners refuse new mail under |

It prints counts and ages, never an address, a sender or a byte of a message:

```
  FAIL 3 accepted inbound message(s) wait in the shared queue instead of reaching the store (deferred 3); the oldest was accepted 1d 6h ago — …
  FAIL AT RISK OF LOSS: 2 inbound message(s) accepted more than 1d 0h ago are still not stored. Postfix gives a message up 5d 0h after accepting it (maximal_queue_lifetime), and an inbound message given up is lost: the oldest has 3d 17h left
[…] INBOUND HEALTH: FAILED (3 problem(s) above) — restore the store before accepted inbound mail is given up
```

On a host without inbound mail it has nothing to check, and passes.

On the host, as root, it is also what the automatic monitor checks — the
monitor runs the same library, without the store's full verify.

## The automatic monitor

**Not installed and not enabled on the production host.**
- Install tits.guru inbound mail monitor has never run.
- Verify tits.guru inbound SMTP (run
  [`38261781978`](https://github.com/menvil/RateGuru/actions/runs/38261781978))
  reports `the automatic inbound mail monitor is not installed on this host`.
- No `production-tits-guru-monitor` Environment exists.
- `RATEGURU_MAIL_INBOUND_HEALTH_SCHEDULE` is not set, so the schedule
  dispatches nothing.

Its setup moves into RateGuru's future unified monitoring (ROADMAP,
*Post-launch: Mail Processing & Unified Monitoring*). Until then:

- nothing checks inbound mail unattended;
- run **Verify tits.guru inbound SMTP** by hand. It checks the store and
  counts accepted inbound mail waiting in the queue.

What follows describes the monitor as the repository implements it, for when
it is set up.

Every six hours, without a person: **Schedule tits.guru inbound mail health**
dispatches **Check tits.guru inbound mail health** on `main`, which asks the
production host's **inbound mail monitor** — through a key that can do nothing
else — whether accepted inbound mail is at risk, and keeps one GitHub issue
open while it is.

| | What it is | What it can do |
|---|---|---|
| The schedule | `schedule-tits-guru-inbound-mail-health.yml`, on the default branch | dispatch the check on `main` — no secret, no host |
| The check | `check-tits-guru-inbound-mail-health.yml`, from `main`, behind the main-only gate, in the Environment `production-tits-guru-monitor` | connect as `rateguru-mail-monitor` with the monitor key — nothing else |
| The key | one ed25519 key, `restrict,command="/usr/bin/sudo -n -u postfix /usr/local/libexec/rateguru-mail-inbound-monitor"` | run the monitor: no shell, no other command, no terminal, no forwarding, no file transfer — whatever the client asks |
| The account | `rateguru-mail-monitor`, a system account with no password; its home and keys are root's | nothing of its own |
| sudo | `/etc/sudoers.d/rateguru-mail-monitor`: `rateguru-mail-monitor ALL=(postfix) NOPASSWD: /usr/local/libexec/rateguru-mail-inbound-monitor ""` | the monitor, with no argument, as postfix — never root, never another command |
| The monitor | `/usr/local/libexec/rateguru-mail-inbound-monitor`, `root:root 0755`, rendered from the trusted bundle | read the gateway's record, ask the store gate, read the queue's envelopes (`postcat -e`) and its free space — and print counts and ages |

It reaches the host through none of the production credentials: the
`production-tits-guru` Environment, its reviewers and its `BOOTSTRAP_SSH_KEY` are
unchanged and are never used by it. Its verdict is **PASS** only when the monitor
ran, said pass, exited 0 and found nothing waiting; a monitor that cannot be
reached, does not report, or reports anything else is **FAILED** or **UNKNOWN**,
and opens the issue.

Installing the access needs the production approval, once; running the check
never does. Proved on a real host (Ubuntu 24.04, OpenSSH 9.6, sudo 1.9.15): with
that key, a requested command (`id; cat /etc/shadow`) still runs only the
monitor; a terminal, `ssh -W` forwarding and `sftp` are refused; as the account,
`sudo -l` lists the monitor alone, and the monitor with an argument, as root,
`/bin/sh` or `postsuper -d ALL` all ask for a password it does not have; it
cannot write its own `authorized_keys`. The real-Postfix scenario runs the
monitor as postfix against a real queue.

### Setting it up, once

After the activation (step 5 of [the operator order](#the-operator-order)):

1. **Generate the monitor key**, on your own machine — a key of its own, never
   the bootstrap or a deploy key:
   ```bash
   ssh-keygen -t ed25519 -N '' -C rateguru-mail-monitor -f rateguru-mail-monitor
   ```
2. **Install its access on the host:** run **Install tits.guru inbound mail
   monitor** from `main` with `public_key` = the one line of
   `rateguru-mail-monitor.pub`, and approve it in `production-tits-guru`. It
   refuses anything but one ed25519 public key, and a key any other account on
   the host already holds.
3. **Create the Environment** in GitHub → Settings → Environments → New
   environment:
   - **Name** `production-tits-guru-monitor`;
   - **Required reviewers**: none — and no wait timer;
   - **Deployment branches and tags**: Selected branches and tags — the branch
     `main`, and nothing else;
   - **Secrets**: `MAIL_MONITOR_SSH_KEY` — the private half of the monitor key;
     `MAIL_MONITOR_KNOWN_HOSTS` — the host's verified known_hosts line, the same
     as `production-tits-guru`'s `BOOTSTRAP_KNOWN_HOSTS`;
   - **Variables**: `DEPLOY_HOST` and `DEPLOY_PORT` — the same as
     `production-tits-guru`'s.

   Never `BOOTSTRAP_SSH_KEY`, `DEPLOY_SSH_KEY` or any other key: this
   Environment needs no approval, so it holds the monitor key and nothing else.
4. **Delete the local copies** of both halves of the key.
5. **Run Check tits.guru inbound mail health** once by hand: it passes, with no
   approval asked.
6. **Turn the schedule on:** the repository variable
   `RATEGURU_MAIL_INBOUND_HEALTH_SCHEDULE` = `enabled`.

If the host's sshd restricts who may log in (`AllowUsers`, `AllowGroups`), it
must admit `rateguru-mail-monitor`; the stock Ubuntu sshd does.

**To replace the key**, repeat steps 1–4: the install replaces the one key.
**To revoke it** at once, delete `MAIL_MONITOR_SSH_KEY` from the Environment and,
on the host, `sudo rm /var/lib/rateguru-mail-monitor/.ssh/authorized_keys`; Verify
tits.guru inbound SMTP then reports the access as not what the bundle renders,
until it is installed again.

## When the health check fails

1. **Read which check failed** in the run's log; the issue says how many
   messages wait and for how long.
2. **Restore the store** on the host, as root: `install-mail-inbound --verify`
   says what is wrong. A volume that is not mounted: `systemctl start
   'var-lib-rateguru\x2dmail\x2dinbound-store.mount'`. A full one: read and
   move stored mail off it (by count: `find …/new -type f | wc -l`; never into a
   log). A mailbox not as reviewed: `install-mail-inbound --apply`.
3. **Let Postfix deliver what waits.** It retries on its own, at most every
   `maximal_backoff_time` (about an hour); to retry at once, `postqueue -f`
   retries every deferred message — inbound and outbound alike, which is
   harmless: nothing is removed. Never `postsuper -d`.
4. **Run the health check again**: it passes, and closes the issue, once
   nothing waits.

A message reported **AT RISK OF LOSS** is lost when its queue lifetime runs
out: restore the store before the time the check prints.

## Tested on a real Postfix

Stubs prove the scripts; only Postfix can prove Postfix. The CI job **Mail on a
real Postfix** installs Ubuntu's `postfix` package with no configuration of its
own and runs `tests/Support/real-postfix/scenario.sh` as root in a network
namespace with only loopback — no message can leave and no DNS answers. The
scenario renders the configuration with the gateway's own renderer, from the
reviewed documents, into a directory of its own, and runs it:

- Postfix accepts it, and the gateway's read-back — the outbound contract and
  all twenty-one requirements — passes against the real `postconf`;
- the outbound part, as `postconf -n`, `-M` and `-P` read it, is identical with
  inbound mail absent, disabled and enabled;
- the production listener still takes its sender, and its mail to
  `support@tits.guru` leaves through `rateguru-outbound-tits-guru`, never into
  the store; staging mail reaches the capture destination;
- both inbound listeners answer every corpus address exactly as `route`; no
  AUTH, no ETRN, the size, the recipient limit, STARTTLS with a certificate that
  verifies for `mx1.tits.guru`, the per-client session limit;
- `virtual(8)` stores support, bounce and the bare `<Postmaster>` in their
  Maildirs; a message waits in the queue while the store cannot be written and
  is stored once it can; a bounce to a forged sender has no route;
- the queue scan, on Postfix's own envelopes, counts that waiting message as
  inbound and never the application's deferred message to `support@tits.guru`;
- the store gate, on a store volume of its own: with the volume not mounted,
  mounted read-only, below its reserve of space or of inodes, both inbound
  listeners refuse at `RCPT TO` — `451`/`452`, so no message reaches `DATA` and
  nothing is queued — while 2525 and 2526 still accept; without its gate they
  refuse too; with the store back they accept;
- the inbound listeners refuse below their queue floor while the submission
  listeners accept;
- a reload to disabled closes 25 with the same master process, and every
  listener left still works.

CI's runner is Ubuntu 24.04, so the job runs Postfix 3.8; the scenario passes
on Ubuntu 22.04's Postfix 3.6 as well, the version the hosts run — the store
gate and the queue scan included. The same flow
was rehearsed end to end on Ubuntu 22.04 with systemd and ufw —
install, activation, verify, a repeat that changes nothing, rollback, the
store unavailable, a stale bundle, a host reboot, and a reboot without the
public address.

## The operator order

DNS publication is a manual operator step **within 8.4B.5.2**; no further change
is needed for it. In this order, and no step before the previous one passed:

1. **Merge** this implementation into `develop` after CI.
2. **Promote** `develop` → `main`.
3. **Prepare the external conditions**: the host's public IPv4 address, inbound
   TCP 25 allowed by the provider's firewall, and the TLS certificate for
   `mx1.tits.guru` issued by Certbot and installed by **Install tits.guru
   inbound mail TLS** with `INSTALL tits-guru inbound TLS`, as
   [above](#tls-for-mx1titsguru).
4. **Run Activate tits.guru inbound SMTP** with
   `ACTIVATE tits-guru inbound SMTP`. It creates the store, installs inbound
   mail on the gateway's Postfix, proves it, opens TCP 25 and prints the DNS
   records to publish.
5. **Run Verify tits.guru inbound SMTP**: `enabled-verified`. The automatic
   monitor is not set up here any more. Its setup moves into RateGuru's
   unified monitoring, after the first production launch; when it comes, it
   is done as [above](#setting-it-up-once).
6. **Publish DNS** at the DNS provider, the A record first, then the MX records:
   ```
   mx1.tits.guru          A     <the address Activate printed>
   tits.guru              MX 10 mx1.tits.guru
   bounce.tx.tits.guru    MX 10 mx1.tits.guru
   reply.tits.guru        MX 10 mx1.tits.guru
   ```
   Nothing for `mta1.tits.guru`, no PTR, SPF, DKIM or DMARC change.
7. **Check public DNS**: the A and MX records answer the same from both public
   resolvers —
   ```bash
   for resolver in 1.1.1.1 8.8.8.8; do
     dig +short @"${resolver}" A mx1.tits.guru
     for domain in tits.guru bounce.tx.tits.guru reply.tits.guru; do
       dig +short @"${resolver}" MX "${domain}"
     done
   done
   ```
   — each answer `<the address Activate printed>` and `10 mx1.tits.guru.`, and
   TCP 25 on that address answers from outside the host.
8. **Send a real message** from an outside mailbox to `support@tits.guru` — with
   the support mailbox's count noted just before, on the host:
   `sudo find /var/lib/rateguru-mail-inbound/store/targets/tits-guru/support/new -type f | wc -l`.
9. **Check it is in the inbound store**: the same count, once the message has
   had time to arrive, is one more than before — the count, never the content,
   and never into a workflow log.
10. **Run Verify production infrastructure.**
11. **Run Verify staging infrastructure.**

If TCP 25 is not reachable from outside, or DNS is not published, 8.4B.5.2 is not
accepted.

**On the production host, 2026-10-12:**
- **Steps 1–5:** done, through the runs in [Production
  acceptance](#production-acceptance-2026-10-12).
- **Steps 6–7:** DNS published, and confirmed through both resolvers. TCP 25
  answered from outside.
- **Steps 8–9:** one real message stored in the support Maildir.
- **Steps 10–11:** Verify production infrastructure and Verify staging
  infrastructure have **not** run since; they are open before GO LIVE.

## Returning to disabled

**Run Rollback tits.guru inbound SMTP** with `ROLLBACK tits-guru inbound SMTP`.
The gateway drops the public listener by a reload, through its own one-use
authorization, RateGuru's firewall rule for TCP 25 — and only it — is removed,
and the run proves that nothing listens on 25 any more and that the outbound
path is exactly what it was. Postfix is never stopped: 2525 and 2526 keep
working, the shared queue — mail accepted but not yet stored included — is
kept, and every stored message stays in its Maildir. It works whatever the
bundle requests, and on a host already closed it changes nothing.

**It changes no DNS record.** After it, check the published MX records: unless
public SMTP is coming back soon, remove them, so they do not keep pointing
senders at a closed port. Then Verify tits.guru inbound SMTP says
`installed-disabled`.

To make the repository itself stop requesting public SMTP, commit
`"public_smtp": "disabled"`: Activate then refuses, and a host still enabled is
reported as drift until Rollback closes it.

## The sequence

1. **The inbound contract** — the reviewed contract, its validation, the inbound
   plan, the address verdicts and the DNS plan. *8.4B.5.1, completed.*
2. **Inbound listeners on the host Postfix, proved locally** — the gateway's
   inbound listeners and delivery, rendered from the inbound plan, the store,
   and every requirement above proved on the host without a public listener.
3. **Guarded activation of public SMTP** — the `enabled` state, the port-25
   owner in every check, the firewall, and a rollback that closes the port
   again. *8.4B.5.2 covers steps 2 and 3, and the operator publishes step 4
   within it.*
4. **MX records** — only after step 3 verifies: the A record of `mx1.tits.guru`
   (it may go first), then the three MX records from `render-dns`, verified
   through public resolvers. *Done 2026-10-12.*
5. **An external test message to `support@tits.guru`** — sent from an outside
   mailbox, received, stored, and nothing else accepted. *Done 2026-10-12.*
6. **Bounce reception and correlation** — the Return-Path moves to
   `b-<identifier>@bounce.tx.tits.guru`, delivery status notifications are
   received and matched to the message they report on. Moving the envelope
   sender to the bounce domain brings its own DNS: that domain needs an SPF
   record of its own, and with `aspf=s` SPF no longer aligns with the `From`
   domain, so DMARC then rests on DKIM (`d=tits.guru`, `adkim=s`) alone. *8.4B.5.3.*
7. **Replies** — `Reply-To` addresses on the reply domain, replies received and
   matched to their conversation, and the support mailbox. *8.4B.5.4.*
8. **Storage and processing** — the handlers read what the store holds and hand
   it to the application's storage and processing, idempotently.
   *8.4B.5.3 and 8.4B.5.4.*
9. **Security and recovery** — the real-host acceptance from outside (relay,
   AUTH and limit probes, the external messages), and recovery on a replacement
   host with an inbound fence like the outbound one. *8.4B.5.5.*

| Slice | What | State |
|-------|------|-------|
| 8.4B.5.1 | Inbound contract and DNS plan | **Completed** |
| 8.4B.5.2 | Inbound SMTP on the host Postfix and guarded activation | **Production-accepted 2026-10-12** |
| 8.4B.5.3 | Bounce reception and correlation | Planned — deferred until after the first production launch |
| 8.4B.5.4 | Reply routing and the support mailbox | Planned — deferred until after the first production launch |
| 8.4B.5.5 | Real-host acceptance and recovery proof | Planned. The MX records and the first external message are done within 8.4B.5.2; the extended trials are deferred until after the first production launch |

Inbound mail is not working until a message from outside has actually been
received. One was, on 2026-10-12.

## What the operator prepares before Activate

- **The host's public IPv4 address** — the address its route to the Internet
  leaves from, which outbound mail uses too — and **inbound TCP 25 allowed by
  the provider's firewall** outside the host: if the provider filters traffic
  in front of the VPS — for Contabo, a firewall configured in its customer
  control panel — it needs an allow rule for TCP 25 to that address. The host
  cannot see it; step 7 of the operator order proves it from outside.
- **The host firewall**: ufw active, or no inbound filtering at all — any other
  filtering is refused, and the operator decides how to change it.
- **The TLS certificate** for `mx1.tits.guru`: issued by Certbot over HTTP-01,
  installed by Install tits.guru inbound mail TLS, and renewing as
  [above](#tls-for-mx1titsguru).
- **Disk**: free space for the 1 GiB store plus the 2 GiB host reserve, and the
  queue's file system above that reserve.
- **DNS access** to publish the A record and then the MX records, by hand, in
  that order — and to remove them again if public SMTP is ever rolled back.
- **An outside mailbox** for the external test message.

## What this does not do

It does not process received mail: no DSN parsing, no bounce correlation, no
`Reply-To`, no support inbox, no suppression, no Laravel model — those are
8.4B.5.3, 8.4B.5.4 and 8.4B.6, deferred until after the first production
launch. It does not back up the store or the queue, and nothing watches
inbound mail unattended until the monitor is set up. It changes no outbound
route, OpenDKIM setting, DKIM key, routing policy, `mail-outbound.json`, lifecycle, environment file,
application setting, backup schedule, deploy, GitHub secret or DNS record. It
sends no mail, answers no message, and never prints one.
