<?php

/**
 * activate-mail-inbound: the corpus the proof on loopback asks the live
 * inbound listener about — every address answered exactly as `mail-inbound
 * route` judges it, the bare Postmaster included, and the probe removed — in
 * sessions short enough for Postfix's error limits, and only when the
 * listener's own per-client limits leave the proof room to finish.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost); its probes talk to a fake listener that
 * answers from the configuration the real gateway rendered.
 */
it('proves every corpus address against mail-inbound route, the bare Postmaster included, and removes its probe', function () {
    $setup = mailInboundActivationHost();

    try {
        [$status, $output] = mailInboundActivationRun($setup, 'apply');
        expect($status)->toBe(0, $output);

        foreach ([
            'RCPT TO:<support@tits.guru> accepted',
            'RCPT TO:<postmaster@bounce.tx.tits.guru> accepted',
            'RCPT TO:<Postmaster> accepted',
            'RCPT TO:<unknown-mailbox@tits.guru> rejected',
            'RCPT TO:<b-01234567890abcdefghjkmnpqr@bounce.tx.tits.guru> accepted',
            'RCPT TO:<b-i1234567890abcdefghjkmnpqr@bounce.tx.tits.guru> rejected',
            'RCPT TO:<b-01234567890abcdefghjkmnpqr@reply.tits.guru> rejected',
            'RCPT TO:<probe@relay-probe.invalid> rejected',
        ] as $verdict) {
            expect($output)->toContain("PASS {$verdict}, as mail-inbound route judges it");
        }

        expect($output)
            ->toContain('PASS a message to more than 1 recipient(s) is answered with a temporary refusal for the rest')
            ->toContain('delivered by virtual(8) to host/postmaster/ and removed')
            ->toContain('PASS staging-main (127.0.0.1:2525) still takes mail from staging.invalid, as before')
            ->toContain('PASS tits-guru (127.0.0.1:2526) still takes mail from tits.guru, as before');

        expect(glob($setup['host']['fs'].'/var/lib/rateguru-mail-inbound/store/host/postmaster/new/*') ?: [])->toBe([]);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('asks the loopback listener in sessions short enough for the error limit of Postfix, however many addresses the corpus has', function () {
    // A listener that ends a session after five refused recipients: the whole
    // corpus in one session would be cut off; a few addresses a session is not.
    $setup = mailInboundActivationHost();

    try {
        file_put_contents($setup['host']['toggles'].'/hard-error-limit', '5');

        [$status, $output] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(0, $output);
        expect($output)->not->toContain('did not complete the probe')->toContain('ACTIVATION: DONE');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('refuses, before anything changes, a plan whose proof its own limits would cut short', function () {
    $setup = mailInboundActivationHost(['inbound' => mailInboundContractWith(['receiver.limits.message_rate_per_client' => 10])]);

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->not->toBe(0);
        expect($output)
            ->toMatch("/FAIL the proof on loopback needs \\d+ message\\(s\\) in \\d+ session\\(s\\), more than the inbound listener's own limits of 10 messages and 30 sessions a minute allow/")
            ->toContain('the proof before activation failed');
        expect($result)->toMatchArray(['status' => 'fail', 'changed' => false]);
        expect(mailInboundApplied($setup['host']))->toBeNull();
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
