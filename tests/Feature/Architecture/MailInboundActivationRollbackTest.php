<?php

/**
 * activate-mail-inbound --rollback: public SMTP closed, the receiver disabled,
 * its own firewall rule removed and only it, stored mail kept.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// ROLLBACK
// =============================================================================

it('closes public SMTP: disabled, its own firewall rule removed and only it, stored mail kept', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active', 'ufwRules' => "22/tcp                     ALLOW       Anywhere\n"]);

    try {
        mailInboundActivated($setup);
        $store = $setup['host']['fs'].'/var/spool/rateguru-mail-inbound/store/targets/tits-guru/support/new';
        @mkdir($store, 0o700, true);
        file_put_contents($store.'/1.kept', "a message somebody sent\n");

        [$status, $output, $result] = mailInboundActivationRun($setup, 'rollback');

        expect($status)->toBe(0, $output);
        expect($result)->toMatchArray(['mode' => 'rollback', 'status' => 'pass', 'rolled_back' => true, 'state' => 'installed-disabled', 'public_smtp' => 'disabled']);
        expect($output)->toContain('check the published MX records of every target in the plan');

        expect(mailInboundPort25Closed($setup))->toBeTrue();
        $rules = (string) file_get_contents($setup['host']['state'].'/ufw-rules');
        expect($rules)->toContain('22/tcp')->not->toContain('rateguru-mail-inbound');
        expect(file_get_contents($store.'/1.kept'))->toBe("a message somebody sent\n");
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('rolls back whatever the bundle requests, and does nothing when public SMTP is already closed', function () {
    $setup = mailInboundActivationHost();

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'rollback');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ROLLBACK: NOTHING TO DO — public SMTP is already closed');
        expect($result)->toMatchArray(['status' => 'pass', 'changed' => false, 'state' => 'not-installed']);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('never closes a port 25 that is not the receiver\'s', function () {
    $setup = mailInboundActivationHost(['listeners' => ['0.0.0.0:25 5000 exim4.service']]);

    try {
        [$status, $output] = mailInboundActivationRun($setup, 'rollback');

        expect($status)->not->toBe(0);
        expect($output)->toContain('but no receiver is installed — it is not RateGuru\'s to close; nothing was changed');
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
