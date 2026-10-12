<?php

/**
 * activate-mail-inbound --install-tls: the public listener's certificate, put
 * in place by install-mail-inbound-tls under the host's infrastructure lock,
 * in whatever inbound state the host is in — and the activation that follows
 * it, which judges that certificate exactly as before.
 *
 * Every test runs the REAL activation and installer from a trusted bundle
 * against the simulated inbound host with a Certbot lineage
 * (mailInboundCertbotLineage); the activation's probes talk to the fake
 * listener (mailInboundActivationHost), so its own proof is the real one.
 */
function mailInboundActivationTlsHost(array $options = []): array
{
    $setup = mailInboundActivationHost(['tls' => false, ...$options]);
    mailInboundCertbotLineage($setup['host']);

    return $setup;
}

it('installs the certificate Certbot holds in every inbound state, and changes nothing the second time', function (string $installed, string $state) {
    $setup = mailInboundActivationTlsHost(['installed' => $installed]);
    $host = $setup['host'];

    try {
        $applied = mailInboundApplied($host);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'install-tls');

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('---- install-mail-inbound-tls --apply (exit 0)')
            ->toContain('TLS: INSTALLED — the certificate Certbot holds is in place, and every renewal of it reaches the listener');
        expect($result)->toMatchArray(['mode' => 'install-tls', 'status' => 'pass', 'state' => $state, 'changed' => true, 'rolled_back' => false]);
        mailInboundTlsExpectInstalled($host, 1);
        expect(file_exists($host['fs'].MAIL_TLS_HOOK))->toBeTrue();
        // The inbound state is the gateway's, and it is exactly what it was.
        expect(mailInboundApplied($host))->toBe($applied);

        mailInboundTlsResetLogs($host);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'install-tls');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('TLS: ALREADY INSTALLED — the certificate Certbot holds and its renewal hook were already in place; nothing changed');
        expect($result)->toMatchArray(['status' => 'pass', 'state' => $state, 'changed' => false]);
        expect(mailInboundTlsSystemdRequests($host))->toBe([]);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'no inbound mail yet' => ['absent', 'not-installed'],
    'inbound mail installed, public SMTP closed' => ['disabled', 'installed-disabled'],
    'public SMTP open' => ['enabled', 'installed-enabled'],
]);

it('fails, and installs nothing, when the certificate Certbot holds may not be served', function () {
    $setup = mailInboundActivationTlsHost();
    $host = $setup['host'];
    mailInboundCertbotLineage($host, 1, ['names' => 'mail.example.org']);

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'install-tls');

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain('the certificate does not cover mx1.tits.guru')
            ->toContain('FAIL the inbound TLS certificate could not be installed (above)')
            ->toContain('TLS: NOT INSTALLED');
        expect($result)->toMatchArray(['mode' => 'install-tls', 'status' => 'fail', 'changed' => false]);
        expect(file_exists($host['fs'].MAIL_TLS_CERT))->toBeFalse();
        expect(file_exists($host['fs'].MAIL_TLS_HOOK))->toBeFalse();
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('leads to an activation that judges the certificate exactly as before, and opens public SMTP once it is installed', function () {
    $setup = mailInboundActivationTlsHost();

    try {
        // Before: the activation refuses, for the certificate alone.
        [$status, $output, $result] = mailInboundActivationRun($setup, 'check');
        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain('TLS NOT READY: no certificate is installed at '.MAIL_TLS_CERT.' and '.MAIL_TLS_KEY)
            ->toContain('FAIL no usable TLS certificate — install it as infrastructure/runbooks/mail-inbound.md describes, before activating')
            ->toContain('ACTIVATION CHECK: REFUSED — --apply would change nothing (1 problem(s) above)');

        expect(mailInboundActivationRun($setup, 'install-tls')[0])->toBe(0);

        // After: every check of the activation passes, its TLS line included.
        [$status, $output, $result] = mailInboundActivationRun($setup, 'check');
        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('TLS READY: a certificate for mx1.tits.guru, valid for at least 14 days, its key matching')
            ->toContain('PASS a valid certificate covers every MX host name')
            ->toContain('ACTIVATION CHECK: READY');
        expect($result)->toMatchArray(['mode' => 'check', 'status' => 'pass', 'state' => 'not-installed', 'changed' => false]);

        mailInboundActivated($setup);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('takes no monitor key, and refuses to run unprivileged', function (array $arguments, array $env, string $reason) {
    $setup = mailInboundActivationTlsHost();

    try {
        [$status, $output] = mailInboundHostRun($setup['host'], 'activate-mail-inbound', $arguments, $env);

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect(file_exists($setup['host']['fs'].MAIL_TLS_HOOK))->toBeFalse();
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'a monitor key' => [['--install-tls', '--target', 'tits-guru', '--monitor-public-key', MAIL_MONITOR_KEY], [], '--monitor-public-key belongs to --install-monitor alone'],
    'another target' => [['--install-tls', '--target', 'staging-main'], [], 'staging-main is not a production target the trusted bundle\'s inbound plan receives mail for — nothing was changed'],
    'unprivileged' => [['--install-tls', '--target', 'tits-guru'], ['RATEGURU_MAILINBOUNDACTIVATE_EUID' => '1000'], 'activate-mail-inbound --install-tls must run as root'],
]);
