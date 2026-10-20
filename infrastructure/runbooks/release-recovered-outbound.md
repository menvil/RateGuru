# Releasing a recovered machine's outbound mail

After a clean-host recovery of a target whose mail goes out directly —
`tits-guru` — the replacement machine holds every message that target sends.
Its listener accepts the message with its usual restrictions and signs it,
then puts it on Postfix's HOLD queue. No direct-delivery route exists at all.
This runbook is how that hold ends: once, deliberately, after the machine has
been adopted and the public DNS names it.

The mechanism is `infrastructure/scripts/release-recovered-outbound`, run by
the workflow **Release recovered tits.guru outbound mail**. The hold itself is
defined once, in `infrastructure/scripts/recovery-outbound-hold`.

---

## Why the hold exists

A recovered machine is a complete mail host that believes it is `tits-guru`.
It has the target's real DKIM key (the backup carries it) and the reviewed
policy delivers the target's mail directly. But until DNS moves, the A record
of `mta1.tits.guru`, the PTR of the sending address and the SPF policy still
describe the machine it replaces. Mail sent from the new address before then
would fail SPF and reverse DNS at every receiver, signed with the real key —
the fastest way to damage the domain's reputation.

So the machine's outbound mail is held from before its mail gateway is
installed until the operator releases it.

## The life of the hold

| Stage | What happens to the hold |
|---|---|
| Recovery preparation (`prepare-host --recovery-backup`) | Placed for the recovered target if its reviewed mail goes out directly, after the runtime slice and before host bootstrap installs Postfix. A re-run of the same recovery keeps it; one for another backup, a released one or an unreadable one stops the preparation. |
| Mail gateway (`install-mail-gateway`) | While it is held, the gateway renders, verifies and records the target's mail held, with no direct route, whatever the repository requests. Every Prepare, Repair, bootstrap and verify converges to held. |
| `recover-host --apply` | Binds it to the recovery operation before the backup is downloaded. A failed apply that leaves the machine as prepared takes the binding back. |
| `recover-host --inspect`, `--resume` | Refuse a hold bound to another operation. `--resume` puts a missing hold back. |
| `recover-host --verify` | Reports `OUTBOUND MAIL: HELD`; the production recovery workflow requires it. |
| `release-recovered-outbound --apply` | Releases it, behind the full proof below. |

The marker is `/var/lib/rateguru-mail-gateway/recovery-outbound-holds/tits-guru.json`,
root-only `0600` in a `0700` directory:

```json
{
  "backup": "20261020-030000",
  "hold": "recovery-outbound",
  "operation": "20261020-120000-abcdef",
  "placed_at": "2026-10-20T12:00:00Z",
  "released_address": null,
  "released_at": null,
  "schema_version": 1,
  "state": "held",
  "target": "tits-guru"
}
```

It is a separate fence from the offsite-write hold, and nothing ever deletes
it: once released it stays as the machine's record of when and for which
address. An ordinary Prepare of an existing host never places one.

**Removing the marker by hand releases nothing.** The gateway recorded the held
state; with the marker gone, the next apply sees the reviewed policy asking to
cross from held to outbound and refuses it at the activation boundary, which
only a one-use authorization crosses. Verify reports the difference. Mail stays
held.

**A marker that is not exactly the document above** — wrong owner or mode, a
symlink, a field too many or too few, another target, anything else in its
directory — is refused by every reader: the gateway renders nothing, a
preparation, a recovery and a release stop. It is never read as absent and
never repaired by any tool.

---

## When to release

Only after all of these, in this order:

1. The recovery finished: the run summary of **Recover tits.guru host** shows
   `RECOVERED: YES`, health PASS, offsite writes HELD and outbound mail HELD.
   Note its **recovery operation** ID.
2. The application was checked on the replacement machine while its mail was
   held (see [`clean-host-recovery.md`](clean-host-recovery.md) §I).
3. The machine was adopted: traffic moved to it, and the production GitHub
   Environment's `DEPLOY_HOST`, `DEPLOY_PORT`, `BOOTSTRAP_USER`,
   `BOOTSTRAP_SSH_KEY` and `BOOTSTRAP_KNOWN_HOSTS` now name it.
4. DNS names it, as two independent public resolvers see it:
   - `A mta1.tits.guru` is the machine's sending IPv4 address;
   - the PTR of that address is `mta1.tits.guru` (and its forward lookup
     confirms it);
   - the `tits.guru` SPF policy authorizes that address;
   - `rg1._domainkey.tits.guru` and `_dmarc.tits.guru` are unchanged.

The release is run before anything new is deployed to the machine and before
its offsite-write hold is removed: its proof asks `recover-host --verify`,
which requires both.

## How

Actions → **Release recovered tits.guru outbound mail** → Run workflow, from
`main`:

```text
mode       = check
operation  = <the recovery operation ID from the recovery's summary>
address    = <the machine's sending IPv4 address>
confirm    = (empty)
```

`check` changes nothing and lists every condition. When it says READY:

```text
mode       = apply
operation  = <the same operation>
address    = <the same address>
confirm    = release tits-guru outbound mail on <the same address>
```

The confirmation must be typed exactly; anything else is refused before any
credential is used. Afterwards, `mode=verify` (no operation, address or
confirmation) proves the result.

## What the release proves before it changes anything

All of it, every time, under the host's infrastructure lock:

| Condition | How |
|---|---|
| The trusted bundle delivers `tits-guru`'s mail directly | the bundle's own `mail-routing` and `mail-outbound.json` |
| This machine is a clean-host recovery of it | the hold exists, is sound, held, and bound to exactly the operation named |
| The recovery is complete, with no operation unfinished | `recover-host --verify`: `RECOVERED: YES`, healthy, no recovery or restore guard, the hold bound to a recovery this machine's journal records as completed |
| The gateway is exactly the held one | `install-mail-gateway --verify` |
| DNS names this machine | `mail-identity verify-dns`: A, PTR with forward confirmation, SPF, the installed key's DKIM record and DMARC, as 1.1.1.1 and 8.8.8.8 both answer them — a disagreement is a failure |
| The operator named the right host | `--address` is exactly the address the kernel's route sends from |
| The key is usable and the signer runs | `verify-dns` derives the DKIM record from the installed key; `install-mail-signing --verify --target tits-guru` |
| The operator means it | `--apply` requires `--confirm-release`, which the workflow passes only after the exact typed confirmation |

If any of it fails, the hold stays and nothing changes.

## What the release does

1. Marks the hold `released`, with the operation, the time and the proved
   address.
2. Writes the mail gateway's one-use ACTIVATE authorization, bound to the
   exact recorded and requested policies, valid for five minutes.
3. Runs `install-mail-gateway --apply`, which consumes it and crosses
   `tits-guru` from held to outbound.
4. Requires the gateway's own `--verify` and `mail-identity readiness` with
   every condition PASS and OUTBOUND READY: YES.

Any failure from step 1 on — a refusal, a verify short of PASS, an error, a
signal — puts the hold back to held and, if the gateway already crossed,
returns it to held through its own ROLLBACK authorization. If even that cannot
be proved, the result is CRITICAL and nothing broader is attempted.

**Killed outright** between steps 1 and 3: the hold is released but the
gateway still holds the mail. Every ordinary apply then refuses to cross, so
nothing leaves. Run `check` (it reports the interrupted release) and `apply`
again: it puts the hold back to held and releases again behind the full proof.
Killed after step 3: the next `apply` finds the release done and accepts it as
the no-op it is, or refuses if the machine does not deliver as the release
left it.

`apply` is repeatable: on a released, ready machine it changes nothing.

## What it never does

- It changes no DNS record, no `DEPLOY_HOST`, no Nginx site, no environment
  file and no offsite-write hold, and runs no GO LIVE.
- It sends no mail and opens no SMTP connection.
- It never releases the HOLD queue.

## The mail held during the recovery

Whatever the application sent while the hold stood is still on HOLD after the
release; the release reports how many messages that is. Nothing releases it
automatically, and releasing it is not the default: a held message carries no
route of its own, so `postsuper -H` would hand it to the gateway's error
transport, not deliver it. Decide explicitly, on the machine, as root:

```bash
postqueue -j | jq -c 'select(.queue_name == "hold") | {queue_id, sender, recipients: [.recipients[].address], arrival_time}'
postcat -hq <QUEUE_ID>     # the headers of one message
postsuper -d <QUEUE_ID> hold   # remove one, once it is not to be sent
```

Mail that should still go out is sent again by the application, through the
now-released route.

## See also

- [`clean-host-recovery.md`](clean-host-recovery.md) — the recovery itself
- [`recover-host.md`](recover-host.md) — the server-side state machine
- [`mail-gateway.md`](mail-gateway.md) — the gateway and its activation boundary
- [`mail-outbound-activation.md`](mail-outbound-activation.md) — the original
  held-to-outbound activation of a planned target
