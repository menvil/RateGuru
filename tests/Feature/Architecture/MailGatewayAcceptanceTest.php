<?php

use Illuminate\Support\Facades\File;

/**
 * verify-mail-gateway --e2e — the mutating operator acceptance of the
 * host-global mail gateway — run against a simulated host.
 *
 * The acceptance talks SMTP over /dev/tcp, so the gateway it submits to is a
 * real socket: a small PHP server on ephemeral loopback ports, one per listener
 * of the plan, that answers the way the reviewed Postfix does. It refuses a
 * sender outside its listener's domain at MAIL FROM, names a queue ID for an
 * accepted message, and files that message where its listener's mode would put
 * it: a capture message delivered into Mailpit and its Mailtrap Local mirror
 * (or deferred while Mailpit is down), a held message in HOLD, and an outbound
 * message "on the Internet" — which no run may ever produce. Mailpit, Mailtrap
 * Local, the Postfix queue and systemd are stubs reading and writing the same
 * state, so every check sees what the one before it left behind.
 *
 * The script is sourced and run through its own main(), so the shipped checks
 * run rather than a copy of them. Sourcing is also what lets the plan come from
 * a policy whose submission ports are the fake gateway's: the committed ports
 * cannot be bound by tests that run side by side. Root comes from
 * RATEGURU_MAILGW_EUID, honoured only behind RATEGURU_ALLOW_TEST_OVERRIDES.
 */
function mailGatewayAcceptanceScript(): string
{
    return base_path('infrastructure/scripts/verify-mail-gateway');
}

/**
 * The fake gateway. Arguments: the state directory, then one MODE:DOMAIN per
 * listener. It prints the ports it bound, in that order, on one line, and
 * serves until its stdin closes.
 */
function mailGatewayAcceptanceServer(): string
{
    return <<<'PHP'
        <?php
        $state = $argv[1];
        $listeners = [];

        foreach (array_slice($argv, 2) as $spec) {
            [$mode, $domain] = explode(':', $spec, 2);
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($server === false) {
                fwrite(STDERR, "could not listen: {$error}\n");
                exit(1);
            }
            $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
            $listeners[$port] = ['server' => $server, 'mode' => $mode, 'domain' => $domain];
        }

        echo implode(' ', array_keys($listeners)), "\n";
        fflush(STDOUT);

        $toggle = static fn (string $name): bool => is_file("{$state}/toggles/{$name}");
        $log = static fn (int $port, string $line) => file_put_contents("{$state}/smtp.log", "{$port} {$line}\n", FILE_APPEND);
        $enqueue = static fn (string $id, string $queue, string $token) => file_put_contents("{$state}/queue", "{$id}\t{$queue}\t{$token}\n", FILE_APPEND);
        $copy = static function (string $token, bool $mirror) use ($state): void {
            touch("{$state}/mailpit/{$token}");
            if ($mirror) {
                touch("{$state}/mailtrap/{$token}");
            }
        };

        // Where Postfix leaves a message its listener accepted.
        $file = static function (string $mode, string $id, string $token) use ($state, $toggle, $enqueue, $copy): void {
            if ($mode === 'capture') {
                if (! is_file("{$state}/mailpit-active")) {
                    $toggle('outage-loses-mail') || $enqueue($id, 'deferred', $token);
                } elseif ($toggle('capture-undeliverable')) {
                    $enqueue($id, 'deferred', $token);
                } else {
                    $copy($token, ! $toggle('no-mirror'));
                    $toggle('stuck-in-queue') && $enqueue($id, 'active', $token);
                }
            } elseif ($mode === 'held') {
                if ($toggle('held-routed')) {
                    $copy($token, true);
                } else {
                    $enqueue($id, 'hold', $token);
                    $toggle('held-copied') && $copy($token, true);
                }
            } else {
                touch("{$state}/internet/{$token}");
            }
        };

        while (true) {
            $read = [STDIN, ...array_column($listeners, 'server')];
            $write = $except = null;
            if (stream_select($read, $write, $except, 30) < 1) {
                exit(0);
            }

            foreach ($read as $ready) {
                if ($ready === STDIN) {
                    if (fgets(STDIN) === false) {
                        exit(0);
                    }
                    continue;
                }

                foreach ($listeners as $port => $listener) {
                    if ($listener['server'] !== $ready) {
                        continue;
                    }

                    $conn = @stream_socket_accept($ready, 5);
                    if ($conn === false) {
                        continue;
                    }
                    stream_set_timeout($conn, 10);

                    // The acceptance writes a message in several small writes;
                    // each after the first waits for an ACK that Linux delays
                    // 40 ms. Acknowledge at once instead, where PHP can.
                    $socket = function_exists('socket_import_stream') && defined('TCP_QUICKACK') ? socket_import_stream($conn) : false;
                    $quickAck = static fn (): bool => $socket === false || socket_set_option($socket, SOL_TCP, TCP_QUICKACK, 1);
                    $say = static fn (string $reply) => @fwrite($conn, $reply."\r\n");
                    $say('220 fake-gateway ESMTP');

                    while (($line = fgets($conn)) !== false) {
                        $line = rtrim($line, "\r\n");
                        $log($port, $line);

                        switch (strtoupper((string) strtok($line, ' :'))) {
                            case 'EHLO':
                                // A multi-line reply, in one write: two small
                                // writes wait out a delayed ACK each time.
                                $say("250-fake-gateway\r\n250 8BITMIME");
                                break;
                            case 'MAIL':
                                $from = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
                                $own = substr((string) strrchr($from, '@'), 1) === $listener['domain'];
                                if ($toggle('refuse-every-sender') || ($toggle('held-refuses-every-sender') && $listener['mode'] === 'held')) {
                                    $say("553 5.7.1 <{$from}>: Sender address rejected: not owned by user");
                                } elseif ($toggle('capture-refuses-during-outage') && $listener['mode'] === 'capture' && ! is_file("{$state}/mailpit-active")) {
                                    $say('451 4.3.0 Mail system temporarily unavailable');
                                } elseif ($own || $toggle('accept-every-sender')) {
                                    $say('250 2.1.0 Ok');
                                } elseif ($toggle('defer-foreign-sender')) {
                                    $say('451 4.3.5 Server configuration problem');
                                } else {
                                    $say("553 5.7.1 <{$from}>: Sender address rejected: not owned by user");
                                }
                                break;
                            case 'RCPT':
                                $say('250 2.1.5 Ok');
                                break;
                            case 'DATA':
                                $say('354 End data with <CR><LF>.<CR><LF>');
                                $message = '';
                                while ($quickAck() && ($data = fgets($conn)) !== false && rtrim($data, "\r\n") !== '.') {
                                    $message .= $data;
                                }
                                $id = strtoupper(bin2hex(random_bytes(5)));
                                file_put_contents("{$state}/messages/{$id}", $message);
                                $log($port, "<message {$id}>");
                                $file($listener['mode'], $id, preg_match('/^Subject: (\S+)/m', $message, $m) ? $m[1] : 'untokened');
                                $say($toggle('no-queue-id') ? '250 2.0.0 Ok' : "250 2.0.0 Ok: queued as {$id}");
                                break;
                            case 'RSET':
                                $say('250 2.0.0 Ok');
                                break;
                            case 'QUIT':
                                $say('221 2.0.0 Bye');
                                break 2;
                            default:
                                $say('502 5.5.2 Error: command not recognized');
                        }
                    }

                    fclose($conn);
                }
            }
        }
        PHP;
}

/**
 * Stubs for the host tools the acceptance runs, all reading and writing the
 * simulated host's state. Every call that changes something is logged.
 *
 * @return array<string, string>
 */
function mailGatewayAcceptanceStubs(): array
{
    return [
        // The Mailpit (8025) and Mailtrap Local (3550) APIs. Mailpit's API is
        // down while its unit is stopped.
        'curl' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            method=GET data='' url=''
            while (( $# )); do
                case "$1" in
                    -X) method="$2"; shift 2 ;;
                    -d) data="$2"; shift 2 ;;
                    -H|--noproxy|--max-time) shift 2 ;;
                    -*) shift ;;
                    *) url="$1"; shift ;;
                esac
            done
            case "${url}" in
                http://127.0.0.1:8025/*) service=mailpit key=ID ids=IDs; [[ -e "${S}/mailpit-active" ]] || exit 7 ;;
                http://127.0.0.1:3550/*) service=mailtrap key=id ids=ids; [[ ! -e "${S}/toggles/mailtrap-down" ]] || exit 7 ;;
                *) echo "curl stub: unexpected URL ${url}" >&2; exit 3 ;;
            esac
            path="/${url#http://127.0.0.1:*/}"
            case "${method} ${path}" in
                'GET /api/v1/info'|'GET /api/v1/version') echo '{}' ;;
                'GET /api/v1/search?query='*)
                    token="${path#/api/v1/search?query=}"
                    if [[ -e "${S}/${service}/${token}" ]]; then
                        printf '{"messages":[{"%s":"%s-%s"}]}\n' "${key}" "${service}" "${token}"
                    else
                        echo '{"messages":[]}'
                    fi ;;
                'DELETE /api/v1/messages')
                    printf 'DELETE %s %s\n' "${service}" "${data}" >> "${STUB_LOG}"
                    for id in $(jq -r --arg ids "${ids}" '.[$ids][]' <<<"${data}"); do
                        rm -f "${S}/${service}/${id#"${service}"-}"
                    done ;;
                *) exit 22 ;;
            esac
            STUB,
        // The queue as `postqueue -j` reports it; `-i` retries exactly that
        // message, which is delivered once Mailpit is back.
        'postqueue' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            case "$1" in
                -j)
                    [[ ! -e "${S}/toggles/queue-unreadable" ]] || { echo "postqueue: fatal: simulated" >&2; exit 1; }
                    # Reads after the retry: the first is the run's own check that
                    # the retried entry left the queue, the rest are its cleanup's.
                    cleanup_read=false
                    if [[ -e "${S}/retried" ]]; then
                        reads=$(( $(cat "${S}/reads-after-retry" 2>/dev/null || echo 0) + 1 ))
                        echo "${reads}" > "${S}/reads-after-retry"
                        (( reads >= 2 )) && cleanup_read=true
                    fi
                    if [[ "${cleanup_read}" == true && -e "${S}/toggles/queue-unreadable-in-cleanup" ]]; then
                        echo "postqueue: fatal: simulated" >&2; exit 1
                    fi
                    while IFS=$'\t' read -r id queue token; do
                        [[ -n "${id}" ]] || continue
                        printf '{"queue_name": "%s", "queue_id": "%s", "arrival_time": 1767225600, "message_size": 512, "sender": "", "recipients": []}\n' "${queue}" "${id}"
                    done < "${S}/queue"
                    # The retried entry back in the queue by the time the cleanup looks.
                    if [[ "${cleanup_read}" == true && -e "${S}/toggles/retried-entry-back-in-cleanup" ]]; then
                        printf '{"queue_name": "deferred", "queue_id": "%s", "arrival_time": 1767225600, "message_size": 512, "sender": "", "recipients": []}\n' "$(cat "${S}/retried")"
                    fi ;;
                -i)
                    printf 'postqueue -i %s\n' "$2" >> "${STUB_LOG}"
                    printf '%s' "$2" > "${S}/retried"
                    [[ -e "${S}/mailpit-active" && ! -e "${S}/toggles/retry-lost" ]] || exit 0
                    token="$(awk -F'\t' -v id="$2" '$1 == id { print $3 }' "${S}/queue")"
                    [[ -n "${token}" ]] || exit 0
                    touch "${S}/mailpit/${token}" "${S}/mailtrap/${token}"
                    awk -F'\t' -v id="$2" '$1 != id' "${S}/queue" > "${S}/queue.next" && mv "${S}/queue.next" "${S}/queue" ;;
                *) printf 'postqueue %s\n' "$*" >> "${STUB_LOG}"; exit 1 ;;
            esac
            STUB,
        'postsuper' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            printf 'postsuper %s\n' "$*" >> "${STUB_LOG}"
            [[ ! -e "${S}/toggles/postsuper-fails" ]] || { echo "postsuper: fatal: simulated" >&2; exit 1; }
            # Only the cleanup's form, `postsuper -d ID`, without a queue name.
            [[ ! ( -e "${S}/toggles/postsuper-fails-without-queue-name" && -z "${3:-}" ) ]] || { echo "postsuper: fatal: simulated" >&2; exit 1; }
            [[ "$1" == -d && -n "${2:-}" ]] || exit 1
            awk -F'\t' -v id="$2" -v queue="${3:-}" '!($1 == id && (queue == "" || $2 == queue))' "${S}/queue" > "${S}/queue.next" \
                && mv "${S}/queue.next" "${S}/queue"
            STUB,
        // Only staging-mailpit.service exists here.
        'systemctl' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            unit="${!#}"
            case "$1" in
                is-active)
                    [[ "${unit}" == staging-mailpit.service && -e "${S}/mailpit-active" && ! -e "${S}/toggles/mailpit-reports-inactive" ]] ;;
                stop)
                    printf 'systemctl %s\n' "$*" >> "${STUB_LOG}"
                    rm -f "${S}/mailpit-active"
                    # The operator interrupts the run while Mailpit is down.
                    [[ ! -e "${S}/toggles/interrupt-after-stop" ]] || kill -TERM "${PPID}" ;;
                start)
                    printf 'systemctl %s\n' "$*" >> "${STUB_LOG}"
                    [[ ! -e "${S}/toggles/mailpit-start-fails" ]] || exit 1
                    touch "${S}/mailpit-active" ;;
                *) printf 'systemctl %s\n' "$*" >> "${STUB_LOG}"; exit 1 ;;
            esac
            STUB,
        // Every wait is a poll against state the stubs already settled, so a
        // second of real sleep would prove nothing and cost the suite.
        'sleep' => <<<'STUB'
            #!/bin/sh
            exit 0
            STUB,
    ];
}

/**
 * A simulated host: the fake gateway listening for staging-main (capture) and
 * tits-guru as it was held before its activation (mailPreActivationPolicy())
 * plus the synthetic demo-shop (outbound), a running Mailpit, and someone
 * else's message in the queue and in Mailpit that the acceptance must leave
 * alone.
 *
 * @param  list<string>  $toggles
 * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $policy  edits the policy after the ports are in
 * @return array{scratch: string, state: string, env: array<string, string>, ports: array<string, int>, server: resource, pipes: array<int, resource>}
 */
function mailGatewayAcceptanceHost(array $toggles = [], ?callable $policy = null): array
{
    $scratch = makeScratchDir('mail-gateway-acceptance', ['', '/bin', '/tmp', '/state', '/state/toggles', '/state/mailpit', '/state/mailtrap', '/state/messages', '/state/internet']);
    $state = $scratch.'/state';

    foreach (mailGatewayAcceptanceStubs() as $name => $body) {
        file_put_contents($scratch.'/bin/'.$name, $body."\n");
        chmod($scratch.'/bin/'.$name, 0o755);
    }

    // The installer's own --verify is proved on a simulated Postfix in the
    // MailGateway*Test files; here it only has to give its verdict.
    file_put_contents($scratch.'/install-mail-gateway', <<<'STUB'
        #!/bin/bash
        printf 'install-mail-gateway %s\n' "$*" >> "${STUB_LOG}"
        if [[ -e "${STUB_STATE}/toggles/gateway-drifted" ]]; then
            echo 'DRIFT    /etc/postfix/main.cf — differs from the current render'
            echo 'SUMMARY  pass=7 missing=0 drift=1 conflict=0 deferred=0'
            exit 1
        fi
        echo 'SUMMARY  pass=8 missing=0 drift=0 conflict=0 deferred=0'
        STUB."\n");
    chmod($scratch.'/install-mail-gateway', 0o755);

    touch($state.'/mailpit-active');
    touch($state.'/mailpit/someone-elses-message');
    file_put_contents($state.'/queue', "UNRELATED01\tdeferred\tsomeone-elses-message\n");
    touch($scratch.'/calls.log');
    touch($state.'/smtp.log');

    foreach ($toggles as $toggle) {
        touch($state.'/toggles/'.$toggle);
    }

    file_put_contents($scratch.'/fake-gateway.php', mailGatewayAcceptanceServer());
    $server = proc_open(
        [PHP_BINARY, $scratch.'/fake-gateway.php', $state, 'capture:staging.invalid', 'held:tits.guru', 'outbound:demo-shop.example'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $scratch.'/fake-gateway.err', 'a']],
        $pipes,
    );
    expect($server)->not->toBeFalse('could not start the fake gateway');
    stream_set_timeout($pipes[1], 10);
    $ports = array_map('intval', explode(' ', trim((string) fgets($pipes[1]))));
    expect($ports)->toHaveCount(3, 'the fake gateway did not report its ports: '.@file_get_contents($scratch.'/fake-gateway.err'));
    $ports = array_combine(['staging-main', 'tits-guru', 'demo-shop'], $ports);

    $routing = mailPreActivationPolicy()['routing'];
    $routing['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    foreach ($ports as $target => $port) {
        $routing['targets'][$target]['submission']['port'] = $port;
    }
    file_put_contents($scratch.'/mail-routing.json', mailRoutingJson($policy !== null ? $policy($routing) : $routing));
    file_put_contents($scratch.'/deployment-targets.json', mailRoutingJson(mailRoutingDemoShopRegistry()));

    return [
        'scratch' => $scratch,
        'state' => $state,
        'ports' => $ports,
        'server' => $server,
        'pipes' => $pipes,
        'env' => [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $scratch,
            'TMPDIR' => $scratch.'/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_MAILGW_EUID' => '0',
            'STUB_STATE' => $state,
            'STUB_LOG' => $scratch.'/calls.log',
        ],
    ];
}

function mailGatewayAcceptanceCleanup(array $host): void
{
    foreach ($host['pipes'] as $pipe) {
        fclose($pipe);
    }

    proc_terminate($host['server']);
    proc_close($host['server']);
    removeScratchDir($host['scratch']);
}

/**
 * Source the shipped script, point it at the simulated host, and run $body.
 * Only what the script has no override for is set here: the installer and
 * the policy it renders its plan from. Thirty polls become three — every
 * stub answers at once, so a poll that has not succeeded by then never will.
 *
 * @param  array<string, string>  $env
 * @return array{status: int, output: string}
 */
function mailGatewayAcceptanceRun(array $host, string $body = 'main --e2e', array $env = []): array
{
    $harness = implode("\n", [
        'source '.escapeshellarg(mailGatewayAcceptanceScript()),
        'INSTALLER='.escapeshellarg($host['scratch'].'/install-mail-gateway'),
        'POLICY_FILE='.escapeshellarg($host['scratch'].'/mail-routing.json'),
        'REGISTRY_FILE='.escapeshellarg($host['scratch'].'/deployment-targets.json'),
        'POLL_TIMEOUT=3',
        $body,
    ]);

    $process = proc_open(['bash', '-c', $harness], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $host['scratch'], [...$host['env'], ...$env]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['status' => proc_close($process), 'output' => $output];
}

/** @return list<string> */
function mailGatewayAcceptanceLines(string $path): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($path)), static fn (string $line): bool => $line !== ''));
}

/**
 * What the run left on the simulated host.
 *
 * @return array{calls: list<string>, queue: list<string>, mailpit: list<string>, mailtrap: list<string>, internet: list<string>, mailpit_active: bool, transcripts: array<int, list<string>>, queued: list<string>, plan_files: list<string>}
 */
function mailGatewayAcceptanceState(array $host): array
{
    $names = static function (string $dir): array {
        $files = array_map('basename', glob($dir.'/*') ?: []);
        sort($files);

        return $files;
    };

    $transcripts = [];
    $queued = [];
    foreach (mailGatewayAcceptanceLines($host['state'].'/smtp.log') as $line) {
        [$port, $text] = explode(' ', $line, 2);
        $transcripts[(int) $port][] = $text;
        if (preg_match('/^<message ([0-9A-F]+)>$/', $text, $matches)) {
            $queued[] = $matches[1];
        }
    }

    return [
        'calls' => mailGatewayAcceptanceLines($host['scratch'].'/calls.log'),
        'queue' => mailGatewayAcceptanceLines($host['state'].'/queue'),
        'mailpit' => $names($host['state'].'/mailpit'),
        'mailtrap' => $names($host['state'].'/mailtrap'),
        'internet' => $names($host['state'].'/internet'),
        'mailpit_active' => is_file($host['state'].'/mailpit-active'),
        'transcripts' => $transcripts,
        'queued' => $queued,
        'plan_files' => $names($host['scratch'].'/tmp'),
    ];
}

/** The token a filed message carries. */
function mailGatewayAcceptanceToken(array $host, string $queueId): string
{
    preg_match('/^Subject: (\S+)\r$/m', (string) file_get_contents($host['state'].'/messages/'.$queueId), $matches);

    return $matches[1] ?? '';
}

// =============================================================================
// THE WHOLE ACCEPTANCE
// =============================================================================

it('accepts a healthy gateway end to end, then removes exactly what it created', function () {
    $host = mailGatewayAcceptanceHost();

    try {
        $run = mailGatewayAcceptanceRun($host);
        $state = mailGatewayAcceptanceState($host);
        ['staging-main' => $capture, 'tits-guru' => $held, 'demo-shop' => $outbound] = $host['ports'];

        expect($run['status'])->toBe(0, $run['output']);
        expect($run['output'])
            ->toContain('verifying the mail gateway (end-to-end acceptance; MUTATING)')
            ->toContain('end-to-end acceptance passed; removing the synthetic messages')
            ->not->toContain('FAIL');

        // Three whole messages, in order: A into the capture listener, C into
        // the held one, D into the capture listener during the outage.
        expect($state['queued'])->toHaveCount(3);
        [$delivered, $heldId, $retried] = $state['queued'];
        $tokens = array_map(static fn (string $id): string => mailGatewayAcceptanceToken($host, $id), $state['queued']);
        foreach ($tokens as $token) {
            expect($token)->toMatch('/^mgverify\d{14}\d+$/');
        }
        expect(array_unique($tokens))->toHaveCount(3);

        // Exactly what smtp_submit composes: the listener's own domain at both
        // ends, and the run's token in the subject and in its own header.
        expect(File::get($host['state'].'/messages/'.$delivered))->toBe(implode("\r\n", [
            'From: <mail-gateway-verify@staging.invalid>',
            'To: <mail-gateway-verify@staging.invalid>',
            "Subject: {$tokens[0]}",
            "X-RateGuru-Mail-Gateway-Verify: {$tokens[0]}",
            '',
            "RateGuru mail gateway acceptance message {$tokens[0]}",
            '',
        ]));

        $probe = static fn (string $domain): array => ['EHLO mail-gateway-verify', "MAIL FROM:<intruder@{$domain}>", 'RSET', 'QUIT'];
        $submit = static fn (string $from, string $to, string $id): array => ['EHLO mail-gateway-verify', "MAIL FROM:<{$from}>", "RCPT TO:<{$to}>", 'DATA', "<message {$id}>", 'QUIT'];

        // Every listener is probed with a foreign domain and every other
        // listener's, in plan order (demo-shop, staging-main, tits-guru).
        expect($state['transcripts'][$capture])->toBe([
            ...$submit('mail-gateway-verify@staging.invalid', 'mail-gateway-verify@staging.invalid', $delivered),
            ...$probe('foreign-staging.invalid'),
            ...$probe('demo-shop.example'),
            ...$probe('tits.guru'),
            ...$submit('mail-gateway-verify@staging.invalid', 'mail-gateway-verify@staging.invalid', $retried),
        ]);
        expect($state['transcripts'][$held])->toBe([
            ...$probe('foreign-tits.guru'),
            ...$probe('demo-shop.example'),
            ...$probe('staging.invalid'),
            ...$submit('noreply@tits.guru', 'mail-gateway-verify@tits.guru', $heldId),
        ]);

        // The outbound listener is only ever probed: no recipient, no message,
        // nothing that could leave the host.
        expect($state['transcripts'][$outbound])->toBe([
            ...$probe('foreign-demo-shop.example'),
            ...$probe('staging.invalid'),
            ...$probe('tits.guru'),
        ]);
        expect($state['internet'])->toBe([]);

        expect($run['output'])
            ->toContain("PASS A staging-main: Postfix queued the message on 127.0.0.1:{$capture} ({$delivered})")
            ->toContain('PASS A staging-main: the canonical copy is in Mailpit')
            ->toContain('PASS A staging-main: the mirrored copy is in Mailtrap Local')
            ->toContain("PASS A staging-main: {$delivered} left the queue once delivered")
            ->toContain("PASS B staging-main: a sender from foreign-staging.invalid is refused on 127.0.0.1:{$capture} (553)")
            ->toContain("PASS B tits-guru: a sender from staging.invalid is refused on 127.0.0.1:{$held} (553)")
            ->toContain("PASS B demo-shop: a sender from tits.guru is refused on 127.0.0.1:{$outbound} (553)")
            ->toContain("PASS C tits-guru: accepted from noreply@tits.guru on 127.0.0.1:{$held} ({$heldId})")
            ->toContain("PASS C tits-guru: {$heldId} is in HOLD")
            ->toContain('PASS C tits-guru: it stays in HOLD and is not in Mailpit — there is no route')
            ->toContain("PASS C tits-guru: removed the synthetic held entry {$heldId}, and only it")
            ->toContain("PASS E demo-shop: outbound (direct) on 127.0.0.1:{$outbound} — no message submitted")
            ->toContain("PASS D staging-main: accepted while Mailpit is down ({$retried})")
            ->toContain("PASS D staging-main: {$retried} is deferred, not lost")
            ->toContain("PASS D staging-main: Postfix retried and {$retried} reached Mailpit")
            ->toContain('PASS D staging-main: the Mailtrap Local mirror follows')
            ->toContain("PASS D staging-main: {$retried} left the queue once delivered");

        // Everything it changed, in order: the held entry by its exact ID,
        // Mailpit stopped and started once, exactly the deferred message
        // retried, and its own delivered copies deleted by token.
        expect($state['calls'])->toBe([
            'install-mail-gateway --verify',
            "postsuper -d {$heldId} hold",
            'systemctl stop staging-mailpit.service',
            'systemctl start staging-mailpit.service',
            "postqueue -i {$retried}",
            "DELETE mailpit {\"IDs\":[\"mailpit-{$tokens[0]}\"]}",
            "DELETE mailtrap {\"ids\":[\"mailtrap-{$tokens[0]}\"]}",
            "DELETE mailpit {\"IDs\":[\"mailpit-{$tokens[2]}\"]}",
            "DELETE mailtrap {\"ids\":[\"mailtrap-{$tokens[2]}\"]}",
        ]);

        // Someone else's queue entry and message are where they were, Mailpit
        // runs, and the rendered plan is gone.
        expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
        expect($state['mailpit'])->toBe(['someone-elses-message']);
        expect($state['mailtrap'])->toBe([]);
        expect($state['mailpit_active'])->toBeTrue();
        expect($state['plan_files'])->toBe([]);
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('fails each check with its own reason, and still removes what it created', function (array $toggles, string $reason, ?Closure $then) {
    $host = mailGatewayAcceptanceHost($toggles);

    try {
        $run = mailGatewayAcceptanceRun($host);
        $state = mailGatewayAcceptanceState($host);

        // The reason is printed on stderr, most of them after an SMTP session
        // has opened and closed its socket — and the operator still sees it.
        expect($run['status'])->toBe(1, $run['output']);
        expect($run['output'])->toMatch($reason)->not->toContain('end-to-end acceptance passed');

        // Whatever failed, nothing reached the Internet, no queue entry or
        // message of someone else's was touched, and the plan is gone.
        expect($state['internet'])->toBe([]);
        expect($state['queue'])->toContain("UNRELATED01\tdeferred\tsomeone-elses-message");
        expect($state['mailpit'])->toContain('someone-elses-message');
        expect($state['plan_files'])->toBe([]);

        if ($then !== null) {
            $then($host, $state, $run['output']);
        } else {
            // The usual end: everything it created is gone and Mailpit runs.
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
            expect($state['mailpit'])->toBe(['someone-elses-message']);
            expect($state['mailtrap'])->toBe([]);
            expect($state['mailpit_active'])->toBeTrue();
        }
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
})->with([
    'A: the gateway refuses its own domain' => [
        ['refuse-every-sender'],
        '/FAIL A staging-main: the gateway did not queue a message from its own domain \(mail: 553 5\.7\.1 <mail-gateway-verify@staging\.invalid>: Sender address rejected/',
        null,
    ],
    'A: the gateway names no queue ID' => [
        ['no-queue-id'],
        '/FAIL Postfix accepted the message but named no queue ID \(250 2\.0\.0 Ok\)/',
        null,
    ],
    'A: the message never reaches Mailpit' => [
        ['capture-undeliverable'],
        '/FAIL A staging-main: mgverify\d+ did not reach Mailpit\n/',
        // The deferred entry is removed by its exact ID.
        function (array $host, array $state): void {
            expect($state['calls'])->toContain("postsuper -d {$state['queued'][0]}");
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
        },
    ],
    'A: the mirror never follows' => [
        ['no-mirror'],
        '/FAIL A staging-main: mgverify\d+ did not reach the Mailtrap Local mirror/',
        // The canonical copy that did arrive is deleted by its token.
        function (array $host, array $state): void {
            $token = mailGatewayAcceptanceToken($host, $state['queued'][0]);
            expect($state['calls'])->toContain("DELETE mailpit {\"IDs\":[\"mailpit-{$token}\"]}");
            expect($state['mailpit'])->toBe(['someone-elses-message']);
        },
    ],
    'A: the queue entry never goes' => [
        ['stuck-in-queue'],
        '/FAIL A staging-main: [0-9A-F]{10} is still in the Postfix queue after delivery/',
        function (array $host, array $state): void {
            expect($state['calls'])->toContain("postsuper -d {$state['queued'][0]}");
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
            expect($state['mailpit'])->toBe(['someone-elses-message']);
        },
    ],
    'A: the queue cannot be read' => [
        ['queue-unreadable'],
        // Not "still in the queue", and above all not "left the queue": an
        // entry is never taken to be gone because postqueue did not answer.
        '/FAIL could not read the Postfix queue \(postqueue -j failed\), so where [0-9A-F]{10} is cannot be told/',
        // Its own entry cannot be found either, so the cleanup does not pass
        // over it in silence: it says how to remove it.
        function (array $host, array $state, string $output): void {
            $id = $state['queued'][0];
            expect($output)
                ->not->toContain('left the queue')
                ->toContain("FAIL could not read the Postfix queue to remove entry {$id} — if it is still there, remove it with: postsuper -d {$id}");
            expect($state['calls'])->not->toContain("postsuper -d {$id}");
            expect($state['mailpit_active'])->toBeTrue();
        },
    ],
    'B: a foreign sender is accepted' => [
        ['accept-every-sender'],
        '/FAIL B demo-shop: a sender from foreign-demo-shop\.example was accepted at MAIL FROM on 127\.0\.0\.1:\d+ \(the probe named no recipient and sent nothing\)/',
        // Even accepted, the intruder never got past MAIL FROM — on the one
        // listener that would have sent its message to the Internet.
        function (array $host, array $state): void {
            expect($state['transcripts'][$host['ports']['demo-shop']])
                ->toBe(['EHLO mail-gateway-verify', 'MAIL FROM:<intruder@foreign-demo-shop.example>', 'RSET', 'QUIT']);
            expect($state['mailpit'])->toBe(['someone-elses-message']);
        },
    ],
    'B: a foreign sender is only deferred' => [
        ['defer-foreign-sender'],
        '/FAIL B demo-shop: a sender from foreign-demo-shop\.example was not refused at MAIL FROM \(mail: 451 4\.3\.5 Server configuration problem\)/',
        null,
    ],
    'C: the held listener refuses its own sender' => [
        ['held-refuses-every-sender'],
        '/FAIL C tits-guru: the held listener did not accept a message from noreply@tits\.guru \(mail: 553 5\.7\.1 <noreply@tits\.guru>: Sender address rejected/',
        null,
    ],
    'C: held mail is routed, not held' => [
        ['held-routed'],
        '/FAIL C tits-guru: [0-9A-F]{10} is not in the HOLD queue \(it is in: \)/',
        null,
    ],
    'C: held mail also reaches Mailpit' => [
        ['held-copied'],
        '/FAIL C tits-guru: held mail reached Mailpit/',
        function (array $host, array $state): void {
            expect($state['calls'])->toContain("postsuper -d {$state['queued'][1]}");
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
            expect($state['mailpit'])->toBe(['someone-elses-message']);
        },
    ],
    'C: the held entry cannot be removed' => [
        ['postsuper-fails'],
        '/FAIL C tits-guru: could not remove the synthetic held entry ([0-9A-F]{10})\n/',
        // The cleanup tries once more and tells the operator exactly how.
        function (array $host, array $state, string $output): void {
            $id = $state['queued'][1];
            expect($output)->toContain("FAIL could not remove queue entry {$id} — remove it with: postsuper -d {$id}");
            expect($state['calls'])->toContain("postsuper -d {$id} hold")->toContain("postsuper -d {$id}");
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message", "{$id}\thold\t".mailGatewayAcceptanceToken($host, $id)]);
        },
    ],
    'D: Mailpit is not running before the outage' => [
        ['mailpit-reports-inactive'],
        '/FAIL D staging-mailpit\.service is not active before the outage test/',
        // Nothing was stopped, so there is nothing to restore.
        function (array $host, array $state, string $output): void {
            expect($state['calls'])->not->toContain('systemctl stop staging-mailpit.service');
            expect($output)->not->toContain('restoring staging-mailpit.service');
            expect($state['mailpit_active'])->toBeTrue();
            expect($state['mailpit'])->toBe(['someone-elses-message']);
        },
    ],
    'D: the gateway refuses mail while Mailpit is down' => [
        ['capture-refuses-during-outage'],
        '/FAIL D staging-main: the gateway did not accept mail while Mailpit was down \(mail: 451 4\.3\.0 Mail system temporarily unavailable\)/',
        null,
    ],
    'D: the outage loses the message' => [
        ['outage-loses-mail'],
        '/FAIL D staging-main: [0-9A-F]{10} was not deferred while Mailpit was down \(it is in: \)/',
        // Mailpit was down when it failed, and is started again on the way out.
        function (array $host, array $state, string $output): void {
            expect($output)->toContain('restoring staging-mailpit.service');
            $stopped = (int) array_search('systemctl stop staging-mailpit.service', $state['calls'], true);
            expect(array_slice($state['calls'], $stopped, 2))->toBe(['systemctl stop staging-mailpit.service', 'systemctl start staging-mailpit.service']);
            expect($state['mailpit_active'])->toBeTrue();
        },
    ],
    'D: Mailpit does not start again' => [
        ['mailpit-start-fails'],
        '/FAIL could not restart staging-mailpit\.service — start it by hand/',
        // Its one chance to say so is the cleanup; the deferred entry is still
        // removed by its ID.
        function (array $host, array $state): void {
            $id = $state['queued'][2];
            expect(array_values(array_filter($state['calls'], static fn (string $call): bool => str_starts_with($call, 'systemctl'))))
                ->toBe(['systemctl stop staging-mailpit.service', 'systemctl start staging-mailpit.service', 'systemctl start staging-mailpit.service']);
            expect($state['calls'])->toContain("postsuper -d {$id}");
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
            expect($state['mailpit_active'])->toBeFalse();
        },
    ],
    'D: the retry never delivers' => [
        ['retry-lost'],
        '/FAIL D staging-main: [0-9A-F]{10} did not reach Mailpit after it came back/',
        function (array $host, array $state): void {
            $id = $state['queued'][2];
            expect($state['calls'])->toContain("postqueue -i {$id}")->toContain("postsuper -d {$id}");
            expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
        },
    ],
]);

it('only probes the committed tits-guru, now outbound: no message is ever submitted to it', function () {
    // The committed policy routes tits-guru outbound by direct delivery; the
    // acceptance submits whole messages to capture and held listeners only.
    $host = mailGatewayAcceptanceHost([], static function (array $routing): array {
        $committed = mailCommittedPolicy()['routing']['targets']['tits-guru'];
        $committed['submission'] = $routing['targets']['tits-guru']['submission'];
        $routing['targets']['tits-guru'] = $committed;

        return $routing;
    });

    try {
        $run = mailGatewayAcceptanceRun($host);
        $state = mailGatewayAcceptanceState($host);
        $titsGuru = $host['ports']['tits-guru'];
        $probe = static fn (string $domain): array => ['EHLO mail-gateway-verify', "MAIL FROM:<intruder@{$domain}>", 'RSET', 'QUIT'];

        expect($run['status'])->toBe(0, $run['output']);

        // A and D into the capture listener, and nothing else.
        expect($state['queued'])->toHaveCount(2);
        expect($state['transcripts'][$titsGuru])->toBe([
            ...$probe('foreign-tits.guru'),
            ...$probe('demo-shop.example'),
            ...$probe('staging.invalid'),
        ]);
        expect($state['internet'])->toBe([]);

        expect($run['output'])
            ->toContain("PASS E tits-guru: outbound (direct) on 127.0.0.1:{$titsGuru} — no message submitted")
            ->not->toContain('C tits-guru');
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('fails a passing run whose cleanup cannot prove its own entries gone', function (array $toggles, Closure $expectCleanupFailure) {
    // Every check passes; only the EXIT cleanup runs into trouble. A passing
    // acceptance must leave nothing of its own behind or say so, so the run
    // then exits 1, never 0 with a FAIL in its output.
    $host = mailGatewayAcceptanceHost($toggles);

    try {
        $run = mailGatewayAcceptanceRun($host);
        $state = mailGatewayAcceptanceState($host);

        expect($state['queued'])->toHaveCount(3);
        expect($run['output'])
            ->toContain('end-to-end acceptance passed; removing the synthetic messages')
            ->toContain('FAIL the acceptance checks passed, but this run could not remove what it created — see above; the run fails');
        expect($run['status'])->toBe(1, $run['output']);

        $expectCleanupFailure($state, $run['output']);

        // Never anyone else's: no foreign entry touched, and nothing flushed,
        // released or requeued. Every queue command it ran names one of its
        // own entries: the held removal, the one retry, the cleanup's removal.
        expect($state['queue'])->toContain("UNRELATED01\tdeferred\tsomeone-elses-message");

        foreach (array_filter($state['calls'], static fn (string $call): bool => preg_match('/^post(super|queue) /', $call) === 1) as $call) {
            expect(preg_match('/^(?:postsuper -d (\S+)(?: hold)?|postqueue -i (\S+))$/', $call, $named))->toBe(1, "a queue command it should not run: {$call}");
            expect($state['queued'])->toContain(($named[1] ?? '') !== '' ? $named[1] : $named[2]);
        }

        expect($state['mailpit'])->toContain('someone-elses-message');
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
})->with([
    'the queue cannot be read during cleanup' => [
        ['queue-unreadable-in-cleanup'],
        // Each of its own entries gets the exact command, by its own ID.
        function (array $state, string $output): void {
            foreach ($state['queued'] as $id) {
                expect($output)->toContain("FAIL could not read the Postfix queue to remove entry {$id} — if it is still there, remove it with: postsuper -d {$id}");
            }
        },
    ],
    'its own entry is still queued and cannot be removed' => [
        ['retried-entry-back-in-cleanup', 'postsuper-fails-without-queue-name'],
        function (array $state, string $output): void {
            $id = $state['queued'][2];
            expect($output)->toContain("FAIL could not remove queue entry {$id} — remove it with: postsuper -d {$id}");
            expect($state['calls'])->toContain("postsuper -d {$id}");
        },
    ],
]);

it('stops before any submission when the gateway or the capture backend is not ready', function (array $toggles, bool $mailpitRunning, string $reason, array $calls) {
    $host = mailGatewayAcceptanceHost($toggles);

    try {
        if (! $mailpitRunning) {
            unlink($host['state'].'/mailpit-active');
        }

        $run = mailGatewayAcceptanceRun($host);
        $state = mailGatewayAcceptanceState($host);

        expect($run['status'])->toBe(1, $run['output']);
        expect($run['output'])->toContain($reason);
        expect($state['calls'])->toBe($calls);
        expect($state['transcripts'])->toBe([], 'it submitted or probed before it was ready');
        expect($state['plan_files'])->toBe([]);
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
})->with([
    'the installed gateway has drifted' => [['gateway-drifted'], true, "DRIFT    /etc/postfix/main.cf — differs from the current render\nSUMMARY  pass=7 missing=0 drift=1 conflict=0 deferred=0", ['install-mail-gateway --verify']],
    'Mailpit is down' => [[], false, 'FAIL the Mailpit API is not available', ['install-mail-gateway --verify']],
    'Mailtrap Local is down' => [['mailtrap-down'], true, 'FAIL the Mailtrap Local API is not available', ['install-mail-gateway --verify']],
]);

it('puts Mailpit back and removes its own messages when it is interrupted during the outage', function () {
    $host = mailGatewayAcceptanceHost(['interrupt-after-stop']);

    try {
        $run = mailGatewayAcceptanceRun($host);
        $state = mailGatewayAcceptanceState($host);

        expect($run['status'])->not->toBe(0, $run['output']);
        expect($run['output'])
            ->toContain('restoring staging-mailpit.service')
            ->not->toContain('end-to-end acceptance passed')
            ->not->toContain('accepted while Mailpit is down');

        // Interrupted right after the stop: started again, its earlier
        // messages deleted by token, someone else's left alone.
        expect(array_slice($state['calls'], -4))->toBe([
            'systemctl stop staging-mailpit.service',
            'systemctl start staging-mailpit.service',
            'DELETE mailpit {"IDs":["mailpit-'.mailGatewayAcceptanceToken($host, $state['queued'][0]).'"]}',
            'DELETE mailtrap {"ids":["mailtrap-'.mailGatewayAcceptanceToken($host, $state['queued'][0]).'"]}',
        ]);
        expect($state['mailpit_active'])->toBeTrue();
        expect($state['mailpit'])->toBe(['someone-elses-message']);
        expect($state['queue'])->toBe(["UNRELATED01\tdeferred\tsomeone-elses-message"]);
        expect($state['plan_files'])->toBe([]);
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

// =============================================================================
// WHAT IT REFUSES BEFORE IT STARTS
// =============================================================================

it('refuses to run the mutating acceptance unprivileged, before it touches anything', function () {
    $host = mailGatewayAcceptanceHost();

    try {
        $run = mailGatewayAcceptanceRun($host, 'main --e2e', ['RATEGURU_MAILGW_EUID' => '1000']);

        expect($run['status'])->toBe(1);
        expect($run['output'])->toContain('FAIL --e2e must run as root (it stops and starts Mailpit and removes its own queue entries)');
        expect(mailGatewayAcceptanceState($host)['calls'])->toBe([]);
        expect(mailGatewayAcceptanceState($host)['transcripts'])->toBe([]);

        // The root override is a test seam only behind the shared gate: without
        // it, an unprivileged caller is refused whatever it claims.
        if (trim((string) shell_exec('id -u')) !== '0') {
            $run = mailGatewayAcceptanceRun($host, 'main --e2e', ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'false']);

            expect($run['status'])->toBe(1);
            expect($run['output'])->toContain('FAIL --e2e must run as root');
            expect(mailGatewayAcceptanceState($host)['calls'])->toBe([]);
        }
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('refuses to start without every tool it needs', function () {
    $host = mailGatewayAcceptanceHost();

    try {
        // Everything but postsuper — the last tool it checks for.
        $bin = $host['scratch'].'/bin-without-postsuper';
        mkdir($bin);
        foreach (['curl', 'systemctl', 'postqueue'] as $stub) {
            symlink($host['scratch'].'/bin/'.$stub, $bin.'/'.$stub);
        }
        foreach (['jq', 'dirname'] as $tool) {
            symlink(trim((string) shell_exec('command -v '.$tool)), $bin.'/'.$tool);
        }

        $run = mailGatewayAcceptanceRun($host, 'main --e2e', ['PATH' => $bin]);

        expect($run['status'])->toBe(1);
        expect($run['output'])->toContain('FAIL required tool not found: postsuper');
        expect(mailGatewayAcceptanceState($host)['calls'])->toBe([]);
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('takes its plan only from mail-routing, and submits nothing when the policy is refused', function () {
    // Two targets on one port: mail-routing's own rule, in its own words.
    $host = mailGatewayAcceptanceHost([], static function (array $policy): array {
        $policy['targets']['tits-guru']['submission']['port'] = $policy['targets']['staging-main']['submission']['port'];

        return $policy;
    });

    try {
        $run = mailGatewayAcceptanceRun($host);

        expect($run['status'])->toBe(1);
        expect($run['output'])
            ->toContain('is claimed by more than one target: staging-main, tits-guru')
            ->toContain('FAIL mail-routing render-plan refused the reviewed policy');
        expect(mailGatewayAcceptanceState($host)['transcripts'])->toBe([]);
        expect(mailGatewayAcceptanceState($host)['plan_files'])->toBe([]);

        // And without the CLI there is no plan at all.
        $run = mailGatewayAcceptanceRun($host, 'MAIL_ROUTING_CLI=/nonexistent/mail-routing; main --e2e');

        expect($run['status'])->toBe(1);
        expect($run['output'])->toContain('FAIL the mail routing CLI is not executable: /nonexistent/mail-routing — run from a full repository bundle');
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('observes only the staging Mailpit, and submits nothing to a capture listener routed anywhere else', function () {
    $host = mailGatewayAcceptanceHost([], static function (array $policy): array {
        $policy['targets']['staging-main']['capture']['port'] = 1026;

        return $policy;
    });

    try {
        $run = mailGatewayAcceptanceRun($host);

        expect($run['status'])->toBe(1);
        expect($run['output'])->toContain('FAIL staging-main captures into 127.0.0.1:1026; this acceptance observes only the staging Mailpit at 127.0.0.1:1025');
        expect(mailGatewayAcceptanceState($host)['transcripts'])->toBe([]);
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('has nothing to retry, and stops nothing, when the plan has no capture listener', function () {
    $host = mailGatewayAcceptanceHost();

    try {
        $plan = $host['scratch'].'/held-only.json';
        file_put_contents($plan, mailRoutingJson(['schema_version' => 2, 'listeners' => [[
            'identity' => 'tits-guru',
            'environment_class' => 'production',
            'lifecycle' => 'planned',
            'listen' => ['host' => '127.0.0.1', 'port' => $host['ports']['tits-guru']],
            'delivery_mode' => 'held',
            'sender' => ['allowed_domain' => 'tits.guru', 'default_from' => 'noreply@tits.guru'],
            'route' => null,
        ]]]));

        $run = mailGatewayAcceptanceRun($host, 'PLAN_FILE='.escapeshellarg($plan).'; check_capture_delivery; check_capture_retry');

        expect($run['status'])->toBe(0, $run['output']);
        expect(trim($run['output']))->toBe('PASS D no capture listener in the plan — nothing to retry');
        expect(mailGatewayAcceptanceState($host)['calls'])->toBe([]);
        expect(mailGatewayAcceptanceState($host)['transcripts'])->toBe([]);
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

// =============================================================================
// --read-only AND THE COMMAND LINE
// =============================================================================

it('runs --read-only as the installer\'s --verify alone, with its verdict and its exit status', function () {
    $host = mailGatewayAcceptanceHost();

    try {
        $run = mailGatewayAcceptanceRun($host, 'main --read-only');

        expect($run['status'])->toBe(0, $run['output']);
        expect(trim($run['output']))->toBe('SUMMARY  pass=8 missing=0 drift=0 conflict=0 deferred=0');

        touch($host['state'].'/toggles/gateway-drifted');
        $run = mailGatewayAcceptanceRun($host, 'main --read-only');

        expect($run['status'])->toBe(1);
        expect($run['output'])->toContain('DRIFT    /etc/postfix/main.cf — differs from the current render');

        // Nothing but the installer, and no SMTP: no service, queue or message.
        expect(mailGatewayAcceptanceState($host)['calls'])->toBe(['install-mail-gateway --verify', 'install-mail-gateway --verify']);
        expect(mailGatewayAcceptanceState($host)['transcripts'])->toBe([]);

        $run = mailGatewayAcceptanceRun($host, 'INSTALLER=/nonexistent/install-mail-gateway; main --read-only');

        expect($run['status'])->toBe(1);
        expect($run['output'])->toContain('FAIL install-mail-gateway is not executable next to this script: /nonexistent/install-mail-gateway');
    } finally {
        mailGatewayAcceptanceCleanup($host);
    }
});

it('takes exactly one mode, and nothing it does not know', function (array $arguments, string $reason) {
    $process = proc_open(['bash', mailGatewayAcceptanceScript(), ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toStartWith("Usage:\n  verify-mail-gateway --read-only\n")->toContain("FAIL {$reason}");
})->with([
    'two modes' => [['--read-only', '--e2e'], 'mode given more than once'],
    'the same mode twice' => [['--e2e', '--e2e'], 'mode given more than once'],
    'an unknown argument' => [['--read-only', '--force'], 'unknown argument: --force'],
]);
