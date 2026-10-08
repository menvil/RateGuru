<?php

use Illuminate\Support\Facades\File;

/*
 * activate-mail-outbound when something fails once the gateway started
 * changing: the host returned to held, CRITICAL when that cannot be proved, and
 * queued mail and keys never touched.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

// =============================================================================
// FAILURE AFTER THE GATEWAY CHANGED: BACK TO HELD
// =============================================================================

it('returns the host to held on any failure once the gateway started changing', function (string $toggle, string $problem) {
    $host = mailActivationHost(['toggles' => [$toggle]]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain($problem)
            ->toContain('returning tits-guru to its pre-activation state from the rollback capsule')
            ->toContain("ROLLED BACK: tits-guru's listener holds its mail again and no direct route exists");
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'fail', 'requested' => true, 'changed' => true, 'rolled_back' => true, 'outbound_ready' => false]);

        // The pre-activation gateway re-applied through its own installer, then
        // proved: its verify, and the signing acceptance on the held listener.
        $calls = mailActivationCalls($host);
        $applies = array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, '--apply')));
        expect($applies)->toBe(['install-mail-gateway --apply [outbound]', 'install-mail-gateway --apply [held]']);
        expect(array_slice($calls, array_search('install-mail-gateway --apply [held]', $calls, true)))->toBe([
            'install-mail-gateway --apply [held]',
            'install-mail-gateway --verify [held]',
            'verify-mail-signing --e2e --target tits-guru [held]',
            'install-mail-gateway --verify [held]',
        ]);
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');

        // The queue: read, never changed — the unrelated entry still there.
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");
        expect(array_unique(array_filter(explode("\n", File::get($host['log'].'/queue.log')))))->toBe(['postqueue -j']);

        expect(json_decode(File::get(mailActivationCapsule($host).'/capsule.json'), true)['state'])->toBe('rolled-back');
        expect(glob($host['scratch'].'/tmp/*'))->toBe([]);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'the installer refused' => ['gateway-apply-fails-outbound', 'ERROR: install-mail-gateway --apply refused or failed'],
    'the installer failed half-way' => ['gateway-apply-breaks-outbound', 'ERROR: install-mail-gateway --apply refused or failed'],
    'the gateway does not verify' => ['gateway-verify-fails-outbound', 'FAIL the installed gateway is not exactly the requested render'],
    'readiness is not YES' => ['readonly-signing-fails', 'FAIL mail-identity readiness is not YES (exit 1; not PASS: signing)'],
    'a route leaked to staging' => ['leak-direct-route', 'staging-main (127.0.0.1:2525) routes to a direct delivery transport (rateguru-outbound-tits-guru:) — only tits-guru may'],
]);

it('reports CRITICAL, and attempts nothing broader, when the return to held cannot be proved', function () {
    $host = mailActivationHost(['toggles' => ['gateway-verify-fails-outbound', 'gateway-apply-fails-held']]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(2, $output);
        expect($output)
            ->toContain('CRITICAL: the activation of tits-guru failed AND its return to held could not be proved. Nothing broader was attempted.')
            ->toContain('the signing acceptance is not run: the gateway is not proved to hold tits-guru\'s mail');
        expect(mailActivationResult($output))->toMatchArray(['status' => 'critical', 'changed' => true, 'rolled_back' => false, 'outbound_ready' => false]);

        // One attempt at the pre-activation gateway, and no probe into a
        // gateway that is not proved to hold.
        $calls = mailActivationCalls($host);
        expect(array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, '--apply'))))
            ->toBe(['install-mail-gateway --apply [outbound]', 'install-mail-gateway --apply [held]']);
        expect(count(array_filter($calls, static fn (string $call): bool => str_contains($call, '--e2e'))))->toBe(1);
    } finally {
        mailActivationCleanup($host);
    }
});

it('never flushes, releases, requeues or deletes queued mail, and never reads a key', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/activate-mail-outbound')));

    foreach (['postsuper', 'POSTSUPER', 'postqueue -f', '" -f', 'postcat', ' -H ', 'sendmail', '/dev/tcp', 'opendkim/keys', '.private"', 'curl', 'wget', 'eval '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("activate-mail-outbound uses {$forbidden}");
    }

    // The only queue access is the shared library's read of what is held.
    expect($code)->toContain('source "${SCRIPT_DIR}/smtp-submission"');
    expect(substr_count($code, 'queue_ids_in'))->toBe(1);
    expect($code)->toContain('queue_ids_in hold')->not->toContain('queue_state')->not->toContain('queue_ids_for')->not->toContain('smtp_submit');
});
