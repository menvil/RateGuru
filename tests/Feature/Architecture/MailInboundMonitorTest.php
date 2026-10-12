<?php

/**
 * The automatic health check's read-only access, installed by
 * install-mail-inbound-monitor through activate-mail-inbound --install-monitor:
 * an account whose one key can run one program — the inbound mail monitor, as
 * postfix, with no argument — and that program.
 *
 * Every test runs the REAL installer and the monitor it REALLY renders against
 * the simulated host (mailInboundHost). That sshd and sudo hold the account to
 * exactly that is proved on a real host (infrastructure/runbooks/mail-inbound.md,
 * "The automatic monitor"); that the monitor reads a real queue as postfix,
 * by the real-Postfix scenario.
 */
const MAIL_MONITOR_FORCED_COMMAND = '/usr/bin/sudo -n -u postfix /usr/local/libexec/rateguru-mail-inbound-monitor';

it('installs one account, one key that runs only the monitor, one sudoers rule and the monitor', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        [$status, $output, $result] = mailInboundMonitorInstalled($host);

        expect($status)->toBe(0, $output);
        expect($result)->toMatchArray(['mode' => 'install-monitor', 'status' => 'pass', 'changed' => true, 'state' => 'installed-enabled']);

        // A system account with no password, whose home and keys are root's.
        expect(mailInboundLog($host, 'mutations.log'))->toContain('useradd --system --user-group --no-create-home --home-dir /var/lib/rateguru-mail-monitor --shell /bin/sh --comment RateGuru inbound mail monitor rateguru-mail-monitor');
        expect((string) file_get_contents($host['state'].'/shadow'))->toContain('rateguru-mail-monitor:!:');

        // Exactly one key, which runs the monitor and nothing else.
        $keys = array_values(array_filter(preg_split('/\R/', mailInboundFile($host, '/var/lib/rateguru-mail-monitor/.ssh/authorized_keys')), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
        expect($keys)->toBe(['restrict,command="'.MAIL_MONITOR_FORCED_COMMAND.'" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMonitorKeyOfItsOwnOnTheSimulatedHost0000000 rateguru-mail-monitor']);

        // sudo: the monitor, with no argument, as postfix — and nothing else.
        $sudoers = array_values(array_filter(preg_split('/\R/', mailInboundFile($host, '/etc/sudoers.d/rateguru-mail-monitor')), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
        expect($sudoers)->toBe([
            'Defaults:rateguru-mail-monitor !requiretty',
            'rateguru-mail-monitor ALL=(postfix) NOPASSWD: /usr/local/libexec/rateguru-mail-inbound-monitor ""',
        ]);
        expect(mailInboundLog($host, 'reads.log'))->toContain('visudo -cqf ');

        foreach (['/var/lib/rateguru-mail-monitor' => '755', '/var/lib/rateguru-mail-monitor/.ssh' => '755', '/var/lib/rateguru-mail-monitor/.ssh/authorized_keys' => '644', '/etc/sudoers.d/rateguru-mail-monitor' => '440', '/usr/local/libexec/rateguru-mail-inbound-monitor' => '755'] as $path => $mode) {
            expect(decoct(fileperms($host['fs'].$path) & 0o777))->toBe($mode, $path);
        }

        // Nothing of Postfix, the queue, the store, sshd or the firewall.
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('systemctl')->not->toContain('ufw')->not->toContain('postsuper');
        expect(file_exists($host['fs'].'/etc/ssh/sshd_config.d'))->toBeFalse();
    } finally {
        mailInboundCleanup($host);
    }
});

it('refuses a key that is not one plain ed25519 public key, before anything changes', function (string $key) {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        [$status, $output] = mailInboundMonitorInstalled($host, $key);

        expect($status)->not->toBe(0);
        expect($output)->toContain('--public-key is not an ed25519 public key ("ssh-ed25519 AAAA…", one line, no options) — nothing was changed');
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-monitor/.ssh/authorized_keys'))->toBeFalse();
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('useradd');
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'options before it' => ['command="/bin/sh" '.MAIL_MONITOR_KEY],
    'restrict lifted' => ['no-restrict '.MAIL_MONITOR_KEY],
    'another key type' => ['ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC0 rateguru-mail-monitor'],
    'a second line' => [MAIL_MONITOR_KEY."\n".MAIL_MONITOR_KEY],
    'a quote in the comment' => [substr(MAIL_MONITOR_KEY, 0, -22).' "x'],
    'a private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----"],
    'a truncated blob' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMonitorKey'],
]);

it('refuses a key any other account on the host already holds: never a privileged key reused', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        [$status, $output] = mailInboundMonitorInstalled($host, MAIL_MONITOR_BOOTSTRAP_KEY.' rateguru-mail-monitor');

        expect($status)->not->toBe(0);
        expect($output)->toContain('this key is already authorized for root — the monitor needs a key of its own, never one that can do more. Nothing was changed');
        expect(file_exists($host['fs'].'/usr/local/libexec/rateguru-mail-inbound-monitor'))->toBeFalse();
    } finally {
        mailInboundCleanup($host);
    }
});

it('installs nothing on a host without inbound mail, and never takes over an account it did not create', function () {
    $absent = mailInboundHost();
    $taken = mailInboundHost(['installed' => 'disabled']);

    try {
        [$status, $output] = mailInboundMonitorInstalled($absent);
        expect($status)->not->toBe(0);
        expect($output)->toContain('inbound mail is not installed on this host — the monitor watches it, so it is installed after the activation. Nothing was changed');

        file_put_contents($taken['state'].'/passwd', "rateguru-mail-monitor:x:1500:1500:Someone:/home/someone:/bin/bash\n", FILE_APPEND);
        [$status, $output] = mailInboundMonitorInstalled($taken);
        expect($status)->not->toBe(0);
        expect($output)->toContain('rateguru-mail-monitor exists but was not created by RateGuru — it is never taken over. Nothing was changed');
        expect(file_exists($taken['fs'].'/var/lib/rateguru-mail-monitor/.ssh/authorized_keys'))->toBeFalse();
    } finally {
        mailInboundCleanup($absent);
        mailInboundCleanup($taken);
    }
});

it('replaces the key when run again, and the activation verify judges the access as installed', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        [$status] = mailInboundMonitorInstalled($host);
        expect($status)->toBe(0);

        $rotated = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRotatedMonitorKeyOnTheSimulatedHost00000000 rateguru-mail-monitor';
        [$status, $output] = mailInboundMonitorInstalled($host, $rotated);
        expect($status)->toBe(0, $output);
        expect(mailInboundFile($host, '/var/lib/rateguru-mail-monitor/.ssh/authorized_keys'))
            ->toContain(substr($rotated, 12, 68))->not->toContain('MonitorKeyOfItsOwn');

        [$status, $output] = mailInboundHostRun($host, 'activate-mail-inbound', ['--verify', '--target', 'tits-guru']);
        expect($output)->toContain("PASS the automatic inbound mail monitor's access is exactly what this bundle renders");

        // A key edited on the host to do more is drift.
        $path = $host['fs'].'/var/lib/rateguru-mail-monitor/.ssh/authorized_keys';
        file_put_contents($path, str_replace('restrict,command="'.MAIL_MONITOR_FORCED_COMMAND.'" ', '', (string) file_get_contents($path)));
        [$status, $output] = mailInboundHostRun($host, 'activate-mail-inbound', ['--verify', '--target', 'tits-guru']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('DRIFT    file:/var/lib/rateguru-mail-monitor/.ssh/authorized_keys not exactly one key restricted to the monitor')
            ->toContain("FAIL the automatic inbound mail monitor's access is not what this bundle renders");
    } finally {
        mailInboundCleanup($host);
    }
});

it('fails, and never passes, when the access would do more or is broken', function (Closure $tamper, string $finding) {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        [$status] = mailInboundMonitorInstalled($host);
        expect($status)->toBe(0);
        $tamper($host);

        [$status, $output] = mailInboundHostRun($host, 'install-mail-inbound-monitor', ['--verify', '--target', 'tits-guru']);
        expect($status)->not->toBe(0);
        expect($output)->toContain($finding);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'a second key' => [fn (array $host) => file_put_contents($host['fs'].'/var/lib/rateguru-mail-monitor/.ssh/authorized_keys', MAIL_MONITOR_BOOTSTRAP_KEY."\n", FILE_APPEND), 'not exactly one key restricted to the monitor'],
    'sudo for more' => [function (array $host): void {
        // As root would: the rule is 0440 to everyone else.
        chmod($host['fs'].'/etc/sudoers.d/rateguru-mail-monitor', 0o640);
        file_put_contents($host['fs'].'/etc/sudoers.d/rateguru-mail-monitor', "rateguru-mail-monitor ALL=(root) NOPASSWD: ALL\n", FILE_APPEND);
        chmod($host['fs'].'/etc/sudoers.d/rateguru-mail-monitor', 0o440);
    }, 'DRIFT    file:/etc/sudoers.d/rateguru-mail-monitor'],
    'the monitor edited' => [fn (array $host) => file_put_contents($host['fs'].'/usr/local/libexec/rateguru-mail-inbound-monitor', "rm -rf /var/spool/postfix\n", FILE_APPEND), 'DRIFT    file:/usr/local/libexec/rateguru-mail-inbound-monitor'],
    'a password given' => [fn (array $host) => file_put_contents($host['state'].'/shadow', str_replace('rateguru-mail-monitor:!:', 'rateguru-mail-monitor:$6$hash:', (string) file_get_contents($host['state'].'/shadow'))), 'FAIL     account                            rateguru-mail-monitor has a password'],
    'the key now root\'s too' => [fn (array $host) => file_put_contents($host['fs'].'/root/.ssh/authorized_keys', MAIL_MONITOR_KEY."\n", FILE_APPEND), 'its key is authorized for another account too: root'],
]);

it('runs the monitor read-only: PASS only when the store takes mail and nothing waits', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundMonitorInstalled($host);
        $before = [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), (string) file_get_contents($host['state'].'/queue')];
        file_put_contents($host['log'].'/mutations.log', '');

        [$status, $output, $result] = mailInboundMonitorRun($host);
        expect($status)->toBe(0, $output);
        expect($result)->toBe(['target' => 'tits-guru', 'mode' => 'monitor', 'status' => 'pass', 'state' => 'installed-enabled', 'gate' => 'DUNNO', 'queue' => ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []]]);

        // Waiting mail, whatever its reason; mail at risk; outbound mail never counted.
        mailInboundQueued($host, [
            ['id' => 'IN1', 'reason' => 'mail system configuration error', 'age' => 3600],
            ['id' => 'IN2', 'via' => 'public', 'age' => 90000],
            ['id' => 'OUT1', 'via' => 'submission', 'reason' => 'Host or domain name not found', 'age' => 400000],
        ]);
        [$status, $output, $result] = mailInboundMonitorRun($host);
        expect($status)->toBe(1, $output);
        expect($result)->toMatchArray(['status' => 'fail']);
        expect($result['queue'])->toMatchArray(['inbound' => 2, 'waiting' => 2, 'at_risk' => 1]);
        expect($output)->toContain('FAIL AT RISK OF LOSS: 1 of them were accepted more than 1d 0h ago')->not->toContain('support@')->not->toContain('sender.example');

        // It changed nothing.
        expect(mailInboundLog($host, 'mutations.log'))->toBe('');
        expect([mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf')])->toBe([$before[0], $before[1]]);
    } finally {
        mailInboundCleanup($host);
    }
});

it('never says pass when it cannot judge: no store, no gate, no queue, no record', function (Closure $break, string $finding) {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundMonitorInstalled($host);
        $break($host);

        [$status, $output, $result] = mailInboundMonitorRun($host);

        expect($status)->toBe(1, $output);
        expect($result['status'] ?? null)->toBe('fail');
        expect($output)->toContain($finding);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'the store volume not mounted' => [fn (array $host) => file_put_contents($host['state'].'/mountinfo', ''), 'FAIL the store gate refuses new inbound mail now (451 4.3.0'],
    'the gate gone' => [fn (array $host) => unlink($host['fs'].'/etc/postfix/rateguru-inbound-store-gate'), 'FAIL the store gate gave no answer'],
    'the queue unreadable' => [fn (array $host) => touch($host['toggles'].'/postqueue-fail'), 'FAIL the shared queue cannot be read'],
    'an envelope unreadable' => [function (array $host): void {
        mailInboundQueued($host, [['id' => 'IN1', 'queue' => 'active', 'age' => 10]]);
        touch($host['toggles'].'/postcat-fail');
    }, 'FAIL the shared queue cannot be read'],
    'the record gone' => [fn (array $host) => unlink($host['fs'].'/var/lib/rateguru-mail-gateway/applied-inbound.json'), 'FAIL inbound mail is not installed on this host — a monitored host always has it'],
    'the queue file system full' => [fn (array $host) => file_put_contents($host['state'].'/free', '1000'), "FAIL the queue's file system has 1000 bytes free"],
]);

it('takes no argument, reads no environment and never writes', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundMonitorInstalled($host);

        [$status, $output, $result] = mailInboundMonitorRun($host, ['--anything']);
        expect($status)->toBe(2);
        expect($output)->toContain('the inbound mail monitor takes no argument — refused');
        expect($result)->toBeNull();

        $monitor = mailInboundFile($host, '/usr/local/libexec/rateguru-mail-inbound-monitor');
        $code = executableSourceLines($monitor);
        // Everything it runs is decided when it is rendered: no override, no
        // environment, no original SSH command.
        foreach (['RATEGURU_ALLOW', '${RATEGURU', '$RATEGURU', 'SSH_ORIGINAL_COMMAND', 'gated_default', '${HOME', 'eval ', 'source '] as $forbidden) {
            expect(str_contains($code, $forbidden))->toBeFalse("the monitor reads {$forbidden}");
        }
        // And it writes nothing, changes no service and touches no message.
        foreach (['postsuper', 'postqueue -f', 'postqueue -i', 'postfix ', 'systemctl', 'ufw', 'rm ', 'mv ', 'cp ', 'install ', 'mkdir', 'chmod', 'chown', 'tee ', 'sendmail', 'ssh ', 'curl', 'wget'] as $mutation) {
            expect(str_contains($code, $mutation))->toBeFalse("the monitor runs {$mutation}");
        }
        preg_match_all('/(?<![<>=-])(?:\d|&)?>{1,2}(?!=)\s*([^\s;|&)]+)/', $code, $targets);
        $files = array_filter(array_unique($targets[1]), static fn (string $target): bool => ! in_array($target, ['/dev/null', '&2'], true) && preg_match('/\A\d+\z/', $target) !== 1);
        expect(array_values($files))->toBe([]);
        expect($code)->toContain('envelope="$("${postcat}" -e -q "${id}" 2>/dev/null)"');
    } finally {
        mailInboundCleanup($host);
    }
});
