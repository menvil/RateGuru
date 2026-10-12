#!/usr/bin/env bash
#
# The public HTTP endpoint of a planned production target, on a REAL Nginx.
#
#   scenario.sh REPOSITORY WORK
#
# Run as root, in a network and mount namespace of its own (`unshare --net
# --mount`): only loopback exists, so no request leaves the host and no DNS is
# asked. Nginx's configuration, the ACME webroot, Nginx's logs and /run are
# copies or empty directories under WORK, bind-mounted in this namespace, so
# the host's own are never written. WORK is an empty directory.
#
# The sites are the ones a production host carries: the package's own default
# site (default_server, root /var/www/html — where Certbot's webroot plugin
# answers the MX host's HTTP-01 challenge), tits-guru's internal site as
# install-bootstrap-services renders it, and the committed staging and mail
# capture sites, their certificates replaced by a throwaway one under WORK.
# Nginx runs that configuration, and prepare-public-http runs against it with
# nothing replaced but systemctl, whose stand-in reloads this Nginx and logs
# what it was asked. Every claim is checked against what Nginx answers:
#
#   * --check and --verify pass and change nothing before the site exists;
#   * --apply installs the site with one reload of the same master process:
#     tits.guru answers a challenge token from /var/www/html as text, and 503
#     with Retry-After for everything else — /, /index.php, a path climbing out
#     of the challenge directory — while a PHP file, a link and a name that is
#     not a token are never served from the webroot;
#   * mx1.tits.guru keeps its HTTP-01 route: its token is served from the
#     webroot before, during and after, on 127.0.0.1 and [::1];
#   * every other file under /etc/nginx is byte-identical afterwards, and the
#     internal site, staging and the mail capture sites answer as before;
#   * a second --apply and --verify write nothing and reload nothing;
#   * once an HTTPS configuration has taken the public site over, keeping its
#     first line, --apply and --rollback refuse it: the file, the link and the
#     443 listener stay as they are, and nothing is reloaded;
#   * nginx -t failing on the candidate, or the reload failing, puts the
#     previous state back and Nginx answers as before;
#   * the order of the sites: with no explicit default_server, --check refuses
#     — and the hazard it refuses is real: where the public site would load
#     first, Nginx hands it mx1.tits.guru's challenge;
#   * another site claiming tits.guru refuses;
#   * --rollback removes exactly the site's file and link with one reload, a
#     failed reload puts them back, and a second --rollback is a no-op;
#   * Nginx is reloaded only: never started, stopped or restarted;
#   * HTTPS: with a certificate for tits.guru from a test authority, laid out
#     as Certbot lays it out, prepare-public-https takes http-acme-ready to
#     tls-ready — a verified certificate on 443 and 503 with Retry-After and
#     Cache-Control: no-store, a 301 to HTTPS on 80, ACME still served for both
#     names, staging's HTTPS sites and the default server for unlisted names
#     unchanged — and prepare-public-http then refuses the site; a second apply
#     is a no-op; the renewal hook reloads Nginx for a renewed tits.guru, which
#     is then served, and does nothing for mx1.tits.guru; GO LIVE is refused for
#     a planned target; the public-active render passes nginx -t; a failed
#     nginx -t or reload puts the previous state back; and --rollback returns
#     the exact http-acme-ready, keeping the certificate;
#   * on a host that disables IPv6 — as the production host does: disable_ipv6=1,
#     no ::1, Nginx still bound to [::]:80 — check, apply, a second apply,
#     verify and rollback pass on 127.0.0.1 alone, say the [::1] probes are
#     skipped, and keep the MX host's route — and HTTPS goes to tls-ready and
#     back the same way.
#
# Prints PASS and FAIL lines, and exits non-zero on any FAIL.

set -Eeuo pipefail

REPOSITORY="$1"
WORK="$2"
SCRIPTS="${REPOSITORY}/infrastructure/scripts"
CONFIG="${REPOSITORY}/infrastructure/config"
FAILURES=0

pass() { printf 'PASS %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
expect() { if [[ "$2" == "$3" ]]; then pass "$1"; else fail "$1 — got \"$2\", expected \"$3\""; fi; }

[[ "${EUID}" == 0 ]] || { echo "scenario.sh runs as root, in a network namespace of its own" >&2; exit 2; }
[[ "${WORK}" =~ ^/[A-Za-z0-9/._-]+$ && -d "${WORK}" ]] || { echo "WORK must be an existing directory with a plain path: ${WORK}" >&2; exit 2; }
for tool in nginx curl jq openssl ip mount flock; do
    command -v "${tool}" >/dev/null || { echo "scenario.sh needs ${tool}" >&2; exit 2; }
done

for namespace in net mnt; do
    if [[ "$(readlink "/proc/self/ns/${namespace}")" == "$(readlink "/proc/1/ns/${namespace}")" ]]; then
        echo "scenario.sh runs in a network and mount namespace of its own — under unshare --net --mount" >&2
        exit 2
    fi
done
for dir in /etc/nginx /var/www/html /var/log/nginx /run; do
    [[ -d "${dir}" ]] || { echo "scenario.sh needs the Nginx package's ${dir}" >&2; exit 2; }
done

ip link set lo up
sysctl -qw net.ipv6.conf.lo.disable_ipv6=0 >/dev/null 2>&1 || true
ip -6 addr show dev lo | grep -q '::1' || { echo "scenario.sh needs IPv6 on loopback: every site listens on [::]:80" >&2; exit 2; }
if ip -4 route get 1.1.1.1 >/dev/null 2>&1; then
    echo "scenario.sh found a route off the host in its namespace" >&2
    exit 2
fi

# --- The host, in copies ----------------------------------------------------------

TARGET=tits-guru
REGISTRY="${CONFIG}/deployment-targets.json"
SITE="$(jq -r --arg t "${TARGET}" '.targets[$t].nginx.site_name' "${REGISTRY}")"
PUBLIC_SITE="${SITE}-public"
PUBLIC_FILE="/etc/nginx/sites-available/${PUBLIC_SITE}"
PUBLIC_LINK="/etc/nginx/sites-enabled/${PUBLIC_SITE}"
PUBLIC_HOST="$(jq -r --arg t "${TARGET}" '.targets[$t].public_hostnames[0]' "${REGISTRY}")"
INTERNAL_HOST="$(jq -r --arg t "${TARGET}" '.targets[$t].nginx.internal_hostname' "${REGISTRY}")"
MX_HOST="$("${SCRIPTS}/mail-inbound" render-receiver | jq -r '.mx_hostnames[0]')"

cp -a /etc/nginx "${WORK}/etc-nginx"
mkdir -m 0755 "${WORK}/www-html" "${WORK}/log-nginx" "${WORK}/run" "${WORK}/bin" "${WORK}/run-rateguru" "${WORK}/certs"
cp -a /var/www/html/. "${WORK}/www-html/"
mount --bind "${WORK}/etc-nginx" /etc/nginx
mount --bind "${WORK}/www-html" /var/www/html
mount --bind "${WORK}/log-nginx" /var/log/nginx
mount --bind "${WORK}/run" /run

# The sites a production host carries, and nothing else.
find /etc/nginx/sites-enabled -mindepth 1 -maxdepth 1 -exec rm -f -- {} +
[[ -f /etc/nginx/sites-available/default ]] || { echo "scenario.sh needs the Nginx package's default site" >&2; exit 2; }
ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

(
    # tits-guru's internal site, as install-bootstrap-services renders it.
    # shellcheck disable=SC1091
    source "${SCRIPTS}/install-bootstrap-services"
    TGT_NGINX_SITE[${TARGET}]="${SITE}"
    TGT_INTERNAL_HOSTNAME[${TARGET}]="${INTERNAL_HOST}"
    TGT_ROOT[${TARGET}]="$(jq -r --arg t "${TARGET}" '.targets[$t].application_root' "${REGISTRY}")"
    TGT_FPM_SOCKET[${TARGET}]="$(jq -r --arg t "${TARGET}" '.targets[$t].php_fpm.socket' "${REGISTRY}")"
    render_nginx_site "${TARGET}"
) >"/etc/nginx/sites-available/${SITE}"
ln -s "/etc/nginx/sites-available/${SITE}" "/etc/nginx/sites-enabled/${SITE}"

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=scenario.invalid" \
    -keyout "${WORK}/certs/privkey.pem" -out "${WORK}/certs/fullchain.pem" >/dev/null 2>&1
printf 'scenario:$apr1$scenario$0000000000000000000000\n' >/etc/nginx/rateguru-staging.htpasswd
for staging in rateguru-staging mailpit-staging mailtrap-local-staging; do
    sed -e "s#/etc/letsencrypt/live/[^/]*/fullchain.pem#${WORK}/certs/fullchain.pem#" \
        -e "s#/etc/letsencrypt/live/[^/]*/privkey.pem#${WORK}/certs/privkey.pem#" \
        -e '/options-ssl-nginx.conf/d' -e '/ssl_dhparam/d' \
        "${CONFIG}/nginx/${staging}" >"/etc/nginx/sites-available/${staging}"
    ln -s "/etc/nginx/sites-available/${staging}" "/etc/nginx/sites-enabled/${staging}"
done

# A challenge Certbot is answering for the MX host: a token in the webroot.
TOKEN="scenario-token-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
TOKEN_BODY="${TOKEN}.thumbprint"
install -d -m 0755 /var/www/html/.well-known /var/www/html/.well-known/acme-challenge
printf '%s' "${TOKEN_BODY}" >"/var/www/html/.well-known/acme-challenge/${TOKEN}"
# What must never be served from it: PHP, and a token that is a link out of it.
printf '<?php echo "executed";\n' >/var/www/html/.well-known/acme-challenge/probe.php
ln -s /etc/hostname /var/www/html/.well-known/acme-challenge/scenario-link-token

nginx -t >/dev/null 2>&1 || { nginx -t; echo "the scenario's own configuration does not pass nginx -t" >&2; exit 2; }
nginx
MASTER="$(cat /run/nginx.pid)"

# systemctl, for Nginx alone: it logs every request, reloads this Nginx, and
# refuses anything else.
cat >"${WORK}/bin/systemctl" <<EOF
#!/bin/bash
printf '%s\n' "\$*" | tee -a "${WORK}/systemctl-all.log" >> "${WORK}/systemctl.log"
case "\$1" in
    is-active) [[ "\$2" == --quiet && "\$3" == nginx ]] && kill -0 "\$(cat /run/nginx.pid)" 2>/dev/null ;;
    reload)
        [[ "\$2" == nginx ]] || exit 1
        if [[ -e "${WORK}/reload-fail-once" ]]; then rm -f "${WORK}/reload-fail-once"; exit 1; fi
        nginx -s reload
        ;;
    *) exit 1 ;;
esac
EOF
# nginx, unchanged — except -t fails while the public site is linked, when the
# scenario asks it to.
cat >"${WORK}/bin/nginx" <<EOF
#!/bin/bash
if [[ "\$1" == -t && -e "${WORK}/nginx-t-fails-with-public" && -L "${PUBLIC_LINK}" ]]; then
    echo "nginx: [emerg] the scenario refuses the candidate" >&2
    exit 1
fi
if [[ "\$1" == -t && -e "${WORK}/nginx-t-fails-with-https" ]] && grep -q 'listen 443 ssl;' "${PUBLIC_FILE}" 2>/dev/null; then
    echo "nginx: [emerg] the scenario refuses the HTTPS candidate" >&2
    exit 1
fi
exec nginx "\$@"
EOF
chmod 0755 "${WORK}/bin/systemctl" "${WORK}/bin/nginx"
: >"${WORK}/systemctl.log"
cp "${REGISTRY}" "${WORK}/installed-registry.json"

run_public_http() {
    local mode="$1"
    OUTPUT="$(env RATEGURU_ALLOW_TEST_OVERRIDES=true \
        RATEGURU_PUBLICHTTP_SYSTEMCTL_BIN="${WORK}/bin/systemctl" \
        RATEGURU_PUBLICHTTP_NGINX_BIN="${WORK}/bin/nginx" \
        RATEGURU_PUBLICHTTP_RUN_ROOT="${WORK}/run-rateguru" \
        RATEGURU_PUBLICHTTP_INSTALLED_REGISTRY="${WORK}/installed-registry.json" \
        "${SCRIPTS}/prepare-public-http" "--${mode}" --target "${TARGET}" 2>&1)" && STATUS=0 || STATUS=$?
    RESULT="$(grep '^RATEGURU_PUBLIC_HTTP_RESULT=' <<<"${OUTPUT}" | sed 's/^RATEGURU_PUBLIC_HTTP_RESULT=//')"
}

run_public_https() {
    local mode="$1"
    shift
    OUTPUT="$(env RATEGURU_ALLOW_TEST_OVERRIDES=true \
        RATEGURU_PUBLICHTTP_SYSTEMCTL_BIN="${WORK}/bin/systemctl" \
        RATEGURU_PUBLICHTTP_NGINX_BIN="${WORK}/bin/nginx" \
        RATEGURU_PUBLICHTTP_RUN_ROOT="${WORK}/run-rateguru" \
        RATEGURU_PUBLICHTTP_INSTALLED_REGISTRY="${WORK}/installed-registry.json" \
        RATEGURU_PUBLICHTTPS_LETSENCRYPT="${WORK}/letsencrypt" \
        RATEGURU_PUBLICHTTPS_TRUST_STORE="${WORK}/ca/ca.pem" \
        "${SCRIPTS}/prepare-public-https" "--${mode}" --target "${TARGET}" "$@" 2>&1)" && STATUS=0 || STATUS=$?
    RESULT="$(grep '^RATEGURU_PUBLIC_HTTPS_RESULT=' <<<"${OUTPUT}" | sed 's/^RATEGURU_PUBLIC_HTTPS_RESULT=//')"
}

show_output() {
    sed 's/^/    | /' <<<"${OUTPUT}"
}

result_is() {
    local label="$1" status="$2" filter="$3"
    if [[ "${STATUS}" == "${status}" ]] && jq -e "${filter}" <<<"${RESULT}" >/dev/null 2>&1; then
        pass "${label}"
    else
        fail "${label} — exit ${STATUS}, result ${RESULT:-none}"
        show_output
    fi
}

# answer ADDRESS HOST PATH — "STATUS|CONTENT-TYPE|RETRY-AFTER|BODY" from this Nginx.
answer() {
    local headers body code
    headers="${WORK}/answer.headers"
    body="${WORK}/answer.body"
    code="$(curl --silent --globoff --path-as-is --noproxy '*' --max-time 5 -o "${body}" -D "${headers}" -w '%{http_code}' \
        -H "Host: $2" "http://$1$3" 2>/dev/null)" || code=000
    printf '%s|%s|%s|%s\n' "${code}" \
        "$(tr -d '\r' <"${headers}" | awk -F': *' 'tolower($1) == "content-type" { print $2 }')" \
        "$(tr -d '\r' <"${headers}" | awk -F': *' 'tolower($1) == "retry-after" { print $2 }')" \
        "$(head -c 200 "${body}" | tr '\n' '~')"
}

etc_snapshot() {
    (
        cd /etc/nginx
        find . -path "./sites-available/${PUBLIC_SITE}" -prune -o -path "./sites-enabled/${PUBLIC_SITE}" -prune -o -print0 \
            | LC_ALL=C sort -z \
            | while IFS= read -r -d '' entry; do
                if [[ -L "${entry}" ]]; then printf '%s -> %s\n' "${entry}" "$(readlink "${entry}")"
                elif [[ -f "${entry}" ]]; then printf '%s %s\n' "${entry}" "$(sha256sum <"${entry}" | cut -c1-64)"
                else printf '%s/\n' "${entry}"; fi
            done
    )
}

webroot_snapshot() {
    (cd /var/www/html && find . -printf '%p %y %m\n' | LC_ALL=C sort)
}

# expect_mx — the MX host's challenge, answered from the webroot as Certbot needs it.
expect_mx() {
    local label="$1" address got
    for address in 127.0.0.1 '[::1]'; do
        got="$(answer "${address}" "${MX_HOST}" "/.well-known/acme-challenge/${TOKEN}")"
        if [[ "${got%%|*}" == 200 && "${got##*|}" == "${TOKEN_BODY}" ]]; then
            pass "${label}: ${MX_HOST} on ${address} serves its challenge from the webroot"
        else
            fail "${label}: ${MX_HOST} on ${address} answered ${got}"
        fi
    done
}

expect_not_public() {
    local label="$1" address got
    for address in 127.0.0.1 '[::1]'; do
        got="$(answer "${address}" "${PUBLIC_HOST}" "/")"
        if [[ "${got%%|*}" != 503 && "${got%%|*}" != 000 ]]; then
            pass "${label}: ${PUBLIC_HOST} on ${address} is not answered by the public site (${got%%|*})"
        else
            fail "${label}: ${PUBLIC_HOST} on ${address} answered ${got}"
        fi
    done
}

public_site_absent() {
    if [[ ! -e "${PUBLIC_FILE}" && ! -L "${PUBLIC_LINK}" ]]; then
        pass "$1: the public site's file and link are absent"
    else
        fail "$1: the public site's file or link is present"
    fi
}

master_kept() {
    expect "$1: the same Nginx master process (reloaded, never restarted)" "$(cat /run/nginx.pid)" "${MASTER}"
}

# --- Before ---------------------------------------------------------------------------

ETC_BEFORE="$(etc_snapshot)"
WEBROOT_BEFORE="$(webroot_snapshot)"
STAGING_BEFORE="$(answer 127.0.0.1 mailpit.staging.myprojects.pp.ua /)"
INTERNAL_BEFORE="$(answer 127.0.0.1 "${INTERNAL_HOST}" /)"

expect_mx "before"
expect_not_public "before"

run_public_http check
result_is "--check passes before the site exists" 0 '.status == "pass" and .state == "not-prepared" and (.changed | not)'
run_public_http verify
result_is "--verify passes before the site exists" 0 '.status == "pass" and .state == "not-prepared" and (.changed | not)'
expect "check and verify asked systemd nothing but whether Nginx is active" "$(grep -cv '^is-active --quiet nginx$' "${WORK}/systemctl.log" || true)" 0
public_site_absent "after check and verify"

# --- Apply ------------------------------------------------------------------------------

run_public_http apply
result_is "--apply installs the public site" 0 '.status == "pass" and .state == "http-acme-ready" and .changed and .reloaded and (.rolled_back | not) and .hostnames == ["'"${PUBLIC_HOST}"'"] and .lifecycle == "planned"'
master_kept "apply"
expect "apply reloaded Nginx once" "$(grep -c '^reload nginx$' "${WORK}/systemctl.log" || true)" 1
expect "the public site's file is root's, 0644, a regular file" "$(stat -c '%U:%G:%a:%F' "${PUBLIC_FILE}")" "root:root:644:regular file"
expect "the public site's link leads to its file" "$(readlink "${PUBLIC_LINK}")" "${PUBLIC_FILE}"

for address in 127.0.0.1 '[::1]'; do
    expect "${PUBLIC_HOST} on ${address}: its challenge token, from the webroot, as text" \
        "$(answer "${address}" "${PUBLIC_HOST}" "/.well-known/acme-challenge/${TOKEN}")" "200|text/plain||${TOKEN_BODY}"
    expect "${PUBLIC_HOST} on ${address}: / is 503 with Retry-After" \
        "$(answer "${address}" "${PUBLIC_HOST}" /)" "503|text/plain|3600|Service Unavailable~"
    expect "${PUBLIC_HOST} on ${address}: /index.php is the same 503" \
        "$(answer "${address}" "${PUBLIC_HOST}" /index.php)" "503|text/plain|3600|Service Unavailable~"
done
expect "${PUBLIC_HOST}: an absent token is 404" "$(answer 127.0.0.1 "${PUBLIC_HOST}" /.well-known/acme-challenge/absent-token | cut -d'|' -f1)" 404
expect "${PUBLIC_HOST}: a PHP file in the challenge directory is never served or run" "$(answer 127.0.0.1 "${PUBLIC_HOST}" /.well-known/acme-challenge/probe.php | cut -d'|' -f1)" 404
got="$(answer 127.0.0.1 "${PUBLIC_HOST}" /.well-known/acme-challenge/scenario-link-token)"
if [[ "${got%%|*}" =~ ^(403|404)$ ]]; then
    pass "${PUBLIC_HOST}: a token that is a link out of the webroot is refused (${got%%|*})"
else
    fail "${PUBLIC_HOST}: a token that is a link out of the webroot answered ${got}"
fi
expect "${PUBLIC_HOST}: the challenge directory itself is 404" "$(answer 127.0.0.1 "${PUBLIC_HOST}" /.well-known/acme-challenge/ | cut -d'|' -f1)" 404
expect "${PUBLIC_HOST}: a path climbing out of the challenge directory is the 503" \
    "$(answer 127.0.0.1 "${PUBLIC_HOST}" /.well-known/acme-challenge/../../index.nginx-debian.html | cut -d'|' -f1,3)" "503|3600"
expect "${PUBLIC_HOST}: the webroot outside the challenge directory is never served" \
    "$(answer 127.0.0.1 "${PUBLIC_HOST}" /index.nginx-debian.html | cut -d'|' -f1,3)" "503|3600"

expect_mx "after apply"
expect "after apply: an unlisted name still reaches the default site" "$(answer 127.0.0.1 unlisted.invalid / | cut -d'|' -f1)" 200
expect "after apply: the staging mail capture answers as before" "$(answer 127.0.0.1 mailpit.staging.myprojects.pp.ua /)" "${STAGING_BEFORE}"
expect "after apply: the internal site answers as before" "$(answer 127.0.0.1 "${INTERNAL_HOST}" /)" "${INTERNAL_BEFORE}"
expect "after apply: every other file under /etc/nginx is unchanged" "$(etc_snapshot)" "${ETC_BEFORE}"
expect "after apply: the webroot is unchanged" "$(webroot_snapshot)" "${WEBROOT_BEFORE}"

# --- Again: nothing to do ---------------------------------------------------------------

IDENTITY="$(stat -c '%i:%Y' "${PUBLIC_FILE}")"
: >"${WORK}/systemctl.log"
run_public_http apply
result_is "a second --apply is a no-op" 0 '.status == "pass" and .state == "http-acme-ready" and (.changed | not) and (.reloaded | not)'
run_public_http verify
result_is "--verify passes on the public site" 0 '.status == "pass" and .state == "http-acme-ready" and (.changed | not)'
run_public_http check
result_is "--check passes on the public site" 0 '.status == "pass" and .state == "http-acme-ready"'
expect "the no-op, verify and check reloaded nothing" "$(grep -c '^reload' "${WORK}/systemctl.log" || true)" 0
expect "the no-op rewrote nothing" "$(stat -c '%i:%Y' "${PUBLIC_FILE}")" "${IDENTITY}"

# --- The HTTPS installer takes the site over ------------------------------------------------

# The next state's installer keeps the public site's file and link, and its
# first line, and puts an HTTPS configuration in them. From then on this script
# refuses both ways: nothing written, nothing removed, nothing reloaded.
cp -p "${PUBLIC_FILE}" "${WORK}/public-http-render"
{
    head -n 1 "${WORK}/public-http-render"
    cat <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${PUBLIC_HOST};

    location ~ ^/\.well-known/acme-challenge/[A-Za-z0-9_-]+\$ {
        root /var/www/html;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${PUBLIC_HOST};

    ssl_certificate ${WORK}/certs/fullchain.pem;
    ssl_certificate_key ${WORK}/certs/privkey.pem;

    default_type text/plain;
    return 200 "https stand-in\n";
}
EOF
} >"${PUBLIC_FILE}"
nginx -t >/dev/null 2>&1 && nginx -s reload 2>/dev/null
sleep 1

https_answer() {
    curl --silent --insecure --noproxy '*' --max-time 5 --resolve "${PUBLIC_HOST}:443:127.0.0.1" "https://${PUBLIC_HOST}/" 2>/dev/null || true
}

HTTPS_SITE="$(sha256sum <"${PUBLIC_FILE}")"
expect "the HTTPS site that took the public site over answers on 443" "$(https_answer)" "https stand-in"
: >"${WORK}/systemctl.log"
for mode in apply rollback; do
    run_public_http "${mode}"
    result_is "--${mode} refuses the HTTPS site that took the public site over" 1 '.status == "fail" and .state == "foreign" and (.changed | not) and (.reloaded | not) and (.rolled_back | not)'
    expect "--${mode} left the HTTPS site's file as it was" "$(sha256sum <"${PUBLIC_FILE}")" "${HTTPS_SITE}"
    expect "--${mode} left its link" "$(readlink "${PUBLIC_LINK}")" "${PUBLIC_FILE}"
done
expect "neither reloaded Nginx" "$(grep -c '^reload' "${WORK}/systemctl.log" || true)" 0
expect "the HTTPS site still answers on 443" "$(https_answer)" "https stand-in"
expect_mx "with the HTTPS site in place"
master_kept "the HTTPS takeover"

# Back to the HTTP state, for the rest of the scenario.
cp -p "${WORK}/public-http-render" "${PUBLIC_FILE}"
nginx -s reload 2>/dev/null
sleep 1

# --- Rollback -------------------------------------------------------------------------------

: >"${WORK}/systemctl.log"
touch "${WORK}/reload-fail-once"
run_public_http rollback
result_is "a rollback whose reload fails puts the site back" 1 '.status == "fail" and .rolled_back and .state == "http-acme-ready"'
expect "the failed rollback's site file is back" "$(readlink "${PUBLIC_LINK}")" "${PUBLIC_FILE}"
expect "after the failed rollback ${PUBLIC_HOST} is still the 503" "$(answer 127.0.0.1 "${PUBLIC_HOST}" / | cut -d'|' -f1,3)" "503|3600"
expect_mx "after the failed rollback"

: >"${WORK}/systemctl.log"
run_public_http rollback
result_is "--rollback removes the public site" 0 '.status == "pass" and .state == "not-prepared" and .changed and .reloaded'
public_site_absent "after rollback"
master_kept "rollback"
expect "rollback reloaded Nginx once" "$(grep -c '^reload nginx$' "${WORK}/systemctl.log" || true)" 1
expect_not_public "after rollback"
expect_mx "after rollback"
expect "after rollback: every other file under /etc/nginx is unchanged" "$(etc_snapshot)" "${ETC_BEFORE}"
expect "after rollback: the webroot is unchanged" "$(webroot_snapshot)" "${WEBROOT_BEFORE}"

: >"${WORK}/systemctl.log"
run_public_http rollback
result_is "a second --rollback is a no-op" 0 '.status == "pass" and .state == "not-prepared" and (.changed | not) and (.reloaded | not)'
expect "the no-op rollback reloaded nothing" "$(grep -c '^reload' "${WORK}/systemctl.log" || true)" 0

# --- Failures put the previous state back ------------------------------------------------------

touch "${WORK}/nginx-t-fails-with-public"
run_public_http apply
result_is "nginx -t failing on the candidate refuses and restores" 1 '.status == "fail" and .rolled_back and (.reloaded | not) and .state == "not-prepared"'
rm -f "${WORK}/nginx-t-fails-with-public"
public_site_absent "after the refused candidate"
expect_not_public "after the refused candidate"

: >"${WORK}/systemctl.log"
touch "${WORK}/reload-fail-once"
run_public_http apply
result_is "a failed reload restores the previous state" 1 '.status == "fail" and .rolled_back and .state == "not-prepared"'
public_site_absent "after the failed reload"
expect_not_public "after the failed reload"
expect_mx "after the failed reload"
master_kept "after every failure"

# --- The order of the sites ------------------------------------------------------------------

# Without an explicit default_server, the server for unlisted names is the
# first loaded: refused, whatever that is today.
cp -p /etc/nginx/sites-available/default "${WORK}/default.shipped"
sed -i 's/^\(\s*listen [^;#]*\) default_server;/\1;/' /etc/nginx/sites-available/default
nginx -s reload
sleep 1
run_public_http check
result_is "--check refuses without an explicit default_server" 1 '.status == "fail"'
if grep -q 'FAIL *nginx:default:\*:80' <<<"${OUTPUT}"; then pass "it names the missing default_server"; else fail "it does not name the missing default_server"; show_output; fi
run_public_http apply
result_is "--apply refuses without an explicit default_server, changing nothing" 1 '.status == "fail" and (.changed | not) and (.rolled_back | not)'
public_site_absent "after the refusal"

# And the hazard is real: with only sites that load after the public site's,
# the public site would become the server for the MX host's challenge.
mkdir -m 0755 "${WORK}/parked"
for entry in /etc/nginx/sites-enabled/*; do mv "${entry}" "${WORK}/parked/"; done
ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/zz-default
nginx -s reload
sleep 1
expect_mx "only zz-default"
run_public_http check
result_is "--check refuses where the public site would load first" 1 '.status == "fail"'
(
    source "${SCRIPTS}/prepare-public-http"
    TARGET_ID="${TARGET}"; MODE=check
    load_target >/dev/null
    render_public_site
) >"${WORK}/public-render"
cp "${WORK}/public-render" "${PUBLIC_FILE}"
ln -s "${PUBLIC_FILE}" "${PUBLIC_LINK}"
nginx -s reload
sleep 1
got="$(answer 127.0.0.1 "${MX_HOST}" /)"
if [[ "$(cut -d'|' -f1,3 <<<"${got}")" == "503|3600" ]]; then
    pass "the hazard --check refuses is real: loaded first, the public site becomes the server for ${MX_HOST}"
else
    fail "the hazard did not reproduce: ${MX_HOST} answered ${got}"
fi
rm -f "${PUBLIC_LINK}" "${PUBLIC_FILE}" /etc/nginx/sites-enabled/zz-default
for entry in "${WORK}/parked"/*; do mv "${entry}" /etc/nginx/sites-enabled/; done
cp -p "${WORK}/default.shipped" /etc/nginx/sites-available/default
nginx -s reload
sleep 1
expect "the default site is back as the package ships it" "$(etc_snapshot)" "${ETC_BEFORE}"
expect_mx "with the default site back"

# Another site claiming the public hostname.
printf 'server {\n    listen 80;\n    server_name %s;\n    return 200 "claimed";\n}\n' "${PUBLIC_HOST}" >/etc/nginx/sites-available/claim
ln -s /etc/nginx/sites-available/claim /etc/nginx/sites-enabled/claim
nginx -s reload
sleep 1
run_public_http apply
result_is "--apply refuses while another site claims ${PUBLIC_HOST}" 1 '.status == "fail" and (.changed | not)'
if grep -q "CONFLICT nginx:names" <<<"${OUTPUT}"; then pass "it names the conflicting site"; else fail "it does not name the conflicting site"; show_output; fi
public_site_absent "after the conflict"
rm -f /etc/nginx/sites-enabled/claim /etc/nginx/sites-available/claim
nginx -s reload
sleep 1

# --- HTTPS: tls-ready -------------------------------------------------------------------------

# A certificate for tits.guru from a test authority, laid out as Certbot lays
# it out: the archive, the live links into it, the renewal configuration
# through the webroot, and the deploy hook directory beside the inbound mail
# listener's own hook. The authority is the only one trusted.
LE="${WORK}/letsencrypt"
mkdir -m 0755 "${WORK}/ca" "${LE}" "${LE}/renewal" "${LE}/renewal-hooks" "${LE}/renewal-hooks/deploy"
mkdir -m 0700 "${LE}/live" "${LE}/archive" "${LE}/live/${PUBLIC_HOST}" "${LE}/archive/${PUBLIC_HOST}"
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=RateGuru scenario authority" \
    -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
    -keyout "${WORK}/ca/ca.key" -out "${WORK}/ca/ca.pem" >/dev/null 2>&1

# issue VERSION — version VERSION of the pair, and the live links moved to it, as a renewal does.
issue() {
    local version="$1" archive="${LE}/archive/${PUBLIC_HOST}" part
    openssl req -newkey rsa:2048 -nodes -subj "/CN=${PUBLIC_HOST}" \
        -keyout "${archive}/privkey${version}.pem" -out "${WORK}/ca/leaf.csr" >/dev/null 2>&1
    chmod 0600 "${archive}/privkey${version}.pem"
    printf 'subjectAltName=DNS:%s\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' "${PUBLIC_HOST}" >"${WORK}/ca/leaf.ext"
    openssl x509 -req -in "${WORK}/ca/leaf.csr" -CA "${WORK}/ca/ca.pem" -CAkey "${WORK}/ca/ca.key" -set_serial "100${version}" \
        -days 60 -extfile "${WORK}/ca/leaf.ext" -out "${archive}/cert${version}.pem" >/dev/null 2>&1
    cp "${WORK}/ca/ca.pem" "${archive}/chain${version}.pem"
    cat "${archive}/cert${version}.pem" "${archive}/chain${version}.pem" >"${archive}/fullchain${version}.pem"
    for part in cert chain fullchain privkey; do
        ln -sfn "../../archive/${PUBLIC_HOST}/${part}${version}.pem" "${LE}/live/${PUBLIC_HOST}/${part}.pem"
    done
}
issue 1
printf '[renewalparams]\nauthenticator = webroot\nwebroot_path = /var/www/html,\n' >"${LE}/renewal/${PUBLIC_HOST}.conf"
printf '#!/bin/bash\n# the inbound mail listener'"'"'s own renewal hook\nexit 0\n' >"${LE}/renewal-hooks/deploy/rateguru-mail-inbound-tls"
chmod 0755 "${LE}/renewal-hooks/deploy/rateguru-mail-inbound-tls"
HOOK="${LE}/renewal-hooks/deploy/rateguru-public-https-${TARGET}"

# https_answer ADDRESS HOST PATH — "STATUS|RETRY-AFTER|CACHE-CONTROL|BODY" over a
# verified HTTPS connection, the scenario authority alone trusted.
https_answer() {
    local headers body code
    headers="${WORK}/https.headers"
    body="${WORK}/https.body"
    code="$(curl --silent --globoff --noproxy '*' --max-time 5 --cacert "${WORK}/ca/ca.pem" --resolve "$2:443:$1" \
        -o "${body}" -D "${headers}" -w '%{http_code}' "https://$2$3" 2>/dev/null)" || code=000
    printf '%s|%s|%s|%s\n' "${code}" \
        "$(tr -d '\r' <"${headers}" | awk -F': *' 'tolower($1) == "retry-after" { print $2 }')" \
        "$(tr -d '\r' <"${headers}" | awk -F': *' 'tolower($1) == "cache-control" { print $2 }')" \
        "$(head -c 200 "${body}" | tr '\n' '~')"
}

# others_https ADDRESS — what staging's HTTPS sites and a name nobody lists answer.
others_https() {
    local name
    for name in rateguru.staging.myprojects.pp.ua mailpit.staging.myprojects.pp.ua mailtrap.staging.myprojects.pp.ua unlisted.invalid; do
        printf '%s %s\n' "${name}" "$(curl --silent --insecure --noproxy '*' --max-time 5 --resolve "${name}:443:$1" -o /dev/null -w '%{http_code}' "https://${name}/" 2>/dev/null || echo 000)"
    done
}

served_serial() {
    openssl s_client -connect 127.0.0.1:443 -servername "${PUBLIC_HOST}" </dev/null 2>/dev/null | openssl x509 -noout -serial 2>/dev/null
}

certbot_tree() {
    (cd "${LE}" && find . -path "./renewal-hooks/deploy/rateguru-public-https-${TARGET}" -prune -o -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum)
}

run_public_http apply
result_is "HTTPS: the public site starts from http-acme-ready" 0 '.status == "pass" and .state == "http-acme-ready"'
OTHERS_BEFORE="$(others_https 127.0.0.1; others_https '[::1]')"
ETC_BEFORE_HTTPS="$(etc_snapshot)"
CERTBOT_BEFORE="$(certbot_tree)"

run_public_https check
result_is "HTTPS: --check passes, the certificate valid" 0 '.status == "pass" and .state == "http-acme-ready" and .certificate == "valid"'

: >"${WORK}/systemctl.log"
run_public_https apply
result_is "HTTPS: --apply takes the site to tls-ready" 0 '.status == "pass" and .state == "tls-ready" and .certificate == "valid" and .hook == "installed" and .changed and .reloaded'
master_kept "HTTPS apply"
expect "HTTPS: apply reloaded Nginx once" "$(grep -c '^reload nginx$' "${WORK}/systemctl.log" || true)" 1
for address in 127.0.0.1 '[::1]'; do
    expect "HTTPS: ${PUBLIC_HOST} on ${address}, a verified certificate: / is 503 with Retry-After and no-store" \
        "$(https_answer "${address}" "${PUBLIC_HOST}" /)" "503|3600|no-store|Service Unavailable~"
    expect "HTTPS: ${PUBLIC_HOST} on ${address}: /index.php is the same 503" \
        "$(https_answer "${address}" "${PUBLIC_HOST}" /index.php | cut -d'|' -f1)" 503
    expect "HTTPS: ${PUBLIC_HOST} on ${address}: HTTP is a 301 to HTTPS" \
        "$(curl --silent --globoff --noproxy '*' -o /dev/null -w '%{http_code} %{redirect_url}' -H "Host: ${PUBLIC_HOST}" "http://${address}/somewhere?x=1")" \
        "301 https://${PUBLIC_HOST}/somewhere?x=1"
    expect "HTTPS: ${PUBLIC_HOST} on ${address}: its challenge token, still from the webroot over HTTP" \
        "$(answer "${address}" "${PUBLIC_HOST}" "/.well-known/acme-challenge/${TOKEN}")" "200|text/plain||${TOKEN_BODY}"
done
expect_mx "HTTPS, tls-ready"
expect "HTTPS: staging's HTTPS sites and the default server answer as before" "$(others_https 127.0.0.1; others_https '[::1]')" "${OTHERS_BEFORE}"
expect "HTTPS: every other file under /etc/nginx is unchanged" "$(etc_snapshot)" "${ETC_BEFORE_HTTPS}"
expect "HTTPS: Certbot's certificates, renewal configuration and the mail hook are unchanged" "$(certbot_tree)" "${CERTBOT_BEFORE}"
expect "HTTPS: the renewal hook is root's, 0755" "$(stat -c '%U:%a' "${HOOK}")" "root:755"

for mode in apply rollback; do
    run_public_http "${mode}"
    if [[ "${STATUS}" == 1 ]] && grep -q 'it holds an HTTPS configuration' <<<"${OUTPUT}"; then
        pass "HTTPS: prepare-public-http --${mode} refuses the HTTPS site"
    else
        fail "HTTPS: prepare-public-http --${mode} did not refuse the HTTPS site (exit ${STATUS})"
        show_output
    fi
done

: >"${WORK}/systemctl.log"
run_public_https apply
result_is "HTTPS: a second --apply is a no-op" 0 '.status == "pass" and .state == "tls-ready" and (.changed | not) and (.reloaded | not)'
run_public_https verify
result_is "HTTPS: --verify passes on tls-ready" 0 '.status == "pass" and .state == "tls-ready" and .hook == "installed"'
expect "HTTPS: the no-op and verify reloaded nothing" "$(grep -c '^reload' "${WORK}/systemctl.log" || true)" 0

# Certbot renews tits.guru: the hook judges the renewed pair, runs nginx -t and
# reloads; the renewed certificate is the one served.
expect "HTTPS: version 1 is served" "$(served_serial)" "serial=03E9"
issue 2
: >"${WORK}/systemctl.log"
hook_output="$(env -i PATH="${WORK}/bin:/usr/sbin:/usr/bin:/sbin:/bin" RENEWED_LINEAGE="${LE}/live/${PUBLIC_HOST}" bash "${HOOK}" 2>&1)" && hook_status=0 || hook_status=$?
expect "HTTPS: the hook passes for the renewed ${PUBLIC_HOST}" "${hook_status}" 0
expect "HTTPS: and reloads Nginx once" "$(grep -c '^reload nginx$' "${WORK}/systemctl.log" || true)" 1
sleep 1
expect "HTTPS: the renewed certificate is served" "$(served_serial)" "serial=03EA"
master_kept "HTTPS renewal"
: >"${WORK}/systemctl.log"
hook_output="$(env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin RENEWED_LINEAGE="${LE}/live/${MX_HOST}" bash "${HOOK}" 2>&1)" && hook_status=0 || hook_status=$?
expect "HTTPS: for a renewal of ${MX_HOST} the hook does nothing" "${hook_status}|${hook_output}|$(wc -l <"${WORK}/systemctl.log")" "0||0"

# GO LIVE: refused for a planned target.
run_public_https activate --confirm-public
result_is "HTTPS: GO LIVE is refused for a planned target" 1 '.status == "fail" and .state == "tls-ready" and (.changed | not)'

# The public-active render is valid Nginx: checked in place, without a reload.
cp -p "${PUBLIC_FILE}" "${WORK}/tls-ready-render"
(
    export RATEGURU_ALLOW_TEST_OVERRIDES=true RATEGURU_PUBLICHTTPS_LETSENCRYPT="${LE}"
    source "${SCRIPTS}/prepare-public-https"
    TARGET_ID="${TARGET}"; MODE=check
    load_target >/dev/null
    load_https_target
    render_https_site public-active
) >"${PUBLIC_FILE}"
if grep -q 'fastcgi_pass unix:' "${PUBLIC_FILE}" && nginx -t >/dev/null 2>&1; then
    pass "HTTPS: the public-active render passes nginx -t"
else
    fail "HTTPS: the public-active render fails nginx -t: $(nginx -t 2>&1 | head -1)"
fi
cp -p "${WORK}/tls-ready-render" "${PUBLIC_FILE}"

# A rollback whose reload fails keeps tls-ready.
touch "${WORK}/reload-fail-once"
run_public_https rollback
result_is "HTTPS: a rollback whose reload fails puts tls-ready back" 1 '.status == "fail" and .rolled_back and .state == "tls-ready"'
expect "HTTPS: after the failed rollback, still the HTTPS 503" "$(https_answer 127.0.0.1 "${PUBLIC_HOST}" / | cut -d'|' -f1)" 503

: >"${WORK}/systemctl.log"
run_public_https rollback
result_is "HTTPS: --rollback returns the exact http-acme-ready" 0 '.status == "pass" and .state == "http-acme-ready" and .hook == "absent" and .changed and .reloaded'
expect "HTTPS: the renewal hook is gone" "$([[ -e "${HOOK}" ]] && echo present || echo absent)" absent
expect "HTTPS: the certificate is kept" "$([[ -f "${LE}/archive/${PUBLIC_HOST}/privkey2.pem" && -L "${LE}/live/${PUBLIC_HOST}/fullchain.pem" ]] && echo kept || echo gone)" kept
expect "HTTPS: after rollback, HTTP is the 503 again" "$(answer 127.0.0.1 "${PUBLIC_HOST}" / | cut -d'|' -f1,3)" "503|3600"
expect "HTTPS: after rollback, staging's HTTPS sites and the default server answer as before" "$(others_https 127.0.0.1; others_https '[::1]')" "${OTHERS_BEFORE}"
expect_mx "HTTPS, after rollback"
run_public_http verify
result_is "HTTPS: prepare-public-http owns and verifies the site again" 0 '.status == "pass" and .state == "http-acme-ready"'

# nginx -t refusing the HTTPS candidate puts http-acme-ready back, unreloaded.
touch "${WORK}/nginx-t-fails-with-https"
: >"${WORK}/systemctl.log"
run_public_https apply
result_is "HTTPS: nginx -t refusing the candidate restores http-acme-ready" 1 '.status == "fail" and .rolled_back and .state == "http-acme-ready" and .hook == "absent"'
rm -f "${WORK}/nginx-t-fails-with-https"
expect "HTTPS: the refused candidate reloaded nothing" "$(grep -c '^reload' "${WORK}/systemctl.log" || true)" 0
expect "HTTPS: and left no hook" "$([[ -e "${HOOK}" ]] && echo present || echo absent)" absent
master_kept "HTTPS"

run_public_http rollback
result_is "HTTPS: back to not-prepared for what follows" 0 '.status == "pass" and .state == "not-prepared"'

# --- Never anything but a reload ---------------------------------------------------------------

master_kept "the whole scenario"
if [[ -s "${WORK}/systemctl-all.log" && -z "$(grep -Ev '^(is-active --quiet nginx|reload nginx)$' "${WORK}/systemctl-all.log" || true)" ]]; then
    pass "systemd was asked only whether Nginx is active, and to reload it"
else
    fail "systemd was asked: $(grep -Ev '^(is-active --quiet nginx|reload nginx)$' "${WORK}/systemctl-all.log" | tr '\n' ';')"
fi

# --- A host that disables IPv6 ------------------------------------------------------------

# As the production host runs: net.ipv6.conf.all and lo disable_ipv6=1, no ::1
# on lo. Nginx still binds [::]:80, and nothing answers on [::1]. Disabled in
# this namespace only, after the rest of the scenario, by a fresh Nginx.
nginx -s quit 2>/dev/null || true
for _ in $(seq 1 50); do
    [[ -s /run/nginx.pid ]] && kill -0 "$(cat /run/nginx.pid)" 2>/dev/null || break
    sleep 0.1
done
sysctl -qw net.ipv6.conf.all.disable_ipv6=1 net.ipv6.conf.lo.disable_ipv6=1
expect "IPv4-only: IPv6 is disabled on loopback" "$(cat /proc/sys/net/ipv6/conf/lo/disable_ipv6)" 1
expect "IPv4-only: ::1 is gone from lo" "$(grep -c ' lo$' /proc/net/if_inet6 || true)" 0
nginx
sleep 1
MASTER="$(cat /run/nginx.pid)"
expect "IPv4-only: Nginx is bound to [::]:80 all the same, and nothing answers on [::1]" \
    "$(answer '[::1]' "${MX_HOST}" "/.well-known/acme-challenge/${TOKEN}" | cut -d'|' -f1)" 000

expect_mx_ipv4() {
    local got
    got="$(answer 127.0.0.1 "${MX_HOST}" "/.well-known/acme-challenge/${TOKEN}")"
    if [[ "${got%%|*}" == 200 && "${got##*|}" == "${TOKEN_BODY}" ]]; then
        pass "$1: ${MX_HOST} on 127.0.0.1 serves its challenge from the webroot"
    else
        fail "$1: ${MX_HOST} on 127.0.0.1 answered ${got}"
    fi
}

expect_mx_ipv4 "IPv4-only, before"
: >"${WORK}/systemctl.log"

run_public_http check
result_is "IPv4-only: --check passes" 0 '.status == "pass" and .state == "not-prepared" and .ipv6_loopback == "disabled"'
if grep -q 'SKIP *probes:\[::1\]' <<<"${OUTPUT}" && grep -q 'PASS *route:mx1.tits.guru:\[::\]:80' <<<"${OUTPUT}"; then
    pass "IPv4-only: it says the [::1] probes are skipped, and still judges the [::]:80 route"
else
    fail "IPv4-only: the report does not show the skipped [::1] probes and the [::]:80 route"
    show_output
fi

run_public_http apply
result_is "IPv4-only: --apply installs the public site" 0 '.status == "pass" and .state == "http-acme-ready" and .changed and .reloaded and .ipv6_loopback == "disabled"'
expect "IPv4-only: tits.guru's challenge token, from the webroot" \
    "$(answer 127.0.0.1 "${PUBLIC_HOST}" "/.well-known/acme-challenge/${TOKEN}")" "200|text/plain||${TOKEN_BODY}"
expect "IPv4-only: tits.guru / is 503 with Retry-After" "$(answer 127.0.0.1 "${PUBLIC_HOST}" /)" "503|text/plain|3600|Service Unavailable~"
expect_mx_ipv4 "IPv4-only, after apply"

: >"${WORK}/systemctl.log"
run_public_http apply
result_is "IPv4-only: a second --apply is a no-op" 0 '.status == "pass" and .state == "http-acme-ready" and (.changed | not) and (.reloaded | not)'
run_public_http verify
result_is "IPv4-only: --verify passes" 0 '.status == "pass" and .state == "http-acme-ready" and .ipv6_loopback == "disabled"'
expect "IPv4-only: the no-op and verify reloaded nothing" "$(grep -c '^reload' "${WORK}/systemctl.log" || true)" 0

# HTTPS on the same host: tls-ready on 127.0.0.1 alone, and back.
run_public_https apply
result_is "IPv4-only: HTTPS --apply takes the site to tls-ready" 0 '.status == "pass" and .state == "tls-ready" and .ipv6_loopback == "disabled"'
expect "IPv4-only: HTTPS answers with a verified certificate and the 503" "$(https_answer 127.0.0.1 "${PUBLIC_HOST}" / | cut -d'|' -f1-3)" "503|3600|no-store"
expect_mx_ipv4 "IPv4-only, tls-ready"
run_public_https verify
result_is "IPv4-only: HTTPS --verify passes" 0 '.status == "pass" and .state == "tls-ready" and .ipv6_loopback == "disabled"'
run_public_https rollback
result_is "IPv4-only: HTTPS --rollback returns http-acme-ready" 0 '.status == "pass" and .state == "http-acme-ready"'
: >"${WORK}/systemctl.log"


run_public_http rollback
result_is "IPv4-only: --rollback removes the public site" 0 '.status == "pass" and .state == "not-prepared" and .changed and .reloaded and .ipv6_loopback == "disabled"'
public_site_absent "IPv4-only, after rollback"
got="$(answer 127.0.0.1 "${PUBLIC_HOST}" /)"
if [[ "${got%%|*}" != 503 && "${got%%|*}" != 000 ]]; then
    pass "IPv4-only, after rollback: ${PUBLIC_HOST} is not answered by the public site (${got%%|*})"
else
    fail "IPv4-only, after rollback: ${PUBLIC_HOST} answered ${got}"
fi
expect_mx_ipv4 "IPv4-only, after rollback"
master_kept "IPv4-only"
expect "IPv4-only: systemd was asked only whether Nginx is active, and to reload it" \
    "$(grep -Evc '^(is-active --quiet nginx|reload nginx)$' "${WORK}/systemctl.log" || true)" 0
expect "IPv4-only: every other file under /etc/nginx is unchanged" "$(etc_snapshot)" "${ETC_BEFORE}"

nginx -s quit || true

if (( FAILURES == 0 )); then
    echo "SCENARIO: PASS"
else
    echo "SCENARIO: FAIL (${FAILURES})"
    exit 1
fi
