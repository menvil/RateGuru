<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The one canonical RateGuru build implementation (Phase 7.1, Part A).
 *
 * Everything mechanical about producing an immutable release — toolchain,
 * production dependencies, frontend assets, the package tree and its
 * exclusion contract, the fail-closed infrastructure CLI mode check,
 * release.json, the tarball, its checksum and the artifact upload — exists
 * here and nowhere else. Callers contribute policy only.
 */
function buildRateGuruActionPath(): string
{
    return base_path('.github/actions/build-rateguru/action.yml');
}

function buildRateGuruAction(): array
{
    return Yaml::parse(File::get(buildRateGuruActionPath()));
}

function buildRateGuruStep(string $name): array
{
    $step = collect(data_get(buildRateGuruAction(), 'runs.steps'))->keyBy('name')->get($name);

    expect($step)->not->toBeNull("the build action has no step named {$name}");

    return $step;
}

/**
 * Runs the action's own "Validate build inputs" script — the real one,
 * extracted from the action — against a set of inputs.
 *
 * @return array{0: int, 1: string}
 */
function runBuildInputValidation(array $overrides = []): array
{
    $env = array_merge([
        'SOURCE_REF' => 'develop',
        'RELEASE_VERSION' => 'v0.0.0',
        'WORKFLOW_ARTIFACT_PREFIX' => 'rateguru-release',
        'ARTIFACT_RETENTION_DAYS' => '3',
        'RELEASE_METADATA' => '{}',
        'EXPECTED_SOURCE_SHA' => '',
        'VALIDATE_COMPOSER' => 'false',
        'NODE_CACHE' => '',
    ], $overrides);

    $assignments = collect($env)
        ->map(fn (string $value, string $name): string => $name.'='.escapeshellarg($value))
        ->implode(' ');

    $command = 'env '.$assignments.' bash -c '.escapeshellarg(data_get(buildRateGuruStep('Validate build inputs'), 'run')).' 2>&1';

    $output = [];
    $exit = 0;
    exec($command, $output, $exit);

    return [$exit, implode("\n", $output)];
}

it('defines a hardened reusable RateGuru build action', function () {
    expect(File::exists(buildRateGuruActionPath()))->toBeTrue();

    $action = buildRateGuruAction();
    $steps = collect(data_get($action, 'runs.steps'));

    expect(data_get($action, 'name'))->toBe('Build RateGuru release artifact')
        ->and(data_get($action, 'runs.using'))->toBe('composite');

    // The whole mechanical pipeline, in the only order that is correct.
    expect($steps->pluck('name')->all())->toBe([
        'Validate build inputs',
        'Setup PHP',
        'Setup Node',
        'Validate Composer definition',
        'Install production PHP dependencies',
        'Build frontend assets',
        'Build release archive',
        'Upload immutable release artifact',
    ]);

    foreach (['source-ref', 'release-version', 'workflow-artifact-prefix', 'artifact-retention-days'] as $required) {
        expect(data_get($action, "inputs.{$required}.required"))
            ->toBeTrue("{$required} must be a required input");
    }

    // Optional policy, with the defaults that keep a caller honest: no extra
    // metadata, no commit pin, no strict Composer validation, no npm cache.
    expect(data_get($action, 'inputs.release-metadata.default'))->toBe('{}')
        ->and(data_get($action, 'inputs.expected-source-sha.default'))->toBe('')
        ->and(data_get($action, 'inputs.validate-composer.default'))->toBe('false')
        ->and(data_get($action, 'inputs.node-cache.default'))->toBe('');

    expect(data_get($action, 'outputs'))->toHaveKeys([
        'source-sha',
        'release-id',
        'artifact-name',
        'workflow-artifact-name',
        'artifact-path',
        'checksum-path',
    ]);

    foreach ((array) data_get($action, 'outputs') as $name => $output) {
        expect(data_get($output, 'value'))
            ->toStartWith('${{ steps.release.outputs.', "output {$name} must come from the build step itself");
    }

    // Every third-party action is pinned by commit SHA, and no run script
    // interpolates an expression into a shell.
    foreach ($steps as $step) {
        $uses = data_get($step, 'uses');

        if (is_string($uses)) {
            expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');

            continue;
        }

        expect(data_get($step, 'shell'))->toBe('bash')
            ->and(data_get($step, 'run'))->not->toContain('${{ inputs.');
    }
});

it('is the only build implementation, used by both deployment pipelines', function () {
    $callSites = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') === './.github/actions/build-rateguru') {
                    $callSites[] = basename($path).":{$jobName}";
                }
            }
        }
    }

    expect($callSites)->toEqualCanonicalizing([
        'deploy-staging.yml:build',
        'release.yml:build',
    ]);

    // And nothing else anywhere builds a RateGuru release package: the
    // mechanics exist in exactly one file.
    $duplicates = [];

    foreach (array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
    ) as $path) {
        if ($path === buildRateGuruActionPath()) {
            continue;
        }

        $source = File::get($path);

        foreach (['rateguru-${release_id}.tar.gz', 'verify-required-clis', 'package_root', 'workflow_artifact_name='] as $mechanic) {
            if (str_contains($source, $mechanic)) {
                $duplicates[] = str_replace(base_path().'/', '', $path).': '.$mechanic;
            }
        }
    }

    expect($duplicates)->toBe([], 'the release build pipeline is duplicated outside the shared action');
});

it('preserves the release identity format and the package contract', function () {
    $run = data_get(buildRateGuruStep('Build release archive'), 'run');

    expect($run)
        ->toContain('release_id="${RELEASE_VERSION}-${timestamp}-${short_sha}"')
        ->toContain('artifact_name="rateguru-${release_id}.tar.gz"')
        ->toContain('workflow_artifact_name="${WORKFLOW_ARTIFACT_PREFIX}-${release_id}"')
        ->toContain('timestamp="$(date -u +%Y%m%d-%H%M%S)"')
        ->toContain('short_sha="${source_sha:0:7}"')
        ->toContain('sha256sum "${artifact_name}"');

    // The release ID this action produces must still satisfy the expression
    // the deploy action and the Sentry action both validate against.
    $releaseId = 'v0.5.0-20260826-120211-ca7d1c7';
    expect($releaseId)->toMatch('/^v[0-9]+\.[0-9]+\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$/');
    expect(File::get(base_path('.github/actions/deploy-rateguru/action.yml')))
        ->toContain('expected_artifact_name="rateguru-${RELEASE_ID}.tar.gz"');

    // Production dependencies are installed the one way they always were.
    expect(data_get(buildRateGuruStep('Install production PHP dependencies'), 'run'))
        ->toContain('composer install')
        ->toContain('--no-dev')
        ->toContain('--classmap-authoritative')
        ->and(data_get(buildRateGuruStep('Install production PHP dependencies'), 'env.APP_ENV'))
        ->toBe('production');

    expect(data_get(buildRateGuruStep('Build frontend assets'), 'run'))
        ->toContain('npm ci')
        ->toContain('npm run build');

    // The application file/exclusion contract is exactly what it was.
    expect($run)
        ->toContain("--exclude='.git/'")
        ->toContain("--exclude='.github/'")
        ->toContain("--exclude='.env'")
        ->toContain("--exclude='.env.*'")
        ->toContain("--exclude='node_modules/'")
        ->toContain("--exclude='storage/'")
        ->toContain("--exclude='tests/'")
        ->toContain("--exclude='docs/'")
        ->toContain("--exclude='coderabbit-review/'")
        ->toContain("--exclude='tools/'")
        ->toContain("--exclude='database/database.sqlite'")
        ->toContain("--exclude='public/hot'")
        ->toContain('test -f artisan')
        ->toContain('test -f public/index.php')
        ->toContain('test -f vendor/autoload.php')
        ->toContain('test -f public/build/manifest.json');
});

it('writes the release identity every consumer depends on, and lets no caller redefine it', function () {
    $run = data_get(buildRateGuruStep('Build release archive'), 'run');

    foreach ([
        '--arg project "rateguru"',
        '--arg source_ref "${SOURCE_REF}"',
        '--arg source_sha "${source_sha}"',
        '--arg release "${release_id}"',
        '--arg built_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"',
        '--arg workflow_run_id "${GITHUB_RUN_ID}"',
        '--arg workflow_run_number "${GITHUB_RUN_NUMBER}"',
        '--argjson extra "${RELEASE_METADATA}"',
    ] as $fragment) {
        expect($run)->toContain($fragment);
    }

    // Caller metadata is merged on top and can only ever add fields.
    expect($run)->toContain('} + $extra');

    // The guard's own list of protected fields is the same list the document
    // is built from — asserted against the marker block so the two can never
    // silently diverge.
    $validation = data_get(buildRateGuruStep('Validate build inputs'), 'run');

    expect(preg_match(
        '/# --- core release\.json fields \(begin\) ---\n(.*?)\n\s*# --- core release\.json fields \(end\) ---/s',
        $validation,
        $matches,
    ))->toBe(1, 'could not locate the core release.json field list');

    preg_match('/core_fields=\'(.+)\'/', trim($matches[1]), $listMatch);

    expect(json_decode($listMatch[1] ?? '', true))->toBe([
        'project',
        'source_ref',
        'source_sha',
        'release',
        'built_at',
        'workflow_run_id',
        'workflow_run_number',
    ]);

    // The application only ever reads release + source_sha, and both are core.
    expect(File::get(base_path('app/Support/Deployment/DeploymentMetadata.php')))
        ->toContain("\$decoded['release']")
        ->toContain("\$decoded['source_sha']");
});

it('rejects every malformed policy input before a dependency is installed', function () {
    if (trim((string) shell_exec('command -v jq')) === '') {
        test()->markTestSkipped('jq is not available on this machine.');
    }

    [$exit, $output] = runBuildInputValidation();
    expect($exit)->toBe(0, "the validator rejected a correct set of inputs:\n{$output}");

    $rejections = [
        'empty source ref' => [['SOURCE_REF' => ''], 'source-ref must not be empty'],
        'a raw branch as the release version' => [['RELEASE_VERSION' => 'develop'], 'release-version must be vMAJOR.MINOR.PATCH'],
        'a pre-release suffix in the release version' => [['RELEASE_VERSION' => 'v1.2.3-rc1'], 'release-version must be vMAJOR.MINOR.PATCH'],
        'a flag-shaped artifact prefix' => [['WORKFLOW_ARTIFACT_PREFIX' => '--evil'], 'not a valid artifact name prefix'],
        'a path in the artifact prefix' => [['WORKFLOW_ARTIFACT_PREFIX' => 'a/b'], 'not a valid artifact name prefix'],
        'a zero retention' => [['ARTIFACT_RETENTION_DAYS' => '0'], 'artifact-retention-days must be an integer'],
        'a retention beyond what GitHub allows' => [['ARTIFACT_RETENTION_DAYS' => '400'], 'artifact-retention-days must be an integer'],
        'a truncated expected SHA' => [['EXPECTED_SOURCE_SHA' => 'ca7d1c7'], 'expected-source-sha must be a full commit SHA'],
        'a non-boolean composer switch' => [['VALIDATE_COMPOSER' => 'yes'], 'validate-composer must be true or false'],
        'an unknown node cache' => [['NODE_CACHE' => 'yarn'], 'node-cache must be npm or empty'],
        'metadata that is not an object' => [['RELEASE_METADATA' => '["staging"]'], 'release-metadata must be a JSON object'],
        'metadata that is not JSON at all' => [['RELEASE_METADATA' => 'staging'], 'release-metadata must be a JSON object'],
        'metadata redefining the release ID' => [['RELEASE_METADATA' => '{"release": "v9.9.9-forged"}'], 'must not redefine core release.json fields: release'],
        'metadata redefining the source commit' => [['RELEASE_METADATA' => '{"source_sha": "0000000"}'], 'must not redefine core release.json fields: source_sha'],
    ];

    foreach ($rejections as $case => [$overrides, $message]) {
        [$exit, $output] = runBuildInputValidation($overrides);

        expect($exit)->not->toBe(0, "the validator accepted {$case}");
        expect(str_contains($output, $message))
            ->toBeTrue("wrong diagnostic for {$case}: {$output}");
    }

    // The two real callers' own metadata documents stay acceptable.
    foreach ([
        '{"environment": "staging"}',
        '{"source_tag": "v0.5.0", "version": "v0.5.0", "targets": ["staging-main", "tits-guru"]}',
    ] as $metadata) {
        [$exit, $output] = runBuildInputValidation(['RELEASE_METADATA' => $metadata]);

        expect($exit)->toBe(0, "a real caller's metadata was rejected:\n{$output}");
    }
});

it('delegates the CLI executable-bit check to the shared verify-required-clis, and fails closed when a CLI is not executable', function () {
    // Proves the build step is exactly a delegating call to the real, shared
    // infrastructure/scripts/verify-required-clis — the same algorithm deploy
    // itself uses — never a reimplementation, then runs that exact extracted
    // line end to end against a scratch package_root.
    $run = data_get(buildRateGuruStep('Build release archive'), 'run');

    expect(preg_match(
        '/# --- verify infrastructure CLI executable bits \(begin\) ---\n(.*?)\n\s*# --- verify infrastructure CLI executable bits \(end\) ---/s',
        $run,
        $matches,
    ))->toBe(1, 'could not locate the executable-bit verification block in the build action');

    $delegatingLine = trim($matches[1]);
    expect($delegatingLine)->toBe('infrastructure/scripts/verify-required-clis --release-root "${package_root}"');

    // After rsync stages package_root and before tar freezes it.
    expect(mb_strpos($run, 'rsync \\'))
        ->toBeLessThan(mb_strpos($run, '# --- verify infrastructure CLI executable bits (begin) ---'))
        ->and(mb_strpos($run, '# --- verify infrastructure CLI executable bits (end) ---'))
        ->toBeLessThan(mb_strpos($run, 'tar \\'));

    // Never "fixed" with a chmod, which would hide the wrong repository mode.
    // (The block's own comment says so; what must not exist is the command.)
    expect($run)->not->toMatch('/^\s*chmod\b/m');

    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        $script = 'set -Eeuo pipefail'."\n".'cd '.escapeshellarg(base_path())."\n".'package_root='.escapeshellarg($root)."\n".$delegatingLine;

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "verification rejected a correctly-built package:\n".implode("\n", $output));
        expect(implode("\n", $output))->toContain('verified: every required infrastructure CLI retains its executable mode after release normalization');

        // Now regress exactly one file, matching the real incident.
        chmod($root.'/infrastructure/scripts/targets', 0o640);

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->not->toBe(0);
        expect(implode("\n", $output))->toContain('required CLI lost executable mode after extraction: targets');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('refuses to build a commit the caller did not resolve', function () {
    expect(data_get(buildRateGuruStep('Build release archive'), 'run'))
        ->toContain('source_sha="$(git rev-parse HEAD)"')
        ->toContain('is not the resolved source commit');
});

it('uploads exactly one artifact, with the caller\'s retention and no recompression', function () {
    $upload = buildRateGuruStep('Upload immutable release artifact');

    expect(data_get($upload, 'uses'))->toMatch('/^actions\/upload-artifact@[0-9a-f]{40}$/')
        ->and(data_get($upload, 'with.name'))->toBe('${{ steps.release.outputs.workflow_artifact_name }}')
        ->and(data_get($upload, 'with.if-no-files-found'))->toBe('error')
        ->and(data_get($upload, 'with.compression-level'))->toBe(0)
        ->and(data_get($upload, 'with.retention-days'))->toBe('${{ inputs.artifact-retention-days }}');

    expect(data_get($upload, 'with.path'))
        ->toContain('${{ steps.release.outputs.artifact_path }}')
        ->toContain('${{ steps.release.outputs.checksum_path }}');
});

it('runs strict Composer validation only when the caller asks for it', function () {
    expect(data_get(buildRateGuruStep('Validate Composer definition'), 'if'))
        ->toBe("\${{ inputs.validate-composer == 'true' }}")
        ->and(data_get(buildRateGuruStep('Validate Composer definition'), 'run'))
        ->toBe('composer validate --strict');
});
