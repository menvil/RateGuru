# Mail inbound

How a production target receives mail from the Internet: support mail, delivery
status notifications for the mail it sent, and people replying to it. This
runbook covers the reviewed **inbound contract**, the addresses it accepts and
refuses, the **isolated inbound receiver** that enforces it, the **guarded
activation** that opens public TCP 25 to that receiver and closes it again, the
DNS it needs, and the order of everything that comes after.

The receiver is implemented in the repository. **On a host, nothing listens on
port 25 and no MX record is published until an operator runs Activate
tits.guru inbound SMTP and then publishes DNS by hand** — merging this changes
no host.

Outbound mail is a separate path with its own runbooks —
[`mail-routing.md`](mail-routing.md), [`mail-gateway.md`](mail-gateway.md),
[`mail-identity.md`](mail-identity.md), [`mail-signing.md`](mail-signing.md) and
[`mail-outbound-activation.md`](mail-outbound-activation.md) — and none of it is
changed by this.

## Status

| What | State |
|------|-------|
| Inbound contract `infrastructure/config/mail-inbound.json` | **Implemented**, reviewed — schema 2 |
| `infrastructure/scripts/mail-inbound` (`validate`, `render-plan`, `render-receiver`, `render-dns`, `route`) | **Implemented**; repository tooling, read-only |
| Inbound receiver `install-mail-inbound` (its own Postfix instance) | **Implemented in the repository**; installed on a host only by Activate tits.guru inbound SMTP |
| Guarded activation `activate-mail-inbound` and its three workflows | **Implemented in the repository**; never run automatically |
| Public inbound SMTP — committed request | **`enabled`** — a request, never the state of a host |
| Public inbound SMTP — real host | **Disabled**: nothing listens on port 25 until the guarded activation has run |
| MX records for `tits.guru`, `bounce.tx.tits.guru`, `reply.tits.guru` | **Not published** — and not before the receiver is active and verified |
| `mx1.tits.guru` A record | **Not published**; the receiver's address is never committed |
| TLS certificate for `mx1.tits.guru` | **The operator's** — not on the host until installed as [below](#tls-for-mx1titsguru) |
| Support, bounce and reply handling | **Stored, not processed** — accepted mail is kept in the receiver's store; every handler is `planned` |
| Envelope sender (Return-Path) of mail sent today | `noreply@tits.guru` — the bounce domain is not an envelope sender yet |
| `tits-guru` outbound mail | **Production-accepted** 2026-10-08, and untouched by any of this — see [`mail-outbound-activation.md`](mail-outbound-activation.md) |

The envelope sender of every message sent today is `noreply@tits.guru`. Nothing
here claims that sent mail already names the bounce domain: moving the
Return-Path there is the bounce slice's change (see [the sequence](#the-sequence)).

## A separate receiver, not the gateway

The mail gateway (`install-mail-gateway`) is a **submission** service. It listens
only on each target's loopback port, its port is the sender's identity, and it
holds no public port. A public MX is the opposite trust boundary: it accepts
connections from anyone on the Internet, for recipients this host is
responsible for. Putting a public listener into the gateway would make one
process hold both boundaries, so the receiver is a **second, independent
Postfix instance**:

```
Internet ──SMTP :25──▶ rateguru-mail-inbound.service   (/etc/postfix-inbound, own queue, own volume)
                          │ RCPT TO judged by the inbound plan, before any data
                          ├─ support@ / postmaster@ each served domain → targets/tits-guru/support/
                          ├─ b-<identifier>@bounce.tx.tits.guru        → targets/tits-guru/bounce/
                          ├─ r-<identifier>@reply.tits.guru            → targets/tits-guru/reply/
                          ├─ <Postmaster> (bare)                       → host/postmaster/
                          └─ anything else                             → refused at RCPT TO

applications ──▶ 127.0.0.1:2525 / :2526 ──▶ postfix@-.service (/etc/postfix, loopback only) ──▶ Mailpit / recipient MX
```

| | Outbound gateway | Inbound receiver |
|---|---|---|
| Installer | `install-mail-gateway` | `install-mail-inbound` |
| Configuration | `/etc/postfix` | `/etc/postfix-inbound` |
| Queue | `/var/spool/postfix` | `/var/spool/rateguru-mail-inbound/queue` |
| Service | `postfix.service` / `postfix@-.service` | `rateguru-mail-inbound.service` — not `PartOf` `postfix.service` |
| State | `/var/lib/rateguru-mail-gateway` | `/var/lib/rateguru-mail-inbound` |
| Log | the system journal, `postfix/…` | `/var/spool/rateguru-mail-inbound/log/maillog`, `rateguru-mail-inbound/…` |
| Listeners | `127.0.0.1:2525`, `127.0.0.1:2526` | `127.0.0.1:2580` always; `<public IPv4>:25` only once activated |
| Delivers to | Mailpit, recipient MX | its own store only — it has no SMTP client |

- **Nothing shared but the Postfix package.** The receiver runs
  `postfix -c /etc/postfix-inbound`. It never installs, upgrades or reconfigures
  the package, never uses `postmulti`, and never registers itself in the
  gateway's configuration: `/etc/postfix/main.cf` and `master.cf` stay byte for
  byte what `install-mail-gateway` rendered, and the activation proves that
  before and after every change. Starting, reloading or stopping one instance
  never reaches the other.
- **Public TCP 25 only after its own guarded activation** — never as a side
  effect of a Prepare, a bootstrap or a repair.
- **No SMTP AUTH, and never a relay**: it accepts mail only for the addresses of
  the inbound plan, and refuses everything else at `RCPT TO`, for every client
  alike — loopback included.
- **It cannot send.** Its `master.cf` has no `smtp`, `lmtp`, `local`, `pipe`,
  `relay` or `pickup` service and every fallback transport is `error`, so
  nothing that arrives can leave, and nothing reaches the gateway's submission
  ports.
- **It touches nothing else**: not staging capture, Mailpit, OpenDKIM or the
  outbound queues.

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
| Whether public SMTP is requested, the receiver's loopback port and limits; per target its MX host, support local parts, and bounce and reply address forms | `config/mail-inbound.json` |
| Whether public SMTP is actually open on a host | the host's own applied state, `/var/lib/rateguru-mail-inbound/applied.json` — never the repository |

So `tits.guru`, `bounce.tx.tits.guru` and `reply.tits.guru` appear nowhere in the
inbound contract: change a domain in the routing policy and the inbound plan
follows. One file holds every brand's inbound policy; there is no file per brand.

**`public_smtp` is a request.** `enabled` says that the reviewed tooling may
open public SMTP when an operator activates it; `disabled` says it may not, and
then Activate refuses before it changes anything. Neither value opens or closes
anything on a host by itself: see [the two states](#public-smtp-requested-and-applied).

**`mail-inbound validate` refuses**, each with its own reason:

- a schema other than 2, more than one document, a duplicated key, a control
  character, a symlink, or any property it does not know — at every level;
- a credential-like property name, however deep;
- `public_smtp` other than `disabled` or `enabled`;
- a loopback port outside 1024–65535, or one the mail routing plan already uses
  for a listener or a capture route — the receiver and the gateway never share
  a port;
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

| Destination | Domain | Accepts | The identifier names | Future handler |
|-------------|--------|---------|----------------------|----------------|
| support | `tits.guru` | exactly `support@tits.guru` and `postmaster@tits.guru` — and `postmaster@` the bounce and reply domains | — | `support-mailbox` |
| bounce | `bounce.tx.tits.guru` | `b-<identifier>@bounce.tx.tits.guru` | the outbound message | `bounce-correlation` |
| reply | `reply.tits.guru` | `r-<identifier>@reply.tits.guru` | the conversation | `reply-routing` |
| host-postmaster | — | the bare `<Postmaster>`, which names no domain | — | `host-postmaster` |

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
  `hostmaster@` and `abuse@` are not it.
- **The bare `<Postmaster>` is the host's.** It names no domain, so on a host
  that receives for several brands it belongs to none of them: the receiver
  qualifies it to the reserved domain `rateguru-mail-inbound.invalid` and
  stores it in `host/postmaster/`, a mailbox of its own, never a brand's.
  Nothing else is accepted at that domain. RFC 5321 also expects mail to
  postmaster from anywhere to be accepted with every reasonable effort, so no
  limit refuses it more broadly than an attack in progress requires.
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

| Rejection | When | Answer at `RCPT TO` |
|-----------|------|---------------------|
| `malformed-address` | not one printable `local@domain` of at most 254 characters (the bare `Postmaster` aside), an address literal, a trailing dot | refused (`5xx`) |
| `relay-denied` | no target receives mail at that domain — including subdomains, the MX host and the MTA host | `554 5.7.1` |
| `unknown-recipient` | a domain this host receives at, but not one of its addresses — including another destination's form | `550 5.1.1` |
| `invalid-identifier` | the right prefix, but not a well-formed `base32-128` identifier | `550 5.1.1` |

The activation does not trust this table: it sends a corpus of addresses to the
live receiver and requires every answer to agree with `route`'s verdict.

```console
$ infrastructure/scripts/mail-inbound route --recipient support@tits.guru
{ …, "destination": "support", "target": "tits-guru", "verdict": "accept" }

$ infrastructure/scripts/mail-inbound route --recipient r-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru
{ …, "rejection": "unknown-recipient",
  "reason": "r-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru is a reply address at the bounce domain of tits-guru: …",
  "verdict": "reject" }
```

## The plan

`mail-inbound render-plan` prints the whole inbound plan as JSON: the receiver
(`public_smtp`, its loopback port, its limits, the host postmaster and its
requirements), and per target its MX host, its three domains and its
destinations — the exact support addresses, the bounce and reply address spaces
(`local_part_prefix`, `identifier`), what each identifier names, and the handler
each destination will be given to, every one `planned`. It holds names,
addresses, numbers and closed vocabulary only: no command, path, secret or
message. Rendered twice, or from the same documents in another key order, it is
byte for byte the same.

`mail-inbound render-receiver` prints exactly what the receiver enforces, from
that plan and nothing else: every allowed recipient as an anchored,
case-insensitive POSIX extended regular expression with the mailbox its mail is
stored in, the domains served, the MX host names the certificate must cover,
the loopback port and the limits. `install-mail-inbound` spells that in Postfix
and restates no rule — **there is one implementation of the address rules**,
and the receiver's table is rendered from it.

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
  Without `--ipv4` the plan marks the address `not-provided`. The activation
  takes the address from the host's own route to the Internet and judges it
  with this same command.
- **No MX record is published before the receiver is installed, verified and
  activated.** A published MX is a public promise that a server accepts mail
  there: a sender that finds one queues and retries for days when nothing
  answers, so a reply to a dead MX fails silently for days instead of at once.
  The A record of `mx1.tits.guru` may go first — nothing is sent to it until an
  MX names it — and the plan says so per record (`before-mx`,
  `after-receiver-activation`).
- **No AAAA.** The receiver listens on IPv4 only (`inet_protocols = ipv4`), like
  the gateway, and its firewall rule names one IPv4 address; an AAAA record
  would send IPv6 senders to an address nothing listens on.
- **The outbound identity is untouched.** No record for `mta1.tits.guru`, no PTR,
  no SPF, DKIM or DMARC: the plan names the MTA hostname only to say its records
  are unchanged, and the MX host may never be that name. The SPF policy
  (`v=spf1 ip4:213.199.41.241 -all`) has no `mx` mechanism, so publishing MX
  records changes no SPF result.
- **Nothing is published by the tooling.** Records are published by the operator
  at the DNS provider; no API is called, and no workflow publishes DNS.

## Receiver requirements

The receiver is held to every one of these. The plan carries the list
(`receiver.requirements`), and `install-mail-inbound --verify` reports each by
name against the configuration Postfix actually runs (`postconf -c
/etc/postfix-inbound`), never against what was meant to be written.

| Requirement | What it means |
|-------------|---------------|
| `recipient-allowlist` | Mail is accepted only for the plan's exact support addresses and well-formed bounce and reply addresses at its domains (`virtual_mailbox_maps` rendered by `render-receiver`, `reject_unlisted_recipient`); everything else is refused at `RCPT TO`, before any data. |
| `postmaster-accepted` | `postmaster` is accepted case-insensitively at every domain the receiver serves, and the bare `<Postmaster>` too (RFC 5321 §4.5.1) — into the target's support mailbox, the bare one into the host postmaster's; no limit refuses it more broadly than an attack in progress requires. |
| `relay-refused-at-rcpt` | A recipient at any other domain is refused at `RCPT TO` (`reject_unauth_destination`), for every client, loopback included: no `permit_mynetworks`, no trusted network makes it a relay. |
| `no-smtp-auth` | No SMTP AUTH is offered on any listener (`smtpd_sasl_auth_enable = no`); the activation proves `EHLO` announces none. |
| `no-relay-no-forwarding` | Accepted mail is never relayed or forwarded off the host: no relay domains, aliases or virtual aliases, and every fallback transport is `error`. |
| `message-size-limit` | `message_size_limit` = `limits.message_size_bytes` (10 MB), announced in `EHLO` `SIZE` and proved live. |
| `recipient-limit` | `smtpd_recipient_limit` = `limits.recipients_per_message` (1), proved live: a second `RCPT TO` in one transaction is refused. |
| `connection-limits` | Per client: at most `connections_per_client` (5) concurrent sessions and `connection_rate_per_client` (30) new ones a minute, with no exception list; `concurrent_sessions` (20) public `smtpd` processes in all. |
| `queue-limits` | The queue lives on the fixed-size volume; `queue_minfree` = `storage_reserve_bytes`; mail that cannot be stored stays queued (`maximal_queue_lifetime` 100 days) and is never bounced away. |
| `isolated-queue` | Its own configuration directory, queue, data directory, log and service, never the gateway's. |
| `no-outbound-submission` | No `smtp`, `lmtp`, `relay` or `pickup` service and no path into the gateway's submission ports: it cannot send mail through production outbound delivery. |
| `no-message-content-in-logs` | Message bodies, headers and attachments never reach logs, workflow output or GitHub Actions; TLS logging is off, and the activation's store probe is found by a random token and never printed. |
| `content-never-executed` | No delivery to a command (`mailbox_command` empty, no `pipe`, no `local`); the store is mounted `noexec`, `nosuid`, `nodev`; nothing in a message is ever run, evaluated or used as a path. |
| `untrusted-sender-fields` | `From`, `Reply-To`, `Return-Path` and attachments are untrusted data, never identity or authorization: nothing in the receiver reads them. |
| `no-automatic-replies` | Nothing answers an unverified address: `notify_classes` empty, no auto-reply, and no bounce generated for mail with an empty sender. |
| `null-sender-accepted` | `MAIL FROM:<>` is accepted, so delivery status notifications reach the bounce domain; the activation proves it live. |
| `malformed-and-duplicate-safe` | Malformed mail is set aside without stopping the handler, and a repeated message is processed once: every message is its own Maildir file, never overwritten, never merged. |
| `disk-exhaustion-guard` | The store is a fixed-size volume of its own — it can never fill the host's filesystem — with a free-space floor that answers new mail with a temporary refusal. |
| `single-public-port-owner` | Port 25 is bound only by the receiver's own service on its recorded address, which every check recognizes exactly through `public-smtp-port`; 465 and 587 stay closed. |
| `no-shared-mail-state` | Staging capture, Mailpit, OpenDKIM and the outbound queues are never touched. |

## The receiver: `install-mail-inbound`

```bash
# Read-only: may --apply proceed, and what would it change?
sudo infrastructure/scripts/install-mail-inbound --check

# Install or converge, public SMTP left exactly as the host records it.
sudo infrastructure/scripts/install-mail-inbound --apply

# Read-only: files, store, service, listeners, every requirement by name.
sudo infrastructure/scripts/install-mail-inbound --verify

# Read-only: can the installed certificate serve every MX host name?
sudo infrastructure/scripts/install-mail-inbound --tls-check
```

It is repository tooling, run from a trusted bundle by `activate-mail-inbound`
and by the read-only infrastructure verification — never part of host
bootstrap, Prepare or repair. It refuses, before changing anything, a host
without the Postfix package or the mail gateway, files or a store account
RateGuru did not create, a loopback port in use, and too little free space.

**What it lays out**

| Path | What | Owner, mode |
|------|------|-------------|
| `/etc/postfix-inbound/main.cf`, `master.cf`, `recipients.regexp` | rendered from `render-receiver` | `root:root 0644` |
| `/etc/systemd/system/rateguru-mail-inbound.service` | `postfix -c /etc/postfix-inbound`, `RequiresMountsFor` its volume | `root:root 0644` |
| `/etc/systemd/system/var-spool-rateguru\x2dmail\x2dinbound.mount` | the store volume, `loop,nodev,nosuid,noexec` | `root:root 0644` |
| `/var/lib/rateguru-mail-inbound/store.img` | a fixed-size ext4 image (`storage_bytes`, 1 GiB), label `rgmailin` | `root:root 0600` |
| `/var/spool/rateguru-mail-inbound/` | the mounted volume: `queue/` (Postfix's own layout inside), `data/`, `log/`, `store/` | `queue/` `root 0755`, `data/` `postfix 0700`, `log/` `root 0700`, `store/` `rateguru-mail-inbound 0700` |
| `/var/lib/rateguru-mail-inbound/ownership` | the ownership marker, `installing` then `installed` | `root:root 0644` |
| `/var/lib/rateguru-mail-inbound/applied.json` | the host's applied state: public SMTP `disabled`, or `enabled` and its address | `root:root 0644` |

**Storage.** Postfix answers `250` only once a message is safely in its queue
on the receiver's volume; virtual(8) then delivers it, as the store user
`rateguru-mail-inbound`, into the Maildir of its destination —
`store/targets/<target>/<destination>/` or `store/host/postmaster/` — where every
message is its own uniquely named file. Nothing deletes it and nothing forwards
it; the bounce and reply handlers of the next slices read it from there. The
volume is fixed in size, so the store can never fill the host's filesystem, and
nothing in it can run. When less than `storage_reserve_bytes` is free, new mail
gets a **temporary refusal** (`452`), never a false success; mail already
queued waits up to 100 days and is never bounced away. A stuck queue — mail
deferred, held, or older than ten minutes — fails `--verify`.

**The transaction.** `--apply` validates the plan, the host and Postfix's own
judgement of the rendered configuration (`postconf -c`, `postfix -c … check`)
before it changes anything, backs up every file it replaces, and on any failure
restores them and the service's previous state. The volume, once created, is
kept and reused. A second `--apply` on a converged host changes nothing and
reloads nothing.

## Public SMTP: requested and applied

There are two states, kept in two places:

- **requested** — `receiver.public_smtp` in the committed contract;
- **applied** — `/var/lib/rateguru-mail-inbound/applied.json` on the host.

`install-mail-inbound --apply` **keeps the applied state**, whatever the
repository requests. It moves it between `disabled` and `enabled` only by
consuming a **one-use transition authorization**
(`/var/lib/rateguru-mail-inbound/transition-authorization.json`, root `0600`)
that `activate-mail-inbound` writes after its proof — bound to the direction,
the address and the state it crosses from, with a nonce recorded once used.
So:

- a merge of `"enabled"` opens nothing: the host stays disabled until Activate;
- Prepare, bootstrap and repair never install the receiver, and never open or
  close public SMTP;
- an **older bundle** that does not request public SMTP refuses on an enabled
  host before it changes anything, rather than closing the port behind the
  operator's back — only Rollback closes it;
- an authorization is never used twice, and one that is malformed, for the
  other direction, another address or another starting state is refused with
  nothing changed.

## Port 25 and the gateway's checks: one shared judge

Several checks refuse a public SMTP listener: `install-mail-gateway --verify`
(and with it Prepare, `verify-mail-gateway --read-only` and Verify staging and
production infrastructure), `activate-mail-outbound`'s read-back of the live
routes, and `status-mail-gateway`. They now all ask **one library,
`infrastructure/scripts/public-smtp-port`**, and none keeps a list of ports of
its own:

- **465 and 587 are refused to everything**, always;
- **25 is refused to everything**, unless all of these hold at once:
  the receiver's ownership marker says `installed`; its applied state is valid
  and says `enabled` on one address; the trusted bundle's contract requests
  `enabled`; the listener is on **exactly** that address; and **every process
  holding the socket runs in `rateguru-mail-inbound.service`'s own cgroup** —
  the unit, never a process name like `master` or `postfix`;
- a listener on 25 on another address or on every address, a second owner, an
  unreadable socket table, a corrupt or missing applied state, or a contract
  that no longer requests it — each fails, by name.

**`verify-mail-gateway` is never weakened to allow port 25 in general**, and
neither is any other check: while the receiver is disabled, every check refuses
25 exactly as before. The gateway itself still listens only on its loopback
ports, and its own verification still proves that.

## The firewall

Opening TCP 25 is part of the activation and its rollback, never a separate
manual step on the host. `activate-mail-inbound` works with the firewall the
host actually has:

| Host firewall | What happens |
|---------------|--------------|
| **ufw, active** | One rule of RateGuru's own — `allow in proto tcp to <address> port 25`, comment `rateguru-mail-inbound` — added on activation and removed, alone, on rollback. ufw keeps it across a reboot. A ufw rule RateGuru did not write that already allows 25 refuses the activation: RateGuru's rule must be the only way in, so that its removal really closes the port. |
| **none** — ufw not active, and nothing in `nft list ruleset` or `iptables-save` drops or rejects | Nothing to change: TCP 25 is reachable while the receiver listens and closed when it does not, after a reboot too, since the listener exists only while the applied state says `enabled`. |
| **anything else** — rules that filter inbound traffic and are not ufw's, or a packet filter that cannot be read | **Refused**, before anything changes: RateGuru never edits a firewall it does not manage. Manage it with ufw, or remove those rules. |

SSH, HTTP, HTTPS, outbound traffic and every other rule are never touched, and
465 and 587 are never opened. **The provider's firewall outside the host** —
Contabo's, if one is configured for this VPS — is invisible from the host: the
activation prints that it is the operator's to check and never claims it.
Nothing here gives the application user any power over the firewall.

## TLS for `mx1.tits.guru`

The receiver offers STARTTLS (`smtpd_tls_security_level = may`, TLS 1.2 or
later) with a **real certificate for every MX host name** — `mx1.tits.guru` —
read from the host only:

```
/etc/rateguru-mail-inbound/tls/fullchain.pem   root:root 0644, a regular file
/etc/rateguru-mail-inbound/tls/privkey.pem     root:root 0600, a regular file
```

Nothing is generated or committed: no key in the repository, no permanent
self-signed certificate, and never the website's certificate. Public SMTP is
**enabled only with a certificate that** chains to a trusted certificate
authority (the system trust store), covers every MX host name, does not expire
within 14 days, and matches its key. `install-mail-inbound --tls-check` says
which of these fails, and never prints the certificate or the key. While public
SMTP is disabled and no certificate is installed, `--verify` reports TLS as
`DEFERRED`; once it is enabled, a missing or expiring certificate fails it.

**Installing it** (once the A record of `mx1.tits.guru` may exist — it may go
first, before any MX):

1. Obtain a certificate for `mx1.tits.guru` from a public ACME CA **without
   touching the site's nginx or its certificates** — for example with
   certbot's DNS-01 challenge, which needs one `_acme-challenge` TXT record at
   the DNS provider and no listener at all:
   `sudo certbot certonly --manual --preferred-challenges dns -d mx1.tits.guru`.
2. Copy — never link — the result into place:
   ```bash
   sudo install -d -o root -g root -m 0755 /etc/rateguru-mail-inbound/tls
   sudo install -o root -g root -m 0644 /etc/letsencrypt/live/mx1.tits.guru/fullchain.pem /etc/rateguru-mail-inbound/tls/fullchain.pem
   sudo install -o root -g root -m 0600 /etc/letsencrypt/live/mx1.tits.guru/privkey.pem   /etc/rateguru-mail-inbound/tls/privkey.pem
   sudo infrastructure/scripts/install-mail-inbound --tls-check
   ```
3. **Renewal** repeats step 2 and then
   `sudo systemctl reload rateguru-mail-inbound.service` (from a certbot
   `--deploy-hook`, or by hand); Postfix reads the new pair on reload, and
   nothing else is restarted. A certificate within 14 days of expiry fails
   Verify tits.guru inbound SMTP and Verify production infrastructure, so a
   missed renewal is seen before it breaks STARTTLS.

## Activation, verification and rollback

`infrastructure/scripts/activate-mail-inbound --check|--apply|--verify|--rollback
--target tits-guru`, run as root from a trusted bundle by three workflows. Each
runs from `main` only, behind the same main-only gate as every production
operation, in the `production-tits-guru` Environment with the existing bootstrap
SSH credential — never on a merge or a schedule.

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
   target must be a production target its plan receives for; otherwise it
   refuses with nothing changed.
2. **Already done?** A host whose receiver is enabled and verifies — the
   receiver, its listener's owner, the firewall rule, the gateway — is a no-op
   that succeeds.
3. **The proof, before anything public changes:** the host's public IPv4
   address (from its route to the Internet, judged by `render-dns`), a valid
   certificate, the outbound gateway verifies and its files are recorded,
   nothing foreign listens on 25, 465 or 587, the firewall is manageable, the
   receiver installs or converges **still disabled** and verifies, and the live
   receiver on its loopback port answers a corpus of addresses exactly as
   `route` judges them, offers no AUTH, announces the size limit, enforces the
   recipient limit, accepts the null sender, and stores one probe — with a
   random token — in the host postmaster's mailbox, which is then removed.
4. **A rollback capsule** under `/var/lib/rateguru-mail-inbound-activation/`.
5. **The change:** TCP 25 opened for that address, a one-use enable
   authorization, the receiver's apply — which adds the public listener — and
   the proof again: the receiver verifies, port 25 is owned by its unit alone on
   that address, 465 and 587 are closed, the rule is there, the gateway still
   verifies and its configuration is byte for byte what it was.
6. **Any failure from step 5 on** returns the host to disabled through the same
   path as Rollback and proves it. If even that cannot be proved, the result is
   `critical` and nothing broader is attempted: the gateway, OpenDKIM and every
   queue are never touched, and outbound mail keeps flowing.

**`--verify`** changes nothing and says which state the host is in:

| State | Meaning |
|-------|---------|
| `not-installed` | no receiver on this host |
| `installed-disabled` | the receiver verifies, nothing listens on 25, no RateGuru firewall rule |
| `enabled-verified` | the receiver verifies, it alone listens on its recorded address, the rule is there |
| `drift` | anything else — reported as a failure |

and in every state, that the outbound gateway still verifies. Verify production
infrastructure runs it as its **Mail inbound** group: `enabled-verified` is
PASS, a receiver requested but not yet activated is DEFERRED, drift is FAIL.

Every run ends with exactly one line, which the workflows check and summarize:

```
RATEGURU_MAIL_INBOUND_ACTIVATION_RESULT={"target":"tits-guru","mode":"apply","status":"pass","requested":true,"changed":true,"rolled_back":false,"state":"enabled-verified","public_smtp":"enabled","address":"…","firewall":"ufw"}
```

It carries no message, header, certificate or secret.

## The operator order

DNS publication is a manual operator step **within 8.4B.5.2**; no further change
is needed for it. In this order, and no step before the previous one passed:

1. **Merge** this implementation into `develop` after CI.
2. **Promote** `develop` → `main`.
3. **Prepare the external conditions**: the host's public IPv4 address, inbound
   TCP 25 allowed by the provider's firewall, and the TLS certificate for
   `mx1.tits.guru` installed as [above](#tls-for-mx1titsguru).
4. **Run Activate tits.guru inbound SMTP** with
   `ACTIVATE tits-guru inbound SMTP`. It installs the receiver if needed, proves
   it, opens TCP 25 and prints the DNS records to publish.
5. **Run Verify tits.guru inbound SMTP**: `enabled-verified`.
6. **Publish DNS** at the DNS provider, the A record first, then the MX records:
   ```
   mx1.tits.guru          A     <the address Activate printed>
   tits.guru              MX 10 mx1.tits.guru
   bounce.tx.tits.guru    MX 10 mx1.tits.guru
   reply.tits.guru        MX 10 mx1.tits.guru
   ```
   Nothing for `mta1.tits.guru`, no PTR, SPF, DKIM or DMARC change.
7. **Check public DNS**: the A and MX records answer the same from public
   resolvers (`dig @1.1.1.1` and `dig @8.8.8.8`), and TCP 25 on that address
   answers from outside the host.
8. **Send a real message** from an outside mailbox to `support@tits.guru`.
9. **Check it is in the isolated store**, on the host, without printing it
   anywhere — the count, never the content:
   `sudo find /var/spool/rateguru-mail-inbound/store/targets/tits-guru/support/new -type f | wc -l`.
   Never into a workflow log.
10. **Run Verify production infrastructure.**
11. **Run Verify staging infrastructure.**

If TCP 25 is not reachable from outside, or DNS is not published, 8.4B.5.2 is not
accepted.

## Returning to disabled

**Run Rollback tits.guru inbound SMTP** with `ROLLBACK tits-guru inbound SMTP`.
It returns the receiver to disabled through its own one-use authorization,
removes RateGuru's firewall rule for TCP 25 — and only it — and proves that
nothing listens on 25 any more. The receiver stays installed and keeps every
message it stored; the outbound gateway, OpenDKIM and every queue are never
touched. It works whatever the bundle requests, and on a host already closed it
changes nothing.

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
2. **An isolated receiver, proved locally** — the separate Postfix instance, its
   installer, verifier and store, rendered from the inbound plan and installed
   with public SMTP still disabled; every requirement above proved against it
   on the host without a public listener.
3. **Guarded activation of public SMTP** — the `enabled` state, the port-25
   owner in the gateway's checks, the firewall, and a rollback that closes the
   port again. *8.4B.5.2 covers steps 2 and 3, and the operator publishes step
   4 within it.*
4. **MX records** — only after step 3 verifies: the A record of `mx1.tits.guru`
   (it may go first), then the three MX records from `render-dns`, verified
   through public resolvers.
5. **An external test message to `support@tits.guru`** — sent from an outside
   mailbox, received, stored, and nothing else accepted.
6. **Bounce reception and correlation** — the Return-Path moves to
   `b-<identifier>@bounce.tx.tits.guru`, delivery status notifications are
   received and matched to the message they report on. Moving the envelope
   sender to the bounce domain brings its own DNS: that domain needs an SPF
   record of its own, and with `aspf=s` SPF no longer aligns with the `From`
   domain, so DMARC then rests on DKIM (`d=tits.guru`, `adkim=s`) alone. *8.4B.5.3.*
7. **Replies** — `Reply-To` addresses on the reply domain, replies received and
   matched to their conversation, and the support mailbox. *8.4B.5.4.*
8. **Storage and processing** — the handlers read what the receiver stored and
   hand it to the application's storage and processing, idempotently.
   *8.4B.5.3 and 8.4B.5.4.*
9. **Security and recovery** — the real-host acceptance from outside (relay,
   AUTH and limit probes, the external messages), and recovery on a replacement
   host with an inbound fence like the outbound one. *8.4B.5.5.*

| Slice | What | State |
|-------|------|-------|
| 8.4B.5.1 | Inbound contract and DNS plan | **Completed** |
| 8.4B.5.2 | Isolated inbound SMTP receiver and guarded activation | **Implemented in repository**, awaiting real-host activation and acceptance |
| 8.4B.5.3 | Bounce reception and correlation | Planned |
| 8.4B.5.4 | Reply routing and the support mailbox | Planned |
| 8.4B.5.5 | Real-host acceptance and recovery proof | Planned |

Inbound mail is not working until a message from outside has actually been
received.

## What the operator prepares before Activate

- **The receiver's public IPv4 address** — on today's shared host, the address
  its route to the Internet leaves from, which outbound mail uses too — and
  **inbound TCP 25 allowed by the provider's firewall** outside the host: if
  the provider filters traffic in front of the VPS — for Contabo, a firewall
  configured in its customer control panel — it needs an allow rule for TCP 25
  to that address. The host cannot see it; step 7 below proves it from
  outside.
- **The host firewall**: ufw active, or no inbound filtering at all — any other
  filtering is refused, and the operator decides how to change it.
- **The TLS certificate** for `mx1.tits.guru`, installed and renewing as
  [above](#tls-for-mx1titsguru).
- **Disk**: free space for the 1 GiB store plus the 2 GiB host reserve.
- **DNS access** to publish the A record and then the MX records, by hand, in
  that order — and to remove them again if public SMTP is ever rolled back.
- **An outside mailbox** for the external test message.

## What this does not do

It does not process received mail: no DSN parsing, no bounce correlation, no
`Reply-To`, no support inbox, no suppression, no Laravel model — those are
8.4B.5.3, 8.4B.5.4 and 8.4B.6. It changes no gateway configuration, OpenDKIM
setting, DKIM key, routing policy, `mail-outbound.json`, lifecycle, environment
file, application setting, backup schedule, deploy, GitHub secret or DNS record.
It sends no mail, answers no message, and never prints one.
