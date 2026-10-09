<?php

use Illuminate\Support\Facades\File;

/**
 * public-smtp-port: the one judge of who may listen on a public SMTP port.
 *
 * 465 and 587: nothing, ever. 25: nothing — except the activated inbound
 * receiver, on exactly its recorded address, held only by processes of its own
 * unit, on a host whose receiver is installed and whose reviewed contract
 * requests public SMTP. The library is sourced and driven directly against a
 * fixture filesystem and an ss stub; then the mail gateway's own runtime check,
 * the outbound activation and the gateway status are proved to ask it.
 */

/**
 * The library's verdict on a host: its listeners ("ADDRESS PID UNIT" or
 * "ADDRESS"), the receiver's recorded state and marker, and the contract.
 *
 * @param  list<string>  $listeners
 * @return list<string>
 */
function publicSmtpProblems(array $listeners, ?array $applied = null, string $marker = 'installed', string $request = 'enabled', bool $ssFails = false, ?string $raw = null): array
{
    $scratch = makeScratchDir('public-smtp-port', ['', '/fs', '/bin']);

    try {
        $state = $scratch.'/fs/var/lib/rateguru-mail-inbound';
        @mkdir($state, 0o755, true);

        if ($raw !== null) {
            file_put_contents($state.'/applied.json', $raw);
        } elseif ($applied !== null) {
            file_put_contents($state.'/applied.json', json_encode(['kind' => 'rateguru-mail-inbound-applied', 'schema_version' => 1, ...$applied]));
        }
        if ($marker !== '') {
            file_put_contents($state.'/ownership', "owner=rateguru\ncomponent=mail-inbound\nstate={$marker}\n");
        }

        $lines = [];
        foreach ($listeners as $listener) {
            $parts = preg_split('/\s+/', $listener);
            if (count($parts) === 3) {
                @mkdir("{$scratch}/fs/proc/{$parts[1]}", 0o755, true);
                file_put_contents("{$scratch}/fs/proc/{$parts[1]}/cgroup", "0::/system.slice/{$parts[2]}\n");
                $lines[] = "LISTEN 0 100 {$parts[0]} 0.0.0.0:* users:((\"master\",pid={$parts[1]},fd=12))";
            } else {
                $lines[] = "LISTEN 0 100 {$parts[0]} 0.0.0.0:*";
            }
        }
        file_put_contents($scratch.'/ss.out', implode("\n", $lines)."\n");
        file_put_contents($scratch.'/bin/ss', $ssFails ? "#!/bin/bash\nexit 1\n" : "#!/bin/bash\ncat ".escapeshellarg($scratch.'/ss.out')."\n");
        chmod($scratch.'/bin/ss', 0o755);

        $contract = json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true);
        $contract['receiver']['public_smtp'] = $request;
        file_put_contents($scratch.'/inbound.json', json_encode($contract));

        $script = 'SS_BIN='.escapeshellarg($scratch.'/bin/ss').'; source '.escapeshellarg(base_path('infrastructure/scripts/public-smtp-port'))
            .'; public_smtp_problems '.escapeshellarg($scratch.'/fs').' '.escapeshellarg($scratch.'/inbound.json');
        $output = (string) shell_exec('bash -c '.escapeshellarg($script).' 2>&1');

        return array_values(array_filter(preg_split('/\R/', trim($output))));
    } finally {
        removeScratchDir($scratch);
    }
}

const PUBLIC_SMTP_ENABLED = ['public_smtp' => 'enabled', 'address' => '1.2.3.4'];
const PUBLIC_SMTP_RECEIVER = '1.2.3.4:25 4100 rateguru-mail-inbound.service';

// =============================================================================
// THE RULE
// =============================================================================

it('allows nothing on a public SMTP port while no receiver is activated', function (?array $applied, string $marker, string $reason) {
    expect(publicSmtpProblems(['0.0.0.0:25 4100 rateguru-mail-inbound.service'], $applied, $marker))
        ->toBe(["something listens on 0.0.0.0:25 — {$reason}"]);
})->with([
    'no receiver at all' => [null, '', 'no SMTP service may listen on port 25 while the inbound receiver is not activated'],
    'a disabled receiver' => [['public_smtp' => 'disabled', 'address' => null], 'installed', 'the inbound receiver is recorded as disabled, so nothing may listen on port 25'],
]);

it('allows the activated receiver alone, on its recorded address, owned by its own unit', function () {
    expect(publicSmtpProblems([PUBLIC_SMTP_RECEIVER, '127.0.0.1:2580 4100 rateguru-mail-inbound.service', '127.0.0.1:2526 4000 postfix@-.service'], PUBLIC_SMTP_ENABLED))
        ->toBe([]);
});

it('never allows 465 or 587, whoever holds them', function (string $listener) {
    expect(publicSmtpProblems([PUBLIC_SMTP_RECEIVER, $listener], PUBLIC_SMTP_ENABLED))
        ->toHaveCount(1)
        ->and(publicSmtpProblems([PUBLIC_SMTP_RECEIVER, $listener], PUBLIC_SMTP_ENABLED)[0])->toContain('no SMTP service may ever listen on port');
})->with([
    'submission, the receiver\'s own unit' => ['1.2.3.4:587 4100 rateguru-mail-inbound.service'],
    'smtps, another process' => ['0.0.0.0:465 5000 dovecot.service'],
    'submission on loopback' => ['127.0.0.1:587 5000 dovecot.service'],
]);

it('refuses everything on 25 that is not exactly the activated receiver', function (array $listeners, ?array $applied, string $marker, string $request, string $reason) {
    $problems = publicSmtpProblems($listeners, $applied, $marker, $request);

    expect(implode("\n", $problems))->toContain($reason);
})->with([
    'another process on the address' => [['1.2.3.4:25 5000 exim4.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', 'is held by process 5000 outside rateguru-mail-inbound.service'],
    'the gateway\'s own Postfix' => [['1.2.3.4:25 4000 postfix@-.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', 'is held by process 4000 outside rateguru-mail-inbound.service'],
    'a process named nothing' => [['1.2.3.4:25'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', 'is held by a process ss could not name'],
    'every address' => [['0.0.0.0:25 4100 rateguru-mail-inbound.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', 'the activated inbound receiver owns only 1.2.3.4:25, never another address or IPv6'],
    'another address' => [['5.6.7.8:25 4100 rateguru-mail-inbound.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', 'never another address or IPv6'],
    'IPv6' => [['[::]:25 4100 rateguru-mail-inbound.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', 'never another address or IPv6'],
    'a second socket' => [[PUBLIC_SMTP_RECEIVER, PUBLIC_SMTP_RECEIVER], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', '2 sockets listen on 1.2.3.4:25 — the inbound receiver owns exactly one'],
    'a receiver not marked installed' => [[PUBLIC_SMTP_RECEIVER], PUBLIC_SMTP_ENABLED, 'installing', 'enabled', 'is missing, corrupt or not marked installed'],
    'no marker at all' => [[PUBLIC_SMTP_RECEIVER], PUBLIC_SMTP_ENABLED, '', 'enabled', 'is missing, corrupt or not marked installed'],
    'a contract that no longer requests it' => [[PUBLIC_SMTP_RECEIVER], PUBLIC_SMTP_ENABLED, 'installed', 'disabled', 'does not request public SMTP — run the inbound rollback, or restore the request'],
    'an enabled record with no address' => [[PUBLIC_SMTP_RECEIVER], ['public_smtp' => 'enabled', 'address' => null], 'installed', 'enabled', 'is missing, corrupt or not marked installed'],
]);

it('refuses a recorded state it cannot read, and a socket table it cannot read', function () {
    expect(implode("\n", publicSmtpProblems([PUBLIC_SMTP_RECEIVER], raw: '{"public_smtp": "enabled"')))
        ->toContain('is missing, corrupt or not marked installed');

    expect(publicSmtpProblems([PUBLIC_SMTP_RECEIVER], PUBLIC_SMTP_ENABLED, ssFails: true))
        ->toBe(['ss -ltnpH could not list the listening sockets — a public SMTP listener cannot be ruled out']);
});

it('says nothing about a host where nothing listens on a public SMTP port, whatever is recorded', function (?array $applied) {
    expect(publicSmtpProblems(['127.0.0.1:2526 4000 postfix@-.service', '127.0.0.1:2580 4100 rateguru-mail-inbound.service'], $applied))->toBe([]);
})->with([
    'nothing recorded' => [null],
    'disabled' => [['public_smtp' => 'disabled', 'address' => null]],
    'enabled but down — the receiver\'s own verify says so, not this judge' => [PUBLIC_SMTP_ENABLED],
]);

// =============================================================================
// EVERY CHECK ASKS IT, AND NONE KEEPS A LIST OF ITS OWN
// =============================================================================

it('is the one place that names the public SMTP ports', function () {
    foreach (glob(base_path('infrastructure/scripts/*')) ?: [] as $path) {
        if (basename($path) === 'public-smtp-port') {
            continue;
        }

        $code = executableSourceLines(File::get($path));
        expect(preg_match('/\b(465|587)\b/', $code))->toBe(0, basename($path).' names a public SMTP port itself');
        expect($code)->not->toContain('PUBLIC_SMTP_PORTS');
    }

    foreach (['install-mail-gateway', 'activate-mail-outbound', 'status-mail-gateway', 'install-mail-inbound', 'activate-mail-inbound'] as $script) {
        $code = executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script)));
        expect($code)->toContain('source "${SCRIPT_DIR}/public-smtp-port"');
    }

    foreach (['install-mail-gateway', 'activate-mail-outbound', 'install-mail-inbound', 'activate-mail-inbound'] as $script) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script))))->toContain('public_smtp_problems');
    }
});

it('is a sourced library, in every release tree', function () {
    expect(sourcedLibraryNames())->toContain('public-smtp-port');
    expect(decoct(fileperms(base_path('infrastructure/scripts/public-smtp-port')) & 0o777))->toBe('644');
    expect(File::get(base_path('infrastructure/scripts/verify-required-clis')))->toContain('SOURCED_LIBRARIES=(common restore-common smtp-submission public-smtp-port)');
});

it('fails the mail gateway\'s verify for a foreign listener on 25, and passes it for the activated receiver', function () {
    $host = mailGatewayEstablishedHost();

    try {
        [$status, $output] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(0, $output);

        // An activated receiver, recorded and owned by its own unit.
        $fs = $host['fs'];
        @mkdir($fs.'/var/lib/rateguru-mail-inbound', 0o755, true);
        file_put_contents($fs.'/var/lib/rateguru-mail-inbound/ownership', "state=installed\n");
        file_put_contents($fs.'/var/lib/rateguru-mail-inbound/applied.json', json_encode(['kind' => 'rateguru-mail-inbound-applied', 'schema_version' => 1, ...PUBLIC_SMTP_ENABLED]));
        @mkdir($fs.'/proc/4100', 0o755, true);
        file_put_contents($fs.'/proc/4100/cgroup', "0::/system.slice/rateguru-mail-inbound.service\n");
        file_put_contents($host['scratch'].'/state/listeners', "1.2.3.4:25 users:((\"master\",pid=4100,fd=12))\n", FILE_APPEND);

        [$status, $output] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(0, $output);

        // The same port held by another process fails it.
        @mkdir($fs.'/proc/5000', 0o755, true);
        file_put_contents($fs.'/proc/5000/cgroup', "0::/system.slice/exim4.service\n");
        file_put_contents($host['scratch'].'/state/listeners', "0.0.0.0:25 users:((\"exim4\",pid=5000,fd=3))\n", FILE_APPEND);

        [$status, $output] = mailGatewayRun($host, '--verify');
        expect($status)->not->toBe(0);
        expect($output)->toContain('something listens on 0.0.0.0:25 — the activated inbound receiver owns only 1.2.3.4:25');
    } finally {
        mailGatewayCleanup($host);
    }
});
