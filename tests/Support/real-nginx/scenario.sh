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
#   * nginx -t failing on the candidate, or the reload failing, puts the
#     previous state back and Nginx answers as before;
#   * the order of the sites: with no explicit default_server, --check refuses
#     — and the hazard it refuses is real: where the public site would load
#     first, Nginx hands it mx1.tits.guru's challenge;
#   * another site claiming tits.guru refuses;
#   * --rollback removes exactly the site's file and link with one reload, a
#     failed reload puts them back, and a second --rollback is a no-op;
#   * Nginx is reloaded only: never started, stopped or restarted.
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

# --- Never anything but a reload ---------------------------------------------------------------

master_kept "the whole scenario"
if [[ -s "${WORK}/systemctl-all.log" && -z "$(grep -Ev '^(is-active --quiet nginx|reload nginx)$' "${WORK}/systemctl-all.log" || true)" ]]; then
    pass "systemd was asked only whether Nginx is active, and to reload it"
else
    fail "systemd was asked: $(grep -Ev '^(is-active --quiet nginx|reload nginx)$' "${WORK}/systemctl-all.log" | tr '\n' ';')"
fi

nginx -s quit || true

if (( FAILURES == 0 )); then
    echo "SCENARIO: PASS"
else
    echo "SCENARIO: FAIL (${FAILURES})"
    exit 1
fi
