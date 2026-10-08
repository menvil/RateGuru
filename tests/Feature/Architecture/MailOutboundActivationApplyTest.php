<?php

use Illuminate\Support\Facades\File;

/*
 * activate-mail-outbound --apply and --verify: the requested gateway activated
 * behind the whole proof, with a capsule to return to; a second run that
 * changes nothing; and a target verified as not ready when anything it depends
 * on fails.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

/** What the simulated host's postconf reads back: `-P SPEC`, or `-M`. */
function mailActivationPostconf(array $host, string ...$arguments): string
{
    $process = proc_open([$host['scratch'].'/bin/postconf', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $host['scratch'], $host['env']);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}

// =============================================================================
// APPLY
// =============================================================================

it('activates exactly the requested gateway behind the whole proof, with a capsule to return to', function () {
    $host = mailActivationHost();

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'pass', 'requested' => true, 'changed' => true, 'rolled_back' => false, 'outbound_ready' => true]);
        expect($output)
            ->toContain('PASS the installed gateway is exactly the pre-activation render')
            ->toContain("PASS tits-guru's listener holds its mail, no direct route exists, nothing listens on a public SMTP port")
            ->toContain('PASS public DNS — A, PTR, SPF, DKIM and DMARC — and the key verify for tits-guru')
            ->toContain('PASS the signing acceptance passed: a foreign From refused before it was queued, a valid probe signed, held and removed')
            ->toContain('PASS the HOLD queue is empty')
            ->toContain('OUTBOUND READY: YES')
            ->toContain('PASS mail-identity readiness: every condition PASS — OUTBOUND READY: YES')
            ->toContain("PASS tits-guru's listener alone owns its direct route, capture listeners still capture, nothing listens on a public SMTP port")
            ->toContain('ACTIVATION: DONE');

        // The proof from the pre-activation bundle, then the one change from
        // the trusted bundle, then its own proof.
        $calls = mailActivationCalls($host);
        expect($calls)->toBe([
            'install-mail-gateway --verify [outbound]',
            'install-mail-gateway --verify [held]',
            'install-mail-signing --verify --target tits-guru',
            'verify-mail-signing --e2e --target tits-guru [held]',
            'install-mail-gateway --verify [held]',
            // The one-use authorization, bound to the digests the gateway
            // itself reports, then the one change it permits.
            'install-mail-gateway --policy-digest [outbound]',
            'install-mail-gateway --apply [outbound]',
            'install-mail-gateway --verify [outbound]',
            'verify-mail-signing --read-only --target tits-guru [outbound]',
            'install-mail-gateway --verify [outbound]',
            'install-mail-signing --verify --target tits-guru',
        ]);

        // The gateway consumed it: no authorization is left, and the ledger has it.
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'))->toMatch('/^[0-9a-f]{64} [0-9a-f]{32} activate tits-guru /');

        // The host: tits-guru delivers through its own direct transport, and
        // staging still captures.
        expect(mailActivationInstalledMode($host))->toBe('outbound');
        expect(mailActivationPostconf($host, '-P', '127.0.0.1:2526/inet/content_filter'))->toBe("127.0.0.1:2526/inet/content_filter = rateguru-outbound-tits-guru:\n");
        expect(mailActivationPostconf($host, '-P', '127.0.0.1:2525/inet/content_filter'))->toBe("127.0.0.1:2525/inet/content_filter = rateguru-capture-staging-main:[127.0.0.1]:1025\n");
        expect(collect(explode("\n", trim(mailActivationPostconf($host, '-M'))))->filter(fn (string $line): bool => str_ends_with($line, ' smtp'))->map(fn (string $line): string => strtok($line, ' '))->values()->all())
            ->toBe(['rateguru-capture-staging-main', 'rateguru-outbound-tits-guru']);

        // The capsule: root-only, the two pre-activation documents — exactly
        // the committed ones — and a non-secret record.
        $capsule = mailActivationCapsule($host);
        expect(fileperms($capsule) & 0o777)->toBe(0o700);
        expect(collect(File::files($capsule))->map->getFilename()->sort()->values()->all())->toBe(['capsule.json', 'mail-outbound.json', 'mail-routing.json']);
        foreach (File::files($capsule) as $file) {
            expect(fileperms($file->getPathname()) & 0o777)->toBe(0o600);
            expectNoKeyMaterial(File::get($file->getPathname()), $host['key']);
        }
        expect(json_decode(File::get($capsule.'/mail-routing.json'), true))->toBe(mailPreActivationPolicy()['routing']);
        expect(json_decode(File::get($capsule.'/mail-outbound.json'), true))->toBe(mailPreActivationPolicy()['outbound']);

        $record = json_decode(File::get($capsule.'/capsule.json'), true);
        expect(array_keys($record))->toBe(['kind', 'schema_version', 'target', 'state', 'recorded_at', 'pre_activation', 'requested']);
        expect($record)->toMatchArray(['kind' => 'rateguru-mail-outbound-activation', 'schema_version' => 1, 'target' => 'tits-guru', 'state' => 'activated']);
        expect($record['pre_activation']['mail-routing.json'])->toBe(hash_file('sha256', $capsule.'/mail-routing.json'));
        expect($record['requested']['mail-outbound.json'])->toBe(hash_file('sha256', $host['bundle'].'/infrastructure/config/mail-outbound.json'));

        // No key in the output, no temporary bundle left behind, the trusted
        // documents untouched, the queue untouched.
        expectNoKeyMaterial($output, $host['key']);
        expect(glob($host['scratch'].'/tmp/*'))->toBe([]);
        expect(json_decode(File::get($host['bundle'].'/infrastructure/config/mail-routing.json'), true))->toBe(mailActivationRequest()['routing']);
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");
    } finally {
        mailActivationCleanup($host);
    }
});

it('is an idempotent no-op once the host is activated and ready, and verifies it read-only', function () {
    $host = mailActivationHost();

    try {
        mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        @unlink($host['host'].'/calls.log');
        $capsule = File::get(mailActivationCapsule($host).'/capsule.json');

        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION: ALREADY ACTIVE');
        expect(mailActivationResult($output))->toMatchArray(['status' => 'pass', 'changed' => false, 'rolled_back' => false, 'outbound_ready' => true]);
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e')))->toBe([]);
        expect(File::get(mailActivationCapsule($host).'/capsule.json'))->toBe($capsule);

        [$status, $output] = mailActivationRun($host, ['--verify', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('OUTBOUND READY: YES');
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'verify', 'status' => 'pass', 'requested' => true, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => true]);
    } finally {
        mailActivationCleanup($host);
    }
});

it('verifies an activated target as not ready when anything it depends on fails', function () {
    $host = mailActivationHost(['installed' => 'outbound', 'toggles' => ['readonly-signing-fails']]);

    try {
        [$status, $output] = mailActivationRun($host, ['--verify', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain('FAIL mail-identity readiness is not YES')->toContain('OUTBOUND READY: NO');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'verify', 'status' => 'fail', 'outbound_ready' => false]);
    } finally {
        mailActivationCleanup($host);
    }
});
