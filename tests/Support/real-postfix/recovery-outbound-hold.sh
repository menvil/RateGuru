#!/usr/bin/env bash
#
# The recovery outbound hold, on a REAL Postfix.
#
#   recovery-outbound-hold.sh REPOSITORY WORK
#
# Run as root, in a network and mount namespace of its own (`unshare --net
# --mount`), exactly like scenario.sh: only loopback exists, so no message can
# leave and no DNS answers; the one file Postfix reads from /etc/postfix is
# added to a copy bind-mounted there, so the host's own /etc/postfix is never
# written. WORK is an empty directory.
#
# The configurations are the ones install-mail-gateway renders, from the
# repository's reviewed documents — tits-guru outbound by direct delivery —
# with the gateway's own recovery-hold step (apply_recovery_outbound_holds,
# sourced) reading a hold placed by the hold library's own place step. Postfix
# then runs them, and every claim is checked against what Postfix does:
#
#   * held: no direct-delivery transport exists at all; the production
#     listener still takes its own sender, refuses a foreign one, and puts the
#     message it accepts on HOLD; the staging listener still captures;
#   * nothing held is ever handed to a delivery agent — not by a reload, not
#     by a full stop and start of Postfix, not by a flush;
#   * a message that was queued for the direct route before the hold stood is
#     never delivered while it stands: its transport no longer exists;
#   * every other service and override is exactly what it is when the mail is
#     released: the hold changes tits-guru's listener and its direct route,
#     and nothing else;
#   * released: the direct route exists again and new mail goes to it (and
#     goes nowhere: this namespace has no route off the host), and what was
#     held stays held — a release never releases the HOLD queue.
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

[[ "${EUID}" == 0 ]] || { echo "recovery-outbound-hold.sh runs as root, in a network namespace of its own" >&2; exit 2; }
[[ "${WORK}" =~ ^/[a-z0-9/._-]+$ && -d "${WORK}" ]] || { echo "WORK must be an existing directory with a plain lowercase path: ${WORK}" >&2; exit 2; }
for tool in postfix postconf postqueue python3 jq; do
    command -v "${tool}" >/dev/null || { echo "recovery-outbound-hold.sh needs ${tool}" >&2; exit 2; }
done

for namespace in net mnt; do
    if [[ "$(readlink "/proc/self/ns/${namespace}")" == "$(readlink "/proc/1/ns/${namespace}")" ]]; then
        echo "recovery-outbound-hold.sh runs in a network and mount namespace of its own — under unshare --net --mount" >&2
        exit 2
    fi
done
[[ -d /etc/postfix ]] || { echo "recovery-outbound-hold.sh needs the Postfix package's /etc/postfix" >&2; exit 2; }

ip link set lo up
if ip -4 route get 1.1.1.1 >/dev/null 2>&1; then
    echo "this namespace has a route off the host — run recovery-outbound-hold.sh under unshare --net --mount" >&2
    exit 2
fi

CFG="${WORK}/cfg"
ROOT="${WORK}/root"
mkdir -p "${CFG}" "${WORK}/queue" "${WORK}/log" "${WORK}/bin" "${WORK}/held" "${WORK}/released" "${ROOT}"
install -d -o postfix -g postfix -m 0700 "${WORK}/data"
chmod 0755 "${WORK}"

cleanup() {
    postfix -c "${CFG}" stop >/dev/null 2>&1 || true
    [[ -n "${MILTER_PID:-}" ]] && kill "${MILTER_PID}" 2>/dev/null || true
    [[ -n "${SINK_PID:-}" ]] && kill "${SINK_PID}" 2>/dev/null || true
}
trap cleanup EXIT

# --- Stand-ins for what is not Postfix ------------------------------------------
#
# The DKIM signer (a milter that accepts everything), the capture destination
# (an SMTP sink that keeps what it receives), and an SMTP client.

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

# --- The two configurations the gateway renders --------------------------------------

"${SCRIPTS}/mail-routing" render-plan --file "${CONFIG}/mail-routing.json" --registry "${CONFIG}/deployment-targets.json" > "${WORK}/plan.json"
"${SCRIPTS}/mail-identity" render-signing-plan --identity "${CONFIG}/mail-identity.json" --routing "${CONFIG}/mail-routing.json" \
    --registry "${CONFIG}/deployment-targets.json" --outbound "${CONFIG}/mail-outbound.json" > "${WORK}/signing.json"

expect "the reviewed policy delivers tits-guru's mail outbound by direct delivery" \
    "$(jq -r '.listeners[] | select(.identity == "tits-guru") | "\(.delivery_mode) \(.route.kind)"' "${WORK}/plan.json")" "outbound direct"

# The hold, placed by the library's own step, where a recovery preparation
# places it — under ROOT, the gateway's simulated filesystem root.
(
    RECOVERY_OUTBOUND_HOLD_OWNER=root
    # shellcheck disable=SC1091
    source "${SCRIPTS}/recovery-outbound-hold"
    recovery_outbound_hold_place "${ROOT}${RECOVERY_OUTBOUND_HOLD_DIR}" tits-guru 20261020-030000 \
        || { echo "could not place the hold: ${ROH_PROBLEM}" >&2; exit 1; }
)

# render STATE DIR — the gateway's own renderer, into DIR: the reviewed plan as
# it is (released), or as the gateway's own recovery-hold step makes it while
# the hold stands (held). The only lines added say where this run's queue,
# data and log live.
render() {
    local state="$1" dir="$2"
    (
        export RATEGURU_ALLOW_TEST_OVERRIDES=true RATEGURU_MAILGW_FS_ROOT="${ROOT}" RATEGURU_MAILGW_FILE_OWNER=root
        # shellcheck disable=SC1091
        source "${SCRIPTS}/install-mail-gateway"
        WORK_DIR="${dir}/work"
        mkdir -p "${WORK_DIR}"
        PLAN_FILE="${WORK_DIR}/plan.json"
        OUTBOUND_FILE="${CONFIG}/mail-outbound.json"
        cp "${WORK}/plan.json" "${PLAN_FILE}"
        [[ "${state}" == released ]] || apply_recovery_outbound_holds
        jq -c . "${PLAN_FILE}" > "${dir}/plan.json"
        render_gateway_config "${PLAN_FILE}" "${OUTBOUND_FILE}" "${WORK}/signing.json" inet:127.0.0.1:8891 "${dir}"
    )
    cat >> "${dir}/main.cf" <<EOF

# --- this run only: where its queue, data and log live
queue_directory = ${WORK}/queue
data_directory = ${WORK}/data
maillog_file_prefixes = ${WORK}
maillog_file = ${WORK}/log/maillog
EOF
}

render held "${WORK}/held"
render released "${WORK}/released"

expect "while the hold stands, the gateway renders tits-guru's mail held, with no route" \
    "$(jq -r '.listeners[] | select(.identity == "tits-guru") | "\(.delivery_mode) \(.route)"' "${WORK}/held/plan.json")" "held null"

use() {
    cp "${WORK}/$1/main.cf" "${CFG}/main.cf"
    cp "${WORK}/$1/master.cf" "${CFG}/master.cf"
}

# The signed listener's From policy is read from /etc/postfix, where the
# gateway installs it: into a copy of the package's /etc/postfix, bind-mounted
# over it in this mount namespace only.
cp -a /etc/postfix "${WORK}/etc-postfix"
install -m 0644 "${WORK}/held/rateguru-from-tits-guru.regexp" "${WORK}/etc-postfix/rateguru-from-tits-guru.regexp"
mount --bind "${WORK}/etc-postfix" /etc/postfix

use held

if warnings="$(postconf -c "${CFG}" -n 2>&1 >/dev/null)" && [[ -z "${warnings}" ]]; then pass "postconf reads the held main.cf without a warning"; else fail "postconf: ${warnings}"; fi
if warnings="$(postfix -c "${CFG}" check 2>&1)" && [[ -z "${warnings}" ]]; then pass "postfix check accepts the held configuration"; else fail "postfix check: ${warnings}"; fi

transports() {
    postconf -c "$1" -M | awk '$1 ~ /^rateguru-outbound-/ { print $1 }' | sort | tr '\n' ' '
}
expect "held: no direct-delivery transport exists at all" "$(transports "${CFG}")" ""
expect "released: tits-guru's own direct route, and no other" "$(transports "${WORK}/released")" "rateguru-outbound-tits-guru "

# Everything but tits-guru's listener and its direct route, as postconf reads
# it, is the same held and released.
rest() {
    {
        # default_filter_nexthop is set only while some target delivers
        # directly: it is what lets a direct route find each recipient's MX.
        postconf -c "$1" -n | grep -vE '^(config_directory|queue_directory|data_directory|maillog_file|default_filter_nexthop)'
        echo '--- services'
        postconf -c "$1" -M | grep -vE '^(127\.0\.0\.1:2526|rateguru-outbound-tits-guru)[[:space:]]'
        echo '--- overrides'
        postconf -c "$1" -P | grep -vE '^(127\.0\.0\.1:2526|rateguru-outbound-tits-guru)/'
    } | sha256sum | cut -d ' ' -f 1
}
expect "the hold changes tits-guru's listener and its direct route, and nothing else — staging's capture and every other service are the same" \
    "$(rest "${CFG}")" "$(rest "${WORK}/released")"

# --- Running it, held -----------------------------------------------------------------

postfix -c "${CFG}" start >/dev/null 2>&1 || { fail "Postfix did not start"; exit 1; }
sleep 2

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
queue_of() {
    postqueue -c "${CFG}" -j 2>/dev/null | jq -r --arg id "$1" 'select(.queue_id == $id) | .queue_name'
}
queue_id_in() {
    awk '$1 == "DATA" { for (i = 1; i <= NF; i++) if ($i == "as") print $(i + 1) }' <<<"$1" | tr -d '.' | head -n 1
}

out="$(smtp 127.0.0.1 2526 'MAIL FROM:<noreply@tits.guru>' 'RCPT TO:<someone@example.net>' \
    'DATA:From: TitsGuru <noreply@tits.guru>\nTo: someone@example.net\nSubject: held by the recovery\n\nan application message')"
expect "held: the production listener still takes its own sender" "$(code_of MAIL <<<"${out}")" 250
expect "and accepts the application's message" "$(code_of DATA <<<"${out}")" 250
HELD_ID="$(queue_id_in "${out}")"
[[ -n "${HELD_ID}" ]] || fail "no queue ID in the reply: ${out//$'\n'/; }"
sleep 2
expect "which waits on HOLD" "$(queue_of "${HELD_ID}")" hold

expect "held: a foreign sender is still refused at MAIL FROM" "$(smtp 127.0.0.1 2526 'MAIL FROM:<someone@example.net>' | code_of MAIL | cut -c1)" 5

out="$(smtp 127.0.0.1 2525 'MAIL FROM:<noreply@staging.invalid>' 'RCPT TO:<support@tits.guru>' 'DATA:From: s@staging.invalid\nSubject: staging\n\nstaging message')"
expect "held: the staging listener takes its own sender's message" "$(code_of DATA <<<"${out}")" 250
if log_has 'rateguru-capture-staging-main/smtp\[[0-9]+\]: .* relay=127\.0\.0\.1\[127\.0\.0\.1\]:1025, .*status=sent'; then pass "and staging mail still reaches its capture destination"; else fail "staging mail did not reach the capture destination"; fi

# A reload, a full stop and start, and a flush: none of them hands the held
# message to a delivery agent.
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2
postfix -c "${CFG}" stop >/dev/null 2>&1; sleep 1
postfix -c "${CFG}" start >/dev/null 2>&1 || fail "Postfix did not start again"
sleep 2
postqueue -c "${CFG}" -f >/dev/null 2>&1 || true
sleep 3
expect "after a reload, a stop and start of Postfix and a flush, the message is still on HOLD" "$(queue_of "${HELD_ID}")" hold
if grep -qE "${HELD_ID}: to=" "${WORK}/log/maillog" 2>/dev/null; then fail "a delivery agent was handed the held message ${HELD_ID}"; else pass "no delivery agent was ever handed the held message"; fi

# --- A message queued for the direct route before the hold stood ---------------------

use released
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2
out="$(smtp 127.0.0.1 2526 'MAIL FROM:<noreply@tits.guru>' 'RCPT TO:<early@example.net>' \
    'DATA:From: TitsGuru <noreply@tits.guru>\nTo: early@example.net\nSubject: queued before the hold\n\nqueued for the direct route')"
EARLY_ID="$(queue_id_in "${out}")"
if log_has "rateguru-outbound-tits-guru/smtp\[[0-9]+\]: ${EARLY_ID}: to=<early@example.net>.*status=deferred"; then
    pass "released: a message goes to the direct route — and, with no route off this namespace, is deferred"
else
    fail "the released listener did not hand ${EARLY_ID} to its direct route"
fi

use held
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2
lines_before_hold="$(wc -l < "${WORK}/log/maillog")"
postqueue -c "${CFG}" -f >/dev/null 2>&1 || true
sleep 3
expect "held again: the message queued for the direct route stays in the queue" "$(queue_of "${EARLY_ID}")" deferred
if log_has "${EARLY_ID}: to=<early@example.net>.*(mail transport unavailable|status=deferred \(unknown mail transport)"; then
    pass "and is never handed to a delivery agent: its transport no longer exists"
else
    fail "${EARLY_ID} was not refused for its missing transport"
fi
if tail -n "+$((lines_before_hold + 1))" "${WORK}/log/maillog" | grep -qE "rateguru-outbound-tits-guru/smtp\[[0-9]+\]: ${EARLY_ID}"; then
    fail "an smtp client ran for ${EARLY_ID} while the hold stood"
else
    pass "no smtp client ran for it while the hold stood"
fi
postsuper -c "${CFG}" -d "${EARLY_ID}" >/dev/null 2>&1 || true

# --- Released --------------------------------------------------------------------------

use released
postfix -c "${CFG}" reload >/dev/null 2>&1; sleep 2
postqueue -c "${CFG}" -f >/dev/null 2>&1 || true
sleep 3
expect "released: what was held stays on HOLD — a release never releases the HOLD queue" "$(queue_of "${HELD_ID}")" hold

out="$(smtp 127.0.0.1 2526 'MAIL FROM:<noreply@tits.guru>' 'RCPT TO:<after@example.net>' \
    'DATA:From: TitsGuru <noreply@tits.guru>\nTo: after@example.net\nSubject: after the release\n\nafter the release')"
AFTER_ID="$(queue_id_in "${out}")"
if log_has "rateguru-outbound-tits-guru/smtp\[[0-9]+\]: ${AFTER_ID}: to=<after@example.net>"; then
    pass "released: new mail goes to tits-guru's own direct route"
else
    fail "after the release, ${AFTER_ID} did not reach the direct route"
fi

if grep -qE 'status=sent' <(grep -E 'rateguru-outbound-tits-guru' "${WORK}/log/maillog" 2>/dev/null); then
    fail "a direct-route delivery reported sent — nothing may leave this namespace"
else
    pass "nothing left this namespace: no direct-route delivery was ever sent"
fi

if (( FAILURES > 0 )); then
    printf 'SCENARIO: %d FAILURE(S)\n' "${FAILURES}"
    exit 1
fi
printf 'SCENARIO: PASS\n'
