<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 4 slice 6: target-aware rollback.
 *
 * These tests execute the real shipped `infrastructure/scripts/rollback` —
 * never a reimplementation of its logic — with every host dependency
 * (common's deployment.conf, the target registry, the targets validator,
 * health-check, systemctl) supplied through the RATEGURU_* test-override
 * contract already established for health-check/status/cleanup/deploy, plus
 * one new override specific to rollback (RATEGURU_HEALTH_CHECK_BIN, the same
 * name deploy already uses for its own health-check dependency).
 *
 * rollback's own source guard (`if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
 * main "$@"; fi`) means sourcing the file never auto-runs main() — tests
 * simply `source` the real file directly, then call
 * parse_rollback_args/resolve_target/perform_rollback (bypassing
 * require_root, for parsing/resolution/pipeline coverage that doesn't need
 * real root) or invoke the script as a genuine subprocess (which does run
 * main(), requiring root first, exactly as production) — the same technique
 * DeployTest.php already established.
 *
 * No `chown`/ownership assertions are needed anywhere in this suite:
 * rollback never touches file ownership, only symlinks, history, and
 * PHP-FPM reload — unlike deploy, which extracts and chowns release trees.
 */
function rollbackOpsScript(): string
{
    return base_path('infrastructure/scripts/rollback');
}

function rollbackOpsSource(): string
{
    return File::get(rollbackOpsScript());
}

function rollbackOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function rollbackOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function rollbackOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function rollbackOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function rollbackOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/rollback-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function rollbackOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Run a bash script as a real subprocess with an explicit environment (never
 * inherited shell exports). fd 2 is redirected onto fd 1 at the descriptor
 * level so there is only one stream to drain.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function rollbackOpsExec(string $scriptPath, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Run the real rollback script as a genuine subprocess (never sourced) —
 * this is what makes BASH_SOURCE[0] == $0 true inside it, so its own source
 * guard calls main(), which calls require_root first, exactly like
 * production.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function rollbackOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', rollbackOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start rollback subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Sources the real rollback script (BASH_SOURCE[0] != $0 here, so main()
 * never auto-runs) then runs $body, which can call
 * parse_rollback_args/resolve_target/perform_rollback/main directly.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function rollbackOpsRunHarness(string $scratch, string $body, array $env = []): array
{
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg(rollbackOpsScript())."\n".$body."\n";
    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return rollbackOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function rollbackOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => rollbackOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => rollbackOpsTargetsCli(),
    ], $overrides);
}

/**
 * A stub matching health-check's real --environment/--target CLI shape
 * closely enough for rollback's own purposes: logs its argv, exits 0 (or 1
 * to simulate a failed post-switch/recovery health check).
 */
function rollbackOpsHealthCheckStub(string $scratch, string $logFile, bool $fail = false): string
{
    $path = $scratch.'/bin/health-check-stub-'.uniqid('', true);
    $exitCode = $fail ? 1 : 0;
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit {$exitCode}\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * PATH-shadowed stub for systemctl — rollback never invokes runuser or php,
 * unlike deploy, so only this one stub is needed.
 */
function rollbackOpsInstallSystemctlStub(string $scratch): void
{
    file_put_contents($scratch.'/bin/systemctl', "#!/usr/bin/env bash\n"
        .'echo "systemctl $*" >> '.escapeshellarg($scratch.'/systemctl.log')."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/systemctl', 0o755);
}

/**
 * A target root with three real release directories: "old" (what `previous`
 * points at), "current" (what `current` points at), and "explicit" (a third
 * release, pointed at by neither — used by --release destination tests so
 * they exercise a genuinely different code path than --previous).
 *
 * @return array{root: string, old: string, current: string, explicit: string}
 */
function rollbackOpsBuildFixture(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;

    foreach ([$root.'/releases', $root.'/deployments', $root.'/locks'] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    $releaseOld = 'v1.0.0-20260101-000000-aaa1111';
    $releaseCurrent = 'v1.0.1-20260102-000000-bbb2222';
    $releaseExplicit = 'v1.0.2-20260103-000000-ccc3333';

    foreach ([$releaseOld, $releaseCurrent, $releaseExplicit] as $releaseId) {
        $dir = $root.'/releases/'.$releaseId;
        mkdir($dir, 0o755, true);
        file_put_contents($dir.'/marker.txt', "release {$releaseId}\n");
    }

    symlink($root.'/releases/'.$releaseCurrent, $root.'/current');
    symlink($root.'/releases/'.$releaseOld, $root.'/previous');

    return [
        'root' => $root,
        'old' => $releaseOld,
        'current' => $releaseCurrent,
        'explicit' => $releaseExplicit,
    ];
}

/**
 * A target root with a current release and one other release, but no
 * `previous` symlink at all — used by the failure-recovery regression test
 * proving `previous` stays absent after a restored recovery, rather than
 * being left behind by a half-finished rollback attempt.
 *
 * @return array{root: string, current: string, target: string}
 */
function rollbackOpsBuildFixtureNoPrevious(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;

    foreach ([$root.'/releases', $root.'/deployments', $root.'/locks'] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    $releaseCurrent = 'v2.0.0-20260201-000000-ddd4444';
    $releaseTarget = 'v2.0.1-20260202-000000-eee5555';

    foreach ([$releaseCurrent, $releaseTarget] as $releaseId) {
        mkdir($root.'/releases/'.$releaseId, 0o755, true);
    }

    symlink($root.'/releases/'.$releaseCurrent, $root.'/current');

    return ['root' => $root, 'current' => $releaseCurrent, 'target' => $releaseTarget];
}

/**
 * A bare target root — releases/deployments/locks directories only, no
 * releases, no current/previous symlinks — for path-safety tests that build
 * their own exact, deliberately unsafe layout.
 *
 * @return array{root: string}
 */
function rollbackOpsBareFixture(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;

    foreach ([$root.'/releases', $root.'/deployments', $root.'/locks'] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    return ['root' => $root];
}

/**
 * A deployment.conf fixture derived from the real committed template, with
 * STAGING_ROOT overridden to point at the fixture's own root. Accepts any
 * array carrying a 'root' key, so both the full and bare fixture builders
 * work here interchangeably.
 *
 * @param  array{root: string}  $fixture
 */
function rollbackOpsDeploymentConfForFixture(string $scratch, array $fixture): string
{
    $conf = File::get(rollbackOpsDeploymentConfPath());
    $conf = preg_replace('/^STAGING_ROOT=.*$/m', 'STAGING_ROOT='.$fixture['root'], $conf);

    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, $conf);

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at the fixture's own
 * application root — the same technique DeployTest.php/CleanupTest.php
 * already established, re-derived here per this codebase's convention of
 * each test file owning its own helper namespace. runtime_user/deploy_user/
 * runtime_group/code_group are the current test process's own account/
 * group — rollback itself never uses them (no chown, no runuser), but the
 * registry schema requires every target to declare them regardless.
 *
 * @param  array{root: string}  $fixture
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function rollbackOpsParityRegistry(string $scratch, array $fixture): array
{
    $account = rollbackOpsCurrentAccount();
    $group = rollbackOpsCurrentGroup();

    // See DeployTest.php's own deployOpsParityRegistry() for why each of
    // these four constraints is relaxed in this throwaway, test-only copy of
    // the committed `targets` validator.
    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(rollbackOpsTargetsCli()),
    );
    $patchedTargets = str_replace(
        'elif [[ "${application_root}" != /home/www/rateguru/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'elif [[ "${incoming}" != /home/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_group}" ]]; then',
        'if false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_user}" ]]; then',
        'if false; then',
        $patchedTargets,
    );

    $targetsPath = $scratch.'/parity-targets';
    file_put_contents($targetsPath, $patchedTargets);
    chmod($targetsPath, 0o755);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $fixture['root'],
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => $account,
                'code_group' => $group,
                'incoming_artifacts' => $scratch.'/incoming-unused',
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example', 'parity-secondary.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => 1, 'offsite_retention_days' => 1],
                'php_fpm' => ['pool' => 'parity', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/parity-registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

function rollbackOpsCurrentAccount(): string
{
    exec('id -un', $output);

    return trim($output[0] ?? '');
}

function rollbackOpsCurrentGroup(): string
{
    exec('id -gn', $output);

    return trim($output[0] ?? '');
}

/**
 * Runs one full, real rollback (lock, current/previous validation, history,
 * atomic switch, PHP-FPM reload, health-check) against a fresh fixture,
 * using either --environment staging or --target parity-target, and either
 * --previous or an explicit --release.
 *
 * @return array{exit: int, output: string, fixture: array, healthCheckLog: string}
 */
function rollbackOpsRunFullRollback(string $scratch, bool $useTarget, ?string $explicitRelease, bool $failHealthCheck = false): array
{
    $fixture = rollbackOpsBuildFixture($scratch);
    $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
    rollbackOpsInstallSystemctlStub($scratch);

    $healthCheckLog = $scratch.'/health-check-'.uniqid('', true).'.log';
    touch($healthCheckLog);
    $healthCheckStub = rollbackOpsHealthCheckStub($scratch, $healthCheckLog, $failHealthCheck);

    $env = rollbackOpsBaseEnv($scratch, [
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
    ]);

    if ($useTarget) {
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        $env['RATEGURU_TARGET_REGISTRY_FILE'] = $registryPath;
        $env['RATEGURU_TARGETS_CLI'] = $targetsPath;
        $selectorArgs = '--target parity-target';
    } else {
        $selectorArgs = '--environment staging';
    }

    $destinationArgs = $explicitRelease !== null ? "--release {$explicitRelease}" : '--previous';

    [$exit, $output] = rollbackOpsRunHarness(
        $scratch,
        "parse_rollback_args {$selectorArgs} {$destinationArgs}\nresolve_target\nperform_rollback",
        $env,
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'fixture' => $fixture,
        'healthCheckLog' => $healthCheckLog,
    ];
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the legacy --environment selector', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=staging');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('supports the --target selector', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects --target and --environment used together', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --environment staging --previous',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('cannot be combined');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('requires exactly one selector', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --previous',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('exactly one of --target or --environment is required');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects duplicate selectors', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$targetExit, $targetOutput] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target a --target b --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($targetExit)->not->toBe(0);
        expect($targetOutput)->toContain('--target given more than once');

        [$envExit, $envOutput] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --environment production --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($envExit)->not->toBe(0);
        expect($envOutput)->toContain('--environment given more than once');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a selector given without a value', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --target', rollbackOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --environment', rollbackOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--environment requires a value');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an explicitly empty selector value', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target '' --previous",
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a selector that swallows the next flag as its value', function () {
    // Regression-shaped: a value-taking flag must never blindly take
    // whatever token follows it, including another flag — mirrors deploy's
    // identical protection for --target/--environment.
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --target --previous', rollbackOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --previous');

        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --environment --previous', rollbackOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--environment requires a value, not another option: --previous');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('shows both selector forms on --help and exits 0', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --help', rollbackOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--environment staging|production')
            ->toContain('--target TARGET_ID')
            ->toContain('--release RELEASE_ID')
            ->toContain('--previous');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --bogus', rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Destination contract: --release RELEASE_ID | --previous
// =============================================================================

it('parses an explicit --release', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --release v1.0.0-20260101-000000-abc1234'
            .'; printf "RELEASE_ID=[%s]\nUSE_PREVIOUS=[%s]\n" "${RELEASE_ID}" "${USE_PREVIOUS}"',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE_ID=[v1.0.0-20260101-000000-abc1234]')
            ->toContain('USE_PREVIOUS=[false]');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('parses --previous', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --previous'
            .'; printf "RELEASE_ID=[%s]\nUSE_PREVIOUS=[%s]\n" "${RELEASE_ID}" "${USE_PREVIOUS}"',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE_ID=[]')
            ->toContain('USE_PREVIOUS=[true]');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('requires a rollback destination', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release or --previous is required');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects --release and --previous used together, in either order', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --release v1.0.0-20260101-000000-abc1234 --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release and --previous cannot be combined');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --previous --release v1.0.0-20260101-000000-abc1234',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release and --previous cannot be combined');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects duplicate --release', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --release v1.0.0-20260101-000000-abc1234 --release v1.0.1-20260101-000000-abc1235',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release given more than once');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects duplicate --previous', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --previous --previous',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--previous given more than once');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects --release given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --release',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --environment staging --release ''",
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a non-empty value');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --environment staging --release --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value, not another option: --previous');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an invalid release ID at the pipeline level', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --release not-a-valid-release-id
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid release ID');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects rolling back onto the release that is already current', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<BASH
            parse_rollback_args --environment staging --release {$fixture['current']}
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target release is already current');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Target resolution
// =============================================================================

it('target mode resolves the root only from the registry, never from deployment.conf', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        rollbackOpsInstallSystemctlStub($scratch);
        $healthCheckLog = $scratch.'/hc.log';
        touch($healthCheckLog);
        $stub = rollbackOpsHealthCheckStub($scratch, $healthCheckLog);

        // deployment.conf deliberately points STAGING_ROOT somewhere that
        // does not exist — if resolve_target's target branch ever consulted
        // it, validate_releases_root would fail here.
        $brokenConfPath = rollbackOpsDeploymentConfForFixture($scratch, ['root' => $scratch.'/definitely-missing-root-'.uniqid('', true)]);

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target parity-target --previous\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $brokenConfPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $stub,
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($fixture['root'].'/releases/'.$fixture['old']));
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('legacy mode resolves the root only from deployment.conf, never from the registry', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        rollbackOpsInstallSystemctlStub($scratch);
        $healthCheckLog = $scratch.'/hc.log';
        touch($healthCheckLog);
        $stub = rollbackOpsHealthCheckStub($scratch, $healthCheckLog);

        // The registry is deliberately missing entirely — if resolve_target's
        // legacy branch ever consulted it, this would fail.
        $missingRegistryPath = $scratch.'/definitely-missing-registry.json';

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --environment staging --previous\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $missingRegistryPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $stub,
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($fixture['root'].'/releases/'.$fixture['old']));
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('resolve_target never calls the legacy helper in target mode, and never calls the target helper in legacy mode', function () {
    $source = rollbackOpsSource();

    expect(preg_match('/^resolve_target\(\) \{\n(.*?)\n\}\n/ms', $source, $matches))
        ->toBe(1, 'could not locate resolve_target() in scripts/rollback');

    $body = $matches[1];
    $parts = explode("    else\n", $body, 2);
    expect($parts)->toHaveCount(2, 'could not split resolve_target() into target/legacy branches');

    [$targetBranch, $legacyBranch] = $parts;

    expect($targetBranch)
        ->toContain('target_root')
        ->not->toContain('environment_root')
        ->not->toContain('validate_environment');

    expect($legacyBranch)
        ->toContain('environment_root')
        ->not->toContain('target_root')
        ->not->toContain('require_active_target');
});

// =============================================================================
// Lifecycle ordering: root-first, then lifecycle=planned, before any
// filesystem, lock, or history work
// =============================================================================

it('requires root before anything else, even for --target with an obviously invalid rollback', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunScript(
            ['--target', 'tits-guru', '--release', 'v0.0.0-20260728-000000-abcdef0'],
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a planned target before any filesystem, lock, or history work', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target tits-guru --release v0.0.0-20260728-000000-abcdef0
            resolve_target
            echo "SHOULD NOT REACH HERE"
            BASH, rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target ghost-target --previous
            resolve_target
            BASH, rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Release path safety: every case must fail closed before any symlink is
// switched.
// =============================================================================

it('rejects a current release located outside the releases root', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        $outside = $scratch.'/outside-root/v9.0.0-20260101-000000-fff9999';
        mkdir($outside, 0o755, true);
        symlink($outside, $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release is not a direct child of the releases root');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a previous release located outside the releases root', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        $outside = $scratch.'/outside-root/v9.0.0-20260101-000000-fff9999';
        mkdir($outside, 0o755, true);
        symlink($outside, $fixture['root'].'/previous');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('previous release is not a direct child of the releases root');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an explicit --release that is itself a symlink', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        $elsewhere = $scratch.'/elsewhere';
        mkdir($elsewhere, 0o755, true);
        $explicitId = 'v1.0.5-20260105-000000-abc5555';
        symlink($elsewhere, $fixture['root'].'/releases/'.$explicitId);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<BASH
            parse_rollback_args --environment staging --release {$explicitId}
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('explicit release must not be a symlink');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a current release directory that is itself a symlink', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        $elsewhere = $scratch.'/elsewhere';
        mkdir($elsewhere, 0o755, true);
        $currentId = 'v1.0.0-20260101-000000-aaa1111';
        symlink($elsewhere, $fixture['root'].'/releases/'.$currentId);
        symlink($fixture['root'].'/releases/'.$currentId, $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release must not be a symlink');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a previous release directory that is itself a symlink', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        $elsewhere = $scratch.'/elsewhere';
        mkdir($elsewhere, 0o755, true);
        $previousId = 'v0.9.0-20251201-000000-def5678';
        symlink($elsewhere, $fixture['root'].'/releases/'.$previousId);
        symlink($fixture['root'].'/releases/'.$previousId, $fixture['root'].'/previous');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('previous release must not be a symlink');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a nested release path', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        $nestedId = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/subdir/'.$nestedId, 0o755, true);
        symlink($fixture['root'].'/releases/subdir/'.$nestedId, $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release is not a direct child of the releases root');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an invalid release ID basename', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        mkdir($fixture['root'].'/releases/not-a-valid-release-id', 0o755, true);
        symlink($fixture['root'].'/releases/not-a-valid-release-id', $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid release ID');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a missing release target', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);

        // A dangling symlink: the target directory is never created.
        symlink($fixture['root'].'/releases/v1.0.0-20260101-000000-missing0', $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --environment staging --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release does not exist');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// End-to-end scratch parity: identical rollback through both selectors
// =============================================================================

it('rolls back identically through --environment and --target for --previous: new current/previous, history, PHP-FPM reload, health selector', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $envResult = rollbackOpsRunFullRollback($scratch, useTarget: false, explicitRelease: null);
        expect($envResult['exit'])->toBe(0, $envResult['output']);

        $targetResult = rollbackOpsRunFullRollback($scratch, useTarget: true, explicitRelease: null);
        expect($targetResult['exit'])->toBe(0, $targetResult['output']);

        foreach (['env' => $envResult, 'target' => $targetResult] as $label => $result) {
            $root = $result['fixture']['root'];
            $oldReleaseRoot = $root.'/releases/'.$result['fixture']['old'];
            $currentReleaseRoot = $root.'/releases/'.$result['fixture']['current'];

            expect(realpath($root.'/current'))->toBe(realpath($oldReleaseRoot), "{$label}: current must now point at the former previous release");
            expect(realpath($root.'/previous'))->toBe(realpath($currentReleaseRoot), "{$label}: previous must now point at the former current release");

            $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
            expect($history)->toHaveCount(2, "{$label}: rollback-started + rollback-finished");
            $started = json_decode($history[0], true, 512, JSON_THROW_ON_ERROR);
            $finished = json_decode($history[1], true, 512, JSON_THROW_ON_ERROR);
            expect($started['event'])->toBe('rollback-started');
            expect($started['status'])->toBe('running');
            expect($finished['event'])->toBe('rollback-finished');
            expect($finished['status'])->toBe('success');
            expect($finished['release'])->toBe($result['fixture']['old']);

            expect(trim(file_get_contents($scratch.'/systemctl.log')))->toContain('reload');

            expect(file_exists($root.'/current.new'))->toBeFalse("{$label}: temporary current symlink must be cleaned up");
            expect(file_exists($root.'/previous.new'))->toBeFalse("{$label}: temporary previous symlink must be cleaned up");
        }

        expect(trim(File::get($envResult['healthCheckLog'])))->toBe('--environment staging');
        expect(trim(File::get($targetResult['healthCheckLog'])))->toBe('--target parity-target');

        expect($envResult['output'])->toContain('staging rolled back to');
        expect($targetResult['output'])->toContain('parity-target rolled back to');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rolls back identically through --environment and --target for an explicit --release', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $explicitRelease = 'v1.0.2-20260103-000000-ccc3333';

        $envResult = rollbackOpsRunFullRollback($scratch, useTarget: false, explicitRelease: $explicitRelease);
        expect($envResult['exit'])->toBe(0, $envResult['output']);

        $targetResult = rollbackOpsRunFullRollback($scratch, useTarget: true, explicitRelease: $explicitRelease);
        expect($targetResult['exit'])->toBe(0, $targetResult['output']);

        foreach (['env' => $envResult, 'target' => $targetResult] as $label => $result) {
            $root = $result['fixture']['root'];
            $explicitReleaseRoot = $root.'/releases/'.$explicitRelease;
            $currentReleaseRoot = $root.'/releases/'.$result['fixture']['current'];

            expect(realpath($root.'/current'))->toBe(realpath($explicitReleaseRoot), "{$label}: current must point at the explicit release");
            expect(realpath($root.'/previous'))->toBe(realpath($currentReleaseRoot), "{$label}: previous must point at the former current release");

            $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
            $finished = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
            expect($finished['event'])->toBe('rollback-finished');
            expect($finished['status'])->toBe('success');
            expect($finished['release'])->toBe($explicitRelease);
        }

        expect(trim(File::get($envResult['healthCheckLog'])))->toBe('--environment staging');
        expect(trim(File::get($targetResult['healthCheckLog'])))->toBe('--target parity-target');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Failure recovery, for both selectors
// =============================================================================

it('recovers correctly from a failed post-switch health check in legacy mode', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, useTarget: false, explicitRelease: null, failHealthCheck: true);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('rollback health check failed');

        $root = $result['fixture']['root'];
        $originalCurrent = realpath($root.'/releases/'.$result['fixture']['current']);
        $originalPrevious = realpath($root.'/releases/'.$result['fixture']['old']);

        expect(realpath($root.'/current'))->toBe($originalCurrent, 'current must be restored to its original release');
        expect(realpath($root.'/previous'))->toBe($originalPrevious, 'previous must be restored to its original release');

        expect(is_dir($root.'/releases/'.$result['fixture']['old']))->toBeTrue('the target release must never be deleted, only the symlinks are touched');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        expect($history)->toHaveCount(2);
        $started = json_decode($history[0], true, 512, JSON_THROW_ON_ERROR);
        $finished = json_decode($history[1], true, 512, JSON_THROW_ON_ERROR);
        expect($started['event'])->toBe('rollback-started');
        expect($finished['event'])->toBe('rollback-finished');
        expect($finished['status'])->toBe('failed-health-check');
        expect($finished['release'])->toBe($result['fixture']['old']);

        // The normal and recovery health-check calls use the identical
        // selector — two identical lines in the log.
        $healthCheckCalls = array_values(array_filter(explode("\n", trim(File::get($result['healthCheckLog'])))));
        expect($healthCheckCalls)->toBe(['--environment staging', '--environment staging']);

        // PHP-FPM reload happens once for the initial switch, once during
        // recovery.
        expect(substr_count(trim(file_get_contents($scratch.'/systemctl.log')), 'reload'))->toBe(2);

        expect(file_exists($root.'/current.new'))->toBeFalse();
        expect(file_exists($root.'/current.recovery'))->toBeFalse();
        expect(file_exists($root.'/previous.new'))->toBeFalse();
        expect(file_exists($root.'/previous.recovery'))->toBeFalse();
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('recovers correctly from a failed post-switch health check in target mode', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, useTarget: true, explicitRelease: null, failHealthCheck: true);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('rollback health check failed');

        $root = $result['fixture']['root'];
        $originalCurrent = realpath($root.'/releases/'.$result['fixture']['current']);
        $originalPrevious = realpath($root.'/releases/'.$result['fixture']['old']);

        expect(realpath($root.'/current'))->toBe($originalCurrent, 'current must be restored to its original release');
        expect(realpath($root.'/previous'))->toBe($originalPrevious, 'previous must be restored to its original release');

        expect(is_dir($root.'/releases/'.$result['fixture']['old']))->toBeTrue('the target release must never be deleted, only the symlinks are touched');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        expect($history)->toHaveCount(2);
        $finished = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($finished['event'])->toBe('rollback-finished');
        expect($finished['status'])->toBe('failed-health-check');
        expect($finished['release'])->toBe($result['fixture']['old']);

        $healthCheckCalls = array_values(array_filter(explode("\n", trim(File::get($result['healthCheckLog'])))));
        expect($healthCheckCalls)->toBe(['--target parity-target', '--target parity-target']);

        expect(substr_count(trim(file_get_contents($scratch.'/systemctl.log')), 'reload'))->toBe(2);

        expect(file_exists($root.'/current.new'))->toBeFalse();
        expect(file_exists($root.'/current.recovery'))->toBeFalse();
        expect(file_exists($root.'/previous.new'))->toBeFalse();
        expect(file_exists($root.'/previous.recovery'))->toBeFalse();
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('leaves previous absent after a restored recovery, when it did not exist beforehand', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixtureNoPrevious($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        rollbackOpsInstallSystemctlStub($scratch);

        $healthCheckLog = $scratch.'/hc.log';
        touch($healthCheckLog);
        $failingStub = rollbackOpsHealthCheckStub($scratch, $healthCheckLog, fail: true);

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --environment staging --release {$fixture['target']}\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $failingStub,
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('rollback health check failed');

        expect(is_link($fixture['root'].'/previous'))->toBeFalse('previous must remain absent — it never existed before this attempt');
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($fixture['root'].'/releases/'.$fixture['current']));
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Override gating: RATEGURU_COMMON_FILE / RATEGURU_HEALTH_CHECK_BIN
// =============================================================================

it('ignores RATEGURU_COMMON_FILE without the opt-in flag, and honors it with the opt-in flag', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$deniedExit, $deniedOutput] = rollbackOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => '/tmp/poisoned-common-'.uniqid('', true),
        ]);
        expect($deniedExit)->not->toBe(0);
        expect($deniedOutput)->toContain('/home/www/rateguru/bin/common');

        [$exit, $output] = rollbackOpsRunHarness($scratch, 'printf "COMMON_FILE=%s\n" "${COMMON_FILE}"', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
        ]);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('COMMON_FILE='.rollbackOpsCommonFile());
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('ignores RATEGURU_HEALTH_CHECK_BIN without the opt-in flag, and honors it with it', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'printf "HEALTH_CHECK_BIN=%s\n" "${HEALTH_CHECK_BIN}"', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
            // Deliberately no RATEGURU_HEALTH_CHECK_BIN — the default must
            // resolve to the hardcoded production path even though the opt-in
            // flag is on, because no override value was given.
        ]);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('HEALTH_CHECK_BIN=/home/www/rateguru/bin/health-check');

        [$grantedExit, $grantedOutput] = rollbackOpsRunHarness($scratch, 'printf "HEALTH_CHECK_BIN=%s\n" "${HEALTH_CHECK_BIN}"', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
        ]);
        expect($grantedExit)->toBe(0, $grantedOutput);
        expect($grantedOutput)->toContain('HEALTH_CHECK_BIN=/tmp/poisoned-health-check');

        // The genuinely gate-off case: no RATEGURU_ALLOW_TEST_OVERRIDES at
        // all, real subprocess — rollback must fail at its hardcoded default
        // COMMON_FILE path, proving no override of any kind took effect.
        [$fullyDeniedExit, $fullyDeniedOutput] = rollbackOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
        ]);
        expect($fullyDeniedExit)->not->toBe(0);
        expect($fullyDeniedOutput)->toContain('/home/www/rateguru/bin/common');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Out of scope: nothing else changed
// =============================================================================

it('leaves deploy, backup scripts and workflows untouched', function () {
    exec('git rev-parse --verify -q origin/develop >/dev/null 2>&1', $probeOutput, $probeExit);

    if ($probeExit !== 0) {
        test()->markTestSkipped('origin/develop is not available in this checkout (shallow clone) — run locally for full history to exercise this check.');
    }

    $unchanged = [
        'infrastructure/scripts/deploy',
        'infrastructure/scripts/backup',
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/restore-test',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/config/sudoers/rateguru-deploy',
        '.github/workflows/deploy-staging.yml',
    ];

    foreach ($unchanged as $path) {
        $full = base_path($path);
        expect(File::exists($full))->toBeTrue("expected file to exist: {$path}");

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('git show origin/develop:'.escapeshellarg($path), $descriptors, $pipes);
        expect($process)->not->toBeFalse();
        $developContent = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $ok = proc_close($process) === 0;

        expect($ok)->toBeTrue("origin/develop is unavailable for {$path}: {$stderr}");
        expect(File::get($full))->toBe($developContent, "{$path} must be byte-identical to origin/develop in this slice");
    }
});

it('does not add queue restart to rollback', function () {
    // Explicitly out of scope for this slice — a separate future change, not
    // part of the target migration.
    expect(rollbackOpsSource())->not->toContain('queue:restart');
});
