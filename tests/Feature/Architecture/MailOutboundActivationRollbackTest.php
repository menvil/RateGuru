<?php

use Illuminate\Support\Facades\File;

/*
 * activate-mail-outbound's rollback: an activated target returned to held from
 * its own capsule, refused without a current, untampered one, and CRITICAL when
 * it cannot be proved.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

// =============================================================================
// THE INITIAL-LAUNCH ROLLBACK
// =============================================================================

it('returns an activated planned target to held from its capsule, and leaves the committed configuration alone', function () {
    $host = mailActivationHost();

    try {
        mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        @unlink($host['host'].'/calls.log');
        $documents = [
            File::get($host['bundle'].'/infrastructure/config/mail-routing.json'),
            File::get($host['bundle'].'/infrastructure/config/mail-outbound.json'),
        ];

        [$status, $output] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain("RUNTIME HELD AGAIN: tits-guru's listener holds its mail, no direct route exists, and the signing acceptance passed")
            ->toContain('The committed configuration STILL requests outbound delivery for tits-guru: revert the activation change');
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'rollback', 'status' => 'pass', 'requested' => true, 'changed' => true, 'rolled_back' => true, 'outbound_ready' => false]);

        expect(mailActivationCalls($host))->toBe([
            // The gateway records outbound, so the way back needs its own
            // one-use authorization.
            'install-mail-gateway --policy-digest [held]',
            'install-mail-gateway --policy-digest [held]',
            'install-mail-gateway --apply [held]',
            'install-mail-gateway --verify [held]',
            'verify-mail-signing --e2e --target tits-guru [held]',
            'install-mail-gateway --verify [held]',
        ]);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'))->toContain(' rollback tits-guru ');
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect([
            File::get($host['bundle'].'/infrastructure/config/mail-routing.json'),
            File::get($host['bundle'].'/infrastructure/config/mail-outbound.json'),
        ])->toBe($documents);
        expect(json_decode(File::get(mailActivationCapsule($host).'/capsule.json'), true)['state'])->toBe('rolled-back');
    } finally {
        mailActivationCleanup($host);
    }
});

it('refuses a rollback without its own, current, untampered capsule', function (string $case, string $problem) {
    $host = mailActivationHost();

    try {
        if ($case !== 'no capsule') {
            mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        }

        $capsule = mailActivationCapsule($host);

        match ($case) {
            'no capsule' => null,
            'a foreign file' => file_put_contents($capsule.'/notes.txt', "mine\n"),
            'another kind' => file_put_contents($capsule.'/capsule.json', json_encode(['kind' => 'something-else', 'target' => 'tits-guru'])),
            'a tampered document' => file_put_contents($capsule.'/mail-outbound.json', File::get($capsule.'/mail-outbound.json').' '),
            'a changed request' => (function () use ($host): void {
                $request = mailActivationRequest();
                $request['routing']['targets']['staging-main']['capture']['port'] = 1026;
                file_put_contents($host['bundle'].'/infrastructure/config/mail-routing.json', mailRoutingJson($request['routing']));
            })(),
        };

        @unlink($host['host'].'/calls.log');
        $before = is_dir($capsule) ? collect(File::files($capsule))->mapWithKeys(fn ($file) => [$file->getFilename() => File::get($file->getPathname())])->all() : [];

        [$status, $output] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('the rollback capsule cannot be used — nothing was changed');
        expect(mailActivationCalls($host))->toBe([]);
        expect(is_dir($capsule) ? collect(File::files($capsule))->mapWithKeys(fn ($file) => [$file->getFilename() => File::get($file->getPathname())])->all() : [])->toBe($before);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'no capsule' => ['no capsule', 'there is no rollback capsule for tits-guru'],
    'a foreign file' => ['a foreign file', 'is not an activation capsule of tits-guru — it is never used or removed'],
    'another kind' => ['another kind', 'is not an activation capsule of tits-guru'],
    'a tampered document' => ['a tampered document', "the capsule's pre-activation mail-outbound.json is not the one it recorded"],
    'a changed request' => ['a changed request', 'the configuration changed since the activation, so this capsule is stale'],
]);

it('reports CRITICAL when an explicit rollback cannot be proved', function () {
    $host = mailActivationHost();

    try {
        mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        touch($host['host'].'/toggles/gateway-apply-fails-held');

        [$status, $output] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);

        expect($status)->toBe(2, $output);
        expect($output)->toContain('CRITICAL: the rollback of tits-guru could not be proved. Nothing broader was attempted.');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'rollback', 'status' => 'critical', 'rolled_back' => false]);
    } finally {
        mailActivationCleanup($host);
    }
});
