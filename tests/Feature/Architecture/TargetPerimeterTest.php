<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 4 slice 8: the target-aware perimeter — the three generic sudo
 * wrappers (infrastructure/config/wrappers/rateguru-{deploy,rollback,cleanup}),
 * the GitHub composite action and staging workflow that invoke them through
 * SSH, the sudoers rule, and the backup cron entries.
 *
 * Wrapper tests run the real, shipped wrapper scripts — never a
 * reimplementation — by sourcing them (the BASH_SOURCE[0] != $0 guard means
 * main() never auto-runs) and calling parse_wrapper_args/authorize_caller/
 * require_active_target/exec_underlying directly, bypassing require_root the
 * same way infrastructure/scripts/deploy's own test suite does. require_root
 * itself is proven separately, once per wrapper, against a real (non-root)
 * subprocess invocation — see "root authorization" below.
 */

// =============================================================================
// Harness
// =============================================================================

function perimeterWrapperPath(string $name): string
{
    return base_path("infrastructure/config/wrappers/{$name}");
}

function perimeterCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function perimeterDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function perimeterRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function perimeterTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function perimeterScratchDir(): string
{
    $dir = sys_get_temp_dir().'/target-perimeter-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function perimeterCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function perimeterExec(string $scriptPath, array $env): array
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
 * Sources the real wrapper script (never as $0, so main() never auto-runs),
 * then runs $body — typically a sequence of direct calls to
 * parse_wrapper_args/authorize_caller/require_active_target/exec_underlying.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function perimeterRunHarness(string $wrapperName, string $scratch, string $body, array $env = []): array
{
    $wrapperPath = perimeterWrapperPath($wrapperName);
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg($wrapperPath)."\n".$body."\n";
    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return perimeterExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * Runs the real wrapper file as a genuine subprocess (BASH_SOURCE[0] == $0,
 * so main() — including its unconditional require_root — really executes).
 * Used only to prove require_root gates a real invocation; every other test
 * below bypasses it via perimeterRunHarness.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function perimeterRunSubprocess(string $wrapperName, array $arguments, array $env): array
{
    $wrapperPath = perimeterWrapperPath($wrapperName);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', $wrapperPath], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start wrapper subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function perimeterBaseEnv(array $overrides = []): array
{
    return array_merge([
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => perimeterCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => perimeterDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => perimeterRegistryPath(),
        'RATEGURU_TARGETS_CLI' => perimeterTargetsCli(),
    ], $overrides);
}

/**
 * Writes a self-contained stub underlying binary at $path that appends its
 * own received arguments and its full environment to $logFile, then exits
 * $exitCode. The log path is baked directly into the stub's own source text
 * (a bash single-quoted literal), never read from an environment variable —
 * exec_underlying's env -i strips every environment variable before the
 * stub ever runs, exactly as production requires, so a stub that expected to
 * receive its own log path via the environment would never find it.
 */
function perimeterWriteStubBin(string $path, string $logFile, int $exitCode = 0): void
{
    $quotedLog = "'".str_replace("'", "'\\''", $logFile)."'";

    $script = <<<SH
#!/usr/bin/env bash
{
    printf 'ARGS:'
    for a in "\$@"; do
        printf ' [%s]' "\$a"
    done
    printf '\\n'
    printf 'ENV_BEGIN\\n'
    env | sort
    printf 'ENV_END\\n'
} >> {$quotedLog}
exit {$exitCode}

SH;

    file_put_contents($path, $script);
    chmod($path, 0o755);
}

function perimeterReadStubLog(string $logFile): string
{
    return trim((string) @file_get_contents($logFile));
}

// =============================================================================
// Wrappers: argument parsing
// =============================================================================

it('requires --target', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects --environment explicitly', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args --environment staging', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--environment is not supported by rateguru-deploy; use --target');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects a duplicate --target', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args --target staging-main --target tits-guru', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects a missing --target value', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args --target', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects an empty --target value', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args --target ""', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects a flag-shaped --target value', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args --target --migrate', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --migrate');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects the equals-joined --target=VALUE and --environment=VALUE forms', function () {
    $scratch = perimeterScratchDir();

    try {
        foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $wrapper) {
            [$exit, $output] = perimeterRunHarness($wrapper, $scratch, 'parse_wrapper_args --target=staging-main', perimeterBaseEnv());

            expect($exit)->not->toBe(0);
            expect($output)->toContain("--target must be given as '--target VALUE', not '--target=VALUE'");

            [$exit, $output] = perimeterRunHarness($wrapper, $scratch, 'parse_wrapper_args --environment=staging', perimeterBaseEnv());

            expect($exit)->not->toBe(0);
            expect($output)->toContain("--environment is not supported by {$wrapper}; use --target");
        }
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects an unknown short (wrapper-only) flag', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'parse_wrapper_args --target staging-main -x', perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown rateguru-deploy flag: -x');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('adds --target exactly once and preserves every operation flag unexamined, in order', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main --release v1.0.0 --artifact /tmp/a.tar.gz --checksum /tmp/a.tar.gz.sha256 --migrate
            printf 'TARGET=%s\n' "${TARGET_ID}"
            printf 'OPS:'
            for a in "${OPERATION_ARGS[@]}"; do printf ' [%s]' "$a"; done
            printf '\n'
            BASH, perimeterBaseEnv());

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TARGET=staging-main');
        expect($output)->toContain('OPS: [--release] [v1.0.0] [--artifact] [/tmp/a.tar.gz] [--checksum] [/tmp/a.tar.gz.sha256] [--migrate]');
        expect(substr_count($output, 'TARGET='))->toBe(1);
    } finally {
        perimeterCleanup($scratch);
    }
});

it('--help prints a target-only usage form and never runs the underlying operation', function () {
    $scratch = perimeterScratchDir();

    try {
        foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $wrapper) {
            [$exit, $output] = perimeterRunHarness($wrapper, $scratch, 'parse_wrapper_args --help', perimeterBaseEnv());

            expect($exit)->toBe(0, $output);
            expect($output)->toContain("{$wrapper} --target TARGET_ID");
            expect($output)->not->toContain('--environment staging');
        }
    } finally {
        perimeterCleanup($scratch);
    }
});

// =============================================================================
// Wrappers: root authorization
// =============================================================================

it('refuses to run as a non-root caller', function () {
    // This process is never real root in CI, so a genuine subprocess
    // invocation proves require_root gates every real invocation, including
    // --help — the reason every other test in this file bypasses it via
    // perimeterRunHarness instead.
    if (posix_getuid() === 0) {
        test()->markTestSkipped('this check requires a non-root test process');
    }

    foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $wrapper) {
        [$exit, $output] = perimeterRunSubprocess($wrapper, ['--target', 'staging-main'], perimeterBaseEnv());

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
    }
});

it('does not source an overridden common when run directly as non-root — the root gate runs first', function () {
    // Proves the literal root-first contract: the inline EUID gate at the
    // top of each wrapper must reject a real non-root invocation before
    // RATEGURU_ALLOW_TEST_OVERRIDES is even consulted and before common is
    // sourced. A "poisoned" common that leaves a detectable marker if
    // sourced makes this observable — perimeterBaseEnv()'s own override
    // (pointing at the real repo common) would otherwise source
    // successfully and only fail later, inside main()'s require_root,
    // which would look identical from the exit code/message alone.
    if (posix_getuid() === 0) {
        test()->markTestSkipped('this check requires a non-root test process');
    }

    $scratch = perimeterScratchDir();
    $poisonedCommon = $scratch.'/poisoned-common';
    $markerFile = $scratch.'/marker';

    file_put_contents($poisonedCommon, "#!/usr/bin/env bash\ntouch ".escapeshellarg($markerFile)."\n");

    try {
        foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $wrapper) {
            [$exit, $output] = perimeterRunSubprocess($wrapper, ['--target', 'staging-main'], [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: '/tmp',
                'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
                'RATEGURU_COMMON_FILE' => $poisonedCommon,
            ]);

            expect($exit)->not->toBe(0);
            expect($output)->toContain('this command must be executed as root');
            expect(file_exists($markerFile))->toBeFalse("{$wrapper} must not source the overridden common before the root check");
        }
    } finally {
        perimeterCleanup($scratch);
    }
});

// =============================================================================
// Wrappers: caller authorization (SUDO_USER vs. registry deploy user)
// =============================================================================

it('permits a direct root invocation (no SUDO_USER) regardless of target', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main
            authorize_caller
            printf 'AUTHORIZED\n'
            BASH, perimeterBaseEnv(['SUDO_USER' => '']));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('AUTHORIZED');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('permits SUDO_USER=root regardless of target', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target tits-guru
            authorize_caller
            printf 'AUTHORIZED\n'
            BASH, perimeterBaseEnv(['SUDO_USER' => 'root']));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('AUTHORIZED');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('permits the exact registered deploy user for the target', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main
            authorize_caller
            printf 'AUTHORIZED\n'
            BASH, perimeterBaseEnv(['SUDO_USER' => 'deploy-rateguru-staging']));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('AUTHORIZED');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects a caller whose SUDO_USER does not match the target deploy user, before any child execution', function () {
    $scratch = perimeterScratchDir();
    $stubLog = $scratch.'/stub.log';
    $stubBin = $scratch.'/bin/stub-deploy';
    perimeterWriteStubBin($stubBin, $stubLog);

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main
            authorize_caller
            require_active_target "${TARGET_ID}"
            exec_underlying
            BASH, perimeterBaseEnv([
            'SUDO_USER' => 'deploy-rateguru-tits-guru',
            'RATEGURU_DEPLOY_BIN' => $stubBin,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('deploy user deploy-rateguru-tits-guru is not authorized for target staging-main');
        expect(perimeterReadStubLog($stubLog))->toBe('', 'the underlying binary must never be invoked for an unauthorized caller');
    } finally {
        perimeterCleanup($scratch);
    }
});

// =============================================================================
// Wrappers: target lifecycle
// =============================================================================

it('accepts an active target', function () {
    $scratch = perimeterScratchDir();

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main
            authorize_caller
            require_active_target "${TARGET_ID}"
            printf 'ACTIVE\n'
            BASH, perimeterBaseEnv(['SUDO_USER' => '']));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('ACTIVE');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rejects a planned target before any child execution', function () {
    $scratch = perimeterScratchDir();
    $stubLog = $scratch.'/stub.log';
    $stubBin = $scratch.'/bin/stub-deploy';
    perimeterWriteStubBin($stubBin, $stubLog);

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target tits-guru
            authorize_caller
            require_active_target "${TARGET_ID}"
            exec_underlying
            BASH, perimeterBaseEnv([
            'SUDO_USER' => '',
            'RATEGURU_DEPLOY_BIN' => $stubBin,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('lifecycle=planned');
        expect(perimeterReadStubLog($stubLog))->toBe('', 'the underlying binary must never be invoked for a planned target');
    } finally {
        perimeterCleanup($scratch);
    }
});

// =============================================================================
// Wrappers: exec into the underlying operation
// =============================================================================

it('execs the underlying binary with the child exit code preserved', function () {
    $scratch = perimeterScratchDir();
    $stubLog = $scratch.'/stub.log';
    $stubBin = $scratch.'/bin/stub-deploy';
    perimeterWriteStubBin($stubBin, $stubLog, 42);

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main --release v1.0.0
            authorize_caller
            require_active_target "${TARGET_ID}"
            exec_underlying
            BASH, perimeterBaseEnv([
            'SUDO_USER' => '',
            'RATEGURU_DEPLOY_BIN' => $stubBin,
        ]));

        expect($exit)->toBe(42, $output);

        $log = perimeterReadStubLog($stubLog);
        expect($log)->toContain('ARGS: [--target] [staging-main] [--release] [v1.0.0]');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('scrubs the production environment before exec — no RATEGURU_* overrides or arbitrary caller variables survive', function () {
    $scratch = perimeterScratchDir();
    $stubLog = $scratch.'/stub.log';
    $stubBin = $scratch.'/bin/stub-deploy';
    perimeterWriteStubBin($stubBin, $stubLog);

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main
            authorize_caller
            require_active_target "${TARGET_ID}"
            exec_underlying
            BASH, perimeterBaseEnv([
            'SUDO_USER' => '',
            'RATEGURU_DEPLOY_BIN' => $stubBin,
            'RATEGURU_BACKUP_BASE' => '/should/not/leak',
            'RATEGURU_RUN_ROOT' => '/should/not/leak',
            'ARBITRARY_CALLER_SECRET' => 'leak-me-not',
        ]));

        expect($exit)->toBe(0, $output);

        $log = perimeterReadStubLog($stubLog);
        expect(preg_match('/ENV_BEGIN\n(.*)\nENV_END/s', $log, $matches))->toBe(1, $log);
        $envLines = array_values(array_filter(explode("\n", $matches[1])));
        $envNames = array_map(fn (string $line): string => explode('=', $line, 2)[0], $envLines);
        sort($envNames);

        // PWD, SHLVL and _ are not part of exec_underlying's explicit set —
        // they are injected by bash itself the moment the stub's own
        // #!/usr/bin/env bash shebang starts a fresh shell, regardless of
        // what env -i handed it, and carry no application data. Everything
        // else must be exactly the four explicit production variables.
        $unexplained = array_values(array_diff($envNames, ['HOME', 'LOGNAME', 'PATH', 'PWD', 'SHLVL', 'USER', '_']));
        expect($unexplained)->toBe([], 'unexpected environment variable(s) survived exec: '.implode(', ', $unexplained));
        expect($envNames)->toContain('HOME')->toContain('LOGNAME')->toContain('PATH')->toContain('USER');

        expect($log)->toContain('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        expect($log)->toContain('HOME=/root');
        expect($log)->toContain('USER=root');
        expect($log)->toContain('LOGNAME=root');
        expect($log)->not->toContain('RATEGURU_');
        expect($log)->not->toContain('leak-me-not');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('never uses eval or bash -c anywhere in any wrapper', function () {
    foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $wrapper) {
        $source = File::get(perimeterWrapperPath($wrapper));

        foreach (preg_split('/\R/', $source) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            expect($trimmed)->not->toMatch('/(^|[;&|]\s*)eval\b/', "{$wrapper} must never use eval: {$line}");
            expect($trimmed)->not->toContain('bash -c', "{$wrapper} must never use bash -c: {$line}");
        }
    }
});

it('test overrides are gated behind RATEGURU_ALLOW_TEST_OVERRIDES', function () {
    // This test's proof depends on /home/www/rateguru/bin/common genuinely
    // being absent on the machine running it — verify that precondition
    // explicitly rather than assuming it, so a host that happens to have
    // this path (e.g. a real staging VPS) gets a clear skip instead of a
    // confusing pass or failure.
    if (File::exists('/home/www/rateguru/bin/common')) {
        test()->markTestSkipped('/home/www/rateguru/bin/common exists on this host — the hardcoded-default-path proof below cannot be exercised');
    }

    $scratch = perimeterScratchDir();

    try {
        // RATEGURU_COMMON_FILE is set, but RATEGURU_ALLOW_TEST_OVERRIDES is
        // deliberately omitted — the override must be ignored and the
        // wrapper must fall back to its hardcoded production common path
        // (which does not exist on this machine), never the repo's own
        // common this override points at.
        [$exit, $output] = perimeterRunHarness('rateguru-deploy', $scratch, 'true', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => perimeterCommonFile(),
        ]);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('/home/www/rateguru/bin/common: No such file or directory');
    } finally {
        perimeterCleanup($scratch);
    }
});

// =============================================================================
// Wrappers: each underlying operation binary and flag set
// =============================================================================

it('rateguru-rollback execs into the rollback binary preserving --release/--previous', function () {
    $scratch = perimeterScratchDir();
    $stubLog = $scratch.'/stub.log';
    $stubBin = $scratch.'/bin/stub-rollback';
    perimeterWriteStubBin($stubBin, $stubLog);

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-rollback', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main --previous
            authorize_caller
            require_active_target "${TARGET_ID}"
            exec_underlying
            BASH, perimeterBaseEnv([
            'SUDO_USER' => '',
            'RATEGURU_ROLLBACK_BIN' => $stubBin,
        ]));

        expect($exit)->toBe(0, $output);
        expect(perimeterReadStubLog($stubLog))->toContain('ARGS: [--target] [staging-main] [--previous]');
    } finally {
        perimeterCleanup($scratch);
    }
});

it('rateguru-cleanup execs into the cleanup binary preserving --keep/--dry-run/--apply', function () {
    $scratch = perimeterScratchDir();
    $stubLog = $scratch.'/stub.log';
    $stubBin = $scratch.'/bin/stub-cleanup';
    perimeterWriteStubBin($stubBin, $stubLog);

    try {
        [$exit, $output] = perimeterRunHarness('rateguru-cleanup', $scratch, <<<'BASH'
            parse_wrapper_args --target staging-main --keep 3 --apply
            authorize_caller
            require_active_target "${TARGET_ID}"
            exec_underlying
            BASH, perimeterBaseEnv([
            'SUDO_USER' => '',
            'RATEGURU_CLEANUP_BIN' => $stubBin,
        ]));

        expect($exit)->toBe(0, $output);
        expect(perimeterReadStubLog($stubLog))->toContain('ARGS: [--target] [staging-main] [--keep] [3] [--apply]');
    } finally {
        perimeterCleanup($scratch);
    }
});

// =============================================================================
// Sudoers
// =============================================================================

it('provides a syntactically valid candidate sudoers file', function () {
    exec('command -v visudo >/dev/null 2>&1', $probeOutput, $probeExit);

    if ($probeExit !== 0) {
        test()->markTestSkipped('visudo is not available on this host');
    }

    $path = base_path('infrastructure/config/sudoers/rateguru-deploy');

    exec('visudo -cf '.escapeshellarg($path).' 2>&1', $output, $exit);

    expect($exit)->toBe(0, "visudo -cf failed:\n".implode("\n", $output));
});

it('grants the staging deploy user access to the three generic wrappers only', function () {
    $source = File::get(base_path('infrastructure/config/sudoers/rateguru-deploy'));

    expect($source)
        ->toContain('deploy-rateguru-staging ALL=(root) NOPASSWD:')
        ->toContain('/usr/local/sbin/rateguru-deploy')
        ->toContain('/usr/local/sbin/rateguru-rollback')
        ->toContain('/usr/local/sbin/rateguru-cleanup')
        ->not->toContain('deploy-rateguru-tits-guru');
});

it('preserves the temporary legacy per-environment sudoers rules, marked deprecated', function () {
    $source = File::get(base_path('infrastructure/config/sudoers/rateguru-deploy'));

    expect($source)
        ->toContain('/usr/local/sbin/rateguru-staging-deploy')
        ->toContain('/usr/local/sbin/rateguru-staging-rollback')
        ->toContain('/usr/local/sbin/rateguru-staging-cleanup')
        ->toContain('/usr/local/sbin/rateguru-production-deploy')
        ->toContain('/usr/local/sbin/rateguru-production-rollback')
        ->toContain('/usr/local/sbin/rateguru-production-cleanup')
        ->toContain('Temporary legacy compatibility')
        ->toContain('do not add a new caller');
});

// =============================================================================
// Backup cron
// =============================================================================

it('keeps cron schedules and log paths unchanged while switching all three jobs to --target staging-main', function () {
    $source = File::get(base_path('infrastructure/config/cron/rateguru-backups'));

    expect($source)
        ->toContain('30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1')
        ->toContain('10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1')
        ->toContain('40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1')
        ->not->toContain('--environment');

    // Exactly three operational (schedule-prefixed) lines.
    $operationalLines = array_values(array_filter(
        preg_split('/\R/', $source),
        fn (string $line): bool => (bool) preg_match('/^[0-9*]+[[:space:]]/', $line),
    ));
    expect($operationalLines)->toHaveCount(3);
});

it('documents the real five-step nightly pipeline in the cron comment', function () {
    $source = File::get(base_path('infrastructure/config/cron/rateguru-backups'));

    expect($source)
        ->toContain('local backup')
        ->toContain('local restore-test')
        ->toContain('B2 upload')
        ->toContain('offsite retention apply')
        ->toContain('offsite restore-test');
});

it('leaves the Laravel scheduler cron entry untouched', function () {
    $source = File::get(base_path('infrastructure/config/cron/rateguru-staging-scheduler'));

    expect($source)
        ->toContain('rateguru-staging cd /home/www/rateguru/staging/current')
        ->not->toContain('--target')
        ->not->toContain('--environment');
});

// =============================================================================
// GitHub composite action
// =============================================================================

function perimeterActionYaml(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/deploy-rateguru/action.yml')));
}

it('requires a deployment-target input on the composite action', function () {
    $action = perimeterActionYaml();

    expect(data_get($action, 'inputs.deployment-target.required'))->toBeTrue();
});

it('validates the deployment target locally before SSH, rejecting every unsafe shape', function () {
    $action = perimeterActionYaml();
    $run = data_get(collect(data_get($action, 'runs.steps'))->keyBy('name')->get('Validate deployment inputs'), 'run');

    expect(preg_match("/target_regex='([^']+)'/", $run, $matches))->toBe(1, 'could not find target_regex in the action');
    $regex = $matches[1];

    expect($run)->toContain('Invalid deployment target');

    $validCases = ['staging-main', 'tits-guru', 'a', 'a1-b2'];
    $invalidCases = [
        '' => 'empty',
        'Staging-Main' => 'uppercase',
        'staging/main' => 'slash',
        'staging main' => 'whitespace',
        'staging;main' => 'shell metacharacter',
        '-staging-main' => 'flag-shaped',
        '--target' => 'flag-shaped',
    ];

    foreach ($validCases as $case) {
        $script = 'target_regex='.escapeshellarg($regex).'; [[ '.escapeshellarg($case).' =~ ${target_regex} ]]';
        exec('bash -c '.escapeshellarg($script), $output, $exit);
        expect($exit)->toBe(0, "expected '{$case}' to be accepted by the target regex");
    }

    foreach ($invalidCases as $case => $reason) {
        $script = 'target_regex='.escapeshellarg($regex).'; [[ '.escapeshellarg($case).' =~ ${target_regex} ]]';
        exec('bash -c '.escapeshellarg($script), $output, $exit);
        expect($exit)->not->toBe(0, "expected '{$case}' ({$reason}) to be rejected by the target regex");
    }
});

it('passes the deployment target to the wrapper with safe quoting, and never emits secrets in the summary log', function () {
    $action = perimeterActionYaml();
    $deploySteps = collect(data_get($action, 'runs.steps'))->keyBy('name');
    $run = data_get($deploySteps->get('Deploy release'), 'run');

    expect($run)
        ->toContain("'sudo -n %q --target %q --release %q --artifact %q --checksum %q'")
        ->toContain('"${DEPLOY_WRAPPER}"')
        ->toContain('"${DEPLOYMENT_TARGET}"')
        ->toContain('remote_command+=" --migrate"')
        ->toContain('echo "Deployment target: ${DEPLOYMENT_TARGET}"')
        ->not->toContain('echo "${RATEGURU_SSH_KEY_PATH}"')
        ->not->toContain('cat "${RATEGURU_SSH_KEY_PATH}"')
        ->not->toContain('--environment');

    expect(data_get($deploySteps->get('Deploy release'), 'env.DEPLOYMENT_TARGET'))
        ->toBe('${{ inputs.deployment-target }}');
});

it('never references the deprecated legacy staging wrapper', function () {
    $source = File::get(base_path('.github/actions/deploy-rateguru/action.yml'));

    expect($source)->not->toContain('rateguru-staging-deploy');
});

it('describes the transport inputs as target-specific, not environment-specific', function () {
    $action = perimeterActionYaml();

    expect(data_get($action, 'inputs.deploy-incoming.description'))->toContain('Target-specific');
    expect(data_get($action, 'inputs.deploy-wrapper.description'))->toContain('Target-specific');
    expect(data_get($action, 'inputs.deploy-root.description'))->toContain('Target');
});

// =============================================================================
// Staging workflow
// =============================================================================

it('passes deployment-target: staging-main explicitly to the deploy action', function () {
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    $deploySteps = collect(data_get($workflow, 'jobs.deploy.steps'))->keyBy('name');

    expect(data_get($deploySteps->get('Deploy to staging'), 'with.deployment-target'))->toBe('staging-main');
});

it('keeps the GitHub environment, concurrency group and dispatch/build behaviour unchanged by the target migration', function () {
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));

    expect(data_get($workflow, 'jobs.deploy.environment'))->toBe('staging')
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'name'))->toBe('Deploy to staging')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.ref.default'))->toBe('develop');
});

it('never references the legacy --environment staging selector or the deprecated wrapper anywhere in the workflow', function () {
    $source = File::get(base_path('.github/workflows/deploy-staging.yml'));

    // Not a plain toContain('rateguru-staging-deploy'): the unrelated,
    // unchanged concurrency group "rateguru-staging-deployment" contains
    // that exact substring, so the deprecated wrapper's full installed path
    // is checked instead.
    expect($source)
        ->not->toContain('--environment staging')
        ->not->toContain('/usr/local/sbin/rateguru-staging-deploy');
});
