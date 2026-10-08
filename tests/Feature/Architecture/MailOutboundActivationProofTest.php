<?php

use Illuminate\Support\Facades\File;

/*
 * activate-mail-outbound's proof before it changes anything: every part of it
 * must hold, and --check proves all of it while submitting nothing.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

// =============================================================================
// THE PRE-ACTIVATION PROOF: NOTHING CHANGES UNTIL ALL OF IT HOLDS
// =============================================================================

it('changes nothing when any part of the pre-activation proof fails', function (string $case, string $problem, bool $probed) {
    $options = match ($case) {
        'bad DNS', 'missing key', 'weak key' => [],
        'signer down' => ['toggles' => ['signer-down']],
        'foreign From accepted' => ['toggles' => ['e2e-foreign-accepted']],
        'acceptance exited non-zero' => ['toggles' => ['e2e-exits-nonzero']],
        'held mail' => ['queue' => ["0123ABCDEF\thold\tsomeone@example.net", "FOREIGN0001\tdeferred\tsomeone@example.net"]],
        'public SMTP' => [],
        'gateway not held' => ['installed' => 'drifted', 'listeners' => ['127.0.0.1:1025', '127.0.0.1:1026']],
    };

    if (($options['installed'] ?? null) === 'drifted') {
        // Held, but not the pre-activation state: staging captures elsewhere.
        $drifted = mailPreActivationPolicy();
        $drifted['routing']['targets']['staging-main']['capture']['port'] = 1026;
        $options['installed'] = $drifted;
    }

    $host = mailActivationHost($options);

    try {
        if ($case === 'bad DNS') {
            mailIdentityDnsHost($host['scratch'], [
                ...mailIdentityGoodDns(mailIdentityPublicKey($host['key'])),
                'TXT _dmarc.tits.guru' => ['"v=DMARC1; p=none"'],
            ]);
        }

        if ($case === 'public SMTP') {
            file_put_contents($host['state'].'/listeners', "0.0.0.0:25\n", FILE_APPEND);
        }

        if ($case === 'missing key') {
            unlink($host['key']);
        }

        if ($case === 'weak key') {
            copy(mailIdentityKey('rsa1024'), $host['key']);
        }

        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('nothing was changed');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'apply', 'status' => 'fail', 'requested' => true, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => false]);

        $calls = mailActivationCalls($host);
        expect(array_filter($calls, static fn (string $call): bool => str_contains($call, '--apply')))->toBe([]);
        expect(in_array('verify-mail-signing --e2e --target tits-guru [held]', $calls, true))->toBe($probed);
        expect(is_dir(mailActivationCapsule($host)))->toBeFalse();

        // Held mail is named by its queue ID alone, and never touched.
        if ($case === 'held mail') {
            expect($output)->not->toContain('someone@example.net');
            expect(File::get($host['state'].'/queue'))->toContain("0123ABCDEF\thold");
            expect(File::get($host['log'].'/queue.log'))->toBe("postqueue -j\n");
        }
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'bad DNS' => ['bad DNS', 'FAIL public DNS or the key does not verify for tits-guru', false],
    'signer down' => ['signer down', 'FAIL the signer is not verified for tits-guru', false],
    'missing key' => ['missing key', '/etc/opendkim/keys/tits-guru/rg1.private is not usable: it does not exist', false],
    'weak key' => ['weak key', 'below the reviewed minimum of 2048 bits', false],
    'foreign From accepted' => ['foreign From accepted', 'FAIL the signing acceptance did not pass', true],
    // A passing result line is not enough: the acceptance's exit status must agree.
    'acceptance exited non-zero' => ['acceptance exited non-zero', 'FAIL the signing acceptance did not pass (exit 1)', true],
    'held mail' => ['held mail', 'FAIL the HOLD queue is not empty (0123ABCDEF)', true],
    'public SMTP' => ['public SMTP', 'something listens on 0.0.0.0:25 — no SMTP service may listen on port 25', false],
    'gateway not held' => ['gateway not held', 'FAIL the installed gateway is not exactly the pre-activation render', false],
]);

it('proves everything --apply would and submits nothing in --check', function () {
    $host = mailActivationHost();

    try {
        [$status, $output] = mailActivationRun($host, ['--check', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION CHECK: READY');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'check', 'status' => 'pass', 'changed' => false]);
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e')))->toBe([]);
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect(is_dir(mailActivationCapsule($host)))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
});
