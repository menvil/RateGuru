<?php

/**
 * activate-mail-inbound --apply: installing, proving and opening TCP 25 for
 * exactly the host's address, the firewall rule of its own, and the no-op on a
 * host already enabled and verified.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// THE ACTIVATION
// =============================================================================

it('installs, proves live, opens TCP 25 for exactly the host address, and proves it again', function () {
    $setup = mailInboundActivationHost();

    try {
        $gateway = mailInboundGatewayDigests($setup['host']);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(0, $output);
        expect($result)->toBe([
            'target' => 'tits-guru', 'mode' => 'apply', 'status' => 'pass', 'requested' => true,
            'changed' => true, 'rolled_back' => false, 'state' => 'enabled-verified',
            'public_smtp' => 'enabled', 'address' => '1.2.3.4', 'firewall' => 'none',
        ]);

        // The live proof judged the receiver against mail-inbound route.
        expect($output)
            ->toContain('PASS no SMTP AUTH is offered')
            ->toContain('PASS the receiver announces the reviewed maximum message size (SIZE 10485760)')
            ->toContain('PASS RCPT TO:<support@tits.guru> accepted, as mail-inbound route judges it')
            ->toContain('PASS RCPT TO:<postmaster@bounce.tx.tits.guru> accepted, as mail-inbound route judges it')
            ->toContain('PASS RCPT TO:<Postmaster> accepted, as mail-inbound route judges it')
            ->toContain('PASS RCPT TO:<unknown-mailbox@tits.guru> rejected, as mail-inbound route judges it')
            ->toContain('PASS RCPT TO:<b-i1234567890abcdefghjkmnpqr@bounce.tx.tits.guru> rejected, as mail-inbound route judges it')
            ->toContain('PASS RCPT TO:<b-01234567890abcdefghjkmnpqr@reply.tits.guru> rejected, as mail-inbound route judges it')
            ->toContain('PASS RCPT TO:<probe@relay-probe.invalid> rejected, as mail-inbound route judges it')
            ->toContain('PASS a message to more than 1 recipient(s) is answered with a temporary refusal for the rest')
            ->toContain('stored in host/postmaster/ and removed');

        // Public SMTP on exactly that address, recorded, its authorization used.
        expect(mailInboundApplied($setup['host']))->toMatchArray(['public_smtp' => 'enabled', 'address' => '1.2.3.4']);
        expect(file_get_contents($setup['host']['fs'].'/etc/postfix-inbound/master.cf'))->toContain("1.2.3.4:25 inet n - n - 20 smtpd\n");
        expect(file_exists($setup['host']['fs'].'/var/lib/rateguru-mail-inbound/transition-authorization.json'))->toBeFalse();
        expect(json_decode((string) file_get_contents($setup['host']['fs'].'/var/lib/rateguru-mail-inbound-activation/tits-guru/capsule.json'), true))
            ->toMatchArray(['kind' => 'rateguru-mail-inbound-activation', 'state' => 'activated', 'address' => '1.2.3.4', 'firewall' => 'none']);

        // The probe is gone; nothing of it stays in the store.
        expect(glob($setup['host']['fs'].'/var/spool/rateguru-mail-inbound/store/host/postmaster/new/*') ?: [])->toBe([]);

        // The gateway, byte for byte.
        expect(mailInboundGatewayDigests($setup['host']))->toBe($gateway);
        expect($output)->toContain('PASS the gateway\'s main.cf, master.cf and recorded policy are byte for byte what they were');

        // DNS is the operator's, in order: the A record first.
        $a = strpos($output, '    mx1.tits.guru  A  1.2.3.4');
        $mx = strpos($output, '    tits.guru  MX 10  mx1.tits.guru');
        expect($a)->not->toBeFalse();
        expect($mx)->toBeGreaterThan($a);
        expect($output)->toContain('confirm the provider\'s firewall outside this host allows inbound TCP 25 to 1.2.3.4 — this script cannot see it and never claims it');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('opens TCP 25 in ufw with one rule of its own, and nothing else', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active', 'ufwRules' => "22/tcp                     ALLOW       Anywhere\n"]);

    try {
        mailInboundActivated($setup);

        $rules = (string) file_get_contents($setup['host']['state'].'/ufw-rules');
        expect($rules)
            ->toContain("22/tcp                     ALLOW       Anywhere\n")
            ->toMatch('/^1\.2\.3\.4 25\/tcp\s+ALLOW\s+Anywhere\s+# rateguru-mail-inbound$/m');
        expect(substr_count(mailInboundLog($setup['host'], 'mutations.log'), 'ufw allow'))->toBe(1);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toContain('ufw allow in proto tcp to 1.2.3.4 port 25 comment rateguru-mail-inbound');
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('465')->not->toContain('587')->not->toContain('22/tcp');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('is a no-op on a host already enabled and verified', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active']);

    try {
        mailInboundActivated($setup);
        $tree = mailInboundTree($setup['host'], 'etc');
        file_put_contents($setup['host']['log'].'/mutations.log', '');

        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION: ALREADY ACTIVE — the receiver listens on 1.2.3.4:25 and verifies; nothing was changed');
        expect($result)->toMatchArray(['status' => 'pass', 'changed' => false, 'state' => 'enabled-verified']);
        expect(mailInboundTree($setup['host'], 'etc'))->toBe($tree);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
