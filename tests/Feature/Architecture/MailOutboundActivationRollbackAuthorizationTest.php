<?php

use Illuminate\Support\Facades\File;

/*
 * activate-mail-outbound and the way back across the boundary: Prepare never
 * crosses it again after the initial-launch rollback, and a rollback carries
 * its own authorization only when the gateway recorded the outbound policy.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

// =============================================================================
// THE ONE TRANSITION
// =============================================================================

it('leaves an ordinary Prepare unable to cross the boundary again after the initial-launch rollback', function () {
    $host = mailActivationHost();

    try {
        [$activated] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        expect($activated)->toBe(0);
        [$rolledBack] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);
        expect($rolledBack)->toBe(0);
        expect(mailActivationInstalledMode($host))->toBe('held');

        // main still requests outbound; Prepare applies the gateway from it.
        [$status, $output] = mailActivationRun($host, ['--apply'], script: 'install-mail-gateway');
        expect($status)->toBe(1, $output);
        expect($output)->toContain("this bundle moves tits-guru's mail from held to outbound — the activation boundary, which only activate-mail-outbound crosses");
        expect(mailActivationInstalledMode($host))->toBe('held');

        // Only another guarded activation crosses it again.
        [$again, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        expect($again)->toBe(0, $output);
        expect(mailActivationInstalledMode($host))->toBe('outbound');
        expect(substr_count(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'), ' activate tits-guru '))->toBe(2);
    } finally {
        mailActivationCleanup($host);
    }
});

it('crosses back with its own rollback authorization only when the gateway recorded the outbound policy, and leaves none behind', function (string $toggle, bool $recordedOutbound) {
    $host = mailActivationHost(['toggles' => [$toggle]]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect(mailActivationResult($output))->toMatchArray(['rolled_back' => true]);
        expect(mailActivationInstalledMode($host))->toBe('held');

        $ledger = (string) @file_get_contents($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations');
        if ($recordedOutbound) {
            // The activation was consumed, then the rollback.
            expect($ledger)->toContain(' activate tits-guru ')->toContain(' rollback tits-guru ');
            expect($output)->toContain('authorized the rollback of tits-guru');
        } else {
            // The gateway never ran: nothing was consumed, nothing needed
            // authorizing on the way back, and the unused activation was withdrawn.
            expect($ledger)->toBe('');
            expect($output)->not->toContain('authorized the rollback')->toContain('withdrew the unused transition-authorization.json');
        }

        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'the installer refused' => ['gateway-apply-fails-outbound', false],
    'the installer failed after installing' => ['gateway-apply-breaks-outbound', true],
    'readiness is not YES' => ['readonly-signing-fails', true],
]);
