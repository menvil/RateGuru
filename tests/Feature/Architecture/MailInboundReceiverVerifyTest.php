<?php

/**
 * install-mail-inbound --verify: every requirement of the plan, by name, as
 * Postfix reads the installed configuration; a foreign public listener, a stuck
 * queue and TLS that is not ready.
 *
 * Every test runs the shipped installer against a simulated host
 * (mailInboundHost) whose systemd, mount, volume tools, store account,
 * Postfix tools, ss, ip and openssl are stubs that read back what the installer
 * wrote. The rules the receiver applies are mail-inbound's: nothing here
 * restates one.
 */

// =============================================================================
// VERIFY: EVERY REQUIREMENT, AS POSTFIX READS IT
// =============================================================================

it('verifies an installed receiver against every requirement of the plan, by name', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        [$status, $output] = mailInboundRunInstaller($host, '--verify');

        expect($status)->toBe(0, $output);

        $plan = json_decode((string) shell_exec('bash '.escapeshellarg(base_path('infrastructure/scripts/mail-inbound')).' render-plan'), true);
        expect($plan['receiver']['requirements'])->toHaveCount(20);

        foreach ($plan['receiver']['requirements'] as $id) {
            expect($output)->toMatch('/^PASS\s+requirement:'.preg_quote($id, '/').'\s+met, as Postfix reads the installed configuration$/m');
        }

        expect($output)
            ->toMatch('/^DEFERRED\s+tls\s+no certificate is installed/m')
            ->toContain('stored   targets/tits-guru/support/')
            ->toContain('stored   host/postmaster/')
            ->toContain('SUMMARY  pass=')
            ->toContain(' fail=0 drift=0');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('fails a requirement the installed configuration no longer meets', function (string $from, string $to, string $requirement) {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        $path = $host['fs'].'/etc/postfix-inbound/main.cf';
        $main = (string) file_get_contents($path);
        expect(substr_count($main, $from))->toBe(1, "anchor not unique: {$from}");
        file_put_contents($path, str_replace($from, $to, $main));

        [$status, $output] = mailInboundRunInstaller($host, '--verify');

        expect($status)->not->toBe(0);
        expect($output)->toMatch('/^FAIL\s+requirement:'.preg_quote($requirement, '/').'\s/m');
        expect($output)->toMatch('/^DRIFT\s+file:main\.cf/m');
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'relay for trusted networks' => ['smtpd_relay_restrictions = reject_unauth_destination', 'smtpd_relay_restrictions = permit_mynetworks, reject_unauth_destination', 'relay-refused-at-rcpt'],
    'SMTP AUTH' => ['smtpd_sasl_auth_enable = no', 'smtpd_sasl_auth_enable = yes', 'no-smtp-auth'],
    'a bigger message' => ['message_size_limit = 10485760', 'message_size_limit = 52428800', 'message-size-limit'],
    'more recipients' => ['smtpd_recipient_limit = 1', 'smtpd_recipient_limit = 100', 'recipient-limit'],
    'a trusted network exempt from limits' => ['smtpd_client_event_limit_exceptions =', 'smtpd_client_event_limit_exceptions = 127.0.0.0/8', 'connection-limits'],
    'a relay transport' => ['relay_transport = error:5.7.1 the inbound receiver never relays', 'relay_transport = smtp', 'no-relay-no-forwarding'],
    'the signer wired in' => ["\nsmtpd_milters =\n", "\nsmtpd_milters = inet:127.0.0.1:8891\n", 'no-shared-mail-state'],
    'no free-space floor' => ['queue_minfree = 104857600', 'queue_minfree = 0', 'disk-exhaustion-guard'],
    'a command for mail' => ["mailbox_command =\n", "mailbox_command = /bin/sh\n", 'content-never-executed'],
    'bounces to postmaster' => ["notify_classes =\n", "notify_classes = bounce\n", 'no-automatic-replies'],
    'a sender restriction' => ["smtpd_sender_restrictions =\n", "smtpd_sender_restrictions = reject_unknown_sender_domain\n", 'null-sender-accepted'],
]);

it('fails verify when something but the receiver listens on a public SMTP port', function (string $listener, string $reason) {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        mailInboundAddListener($host, $listener);

        [$status, $output] = mailInboundRunInstaller($host, '--verify');

        expect($status)->not->toBe(0);
        expect($output)->toMatch('/^FAIL\s+runtime\s/m')->toContain($reason);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'a foreign process on 25' => ['0.0.0.0:25 5000 exim4.service', 'something listens on 0.0.0.0:25 — the inbound receiver is recorded as disabled'],
    'submission' => ['0.0.0.0:587 5000 dovecot.service', 'no SMTP service may ever listen on port 587'],
    'smtps' => ['0.0.0.0:465 5000 dovecot.service', 'no SMTP service may ever listen on port 465'],
]);

it('fails verify while accepted mail waits in the queue instead of reaching the store', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        file_put_contents($host['state'].'/queue', json_encode(['queue_name' => 'deferred', 'queue_id' => '4j1ABCDEF', 'arrival_time' => time(), 'recipients' => [['address' => 'support@tits.guru']]])."\n");

        [$status, $output] = mailInboundRunInstaller($host, '--verify');

        expect($status)->not->toBe(0);
        expect($output)->toContain('accepted mail has not reached the store and waits in the queue: 4j1ABCDEF');
        // Queue IDs only, never an address.
        expect($output)->not->toContain('support@tits.guru: ');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('says why TLS is not ready, and never prints the certificate or the key', function (array $options, array $toggles, int $status, string $expected) {
    $host = mailInboundHost($options);

    try {
        foreach ($toggles as $toggle) {
            touch($host['scratch'].'/toggles/'.$toggle);
        }

        [$exit, $output] = mailInboundRunInstaller($host, '--tls-check');

        expect($exit)->toBe($status);
        expect($output)->toContain($expected)->not->toContain('not a real key')->not->toContain('BEGIN');
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'ready' => [['tls' => true], [], 0, 'TLS READY: a certificate for mx1.tits.guru, valid for at least 14 days, its key matching'],
    'absent' => [['tls' => false], [], 1, 'TLS NOT READY: no certificate is installed at /etc/rateguru-mail-inbound/tls/fullchain.pem'],
    'untrusted' => [['tls' => true], ['tls-untrusted'], 1, 'a self-signed or incomplete chain never serves the MX'],
]);
