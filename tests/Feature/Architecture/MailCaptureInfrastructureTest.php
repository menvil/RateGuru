<?php

use Illuminate\Support\Facades\File;

function mailCaptureSource(string $path): string
{
    $full = base_path('infrastructure/'.$path);

    expect(File::exists($full))->toBeTrue("missing infrastructure file: {$path}");

    return File::get($full);
}

it('ships every required mail-capture file', function () {
    $required = [
        'ROADMAP.md',
        'config/mail-capture/versions.env',
        'config/mail-capture/mailpit.env',
        'config/mail-capture/mailtrap-local.yml',
        'config/mail-capture/checksums/mailpit-1.30.5.sha256',
        'config/mail-capture/checksums/mailtrap-local-0.2.0.sha256',
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
    $mailpit = mailCaptureSource('config/mail-capture/checksums/mailpit-1.30.5.sha256');
    $mailtrap = mailCaptureSource('config/mail-capture/checksums/mailtrap-local-0.2.0.sha256');

    expect($mailpit)
        ->toMatch('/^[0-9a-f]{64}  mailpit-linux-amd64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailpit-linux-arm64\.tar\.gz$/m')
        ->and($mailtrap)
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
    $mailpit = mailCaptureSource('config/mail-capture/mailpit.env');

    expect($mailpit)
        ->toContain('MP_SMTP_RELAY_ALL=true')
        ->toContain('MP_SMTP_RELAY_HOST=127.0.0.1')
        ->toContain('MP_SMTP_RELAY_PORT=3535')
        // Relay failures must NOT be forwarded to the upstream SMTP client.
        ->not->toContain('MP_SMTP_RELAY_FWD_SMTP_ERRORS=true');
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
    $directives = [
        'NoNewPrivileges=true',
        'PrivateTmp=true',
        'PrivateDevices=true',
        'ProtectSystem=strict',
        'ProtectHome=true',
        'ProtectKernelTunables=true',
        'ProtectKernelModules=true',
        'ProtectControlGroups=true',
        'RestrictSUIDSGID=true',
        'LockPersonality=true',
        'CapabilityBoundingSet=',
    ];

    foreach (['rateguru-mailpit', 'rateguru-mailtrap-local'] as $unit) {
        $source = mailCaptureSource('config/systemd/'.$unit.'.service');

        foreach ($directives as $directive) {
            expect($source)->toContain($directive);
        }
    }

    expect(mailCaptureSource('config/systemd/rateguru-mailpit.service'))
        ->toContain('ReadWritePaths=/var/lib/rateguru-mail-capture/mailpit');
    expect(mailCaptureSource('config/systemd/rateguru-mailtrap-local.service'))
        ->toContain('ReadWritePaths=/var/lib/rateguru-mail-capture/mailtrap-local');
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
            ->toContain('auth_basic')
            ->toContain('auth_basic_user_file /etc/nginx/rateguru-staging.htpasswd');
    }
});

it('proxies WebSockets to loopback only', function () {
    foreach ([
        'rateguru-mailpit-staging' => 'http://127.0.0.1:8025',
        'rateguru-mailtrap-local-staging' => 'http://127.0.0.1:3550',
    ] as $vhost => $upstream) {
        expect(mailCaptureSource('config/nginx/'.$vhost))
            ->toContain('proxy_pass '.$upstream.';')
            ->toContain('proxy_set_header Upgrade $http_upgrade;')
            ->toContain('proxy_set_header Connection');
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
    $staging = mailCaptureSource('templates/environment/staging.env.example');

    expect($staging)
        ->toContain('MAIL_MAILER=smtp')
        ->toContain('MAIL_HOST=127.0.0.1')
        ->toContain('MAIL_PORT=1025')
        ->toContain('MAIL_USERNAME=')
        ->toContain('MAIL_PASSWORD=')
        ->toContain('MAIL_ENCRYPTION=')
        ->toContain('MAIL_FROM_ADDRESS=noreply@staging.invalid')
        ->toContain('MAIL_FROM_NAME="${APP_NAME}"');
});

it('leaves the production mail configuration unchanged', function () {
    $production = mailCaptureSource('templates/environment/production.env.example');

    // Production mail keys stay empty; no SMTP host/port/mailer is injected.
    expect($production)
        ->toContain('MAIL_MAILER=')
        ->toContain('MAIL_FROM_ADDRESS=')
        ->toContain('MAIL_FROM_NAME=')
        ->not->toContain('MAIL_MAILER=smtp')
        ->not->toContain('MAIL_HOST=')
        ->not->toContain('MAIL_PORT=')
        ->not->toContain('127.0.0.1:1025');
});

it('keeps the installer check mode free of side effects', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    // --check must dispatch to run_check.
    expect($installer)->toMatch('/--check\)\s*\n\s*MODE="check"/');

    // The run_check body must not perform any mutating operation.
    expect(preg_match('/run_check\(\)\s*\{(.*?)\n\}/s', $installer, $matches))->toBe(1);
    $body = $matches[1];

    foreach (['useradd', 'systemctl', 'daemon-reload', 'fetch_binary', 'download ', 'install -o', 'mv -f', 'nginx -t'] as $mutating) {
        expect(str_contains($body, $mutating))
            ->toBeFalse("run_check must not call '{$mutating}'");
    }

    // Apply-only work lives in run_apply, which does require root.
    expect($installer)
        ->toContain('require_root')
        ->toContain('run_apply');
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
    // The backup allowlist must never include the mail-capture state dir.
    expect($backup)->not->toContain('rateguru-mail-capture');
});
