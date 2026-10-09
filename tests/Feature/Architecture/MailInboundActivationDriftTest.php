<?php

/**
 * activate-mail-inbound --verify: an enabled host that is not what it should
 * be is drift, by name, whatever changed.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// VERIFY: DRIFT ON AN ENABLED HOST
// =============================================================================

it('reports drift when the enabled host is not what it should be', function (string $change, string $reason) {
    $setup = mailInboundActivationHost(['ufw' => 'active']);

    try {
        mailInboundActivated($setup);

        match ($change) {
            'rule' => file_put_contents($setup['host']['state'].'/ufw-rules', ''),
            'foreign' => mailInboundAddListener($setup['host'], '0.0.0.0:587 5000 dovecot.service'),
            'applied' => file_put_contents($setup['host']['fs'].'/var/lib/rateguru-mail-inbound/applied.json', '{}'),
        };

        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect($result)->toMatchArray(['status' => 'fail', 'state' => 'drift']);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'its firewall rule removed by hand' => ['rule', 'RateGuru\'s ufw rule for it is missing'],
    'another SMTP listener' => ['foreign', 'no SMTP service may ever listen on port 587'],
    'its recorded state corrupted' => ['applied', 'FAIL'],
]);
