<?php

/**
 * activate-mail-inbound: the proof on loopback before public SMTP opens. Once
 * the gateway has installed inbound mail — the loopback listener and the
 * delivery — the activation asks the live listeners: a corpus of addresses
 * answered exactly as `mail-inbound route` judges them, no AUTH, no ETRN, the
 * reviewed size, the recipient limit, a probe stored in the host postmaster's
 * Maildir; and every submission listener still taking its own sender. A host
 * that does not answer so never gets a public listener.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost); its probes talk to a fake listener that
 * answers from the configuration the real gateway rendered.
 */
it('opens nothing public when the live listeners do not answer as the plan says', function (string $toggle, string $reason) {
    $setup = mailInboundActivationHost(['toggles' => [$toggle]]);

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('inbound mail did not prove itself before public SMTP');
        expect($result)->toMatchArray(['status' => 'fail', 'rolled_back' => false, 'public_smtp' => 'disabled', 'state' => 'installed-disabled']);

        // Installed, still disabled: no public listener, no firewall rule.
        expect(mailInboundApplied($setup['host']))->toMatchArray(['public_smtp' => 'disabled']);
        expect(mailInboundPort25Closed($setup))->toBeTrue();
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('ufw allow');
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'every recipient accepted' => ['accept-all', 'RCPT TO:<unknown-mailbox@tits.guru> answered "250 2.1.5 Ok", but mail-inbound route says reject'],
    'SMTP AUTH offered' => ['offer-auth', 'FAIL the inbound listener offers SMTP AUTH'],
    'ETRN offered' => ['offer-etrn', 'FAIL the inbound listener offers ETRN'],
    'no size announced' => ['no-size', 'FAIL the inbound listener does not announce SIZE 10485760'],
    'accepted mail never stored' => ['no-store', 'did not reach host/postmaster/ within 2s — accepted mail is not reaching the store'],
    'a submission listener no longer takes its sender' => ['outbound-refused', 'FAIL tits-guru (127.0.0.1:2526) does not take mail from tits.guru'],
]);

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
