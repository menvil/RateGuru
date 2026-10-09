<?php

/**
 * The mail gateway's read-back of its inbound part: every receiver requirement
 * judged by name against what Postfix reads from the installed configuration
 * (postconf), never against what was meant to be written — so a configuration
 * tampered with after the render fails the requirement it no longer meets, and
 * no submission listener may ever take an inbound rule.
 *
 * Every test runs the REAL installer against the simulated host
 * (mailInboundHost).
 */
it('verifies every inbound requirement by name, as Postfix reads the installed configuration', function (string $installed) {
    $host = mailInboundHost(['installed' => $installed]);

    try {
        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->toBe(0, $output);

        preg_match_all('/^  PASS\s+inbound:requirement:([a-z-]+) /m', $output, $met);
        expect($met[1])->toBe(mailInboundJson(['render-plan'])['receiver']['requirements']);
    } finally {
        mailInboundCleanup($host);
    }
})->with(['disabled', 'enabled']);

it('fails the requirement a tampered configuration no longer meets', function (string $from, string $to, string $requirement) {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        $path = $host['fs'].'/etc/postfix/master.cf';
        $master = (string) file_get_contents($path);
        expect(substr_count($master, $from))->toBeGreaterThan(0, "the render has no {$from}");
        file_put_contents($path, preg_replace('/'.preg_quote($from, '/').'/', $to, $master, 1));

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->not->toBe(0);
        expect($output)->toMatch('/CONFLICT\s+inbound:requirement:'.preg_quote($requirement, '/').' /');
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'a trusted network relays' => ['  -o smtpd_relay_restrictions=reject_unauth_destination', '  -o smtpd_relay_restrictions=permit_mynetworks,reject_unauth_destination', 'relay-refused-at-rcpt'],
    'the client restrictions inherited' => ["  -o smtpd_client_restrictions=\n", '', 'relay-refused-at-rcpt'],
    'every recipient accepted' => ['  -o smtpd_recipient_restrictions=reject_unlisted_recipient', '  -o smtpd_recipient_restrictions=permit', 'recipient-allowlist'],
    'SMTP AUTH offered' => ['  -o smtpd_sasl_auth_enable=no', '  -o smtpd_sasl_auth_enable=yes', 'no-smtp-auth'],
    'a bigger message' => ['  -o message_size_limit=10485760', '  -o message_size_limit=52428800', 'message-size-limit'],
    'more recipients' => ['  -o smtpd_recipient_limit=1', '  -o smtpd_recipient_limit=50', 'recipient-limit'],
    'a client exempt from the limits' => ['  -o smtpd_client_event_limit_exceptions=', '  -o smtpd_client_event_limit_exceptions=127.0.0.0/8', 'connection-limits'],
    'no queue floor' => ['  -o queue_minfree=2147483648', '  -o queue_minfree=0', 'queue-limits'],
    'no message rate' => ['  -o smtpd_client_message_rate_limit=60', '  -o smtpd_client_message_rate_limit=0', 'shared-queue-guard'],
    'inbound mail handed to an outbound route' => ["  -o content_filter=\n  -o smtpd_discard", "  -o content_filter=rateguru-outbound-tits-guru:\n  -o smtpd_discard", 'no-outbound-submission'],
    'inbound mail signed' => ["  -o smtpd_milters=\n", "  -o smtpd_milters=inet:127.0.0.1:8891\n", 'no-outbound-submission'],
    'the bare postmaster qualified as the MTA' => ['  -o myorigin=rateguru-mail-inbound.invalid', '  -o myorigin=mta1.tits.guru', 'postmaster-accepted'],
    'TLS sessions logged' => ['  -o smtpd_tls_loglevel=0', '  -o smtpd_tls_loglevel=2', 'no-message-content-in-logs'],
    'a second public listener' => ['1.2.3.4:25     inet', "0.0.0.0:587    inet  n       -       n       -       -       smtpd\n1.2.3.4:25     inet", 'single-public-port-owner'],
    'a submission listener takes the queue floor' => ["  -o syslog_name=postfix/rateguru-tits-guru\n", "  -o syslog_name=postfix/rateguru-tits-guru\n  -o queue_minfree=1\n", 'outbound-unaffected'],
]);

it('never judges a submission listener against the inbound table', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $path = $host['fs'].'/etc/postfix/master.cf';
        $master = (string) file_get_contents($path);
        file_put_contents($path, preg_replace('/(127\.0\.0\.1:2526 .*\n(?:  -o .*\n)*?)  -o smtpd_reject_unlisted_recipient=no\n/', '$1', $master, 1));

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('tits-guru (127.0.0.1:2526) judges recipients against the inbound table');
    } finally {
        mailInboundCleanup($host);
    }
});
