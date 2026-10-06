<?php

/**
 * infrastructure/scripts/provision-target when shared/.env exists but is not
 * a regular file — a directory or a dangling symlink, which configure-target
 * cannot use either.
 *
 * Every mode fails closed on it, reports it as a conflict while still
 * reporting the structure, and never suggests repairing structure by stepping
 * back over the phase boundary — while a target Provision still owns keeps
 * being told to run the child installers. How a well-formed .env changes each
 * mode's answer, and why the phase boundary exists, is
 * ProvisionTargetConfigureHandoffTest's.
 */

// =============================================================================
// A shared/.env that is not a regular file
// =============================================================================

it('fails every mode closed when shared/.env is not a regular file', function (string $shape, string $mode) {
    // configure-target requires a REGULAR FILE. A directory or a dangling
    // symlink at that path is a target Configure cannot proceed on, so --verify
    // must not pass it either: a green structural gate followed by Configure
    // refusing the same file is the deadlock this whole change exists to remove,
    // wearing different clothes.
    $scratch = provisionScratchDir();

    try {
        [$env, $root] = provisionWithMalformedEnv($scratch, $shape);

        $before = provisionSnapshotDemoState($scratch);
        file_put_contents($scratch.'/log/identity.log', '');

        [$exit, $output] = provisionRun([$mode, '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('is not a regular file');

        // Never tidied away: no delete, no move, no chmod, no chown, whatever
        // shape the path is in.
        expect(file_exists($root.'/shared/.env') || is_link($root.'/shared/.env'))->toBeTrue();
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionSnapshotDemoState($scratch))->toBe($before);
    } finally {
        provisionCleanup($scratch);
    }
})->with(['directory', 'dangling symlink'])->with(['--verify', '--check', '--apply']);

it('reports a malformed shared/.env as a conflict and still reports the structure', function (string $shape) {
    // Same read-only obligation as the valid-.env case: the phase answer must
    // not cost the operator the structural one.
    $scratch = provisionScratchDir();

    try {
        [$env] = provisionWithMalformedEnv($scratch, $shape);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('CONFLICT phase:demo-shop')
            ->toContain('is not a regular file')
            ->toContain('TARGET INFRASTRUCTURE (authoritative owners')
            ->toContain('PASS     layout:install-bootstrap-host-layout')
            ->toContain('PASS     services:install-bootstrap-services');
    } finally {
        provisionCleanup($scratch);
    }
})->with(['directory', 'dangling symlink']);

it('never suggests repairing structure by bypassing the phase boundary', function (string $shape) {
    // The structural findings stay visible past the phase boundary, and that is
    // the point — but the remediation they used to carry was a direct child
    // installer apply. Those installers answer to provisioning authorization,
    // not to the phase gate, so following that hint would converge exactly what
    // this run has just refused to converge itself.
    $scratch = provisionScratchDir();

    try {
        [$env] = $shape === 'valid file'
            ? provisionWithCanonicalEnv($scratch)
            : provisionWithMalformedEnv($scratch, $shape);

        // Break the structure so a MISSING finding — the one that carries the
        // remediation — is actually produced.
        unlink($scratch.'/fs/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf');

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);

        // The drift is still shown...
        expect(provisionSummaryCount($output, 'MISSING') + provisionSummaryCount($output, 'DRIFT'))
            ->toBeGreaterThan(0, "the structural drift must still be reported:\n{$output}");

        // ...and no line tells the operator to run a child installer directly.
        expect($output)
            ->not->toContain('--apply --target demo-shop --provisioning')
            ->not->toContain('install-bootstrap-services --apply')
            ->not->toContain('install-bootstrap-host-layout --apply');

        expect($output)
            ->toContain('DIAGNOSTIC')
            ->toContain('bypasses the phase boundary');
    } finally {
        provisionCleanup($scratch);
    }
})->with(['valid file', 'directory', 'dangling symlink']);

it('still names the child installer as the remediation while Provision owns the target', function () {
    // The counterpart of the test above: suppressing the hint unconditionally
    // would take the one useful instruction away from the ordinary case.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('install-bootstrap-host-layout --apply --target demo-shop --provisioning')
            ->toContain('install-bootstrap-services --apply --target demo-shop --provisioning')
            ->not->toContain('DIAGNOSTIC');
    } finally {
        provisionCleanup($scratch);
    }
});
