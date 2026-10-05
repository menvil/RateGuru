# Mail gateway

The host-global Postfix gateway every target submits its mail to, rendered from
the reviewed mail routing policy. The policy, and why the submission port is a
target's identity, are in [`mail-routing.md`](mail-routing.md); this runbook is
how the gateway is installed, verified, accepted and cut over to.

## Status

| What | State |
|------|-------|
| `install-mail-gateway` / `verify-mail-gateway` / `status-mail-gateway` | **Implemented**, part of host bootstrap |
| Gateway on the staging host | **Not installed yet** — the next Prepare staging host installs it |
| Real-host acceptance (`verify-mail-gateway --e2e`) | **Not run yet** |
| Staging application mail | **Still direct to Mailpit** (`MAIL_PORT=1025` in the host's `shared/.env`) until the operator cutover below |
| `tits-guru` | `lifecycle=planned`; its listener exists and **holds** everything; nothing is delivered |
| Production outbound delivery | **Does not exist** |

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
  domain and places it on Postfix's hold queue. Nothing names a route for it:
  no Mailpit, no other target's transport, no relayhost, no DNS delivery. A
  held message released by hand still has nowhere to go — it bounces into the
  error transport.
- **Fail closed.** `default_transport`, `relay_transport`, `local_transport`
  and `virtual_transport` are all `error:`; `relayhost`, `mydestination`,
  `relay_domains` and `transport_maps` are empty; there is no generic `smtp`,
  `relay`, `local`, `virtual` or `lmtp` delivery agent. Mail with no rendered
  route — a local `sendmail`, a cron report, anything — is undeliverable. There
  is no Internet delivery in this gateway at all.
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

## Ownership and the package

- `--apply` installs Ubuntu 22.04's `postfix` package with debconf preseeded
  **Local only**, IPv4, no relayhost, and with a `policy-rc.d` in place for the
  installation so the maintainer scripts start nothing. A `policy-rc.d` that
  already existed is preserved and put back.
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
unclassified mail cannot be delivered. No SMTP, no queue change, no reload.

`--e2e` (root) runs all of that, then, for every listener in the plan:

- **A — capture:** a message from the listener's own domain is queued by
  Postfix and reaches Mailpit (canonical) and Mailtrap Local (mirror);
- **B — sender isolation:** a foreign domain, and every other listener's
  domain, is refused at `MAIL FROM`;
- **C — held:** a message from `noreply@tits.guru` is accepted, its exact queue
  entry is in HOLD, it is not in Mailpit, and that one entry is then removed;
- **D — outage:** Mailpit is stopped, a staging message is accepted and
  deferred, Mailpit is started again, that exact message is retried
  (`postqueue -i <id>`) and reaches Mailpit and its mirror.

It removes only what it created — its Mailpit/Mailtrap messages by their unique
token, its queue entries by their exact queue ID — and never flushes or empties
the queue. Mailpit is put back the way it was on every exit.

From GitHub: the **Verify staging mail gateway** workflow runs exactly
`verify-mail-gateway --e2e` on the staging host from a temporary trusted bundle
(develop only, the `staging` Environment, the bootstrap credential, the
`rateguru-staging-deployment` concurrency group). It never edits an environment
file and never deploys. Ordinary Prepare never runs the mutating acceptance.

## Status

```bash
sudo infrastructure/scripts/status-mail-gateway
```

Read-only: package and version, ownership marker, service state, listeners,
each listener's route as Postfix reads it from the installed configuration,
queue counts by queue (counts only — no address, no body), and the last hour
of gateway warnings. Logs: `journalctl -u postfix@-.service`.

## Staging cutover

The committed staging environment contract now names the gateway
(`MAIL_PORT=2525` in `staging.env.example`). The host's own `shared/.env` is
operator-owned and **is never changed by code** — the cutover is an operator
action, in this order:

1. **Prepare staging host** — installs and verifies the gateway.
2. **Verify staging mail gateway** — the end-to-end acceptance passes.
3. The operator changes the host's `shared/.env`:
   `MAIL_PORT=1025` → `MAIL_PORT=2525` (`MAIL_MAILER=smtp`,
   `MAIL_HOST=127.0.0.1` and `MAIL_FROM_ADDRESS=noreply@staging.invalid` are
   unchanged).
4. **Deploy to staging** again, so deploy's `artisan config:cache` reads the new
   value.
5. Trigger one real application-generated staging email.
6. Confirm it appears in Mailpit and in Mailtrap Local.

**Rollback:** set the host's `shared/.env` back to `MAIL_PORT=1025` and deploy
staging again. The application then bypasses the gateway and returns to the
previously accepted direct-to-Mailpit path; the gateway can stay installed.

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
- **Never** run `postqueue -f`, `postsuper -d ALL` or `postsuper -H ALL` to
  "fix" a held queue: held mail has no route by design.
