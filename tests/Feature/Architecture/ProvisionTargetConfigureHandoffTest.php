<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/provision-target across the hand-off to Configure —
 * what each of its three modes answers once the operator has written the
 * canonical shared/.env.
 *
 * --verify asks whether the structure is still provisioned, and keeps passing;
 * --check and --apply ask whether Provision may still be applied, and refuse,
 * before any mutation. Deployment-owned state stays a conflict either way. A
 * shared/.env that is not a regular file is ProvisionTargetMalformedEnvTest's;
 * what Provision refuses before it mutates anything is
 * ProvisionTargetPreconditionsTest's. All three run the real orchestrator
 * against the simulated host whose harness lives in tests/Pest.php.
 */

// =============================================================================
// The onboarding transition: Provision -> operator writes .env -> Configure
// =============================================================================
//
// A real Configure run reached the VPS and stopped, having changed nothing:
//
//   BLOCKED provision-target --verify does not pass for tits-guru
//   ERROR: refusing to configure tits-guru: the target is not provisioned.
//
// Two requirements contradicted each other. configure-target needs the canonical
// shared/.env to ALREADY exist (it never creates one) and needs
// `provision-target --verify` to pass — while verify counted an existing .env as a
// conflict. Without the file Configure refuses; with it Provision's verify
// refuses. The documented sequence could not be performed at all.
//
// The fix separates two questions that one check had been answering:
//
//   --verify  "is this structure still provisioned?"  MONOTONIC — later
//             onboarding material does not un-provision what Provision built.
//   --check   "may Provision still be applied?"       PHASE-BOUNDED.
//   --apply   same question, and fails closed.

it('verifies a provisioned target whose .env does not exist yet', function () {
    // Case A: the state immediately after Provision.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$applyExit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($applyExit)->toBe(0);

        expect(file_exists($scratch.'/fs/home/www/rateguru/production/demo-shop/shared/.env'))->toBeFalse();

        [$exit, $output] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TARGET INFRASTRUCTURE: PROVISIONED');
    } finally {
        provisionCleanup($scratch);
    }
});

it('verifies a provisioned target whose canonical .env is present', function () {
    // Case B, and THE regression. This is the state the real run was refused in.
    $scratch = provisionScratchDir();

    try {
        [$env, $root] = provisionWithCanonicalEnv($scratch);

        [$exit, $output] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            // And it says why the file is acceptable rather than silently ignoring it.
            ->toContain('belongs to the Configure phase')
            // Scoped: the summary legitimately prints a CONFLICT counter, so the
            // claim is that no conflict was RAISED, not that the word is absent.
            ->not->toContain('CONFLICT phase:')
            ->not->toContain('CONFLICT state:');

        // Every structural guarantee still asserted, not loosened alongside.
        expect($output)
            ->toContain('LIFECYCLE: planned')
            ->toContain('APPLICATION: NOT DEPLOYED')
            ->toContain('PUBLIC TRAFFIC: NOT ACTIVATED')
            ->toContain('PASS     layout:install-bootstrap-host-layout')
            ->toContain('PASS     services:install-bootstrap-services');

        // The file is read for existence and nothing else.
        expect(File::get($root.'/shared/.env'))->toBe("APP_KEY=base64:OPERATOR-WROTE-THIS\n");
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses to apply provisioning once the canonical .env exists', function () {
    // Case C: phase-bounded, fail-closed, before any mutation.
    $scratch = provisionScratchDir();

    try {
        [$env, $root] = provisionWithCanonicalEnv($scratch);

        $before = provisionSnapshotDemoState($scratch);
        file_put_contents($scratch.'/log/identity.log', '');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('shared/.env already exists')
            ->toContain('past the provisioning phase')
            ->toContain('never deleted, moved or re-owned');

        // No mutation, and above all the operator's file is untouched.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionSnapshotDemoState($scratch))->toBe($before);
        expect(File::get($root.'/shared/.env'))->toBe("APP_KEY=base64:OPERATOR-WROTE-THIS\n");
    } finally {
        provisionCleanup($scratch);
    }
});

it('reports in --check that provisioning may no longer be applied', function () {
    // Case D: the operator report says the structure is there AND that apply is
    // over — not that something is missing.
    $scratch = provisionScratchDir();

    try {
        [$env] = provisionWithCanonicalEnv($scratch);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('CONFLICT phase:demo-shop')
            ->toContain('provisioning may not be applied')
            ->toContain('belongs to the Configure/later phase')
            ->toContain('Continue with configure-target');

        // Not dressed up as absent structure: this is a phase conflict, not a
        // state one, and the two have different names so an operator can tell
        // which of the two problems they have.
        expect($output)->not->toContain('CONFLICT state:demo-shop');
    } finally {
        provisionCleanup($scratch);
    }
});

it('still reports the structure in --check once the canonical .env exists', function () {
    // The phase conflict answers "may Provision be applied?". It must not also
    // answer "is the structure intact?" by silently not looking: --check reaches
    // the structural report through report_new_target_safety, so recording the
    // conflict with a non-zero return would have withheld every delegated check
    // behind a verdict that reads as though they had run.
    $scratch = provisionScratchDir();

    try {
        [$env] = provisionWithCanonicalEnv($scratch);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);

        // Both answers, in one report.
        expect($output)
            ->toContain('CONFLICT phase:demo-shop')
            ->toContain('TARGET INFRASTRUCTURE (authoritative owners')
            ->toContain('PASS     layout:install-bootstrap-host-layout')
            ->toContain('PASS     services:install-bootstrap-services');

        // And the summary counted the delegated passes rather than reporting a
        // bare conflict against an uninspected target.
        expect($output)->toContain('CONFLICT: 1');
        expect(provisionSummaryCount($output, 'PASS'))->toBeGreaterThan(2);
    } finally {
        provisionCleanup($scratch);
    }
});

it('reports a broken structure in --check even when the canonical .env exists', function () {
    // The case the suppression actually endangered: a target that was provisioned,
    // has since been configured, and has had a structural component removed from
    // under it. The phase conflict is true and the structure is broken, and an
    // operator needs to be told the second thing.
    $scratch = provisionScratchDir();

    try {
        [$env] = provisionWithCanonicalEnv($scratch);

        $pool = $scratch.'/fs/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf';
        expect(file_exists($pool))->toBeTrue('the fixture must start from a provisioned target');
        unlink($pool);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);

        // The conflict is still raised...
        expect($output)->toContain('CONFLICT phase:demo-shop');

        // ...and the missing pool is named, not hidden behind it.
        expect($output)->toContain('services:install-bootstrap-services');
        expect(provisionSummaryCount($output, 'MISSING') + provisionSummaryCount($output, 'DRIFT'))
            ->toBeGreaterThan(0, "a removed PHP-FPM pool must be reported:\n{$output}");
        expect($output)->toContain('rateguru-demo-shop.conf');
    } finally {
        provisionCleanup($scratch);
    }
});

it('still fails verify on deployment-owned state, with or without an .env', function (string $shape) {
    // Cases E, F, G. Relaxing the .env rule must not relax these: a planned
    // target never legitimately holds a release or a pointer to one, and Provision
    // and Configure both never create them.
    $scratch = provisionScratchDir();

    try {
        [$env, $root] = provisionWithCanonicalEnv($scratch);

        @mkdir($root.'/releases/20240101120000', 0o755, true);

        match ($shape) {
            'current' => symlink($root.'/releases/20240101120000', $root.'/current'),
            'previous' => symlink($root.'/releases/20240101120000', $root.'/previous'),
            'release' => null,
        };

        [$exit, $output] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT state:demo-shop');
    } finally {
        provisionCleanup($scratch);
    }
})->with(['current', 'previous', 'release']);
