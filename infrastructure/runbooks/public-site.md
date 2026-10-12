# Public site of a production target

How a planned production target gets a public site, one step at a time, without
the application becoming reachable before GO LIVE. Today it covers the first
step only: public HTTP that answers the ACME HTTP-01 challenge, so that a real
certificate can be issued.

**State on 2026-10-12.** `prepare-public-http` and its three workflows exist in
the repository. **Prepare tits.guru public HTTP** ran once on the production
host, in run [`38306251547`](https://github.com/menvil/RateGuru/actions/runs/38306251547).
It refused **before changing anything**:
- the result was `status=fail`, `state=not-prepared`, `changed=false` and
  `reloaded=false`;
- the cause was that the host disables IPv6, so `[::1]` could not answer. That
  is now handled; see [Hosts that disable IPv6](#hosts-that-disable-ipv6).

`tits.guru` has no public site yet. Its DNS A record has not been checked for
this either, so do not assume it names the VPS.

## The states

| State | What the host serves for `tits.guru` | How it is reached | Implemented |
|-------|--------------------------------------|-------------------|-------------|
| `not-prepared` | Nothing of its own: the request falls to the host's default server. | The starting state, and **Rollback tits.guru public HTTP**. | Yes |
| `http-acme-ready` | Port 80 only: `/.well-known/acme-challenge/<token>` from `/var/www/html`, and `503 Service Unavailable` with `Retry-After: 3600` for everything else. | **Prepare tits.guru public HTTP**. | Yes — in the repository; not yet run on the host |
| `tls-ready` | The certificate for `tits.guru` installed and HTTPS prepared, still without the application. | The HTTPS installer (8.5.3), which takes the public site over. | **No** |
| `public-active` | The application, over HTTPS. | GO LIVE (8.7) only. | **No** |

`prepare-public-http` manages the first two states and no others.
`--verify` reports one of these:
- `not-prepared` or `http-acme-ready`;
- `drift` — its own pieces are incomplete, for example its exact file without
  the link;
- `foreign` — something at its paths is not its own.

It does not know `tls-ready` or `public-active`, and nothing here claims them.

## Two sites, two owners

A production target has two independent Nginx sites:

| Site | Server name | Owner | What it is for |
|------|-------------|-------|----------------|
| `rateguru-tits-guru` | `tits-guru.internal` (`nginx.internal_hostname`) | `install-bootstrap-services` (provisioning, `runbooks/provision-target.md`) | Internal checks of the application, loopback only. Never public, never edited by the public site. |
| `rateguru-tits-guru-public` | every entry of `public_hostnames` — `tits.guru` | `prepare-public-http` | The public site. |

The public site has its own file, `/etc/nginx/sites-available/rateguru-tits-guru-public`,
and its own link, `/etc/nginx/sites-enabled/rateguru-tits-guru-public`.
`prepare-public-http` writes and removes those two paths and nothing else, and
only under the ownership rules below.

The internal renderer still has no notion of a public hostname, a TLS listener
or a certificate. The old `infrastructure/config/nginx/rateguru-production` is
not used for either site.

## Who owns the public site, and when it changes hands

`prepare-public-http` owns the public site only as **its exact render**. That
means:
- a regular file, `root:root 0644`;
- byte for byte what the trusted bundle renders;
- the link to it.

The first line marks the file, but it is **not** ownership on its own.
Anything else at those paths is `foreign`, and both `--apply` and `--rollback`
refuse it. That covers:
- a file that keeps that first line but says anything else;
- the render with another owner or mode;
- a link, a directory;
- a link that leads elsewhere.

On a refusal the script:
- writes and removes no file;
- creates and removes no link;
- does not reload Nginx;
- says why;
- reports `foreign`, which `--verify` reports as a failure.

Its own pieces left incomplete are still handled. If the exact render is in
place without its link, `--apply` adds the link and `--rollback` removes the
file.

**When the site moves to HTTPS**, the HTTPS installer (8.5.3) takes the public
site over: the same file and link, its own configuration in them. From then
on:
- **Prepare tits.guru public HTTP cannot return the site to HTTP**: `--apply`
  refuses the file, whatever its first line says;
- **Rollback tits.guru public HTTP cannot remove it**: `--rollback` refuses it,
  and the link stays.

Taking the site back out of HTTPS is the HTTPS installer's own rollback, not
this one. A file with a 443 listener or a certificate is reported as passed to
the HTTPS installer.

**A content difference is never resolved by running Prepare again.** That
includes a hand edit, and a render this script changed in a later revision.
It is resolved by a reviewed change that says what the file should become.

## What the public site answers, in `http-acme-ready`

```nginx
server {
    listen 80;
    listen [::]:80;

    server_name tits.guru;

    location ~ ^/\.well-known/acme-challenge/[A-Za-z0-9_-]+$ {
        root /var/www/html;
        disable_symlinks on from=$document_root;
        types { }
        default_type text/plain;
        try_files $uri =404;
    }

    location /.well-known/acme-challenge/ {
        return 404;
    }

    location / {
        add_header Retry-After 3600 always;
        default_type text/plain;
        return 503 "Service Unavailable\n";
    }
}
```

(Rendered from `deployment-targets.json`. The comments and the log lines are
left out above.)

- **A challenge token.** Served from the webroot Certbot already uses for
  `mx1.tits.guru`, as a regular file and as text. A token name is
  `[A-Za-z0-9_-]+`. Anything else under the challenge path — `probe.php`, a
  directory, a file name with a dot — is 404. A link is never followed out of
  the webroot.
- **Everything else** is the same 503, `/index.php` included. There is no
  `fastcgi_pass`, no `proxy_pass`, no `root` of the release tree, no TLS
  listener and no redirect to HTTPS: a redirect would point at a certificate
  that does not exist yet.
- **No `default_server`.** The site only ever gets the names it lists.

## How `mx1.tits.guru` keeps its HTTP-01 route

The inbound mail listener's certificate for `mx1.tits.guru` renews through
Certbot's webroot, over HTTP-01 on port 80, served from `/var/www/html` by
whichever server answers `mx1.tits.guru` today
([`runbooks/mail-inbound.md`](mail-inbound.md#tls-for-mx1titsguru)). The
public site must never become that server.

It lists `tits.guru` exactly and is never a default server, so it can only take
`mx1.tits.guru` by **becoming the default server through load order**. With no
explicit `default_server`, Nginx picks the first server it loads for a socket.
`sites-enabled/*` loads in name order, so a new file can become that first
server. The real-Nginx scenario in CI shows this happening: with only a
`zz-default` site that lacks `default_server`, the public site, loaded first,
answers `mx1.tits.guru`.

So, before anything is written, `prepare-public-http` reads the configuration
Nginx loads (`nginx -T`), with Nginx's own token rules, and requires all of
the following:

| Check | Why |
|-------|-----|
| An explicit `default_server` on `*:80` and `[::]:80`, in another site | Unlisted names — `mx1.tits.guru` among them — do not depend on load order. |
| No port-80 listener bound to a single address | Such a listener routes that address separately, and the loopback probes could not see it. |
| No other server whose `server_name` matches `tits.guru` (exactly, by a wildcard, or by a regular expression) | Nothing else serves it now. |
| No `listen` or `server_name` outside a server block | A fragment included into a server would make that server's names unknowable. |
| `mx1.tits.guru` answered by a server that is not the public site, and an absent challenge answered 404 on `127.0.0.1` — and on `[::1]` unless the host disables IPv6 | Its HTTP-01 route works today and is not the public site's. |

If any of these cannot be proved, the run **refuses**. It never deletes, edits
or replaces the default site, or any other site, to make the checks pass.
When the default server is not explicit, the fix belongs to the operator and is
reviewed on its own. On Ubuntu that means the package's own `default` site,
`listen 80 default_server; listen [::]:80 default_server; root /var/www/html;`.

The check is repeated after the file is written, before the reload. The
candidate configuration must keep every MX host name and both default servers
on exactly the servers that answered them before. After the reload, the host's
own Nginx is asked again: `mx1.tits.guru` and an unlisted name must answer
exactly as they did before the run.

### Hosts that disable IPv6

The answers are asked on `127.0.0.1` always, and on `[::1]` whenever IPv6 is
up on loopback. Every mode reads the kernel's view first, from `/proc` only:
- `net.ipv6.conf.lo.disable_ipv6` (`/proc/sys/net/ipv6/conf/lo/disable_ipv6`);
- whether `::1` is on `lo` (`/proc/net/if_inet6`).

| What `/proc` says | `[::1]` | Result |
|-------------------|---------|--------|
| `disable_ipv6=0`, `::1` on `lo` | **required**, like `127.0.0.1` | `ipv6_loopback: available`. A `[::1]` that does not answer fails the run. |
| `disable_ipv6=1`, no `::1` — the host disables IPv6, as the production host does | skipped, with a `SKIP probes:[::1]` line saying why | `ipv6_loopback: disabled` |
| anything else: one without the other, or a file that cannot be read | — | **refused**: IPv6 is never assumed to be disabled. `ipv6_loopback: unknown`. |

Only the HTTP probes on `[::1]` are skipped. The following do not change:
- the public site still listens on `[::]:80`; Nginx binds it with IPv6
  disabled;
- the `default_server` on `[::]:80` and the route of `mx1.tits.guru` on it are
  still judged from the configuration;
- the answers on `127.0.0.1` are always required, and an IPv4 loopback that
  does not answer fails the run whatever IPv6 does;
- the transaction, the ownership rules and the host lock.

Nothing here turns IPv6 on or off.

## The operations

`infrastructure/scripts/prepare-public-http --check|--apply|--verify|--rollback
--target tits-guru`. It runs as root from a trusted bundle, for a registered
production target that is `lifecycle=planned` in both the trusted bundle's
registry and the host's installed one. `staging-main`, an unknown target, or a
public hostname that is not a public DNS name, is internal, or is an MX host
name, is refused before anything is read from the host.

| Workflow | Confirmation | Runs |
|----------|--------------|------|
| **Prepare tits.guru public HTTP** | `PREPARE tits-guru public HTTP` | `--apply` |
| **Verify tits.guru public HTTP** | — (read-only) | `--verify` |
| **Rollback tits.guru public HTTP** | `ROLLBACK tits-guru public HTTP` | `--rollback` |

Every workflow:
- runs from `main` only, behind the same main-only gate as every production
  operation;
- uses the `production-tits-guru` Environment with the existing bootstrap SSH
  credential and a verified `known_hosts`;
- waits in the `rateguru-staging-deployment` group with every other operation
  on that machine;
- never runs on a merge or a schedule.

The confirmation is judged in a job that holds no Environment and no secret.
The action uploads the `infrastructure/` tree alone and removes it, and the
local key files, on every path. It accepts exactly one
`RATEGURU_PUBLIC_HTTP_RESULT` line, judges it, and writes the state, the
changes and the next step into the job summary. A merge into `develop` or
`main` changes nothing on the host.

**`--check`** (read-only, no lock) reports everything above, the files it would
write, every site it keeps, and the exact render.

**`--apply`**, under the host's infrastructure lock:

1. Every check, run again, and the answers of `mx1.tits.guru` and of an unlisted
   name recorded.
2. When there is no file, the render is written beside its place and moved into
   it (`root:root 0644`, never through a link). An existing file is never
   rewritten: it is either exactly the render, or the run was refused at
   step 1. Then the link is created, if it is missing.
3. `nginx -t`, and the candidate's routing judged again. Nothing has been
   reloaded yet.
4. `systemctl reload nginx` — never a restart, a stop or a start.
5. Proof on `127.0.0.1`, and on `[::1]` unless the host disables IPv6:
   - `tits.guru` answers 503 with `Retry-After` for `/` and `/index.php`, and
     404 for an absent token;
   - `mx1.tits.guru` and an unlisted name answer exactly as in step 1;
   - every other file under `/etc/nginx` is byte-identical.

Any failure puts the file and link back as they were, runs `nginx -t`, reloads
again if a reload had happened, and proves step 1's answers again. The run
then fails with `rolled_back: true`. If that return cannot be proved, it says
**CRITICAL** and exits 2. Run again with nothing to change, it writes nothing
and reloads nothing. If the files are in place but Nginx does not answer from
them, it reloads once.

**`--verify`** (read-only, no lock) reports:
- the file as rendered and the link;
- the routing checks, with the public site loaded exactly once;
- the answers of `tits.guru` and `mx1.tits.guru`;
- the internal site still the one `install-bootstrap-services` renders;
- the target still `planned` on both registries.

It passes in `not-prepared` and `http-acme-ready`, and fails on `drift` and
`foreign`.

**`--rollback`**, under the lock, removes the link and the file — only when the
file is exactly its render, never a site the HTTPS installer has taken over —
then runs `nginx -t`, reloads, and proves:
- `tits.guru` is no longer answered by the public site;
- `mx1.tits.guru` and an unlisted name answer as before.

A failure puts both back. It never touches:
- the internal site or another site;
- `/var/www/html`;
- a certificate, including any future one for `tits.guru`;
- the logs.

Run again, it is a no-op.

## The order after this change

1. Merge into `develop`.
2. Staging checks pass (CI, including **Public HTTP on a real Nginx**).
3. `develop` promoted into `main`, the trusted tooling every production workflow
   checks out.
4. **Prepare tits.guru public HTTP**, from `main`. Its summary must say
   `http-acme-ready`.
5. The HTTP-01 route of both names, checked:
   - **Verify tits.guru public HTTP** passes, `http-acme-ready`;
   - **Verify tits.guru inbound SMTP** still passes;
   - on the host, `sudo certbot renew --dry-run --cert-name mx1.tits.guru`
     succeeds. This is a real HTTP-01 round trip against the Let's Encrypt
     staging service, and saves nothing.
6. Only then, if needed, the DNS A record of `tits.guru` naming the VPS
   (`213.199.41.241`). It is checked independently, not assumed:
   `dig +short @1.1.1.1 tits.guru A` and `dig +short @8.8.8.8 tits.guru A`
   must both answer it.
7. 8.5.2 — the real certificate, through the webroot. Rehearse it first; the
   dry run saves nothing:

   ```bash
   sudo certbot certonly --webroot --webroot-path /var/www/html \
     --cert-name tits.guru -d tits.guru --dry-run
   sudo certbot certonly --webroot --webroot-path /var/www/html \
     --cert-name tits.guru -d tits.guru
   ```

   No `--nginx` plugin: it edits sites and would rewrite the public site behind
   its owner's back.
8. 8.5.3 — the next change: HTTPS for `tits.guru`:
   - the certificate installed safely, with its renewal;
   - the HTTPS installer taking the public site over (`tls-ready`), after which
     the workflows here refuse it;
   - the mechanism that activates the public site (`public-active`, at GO
     LIVE).
9. Phase 8.6 — the real production deploy and the recovery checks.
10. Phase 8.7 — the site opened for good.

## What this does not do

- No certificate is issued and no TLS listener exists on 443 for `tits.guru`.
- No redirect from HTTP to HTTPS.
- The application stays closed. The target stays `lifecycle=planned`, with no
  deploy and no activation.
- No DNS record, Postfix, OpenDKIM, MX record, firewall rule or the
  `mx1.tits.guru` certificate is changed.
- Monitoring, and bounce, reply or support processing, are untouched.

## How it is tested

- `tests/Feature/Architecture/PublicHttpTest.php` uses a simulated host whose
  stand-ins answer as Nginx routes. It covers:
  - valid and refused targets and hostnames, and paths the script does not own;
  - another server claiming the name;
  - every routing check, including the load-order hazard;
  - `nginx -t`, reload and post-reload failures, and the CRITICAL path;
  - the no-op `--apply` and `--rollback`, `--verify` on every state, the host
    lock, and the root gate;
  - hosts that disable IPv6: every mode on `127.0.0.1` alone, nothing asked of
    `[::1]`. Where IPv6 is up, `[::1]` is required and a dead `[::1]` fails.
    A dead `127.0.0.1` fails whatever IPv6 does. An IPv6 state that cannot be
    read, or disagrees with itself, refuses;
  - the hand-off. A file that keeps the first line but holds an HTTPS
    configuration, or a hand-edited HTTP site, or the render with another mode,
    is refused by `--apply`, `--rollback`, `--check` and `--verify`. Each
    refusal leaves the file, the link and Nginx as they were.
- `tests/Feature/Architecture/PublicHttpWorkflowTest.php` covers the three
  workflows and the action: the gate, the confirmation, the Environment, the
  result judging and the cleanup.
- `tests/Support/real-nginx/scenario.sh` covers the same against a **real
  Nginx**, in a network and mount namespace of its own, with:
  - the package's default site;
  - the internal site as `install-bootstrap-services` renders it;
  - the committed staging and mail capture sites.

  It also takes the site over with a real HTTPS configuration (a 443 listener
  and a certificate). `--apply` and `--rollback` refuse it, nothing is
  reloaded, and 443 keeps answering.

  Finally it disables IPv6 in its namespace, as the production host does, and
  starts a fresh Nginx, still bound to `[::]:80`. Check, apply, a second apply,
  verify and rollback then pass on `127.0.0.1` alone, and the route of
  `mx1.tits.guru` is kept.

  It is CI's **Public HTTP on a real Nginx** job. It has passed on Ubuntu
  22.04 (Nginx 1.18, as on the host) and on Ubuntu 24.04 (Nginx 1.24).
