<?php

use Illuminate\Support\Facades\File;

/**
 * activate-mail-inbound --verify: which state the host is in, and a host
 * enabled while its bundle no longer requests it.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// VERIFY: WHICH STATE, AND WHETHER IT IS RIGHT
// =============================================================================

it('tells the states apart: not installed, installed and disabled, enabled and verified', function () {
    $setup = mailInboundActivationHost(['ufw' => 'active']);

    try {
        [$status, , $result] = mailInboundActivationRun($setup, 'verify');
        expect($status)->toBe(0);
        expect($result)->toMatchArray(['mode' => 'verify', 'status' => 'pass', 'state' => 'not-installed']);

        [$installed, $output] = mailInboundRunInstaller($setup['host'], '--apply', ['RATEGURU_MAILINBOUND_INBOUND_FILE' => $setup['bundle'].'/infrastructure/config/mail-inbound.json']);
        expect($installed)->toBe(0, $output);
        [$status, , $result] = mailInboundActivationRun($setup, 'verify');
        expect($status)->toBe(0);
        expect($result)->toMatchArray(['state' => 'installed-disabled', 'public_smtp' => 'disabled']);

        mailInboundActivated($setup);
        $before = mailInboundTree($setup['host']);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');
        expect($status)->toBe(0, $output);
        expect($result)->toMatchArray(['state' => 'enabled-verified', 'public_smtp' => 'enabled', 'address' => '1.2.3.4', 'firewall' => 'ufw']);
        expect($output)->toContain('PASS the outbound mail gateway verifies');
        expect(mailInboundTree($setup['host']))->toBe($before);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('reports drift when the bundle no longer requests what the host has open', function () {
    $setup = mailInboundActivationHost();

    try {
        mailInboundActivated($setup);
        $contract = json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true);
        $contract['receiver']['public_smtp'] = 'disabled';
        file_put_contents($setup['bundle'].'/infrastructure/config/mail-inbound.json', mailRoutingJson($contract));

        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');

        expect($status)->not->toBe(0);
        expect($output)->toContain('the trusted bundle does not request it — run the inbound rollback, or restore the request');
        expect($result)->toMatchArray(['state' => 'drift', 'public_smtp' => 'enabled']);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
