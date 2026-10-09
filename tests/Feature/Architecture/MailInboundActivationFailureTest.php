<?php

/**
 * activate-mail-inbound: a failure after TCP 25 opened returns the receiver to
 * disabled, and CRITICAL when even that cannot be proved.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// FAILURE AFTER PUBLIC SMTP OPENED: BACK TO DISABLED
// =============================================================================

it('returns the receiver to disabled, and closes its port, when anything fails after TCP 25 opened', function (string $toggle, string $content, string $reason) {
    $setup = mailInboundActivationHost(['ufw' => 'active']);

    try {
        file_put_contents($setup['host']['scratch'].'/toggles/'.$toggle, $content);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(1, $output);
        expect($output)->toContain($reason)->toContain('ROLLED BACK: public SMTP is closed, nothing listens on 25, the receiver is disabled; the activation failed');
        expect($result)->toMatchArray(['status' => 'fail', 'rolled_back' => true, 'public_smtp' => 'disabled']);

        expect(mailInboundApplied($setup['host'])['public_smtp'])->toBe('disabled');
        expect(mailInboundPort25Closed($setup))->toBeTrue();
        expect((string) file_get_contents($setup['host']['state'].'/ufw-rules'))->not->toContain('rateguru-mail-inbound');
        expect(file_exists($setup['host']['fs'].'/var/lib/rateguru-mail-inbound/transition-authorization.json'))->toBeFalse();
        expect(json_decode((string) file_get_contents($setup['host']['fs'].'/var/lib/rateguru-mail-inbound-activation/tits-guru/capsule.json'), true)['state'])->toBe('rolled-back');
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    // The gateway's own verify is asked once before public SMTP opens; the
    // second time is after it opened.
    'the gateway fails its verify once public SMTP is open' => ['gateway-verify-fail-at', '2', 'FAIL the outbound mail gateway does not verify'],
    'the receiver does not reload with its public listener' => ['reload-fail', '', 'install-mail-inbound --apply refused or failed to enable public SMTP'],
]);

it('reports CRITICAL, and tries nothing broader, when it cannot prove the return to disabled', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active']);

    try {
        // The gateway stops verifying after public SMTP opened, and never comes
        // back: the return to disabled cannot be proved.
        file_put_contents($setup['host']['scratch'].'/toggles/gateway-verify-fail-after', '2');
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(2, $output);
        expect($output)->toContain('CRITICAL: the activation of public SMTP failed AND its return to disabled could not be proved. Nothing broader was attempted.');
        expect($result)->toMatchArray(['status' => 'critical']);
        // What could be closed was closed.
        expect(mailInboundApplied($setup['host'])['public_smtp'])->toBe('disabled');
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('postfix@-')->not->toContain('apt-get');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
