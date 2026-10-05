# Mail routing

The reviewed contract for where each deployment target's mail goes once it
leaves the application, and the repository tooling that proves the contract.

## Status

| What | State |
|------|-------|
| Routing policy `infrastructure/config/mail-routing.json` | **Implemented**, reviewed |
| `infrastructure/scripts/mail-routing validate` / `render-plan` | **Implemented**; repository tooling, run from the trusted bundle, never installed on a host |
| Local mail gateway | **Implemented** as committed host infrastructure — `install-mail-gateway`, converged by Prepare Host; see [`mail-gateway.md`](mail-gateway.md) |
| Gateway on the real staging host | **Not installed or accepted yet** — the next Prepare staging host installs it, then the operator acceptance runs |
| Staging application mail | **Still direct**: the host's `shared/.env` says `MAIL_PORT=1025` (Laravel → Mailpit → Mailtrap Local) until the operator cutover |
| Production mail (`tits-guru`) | **Held**: identity reviewed, its listener holds everything, no route exists, nothing is delivered |

The policy is the contract; `install-mail-gateway` turns its rendered plan into
Postfix configuration and never re-derives a rule of its own. It was written
first so that the routing rules were reviewed before any mail transfer agent was
allowed onto a host.

## Architecture

An application never decides where its mail goes. It submits every message to
a local gateway, and infrastructure decides — per target — whether that mail is
captured or delivered:

```
  application of target T
        │  SMTP to 127.0.0.1:<T's own submission port>
        ▼
  ┌────────────────────────────────────────────────┐
  │ local mail gateway (loopback only)             │
  │   identity  = the port the message arrived on  │
  │   sender    = checked against T's identity     │
  └───────────────────────┬────────────────────────┘
                          │  T's delivery mode
              ┌───────────┴────────────┐
              ▼                        ▼
          capture                    held
   loopback capture service     no route at all
   (staging targets)            (production, until its
                                 outbound transport exists)
```

For the two targets in the registry today:

```
staging-main  127.0.0.1:2525 ──capture──▶ Mailpit 127.0.0.1:1025 ──mirror──▶ Mailtrap Local
tits-guru     127.0.0.1:2526 ──HELD────▶ (nothing: no route exists)
```

The application side is identical for every target: `MAIL_MAILER=smtp`,
`MAIL_HOST=127.0.0.1` and the target's own `MAIL_PORT`. Whether a target is
capture-only or really delivering is decided by its policy, never by a
different mail configuration in the application. A future production target
uses exactly this mechanism, with no target-specific code: it gets a registry
entry, a reviewed policy and its own port.

## Why the submission endpoint is the target's identity

Each target submits on a loopback port that belongs to it alone, so the port a
message arrived on says unambiguously which target sent it:

```
127.0.0.1:2525  →  staging-main
127.0.0.1:2526  →  tits-guru
```

The sender domain is **not** the selector. A `From` header is whatever the
client wrote, and a target chosen by it is a target chosen by the client: one
application could claim another's domain and be routed as that target. The
sender domain is a second, independent contract the gateway enforces **on top
of** the port — a message arriving on `127.0.0.1:2526` must also carry the
`tits.guru` identity — never instead of it.

That is why every submission port is unique across targets, and why every
listener is on `127.0.0.1`: one address for all of them, so the port alone is
the identity.

## Where the policy lives

`infrastructure/config/deployment-targets.json` stays authoritative for a
target's ID, lifecycle, environment class and application identity. The mail
policy never repeats any of them; `mail-routing` reads them from the registry.

`infrastructure/config/mail-routing.json` (schema version 1) holds only what
the registry cannot: per target ID, the gateway endpoint, the delivery mode and
the mail identity. Every target in the registry has exactly one policy, and
every policy belongs to a target in the registry — the same reviewed-file
pattern as `backup-schedules.json`.

| Property | Mode | Meaning |
|----------|------|---------|
| `submission.host` | both | Always `127.0.0.1` |
| `submission.port` | both | The target's own gateway port: an integer from 1024 to 65535, unique across targets |
| `delivery_mode` | both | `capture` or `held` |
| `allowed_from_domain` | capture | The only sender domain accepted; under the reserved `.invalid` TLD |
| `capture.host` / `capture.port` | capture | The loopback capture service the mail goes to |
| `mail_domain` | held | The target's production mail domain |
| `default_from` | held | The target's default sender, an address at `mail_domain` |
| `bounce_domain` | held | Where bounces will be received; a subdomain of `mail_domain` |
| `reply_domain` | held | Where replies will be routed; a subdomain of `mail_domain` |

## Delivery modes: capture and held

The set is closed, and each mode exists because a target uses it. A new mode is
added by the change that implements it — never ahead of it as an unused
placeholder, because a value the validator accepts is a value somebody can
configure.

- **`capture`** — the mail is handed to a loopback capture service and never
  leaves the host. It is the only mode a staging target may use, and its sender
  domain must be under the reserved `.invalid` TLD, so captured mail can never
  carry a deliverable identity. `staging-main` captures into the existing
  Mailpit on `127.0.0.1:1025`, which mirrors to Mailtrap Local exactly as
  [`mail-capture.md`](mail-capture.md) describes.
- **`held`** — the production identity has been reviewed and a gateway endpoint
  is reserved, but **no route exists**. It is the only mode a production target
  may use until its outbound transport has been implemented and reviewed.

`held` is not a disguised `capture`. A production target may not use `capture`
at all — that would send production mail into the staging capture service — and
a held policy may not declare any destination (`capture`, `relay`, `outbound`,
`transport` or any other).

### Why held can never deliver

A held policy has no destination property, so there is nothing to deliver to;
its rendered plan has `"route": null`. And a held target may not be
`lifecycle=active`: an active target whose mail can never leave is a live site
silently losing its mail. `tits-guru` is held and planned, and the validator
refuses to accept it as active until its policy names a real route — which can
only happen in the change that implements outbound delivery.

## Validation and the rendered plan

```bash
infrastructure/scripts/mail-routing validate
infrastructure/scripts/mail-routing render-plan
```

Both are read-only and write nothing. Both default to the committed policy and
registry; `--file PATH` and `--registry PATH` point them at others. `validate`
reports every problem it finds and exits non-zero if any remain; `render-plan`
runs the same validation first and prints nothing when it fails. The script is
repository tooling: it is not in `required-clis.txt` and is never installed on a
host.

`validate` proves, among other things:

- `schema_version` is exactly 1, and the top-level shape and every policy's
  shape are closed — an unknown, missing or misplaced property is refused;
- the file is one JSON document, with no control characters and no key declared
  twice (a JSON parser silently keeps only the last of two);
- the registry itself passes `targets validate`;
- every policy names a registry target, and every registry target has exactly
  one policy;
- target IDs match the registry's closed format and reach jq only as data;
- every submission host is exactly `127.0.0.1` and every submission port is
  unique across targets;
- the delivery mode is `capture` or `held`, and the one the target's
  environment class allows: `capture` for staging, `held` for production;
- a capture destination is an IPv4 loopback address and is not a gateway
  endpoint — its own (a loop) or another target's (resubmission as that
  target);
- a held policy declares no destination, and a held target is not active;
- domains and addresses are lowercase and syntactically safe, `default_from`
  is at `mail_domain`, bounce and reply domains are subdomains of it, and no
  production identity domain is shared between targets or is under `.invalid`;
- no property name anywhere looks like a credential.

`render-plan` prints, as JSON with sorted keys and one listener per target in
target order, what a gateway would do:

```json
{
  "listeners": [
    {
      "delivery_mode": "capture",
      "environment_class": "staging",
      "identity": "staging-main",
      "lifecycle": "active",
      "listen": { "host": "127.0.0.1", "port": 2525 },
      "route": { "host": "127.0.0.1", "kind": "capture", "port": 1025 },
      "sender": { "allowed_domain": "staging.invalid" }
    },
    {
      "delivery_mode": "held",
      "environment_class": "production",
      "identity": "tits-guru",
      "lifecycle": "planned",
      "listen": { "host": "127.0.0.1", "port": 2526 },
      "route": null,
      "sender": {
        "allowed_domain": "tits.guru",
        "bounce_domain": "bounce.tx.tits.guru",
        "default_from": "noreply@tits.guru",
        "reply_domain": "reply.tits.guru"
      }
    }
  ],
  "schema_version": 1
}
```

The plan is deterministic — the same policy renders the same bytes, however the
file is ordered — and holds only addresses, ports, domains and closed
vocabulary: no shell command, no path and no secret. It is the one input the
gateway installer renders Postfix from; the plan itself installs nothing.

## Environment contract: current and future values

Laravel's `smtp` mailer (`config/mail.php`) already reads `MAIL_HOST`,
`MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME` and `MAIL_PASSWORD`, and the sender
from `MAIL_FROM_ADDRESS`. Moving a target onto the gateway is therefore an
environment change only — no application change is needed.

### The committed staging template names the gateway

The reviewed desired endpoint, generated into
`infrastructure/templates/environment/staging.env.example` from
`environment-contract.json`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_FROM_ADDRESS=noreply@staging.invalid
```

### CURRENT runtime values (what the staging host runs today)

The host's own `shared/.env` is operator-owned and **never written by code**. It
still submits **directly to Mailpit**:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@staging.invalid
```

Deploy compares a host's `.env` with the template by key, never by value, so
both run. The operator moves the host to `MAIL_PORT=2525` only after the gateway
is installed and its acceptance has passed — the ordered cutover, and its
rollback, are in [`mail-gateway.md`](mail-gateway.md#staging-cutover).

### FUTURE gateway values (not set — tits-guru has no route yet)

`tits-guru`, once it has a delivery route and is activated:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2526
MAIL_FROM_ADDRESS=noreply@tits.guru
```

Its template keeps `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` and
`MAIL_FROM_ADDRESS` empty: the target is planned, not deployed, and its listener
holds everything it accepts, so naming it as a working endpoint would claim a
delivery path that does not exist.

Two `2525`s elsewhere in the repository are unrelated framework defaults:
`config/mail.php` falls back to `env('MAIL_PORT', 2525)`, and the root
`.env.example` sets `MAIL_PORT=2525` beside `MAIL_MAILER=log` for local
development. That they match staging's gateway port is a coincidence: every
deployed template sets `MAIL_PORT` explicitly.

## Adding a target

Every target in the registry needs exactly one policy, so a new target's policy
is reviewed alongside its registry entry:

1. Give it a submission port of its own: unprivileged, and unused by any other
   target and by any service already listening on the host.
2. A production target starts `held`, with its `mail_domain`, `default_from`,
   `bounce_domain` and `reply_domain`. A staging target uses `capture`.
3. Run `infrastructure/scripts/mail-routing validate` and fix every reported
   problem.

No code changes: nothing in `mail-routing` names a target, a domain or a port.

## Security model

- **Loopback only.** Every gateway endpoint is `127.0.0.1`, and every capture
  destination is in `127.0.0.0/8`. There is **no public SMTP listener**: the
  gateway binds exactly the plan's loopback endpoints and has no smtp,
  submission or smtps listener, nothing listens on 25, 465 or 587, and no
  firewall rule or Nginx `stream` block exists for mail.
- **Submission ports are unprivileged.** A gateway endpoint is never on a port
  below 1024, so it can never be confused with a well-known public SMTP port.
- **No secret in the routing policy.** Its shape is closed and every value has
  a closed format — addresses, ports, domains, a mode — so there is nowhere a
  credential could go, and a property named like one (`password`, `token`,
  `secret`, `auth`, `username`, …) is refused outright. Credentials for a
  future outbound transport will live on the host, never in this file.

## What comes next

The roadmap orders this work.

1. **The local gateway, with staging routed through it** (ROADMAP 8.4B.2) —
   implemented: the gateway is committed host infrastructure and the staging
   template names `127.0.0.1:2525`. Its real-host acceptance is the operator
   sequence in [`mail-gateway.md`](mail-gateway.md#staging-cutover):

   ```
   Laravel staging → gateway 127.0.0.1:2525 → Mailpit 127.0.0.1:1025 → Mailtrap Local
   ```

   Mailpit and Mailtrap Local are unchanged by it.
2. **Production outbound delivery** (ROADMAP 8.4B.3). `held` is replaced by a
   real outbound transport for production, only once that transport is
   implemented and reviewed. The new delivery mode is added to the validator in
   that change.
3. **Mail identity and DNS signing policy** (ROADMAP 8.4B.4): SPF, DKIM and
   DMARC for `tits.guru`, TLS for the outbound connection, a bounce receiver for
   `bounce.tx.tits.guru` and reply routing for `reply.tits.guru`. No DNS record,
   key, certificate, MX or relay has been created for any of them.
