<?php

/**
 * activate-mail-inbound --apply: the store, inbound mail installed on the
 * gateway's Postfix, the proof on loopback, then TCP 25 opened for exactly the
 * host's address — the firewall rule and the gateway's reload with the public
 * listener — and the proof again; Postfix is never restarted, and the outbound
 * path is exactly what it was. A host already enabled and verified is a no-op.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost); its probes talk to a fake listener that
 * answers from the configuration the real gateway rendered.
 */
it('installs, proves on loopback, opens TCP 25 for exactly the host address by a reload, and proves it again', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active']);

    try {
        $outbound = mailInboundOutboundConfiguration($setup['host']);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(0, $output);
        expect($result)->toBe([
            'target' => 'tits-guru', 'mode' => 'apply', 'status' => 'pass', 'requested' => true, 'changed' => true,
            'rolled_back' => false, 'state' => 'enabled-verified', 'public_smtp' => 'enabled', 'address' => '1.2.3.4', 'firewall' => 'ufw',
        ]);

        // In order: the store, the install transition, the proof on loopback,
        // the firewall, the enable transition, the proof again.
        $order = ['install-mail-inbound --apply (exit 0)', 'the gateway made the install transition', 'the proof on loopback',
            'opening TCP 25 to 1.2.3.4 in ufw', 'the gateway made the enable transition and records public SMTP enabled on 1.2.3.4:25', 'the proof of public SMTP'];
        $position = -1;
        foreach ($order as $step) {
            $next = strpos($output, $step);
            expect($next)->not->toBeFalse("missing: {$step}");
            expect($next)->toBeGreaterThan($position, "out of order: {$step}");
            $position = $next;
        }

        expect($output)
            ->toContain('PASS only the host\'s Postfix (postfix@-.service) listens on TCP 25, on 1.2.3.4 alone; no other public SMTP port is open')
            ->toContain('PASS the public listener offers STARTTLS')
            ->toContain('PASS the public listener offers no SMTP AUTH')
            ->toContain('PASS the public listener refuses to relay')
            ->toContain('PASS the outbound path is exactly what it was')
            ->toContain('NEXT (operator): confirm the provider\'s firewall outside this host allows inbound TCP 25 to 1.2.3.4');
        // DNS is the operator's, in order: the A record, then the MX records.
        expect(preg_replace('/\s+/', ' ', $output))->toContain('mx1.tits.guru A 1.2.3.4 tits.guru MX 10 mx1.tits.guru bounce.tx.tits.guru MX 10 mx1.tits.guru reply.tits.guru MX 10 mx1.tits.guru');

        expect(mailInboundApplied($setup['host']))->toMatchArray(['public_smtp' => 'enabled', 'address' => '1.2.3.4']);
        expect(mailInboundServices(mailInboundFile($setup['host'], '/etc/postfix/master.cf')))->toHaveKey('1.2.3.4:25/inet');
        expect((string) file_get_contents($setup['host']['state'].'/ufw-rules'))->toContain('1.2.3.4 25/tcp')->toContain('# rateguru-mail-inbound');
        expect(mailInboundOutboundConfiguration($setup['host']))->toBe($outbound);

        // One Postfix, reloaded, never stopped or restarted.
        $mutations = mailInboundLog($setup['host'], 'mutations.log');
        expect($mutations)->toContain('systemctl reload postfix@-.service')
            ->not->toContain('systemctl restart postfix')->not->toContain('systemctl stop postfix');

        $capsule = json_decode(mailInboundFile($setup['host'], '/var/lib/rateguru-mail-inbound-activation/tits-guru/capsule.json'), true);
        expect($capsule)->toMatchArray(['kind' => 'rateguru-mail-inbound-activation', 'state' => 'activated', 'address' => '1.2.3.4', 'firewall' => 'ufw']);
        expect(file_exists($setup['host']['fs'].'/var/lib/rateguru-mail-gateway/inbound-transition-authorization.json'))->toBeFalse();
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('needs no firewall rule on a host with no host firewall, and opens TCP 25 by the listener alone', function () {
    $setup = mailInboundActivationHost(['ufw' => 'inactive']);

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');
        expect($status)->toBe(0, $output);
        expect($result)->toMatchArray(['state' => 'enabled-verified', 'firewall' => 'none']);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('ufw');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('is a no-op on a host already enabled and verified', function () {
    $setup = mailInboundActivationHost(['installed' => 'enabled', 'ufw' => 'active']);

    try {
        file_put_contents($setup['host']['log'].'/mutations.log', '');
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION: ALREADY ACTIVE — public SMTP listens on 1.2.3.4:25 and verifies; nothing was changed');
        expect($result)->toMatchArray(['status' => 'pass', 'changed' => false, 'state' => 'enabled-verified']);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('enables a host whose inbound mail is already installed and disabled, without installing it again', function () {
    $setup = mailInboundActivationHost(['installed' => 'disabled', 'ufw' => 'active']);

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');
        expect($status)->toBe(0, $output);
        expect($output)->not->toContain('install transition')->toContain('the gateway made the enable transition');
        expect($result)->toMatchArray(['state' => 'enabled-verified']);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
