<?php

/**
 * activate-mail-inbound: a failure after TCP 25 opened returns public SMTP to
 * disabled — the disable transition, by a reload, and RateGuru's firewall rule
 * removed — and proves it; CRITICAL when even that cannot be proved, with
 * nothing broader attempted. Postfix is never stopped.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost); its probes talk to a fake listener that
 * answers from the configuration the real gateway rendered.
 */
it('returns public SMTP to disabled, and closes its port, when anything fails after TCP 25 opened', function (string $toggle, string $reason, bool $reloads) {
    // Inbound mail installed and disabled: the first reload is the enable one.
    $setup = mailInboundActivationHost(['installed' => 'disabled', 'ufw' => 'active']);

    try {
        $outbound = mailInboundOutboundConfiguration($setup['host']);
        touch($setup['host']['toggles'].'/'.$toggle);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(1, $output);
        expect($output)->toContain($reason)->toContain('ROLLED BACK: public SMTP is closed, nothing listens on 25, inbound mail is disabled; the activation failed');
        expect($result)->toMatchArray(['status' => 'fail', 'rolled_back' => true, 'public_smtp' => 'disabled']);

        expect(mailInboundApplied($setup['host'])['public_smtp'])->toBe('disabled');
        expect(mailInboundPort25Closed($setup))->toBeTrue();
        expect((string) file_get_contents($setup['host']['state'].'/ufw-rules'))->not->toContain('rateguru-mail-inbound');
        expect(file_exists($setup['host']['fs'].'/var/lib/rateguru-mail-gateway/inbound-transition-authorization.json'))->toBeFalse();
        expect(json_decode(mailInboundFile($setup['host'], '/var/lib/rateguru-mail-inbound-activation/tits-guru/capsule.json'), true)['state'])->toBe('rolled-back');

        // The outbound path never moved, and Postfix runs. It takes every
        // change by a reload; only a Postfix that refuses to reload at all is
        // restarted, by the gateway's own restore, as the last way back.
        expect(mailInboundOutboundConfiguration($setup['host']))->toBe($outbound);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('systemctl stop postfix');
        if ($reloads) {
            expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('systemctl restart postfix');
        }
        expect(file_exists($setup['host']['state'].'/postfix@-.service.active'))->toBeTrue();
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'the public listener offers no STARTTLS' => ['no-starttls', 'FAIL the public listener does not offer STARTTLS', true],
    'Postfix will not reload at all' => ['reload-fail', 'FAIL install-mail-gateway --apply refused or failed the enable transition', false],
]);

it('reports CRITICAL, and tries nothing broader, when it cannot prove the return to disabled', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active', 'toggles' => ['no-starttls', 'ufw-delete-fail']]);

    try {
        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->toBe(2, $output);
        expect($output)->toContain('CRITICAL: the activation of public SMTP failed AND its return to disabled could not be proved. Nothing broader was attempted: Postfix was not stopped and its queue was not touched.');
        expect($result)->toMatchArray(['status' => 'critical']);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('systemctl stop')->not->toContain('postsuper');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
