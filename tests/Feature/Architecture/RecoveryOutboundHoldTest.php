<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/recovery-outbound-hold — the recovery-time outbound
 * hold's one definition, driven by sourcing the shipped library: what a
 * marker is, how a preparation places it, a recovery binds it, a release
 * releases it, and that nothing it does not recognise is ever read as a fence
 * that is not there.
 */

/**
 * Run BODY with the library sourced, the hold directory under FS in $D, and
 * the test's own user as the owner a host's root would be.
 *
 * @return array{0: int, 1: string}
 */
function recoveryOutboundHoldShell(string $fs, string $body): array
{
    $script = 'set -Eeuo pipefail'."\n"
        .'RECOVERY_OUTBOUND_HOLD_OWNER="$(id -un)"'."\n"
        .'source '.escapeshellarg(base_path('infrastructure/scripts/recovery-outbound-hold'))."\n"
        .'D='.escapeshellarg(recoveryOutboundHoldDir($fs))."\n"
        .$body;

    $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $fs, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

it('places a hold once, keeps it for the same recovery, and refuses to move it to another', function () {
    $fs = makeScratchDir('recovery-outbound-hold');

    try {
        [$status, $output] = recoveryOutboundHoldShell($fs, <<<'BASH'
            recovery_outbound_hold_judge "$D" tits-guru; echo "before=$ROH_STATE"
            recovery_outbound_hold_place "$D" tits-guru 20261020-030000; echo "first=$ROH_PLACED"
            recovery_outbound_hold_place "$D" tits-guru 20261020-030000; echo "again=$ROH_PLACED"
            recovery_outbound_hold_place "$D" tits-guru 20261021-030000 || echo "other: $ROH_PROBLEM"
            stat -c '%a %n' "$D" "$D/tits-guru.json" | sed "s#$D#D#"
            BASH);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('before=absent')
            ->toContain('first=placed')
            ->toContain('again=kept')
            ->toContain('other: this machine already holds tits-guru\'s outbound mail for the recovery from backup 20261020-030000, not 20261021-030000')
            ->toContain('700 D')
            ->toContain('600 D/tits-guru.json');

        $document = recoveryOutboundHoldRead($fs);
        expect(array_keys($document))->toEqualCanonicalizing(array_keys(recoveryOutboundHoldDocument()));
        expect($document)->toMatchArray(['hold' => 'recovery-outbound', 'schema_version' => 1, 'target' => 'tits-guru', 'backup' => '20261020-030000', 'operation' => null, 'state' => 'held', 'released_at' => null, 'released_address' => null]);
    } finally {
        removeScratchDir($fs);
    }
});

it('binds a hold to the one recovery operation that restores onto the machine, and refuses any other', function () {
    $fs = makeScratchDir('recovery-outbound-hold');

    try {
        recoveryOutboundHoldWrite($fs);

        [$status, $output] = recoveryOutboundHoldShell($fs, <<<'BASH'
            recovery_outbound_hold_bind "$D" tits-guru 20261020-030000 20261020-120000-abcdef; echo "bound=$ROH_OPERATION"
            recovery_outbound_hold_bind "$D" tits-guru 20261020-030000 20261020-120000-abcdef; echo "again=$ROH_OPERATION"
            recovery_outbound_hold_bind "$D" tits-guru 20261020-030000 20261020-130000-123456 || echo "other operation: $ROH_PROBLEM"
            recovery_outbound_hold_bind "$D" tits-guru 20261021-030000 20261020-120000-abcdef || echo "other backup: $ROH_PROBLEM"
            recovery_outbound_hold_require "$D" tits-guru 20261020-120000-abcdef && echo required
            recovery_outbound_hold_require "$D" tits-guru 20261020-130000-123456 || echo "require other: $ROH_PROBLEM"
            recovery_outbound_hold_bind "$D" staging-main 20261020-030000 20261020-120000-abcdef || echo "absent: $ROH_PROBLEM"
            BASH);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('bound=20261020-120000-abcdef')
            ->toContain('again=20261020-120000-abcdef')
            ->toContain('other operation: the recovery outbound hold on this machine is bound to recovery operation 20261020-120000-abcdef, not 20261020-130000-123456')
            ->toContain('other backup: the recovery outbound hold on this machine was placed for backup 20261020-030000, not 20261021-030000')
            ->toContain('required')
            ->toContain('require other: the recovery outbound hold on this machine is bound to recovery operation 20261020-120000-abcdef, not 20261020-130000-123456')
            ->toContain('absent: this machine holds no outbound mail for staging-main — its preparation for this recovery never placed the recovery outbound hold');

        expect(recoveryOutboundHoldRead($fs)['operation'])->toBe('20261020-120000-abcdef');
    } finally {
        removeScratchDir($fs);
    }
});

it('releases only the held hold of the named operation, records the release, and puts back only its own', function () {
    $fs = makeScratchDir('recovery-outbound-hold');

    try {
        recoveryOutboundHoldWrite($fs, 'tits-guru', ['operation' => '20261020-120000-abcdef']);

        [$status, $output] = recoveryOutboundHoldShell($fs, <<<'BASH'
            recovery_outbound_hold_release "$D" tits-guru 20261020-130000-123456 203.0.113.10 || echo "other: $ROH_PROBLEM"
            recovery_outbound_hold_release "$D" tits-guru 20261020-120000-abcdef 203.0.113.10; echo "released=$ROH_STATE for $ROH_ADDRESS"
            recovery_outbound_hold_release "$D" tits-guru 20261020-120000-abcdef 203.0.113.10 || echo "twice: $ROH_PROBLEM"
            recovery_outbound_hold_place "$D" tits-guru 20261020-030000 || echo "re-prepare: $ROH_PROBLEM"
            recovery_outbound_hold_targets "$D"; echo "held=[${ROH_HELD_TARGETS[*]-}] released=[${ROH_RELEASED_TARGETS[*]-}]"
            recovery_outbound_hold_restore "$D" tits-guru 20261020-130000-123456 || echo "restore other: $ROH_PROBLEM"
            recovery_outbound_hold_restore "$D" tits-guru 20261020-120000-abcdef; echo "restored=$ROH_STATE"
            BASH);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('other: the recovery outbound hold on this machine is bound to recovery operation 20261020-120000-abcdef, not 20261020-130000-123456')
            ->toContain('released=released for 203.0.113.10')
            ->toContain("twice: tits-guru's outbound mail was already released on this machine")
            ->toContain("re-prepare: tits-guru's outbound mail was already released on this machine")
            ->toContain('held=[] released=[tits-guru]')
            ->toContain('restore other: the recovery outbound hold of tits-guru is released for operation 20261020-120000-abcdef — not the release of 20261020-130000-123456 to put back')
            ->toContain('restored=held');

        expect(recoveryOutboundHoldRead($fs))->toMatchArray(['state' => 'held', 'operation' => '20261020-120000-abcdef', 'released_at' => null, 'released_address' => null]);
    } finally {
        removeScratchDir($fs);
    }
});

it('judges every marker it does not recognise as corrupt, never as absent', function (string $case, string $expected) {
    $fs = makeScratchDir('recovery-outbound-hold');

    try {
        $path = recoveryOutboundHoldWrite($fs);
        $dir = recoveryOutboundHoldDir($fs);

        match ($case) {
            'group-readable' => chmod($path, 0o640),
            'a directory open to others' => chmod($dir, 0o755),
            'a symlink' => (static function () use ($path, $dir): void {
                rename($path, "{$dir}/../target.json");
                symlink("{$dir}/../target.json", $path);
            })(),
            'a directory in its place' => (static function () use ($path): void {
                unlink($path);
                mkdir($path);
            })(),
            'another kind of hold' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['hold' => 'offsite-writes']),
            'another schema' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['schema_version' => 2]),
            'another target' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['target' => 'staging-main']),
            'a backup that is not one' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['backup' => 'latest']),
            'an operation that is not one' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['operation' => '../../x']),
            'a missing field' => File::put($path, json_encode(array_diff_key(recoveryOutboundHoldDocument(), ['backup' => true]))),
            'an extra field' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['note' => 'x']),
            'a held hold with a release' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['released_at' => '2026-10-21T10:00:00Z']),
            'a release without an address' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['state' => 'released', 'operation' => '20261020-120000-abcdef', 'released_at' => '2026-10-21T10:00:00Z']),
            'a state that is not one' => recoveryOutboundHoldWrite($fs, 'tits-guru', ['state' => 'paused']),
            'not JSON' => recoveryOutboundHoldWrite($fs, 'tits-guru', '{"hold":'),
            'far too large' => recoveryOutboundHoldWrite($fs, 'tits-guru', str_repeat(' ', 5000).'{}'),
        };

        [$status, $output] = recoveryOutboundHoldShell($fs, <<<'BASH'
            recovery_outbound_hold_judge "$D" tits-guru; echo "state=$ROH_STATE"; echo "problem=$ROH_PROBLEM"
            recovery_outbound_hold_targets "$D" && echo "targets=trusted" || echo "targets=refused"
            recovery_outbound_hold_place "$D" tits-guru 20261020-030000 && echo "place=accepted" || echo "place=refused"
            BASH);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('state=corrupt')
            ->toContain($expected)
            ->toContain('targets=refused')
            ->toContain('place=refused');
    } finally {
        removeScratchDir($fs);
    }
})->with([
    'group-readable' => ['group-readable', 'mode 600'],
    'a directory open to others' => ['a directory open to others', 'mode 700'],
    'a symlink' => ['a symlink', 'is not a regular file'],
    'a directory in its place' => ['a directory in its place', 'is not a regular file'],
    'another kind of hold' => ['another kind of hold', 'it is not a recovery outbound hold (hold "offsite-writes")'],
    'another schema' => ['another schema', 'it is not of schema 1'],
    'another target' => ['another target', 'it holds "staging-main", not tits-guru'],
    'a backup that is not one' => ['a backup that is not one', 'is not an exact backup timestamp'],
    'an operation that is not one' => ['an operation that is not one', 'is not a recovery operation ID'],
    'a missing field' => ['a missing field', 'it lacks backup'],
    'an extra field' => ['an extra field', 'it carries a field a hold never has (note)'],
    'a held hold with a release' => ['a held hold with a release', 'it is held, and records a release'],
    'a release without an address' => ['a release without an address', 'it is released, and does not record the operation, the time and the address of that release'],
    'a state that is not one' => ['a state that is not one', 'its state "paused" is neither held nor released'],
    'not JSON' => ['not JSON', 'is not a JSON document'],
    'far too large' => ['far too large', 'far larger than any hold'],
]);

it('refuses a hold directory that holds anything but holds, and ignores only its own unfinished writes', function () {
    $fs = makeScratchDir('recovery-outbound-hold');

    try {
        recoveryOutboundHoldWrite($fs);
        $dir = recoveryOutboundHoldDir($fs);
        file_put_contents("{$dir}/.tits-guru.json.tmp.4242", '{');

        [$status, $output] = recoveryOutboundHoldShell($fs, <<<'BASH'
            recovery_outbound_hold_targets "$D" && echo "held=${ROH_HELD_TARGETS[*]}"
            BASH);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('held=tits-guru');

        file_put_contents("{$dir}/README", "the hold\n");
        [$status, $output] = recoveryOutboundHoldShell($fs, <<<'BASH'
            recovery_outbound_hold_targets "$D" || echo "refused: $ROH_PROBLEM"
            BASH);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('/README is not a recovery outbound hold — that directory holds one <target>.json per held target and nothing else');
    } finally {
        removeScratchDir($fs);
    }
});
