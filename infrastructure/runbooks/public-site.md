# Public site of a production target

How a planned production target gets a public site, one step at a time, without
the application becoming reachable before GO LIVE:
1. public HTTP that answers the ACME HTTP-01 challenge, so that a real
   certificate can be issued;
2. the certificate, issued by hand;
3. HTTPS with that certificate, still closed to the public;
4. at GO LIVE only, the application.

**Where it stands, 2026-10-12, by the operator's report.**
- **8.5.1 is done on the production host.**
  - Prepare tits.guru public HTTP, Verify tits.guru public HTTP and Verify
    tits.guru inbound SMTP passed.
  - The public site is `http-acme-ready`, `tits-guru` is `lifecycle=planned`,
    and Laravel is not reachable publicly.
  - The run before it, [`38306251547`](https://github.com/menvil/RateGuru/actions/runs/38306251547),
    refused before changing anything, because the host disables IPv6; see
    [Hosts that disable IPv6](#hosts-that-disable-ipv6).
- **8.5.2 is done by hand.**
  - The DNS A record of `tits.guru` names the production host,
    `213.199.41.241`.
  - Certbot issued the certificate over the webroot:
    `/etc/letsencrypt/live/tits.guru/{fullchain,privkey}.pem`, Certbot's links
    into `/etc/letsencrypt/archive/tits.guru/`.
- **8.5.3 is implemented in the repository and has not run on the host.**
  `prepare-public-https` and its workflows take the site to `tls-ready`. See
  [HTTPS: `tls-ready`](#https-tls-ready).

The host disables IPv6. Every operation here supports that, and none of them
turns IPv6 on.

## The states

| State | What the host serves for `tits.guru` | How it is reached | Implemented |
|-------|--------------------------------------|-------------------|-------------|
| `not-prepared` | Nothing of its own: the request falls to the host's default server. | The starting state, and **Rollback tits.guru public HTTP**. | Yes |
| `http-acme-ready` | Port 80 only: `/.well-known/acme-challenge/<token>` from `/var/www/html`, and `503 Service Unavailable` with `Retry-After: 3600` for everything else. | **Prepare tits.guru public HTTP**. | Yes — in the repository; not yet run on the host |
| `tls-ready` | Port 80: the ACME challenge from `/var/www/html`, and a 301 to HTTPS for everything else. Port 443: Certbot's certificate, and `503` with `Retry-After: 3600` and `Cache-Control: no-store` for everything. | **Prepare tits.guru HTTPS** (`prepare-public-https --apply`), which takes the public site over; and **Close tits.guru public site**. | Yes — in the repository (8.5.3); not yet run on the host |
| `public-active` | Port 80 as in `tls-ready`. Port 443: the production application, through `tits-guru`'s own PHP-FPM pool. | **GO LIVE tits.guru** (`--activate`), at 8.7 only. | Prepared in the repository and refused while the target is `planned`; never run |

`prepare-public-http` manages the first two states and no others.
`prepare-public-https` manages `http-acme-ready` → `tls-ready` → `public-active`
and back, and nothing earlier.
`--verify` reports one of these:
- `not-prepared` or `http-acme-ready`;
- `drift` — its own pieces are incomplete, for example its exact file without
  the link;
- `foreign` — something at its paths is not its own.

It does not know `tls-ready` or `public-active`: from `tls-ready` on, it reports
the site as `foreign`, and **Verify tits.guru HTTPS** is the check that applies.

## Two sites, two owners

A production target has two independent Nginx sites:

| Site | Server name | Owner | What it is for |
|------|-------------|-------|----------------|
| `rateguru-tits-guru` | `tits-guru.internal` (`nginx.internal_hostname`) | `install-bootstrap-services` (provisioning, `runbooks/provision-target.md`) | Internal checks of the application, loopback only. Never public, never edited by the public site. |
| `rateguru-tits-guru-public` | every entry of `public_hostnames` — `tits.guru` | `prepare-public-http` up to `http-acme-ready`; `prepare-public-https` from `tls-ready` on | The public site. |

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

**When the site moves to HTTPS**, `prepare-public-https` (8.5.3) takes the
public site over. It uses the same file and link, with its own render in them,
and keeps their first line. It also adds its own Certbot renewal hook. From
then on:
- **Prepare tits.guru public HTTP cannot return the site to HTTP**: `--apply`
  refuses the file, whatever its first line says;
- **Rollback tits.guru public HTTP cannot remove it**: `--rollback` refuses it,
  and the link stays.

Taking the site back out of HTTPS is **Rollback tits.guru HTTPS**, and only
while the site is `tls-ready`. Once it is `public-active`, nothing returns it
to HTTP: **Close tits.guru public site** closes it to the public and keeps
HTTPS. A file with a 443 listener or a certificate is reported as passed to the
HTTPS installer.

`prepare-public-https` follows the same rule. It owns the site only as exactly
one of its known renders — `http-acme-ready` (prepare-public-http's own),
`tls-ready` or `public-active` — as `root:root 0644`, and its hook only as
rendered, `root:root 0755`. Anything else is `foreign` and refused.

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

## Public HTTP: the operations (8.5.1)

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

## HTTPS: `tls-ready`

`infrastructure/scripts/prepare-public-https` takes the public site from
`http-acme-ready` to `tls-ready` and back, and at GO LIVE to `public-active`
and back. It is built on `prepare-public-http`, which it sources from the same
trusted bundle. They share:
- the target loader;
- the `http-acme-ready` render;
- the analysis of the loaded configuration;
- the probes, IPv6 handling included;
- the host lock.

### What `tls-ready` serves

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name tits.guru;

    location ~ ^/\.well-known/acme-challenge/[A-Za-z0-9_-]+$ {
        root /var/www/html;          # the same token location as http-acme-ready
        ...
    }
    location /.well-known/acme-challenge/ { return 404; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name tits.guru;

    ssl_certificate     /etc/letsencrypt/live/tits.guru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tits.guru/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    location / {
        add_header Retry-After 3600 always;
        add_header Cache-Control "no-store" always;
        default_type text/plain;
        return 503 "Service Unavailable\n";
    }
}
```

- **Port 80** keeps the HTTP-01 challenge for `tits.guru` and sends everything
  else to HTTPS.
- **Port 443** has no `root`, no application, no Basic Auth and no HSTS. It
  serves no file of the release, no key, no hidden directory and nothing of
  `/var/www/html`.
- **Never a `default_server`**, on 80 or on 443.
- **The certificate is read straight from Certbot's live links**, so a
  renewal is served after the next reload, which the renewal hook makes.

### The certificate, judged before any change

`--apply` (and `--activate`, and `--verify` in `tls-ready` and
`public-active`) requires:

| Check | What it rules out |
|-------|-------------------|
| `/etc/letsencrypt`, `live`, `archive`, `live/tits.guru`, `archive/tits.guru`: real directories, root's, writable by no one else | a tree someone else could swap a key into |
| `live/tits.guru/fullchain.pem` and `privkey.pem` are links to regular root-owned files directly in `archive/tits.guru/` | a copied file, or a link out of Certbot's archive |
| the private key readable by root alone — neither its group nor anyone else; Certbot writes it `0600` | a leaked key |
| every public hostname covered (`openssl x509 -checkhost`) | the wrong certificate |
| a chain to the system's trusted authorities (`openssl verify`) | a self-signed or broken chain |
| the key matches the certificate, compared through its public half | a mismatched pair; the key is never printed |
| at least 14 days left | a certificate about to expire |
| `/etc/letsencrypt/renewal/tits.guru.conf`, root's, with `authenticator = webroot` and the webroot `/var/www/html` | a certificate Certbot would not renew, or renew with a plugin that edits Nginx |
| Certbot's `renewal-hooks/deploy` directory exists, root's | the script never creates it |

The installer never issues, renews or removes a certificate, and never runs
Certbot.

### The renewal

Certbot renews `tits.guru` through its own timer, over the webroot: the
challenge still answers on port 80 in `tls-ready` and `public-active`.
`--apply` installs one deploy hook of the site's own:

```
/etc/letsencrypt/renewal-hooks/deploy/rateguru-public-https-tits-guru   root:root 0755
```

It is rendered from the trusted bundle, with the same certificate judge as the
installer, and nothing in it points back at the bundle. Certbot runs every
deploy hook after every renewal:
- when `RENEWED_LINEAGE` is `/etc/letsencrypt/live/tits.guru`, it judges the
  renewed pair, runs `nginx -t`, and only then runs `systemctl reload nginx`;
- if the pair does not hold or `nginx -t` fails, it reloads nothing and exits
  non-zero, so Certbot logs it, and Nginx keeps serving the previous
  certificate from memory;
- if Nginx is not running, there is nothing to reload;
- for any other lineage — `mx1.tits.guru` above all — it exits at once and
  changes nothing.

It never restarts Nginx and never touches the mail gateway. The inbound mail
listener's own hook, `rateguru-mail-inbound-tls`, sits beside it, untouched:
each acts on its own lineage only. `--verify` reports the hook as rendered or
not. `--apply` installs it again if it is missing, and `--rollback` removes it.

### Routing on port 443

Port 80 keeps every rule of [8.5.1](#how-mx1titsguru-keeps-its-http-01-route).
On 443, the host's other HTTPS sites — staging and the mail capture today —
must keep answering exactly as before. Before anything is written, and again
on the candidate before the reload:
- the default server of `*:443` and `[::]:443` — explicit, or the first
  loaded — stays the same server, and is never the public site;
- every other HTTPS name stays routed to the same server;
- no listener on 443 is bound to a single address.

If no other site listens on 443, the public site would become the default for
every HTTPS name, and the run refuses. After the reload, every other HTTPS
name, and a name nobody lists, must answer exactly as before.

### The operations

`prepare-public-https --check|--apply|--verify|--rollback|--activate|--deactivate
--target tits-guru`. It runs as root from a trusted bundle, through five
workflows. All of them run from `main` only, in `production-tits-guru` with
the bootstrap credential, in the `rateguru-staging-deployment` group, and
never on a merge or a schedule:

| Workflow | Confirmation | Runs | From → to |
|----------|--------------|------|-----------|
| **Prepare tits.guru HTTPS** | `PREPARE tits-guru HTTPS` | `--apply` | `http-acme-ready` → `tls-ready` |
| **Verify tits.guru HTTPS** | — (read-only) | `--verify` | — |
| **Rollback tits.guru HTTPS** | `ROLLBACK tits-guru HTTPS` | `--rollback` | `tls-ready` → `http-acme-ready` |
| **GO LIVE tits.guru** | `OPEN tits.guru TO THE PUBLIC` | `--activate --confirm-public` | `tls-ready` → `public-active` |
| **Close tits.guru public site** | `CLOSE tits.guru TO THE PUBLIC` | `--deactivate` | `public-active` → `tls-ready` |

The confirmation is judged in a job with no Environment and no secret. GO
LIVE's job also checks out `main` and refuses unless its registry says
`tits-guru` is `active`. The action uploads `infrastructure/` alone and accepts
exactly one `RATEGURU_PUBLIC_HTTPS_RESULT` line. That line is judged against
what the mode promises before the job summary shows the state, the
certificate, the hook and what changed.

Every changing mode shares one transaction:
1. It holds the host's lock and judges everything again under it.
2. It starts from its own exact known state:

   | Mode | Starts from | Lifecycle, in both registries |
   |------|-------------|-------------------------------|
   | `--apply` | `http-acme-ready` | `planned` |
   | `--rollback` | `tls-ready` | `planned` |
   | `--activate` | `tls-ready` | `active` |
   | `--deactivate` | `public-active` | any, and the two may differ; the host's registry must name the target |

   Anything else is refused before a change. `--rollback` and `--deactivate`
   never wait on the certificate, and `--deactivate` never waits on the
   application.
3. It records the answers of `mx1.tits.guru`, of every other HTTPS site and of
   a name nobody lists, and keeps the site's file and the hook as they are.
4. It writes the new render (and installs or removes the hook), runs
   `nginx -t`, and judges the candidate's routing again. Nothing is reloaded
   yet.
5. It runs `systemctl reload nginx` — never a restart, a stop or a start.
6. It proves the result on `127.0.0.1`, and on `[::1]` unless the host
   disables IPv6:
   - the public hostnames over HTTPS, with Host and SNI and a certificate
     verified against the system's authorities — except for `--deactivate`,
     which proves the closed site by its 503 alone;
   - the 301 on port 80;
   - the HTTP-01 challenge of `tits.guru` (404 for an absent token) and of
     `mx1.tits.guru`;
   - every other site exactly as before;
   - every file under `/etc/nginx` and Certbot's tree, but the site's own two,
     byte-identical.

Any failure puts the previous file and hook back, runs `nginx -t`, reloads
again if needed, and proves the previous state answers again. The run then
fails with `rolled_back: true`. If that cannot be proved, it says
**CRITICAL** and exits 2. Run again with nothing to change, every mode writes
nothing and reloads nothing.

**`--rollback`** returns the exact `http-acme-ready` render and removes the
hook. Certbot's certificate, its links and its renewal configuration are kept.
`prepare-public-http` owns the site again, and its `--verify` passes.

### GO LIVE: `public-active`, only at 8.7

`--activate` serves the production release over HTTPS, through `tits-guru`'s
own pool and never staging's:
- `root /home/www/rateguru/production/tits-guru/current/public`;
- `fastcgi_pass unix:/run/php/rateguru-tits-guru.sock`.

It refuses unless all of these hold:
- `tits-guru` is `active` in the trusted bundle's registry and the host's;
- `current` leads to a release with `public/index.php` and `artisan`;
- the target's PHP-FPM socket is listening;
- the internal health check (`health.url` with `health.host_header`) answers
  2xx through the internal site and PHP-FPM;
- the certificate holds;
- `--confirm-public` is given, which the workflow passes only after its typed
  confirmation.

After the reload it proves the application answers over HTTPS with a verified
certificate, and that ACME and every other site are unchanged.

**`--deactivate`** returns `public-active` to `tls-ready`: everyone gets the
503 again, while HTTPS, the certificate, the redirect and ACME stay. Once a
site is `public-active`, `--apply` and `--rollback` refuse it: nothing removes
a live site's HTTPS by accident.

Closing is the emergency exit, so it waits on nothing that might be what went
wrong:
- **The certificate.** It is neither judged nor trusted. The closed site is
  proved by its own answer: `503` with `Retry-After: 3600` and
  `Cache-Control: no-store`, requested with the public hostname as Host and
  SNI. An expired or untrusted certificate therefore never keeps the site
  open, and never puts `public-active` back. **Verify tits.guru HTTPS** still
  reports it, and fails until Certbot has a valid one.
- **The lifecycle.** Any lifecycle, and the two registries may disagree. The
  target must still be production in the trusted bundle's registry and be
  named in the host's own; the site's file must be exactly a known render; and
  the routing on 80 and 443 is judged as for every other change.
- **What still stops it.** `nginx -t` failing, a reload failing, or the 503 not
  answering after the reload. These are failures to install the closed
  configuration, and the transaction puts `public-active` back.

Today `tits-guru` is `planned`, so **GO LIVE tits.guru is refused**, by its
workflow before the Environment, and on the host.

## The order from here

Done:
- **8.5.1**: Prepare and Verify tits.guru public HTTP passed; `http-acme-ready`.
- **8.5.2**: DNS A `tits.guru` → `213.199.41.241`; Certbot's certificate
  through the webroot.

After this change is merged:

1. Merge into `develop`. CI passes, including **Public HTTP on a real Nginx**,
   which now runs HTTPS on a real Nginx with real certificates.
2. `develop` promoted into `main`, the trusted tooling every production
   workflow checks out.
3. Read-only checks first:
   - **Verify tits.guru public HTTP** passes, `http-acme-ready`: the starting
     state;
   - **Verify tits.guru inbound SMTP** passes;
   - on the host, `sudo certbot certificates` lists `tits.guru`, and
     `sudo certbot renew --dry-run --cert-name tits.guru` and
     `sudo certbot renew --dry-run --cert-name mx1.tits.guru` both succeed. A
     dry run is a real HTTP-01 round trip against Let's Encrypt's staging
     service; it saves nothing and runs no deploy hook.
4. **Prepare tits.guru HTTPS**, typing `PREPARE tits-guru HTTPS`. Its summary
   must say `tls-ready`, the certificate `valid`, and the hook `installed`.
5. Check it:
   - **Verify tits.guru HTTPS** passes, `tls-ready`;
   - **Verify tits.guru inbound SMTP** still passes;
   - from outside, `curl -sI https://tits.guru/` answers `503` with
     `Retry-After: 3600` and `Cache-Control: no-store`, with no certificate
     warning;
   - from outside, `curl -sI http://tits.guru/x` answers `301` to
     `https://tits.guru/x`;
   - `openssl s_client -connect tits.guru:443 -servername tits.guru
     </dev/null | openssl x509 -noout -subject -enddate` shows the Let's
     Encrypt certificate for `tits.guru`;
   - both dry runs of step 3 still succeed, so HTTP-01 still works for both
     names;
   - on the host, the hook does nothing for another lineage:
     `sudo env -i RENEWED_LINEAGE=/etc/letsencrypt/live/mx1.tits.guru
     /etc/letsencrypt/renewal-hooks/deploy/rateguru-public-https-tits-guru`
     prints nothing and exits 0.
6. If anything is wrong while it is `tls-ready`: **Rollback tits.guru HTTPS**
   (`ROLLBACK tits-guru HTTPS`) returns the exact `http-acme-ready` and keeps
   the certificate; **Verify tits.guru public HTTP** passes again.
7. Phase 8.6: the production environment, activation in the registry, the
   first deploy, rollback and deploy, the backup cycle and the recovery
   checks. The site stays `tls-ready`, closed.
8. Phase 8.7, GO LIVE: **GO LIVE tits.guru** (`OPEN tits.guru TO THE PUBLIC`),
   once `tits-guru` is active in both registries, deployed and healthy. If
   needed, **Close tits.guru public site** (`CLOSE tits.guru TO THE PUBLIC`)
   closes it again at any time.

## What this does not do

- No certificate is issued, renewed or removed by any script here, and Certbot
  is never run: it renews on its own.
- The application stays closed until GO LIVE (8.7). The target's lifecycle is
  never changed here, and no release is deployed.
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

  For HTTPS, it issues a certificate for `tits.guru` from a test authority
  and lays it out as Certbot does. It then proves, on a real Nginx with real
  TLS:
  - `--apply` to `tls-ready`: the verified 503 with `Retry-After` and
    `Cache-Control: no-store` on both loopbacks, the 301, both HTTP-01 routes,
    and staging's HTTPS sites and the default server unchanged;
  - `prepare-public-http` then refusing the site, and a no-op second
    `--apply`;
  - a renewal through the hook that is then the certificate served, and a
    renewal of `mx1.tits.guru` that the hook ignores;
  - GO LIVE refused for the planned target, and the `public-active` render
    passing `nginx -t`;
  - a failed reload or `nginx -t` putting the previous state back;
  - `--rollback` to the exact `http-acme-ready`, keeping the certificate;
  - the same `tls-ready` and back with IPv6 disabled.

  It is CI's **Public HTTP on a real Nginx** job. It has passed on Ubuntu
  22.04 (Nginx 1.18, as on the host) and on Ubuntu 24.04 (Nginx 1.24).
- `tests/Feature/Architecture/PublicHttpsTest.php` covers `prepare-public-https`
  on the simulated host:
  - the renders, `tls-ready` and `public-active`;
  - the move to `tls-ready`, its no-op, and the hand-off that
    `prepare-public-http` then refuses;
  - the rollback, which keeps every certificate;
  - every certificate refusal: none, expired or expiring, another name,
    untrusted, another key, a copied file, a link out of the archive, a
    key its group or anyone can read, a writable archive, no renewal
    configuration, a renewal that edits Nginx, no hook directory;
  - the 443 default-server refusal;
  - `nginx -t`, reload and post-reload failures, and CRITICAL;
  - the renewal hook: `tits.guru` reloaded after `nginx -t`, `mx1.tits.guru`
    ignored, a bad renewal refused, no reload while `nginx -t` fails;
  - GO LIVE: refused while `planned`, and refused without its confirmation,
    release, PHP-FPM, health or certificate; once all hold, it goes live and
    closes again, and `--rollback` and `--apply` are refused while it is
    public;
  - closing a live site with the application down, with an expired or
    untrusted certificate (which `--verify` still reports), and with the two
    registries disagreeing on the lifecycle;
  - IPv6 disabled.
- `tests/Feature/Architecture/PublicHttpsWorkflowTest.php` covers the five
  workflows and their action:
  - the gate and the confirmations;
  - GO LIVE's lifecycle check before the Environment;
  - `--confirm-public` passed for GO LIVE alone;
  - the result judged against each mode's promise, and the cleanup.
