<?php

/**
 * activate-mail-inbound: the live proof on the receiver's loopback listener.
 * A receiver that does not answer as the plan says never gets a public port.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// THE LIVE PROOF REFUSES A RECEIVER THAT DOES NOT ANSWER AS THE PLAN SAYS
// =============================================================================

it('refuses to open public SMTP when the live receiver does not answer as the plan says', function (string $toggle, string $reason) {
    $setup = mailInboundActivationHost();

    try {
        touch($setup['host']['scratch'].'/toggles/'.$toggle);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('the receiver did not prove itself before public SMTP');
        expect($result)->toMatchArray(['status' => 'fail', 'rolled_back' => false]);
        expect(mailInboundApplied($setup['host'])['public_smtp'])->toBe('disabled');
        expect(mailInboundPort25Closed($setup))->toBeTrue();
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'a receiver that accepts everything' => ['accept-all', 'but mail-inbound route says reject'],
    'SMTP AUTH offered' => ['offer-auth', 'FAIL the receiver offers SMTP AUTH'],
    'no message size' => ['no-size', 'FAIL the receiver does not announce SIZE 10485760'],
    'accepted mail not stored' => ['no-store', 'accepted mail is not being stored'],
]);
