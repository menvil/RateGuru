<?php

/**
 * public-smtp-port: the one judge of who may listen on a public SMTP port.
 *
 * 465 and 587: nothing, ever. 25: nothing — except the host's own Postfix,
 * once activate-mail-inbound has enabled public inbound SMTP: exactly one
 * socket, on exactly the address the gateway recorded, held only by processes
 * of Postfix's own unit (judged by their cgroup, never their name), named as
 * the one public listener of the installed master.cf, on a host whose gateway
 * is installed and whose reviewed contract requests public SMTP. The library
 * is sourced and driven directly against a fixture filesystem and an ss stub;
 * then the mail gateway's own runtime check is proved to ask it.
 */
const PUBLIC_SMTP_ENABLED = ['public_smtp' => 'enabled', 'address' => '1.2.3.4'];
const PUBLIC_SMTP_POSTFIX = '1.2.3.4:25 4000 system-postfix.slice/postfix@-.service';

/**
 * The library's verdict on a host: its listeners ("ADDRESS PID UNIT" or
 * "ADDRESS"), the gateway's recorded inbound state and marker, the public
 * listeners its master.cf names, and the contract's request.
 *
 * @param  list<string>  $listeners
 * @param  list<string>  $master
 * @return list<string>
 */
function publicSmtpProblems(array $listeners, ?array $applied = null, string $marker = 'installed', string $request = 'enabled', ?array $master = null, bool $ssFails = false, ?string $raw = null): array
{
    $scratch = makeScratchDir('public-smtp-port', ['', '/fs', '/bin']);

    try {
        $state = $scratch.'/fs/var/lib/rateguru-mail-gateway';
        @mkdir($state, 0o755, true);

        if ($raw !== null) {
            file_put_contents($state.'/applied-inbound.json', $raw);
        } elseif ($applied !== null) {
            file_put_contents($state.'/applied-inbound.json', json_encode(['kind' => 'rateguru-mail-gateway-applied-inbound', 'schema_version' => 1, ...$applied]));
        }
        if ($marker !== '') {
            file_put_contents($state.'/ownership', "owner=rateguru\ncomponent=mail-gateway\nstate={$marker}\n");
        }

        $master ??= ($applied['public_smtp'] ?? null) === 'enabled' ? [($applied['address'] ?? '1.2.3.4').':25'] : [];
        @mkdir($scratch.'/fs/etc/postfix', 0o755, true);
        file_put_contents($scratch.'/fs/etc/postfix/master.cf', implode('', array_map(
            static fn (string $service): string => "{$service} inet  n       -       n       -       -       smtpd\n",
            ['127.0.0.1:2525', '127.0.0.1:2526', '127.0.0.1:2580', ...$master],
        )));

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

        file_put_contents($scratch.'/inbound.json', json_encode(mailInboundContractWith(['receiver.public_smtp' => $request])));

        $script = 'SS_BIN='.escapeshellarg($scratch.'/bin/ss').'; source '.escapeshellarg(base_path('infrastructure/scripts/public-smtp-port'))
            .'; public_smtp_problems '.escapeshellarg($scratch.'/fs').' '.escapeshellarg($scratch.'/inbound.json');
        $output = (string) shell_exec('bash -c '.escapeshellarg($script).' 2>&1');

        return array_values(array_filter(preg_split('/\R/', trim($output))));
    } finally {
        removeScratchDir($scratch);
    }
}

// =============================================================================
// THE RULE
// =============================================================================

it('allows nothing on a public SMTP port while public inbound SMTP is not activated', function (?array $applied, string $reason) {
    expect(publicSmtpProblems(['0.0.0.0:25 4000 system-postfix.slice/postfix@-.service'], $applied))
        ->toBe(["something listens on 0.0.0.0:25 — {$reason}"]);
})->with([
    'no inbound mail at all' => [null, 'no SMTP service may listen on port 25 while public inbound SMTP is not activated'],
    'inbound mail disabled' => [['public_smtp' => 'disabled', 'address' => null], 'public inbound SMTP is recorded as disabled, so nothing may listen on port 25'],
]);

it('allows the host Postfix alone, on its recorded address, as the one public listener of its master.cf', function () {
    expect(publicSmtpProblems([PUBLIC_SMTP_POSTFIX, '127.0.0.1:2580 4000 system-postfix.slice/postfix@-.service', '127.0.0.1:2526 4000 system-postfix.slice/postfix@-.service'], PUBLIC_SMTP_ENABLED))
        ->toBe([]);
});

it('never allows 465 or 587, whoever holds them', function (string $listener) {
    $problems = publicSmtpProblems([PUBLIC_SMTP_POSTFIX, $listener], PUBLIC_SMTP_ENABLED);

    expect($problems)->toHaveCount(1);
    expect($problems[0])->toContain('no SMTP service may ever listen on port');
})->with([
    'submission, the host Postfix itself' => ['1.2.3.4:587 4000 system-postfix.slice/postfix@-.service'],
    'smtps, another process' => ['0.0.0.0:465 5000 dovecot.service'],
    'submission on loopback' => ['127.0.0.1:587 5000 dovecot.service'],
]);

it('refuses everything on 25 that is not exactly the activated public listener of the host Postfix', function (array $listeners, ?array $applied, string $marker, string $request, ?array $master, string $reason) {
    expect(implode("\n", publicSmtpProblems($listeners, $applied, $marker, $request, $master)))->toContain($reason);
})->with([
    'another process on the address' => [['1.2.3.4:25 5000 exim4.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, 'is held by process 5000 outside postfix@-.service — only the host\'s own Postfix may listen on port 25'],
    'a process named master, outside Postfix\'s unit' => [['1.2.3.4:25 5001 rogue-master.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, 'is held by process 5001 outside postfix@-.service'],
    'a process ss could not name' => [['1.2.3.4:25'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, 'is held by a process ss could not name'],
    'every address' => [['0.0.0.0:25 4000 system-postfix.slice/postfix@-.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, 'public inbound SMTP is activated on 1.2.3.4:25 only, never another address or IPv6'],
    'another address' => [['5.6.7.8:25 4000 system-postfix.slice/postfix@-.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, 'never another address or IPv6'],
    'IPv6' => [['[::]:25 4000 system-postfix.slice/postfix@-.service'], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, 'never another address or IPv6'],
    'a second socket' => [[PUBLIC_SMTP_POSTFIX, PUBLIC_SMTP_POSTFIX], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', null, '2 sockets listen on 1.2.3.4:25 — public inbound SMTP is exactly one listener'],
    'a gateway not marked installed' => [[PUBLIC_SMTP_POSTFIX], PUBLIC_SMTP_ENABLED, 'installing', 'enabled', null, 'is corrupt or its gateway is not marked installed'],
    'no gateway marker at all' => [[PUBLIC_SMTP_POSTFIX], PUBLIC_SMTP_ENABLED, '', 'enabled', null, 'is corrupt or its gateway is not marked installed'],
    'a contract that no longer requests it' => [[PUBLIC_SMTP_POSTFIX], PUBLIC_SMTP_ENABLED, 'installed', 'disabled', null, 'does not request it — run the inbound rollback, or restore the request'],
    'an enabled record with no address' => [[PUBLIC_SMTP_POSTFIX], ['public_smtp' => 'enabled', 'address' => null], 'installed', 'enabled', ['1.2.3.4:25'], 'is corrupt or its gateway is not marked installed'],
    'a second public listener in master.cf' => [[PUBLIC_SMTP_POSTFIX], PUBLIC_SMTP_ENABLED, 'installed', 'enabled', ['1.2.3.4:25', '0.0.0.0:2525'], 'the installed master.cf names the public listeners [1.2.3.4:25 0.0.0.0:2525], not exactly 1.2.3.4:25'],
    'a public listener while disabled' => [[], ['public_smtp' => 'disabled', 'address' => null], 'installed', 'enabled', ['1.2.3.4:25'], 'the installed master.cf names a listener off loopback (1.2.3.4:25) while public inbound SMTP is not activated'],
]);

it('refuses a recorded state it cannot read, and a socket table it cannot read', function () {
    expect(implode("\n", publicSmtpProblems([PUBLIC_SMTP_POSTFIX], raw: '{"public_smtp": "enabled"')))
        ->toContain('is corrupt or its gateway is not marked installed');

    expect(publicSmtpProblems([PUBLIC_SMTP_POSTFIX], PUBLIC_SMTP_ENABLED, ssFails: true))
        ->toBe(['ss -ltnpH could not list the listening sockets — a public SMTP listener cannot be ruled out']);
});

it('says nothing about a host where nothing listens on a public SMTP port and master.cf names none', function (?array $applied) {
    expect(publicSmtpProblems(['127.0.0.1:2526 4000 system-postfix.slice/postfix@-.service', '127.0.0.1:2580 4000 system-postfix.slice/postfix@-.service'], $applied, master: []))->toBe([]);
})->with([
    'nothing recorded' => [null],
    'disabled' => [['public_smtp' => 'disabled', 'address' => null]],
]);

// =============================================================================
// EVERY CHECK ASKS IT, AND NONE KEEPS A LIST OF ITS OWN
// =============================================================================

it('is the one place that names the public SMTP ports', function () {
    foreach (glob(base_path('infrastructure/scripts/*')) ?: [] as $path) {
        if (in_array(basename($path), ['public-smtp-port', 'mail-inbound-host'], true)) {
            continue;
        }

        $code = executableSourceLines(File::get($path));
        expect(preg_match('/\b(465|587)\b/', $code))->toBe(0, basename($path).' names a public SMTP port itself');
        expect($code)->not->toContain('PUBLIC_SMTP_PORTS');
    }

    foreach (['install-mail-gateway', 'activate-mail-outbound', 'status-mail-gateway', 'activate-mail-inbound'] as $script) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script))))->toContain('source "${SCRIPT_DIR}/public-smtp-port"');
    }

    foreach (['install-mail-gateway', 'activate-mail-outbound', 'activate-mail-inbound'] as $script) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script))))->toContain('public_smtp_problems');
    }
});

it('judges the owner by its unit, never by a process name', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/public-smtp-port')));

    expect($code)->toContain('cgroup="$(cat "${root}/proc/${pid}/cgroup" 2>/dev/null)"')->not->toContain('"master"')->not->toContain('comm');
    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/mail-inbound-host'))))->toContain('MAIL_INBOUND_POSTFIX_UNIT="postfix@-.service"');
});

it('only reads: it writes no file and runs nothing that changes the host', function () {
    foreach (['public-smtp-port', 'mail-inbound-host'] as $library) {
        $code = executableSourceLines(File::get(base_path("infrastructure/scripts/{$library}")));

        // Output is only ever redirected away, never into a file.
        preg_match_all('/(?<![<>=-])(?:\d|&)?>{1,2}(?!=)\s*([^\s;|&)]+)/', $code, $targets);
        expect(array_values(array_diff(array_unique($targets[1]), ['/dev/null', '&2'])))->toBe([], "{$library} writes a file");

        foreach (['systemctl', 'postconf -e', 'postfix reload', 'postfix start', 'postfix stop', 'postsuper', 'ufw', 'mv ', 'cp ', 'rm ', 'install ', 'mkdir', 'chmod', 'chown', 'tee '] as $mutation) {
            expect(str_contains($code, $mutation))->toBeFalse("{$library} runs {$mutation}");
        }
    }
});

it('is a pair of sourced libraries, in every release tree', function () {
    foreach (['public-smtp-port', 'mail-inbound-host'] as $library) {
        expect(sourcedLibraryNames())->toContain($library);
        expect(decoct(fileperms(base_path("infrastructure/scripts/{$library}")) & 0o777))->toBe('644');
    }
    expect(File::get(base_path('infrastructure/scripts/verify-required-clis')))->toContain('SOURCED_LIBRARIES=(common restore-common smtp-submission public-smtp-port mail-inbound-host)');
});

it('fails the mail gateway\'s verify for a foreign listener on 25, and passes it for the activated public listener', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->toBe(0, $output);

        // The same port held by another process fails it.
        @mkdir($host['fs'].'/proc/5000', 0o755, true);
        file_put_contents($host['fs'].'/proc/5000/cgroup', "0::/system.slice/exim4.service\n");
        file_put_contents($host['state'].'/listeners', "0.0.0.0:25 users:((\"exim4\",pid=5000,fd=3))\n", FILE_APPEND);

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('something listens on 0.0.0.0:25 — public inbound SMTP is activated on 1.2.3.4:25 only');
    } finally {
        mailInboundCleanup($host);
    }
});
