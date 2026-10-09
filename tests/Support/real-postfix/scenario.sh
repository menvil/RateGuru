#!/usr/bin/env bash
#
# The host's one Postfix, with inbound mail, on a REAL Postfix.
#
#   scenario.sh REPOSITORY WORK
#
# Run as root, in a network and mount namespace of its own (`unshare --net
# --mount`): only loopback exists, and the "public" address 192.0.2.25 is put
# on it, so no message can leave and no DNS answers; and the one file Postfix
# reads from /etc/postfix is added to a copy bind-mounted there, so the host's
# own /etc/postfix is never written. WORK is an empty directory.
#
# The configuration is the one install-mail-gateway renders — its own
# render_gateway_config, sourced, from the repository's reviewed documents and
# `mail-inbound render-receiver` — written into WORK/cfg, with a queue, data
# directory, log, inbound store and certificate of its own under WORK. The only
# lines added are where the queue, data and log of this run live. Postfix then
# runs it, and every claim is checked against what Postfix does:
#
#   * Postfix accepts the configuration (postconf, postfix check), and the
#     gateway's own read-back — the outbound contract and every inbound
#     requirement — passes against what the real postconf reads;
#   * the outbound part is exactly what it is without inbound mail, as the
#     real postconf reads both;
#   * outbound: a submission listener still takes its own sender, and mail it
#     takes for a domain this host receives for leaves through its own route,
#     never into the store; staging mail reaches the capture destination;
#   * inbound: every address of a corpus is answered at RCPT TO exactly as
#     `mail-inbound route` judges it, on the loopback listener and the public
#     one, no AUTH and no ETRN are offered, the size and recipient limits and
#     the per-client connection limit hold, the null sender is accepted,
#     STARTTLS is offered on the public listener, relaying is refused;
#   * storage: accepted mail is delivered by virtual(8) into the Maildir its
#     destination names — the bare <Postmaster> into the host's own — and
#     waits in the queue while the store cannot be written, to be delivered
#     once it can;
#   * the shared queue: the inbound listeners refuse new mail below their own
#     queue floor while the submission listeners still accept;
#   * no backscatter: a bounce to a forged sender has no route off the host;
#   * a reload to the disabled state closes TCP 25 without restarting Postfix,
#     and the submission listeners keep working.
#
# Prints PASS and FAIL lines, and exits non-zero on any FAIL.

set -Eeuo pipefail

REPOSITORY="$1"
WORK="$2"
SCRIPTS="${REPOSITORY}/infrastructure/scripts"
CONFIG="${REPOSITORY}/infrastructure/config"
PUBLIC="192.0.2.25"
FAILURES=0

pass() { printf 'PASS %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
expect() { if [[ "$2" == "$3" ]]; then pass "$1"; else fail "$1 — got \"$2\", expected \"$3\""; fi; }

[[ "${EUID}" == 0 ]] || { echo "scenario.sh runs as root, in a network namespace of its own" >&2; exit 2; }
# The gateway splices these paths into Postfix syntax, and takes only plain
# lowercase ones.
[[ "${WORK}" =~ ^/[a-z0-9/._-]+$ && -d "${WORK}" ]] || { echo "WORK must be an existing directory with a plain lowercase path: ${WORK}" >&2; exit 2; }
for tool in postfix postconf python3 openssl jq; do
    command -v "${tool}" >/dev/null || { echo "scenario.sh needs ${tool}" >&2; exit 2; }
done

for namespace in net mnt; do
    if [[ "$(readlink "/proc/self/ns/${namespace}")" == "$(readlink "/proc/1/ns/${namespace}")" ]]; then
        echo "scenario.sh runs in a network and mount namespace of its own — under unshare --net --mount" >&2
        exit 2
    fi
done
[[ -d /etc/postfix ]] || { echo "scenario.sh needs the Postfix package's /etc/postfix" >&2; exit 2; }

ip link set lo up
ip addr add "${PUBLIC}/32" dev lo
if ip -4 route get 1.1.1.1 >/dev/null 2>&1; then
    echo "this namespace has a route off the host — run scenario.sh under unshare --net --mount" >&2
    exit 2
fi

CFG="${WORK}/cfg"
mkdir -p "${CFG}" "${WORK}/queue" "${WORK}/log" "${WORK}/tls" "${WORK}/bin"
install -d -o postfix -g postfix -m 0700 "${WORK}/data"
chmod 0755 "${WORK}"
STORE="${WORK}/store"
install -d -o nobody -g "$(id -gn nobody)" -m 0700 "${STORE}"

cleanup() {
    postfix -c "${CFG}" stop >/dev/null 2>&1 || true
    [[ -n "${MILTER_PID:-}" ]] && kill "${MILTER_PID}" 2>/dev/null || true
    [[ -n "${SINK_PID:-}" ]] && kill "${SINK_PID}" 2>/dev/null || true
}
trap cleanup EXIT

# --- Stand-ins for what is not Postfix ------------------------------------------
#
# The DKIM signer (a milter that accepts everything) and the capture
# destination (an SMTP sink that keeps what it receives), and an SMTP client.

cat > "${WORK}/bin/milter.py" <<'PY'
import socket, struct, threading
def handle(c):
    try:
        while True:
            h = c.recv(4, socket.MSG_WAITALL)
            if len(h) < 4: return
            d = c.recv(struct.unpack('!I', h)[0], socket.MSG_WAITALL)
            if d[:1] == b'O': p = b'O' + struct.pack('!III', 6, 0, 0)
            elif d[:1] in (b'D', b'A'): continue
            elif d[:1] == b'Q': return
            else: p = b'c'
            c.sendall(struct.pack('!I', len(p)) + p)
    finally:
        c.close()
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('127.0.0.1', 8891)); s.listen(16)
while True:
    c, _ = s.accept(); threading.Thread(target=handle, args=(c,), daemon=True).start()
PY

cat > "${WORK}/bin/sink.py" <<'PY'
import socket, sys, threading
out = sys.argv[1]
def handle(c):
    f = c.makefile('rb'); say = lambda l: c.sendall((l + '\r\n').encode())
    say('220 sink ESMTP')
    while True:
        line = f.readline()
        if not line: break
        verb = line[:4].upper()
        if verb in (b'EHLO', b'HELO'): say('250 sink')
        elif verb == b'DATA':
            say('354 go'); body = b''
            while True:
                l = f.readline()
                if l in (b'.\r\n', b'.\n', b''): break
                body += l
            open(out, 'ab').write(body + b'\n--\n'); say('250 2.0.0 kept')
        elif verb == b'QUIT': say('221 bye'); break
        else: say('250 ok')
    c.close()
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(('127.0.0.1', 1025)); s.listen(16)
while True:
    c, _ = s.accept(); threading.Thread(target=handle, args=(c,), daemon=True).start()
PY

cat > "${WORK}/bin/smtp.py" <<'PY'
# smtp.py HOST PORT COMMAND... — one SMTP conversation: EHLO, then each
# command; "DATA:<text>" sends a message. Prints one reply line per command.
import socket, sys
host, port, commands = sys.argv[1], int(sys.argv[2]), sys.argv[3:]
s = socket.create_connection((host, port), timeout=20); f = s.makefile('rb')
def reply():
    lines = []
    while True:
        l = f.readline().decode().rstrip('\r\n'); lines.append(l)
        if len(l) < 4 or l[3] != '-': return lines
print('CONNECT ' + reply()[-1])
s.sendall(b'EHLO probe.invalid\r\n'); print('EHLO ' + ' '.join(l[4:] for l in reply()))
for c in commands:
    if c.startswith('DATA:'):
        s.sendall(b'DATA\r\n'); r = reply()[-1]
        if r.startswith('354'):
            s.sendall(c[5:].replace('\\n', '\r\n').encode() + b'\r\n.\r\n'); r = reply()[-1]
        print('DATA ' + r)
    else:
        s.sendall((c + '\r\n').encode()); print(c.split(' ')[0].split(':')[0] + ' ' + reply()[-1])
s.sendall(b'QUIT\r\n'); s.close()
PY

python3 "${WORK}/bin/milter.py" & MILTER_PID=$!
python3 "${WORK}/bin/sink.py" "${WORK}/captured" & SINK_PID=$!

# A certificate for the MX host, from a CA of this run.
openssl req -x509 -newkey rsa:2048 -nodes -keyout "${WORK}/tls/ca.key" -out "${WORK}/tls/ca.pem" -days 2 -subj "/CN=scenario CA" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -keyout "${WORK}/tls/privkey.pem" -out "${WORK}/tls/mx.csr" -subj "/CN=mx1.tits.guru" 2>/dev/null
printf 'subjectAltName=DNS:mx1.tits.guru\n' > "${WORK}/tls/ext.cnf"
openssl x509 -req -in "${WORK}/tls/mx.csr" -CA "${WORK}/tls/ca.pem" -CAkey "${WORK}/tls/ca.key" -CAcreateserial \
    -out "${WORK}/tls/fullchain.pem" -days 2 -extfile "${WORK}/tls/ext.cnf" 2>/dev/null
chmod 0600 "${WORK}/tls/privkey.pem"

# --- The configuration the gateway renders ------------------------------------------

"${SCRIPTS}/mail-routing" render-plan --file "${CONFIG}/mail-routing.json" --registry "${CONFIG}/deployment-targets.json" > "${WORK}/plan.json"
"${SCRIPTS}/mail-identity" render-signing-plan --identity "${CONFIG}/mail-identity.json" --routing "${CONFIG}/mail-routing.json" \
    --registry "${CONFIG}/deployment-targets.json" --outbound "${CONFIG}/mail-outbound.json" > "${WORK}/signing.json"
"${SCRIPTS}/mail-inbound" render-receiver > "${WORK}/receiver.json"

# inbound_input STATE — what the gateway reads when the host records STATE,
# with this run's paths for the table, the store and the certificate.
inbound_input() {
    jq -n --slurpfile r "${WORK}/receiver.json" --arg state "$1" --arg address "${PUBLIC}" \
        --argjson uid "$(id -u nobody)" --argjson gid "$(id -g nobody)" \
        --arg table "${CFG}/rateguru-inbound-recipients.regexp" --arg store "${STORE}" \
        --arg cert "${WORK}/tls/fullchain.pem" --arg key "${WORK}/tls/privkey.pem" \
        --argjson reserve "${2:-null}" '
        $r[0] as $r
        | {state: $state, address: (if $state == "enabled" then $address else null end),
           loopback: "127.0.0.1:\($r.loopback_port)", hostname: ($r.mx_hostnames | sort | .[0]),
           host_postmaster_domain: $r.host_postmaster_domain, domains: $r.domains,
           recipients: [$r.recipients[] | {pattern, mailbox}],
           limits: ($r.limits + (if $reserve == null then {} else {host_reserve_bytes: $reserve} end)),
           table: $table, store: $store, uid: $uid, gid: $gid, tls_cert: $cert, tls_key: $key,
           cleanup: "rateguru-inbound-cleanup", rewrite: "rateguru-inbound-rewrite"}'
}

# render STATE DIR [RESERVE] — the gateway's own renderer, into DIR; the only
# lines added say where this run's queue, data and log live.
render() {
    local state="$1" dir="$2" input=""
    mkdir -p "${dir}"
    if [[ "${state}" != absent ]]; then
        input="${WORK}/inbound-${state}.json"
        inbound_input "${state}" "${3:-null}" > "${input}"
    fi
    (
        # shellcheck disable=SC1091
        source "${SCRIPTS}/install-mail-gateway"
        render_gateway_config "${WORK}/plan.json" "${CONFIG}/mail-outbound.json" "${WORK}/signing.json" inet:127.0.0.1:8891 "${dir}" "${input}"
    )
    cat >> "${dir}/main.cf" <<EOF

# --- this run only: where its queue, data and log live
queue_directory = ${WORK}/queue
data_directory = ${WORK}/data
maillog_file_prefixes = ${WORK}
maillog_file = ${WORK}/log/maillog
EOF
}

render enabled "${CFG}"
render absent "${WORK}/cfg-absent"
render disabled "${WORK}/cfg-disabled"

# The signed listener's From policy is read from /etc/postfix, where the
# gateway installs it: into a copy of the package's /etc/postfix, bind-mounted
# over it in this mount namespace only.
cp -a /etc/postfix "${WORK}/etc-postfix"
install -m 0644 "${CFG}/rateguru-from-tits-guru.regexp" "${WORK}/etc-postfix/rateguru-from-tits-guru.regexp"
mount --bind "${WORK}/etc-postfix" /etc/postfix

# --- Postfix's own judgement, and the gateway's read-back ----------------------------

if warnings="$(postconf -c "${CFG}" -n 2>&1 >/dev/null)" && [[ -z "${warnings}" ]]; then pass "postconf reads main.cf without a warning"; else fail "postconf: ${warnings}"; fi
if warnings="$(postconf -c "${CFG}" -M 2>&1 >/dev/null)" && [[ -z "${warnings}" ]]; then pass "postconf reads master.cf without a warning"; else fail "postconf -M: ${warnings}"; fi
if warnings="$(postfix -c "${CFG}" check 2>&1)" && [[ -z "${warnings}" ]]; then pass "postfix check accepts the configuration"; else fail "postfix check: ${warnings}"; fi

readback() {
    # The gateway's own globals, as its candidate run would set them.
    # shellcheck disable=SC2034
    (
        # shellcheck disable=SC1091
        source "${SCRIPTS}/install-mail-gateway"
        PLAN_FILE="${WORK}/plan.json"; SIGNING_FILE="${WORK}/signing.json"; MILTER_ENDPOINT=inet:127.0.0.1:8891
        OUTBOUND_FILE="${CONFIG}/mail-outbound.json"; INBOUND_INPUT="$1"; EFFECTIVE_UID=1
        postfix_contract_problems "$2"
    )
}
problems="$(readback "${WORK}/inbound-enabled.json" "${CFG}")"
if [[ -z "${problems}" ]]; then pass "the gateway's read-back — the outbound contract and all twenty inbound requirements — passes against the real postconf"; else fail "the gateway's read-back: ${problems//$'\n'/; }"; fi

outbound_view() {
    {
        postconf -c "$1" -n | grep -vE '^(virtual_|config_directory|queue_directory|data_directory|maillog_file)'
        echo '--- services'
        postconf -c "$1" -M | grep -vE '^(127\.0\.0\.1:2580|[0-9.]+:25|rateguru-inbound-[a-z]+|virtual)[[:space:]]'
        echo '--- overrides'
        postconf -c "$1" -P | grep -vE '^(127\.0\.0\.1:2580|[0-9.]+:25|rateguru-inbound-[a-z]+)/'
    } | sha256sum | cut -d ' ' -f 1
}
expect "the outbound configuration, as postconf reads it, is the same with inbound mail enabled as without it" "$(outbound_view "${CFG}")" "$(outbound_view "${WORK}/cfg-absent")"
expect "and the same with inbound mail disabled" "$(outbound_view "${WORK}/cfg-disabled")" "$(outbound_view "${WORK}/cfg-absent")"

# --- Running it -------------------------------------------------------------------------

postfix -c "${CFG}" start >/dev/null 2>&1 || { fail "Postfix did not start"; exit 1; }
sleep 2
MASTER="$(tr -d ' ' < "${WORK}/queue/pid/master.pid")"
listening="$(ss -ltn | awk 'NR > 1 { print $4 }' | sort | tr '\n' ' ')"
expect "Postfix listens on the submission listeners, the inbound loopback listener and ${PUBLIC}:25 alone" \
    "$(grep -oE '(127\.0\.0\.1:(2525|2526|2580)|[0-9.]+:(25|465|587)) ' <<<"${listening} " | sort | tr -d '\n')" \
    "127.0.0.1:2525 127.0.0.1:2526 127.0.0.1:2580 ${PUBLIC}:25 "

smtp() { python3 "${WORK}/bin/smtp.py" "$@" 2>&1; }
code_of() { awk -v c="$1" '$1 == c { print $2; exit }'; }
log_has() {
    local waited=0
    while (( waited < 10 )); do
        grep -qE "$1" "${WORK}/log/maillog" 2>/dev/null && return 0
        sleep 1
        waited=$((waited + 1))
    done
    return 1
}

# Outbound, unchanged.
out="$(smtp 127.0.0.1 2526 'MAIL FROM:<noreply@tits.guru>' 'RCPT TO:<support@tits.guru>' 'RCPT TO:<someone-unknown@tits.guru>' \
    'DATA:From: TitsGuru <noreply@tits.guru>\nTo: support@tits.guru\nSubject: from the application\n\nan application message')"
expect "the production listener takes its own sender" "$(code_of MAIL <<<"${out}")" 250
expect "and a message to a domain this host receives for — never judged against the inbound table" "$(code_of DATA <<<"${out}")" 250
if log_has 'rateguru-outbound-tits-guru/smtp\[[0-9]+\]: [0-9A-F]+: to=<support@tits.guru>'; then
    pass "that message leaves through the target's own direct route, to the domain's MX — not into the store"
else
    fail "the application's message to support@tits.guru did not go to rateguru-outbound-tits-guru"
fi
if [[ -z "$(find "${STORE}/targets" -type f 2>/dev/null)" ]]; then pass "nothing the application sent reached the inbound store"; else fail "the application's message reached the inbound store"; fi

out="$(smtp 127.0.0.1 2525 'MAIL FROM:<noreply@staging.invalid>' 'RCPT TO:<support@tits.guru>' 'DATA:From: s@staging.invalid\nSubject: staging\n\nstaging message')"
expect "the staging listener takes its own sender's message" "$(code_of DATA <<<"${out}")" 250
if log_has 'rateguru-capture-staging-main/smtp\[[0-9]+\]: .* relay=127\.0\.0\.1\[127\.0\.0\.1\]:1025, .*status=sent'; then pass "staging mail reaches the capture destination"; else fail "staging mail did not reach the capture destination"; fi

# Inbound: every address as mail-inbound route judges it, on both listeners.
corpus=(support@tits.guru SUPPORT@Tits.Guru postmaster@tits.guru postmaster@bounce.tx.tits.guru postmaster@reply.tits.guru
    Postmaster noreply@tits.guru unknown@tits.guru b-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru
    b-i1hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru r-01hzx3k9q2w8e7r6t5y4v3p2m1@reply.tits.guru
    b-01hzx3k9q2w8e7r6t5y4v3p2m1@reply.tits.guru someone@gmail.com postmaster@mta1.tits.guru root@rateguru-mail-inbound.invalid)
for listener in "127.0.0.1 2580" "${PUBLIC} 25"; do
    mismatches=()
    for address in "${corpus[@]}"; do
        if "${SCRIPTS}/mail-inbound" route --recipient "${address}" >/dev/null 2>&1; then want=2; else want=5; fi
        # shellcheck disable=SC2086
        got="$(smtp ${listener} 'MAIL FROM:<>' "RCPT TO:<${address}>" | code_of RCPT)"
        [[ "${got:0:1}" == "${want}" ]] || mismatches+=("${address}: ${got}")
    done
    if (( ${#mismatches[@]} == 0 )); then pass "${listener/ /:} answers all ${#corpus[@]} addresses exactly as mail-inbound route judges them"; else fail "${listener/ /:} disagrees with mail-inbound route: ${mismatches[*]}"; fi
done

out="$(smtp 127.0.0.1 2580 'MAIL FROM:<>' 'RCPT TO:<support@tits.guru>' 'RCPT TO:<postmaster@tits.guru>')"
expect "a second recipient in one message is refused, temporarily" "$(awk '$1 == "RCPT" { c = $2 } END { print c }' <<<"${out}")" 452
ehlo="$(smtp 127.0.0.1 2580 | awk '$1 == "EHLO"')"
if [[ "${ehlo}" != *AUTH* ]]; then pass "no SMTP AUTH is offered"; else fail "SMTP AUTH is offered: ${ehlo}"; fi
if [[ "${ehlo}" != *ETRN* ]]; then pass "no ETRN is offered"; else fail "ETRN is offered: ${ehlo}"; fi
if [[ "${ehlo}" == *"SIZE 10485760"* ]]; then pass "the reviewed message size is announced"; else fail "SIZE is not the reviewed one: ${ehlo}"; fi
public_ehlo="$(smtp "${PUBLIC}" 25 | awk '$1 == "EHLO"')"
if [[ "${public_ehlo}" == *STARTTLS* ]]; then pass "the public listener offers STARTTLS"; else fail "the public listener does not offer STARTTLS: ${public_ehlo}"; fi
if [[ "$(echo QUIT | openssl s_client -starttls smtp -connect "${PUBLIC}:25" -CAfile "${WORK}/tls/ca.pem" -verify_hostname mx1.tits.guru 2>/dev/null | grep -c 'Verify return code: 0 (ok)')" == 1 ]]; then pass "its certificate verifies for mx1.tits.guru"; else fail "the public listener's certificate does not verify for mx1.tits.guru"; fi
if [[ "$(smtp 127.0.0.1 2580 | awk '$1 == "CONNECT"')" == *mx1.tits.guru* ]]; then pass "the inbound listeners greet as mx1.tits.guru"; else fail "the inbound listeners greet as another name"; fi
if [[ "$(smtp 127.0.0.1 2526 | awk '$1 == "CONNECT"')" == *mta1.tits.guru* ]]; then pass "the submission listeners still greet as mta1.tits.guru"; else fail "the submission listeners' name changed"; fi

# Storage.
smtp "${PUBLIC}" 25 'MAIL FROM:<someone@sender.example>' 'RCPT TO:<support@tits.guru>' 'DATA:From: someone@sender.example\nSubject: to support\n\nfirst' >/dev/null
smtp 127.0.0.1 2580 'MAIL FROM:<>' 'RCPT TO:<Postmaster>' 'DATA:Subject: to the bare postmaster\n\nsecond' >/dev/null
smtp 127.0.0.1 2580 'MAIL FROM:<>' 'RCPT TO:<b-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru>' 'DATA:Subject: a delivery status notification\n\nthird' >/dev/null
sleep 3
for box in targets/tits-guru/support host/postmaster targets/tits-guru/bounce; do
    count="$(find "${STORE}/${box}/new" -type f 2>/dev/null | wc -l | tr -d ' ')"
    expect "virtual(8) stored one message in ${box}/" "${count}" 1
done
expect "every stored message belongs to the store's account, mode 0600" "$(find "${STORE}" -type f -printf '%u %m\n' | sort -u)" "nobody 600"

# The store cannot be written: the message waits in the queue, then is stored.
chown root:root "${STORE}"; chmod 0555 "${STORE}"; mv "${STORE}/targets" "${WORK}/targets-away"
smtp 127.0.0.1 2580 'MAIL FROM:<a@sender.example>' 'RCPT TO:<support@tits.guru>' 'DATA:Subject: while the store is away\n\nfourth' >/dev/null
if log_has 'virtual\[[0-9]+\]: [0-9A-F]+: to=<support@tits.guru>.*status=deferred \(maildir delivery failed'; then pass "while the store cannot be written, the accepted message is deferred and waits in the queue"; else fail "the message was not deferred while the store was away"; fi
mv "${WORK}/targets-away" "${STORE}/targets"; chown nobody "${STORE}"; chmod 0700 "${STORE}"
postqueue -c "${CFG}" -f; sleep 3
expect "once it can be written, the waiting message is stored" "$(find "${STORE}/targets/tits-guru/support/new" -type f | wc -l | tr -d ' ')" 2

# No backscatter: a bounce to a forged sender has no route off the host.
chown root:root "${STORE}"; chmod 0555 "${STORE}"; mv "${STORE}/targets" "${WORK}/targets-away"
smtp 127.0.0.1 2580 'MAIL FROM:<victim@forged.example>' 'RCPT TO:<r-01hzx3k9q2w8e7r6t5y4v3p2m1@reply.tits.guru>' 'DATA:Subject: undeliverable\n\nfifth' >/dev/null
sleep 2
grep -v '^/\^r-' "${CFG}/rateguru-inbound-recipients.regexp" > "${WORK}/table" && cat "${WORK}/table" > "${CFG}/rateguru-inbound-recipients.regexp"
mv "${WORK}/targets-away" "${STORE}/targets"; chown nobody "${STORE}"; chmod 0700 "${STORE}"
postfix -c "${CFG}" reload >/dev/null 2>&1; postqueue -c "${CFG}" -f
if log_has 'error\[[0-9]+\]: [0-9A-F]+: to=<victim@forged.example>.*status=bounced \(RateGuru mail gateway: no delivery route'; then pass "the bounce of an undeliverable inbound message to its forged sender has no route off the host"; else fail "a bounce to a forged sender was not stopped at the error transport"; fi
render enabled "${CFG}"

# The shared queue: the inbound listeners refuse new mail below their own floor.
render enabled "${CFG}" 999999999999999
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2
expect "below their queue floor the inbound listeners refuse new mail, temporarily" "$(smtp 127.0.0.1 2580 'MAIL FROM:<a@sender.example>' | code_of MAIL)" 452
expect "while the submission listeners still accept" "$(smtp 127.0.0.1 2526 'MAIL FROM:<noreply@tits.guru>' | code_of MAIL)" 250
render enabled "${CFG}"
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2

# The per-client connection limit.
over="$(python3 - "${PUBLIC}" <<'PY'
import socket, sys
held = [socket.create_connection((sys.argv[1], 25), timeout=10) for _ in range(6)]
print(held[-1].recv(200).decode().split()[0])
for s in held: s.close()
PY
)"
expect "a sixth concurrent session from one client is refused" "${over}" 421

# Back to disabled, by a reload: TCP 25 closed, Postfix not restarted.
cp "${WORK}/cfg-disabled/main.cf" "${CFG}/main.cf"; cp "${WORK}/cfg-disabled/master.cf" "${CFG}/master.cf"
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2
expect "the disable reload leaves the same master process running" "$(tr -d ' ' < "${WORK}/queue/pid/master.pid")" "${MASTER}"
if [[ -z "$(ss -ltn | awk 'NR > 1 && $4 ~ /:25$/')" ]]; then pass "nothing listens on TCP 25 any more"; else fail "something still listens on TCP 25"; fi
expect "the production listener still takes its own sender" "$(smtp 127.0.0.1 2526 'MAIL FROM:<noreply@tits.guru>' | code_of MAIL)" 250
expect "the inbound loopback listener still takes mail for the store" "$(smtp 127.0.0.1 2580 'MAIL FROM:<>' 'RCPT TO:<support@tits.guru>' | code_of RCPT)" 250

if (( FAILURES > 0 )); then
    printf 'SCENARIO: %d FAILURE(S)\n' "${FAILURES}"
    exit 1
fi
printf 'SCENARIO: PASS\n'
