# Mail identity

Who production mail claims to be, and how a receiving server can check it: the
host's MTA identity, each production target's DKIM key and DMARC policy, the DNS
records that publish them, and the read-only proof that public DNS says exactly
that. How mail is routed is [`mail-routing.md`](mail-routing.md); the gateway
that delivers it is [`mail-gateway.md`](mail-gateway.md); the host's DKIM signer
is [`mail-signing.md`](mail-signing.md).

## Status

| What | State |
|------|-------|
| Host MTA identity (`config/mail-outbound.json`) | **`mta1.tits.guru`, reviewed — direct delivery still disabled** |
| `tits-guru` identity (`config/mail-identity.json`) | **Reviewed**: DKIM selector `rg1`, `rsa-sha256`, at least 2048 bits; DMARC `p=none`, `adkim=s`, `aspf=s`, no report address |
| `infrastructure/scripts/mail-identity` | **Implemented**: `validate`, `dkim-key`, `check-key`, `show-dns`, `verify-dns`, `readiness`, `render-signing-plan` |
| DKIM private key on the production host | **Installed and validated** at `/etc/opendkim/keys/tits-guru/rg1.private` — supplied by the operator, never generated or committed |
| Public DNS (A, PTR, SPF, DKIM, DMARC) | **Published; production verification PASS** for all five, through both public resolvers (the resolver isolation is production-accepted) |
| DKIM signing (OpenDKIM + Postfix milter) | **Implemented — production acceptance pending**: see [`mail-signing.md`](mail-signing.md). Installed by the next Prepare of the shared host; accepted by **Verify production mail signing** |
| `tits-guru` mail | **Held**; `lifecycle=planned`; direct delivery disabled; no production `MAIL_*` value is set |

Nothing here sends mail. The identity, the key, public DNS and — once the
signing foundation is installed and accepted — signing are what outbound
delivery depends on, made checkable before anything is allowed out.

## Two contracts

**The host — `infrastructure/config/mail-outbound.json`.** Every brand on a host
shares its source address, and an address has one PTR name, so the name direct
delivery greets receiving servers with belongs to the host:

```json
{
  "schema_version": 1,
  "direct": {
    "enabled": false,
    "mta_hostname": "mta1.tits.guru"
  }
}
```

The hostname is reviewed and judged — a lowercase public FQDN, never under a
reserved name such as `.invalid` or `.test` — but while `enabled` is `false` it
creates nothing: the rendered gateway is byte for byte the one staging accepted,
and the hostname appears nowhere in the Postfix configuration.

**Each production target — `infrastructure/config/mail-identity.json`.**

```json
{
  "schema_version": 1,
  "targets": {
    "tits-guru": {
      "dkim":  { "selector": "rg1", "algorithm": "rsa-sha256", "minimum_key_bits": 2048 },
      "dmarc": { "policy": "none", "adkim": "strict", "aspf": "strict" }
    }
  }
}
```

It holds the signing and DNS policy and nothing else. The mail domain, the
sender, the bounce and reply domains, the submission port and the delivery mode
stay in `mail-routing.json`, their only authority; `mail-identity` reads them
from `mail-routing render-plan` and refuses an identity that repeats any of
them. A target here must exist in the registry and carry a production identity
(held or outbound); a staging target never signs. A target that is `outbound`
must have one.

Closed vocabulary, schema 1: `algorithm` is `rsa-sha256`; `minimum_key_bits` an
integer from 2048 to 4096; `selector` one lowercase DNS label; DMARC `policy`
`none`, `quarantine` or `reject`, `adkim`/`aspf` `strict` or `relaxed`. No
report address (`rua`) is reviewed, so none is published.

`mail-identity` is the one judge of both contracts. The gateway installer asks
it (`check-outbound`) before it renders a route; the prerequisite installer asks
it (`dkim-key`, `check-key`) where a key goes, whether it is needed yet and
whether it is one. Nothing restates its rules.

## The DKIM private key

External secret material, like a TLS key: **never generated on a server and
never committed**. Its one place on the host is derived from the target ID and
the reviewed selector:

```
/etc/opendkim/keys                                   root:opendkim 0750
/etc/opendkim/keys/<target>                          root:opendkim 0750
/etc/opendkim/keys/<target>/<selector>.private       root:opendkim 0640
/etc/opendkim/keys/tits-guru/rg1.private
```

Readable by root and by the host's DKIM signer — the `opendkim` package
account, through its own group, which holds no other account — and by nobody
else. The signer never runs as root. A key installed before any signer existed
is root-only, `root:root 0600` in `root:root 0700` directories: that one layout
is accepted as it is, and `install-mail-signing --apply` converts it by owner,
group and mode alone, never touching the key's bytes. Any other metadata — a
world- or group-writable key, another owner, a directory anyone can enter — is a
**CONFLICT**, never "fixed". A new key is installed only once the signer's
group exists, which host bootstrap guarantees: a host's services are installed
before its targets' material.

It is installed by `install-target-prerequisites` as the logical material
`mail-dkim-private-key` (target scope), under the same rules as every other
material: installed only where absent, an identical key left alone, a different
one a **CONFLICT** and never overwritten or rotated, a symlink or non-regular
destination refused. Content is never printed, logged, hashed or measured. The
one difference is that it is judged — by `mail-identity check-key`, through
OpenSSL — before it is installed and whenever it is found installed: a PEM
private key (PKCS#8 or PKCS#1), RSA, at least the reviewed size, unencrypted,
alone in the file. A public key, certificate, passphrase-protected key, EC or
Ed25519 key, or a smaller RSA key is refused, with a fixed reason and nothing of
the key.

**While a target is held its key may be absent.** That row is `DEFERRED`: the
target is ready as it is configured today, because nothing signs with the key.
Once its mail is `outbound` an absent key is `MISSING`, and an outbound target
is never ready without it.

## Creating and supplying the key (operator, after merge)

On a trusted workstation — never on the server, never in the repository:

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out rg1.private
infrastructure/scripts/mail-identity check-key --file rg1.private
```

Then:

1. Store the whole PEM as the **`MAIL_DKIM_PRIVATE_KEY`** secret of the
   `production-tits-guru` GitHub Environment. It is optional: Configure without
   it behaves exactly as before.
2. Run **Configure tits.guru** (from `main`). The runner judges the key with
   `check-key` before anything is uploaded, sends it by its exact name into a
   root-only directory, `install-target-prerequisites` installs it at
   `/etc/opendkim/keys/tits-guru/rg1.private` (`root:opendkim 0640`), and every
   temporary copy — on the
   runner and on the host — is removed whether the run succeeds or fails. The
   key never appears in a log, an output, an artifact or the job summary.
   Before its temporary bundle is removed, Configure runs
   `mail-identity show-dns --target tits-guru --json` from that bundle on the
   host and writes the **public DNS publication plan** into its summary. With no
   installed key it reports `DNS publication plan DEFERRED — DKIM private key
   not installed.` and still succeeds while the target's mail is held. With a
   key, the plan must be complete — host address, A, PTR, DKIM, SPF and DMARC,
   exactly one of each — or the step fails and says not to publish DNS from
   that run; the installed key is left as it is. It never verifies DNS:
   nothing is published yet.
3. Keep the key in the operator's own secret store as well: a machine recovery
   needs it again, and it is never derivable from anything published. Carrying
   the active signing key in production backup and recovery is a requirement
   of the mail operations and recovery slice, before go-live.

The whole sequence, from merging to the first production verification, is in
[`infrastructure-verification.md`](infrastructure-verification.md#from-merge-to-the-first-production-verification).

## Publishing DNS

Configure tits.guru prints the plan in its summary. On the host, from a trusted
bundle and as root (the key is readable by root and the signer only), the same plan is:

```bash
sudo infrastructure/scripts/mail-identity show-dns --target tits-guru
sudo infrastructure/scripts/mail-identity show-dns --target tits-guru --json
```

It prints the records to create — nothing is published by it:

| Record | Name | Value |
|--------|------|-------|
| A | `mta1.tits.guru` | the host's IPv4 address |
| PTR | the host's IPv4 address | `mta1.tits.guru` — set at the provider that owns the address, not in the zone |
| TXT (DKIM) | `rg1._domainkey.tits.guru` | `v=DKIM1; k=rsa; p=<public key derived from the installed private key>` |
| TXT (SPF) | `tits.guru` | `v=spf1 ip4:<host IPv4> -all` |
| TXT (DMARC) | `_dmarc.tits.guru` | `v=DMARC1; p=none; adkim=s; aspf=s` |

The host's IPv4 is never committed: `mail-identity` reads it from the kernel's
route to the Internet (`ip -4 route get`, which sends nothing), so it is
whatever address this host actually sends from. Off the host, `--ipv4 ADDRESS`
shows the SPF value for a given address, and `--key FILE` derives the DKIM value
from a key that is not installed yet.

**Exactly one SPF record.** If `tits.guru` already publishes one, replace it —
never add a second; receivers treat two as a permanent error. A long DKIM
value may be split by the DNS provider into several quoted strings; that is
normal and is joined back when verified.

## Verifying DNS

```bash
sudo infrastructure/scripts/mail-identity verify-dns --target tits-guru
```

Strictly read-only — it writes no file, changes no DNS, restarts nothing and
sends no mail. It needs `dig`, which the host's canonical runtime installs
(`bind9-dnsutils`, converged by Prepare Host). From GitHub, **Verify production
infrastructure** shows the same checks inside its outbound-readiness section —
deferred while the target is held, required once it is outbound.

### What "public DNS" means here

Public DNS is what **independent external recursive resolvers** answer — the
view a receiving mail server has — and not what this host's own resolver
says. Today the witnesses are:

| Witness | Address |
|---------|---------|
| Cloudflare | `1.1.1.1` |
| Google | `8.8.8.8` |

The contract:

- **Every record is asked of both, directly** (`dig @1.1.1.1 …` and
  `dig @8.8.8.8 …`). The host's resolver — `/etc/resolv.conf`,
  systemd-resolved at `127.0.0.53` and whatever it forwards to — is never
  asked, not even as a fallback.
- **Both are required.** A timeout, a server failure, a refused query or no
  response from either one fails that record. The other's answer alone is
  never enough: there is no one-of-two quorum.
- **They must agree.** Both must report the same status (`NOERROR` or
  `NXDOMAIN`) and the same records. Order does not matter and names are
  compared case-insensitively, as DNS compares them; a TXT value is compared
  exactly, after its chunks are joined, because a DKIM key is case-sensitive.
  When they disagree the record fails — nothing picks one answer — and the
  diagnostic names the record and what each resolver answered. For TXT it
  prints only how many records each one returned, never the values (a DKIM
  value is a key); compare them with `dig @1.1.1.1 TXT <name>` and
  `dig @8.8.8.8 TXT <name>`. During propagation a disagreement is expected:
  run it again once both caches have expired.
- **The witnesses are part of the reviewed implementation**, not host
  configuration. No option, environment value or host file changes them or
  lets one resolver be enough.

Deliberately outside this proof: the host's local resolver, `/etc/hosts`,
split DNS and any local cache. They can disagree with public DNS in either
direction — a stale local name for the host's address does not make public
reverse DNS wrong, and a local entry cannot make missing public records
right. `mail-identity` neither reads nor changes any of them. Nor is an
authoritative lookup the activation gate: a record published on the
authoritative servers is not yet proof that the recursive resolvers receiving
servers use can see it.

### The checks

It passes only when, as both public resolvers answer:

- **forward-confirmed reverse DNS:** `mta1.tits.guru` has an A record including
  this host's outbound IPv4, and that address has exactly one PTR, exactly
  `mta1.tits.guru`;
- **SPF:** exactly one SPF policy for `tits.guru`, exactly
  `v=spf1 ip4:<host IPv4> -all` (whitespace and case normalised);
- **DKIM:** exactly one record at `rg1._domainkey.tits.guru`, with exactly the
  tags `v=DKIM1`, `k=rsa` and `p=` equal to the public key of the installed
  private key — split TXT chunks joined first;
- **DMARC:** exactly one DMARC policy at `_dmarc.tits.guru`, with exactly
  `v=DMARC1; p=none; adkim=s; aspf=s`.

A timeout, a refused query or a server failure at either resolver is a
failure, never a pass, and so is a disagreement between them or a host that
sends from a private (NAT) address. The exit status is 0 only on
`DNS VERIFIED: YES`.

## Readiness for outbound delivery

```bash
sudo infrastructure/scripts/mail-identity readiness --target tits-guru
```

The contract outbound delivery of a target's mail depends on, each condition
checked and reported:

1. the target is a production target;
2. its mail routing is `outbound`;
3. direct delivery is enabled on the host;
4. under a valid public MTA hostname;
5. it has a valid DKIM and DMARC identity;
6. a valid private key is installed at its canonical place;
7. public DNS verifies — A, PTR, SPF, DKIM and DMARC, asked of both public
   resolvers and judged exactly as `verify-dns` judges them;
8. the target's mail is signed: `verify-mail-signing --read-only --target T`
   passes — the signer installed, configured, running on loopback and able to
   read the key, and the gateway wiring the target's listener to it. Readiness
   restates none of that; it takes the verifier's verdict, and shows its report
   when it fails.

Once the signing foundation is installed on the production host, `tits-guru`
reads PASS for every condition but two — `routing` (still held) and `direct`
(still disabled) — and ends `OUTBOUND READY: NO` for exactly those reasons. The
activation slice gates the switch from held to outbound on this command.

## What this does not do

No enabled direct delivery, no change of `tits-guru` from `held`, no production
`MAIL_*` value, no `shared/.env` change, no DNS or PTR record and no mail of any
kind. Signing itself — the OpenDKIM package, its configuration and the
gateway's milter — belongs to [`mail-signing.md`](mail-signing.md), and signs
held mail without routing any of it. Activation, bounce reception, reply
routing and the support mailbox, suppression and delivery state, and mail
operations and recovery are later, separate slices.
