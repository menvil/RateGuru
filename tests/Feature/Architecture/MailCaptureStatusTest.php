<?php

use Illuminate\Support\Facades\File;

/*
 * status-mail-capture, run for real against a simulated host.
 *
 * The report is what an operator reads when the slice misbehaves — a unit stuck
 * in "activating (auto-restart)", a listener on the wrong loopback address, an
 * API that stopped answering — so each test builds one of those hosts and reads
 * the report back. Host files are relocated under a scratch root through the
 * gated RATEGURU_MAILCAPTURE_FS_ROOT seam; systemd, the listeners, the APIs and
 * the journal are the stubs in tests/Pest.php.
 */

/**
 * A host with the slice installed: pinned versions, both binaries (answering
 * the version flag each one takes), both state directories and both vhosts.
 */
function mailCaptureStatusInstalledFiles(string $fsRoot): void
{
    foreach (['/etc/staging-mail-capture', '/usr/local/bin', '/etc/nginx/sites-available', '/var/lib/staging-mail-capture/mailpit', '/var/lib/staging-mail-capture/mailtrap-local'] as $directory) {
        @mkdir($fsRoot.$directory, 0o755, true);
    }

    file_put_contents($fsRoot.'/etc/staging-mail-capture/versions.env', File::get(base_path('infrastructure/config/mail-capture/versions.env')));
    writeExecutable($fsRoot.'/usr/local/bin/staging-mailpit', "#!/usr/bin/env bash\n[[ \"\$1\" == version ]] && echo 'mailpit v1.30.5'\n");
    writeExecutable($fsRoot.'/usr/local/bin/staging-mailtrap-local', "#!/usr/bin/env bash\n[[ \"\$1\" == --version ]] && echo 'mailtrap-local 0.2.0'\n");
    file_put_contents($fsRoot.'/var/lib/staging-mail-capture/mailpit/mailpit.db', str_repeat('x', 4096));

    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        file_put_contents($fsRoot.'/etc/nginx/sites-available/'.$vhost, File::get(base_path('infrastructure/config/nginx/'.$vhost)));
    }
}

/** The report's value for a `label: value` line, or null when there is none. */
function mailCaptureStatusValue(string $report, string $label): ?string
{
    return preg_match('/^'.preg_quote($label, '/').'\s+(.*)$/m', $report, $match) === 1 ? $match[1] : null;
}

/** Status never changes anything: only reads reach systemctl and the APIs. */
function expectMailCaptureStatusReadOnly(array $workspace): void
{
    foreach (mailCaptureCalls($workspace) as $call) {
        expect($call)->toMatch(
            '#^(systemctl (is-active|is-enabled|show) |curl -fsS --noproxy \* --max-time 5 http://127\.0\.0\.1:(8025|3550)/api/v1/messages$|journalctl )#',
            "status ran a call that is not a read: {$call}",
        );
    }
}

it('reports an installed, healthy slice in full', function () {
    $workspace = mailCaptureStubWorkspace();
    $fsRoot = $workspace['root'].'/host';

    try {
        mailCaptureStatusInstalledFiles($fsRoot);
        mailCaptureHealthyState($workspace['state']);
        file_put_contents($workspace['state'].'/messages-8025', "a1 one\na2 two\na3 three\n");
        file_put_contents($workspace['state'].'/messages-3550', "b1 one\nb2 two\n");
        file_put_contents($workspace['state'].'/journal-staging-mailpit.service', "mailpit[812]: [smtpd] accepting mail on 127.0.0.1:1025\n");

        $status = mailCaptureStatus($workspace, $fsRoot);
        $report = $status['output'];

        expect($status['exit'])->toBe(0, $report);
        expect(mailCaptureStatusValue($report, 'Checked at:'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');

        expect(mailCaptureStatusValue($report, 'Pinned Mailpit:'))->toBe('1.30.5');
        expect(mailCaptureStatusValue($report, 'Pinned Mailtrap Local:'))->toBe('0.2.0');
        expect(mailCaptureStatusValue($report, 'Mailpit binary:'))->toBe('mailpit v1.30.5');
        expect(mailCaptureStatusValue($report, 'Mailtrap binary:'))->toBe('mailtrap-local 0.2.0');

        expect(mailCaptureStatusValue($report, 'Mailpit (canonical):'))->toBe('active=active enabled=enabled');
        expect(mailCaptureStatusValue($report, 'Mailtrap Local (mirror):'))->toBe('active=active enabled=enabled');
        expect(substr_count($report, 'ActiveState=active SubState=running Result=success ExecMainStatus=0 NRestarts=0'))->toBe(2);

        // The exact endpoint of each listener, the mirror's SMTP on 127.0.0.2.
        expect(mailCaptureStatusValue($report, 'Mailpit SMTP:'))->toBe('127.0.0.1:1025 listening (loopback)');
        expect(mailCaptureStatusValue($report, 'Mailpit HTTP/API:'))->toBe('127.0.0.1:8025 listening (loopback)');
        expect(mailCaptureStatusValue($report, 'Mailtrap SMTP:'))->toBe('127.0.0.2:3535 listening (loopback)');
        expect(mailCaptureStatusValue($report, 'Mailtrap HTTP/API:'))->toBe('127.0.0.1:3550 listening (loopback)');

        expect(mailCaptureStatusValue($report, 'mailpit:'))->toMatch('#^\S+ at '.preg_quote($fsRoot, '#').'/var/lib/staging-mail-capture/mailpit$#');
        expect(mailCaptureStatusValue($report, 'mailtrap-local:'))->toMatch('#^\S+ at '.preg_quote($fsRoot, '#').'/var/lib/staging-mail-capture/mailtrap-local$#');

        expect(mailCaptureStatusValue($report, 'Mailpit messages:'))->toBe('3');
        expect(mailCaptureStatusValue($report, 'Mailtrap messages:'))->toBe('2');

        expect(mailCaptureStatusValue($report, 'Mailpit vhost:'))->toBe('present');
        expect(mailCaptureStatusValue($report, 'Mailtrap vhost:'))->toBe('present');

        // Each unit's heading, then its journal lines.
        expect($report)->toMatch('/^staging-mailpit\.service:\s*\nmailpit\[812\]: \[smtpd\] accepting mail on 127\.0\.0\.1:1025$/m');

        expectMailCaptureStatusReadOnly($workspace);
    } finally {
        removeScratchDir($workspace['root']);
    }
});

it('reports a host with nothing installed and nothing running, and still succeeds', function () {
    $workspace = mailCaptureStubWorkspace();
    $fsRoot = $workspace['root'].'/host';

    try {
        mkdir($fsRoot);

        $status = mailCaptureStatus($workspace, $fsRoot);
        $report = $status['output'];

        expect($status['exit'])->toBe(0, $report);
        expect(mailCaptureStatusValue($report, 'Pinned versions:'))->toBe("not installed ({$fsRoot}/etc/staging-mail-capture/versions.env missing)");
        expect(mailCaptureStatusValue($report, 'Mailpit binary:'))->toBe('not installed');
        expect(mailCaptureStatusValue($report, 'Mailtrap binary:'))->toBe('not installed');

        expect(mailCaptureStatusValue($report, 'Mailpit (canonical):'))->toBe('active=inactive enabled=not-found');
        expect(mailCaptureStatusValue($report, 'Mailtrap Local (mirror):'))->toBe('active=inactive enabled=not-found');

        foreach (['Mailpit SMTP:' => '127.0.0.1:1025', 'Mailpit HTTP/API:' => '127.0.0.1:8025', 'Mailtrap SMTP:' => '127.0.0.2:3535', 'Mailtrap HTTP/API:' => '127.0.0.1:3550'] as $label => $endpoint) {
            expect(mailCaptureStatusValue($report, $label))->toBe("{$endpoint} down");
        }

        expect(mailCaptureStatusValue($report, 'mailpit:'))->toBe("absent ({$fsRoot}/var/lib/staging-mail-capture/mailpit)");
        expect(mailCaptureStatusValue($report, 'Mailpit messages:'))->toBe('API unavailable');
        expect(mailCaptureStatusValue($report, 'Mailtrap messages:'))->toBe('API unavailable');
        expect(mailCaptureStatusValue($report, 'Mailpit vhost:'))->toBe('absent');
        expect(mailCaptureStatusValue($report, 'Mailtrap vhost:'))->toBe('absent');

        expectMailCaptureStatusReadOnly($workspace);
    } finally {
        removeScratchDir($workspace['root']);
    }
});

it('shows a restart-looping mirror, the listener it never bound and its unfiltered journal', function () {
    $workspace = mailCaptureStubWorkspace();
    $fsRoot = $workspace['root'].'/host';
    $state = $workspace['state'];
    $mirror = 'staging-mailtrap-local.service';

    try {
        mailCaptureStatusInstalledFiles($fsRoot);
        mailCaptureHealthyState($state);

        // Mailtrap Local 0.2.0 expanding a 127.0.0.1 bind onto [::1] and dying
        // on it: systemd keeps restarting it, it holds 127.0.0.1:3535 for a
        // moment each time, and never the 127.0.0.2:3535 Mailpit relays to.
        file_put_contents("{$state}/{$mirror}.active", 'activating');
        file_put_contents("{$state}/{$mirror}.sub", 'auto-restart');
        file_put_contents("{$state}/{$mirror}.result", 'exit-code');
        file_put_contents("{$state}/{$mirror}.exec_status", '1');
        file_put_contents("{$state}/{$mirror}.nrestarts", '7');
        file_put_contents("{$state}/listeners", "127.0.0.1:3535\n127.0.0.1:1025\n127.0.0.1:8025\n");
        file_put_contents("{$state}/apis", "http://127.0.0.1:8025/api/v1/info\n");
        // Mailpit answers, but not with JSON.
        file_put_contents("{$state}/messages-body-8025", "<html>502 Bad Gateway</html>\n");
        // Logged at warning, not err: a priority filter would hide all of it.
        file_put_contents("{$state}/journal-{$mirror}", implode("\n", [
            'mailtrap-local[901]: level=ERROR msg="smtp listen" err="listen tcp [::1]:3535: bind: cannot assign requested address"',
            'systemd[1]: staging-mailtrap-local.service: Main process exited, code=exited, status=1/FAILURE',
            'systemd[1]: staging-mailtrap-local.service: Scheduled restart job, restart counter is at 7.',
        ])."\n");

        $status = mailCaptureStatus($workspace, $fsRoot);
        $report = $status['output'];

        expect($status['exit'])->toBe(0, $report);
        expect(mailCaptureStatusValue($report, 'Mailtrap Local (mirror):'))->toBe('active=activating enabled=enabled');
        expect($report)->toContain('ActiveState=activating SubState=auto-restart Result=exit-code ExecMainStatus=1 NRestarts=7');

        expect(mailCaptureStatusValue($report, 'Mailtrap SMTP:'))->toBe('127.0.0.2:3535 down');
        expect(mailCaptureStatusValue($report, 'Mailpit SMTP:'))->toBe('127.0.0.1:1025 listening (loopback)');
        expect(mailCaptureStatusValue($report, 'Mailtrap HTTP/API:'))->toBe('127.0.0.1:3550 down');

        expect(mailCaptureStatusValue($report, 'Mailpit messages:'))->toBe('unknown');
        expect(mailCaptureStatusValue($report, 'Mailtrap messages:'))->toBe('API unavailable');

        expect($report)
            ->toContain('bind: cannot assign requested address')
            ->toContain('Main process exited')
            ->toContain('Scheduled restart job, restart counter is at 7.');

        // The journal is read per unit, for the last hour, with no priority filter.
        expect(array_values(array_filter(mailCaptureCalls($workspace), fn (string $call): bool => str_starts_with($call, 'journalctl '))))->toBe([
            'journalctl -u staging-mailpit.service --no-pager --since -1h',
            'journalctl -u staging-mailtrap-local.service --no-pager --since -1h',
        ]);

        expectMailCaptureStatusReadOnly($workspace);
    } finally {
        removeScratchDir($workspace['root']);
    }
});

it('still reports on a host without ss, jq or journalctl', function () {
    $workspace = mailCaptureStubWorkspace();
    $fsRoot = $workspace['root'].'/host';
    $minimal = $workspace['root'].'/minimal-bin';

    try {
        mailCaptureStatusInstalledFiles($fsRoot);
        mailCaptureHealthyState($workspace['state']);

        // Only what the report and the stubs themselves need: no ss, jq or
        // journalctl anywhere on PATH.
        mkdir($minimal);
        foreach (['bash', 'cat', 'grep', 'wc', 'cp', 'date', 'head', 'tail', 'du', 'awk', 'basename'] as $tool) {
            symlink(trim((string) shell_exec('command -v '.escapeshellarg($tool))), $minimal.'/'.$tool);
        }
        foreach (['systemctl', 'curl'] as $stub) {
            symlink($workspace['bin'].'/'.$stub, $minimal.'/'.$stub);
        }

        $status = mailCaptureRun($workspace, ['bash', infraScript('status-mail-capture')], [
            'PATH' => $minimal,
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_MAILCAPTURE_FS_ROOT' => $fsRoot,
        ]);
        $report = $status['output'];

        expect($status['exit'])->toBe(0, $report);
        expect(mailCaptureStatusValue($report, 'Message counts:'))->toBe('jq not available');
        expect($report)->toMatch('/^journalctl not available$/m');

        // Without ss the listener lines come from a plain TCP connect, which
        // cannot tell loopback addresses apart — so they never claim to.
        foreach (['Mailpit SMTP:' => '127.0.0.1:1025', 'Mailtrap SMTP:' => '127.0.0.2:3535'] as $label => $endpoint) {
            expect(mailCaptureStatusValue($report, $label))->toMatch('/^'.preg_quote($endpoint, '/').' (down|listening)$/');
        }

        expect(mailCaptureStatusValue($report, 'Mailpit (canonical):'))->toBe('active=active enabled=enabled');
    } finally {
        removeScratchDir($workspace['root']);
    }
});

it('reads only the real host paths unless the test seam is allowed', function () {
    $workspace = mailCaptureStubWorkspace();
    $fsRoot = $workspace['root'].'/host';

    try {
        mailCaptureStatusInstalledFiles($fsRoot);

        $status = mailCaptureRun($workspace, ['bash', infraScript('status-mail-capture')], [
            'RATEGURU_MAILCAPTURE_FS_ROOT' => $fsRoot,
        ]);

        // A stray FS_ROOT without the opt-in relocates nothing.
        expect($status['exit'])->toBe(0, $status['output']);
        expect($status['output'])->not->toContain($fsRoot);
        expect(mailCaptureStatusValue($status['output'], 'mailpit:'))->toContain('/var/lib/staging-mail-capture/mailpit');
    } finally {
        removeScratchDir($workspace['root']);
    }
});
