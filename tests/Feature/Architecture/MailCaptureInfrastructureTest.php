<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

function mailCaptureSource(string $path): string
{
    $full = base_path('infrastructure/'.$path);

    expect(File::exists($full))->toBeTrue("missing infrastructure file: {$path}");

    return File::get($full);
}

/**
 * Parse an env/`KEY=VALUE` file into an ordered map, ignoring blank and
 * commented lines. Values are returned verbatim (trailing CR stripped).
 *
 * @return array<string, string>
 */
function mailCaptureEnvValues(string $path): array
{
    $out = [];

    foreach (preg_split('/\R/', mailCaptureSource($path)) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $out[trim($key)] = rtrim($value, "\r");
    }

    return $out;
}

/**
 * Collect the values of a systemd directive from uncommented `Key=Value`
 * lines only (skips comments, section headers and continuation lines).
 *
 * @return array<int, string>
 */
function mailCaptureDirectiveValues(string $path, string $key): array
{
    $values = [];

    foreach (preg_split('/\R/', mailCaptureSource($path)) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';' || $trimmed[0] === '[') {
            continue;
        }

        if (! str_contains($trimmed, '=')) {
            continue;
        }

        [$lineKey, $value] = explode('=', $trimmed, 2);

        if (trim($lineKey) === $key) {
            $values[] = rtrim($value, "\r");
        }
    }

    return $values;
}

it('ships every required mail-capture file', function () {
    $required = [
        'ROADMAP.md',
        'config/mail-capture/versions.env',
        'config/mail-capture/mailpit.env',
        'config/mail-capture/mailpit-relay.yml',
        'config/mail-capture/mailtrap-local.yml',
        'config/mail-capture/SHA256SUMS',
        'config/systemd/rateguru-mailpit.service',
        'config/systemd/rateguru-mailtrap-local.service',
        'config/nginx/rateguru-mailpit-staging',
        'config/nginx/rateguru-mailtrap-local-staging',
        'scripts/install-mail-capture',
        'scripts/verify-mail-capture',
        'scripts/status-mail-capture',
        'runbooks/mail-capture.md',
    ];

    foreach ($required as $path) {
        expect(File::exists(base_path('infrastructure/'.$path)))
            ->toBeTrue("missing infrastructure file: {$path}");
    }
});

it('passes bash -n syntax check on every mail-capture script', function () {
    foreach (['install-mail-capture', 'verify-mail-capture', 'status-mail-capture'] as $script) {
        $path = base_path('infrastructure/scripts/'.$script);
        $output = [];
        $exit = 0;
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exit);

        expect($exit)->toBe(0, "bash -n failed for {$script}: ".implode("\n", $output));
    }
});

it('pins exact versions and never uses latest', function () {
    $versions = mailCaptureSource('config/mail-capture/versions.env');

    expect($versions)
        ->toContain('MAILPIT_VERSION=1.30.5')
        ->toContain('MAILTRAP_LOCAL_VERSION=0.2.0')
        // No component is pinned to a moving tag.
        ->not->toContain('=latest');
});

it('commits verifiable SHA-256 checksums for both pinned releases', function () {
    // A single SHA256SUMS pins every archive the installer may download.
    $checksums = mailCaptureSource('config/mail-capture/SHA256SUMS');

    expect($checksums)
        ->toMatch('/^[0-9a-f]{64}  mailpit-linux-amd64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailpit-linux-arm64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailtrap-local_0\.2\.0_linux_amd64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailtrap-local_0\.2\.0_linux_arm64\.tar\.gz$/m');
});

it('binds every capture listener to loopback only', function () {
    $mailpit = mailCaptureSource('config/mail-capture/mailpit.env');
    $mailtrapUnit = mailCaptureSource('config/systemd/rateguru-mailtrap-local.service');

    expect($mailpit)
        ->toContain('MP_SMTP_BIND_ADDR=127.0.0.1:1025')
        ->toContain('MP_UI_BIND_ADDR=127.0.0.1:8025')
        ->not->toContain('0.0.0.0')
        ->and($mailtrapUnit)
        ->toContain('--smtp-listen 127.0.0.1:3535')
        ->toContain('--http-listen 127.0.0.1:3550')
        ->not->toContain('0.0.0.0');
});

it('configures a best-effort relay-all mirror to Mailtrap Local', function () {
    // mailpit.env enables relay-all and references the relay config file...
    $env = mailCaptureEnvValues('config/mail-capture/mailpit.env');

    expect($env['MP_SMTP_RELAY_ALL'])->toBe('true');
    expect($env['MP_SMTP_RELAY_CONFIG'])->toBe('/etc/rateguru/mail-capture/mailpit-relay.yml');
    // The relay target moved out of the env file.
    expect($env)
        ->not->toHaveKey('MP_SMTP_RELAY_HOST')
        ->not->toHaveKey('MP_SMTP_RELAY_PORT');

    // ...and mailpit-relay.yml defines the loopback target using Mailpit's
    // top-level relay schema, with failures logged rather than forwarded.
    $relay = Yaml::parse(mailCaptureSource('config/mail-capture/mailpit-relay.yml'));

    expect($relay['host'])->toBe('127.0.0.1');
    expect($relay['port'])->toBe(3535);
    expect($relay['auth'])->toBe('none');
    // forward-smtp-errors must stay false so a mirror outage never fails delivery.
    expect($relay['forward-smtp-errors'])->toBeFalse();
});

it('enforces retention limits', function () {
    $mailpit = mailCaptureSource('config/mail-capture/mailpit.env');
    $mailtrap = mailCaptureSource('config/mail-capture/mailtrap-local.yml');

    expect($mailpit)
        ->toContain('MP_MAX_MESSAGES=5000')
        ->toContain('MP_MAX_AGE=14d')
        ->and($mailtrap)
        ->toContain('max_messages: 5000');
});

it('hardens both systemd units', function () {
    // Exact configured values, asserted against parsed (uncommented) directives.
    $expected = [
        'NoNewPrivileges' => 'true',
        'PrivateTmp' => 'true',
        'PrivateDevices' => 'true',
        'ProtectSystem' => 'strict',
        'ProtectHome' => 'true',
        'ProtectKernelTunables' => 'true',
        'ProtectKernelModules' => 'true',
        'ProtectControlGroups' => 'true',
        'RestrictSUIDSGID' => 'true',
        'LockPersonality' => 'true',
        'CapabilityBoundingSet' => '', // empty: no capabilities at all
    ];

    $readWritePaths = [
        'rateguru-mailpit' => '/var/lib/rateguru-mail-capture/mailpit',
        'rateguru-mailtrap-local' => '/var/lib/rateguru-mail-capture/mailtrap-local',
    ];

    foreach ($readWritePaths as $unit => $stateDir) {
        $path = 'config/systemd/'.$unit.'.service';

        foreach ($expected as $key => $value) {
            // toContain is variadic (all args are needles), so assert the exact
            // value without a message argument.
            expect(mailCaptureDirectiveValues($path, $key))->toContain($value);
        }

        // Exactly one ReadWritePaths, pointing at this service's state dir only.
        expect(mailCaptureDirectiveValues($path, 'ReadWritePaths'))
            ->toBe([$stateDir], "{$unit}: must grant write access to exactly {$stateDir}");
    }
});

it('makes Mailpit want, but not require, Mailtrap Local', function () {
    $mailpit = mailCaptureSource('config/systemd/rateguru-mailpit.service');

    expect($mailpit)
        ->toContain('Wants=network-online.target rateguru-mailtrap-local.service')
        ->toContain('After=network-online.target rateguru-mailtrap-local.service')
        // No Requires= directive (matching the start of a line, not the comment
        // that explains why we deliberately avoid it).
        ->not->toMatch('/^Requires=/m');

    // The mirror must never depend on Mailpit.
    expect(mailCaptureSource('config/systemd/rateguru-mailtrap-local.service'))
        ->not->toContain('rateguru-mailpit.service');
});

it('protects both web UIs with the shared staging Basic Auth', function () {
    foreach (['rateguru-mailpit-staging', 'rateguru-mailtrap-local-staging'] as $vhost) {
        expect(mailCaptureSource('config/nginx/'.$vhost))
            // Active auth_basic directive with a non-empty realm (not commented).
            ->toMatch('/^\s*auth_basic\s+"[^"]+";\s*$/m')
            // Active auth_basic_user_file pointing at the exact shared htpasswd.
            ->toMatch('#^\s*auth_basic_user_file\s+/etc/nginx/rateguru-staging\.htpasswd;\s*$#m');
    }
});

it('proxies WebSockets to loopback only', function () {
    // Each vhost proxies to its own loopback upstream and uses its own uniquely
    // named connection-upgrade map variable (so the two vhosts never collide).
    $vhosts = [
        'rateguru-mailpit-staging' => ['http://127.0.0.1:8025', '$mailpit_connection_upgrade'],
        'rateguru-mailtrap-local-staging' => ['http://127.0.0.1:3550', '$mailtrap_connection_upgrade'],
    ];

    foreach ($vhosts as $vhost => [$upstream, $connectionVar]) {
        expect(mailCaptureSource('config/nginx/'.$vhost))
            ->toContain('proxy_pass '.$upstream.';')
            ->toContain('proxy_http_version 1.1;')
            ->toContain('proxy_set_header Upgrade $http_upgrade;')
            ->toContain('proxy_set_header Connection '.$connectionVar.';');
    }
});

it('never exposes an SMTP or raw capture port publicly through Nginx', function () {
    foreach (['rateguru-mailpit-staging', 'rateguru-mailtrap-local-staging'] as $vhost) {
        $source = mailCaptureSource('config/nginx/'.$vhost);

        // Only 80 and 443 may be listened on.
        expect($source)
            ->toContain('listen 80;')
            ->toContain('listen 443 ssl http2;');

        foreach (['1025', '3535', '8025', '3550'] as $port) {
            expect($source)->not->toContain('listen '.$port);
            expect($source)->not->toContain('listen [::]:'.$port);
        }
    }
});

it('points staging Laravel mail at the Mailpit loopback SMTP', function () {
    $env = mailCaptureEnvValues('templates/environment/staging.env.example');

    // Exact values, including the deliberately empty credential/encryption keys.
    expect($env)
        ->toHaveKey('MAIL_USERNAME')
        ->toHaveKey('MAIL_PASSWORD')
        ->toHaveKey('MAIL_ENCRYPTION');

    expect($env['MAIL_MAILER'])->toBe('smtp');
    expect($env['MAIL_HOST'])->toBe('127.0.0.1');
    expect($env['MAIL_PORT'])->toBe('1025');
    expect($env['MAIL_USERNAME'])->toBe('');
    expect($env['MAIL_PASSWORD'])->toBe('');
    expect($env['MAIL_ENCRYPTION'])->toBe('');
    expect($env['MAIL_FROM_ADDRESS'])->toBe('noreply@staging.invalid');
    expect($env['MAIL_FROM_NAME'])->toBe('"${APP_NAME}"');
});

it('leaves the production mail configuration unchanged', function () {
    $env = mailCaptureEnvValues('templates/environment/production.env.example');

    // Production mail keys stay exactly empty; no SMTP mailer/sender is injected.
    expect($env['MAIL_MAILER'])->toBe('');
    expect($env['MAIL_FROM_ADDRESS'])->toBe('');
    expect($env['MAIL_FROM_NAME'])->toBe('');

    // No staging SMTP wiring leaked into the production template.
    expect($env)
        ->not->toHaveKey('MAIL_HOST')
        ->not->toHaveKey('MAIL_PORT')
        ->not->toHaveKey('MAIL_USERNAME')
        ->not->toHaveKey('MAIL_PASSWORD')
        ->not->toHaveKey('MAIL_ENCRYPTION');
});

it('dispatches --check to a root-free code path', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    // --check must dispatch to run_check; apply-only work lives in run_apply,
    // which is the path that requires root.
    expect($installer)
        ->toMatch('/--check\)\s*\n\s*MODE="check"/')
        ->toContain('require_root')
        ->toContain('run_apply');
});

it('runs installer --check with stubbed commands and mutates nothing', function () {
    $installer = base_path('infrastructure/scripts/install-mail-capture');
    $stubDir = sys_get_temp_dir().'/mc-check-stubs-'.uniqid();
    $log = $stubDir.'/invoked.log';

    expect(mkdir($stubDir, 0o755, true))->toBeTrue();

    try {
        // Fake `uname` so run_check proceeds past the Linux/arch gate on any host.
        file_put_contents(
            $stubDir.'/uname',
            "#!/usr/bin/env bash\ncase \"\$1\" in\n  -s) echo Linux;;\n  -m) echo x86_64;;\n  *) echo Linux;;\nesac\n",
        );
        chmod($stubDir.'/uname', 0o755);

        // Any mutating command (filesystem, network, users, services) records
        // its invocation. A side-effect-free check must never trigger one.
        $mutating = [
            'useradd', 'systemctl', 'systemd-analyze', 'nginx',
            'curl', 'wget', 'install', 'mkdir', 'rm', 'cp', 'mv',
            'chmod', 'chown', 'ln', 'tar', 'sha256sum',
        ];

        foreach ($mutating as $cmd) {
            file_put_contents(
                $stubDir.'/'.$cmd,
                "#!/usr/bin/env bash\necho \"{$cmd} \$*\" >> ".escapeshellarg($log)."\nexit 0\n",
            );
            chmod($stubDir.'/'.$cmd, 0o755);
        }

        $command = 'PATH='.escapeshellarg($stubDir).':"$PATH" '
            .escapeshellarg($installer).' --check 2>&1';

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        expect($exit)->toBe(0, "check mode failed:\n".implode("\n", $output));
        expect(file_exists($log))
            ->toBeFalse('check mode invoked a mutating command: '
                .(file_exists($log) ? file_get_contents($log) : ''));
    } finally {
        array_map('unlink', glob($stubDir.'/*') ?: []);
        @rmdir($stubDir);
    }
});

it('documents the recovery drill distinctions in the roadmap', function () {
    $roadmap = mailCaptureSource('ROADMAP.md');

    expect($roadmap)
        ->toContain('Staging mail capture')
        ->toContain('current')
        ->toContain('Backup creation')
        ->toContain('Restore-test')
        ->toContain('Clean-server recovery rehearsal')
        ->toContain('Production disaster recovery');
});

it('excludes captured staging mail from disaster-recovery backups', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');
    $backup = mailCaptureSource('scripts/backup');

    expect($runbook)->toContain('exclude');

    // Parse the backup allowlist itself and assert it neither names the
    // mail-capture state tree nor a broader /var/lib parent that would sweep
    // it in indirectly.
    expect(preg_match('/INFRA_PATHS=\((.*?)\)/s', $backup, $allowlist))
        ->toBe(1, 'could not locate the INFRA_PATHS allowlist in scripts/backup');

    expect($allowlist[1])
        ->not->toContain('rateguru-mail-capture')
        ->not->toContain('var/lib');
});
