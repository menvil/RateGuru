<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * the deployment observability work: .github/actions/record-rateguru-deployment — the single place a
 * successful RateGuru deployment state transition is recorded, in Sentry and in
 * Laravel Nightwatch alike.
 *
 * A deploy and a rollback are the same event to observability: an immutable
 * release became the release this target serves, at this moment. These tests
 * pin the two properties that make that safe — the markers can only be produced
 * AFTER the application state transition succeeded, and no observability
 * failure can ever turn a healthy deployment into a failed one.
 */

/** @return array<string, mixed> */
function rrdAction(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/record-rateguru-deployment/action.yml')));
}

function rrdActionSource(): string
{
    return File::get(base_path('.github/actions/record-rateguru-deployment/action.yml'));
}

/** @return array<string, mixed> */
function rrdWorkflow(string $name): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/'.$name)));
}

/** @return array<int, array<string, mixed>> */
function rrdSteps(array $node): array
{
    return $node['steps'] ?? [];
}

/**
 * The inputs one caller passes: every credential present, so whatever the
 * action leaves out it leaves out by its own decision. The deploy key is a
 * real one, so the action's own key check passes.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function rrdInputs(string $scratch, array $overrides = []): array
{
    exec('ssh-keygen -t ed25519 -N "" -q -C deploy@test -f '.escapeshellarg($scratch.'/fixture-key').' 2>&1', $ignored, $generated);
    expect($generated)->toBe(0);

    return array_merge([
        'deployment-target' => 'staging-main',
        'environment' => 'staging',
        'release-id' => 'v1.4.0-20261029-120000-abcdef1',
        'source-sha' => 'abcdef1',
        'sentry-auth-token' => 'sentry-token-for-tests',
        'sentry-org' => 'rateguru',
        'sentry-project' => 'rateguru',
        'deploy-host' => '203.0.113.10',
        'deploy-port' => '22',
        'deploy-user' => 'deploy-rateguru-staging',
        'ssh-private-key' => (string) file_get_contents($scratch.'/fixture-key'),
        'known-hosts' => '203.0.113.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKnownHostEntryForTestsOnly',
    ], $overrides);
}

/** @return array<string, string> */
function rrdProductionInputs(string $scratch): array
{
    return rrdInputs($scratch, [
        'deployment-target' => 'tits-guru',
        'environment' => 'production',
        'deploy-user' => 'deploy-rateguru-tits-guru',
    ]);
}

/**
 * The value of one expression in the action, the way the runner reads it —
 * for the handful of forms the action uses. Anything else fails the test
 * rather than being guessed at.
 *
 * @param  array<string, string>  $inputs
 * @param  array{outputs: array<string, array<string, string>>, outcomes: array<string, string>, failed: bool}  $state
 */
function rrdEvaluate(string $expression, array $inputs, array $state): string|bool
{
    $expression = trim($expression);

    if (str_contains($expression, '&&')) {
        return collect(explode('&&', $expression))
            ->every(static fn (string $part): bool => rrdTruthy(rrdEvaluate($part, $inputs, $state)));
    }

    foreach (['==', '!='] as $operator) {
        if (str_contains($expression, $operator)) {
            [$left, $right] = array_map(
                static fn (string $side): string => (string) rrdEvaluate($side, $inputs, $state),
                explode($operator, $expression, 2),
            );

            return $operator === '==' ? $left === $right : $left !== $right;
        }
    }

    return match (true) {
        $expression === 'always()' => true,
        $expression === 'success()' => ! $state['failed'],
        (bool) preg_match("/^'([^']*)'$/", $expression, $literal) => $literal[1],
        (bool) preg_match('/^inputs\.([a-z0-9-]+)$/', $expression, $input) => $inputs[$input[1]] ?? '',
        (bool) preg_match('/^steps\.([a-z]+)\.outputs\.([a-z_]+)$/', $expression, $output) => $state['outputs'][$output[1]][$output[2]] ?? '',
        (bool) preg_match('/^steps\.([a-z]+)\.outcome$/', $expression, $outcome) => $state['outcomes'][$outcome[1]] ?? '',
        $expression === 'github.server_url' => 'https://github.com',
        $expression === 'github.repository' => 'menvil/RateGuru',
        $expression === 'github.run_id' => '1001',
        default => throw new RuntimeException("the action uses an expression these tests cannot read: {$expression}"),
    };
}

function rrdTruthy(string|bool $value): bool
{
    return is_bool($value) ? $value : $value !== '';
}

/**
 * @param  array<string, string>  $inputs
 * @param  array{outputs: array<string, array<string, string>>, outcomes: array<string, string>, failed: bool}  $state
 */
function rrdInterpolate(string $value, array $inputs, array $state): string
{
    return (string) preg_replace_callback('/\$\{\{(.*?)\}\}/', static function (array $match) use ($inputs, $state): string {
        $result = rrdEvaluate($match[1], $inputs, $state);

        return is_bool($result) ? ($result ? 'true' : 'false') : $result;
    }, $value);
}

/** @return array<string, string> */
function rrdKeyValues(string $path): array
{
    $values = [];

    foreach (preg_split('/\R/', (string) @file_get_contents($path)) as $line) {
        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $values[$name] = $value;
        }
    }

    return $values;
}

/**
 * One run of the action as the runner makes it: each step's `if:` decided
 * from the outputs and outcomes before it — with the runner's implicit
 * success() where it names no status function — each `run:` executed for real
 * through runActionStep against the stub `ssh`, and its outputs and GITHUB_ENV
 * carried to the steps after it. The nested Sentry action is not executed —
 * sentry-release has tests of its own — only recorded with the inputs it is
 * given, reporting that it recorded.
 *
 * @param  array<string, string>  $inputs
 * @param  array<string, string>  $stub  RGTEST_SSH_* settings for the stub ssh
 * @return array{ran: list<string>, outputs: array<string, array<string, string>>, outcomes: array<string, string>, failed: bool, sentry: array<string, string>|null, log: string, summary: string, ssh: string, env: array<string, string>}
 */
function rrdRun(string $scratch, array $inputs, array $stub = []): array
{
    $action = rrdAction();
    $inputs = array_merge(array_map(static fn (array $input): string => (string) ($input['default'] ?? ''), $action['inputs']), $inputs);

    $state = ['outputs' => [], 'outcomes' => [], 'failed' => false];
    $ran = [];
    $sentry = null;
    $log = '';
    $githubEnv = [];

    foreach ($action['runs']['steps'] as $step) {
        $id = $step['id'] ?? null;
        $condition = trim((string) preg_replace('/^\$\{\{(.*)\}\}$/s', '$1', (string) ($step['if'] ?? 'success()')));
        $implicitSuccess = ! preg_match('/\b(always|success|failure|cancelled)\(\)/', $condition);

        if (! rrdTruthy(rrdEvaluate($condition, $inputs, $state)) || ($implicitSuccess && $state['failed'])) {
            if ($id !== null) {
                $state['outcomes'][$id] = 'skipped';
            }

            continue;
        }

        $ran[] = $step['name'];

        if (isset($step['uses'])) {
            $sentry = array_map(static fn (mixed $value): string => rrdInterpolate((string) $value, $inputs, $state), $step['with'] ?? []);
            $outputs = ['recorded' => 'true'];
            $outcome = 'success';
        } else {
            file_put_contents($scratch.'/github-output', '');

            $env = array_merge(
                $githubEnv,
                array_map(static fn (mixed $value): string => rrdInterpolate((string) $value, $inputs, $state), $step['env'] ?? []),
                ['GITHUB_ENV' => $scratch.'/github-env'],
                $stub,
            );

            $result = runActionStep('.github/actions/record-rateguru-deployment/action.yml', $step['name'], actionStepEnv($scratch, $env));

            $log .= $result['output'];
            $outputs = rrdKeyValues($scratch.'/github-output');
            $githubEnv = rrdKeyValues($scratch.'/github-env');
            $outcome = $result['exit'] === 0 ? 'success' : 'failure';

            if ($outcome === 'failure' && ($step['continue-on-error'] ?? false) !== true) {
                $state['failed'] = true;
            }
        }

        if ($id !== null) {
            $state['outputs'][$id] = $outputs;
            $state['outcomes'][$id] = $outcome;
        }
    }

    return [
        'ran' => $ran,
        'outputs' => $state['outputs'],
        'outcomes' => $state['outcomes'],
        'failed' => $state['failed'],
        'sentry' => $sentry,
        'log' => $log,
        'summary' => (string) @file_get_contents($scratch.'/github-step-summary'),
        'ssh' => (string) @file_get_contents($scratch.'/ssh.log'),
        'env' => $githubEnv,
    ];
}

// =============================================================================
// One shared implementation
// =============================================================================

it('is the one place a successful deployment is recorded', function () {
    expect(File::exists(base_path('.github/actions/record-rateguru-deployment/action.yml')))->toBeTrue();

    // Every caller uses the shared action; none reaches for the Sentry action
    // directly any more, which is what stops deploy, release and rollback from
    // growing three different observability implementations.
    $callers = [
        '.github/workflows/deploy-staging.yml',
        '.github/workflows/release.yml',
        '.github/actions/rollback-rateguru/action.yml',
    ];

    foreach ($callers as $caller) {
        $source = File::get(base_path($caller));

        // toContain is variadic in Pest — a second argument would be read as
        // another needle, not a message, so the diagnostic lives in a comment.
        expect($source)->toContain('./.github/actions/record-rateguru-deployment');
        expect($source)->not->toMatch('/^\s*uses:\s*\.\/\.github\/actions\/sentry-release\s*$/m',
            "{$caller} must not call the Sentry action directly any more");
    }

    // And the shared action reuses the existing Sentry implementation rather
    // than duplicating its logic.
    expect(rrdActionSource())->toContain('./.github/actions/sentry-release');
    expect(rrdActionSource())->not->toContain('getsentry/action-release');
});

it('takes the verified release and its real source commit, never a requested one', function () {
    $inputs = rrdAction()['inputs'];

    foreach (['deployment-target', 'environment', 'release-id', 'source-sha'] as $required) {
        expect($inputs)->toHaveKey($required);
        expect($inputs[$required]['required'])->toBeTrue("{$required} must be required");
    }
});

// =============================================================================
// Ordering: markers only follow a successful state transition
// =============================================================================

it('records the staging deployment only in a job that needs a successful deploy', function () {
    $workflow = rrdWorkflow('deploy-staging.yml');

    expect($workflow['jobs'])->toHaveKey('observability');
    expect($workflow['jobs']['observability']['needs'])->toContain('deploy');

    // The deploy job is what extracts the artifact, switches current, reloads
    // PHP-FPM, health-checks and verifies the active release against
    // release.json. A failed deployment therefore produces no marker at all.
    $names = array_column(rrdSteps($workflow['jobs']['observability']), 'uses');
    expect($names)->toContain('./.github/actions/record-rateguru-deployment');
});

it('records each release deployment after its own deploy step, inside the same job', function () {
    $workflow = rrdWorkflow('release.yml');

    foreach (['deploy-staging' => 'staging-main', 'deploy-production' => 'tits-guru'] as $job => $target) {
        $steps = rrdSteps($workflow['jobs'][$job]);
        $uses = array_map(static fn (array $step): string => $step['uses'] ?? '', $steps);

        $deployIndex = array_search('./.github/actions/deploy-rateguru', $uses, true);
        $recordIndex = array_search('./.github/actions/record-rateguru-deployment', $uses, true);

        expect($deployIndex)->not->toBeFalse("{$job} must deploy");
        expect($recordIndex)->not->toBeFalse("{$job} must record the deployment");
        expect($recordIndex)->toBeGreaterThan($deployIndex,
            "{$job} must record observability only after the deployment step");

        expect($steps[$recordIndex]['with']['deployment-target'])->toBe($target);
    }
});

it('records a rollback only after the restored release was read back off the server', function () {
    $action = Yaml::parse(File::get(base_path('.github/actions/rollback-rateguru/action.yml')));
    $steps = $action['runs']['steps'];

    $ids = array_map(static fn (array $step): string => $step['id'] ?? '', $steps);
    $uses = array_map(static fn (array $step): string => $step['uses'] ?? '', $steps);

    $rollbackIndex = array_search('rollback', $ids, true);
    $activeIndex = array_search('active', $ids, true);
    $recordIndex = array_search('./.github/actions/record-rateguru-deployment', $uses, true);

    expect($rollbackIndex)->toBeLessThan($activeIndex);
    expect($activeIndex)->toBeLessThan($recordIndex);

    // And it is skipped outright unless BOTH identities were resolved.
    $condition = $steps[$recordIndex]['if'];
    expect($condition)->toContain("steps.active.outputs.release_id != ''");
    expect($condition)->toContain("steps.active.outputs.source_sha != ''");
});

it('resolves the rollback release and its commit from the server, not from the request', function () {
    $source = File::get(base_path('.github/actions/rollback-rateguru/action.yml'));

    // `mode=previous` carries no release at all, so both identities have to
    // come from the target: the current symlink for the release, and that
    // release's own release.json for the commit.
    expect($source)->toContain('basename "$(readlink -f %q)"');
    expect($source)->toContain("jq -r '.source_sha // empty'");
    expect($source)->toContain("jq -r '.release // empty'");

    // The two must agree before either is published.
    expect($source)->toContain('release.json names ${metadata_release:-nothing}, but current resolves to ${active_release}');

    // A rollback records the existing immutable release; there is no synthetic
    // release identity anywhere in the pipeline.
    expect($source)->not->toMatch('/rollback-\$\{/');
    expect($source)->not->toContain('rollback-${GITHUB_RUN_ID}');
});

it('validates identity before any marker is produced', function () {
    $source = rrdActionSource();

    // Release ID, source SHA, target and environment are all matched against
    // closed patterns in the very first step, before any network call.
    expect($source)->toContain("release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'");
    expect($source)->toContain('[[ "${SOURCE_SHA}" =~ ^[0-9a-f]{7,40}$ ]]');
    expect($source)->toContain("target_regex='^[a-z0-9]+(-[a-z0-9]+)*\$'");

    $steps = rrdAction()['runs']['steps'];
    expect($steps[0]['id'])->toBe('preflight');
});

// =============================================================================
// Fail-open
// =============================================================================

it('cannot fail a healthy deployment when Nightwatch is unreachable', function () {
    $steps = rrdAction()['runs']['steps'];

    $nightwatch = collect($steps)->firstWhere('id', 'nightwatch');

    expect($nightwatch)->not->toBeNull();
    expect($nightwatch['continue-on-error'])->toBeTrue(
        'the deployment already succeeded; a Nightwatch outage is an observability gap, never a failed deployment');
});

it('cannot fail a healthy deployment when Sentry is unreachable', function () {
    $sentry = Yaml::parse(File::get(base_path('.github/actions/sentry-release/action.yml')));
    $record = collect($sentry['runs']['steps'])->firstWhere('id', 'sentry');

    expect($record['continue-on-error'])->toBeTrue();
});

it('skips the Nightwatch marker cleanly when the environment has no SSH material', function () {
    $source = rrdActionSource();

    expect($source)->toContain('nightwatch_configured=false');
    expect($source)->toContain('skipping the Nightwatch deployment marker');

    $steps = rrdAction()['runs']['steps'];
    $nightwatch = collect($steps)->firstWhere('id', 'nightwatch');

    expect($nightwatch['if'])->toContain("steps.preflight.outputs.nightwatch_configured == 'true'");
});

it('reports honestly which markers were and were not recorded', function () {
    $source = rrdActionSource();

    expect($source)->toContain('Nightwatch deployment marker FAILED');
    expect($source)->toContain('this is an observability gap and must not be rolled back for');
    expect($source)->toContain('- Sentry recorded:');
    expect($source)->toContain('- Nightwatch recorded:');
});

// =============================================================================
// Trust boundary
// =============================================================================

it('never runs application code on the runner and never sends credentials into a build', function () {
    $source = rrdActionSource();

    // The marker is produced on the server by the runtime user, inside the
    // release that is actually serving — never by booting a staging ref on a
    // GitHub runner with observability credentials attached.
    expect($source)->toContain('sudo -n /usr/local/sbin/rateguru-nightwatch-deployment');

    // Not one artisan invocation, and not one observability credential, on the
    // runner: the marker is produced by the target's own runtime user, inside
    // the release that is actually serving.
    $executable = executableSourceLines($source);

    expect($executable)->not->toContain('artisan');
    expect($executable)->not->toContain('NIGHTWATCH_TOKEN');
    expect($executable)->not->toContain('SENTRY_AUTH_TOKEN=');

    // The build job that compiles an arbitrary staging ref holds no
    // environment and therefore no observability secret.
    $deployStaging = rrdWorkflow('deploy-staging.yml');
    expect($deployStaging['jobs']['build'])->not->toHaveKey('environment');
});

it('builds the remote command as a fixed argv with no arbitrary shell', function () {
    $source = rrdActionSource();

    expect($source)->toContain('remote_command=(');
    expect($source)->toContain('"${remote_command[@]@Q}"');

    expect($source)->not->toMatch('/\beval\b/');
    expect($source)->not->toMatch('/StrictHostKeyChecking=no/');
    expect($source)->toContain('StrictHostKeyChecking=yes');
    expect($source)->toContain('BatchMode=yes');
    expect($source)->toContain('IdentitiesOnly=yes');
});

it('uses the ordinary deploy credential, in files its callers cannot clobber', function () {
    $source = rrdActionSource();

    // Recording a marker is a routine post-deployment step, not host
    // administration, so it deliberately does NOT reach for the privileged
    // bootstrap credential.
    expect($source)->not->toContain('BOOTSTRAP_SSH_KEY');

    // Distinct env and file names from the deploy and rollback actions: this
    // action runs inside jobs that already hold their own SSH material, and a
    // shared name would make the caller's cleanup delete the wrong file.
    expect($source)->toContain('RATEGURU_OBSERVABILITY_SSH_KEY_PATH');
    expect($source)->toContain('rateguru_observability_key');
    expect($source)->not->toContain('RATEGURU_SSH_KEY_PATH');

    $cleanup = collect(rrdAction()['runs']['steps'])
        ->firstWhere('name', 'Remove temporary observability SSH material');

    expect($cleanup['if'])->toBe('${{ always() }}');
});

// =============================================================================
// Which targets have a Nightwatch marker at all
// =============================================================================

it('records the Nightwatch marker on staging-main through the marker wrapper', function () {
    $scratch = makeScratchDir('record-deployment');

    try {
        $run = rrdRun($scratch, rrdInputs($scratch));

        expect($run['failed'])->toBeFalse($run['log']);
        expect($run['outputs']['preflight'])->toMatchArray(['nightwatch_supported' => 'true', 'nightwatch_configured' => 'true']);
        expect($run['ran'])->toContain('Configure observability SSH', 'Record Nightwatch deployment marker');
        expect($run['outcomes']['nightwatch'])->toBe('success');

        expect($run['ssh'])->toContain('deploy-rateguru-staging@203.0.113.10');
        expect($run['ssh'])->toContain("'sudo' '-n' '/usr/local/sbin/rateguru-nightwatch-deployment' '--target' 'staging-main' '--release' 'v1.4.0-20261029-120000-abcdef1' '--source-sha' 'abcdef1'");

        expect($run['outputs']['report']['recorded'])->toBe('true');
        expect($run['summary'])->toContain('- Nightwatch recorded: `true`', '- Nightwatch: recorded');

        // The material it wrote is gone again.
        expect(file_exists($scratch.'/rateguru_observability_key'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('never asks a target without Nightwatch for a marker, though its SSH material is all there', function () {
    $scratch = makeScratchDir('record-deployment');

    try {
        $run = rrdRun($scratch, rrdProductionInputs($scratch));

        expect($run['failed'])->toBeFalse($run['log']);
        expect($run['outputs']['preflight'])->toBe(['nightwatch_supported' => 'false']);

        // Neither step that touches the host runs: no key or known_hosts file
        // is written, nothing connects, and no sudo is asked for.
        expect($run['ran'])->not->toContain('Configure observability SSH')
            ->and($run['ran'])->not->toContain('Record Nightwatch deployment marker');
        expect($run['outcomes'])->toMatchArray(['ssh' => 'skipped', 'nightwatch' => 'skipped']);

        expect($run['ssh'])->toBe('');
        expect($run['env'])->toBe([]);
        expect(file_exists($scratch.'/rateguru_observability_key'))->toBeFalse();
        expect(file_exists($scratch.'/rateguru_observability_known_hosts'))->toBeFalse();
        expect($run['log'])->not->toContain('sudo');
    } finally {
        removeScratchDir($scratch);
    }
});

it('records Sentry for a target without Nightwatch exactly as for any other', function () {
    // Nothing the Nightwatch decision adds conditions the Sentry step.
    $sentryStep = collect(rrdAction()['runs']['steps'])->firstWhere('id', 'sentry');
    expect($sentryStep)->not->toHaveKey('if');

    $scratch = makeScratchDir('record-deployment');

    try {
        $run = rrdRun($scratch, rrdProductionInputs($scratch));

        expect($run['ran'])->toContain('Record Sentry release and deployment marker');
        expect($run['sentry'])->toBe([
            'sentry-auth-token' => 'sentry-token-for-tests',
            'sentry-org' => 'rateguru',
            'sentry-project' => 'rateguru',
            'environment' => 'production',
            'release-id' => 'v1.4.0-20261029-120000-abcdef1',
        ]);
        expect($run['summary'])->toContain('- Sentry recorded: `true`');
    } finally {
        removeScratchDir($scratch);
    }
});

it('reports a target without Nightwatch as a deliberate skip, never as a failure', function () {
    $scratch = makeScratchDir('record-deployment');

    try {
        $run = rrdRun($scratch, rrdProductionInputs($scratch));

        expect($run['outputs']['report']['recorded'])->toBe('false');
        expect($run['log'])->toContain('Nightwatch deployment marker skipped: not supported for target tits-guru.');

        expect($run['summary'])->toContain(
            '- Target: `tits-guru`',
            '- Environment: `production`',
            '- Sentry recorded: `true`',
            '- Nightwatch recorded: `false`',
            '- Nightwatch: skipped (not enabled for this target)',
        );

        // Neither of the other two reasons for no marker.
        expect($run['summary'].$run['log'])->not->toContain('FAILED')
            ->and($run['summary'].$run['log'])->not->toContain('SSH material');
    } finally {
        removeScratchDir($scratch);
    }
});

it('skips staging-main without SSH material as missing configuration, not as policy', function () {
    $scratch = makeScratchDir('record-deployment');

    try {
        $run = rrdRun($scratch, rrdInputs($scratch, ['deploy-host' => '']));

        expect($run['failed'])->toBeFalse($run['log']);
        expect($run['outputs']['preflight'])->toBe(['nightwatch_supported' => 'true', 'nightwatch_configured' => 'false']);
        expect($run['outcomes'])->toMatchArray(['ssh' => 'skipped', 'nightwatch' => 'skipped']);
        expect($run['ssh'])->toBe('');

        expect($run['outputs']['report']['recorded'])->toBe('false');
        expect($run['summary'])->toContain(
            '- Nightwatch: skipped (no deployment SSH material)',
            'the staging environment has no deployment SSH material',
        );
        expect($run['summary'])->not->toContain('not enabled for this target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('fails open on staging-main when the Nightwatch marker itself fails', function () {
    $scratch = makeScratchDir('record-deployment');

    try {
        $run = rrdRun($scratch, rrdInputs($scratch), ['RGTEST_SSH_EXIT' => '1', 'RGTEST_SSH_STDERR' => 'sudo: a password is required']);

        // The marker was attempted and failed, and the action still succeeds.
        expect($run['outcomes']['nightwatch'])->toBe('failure');
        expect($run['failed'])->toBeFalse();
        expect($run['ran'])->toContain('Report observability outcome', 'Remove temporary observability SSH material');

        expect($run['outputs']['report']['recorded'])->toBe('false');
        expect($run['summary'])->toContain(
            '- Nightwatch: failed (observability gap only)',
            'Nightwatch deployment marker FAILED',
            'this is an observability gap and must not be rolled back for',
        );
        expect($run['summary'])->not->toContain('not enabled for this target');
        expect(file_exists($scratch.'/rateguru_observability_key'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('asks for a Nightwatch marker on exactly the targets scripts/common names a Nightwatch program for', function () {
    // The action keeps its own copy of the allowlist — a GitHub runner cannot
    // source the server's scripts — so the copy is held to the original: every
    // registered target, and some that are not, decided the same way by both,
    // and by the target alone whatever its environment class.
    $program = shellFunctionBody(File::get(base_path('infrastructure/scripts/common')), 'target_nightwatch_program')."\n}\n";
    $registered = array_keys(json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets']);

    $scratch = makeScratchDir('record-deployment');
    $decisions = [];

    try {
        foreach ([...$registered, 'food-guru', 'animals-guru', 'staging', 'staging-main-2'] as $target) {
            exec('bash -c '.escapeshellarg($program.'target_nightwatch_program "$1"').' _ '.escapeshellarg($target).' >/dev/null 2>&1', $ignored, $status);
            $hasProgram = $status === 0;
            $decisions[] = $hasProgram;

            foreach (['staging', 'production'] as $environment) {
                file_put_contents($scratch.'/github-output', '');

                $result = runActionStep('.github/actions/record-rateguru-deployment/action.yml', 'Validate deployment identity', actionStepEnv($scratch, [
                    'DEPLOYMENT_TARGET' => $target,
                    'ENVIRONMENT' => $environment,
                    'RELEASE_ID' => 'v1.4.0-20261029-120000-abcdef1',
                    'SOURCE_SHA' => 'abcdef1',
                    'DEPLOY_HOST' => '203.0.113.10',
                    'DEPLOY_USER' => 'deploy-rateguru-'.$target,
                    'SSH_KEY_PRESENT' => 'true',
                    'KNOWN_HOSTS_PRESENT' => 'true',
                ]));

                expect($result['exit'])->toBe(0, $result['output']);
                expect(rrdKeyValues($scratch.'/github-output')['nightwatch_supported'] ?? null)
                    ->toBe($hasProgram ? 'true' : 'false', "{$target} ({$environment}) must follow target_nightwatch_program");
            }
        }

        // Both answers were exercised, so agreement is not vacuous.
        expect($decisions)->toContain(true, false);
    } finally {
        removeScratchDir($scratch);
    }
});
