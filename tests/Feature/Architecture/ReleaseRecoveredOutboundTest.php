<?php

use Illuminate\Support\Facades\File;

/**
 * release-recovered-outbound: the one way a recovered machine's direct
 * outbound mail is released — behind the proof that the recovery finished,
 * that public DNS names this machine and that the operator means it — and
 * through the gateway's own one-use authorization, with a return to held on
 * every failure.
 *
 * It runs from a bundle of its own, as the release workflow uploads one: the
 * REAL release-recovered-outbound, recovery-outbound-hold, mail-routing,
 * mail-identity, targets and install-mail-gateway (behind a thin wrapper that
 * logs each call and can make one fail), with the committed documents —
 * tits-guru active and outbound, direct delivery enabled — and stubs for the
 * signer's installer, the signing verifier and recover-host. The host is
 * mailGatewayHost()'s simulated Postfix host, on which the real gateway was
 * installed held by the recovery hold; mail-identity's DNS, sending address
 * and key are mailIdentityDnsHost()'s, with tits-guru's real key. No tool here
 * opens a connection.
 *
 * Toggles (files under host/toggles): apply-fails-once (the next gateway apply
 * refuses, changing nothing), apply-breaks-once (it installs, then fails),
 * verify-fails, signer-down, readonly-signing-fails, recovery-unverified.
 */
const RELEASE_OPERATION = '20261020-120000-abcdef';
const RELEASE_ADDRESS = '203.0.113.10';

/** @return array<string, string> the wrapper and the stubs, by name */
function releaseOutboundStubs(): array
{
    return [
        'install-mail-gateway' => <<<'STUB'
            #!/bin/bash
            here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
            printf 'install-mail-gateway %s\n' "$*" >> "${STUB_HOST}/calls.log"
            toggle() { [[ -f "${STUB_HOST}/toggles/$1" ]]; }
            case "${1:-}" in
                --apply)
                    if toggle apply-fails-once; then
                        rm -f "${STUB_HOST}/toggles/apply-fails-once"
                        echo "  CONFLICT the installer refused (simulated) — nothing was changed"
                        exit 1
                    fi
                    bash "${here}/install-mail-gateway.real" "$@"
                    status=$?
                    if (( status == 0 )) && toggle apply-breaks-once; then
                        rm -f "${STUB_HOST}/toggles/apply-breaks-once"
                        echo "  ERROR the installer failed after installing (simulated)"
                        exit 1
                    fi
                    exit "${status}"
                    ;;
                --verify)
                    toggle verify-fails && { echo "  DRIFT the gateway does not verify (simulated)"; exit 1; }
                    ;;
            esac
            exec bash "${here}/install-mail-gateway.real" "$@"
            STUB,
        'install-mail-signing' => <<<'STUB'
            #!/bin/bash
            [[ "${1:-}" == --milter-endpoint ]] || printf 'install-mail-signing %s\n' "$*" >> "${STUB_HOST}/calls.log"
            case "${1:-}" in
                --milter-endpoint) echo 'inet:127.0.0.1:8891'; exit 0 ;;
                --verify)
                    [[ -f "${STUB_HOST}/toggles/signer-down" ]] && { echo "  FAIL opendkim.service is not running (simulated)"; exit 1; }
                    echo "  PASS the signer runs on 127.0.0.1:8891 and reads the key (simulated)"
                    exit 0 ;;
            esac
            exit 64
            STUB,
        'verify-mail-signing' => <<<'STUB'
            #!/bin/bash
            printf 'verify-mail-signing %s\n' "$*" >> "${STUB_HOST}/calls.log"
            [[ "${1:-}" == --read-only ]] || exit 64
            [[ -f "${STUB_HOST}/toggles/signer-down" || -f "${STUB_HOST}/toggles/readonly-signing-fails" ]] && { echo "  FAIL the signer cannot sign (simulated)"; exit 1; }
            echo "  PASS the signer and the gateway's wiring (simulated)"
            exit 0
            STUB,
        'recover-host' => <<<'STUB'
            #!/bin/bash
            printf 'recover-host %s\n' "$*" >> "${STUB_HOST}/calls.log"
            [[ "${1:-}" == --verify ]] || exit 64
            if [[ -f "${STUB_HOST}/toggles/recovery-unverified" ]]; then
                echo "ERROR: tits-guru still carries a recovery guard — a recovered target has none (simulated)"
                exit 1
            fi
            echo "RECOVERED: YES"
            printf 'RATEGURU_RECOVER_RESULT={"status":"verified","target":"%s"}\n' "$3"
            exit 0
            STUB,
    ];
}

/**
 * A recovered, adopted machine whose tits-guru outbound mail is still held by
 * its recovery hold, and the trusted bundle that may release it.
 *
 * Options:
 *   hold      changes to the hold the recovery left (default: held, bound to
 *             RELEASE_OPERATION); null: no hold at all
 *   dns       DNS answers instead of the correct ones for RELEASE_ADDRESS
 *   ipv4      the address this machine sends from (default RELEASE_ADDRESS)
 *   queue     lines already in the queue, "ID<TAB>QUEUE<TAB>RECIPIENT"
 *
 * @return array{scratch: string, bundle: string, host: string, fs: string, state: string, key: string, env: array<string, string>}
 */
function releaseOutboundHost(array $options = []): array
{
    $gateway = mailGatewayHost(['policy' => null, 'outbound' => null, 'registry' => null]);
    $scratch = $gateway['scratch'];
    $fs = $gateway['fs'];

    foreach (['/host/toggles', '/run', '/tmp', '/dns'] as $sub) {
        @mkdir($scratch.$sub, 0o755, true);
    }

    $bundle = $scratch.'/bundle';
    @mkdir($bundle.'/infrastructure/scripts', 0o755, true);
    @mkdir($bundle.'/infrastructure/config', 0o755, true);

    foreach (['release-recovered-outbound', 'recovery-outbound-hold', 'smtp-submission', 'public-smtp-port', 'mail-inbound-host', 'mail-routing', 'mail-identity', 'targets', 'install-mail-gateway'] as $name) {
        $copy = $name === 'install-mail-gateway' ? 'install-mail-gateway.real' : $name;
        copy(base_path('infrastructure/scripts/'.$name), "{$bundle}/infrastructure/scripts/{$copy}");
        chmod("{$bundle}/infrastructure/scripts/{$copy}", in_array($name, [...sourcedLibraryNames(), 'recovery-outbound-hold'], true) ? 0o644 : 0o755);
    }

    foreach (releaseOutboundStubs() as $name => $body) {
        file_put_contents("{$bundle}/infrastructure/scripts/{$name}", $body."\n");
        chmod("{$bundle}/infrastructure/scripts/{$name}", 0o755);
    }

    foreach (['deployment-targets.json', 'mail-routing.json', 'mail-outbound.json', 'mail-identity.json'] as $document) {
        copy(base_path('infrastructure/config/'.$document), "{$bundle}/infrastructure/config/{$document}");
    }

    file_put_contents($scratch.'/state/queue', implode('', array_map(static fn (string $line): string => $line."\n", $options['queue'] ?? ["APP0000001\thold\tsomeone@example.net", "APP0000002\thold\tother@example.org"])));
    mailGatewayFakeQueueTools($scratch.'/bin');

    $key = mailIdentityInstallKey($scratch, 'tits-guru', 'rg1');
    $ipv4 = $options['ipv4'] ?? RELEASE_ADDRESS;
    // The DNS a correctly adopted machine answers, with an option's answers
    // over it — or, for 'other-dkim', a record naming another key.
    $good = mailIdentityGoodDns(mailIdentityPublicKey(($options['dns'] ?? null) === 'other-dkim' ? mailIdentityKey('rsa3072') : $key), RELEASE_ADDRESS);
    $answers = is_array($options['dns'] ?? null) ? [...$good, ...$options['dns']] : $good;
    $dns = mailIdentityDnsHost($scratch, $answers, $ipv4);
    unset($dns['RATEGURU_MAILIDENTITY_SIGNING_VERIFIER_BIN']);

    $env = [
        ...$gateway['env'],
        'TMPDIR' => $scratch.'/tmp',
        'RATEGURU_RELEASEOUTBOUND_EUID' => '0',
        'RATEGURU_RELEASEOUTBOUND_RUN_ROOT' => $scratch.'/run',
        'RATEGURU_RELEASEOUTBOUND_HOLD_DIR' => recoveryOutboundHoldDir($fs),
        'RATEGURU_RELEASEOUTBOUND_HOLD_OWNER' => trim((string) shell_exec('id -un')),
        'RATEGURU_RELEASEOUTBOUND_POSTQUEUE_BIN' => $scratch.'/bin/postqueue',
        'STUB_HOST' => $scratch.'/host',
        ...$dns,
    ];

    $host = ['scratch' => $scratch, 'bundle' => $bundle, 'host' => $scratch.'/host', 'fs' => $fs, 'state' => $scratch.'/state', 'key' => $key, 'env' => $env];

    // The recovery placed the hold before the gateway existed, and bound it.
    recoveryOutboundHoldWrite($fs, 'tits-guru', ['operation' => RELEASE_OPERATION]);
    [$status, $output] = releaseOutboundRun($host, ['--apply'], [], 'install-mail-gateway');
    expect($status)->toBe(0, "the recovered machine could not be given its held gateway:\n{$output}");

    if (array_key_exists('hold', $options)) {
        if ($options['hold'] === null) {
            File::deleteDirectory(recoveryOutboundHoldDir($fs));
        } else {
            recoveryOutboundHoldWrite($fs, 'tits-guru', ['operation' => RELEASE_OPERATION, ...$options['hold']]);
        }
    }

    @unlink($host['host'].'/calls.log');

    return $host;
}

/** @return array{0: int, 1: string} */
function releaseOutboundRun(array $host, array $arguments, array $env = [], string $script = 'release-recovered-outbound'): array
{
    $process = proc_open(
        ['bash', "{$host['bundle']}/infrastructure/scripts/{$script}", ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], ...$env],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** @return list<string> the release's arguments for MODE, with CHANGES */
function releaseOutboundArguments(string $mode, array $changes = []): array
{
    $arguments = ['--target' => 'tits-guru', '--operation' => RELEASE_OPERATION, '--address' => RELEASE_ADDRESS, ...$changes];
    $list = ["--{$mode}"];

    foreach ($arguments as $flag => $value) {
        if ($value !== null) {
            $list = [...$list, $flag, $value];
        }
    }

    if ($mode === 'apply' && ! array_key_exists('--confirm-release', $changes)) {
        $list[] = '--confirm-release';
    }

    return $list;
}

function releaseOutboundResult(string $output): ?array
{
    return preg_match_all('/^RATEGURU_RECOVERED_OUTBOUND_RESULT=(\{.*\})$/m', $output, $m) === 1 ? json_decode($m[1][0], true) : null;
}

/** Everything on the machine the release could change: the gateway, its records and the hold. */
function releaseOutboundState(array $host): array
{
    return array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
}

function releaseOutboundRecordedMode(array $host): string
{
    return mailGatewayRecordedMode($host);
}

it('checks a recovered, adopted machine whose public DNS names it, and changes nothing', function () {
    $host = releaseOutboundHost();

    try {
        $before = releaseOutboundState($host);

        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('check'));

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE CHECK: READY')
            ->toContain('PASS the recovery of tits-guru is complete on this machine')
            ->toContain('PASS the gateway is exactly the held one')
            ->toContain('PASS this machine sends from 203.0.113.10, the address the operator confirmed')
            ->toContain('PASS public DNS, as two independent resolvers both answer it')
            ->toContain('the HOLD queue holds 2 message(s) — never released by this operation');
        expect(releaseOutboundState($host))->toBe($before);
        expect(releaseOutboundResult($output))->toMatchArray(['mode' => 'check', 'status' => 'pass', 'released' => false, 'changed' => false, 'held_messages' => 2]);
        expectNoKeyMaterial($output, $host['key']);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('releases the held outbound mail through the gateway\'s one-use authorization, leaves the HOLD queue alone, and is a no-op the second time', function () {
    $host = releaseOutboundHost();

    try {
        $queue = File::get($host['state'].'/queue');

        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('apply'));

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('authorized the activate of tits-guru for exactly the recorded and the requested policy')
            ->toContain('PASS the gateway is exactly the requested one')
            ->toContain('OUTBOUND READY: YES')
            ->toContain('RELEASE: COMPLETE');
        expect(releaseOutboundResult($output))->toMatchArray([
            'mode' => 'apply', 'status' => 'pass', 'operation' => RELEASE_OPERATION, 'address' => RELEASE_ADDRESS,
            'released' => true, 'changed' => true, 'rolled_back' => false, 'outbound_ready' => true, 'held_messages' => 2,
        ]);

        // The hold records the release; the gateway crossed, once.
        expect(recoveryOutboundHoldRead($host['fs']))->toMatchArray(['state' => 'released', 'operation' => RELEASE_OPERATION, 'released_address' => RELEASE_ADDRESS]);
        expect(releaseOutboundRecordedMode($host))->toBe('outbound');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toContain('-o content_filter=rateguru-outbound-tits-guru:');
        expect(File::exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'))->toMatch('/^[0-9a-f]{64} [0-9a-f]{32} activate tits-guru /');

        // Held mail stays held: nothing touched the queue.
        expect(File::get($host['state'].'/queue'))->toBe($queue);
        expect((string) @file_get_contents($host['scratch'].'/log/queue.log'))->not->toContain('postsuper');

        // Again: nothing to do, and nothing done.
        $released = releaseOutboundState($host);
        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('apply'));
        expect($status)->toBe(0, $output);
        expect($output)->toContain('RELEASE: ALREADY RELEASED');
        expect(releaseOutboundResult($output))->toMatchArray(['status' => 'pass', 'released' => true, 'changed' => false]);
        expect(releaseOutboundState($host))->toBe($released);

        [$status, $output] = releaseOutboundRun($host, ['--verify', '--target', 'tits-guru']);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('RELEASE VERIFY: RELEASED');
        expect(releaseOutboundResult($output))->toMatchArray(['mode' => 'verify', 'operation' => RELEASE_OPERATION, 'address' => RELEASE_ADDRESS, 'outbound_ready' => true]);

        expectNoKeyMaterial($output, $host['key']);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses the release, holds the mail and changes nothing whenever the proof falls short', function (array $options, array $changes, ?string $toggle, string $expected) {
    $host = releaseOutboundHost($options);

    try {
        if ($toggle !== null) {
            touch($host['host'].'/toggles/'.$toggle);
        }

        $before = releaseOutboundState($host);

        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('apply', $changes));

        expect($status)->not->toBe(0, $output);
        expect($output)->toContain($expected);
        expect(releaseOutboundState($host))->toBe($before);
        expect(releaseOutboundRecordedMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');
        expect((string) @file_get_contents($host['host'].'/calls.log'))->not->toContain('install-mail-gateway --apply');

        // And the check says the same, without changing anything either —
        // except about the confirmation, which only --apply takes.
        [$status] = releaseOutboundRun($host, releaseOutboundArguments('check', array_diff_key($changes, ['--confirm-release' => true])));
        expect($status === 0)->toBe($changes === ['--confirm-release' => null]);
        expect(releaseOutboundState($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'A names another address' => [['dns' => ['A mta1.tits.guru' => ['198.51.100.7']]], [], null, 'public DNS does not yet name this machine'],
    'PTR names another host' => [['dns' => ['PTR 10.113.0.203.in-addr.arpa' => ['mail.old-host.example.']]], [], null, 'public DNS does not yet name this machine'],
    'SPF does not authorize the address' => [['dns' => ['TXT tits.guru' => ['"v=spf1 ip4:198.51.100.7 -all"']]], [], null, 'public DNS does not yet name this machine'],
    'DKIM names another key' => [['dns' => 'other-dkim'], [], null, 'public DNS does not yet name this machine'],
    'no DMARC' => [['dns' => ['TXT _dmarc.tits.guru' => []]], [], null, 'public DNS does not yet name this machine'],
    'the two resolvers disagree' => [['dns' => ['@8.8.8.8 A mta1.tits.guru' => ['198.51.100.7']]], [], null, 'public DNS does not yet name this machine'],
    'the operator names another address' => [[], ['--address' => '198.51.100.7'], null, 'this machine sends from 203.0.113.10, not 198.51.100.7 — the operator confirmed another host'],
    'the machine sends from another address' => [['ipv4' => '198.51.100.7'], [], null, 'this machine sends from 198.51.100.7, not 203.0.113.10'],
    'another recovery operation' => [[], ['--operation' => '20261020-130000-123456'], null, 'is bound to recovery operation 20261020-120000-abcdef, not 20261020-130000-123456'],
    'another target' => [[], ['--target' => 'staging-main'], null, "the trusted bundle does not deliver staging-main's mail directly"],
    'no hold on this machine' => [['hold' => null], [], null, 'this machine carries no recovery outbound hold for tits-guru'],
    'a hold that cannot be trusted' => [['hold' => ['state' => 'open']], [], null, 'cannot be trusted'],
    'a hold no recovery bound' => [['hold' => ['operation' => null]], [], null, 'is bound to no recovery operation'],
    'the recovery is not finished' => [[], [], 'recovery-unverified', 'recover-host --verify does not prove tits-guru a completed recovery on this machine'],
    'the signer is down' => [[], [], 'signer-down', 'the signer cannot sign tits-guru\'s mail'],
    'the gateway is not the held one' => [[], [], 'verify-fails', 'the installed mail gateway does not verify'],
    'no confirmation' => [[], ['--confirm-release' => null], null, '--apply requires --confirm-release'],
]);

it('returns to held on any failure after the hold is released, and proves it', function (string $toggle, string $expected) {
    $host = releaseOutboundHost();

    try {
        touch($host['host'].'/toggles/'.$toggle);

        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('apply'));

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain($expected)
            ->toContain('ROLLED BACK: tits-guru\'s outbound mail is held again');
        expect(releaseOutboundResult($output))->toMatchArray(['status' => 'fail', 'changed' => true, 'rolled_back' => true, 'released' => false, 'outbound_ready' => false]);

        expect(recoveryOutboundHoldRead($host['fs']))->toMatchArray(['state' => 'held', 'operation' => RELEASE_OPERATION, 'released_at' => null, 'released_address' => null]);
        expect(releaseOutboundRecordedMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');
        expect(File::exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();

        [$status, $report] = releaseOutboundRun($host, ['--verify'], [], 'install-mail-gateway');
        expect($status)->toBe(0, $report);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'the gateway refuses the crossing' => ['apply-fails-once', 'install-mail-gateway --apply refused or failed'],
    'the gateway fails after crossing' => ['apply-breaks-once', 'install-mail-gateway --apply refused or failed'],
    'readiness falls short after crossing' => ['readonly-signing-fails', 'mail-identity readiness is not YES'],
]);

it('never lets an interrupted release deliver: an ordinary apply keeps the mail held, and the next release completes it behind the full proof', function () {
    $host = releaseOutboundHost();

    try {
        // Killed after the hold was marked released, before the gateway
        // crossed, and before its authorization was used or withdrawn.
        recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['operation' => RELEASE_OPERATION, 'state' => 'released', 'released_at' => '2026-10-21T10:00:00Z', 'released_address' => RELEASE_ADDRESS]);

        // An ordinary Prepare, repair or bootstrap cannot cross without the
        // release's authorization: the mail stays held.
        [$status, $output] = releaseOutboundRun($host, ['--apply'], [], 'install-mail-gateway');
        expect($status)->toBe(1, $output);
        expect(releaseOutboundRecordedMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');

        [$status, $output] = releaseOutboundRun($host, ['--verify', '--target', 'tits-guru']);
        expect($status)->toBe(1, $output);
        expect($output)->toContain('an earlier release was interrupted before the gateway crossed');

        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('check'));
        expect($status)->toBe(0, $output);
        expect($output)->toContain('an earlier release of tits-guru was interrupted before the gateway crossed');

        [$status, $output] = releaseOutboundRun($host, releaseOutboundArguments('apply'));
        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('putting the hold back to held, then releasing again behind the full proof')
            ->toContain('PASS public DNS, as two independent resolvers both answer it')
            ->toContain('RELEASE: COMPLETE');
        expect(releaseOutboundRecordedMode($host))->toBe('outbound');
        expect(recoveryOutboundHoldRead($host['fs']))->toMatchArray(['state' => 'released', 'released_address' => RELEASE_ADDRESS]);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('takes the target, the operation and the address only in their exact shapes, and the confirmation only with --apply', function (array $arguments, string $expected) {
    $host = releaseOutboundHost();

    try {
        [$status, $output] = releaseOutboundRun($host, $arguments);

        expect($status)->not->toBe(0, $output);
        expect($output)->toContain($expected);
        expect(recoveryOutboundHoldRead($host['fs'])['state'])->toBe('held');
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'no mode' => [['--target', 'tits-guru'], 'a mode is required'],
    'no operation' => [['--check', '--target', 'tits-guru', '--address', RELEASE_ADDRESS], '--check requires --operation'],
    'no address' => [['--check', '--target', 'tits-guru', '--operation', RELEASE_OPERATION], '--check requires --address'],
    'an operation that is not one' => [['--check', '--target', 'tits-guru', '--operation', 'latest', '--address', RELEASE_ADDRESS], 'invalid recovery operation ID: latest'],
    'an address that is not one' => [['--check', '--target', 'tits-guru', '--operation', RELEASE_OPERATION, '--address', '203.0.113.300'], 'invalid IPv4 address: 203.0.113.300'],
    'a confirmation on a check' => [['--check', '--target', 'tits-guru', '--operation', RELEASE_OPERATION, '--address', RELEASE_ADDRESS, '--confirm-release'], '--confirm-release belongs to --apply alone'],
    'an operation on a verify' => [['--verify', '--target', 'tits-guru', '--operation', RELEASE_OPERATION], '--verify takes --target alone'],
]);

it('is repository tooling that never touches the queue, a DNS record, the deployment binding or the offsite-write hold', function () {
    $source = File::get(base_path('infrastructure/scripts/release-recovered-outbound'));
    $executable = executableSourceLines($source);

    foreach (['postsuper', 'postqueue -f', 'sendmail -q', 'nsupdate', 'DEPLOY_HOST', 'offsite-write-hold', 'nginx', 'certbot', 'systemctl', 'rm -rf'] as $forbidden) {
        expect(str_contains($executable, $forbidden))->toBeFalse("release-recovered-outbound must never run {$forbidden}");
    }

    expect(repositoryOnlyScriptNames())->toContain('release-recovered-outbound');
});
