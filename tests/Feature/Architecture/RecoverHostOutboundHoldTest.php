<?php

use Illuminate\Support\Facades\File;

/**
 * Recover Host and the recovery outbound hold: a target whose reviewed mail
 * goes out directly is recovered only onto a machine whose preparation held
 * that mail, the hold is bound to the one recovery operation that restores
 * onto the machine, every later stage proves it, and nothing in a recovery
 * releases it.
 *
 * The real recover-host runs against the same simulated replacement host as
 * RecoverHostTest. Whether the target's mail goes out directly is the reviewed
 * routing's answer, which this file gives through a routing CLI that renders
 * parity-target's mail outbound; RecoverHostTest's own targets capture theirs,
 * and need no hold at all.
 */

/** The routing CLI that says parity-target's reviewed mail goes out directly. */
function recoverHostOutboundRouting(string $scratch): array
{
    $stub = $scratch.'/bin/mail-routing-outbound';
    @mkdir(dirname($stub), 0o755, true);
    file_put_contents($stub, <<<'STUB'
        #!/bin/bash
        [[ "${1:-}" == render-plan ]] || exit 64
        printf '{"schema_version":2,"listeners":[{"identity":"parity-target","delivery_mode":"outbound","environment_class":"production","lifecycle":"active","listen":{"host":"127.0.0.1","port":2526},"route":{"kind":"direct"},"sender":{"allowed_domain":"parity-target.example"}}]}\n'
        STUB."\n");
    chmod($stub, 0o755);

    return ['RATEGURU_RECOVER_MAIL_ROUTING_CLI' => $stub];
}

/** The parity-target hold on the simulated machine, as its preparation placed it. */
function recoverHostPlaceOutboundHold(string $scratch, array $changes = []): string
{
    return recoveryOutboundHoldWrite($scratch, 'parity-target', ['backup' => '20260115-023000', ...$changes]);
}

it('binds the hold its preparation placed to the recovery operation before it downloads anything, and reports it', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        recoverHostPlaceOutboundHold($scratch);

        $result = recoveryApply($scratch, recoverHostOutboundRouting($scratch));
        expect($result['exit'])->toBe(0, $result['output']);
        $operation = recoveryOperationIdIn($result['output']);

        expect(recoveryOutboundHoldRead($scratch, 'parity-target'))->toMatchArray([
            'state' => 'held',
            'backup' => '20260115-023000',
            'operation' => $operation,
        ]);

        // After the offsite writers are held, before a byte is downloaded.
        $offsite = mb_strpos($result['output'], 'step: hold offsite writes');
        $bind = mb_strpos($result['output'], 'step: bind the recovery outbound hold');
        $stage = mb_strpos($result['output'], 'step: stage backup');
        expect($offsite)->toBeLessThan($bind);
        expect($bind)->toBeLessThan($stage);

        expect($result['output'])->toContain("OUTBOUND MAIL: HELD (the recovery outbound hold, bound to operation {$operation}: no direct delivery until release-recovered-outbound releases it)");
        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray(['status' => 'awaiting-code', 'outbound_mail' => 'held']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to recover onto a machine that does not hold the target\'s outbound mail for this recovery, before staging any data', function (?array $hold, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        if ($hold !== null) {
            recoverHostPlaceOutboundHold($scratch, $hold);
        }

        $before = recoveryOutboundHoldRead($scratch, 'parity-target');

        $result = recoveryApply($scratch, recoverHostOutboundRouting($scratch));

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain($expected)
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('No data was staged or activated')
            ->not->toContain('step: stage backup');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(recoveryOutboundHoldRead($scratch, 'parity-target'))->toBe($before);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'no hold' => [null, 'its preparation for this recovery never placed the recovery outbound hold'],
    'a hold for another backup' => [['backup' => '20260114-023000'], 'was placed for backup 20260114-023000, not 20260115-023000'],
    'a hold another recovery owns' => [['operation' => '20260115-031500-c0ffee'], 'is bound to recovery operation 20260115-031500-c0ffee'],
    'a hold already released' => [['operation' => '20260115-031500-c0ffee', 'state' => 'released', 'released_at' => '2026-01-16T10:00:00Z', 'released_address' => '203.0.113.10'], 'was already released on this machine'],
    'a hold it cannot read' => [['state' => 'open'], 'is not a hold this machine can trust'],
]);

it('takes its binding back when it fails and leaves the machine as its preparation left it, so the same recovery can start again', function () {
    $scratch = restoreScratchDir();

    try {
        // Refused after the download — a schema 2 backup — with nothing
        // staged: the machine is prepared and EMPTY again.
        recoveryFixture($scratch, ['backup_options' => ['schema' => 2]]);
        recoverHostPlaceOutboundHold($scratch);

        $result = recoveryApply($scratch, recoverHostOutboundRouting($scratch));

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('step: bind the recovery outbound hold')
            ->toContain('is not clean-host-recovery-capable')
            ->toContain('outbound mail: still HELD — the hold\'s binding to the failed operation');
        expect(recoveryGuard($scratch))->toBeNull();
        expect(recoveryOutboundHoldRead($scratch, 'parity-target'))->toMatchArray(['state' => 'held', 'operation' => null]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the hold through the controlled deployment and the resume, refuses one that is not this operation\'s, and puts back a missing one', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        recoverHostPlaceOutboundHold($scratch);
        $routing = recoverHostOutboundRouting($scratch);

        $applied = recoveryApply($scratch, $routing);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);
        $bound = File::get(recoveryOutboundHoldDir($scratch).'/parity-target.json');

        $inspected = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation], $routing);
        expect($inspected['exit'])->toBe(0, $inspected['output']);
        expect($inspected['output'])->toContain("OUTBOUND MAIL: HELD (the recovery outbound hold, bound to operation {$operation}");

        deployRecoveredRelease($scratch);

        // Another recovery's hold: refused before a single service starts.
        recoverHostPlaceOutboundHold($scratch, ['operation' => '20260115-031500-c0ffee']);
        foreach (['--inspect', '--resume'] as $mode) {
            $refused = recoverHostRun($scratch, [$mode, '--target', 'parity-target', '--operation', $operation], $routing);
            expect($refused['exit'])->not->toBe(0, $refused['output']);
            expect($refused['output'])->toContain("parity-target's outbound mail is not held for recovery operation {$operation}: the recovery outbound hold on this machine is bound to recovery operation 20260115-031500-c0ffee");
        }
        expect($refused['output'])->not->toContain('step: start the target queue');
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'awaiting-code']);

        // A missing one is put back for this operation, and the recovery
        // completes with its outbound mail still held.
        File::delete(recoveryOutboundHoldDir($scratch).'/parity-target.json');
        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation], $routing);
        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect($resumed['output'])
            ->toContain('outbound mail: HELD again by recover-host --resume')
            ->toContain('HOST RECOVERY COMPLETE')
            ->toContain("OUTBOUND MAIL: HELD (the recovery outbound hold, bound to operation {$operation}");
        expect(recoveryOutboundHoldRead($scratch, 'parity-target'))->toMatchArray(['state' => 'held', 'operation' => $operation]);
        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $resumed['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray(['status' => 'completed', 'outbound_mail' => 'held']);

        // Healthy, and still held: the final contract says both.
        $verified = recoverHostRun($scratch, ['--verify', '--target', 'parity-target'], $routing);
        expect($verified['exit'])->toBe(0, $verified['output']);
        expect($verified['output'])
            ->toContain('RECOVERED: YES')
            ->toContain('HEALTH: PASS')
            ->toContain("OUTBOUND MAIL: HELD (the recovery outbound hold, bound to operation {$operation}");

        // Released by the release, verified as released.
        recoverHostPlaceOutboundHold($scratch, ['operation' => $operation, 'state' => 'released', 'released_at' => '2026-01-16T10:00:00Z', 'released_address' => '203.0.113.10']);
        $verified = recoverHostRun($scratch, ['--verify', '--target', 'parity-target'], $routing);
        expect($verified['exit'])->toBe(0, $verified['output']);
        expect($verified['output'])->toContain('OUTBOUND MAIL: RELEASED (by release-recovered-outbound at 2026-01-16T10:00:00Z, for 203.0.113.10)');

        expect($bound)->toContain($operation);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to verify a recovered host whose outbound hold is missing, unreadable or belongs to no completed recovery', function (string $case, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryResumed($scratch);
        $routing = recoverHostOutboundRouting($scratch);

        match ($case) {
            'missing' => null,
            'unreadable' => recoverHostPlaceOutboundHold($scratch, ['state' => 'open']),
            'never bound' => recoverHostPlaceOutboundHold($scratch),
            'bound to another recovery' => recoverHostPlaceOutboundHold($scratch, ['operation' => '20260115-031500-c0ffee']),
        };

        $verified = recoverHostRun($scratch, ['--verify', '--target', 'parity-target'], $routing);

        expect($verified['exit'])->not->toBe(0, $verified['output']);
        expect($verified['output'])->toContain($expected)->not->toContain('RECOVERED: YES');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'missing' => ['missing', "parity-target's mail goes out directly, and this machine carries no recovery outbound hold"],
    'unreadable' => ['unreadable', "parity-target's recovery outbound hold cannot be trusted"],
    'never bound' => ['never bound', 'is bound to no recovery operation'],
    'bound to another recovery' => ['bound to another recovery', 'which this machine\'s recovery journal does not record as completed'],
]);

it('needs no hold for a target whose reviewed mail never leaves the machine directly', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryResumed($scratch);

        $verified = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($verified['exit'])->toBe(0, $verified['output']);
        expect($verified['output'])->toContain("OUTBOUND MAIL: not applicable (parity-target's mail never leaves this machine directly)");
        expect(is_dir(recoveryOutboundHoldDir($scratch)))->toBeFalse();
        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $verified['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray(['outbound_mail' => 'not-applicable']);
    } finally {
        removeScratchDir($scratch);
    }
});
