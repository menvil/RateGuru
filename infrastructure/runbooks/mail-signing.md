# Mail signing

The host-global DKIM signer: one OpenDKIM daemon that signs each production
target's mail with that target's own key, and the gateway listeners that hand
their mail to it. Who a target's mail claims to be — its DKIM selector, its key
and the DNS that publishes it — is [`mail-identity.md`](mail-identity.md); the
gateway the signer sits behind is [`mail-gateway.md`](mail-gateway.md).

Signing is not delivery. A signed message on a held listener is still held:
nothing here routes, releases or sends any mail.

## Status

| What | State |
|------|-------|
| `install-mail-signing` / `verify-mail-signing` | **Implemented**, part of host bootstrap (after mail capture, before the gateway) |
| `mail-identity render-signing-plan` | **Implemented**: the one source of what is signed |
| Gateway milter on `tits-guru`'s held listener (`127.0.0.1:2526`) | **Implemented**; the staging capture listener is never signed |
| `mail-identity readiness` signing condition | **Implemented**: the verdict of `verify-mail-signing --read-only` |
| Signer on the shared production/staging host | **Not installed yet** — the next **Prepare staging host** after merge installs it |
| Live acceptance (**Verify production mail signing**) | **Pending** — run after promotion to `main` |
| `tits-guru` mail | **Held**; `lifecycle=planned`; direct delivery disabled; no production `MAIL_*` value |

## What is installed

```
tits-guru (not deployed) ─▶ 127.0.0.1:2526 ──▶ Postfix smtpd ──milter──▶ OpenDKIM 127.0.0.1:8891
                                                     │                   (signs d=tits.guru s=rg1)
                                                     ▼
                                               HOLD queue → no route, ever

Laravel staging ──────────▶ 127.0.0.1:2525 ──▶ Postfix smtpd (no milter) → capture
```

One OpenDKIM daemon for the whole host, Ubuntu's own `opendkim` package and its
own `opendkim.service`, owned end to end by
`infrastructure/scripts/install-mail-signing`:

- **What is signed comes from one place.** `mail-identity render-signing-plan`
  lists every production target with a reviewed identity — held ones included,
  so the signer is ready before any mail is allowed out — with its mail domain
  (from `mail-routing render-plan`), its DKIM selector, algorithm and minimum
  key size (from `mail-identity.json`) and the canonical path of its key. The
  installer renders that plan into OpenDKIM syntax and restates none of it; no
  caller chooses a domain, selector or key.
- **Signing only.** `Mode s`: it never verifies, and needs no DNS of its own.
- **Exact domains.** `SigningTable` maps each reviewed mail domain exactly —
  no wildcard, no subdomain — to its `KeyTable` entry, which names the
  target's own key. Mail for any other domain passes unsigned, and is routed by
  nobody but the gateway.
- **Loopback only.** `TrustedHosts`: only mail from `127.0.0.1` is signed; the
  milter listens on `127.0.0.1:8891` and nowhere else — no other address, no
  IPv6, no UNIX socket. `install-mail-signing --milter-endpoint` prints that
  endpoint (`inet:127.0.0.1:8891`), and the gateway wires exactly it. No
  setting or option moves it.
- **Never as root.** The daemon runs as the package's `opendkim` account and
  refuses a key anybody else could read (`RequireSafeKeys`).
- **`rsa-sha256`**, the one algorithm the identity contract admits.

The managed files, `root:root 0644`, byte-for-byte functions of the plan:

```
/etc/opendkim.conf
/etc/opendkim/KeyTable
/etc/opendkim/SigningTable
/etc/opendkim/TrustedHosts
```

With no reviewed production identity in the plan, every mode is a no-op: no
package is installed, and nothing is verified.

## The keys: signer-readable, by metadata alone

Each target's private key is target material, installed by
`install-target-prerequisites` (see [`mail-identity.md`](mail-identity.md)).
The signer reads it; nobody else can:

```
/etc/opendkim/keys                     root:opendkim 0750
/etc/opendkim/keys/<target>            root:opendkim 0750
/etc/opendkim/keys/<target>/<sel>.private  root:opendkim 0640
```

A key installed before any signer existed — `tits-guru`'s, today — is
root-only: `root:root 0600` in `root:root 0700` directories. That layout, and
only that one, is the migration source. `install-mail-signing --apply`:

1. derives the key's path from the signing plan;
2. runs `mail-identity check-key` on it — an unencrypted RSA private key of at
   least the reviewed size;
3. only then changes the owner, group and mode of the two directories and the
   key.

It never reads, prints, hashes, measures, copies or rewrites the key: the file
is the same file, byte for byte. Any other layout — a world- or group-writable
key, another owner, a directory anyone can enter, a symlink — is a
**CONFLICT**, and nothing is changed. The `opendkim` account and group are the
package's; nothing here creates either, and the group must hold no other
account. A held target's absent key is **DEFERRED**; an outbound target's is
**MISSING**.

## Ownership and the package

Before the package is installed an ownership marker is written to
`/var/lib/rateguru-mail-signing/ownership`. An OpenDKIM on a host without that
marker — the package, or an `/etc/opendkim.conf` — is not RateGuru's, and every
mode fails closed on it: it is never taken over, rewritten or removed. A marker
in `state=installing` is an interrupted installation RateGuru started, and
`--apply` resumes it.

The package goes in with a `policy-rc.d` in place, so its maintainer scripts
start nothing; the host's own `policy-rc.d` is preserved and put back. The
rendered configuration is judged by OpenDKIM's own parser (`opendkim -n`) —
against candidate tables, before anything is installed — and again once it is
in place, and the service is started only after that. A package this installer
installed is never removed again, as a rollback or otherwise.

`--apply` is a transaction: it records the managed files, the key metadata and
the service's state, and puts all three back if anything fails before it
commits. Backups hold configuration only, under
`/var/backups/rateguru-mail-signing/<timestamp>/` — never a key.

## Installation

Host bootstrap installs the signer, between mail capture and the gateway:

```
install-bootstrap-services --apply
  … mail capture
  → install-mail-signing --verify   (skip) | --apply
  → install-mail-gateway --verify   (skip) | --apply   — wires the signed listeners
  … base services
```

Its `--check` runs before the host's first mutation, so an identity that does
not render, an installed key that is not a usable one or sits in an unknown
layout, or an OpenDKIM RateGuru does not own stops the whole run with nothing
changed. A target-scoped repair, provisioning or configuration run never
touches the signer: no target owns it.

By hand, on the host, from a trusted bundle:

```bash
sudo infrastructure/scripts/install-mail-signing --check
sudo infrastructure/scripts/install-mail-signing --apply
sudo infrastructure/scripts/install-mail-signing --verify
sudo infrastructure/scripts/install-mail-signing --verify --target tits-guru
infrastructure/scripts/install-mail-signing --milter-endpoint
```

## Verification

`install-mail-signing --verify` is the signer's contract: the package is
RateGuru's, the four files are exactly the current render and OpenDKIM accepts
them, `opendkim.service` is enabled, stably running as `opendkim`, unmodified by
drop-ins, and listening on exactly `127.0.0.1:8891`, and every installed key is
valid, signer-ready and actually readable by `opendkim` (it reads one byte into
nothing). With `--target T` it is that contract for one target, whose key must
be installed.

`verify-mail-signing --read-only --target T` composes the owners and restates
none of them:

1. `mail-identity render-signing-plan` — T has a reviewed signing identity;
2. `install-mail-signing --verify --target T`;
3. `install-mail-gateway --verify` — T's listener hands its mail to the signer
   and defers it without one; no other listener names it.

It changes nothing, and it is what `mail-identity readiness` asks for its
`signing` condition. Once the signer is installed, **Verify production
infrastructure** shows, for `tits-guru`:

```
PASS target, mta, identity, key, host, address, a, ptr, spf, dkim, dmarc, signing
FAIL routing   — still held
FAIL direct    — still disabled
OUTBOUND READY: NO
```

— not ready for exactly the two reasons that keep it held, and outbound
readiness still `DEFERRED`, so the overall run passes.

## Acceptance: Verify production mail signing

The live proof that a held target's mail is really signed:

```bash
sudo infrastructure/scripts/verify-mail-signing --e2e --target tits-guru
```

and its workflow, **Verify production mail signing** (`main` only, the
`production-tits-guru` Environment, the bootstrap credential, the shared host's
`rateguru-staging-deployment` concurrency domain; no inputs). It:

1. refuses — before any SMTP connection — unless the target's mail is `held`
   with no route, so its message can go nowhere but the hold queue;
2. proves the read-only contract above, and submits nothing if it fails;
3. submits exactly one synthetic message, from the target's reviewed sender, to
   a recipient under the reserved `.invalid` domain, through the target's own
   loopback listener;
4. takes the exact queue ID from the gateway's reply, and requires that entry
   in **HOLD**;
5. reads only that entry's headers, and requires exactly one `DKIM-Signature`
   with the target's domain (`d=`), selector (`s=`) and algorithm (`a=`), a
   body hash and a signature, over at least `From`;
6. still held, deletes that exact entry, and requires it gone.

It never flushes, empties or releases the queue, never connects anywhere but the
target's listener, prints no message and no signature value, and removes its
own entry — by its exact ID, or by its unique recipient — on every exit. It
proves the signature's shape and identity; cryptographic verification by
receiving servers is the activation canary's to prove, against the public DKIM
record `verify-dns` already checks.

Expected result:

```
PASS tits-guru is signed as d=tits.guru s=rg1 a=rsa-sha256
PASS the signer / the gateway's wiring
PASS Postfix queued the probe as <ID>
PASS queue entry <ID> is in the HOLD queue
PASS the probe carries exactly one DKIM-Signature: d=tits.guru s=rg1 a=rsa-sha256
PASS queue entry <ID> stayed held until it was deleted, and is gone
SIGNING E2E: PASS
```

## Rollout after merge

1. **Prepare staging host** (from `develop`). The shared host's services
   change, so it must converge: it installs the RateGuru-owned OpenDKIM,
   converges its configuration, grants the signer read access to the
   already-installed `tits-guru` key without changing its bytes, and re-renders
   the gateway with the milter on `tits-guru`'s held listener. `tits-guru` stays
   held, direct delivery stays disabled.
2. **Verify staging infrastructure**: every group PASS, Mail identity `N/A`.
3. Promote `develop` to `main` through the normal pull request.
4. **Verify production infrastructure**: readiness as above — `signing PASS`,
   `OUTBOUND READY: NO` for `routing` and `direct` only — and the overall run
   PASS.
5. **Verify production mail signing**: `SIGNING E2E: PASS`.

Only then is the signing foundation production-accepted.

## What this does not do

No change of `tits-guru` from `held`, no `direct.enabled`, no production
`MAIL_*` value, no release of held mail, no external canary, no lifecycle
change, no deploy, no public SMTP port, no DNS, PTR, SPF, DKIM or DMARC record,
and no key generation. It never writes `/etc/postfix/main.cf` or `master.cf` —
those are the gateway's — and never touches `/etc/hosts`, the host's resolver or
its hostname. Activation is a separate, later slice.

## Troubleshooting

- **`CONFLICT ownership — an OpenDKIM RateGuru did not install`**: an
  `opendkim` package or `/etc/opendkim.conf` exists without the marker. Find out
  whose it is; RateGuru never takes it over.
- **`CONFLICT key:<target> — … neither root:opendkim 640 nor the pre-signer
  root:root 600`**: the key, or a directory above it, has access nobody
  reviewed. Restore one of the two layouts by hand, after checking who changed
  it; nothing re-permissions it automatically.
- **`CONFLICT key-access:<target>`**: the `opendkim` account cannot read the
  key — usually a directory between it and `/etc/opendkim` without the group.
- **`CONFLICT runtime — the signer also listens on …`** or **`… carries drop-in
  overrides`**: something changed how the package's unit runs. Remove the
  drop-in and re-run `--apply`.
- **`451 4.7.1` on `tits-guru`'s listener**: the signer is down or cannot read
  the key; the gateway is deferring rather than accepting unsigned mail, as it
  should. `install-mail-signing --verify` names the cause.

## Test overrides

Every `RATEGURU_MAILSIGN_*` variable is honoured only alongside
`RATEGURU_ALLOW_TEST_OVERRIDES=true`, the gate every installer here uses:
`RATEGURU_MAILSIGN_FS_ROOT` prefixes every host path, and the `*_BIN` variables
replace the package manager, systemd, `ss`, `opendkim`, `ps`, `runuser`, `stat`,
`chown` and the queue tools with test doubles. Nothing overrides the milter
endpoint.
