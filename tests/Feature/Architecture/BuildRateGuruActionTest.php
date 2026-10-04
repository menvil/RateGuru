<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The one canonical RateGuru build implementation, shared by every caller.
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
        // The repository itself is a perfectly ordinary application checkout.
        'SOURCE_ROOT' => base_path(),
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

    foreach (['source-root', 'source-ref', 'release-version', 'workflow-artifact-prefix', 'artifact-retention-days'] as $required) {
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
        // Controlled code alignment: the historical build it needs.
        // Same action, same mechanics — the only thing that differs is that
        // the commit comes from a backup rather than from an operator.
        'restore-staging.yml:build',
        'restore-production.yml:build',
        // Host recovery: the same historical build again, for the commit the
        // RECOVERED data belongs to. Also no GitHub Environment and no
        // credential of any kind — an arbitrary historical commit must never
        // be able to reach one.
        'recover-staging.yml:build',
        'recover-production.yml:build',
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
        '--argjson deployment_protocol_min "${deployment_protocol_min}"',
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
        // The minimum deployment protocol the release requires of whatever
        // deploys it. Core, so caller metadata can never restate it: a release
        // that could understate what it needs would be deployed by an engine
        // that cannot honour it.
        'deployment_protocol_min',
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
        'metadata redefining the required deployment protocol' => [['RELEASE_METADATA' => '{"deployment_protocol_min": 1}'], 'must not redefine core release.json fields: deployment_protocol_min'],
        'an empty source root' => [['SOURCE_ROOT' => ''], 'source-root must not be empty'],
        'a source root that does not exist' => [['SOURCE_ROOT' => '/nonexistent/rateguru-source'], 'source-root is not a directory'],
        // Without a checkout there is no commit to record, and the build would
        // silently fall back to whatever tree the runner happened to be in.
        'a source root that is not a checkout' => [['SOURCE_ROOT' => sys_get_temp_dir()], 'source-root is not a Git checkout'],
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

it('builds the application checkout it is pointed at, never the tooling checkout it was loaded from', function () {
    $action = buildRateGuruAction();
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');

    // Every command that touches the application runs inside source-root.
    // Anything missing from this list would silently operate on the trusted
    // tooling checkout at $GITHUB_WORKSPACE instead.
    foreach ([
        'Validate Composer definition',
        'Install production PHP dependencies',
        'Build frontend assets',
        'Build release archive',
    ] as $applicationStep) {
        expect(data_get($steps->get($applicationStep), 'working-directory'))
            ->toBe('${{ inputs.source-root }}', "{$applicationStep} must run inside the application checkout");
    }

    // Steps that install a toolchain are runner-global and correctly have no
    // working directory — but the npm cache key must still be keyed on the
    // application's own lockfile, not on the tooling checkout's.
    expect(data_get($steps->get('Setup Node'), 'with.cache-dependency-path'))
        ->toBe('${{ inputs.source-root }}/package-lock.json');

    expect(data_get($steps->get('Setup PHP'), 'working-directory'))->toBeNull()
        ->and(data_get($steps->get('Setup Node'), 'working-directory'))->toBeNull();

    // Because the archive step runs there, `git rev-parse HEAD`, the four
    // existence checks, rsync's source and the infrastructure CLI verifier are
    // all plain relative paths resolving against the application.
    $run = data_get($steps->get('Build release archive'), 'run');

    expect($run)
        ->toContain('source_sha="$(git rev-parse HEAD)"')
        ->toContain('infrastructure/scripts/verify-required-clis --release-root "${package_root}"')
        // rsync's source is the plain relative "./" — the working directory,
        // which is the application checkout, and never an absolute path
        // pointing back at the tooling workspace.
        ->toContain("  ./ \\\n  \"\${package_root}/\"");

    // The action never reaches back into the tooling checkout for application
    // content — no absolute workspace paths, no copying itself into the tree.
    expect($run)
        ->not->toContain('GITHUB_WORKSPACE')
        ->not->toContain('GITHUB_ACTION_PATH');
});

it('builds an application tree that contains none of the deployment tooling, end to end', function () {
    // The regression this closes: extracting the build pipeline into an action
    // made the action itself come from the ref being deployed, so any staging
    // ref older than the action could no longer be built. Proven here by
    // running the real, extracted build script against an application tree
    // that has no .github/ at all — no workflows, no actions, nothing.
    foreach (['git', 'rsync', 'tar', 'jq', 'sha256sum'] as $tool) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($tool))) === '') {
            test()->markTestSkipped("{$tool} is not available on this machine.");
        }
    }

    $scratch = sys_get_temp_dir().'/rateguru-build-source-'.uniqid('', true);
    $source = $scratch.'/application';
    $runnerTemp = $scratch.'/runner-temp';

    try {
        // A minimal but honest application checkout: everything the build
        // contract requires, the real CLI verifier and its manifest, one
        // application-only marker file — and deliberately no .github/.
        mkdir($source.'/public/build', 0o755, true);
        mkdir($source.'/vendor', 0o755, true);
        mkdir($source.'/app', 0o755, true);
        mkdir($source.'/infrastructure/scripts', 0o755, true);
        mkdir($source.'/infrastructure/config', 0o755, true);
        mkdir($runnerTemp, 0o755, true);

        file_put_contents($source.'/artisan', "#!/usr/bin/env php\n");
        file_put_contents($source.'/public/index.php', "<?php\n");
        file_put_contents($source.'/public/build/manifest.json', '{}');
        file_put_contents($source.'/vendor/autoload.php', "<?php\n");
        file_put_contents($source.'/app/OldBranchMarker.php', "<?php // only in the application checkout\n");

        copy(base_path('infrastructure/config/required-clis.txt'), $source.'/infrastructure/config/required-clis.txt');
        copy(base_path('infrastructure/scripts/verify-required-clis'), $source.'/infrastructure/scripts/verify-required-clis');
        chmod($source.'/infrastructure/scripts/verify-required-clis', 0o755);

        foreach (requiredCliManifestNames() as $cli) {
            if (! file_exists($source.'/infrastructure/scripts/'.$cli)) {
                file_put_contents($source.'/infrastructure/scripts/'.$cli, "#!/usr/bin/env bash\n");
            }

            chmod($source.'/infrastructure/scripts/'.$cli, 0o755);
        }

        foreach (sourcedLibraryNames() as $library) {
            file_put_contents($source.'/infrastructure/scripts/'.$library, "#!/usr/bin/env bash\n");
            chmod($source.'/infrastructure/scripts/'.$library, 0o644);
        }

        expect(is_dir($source.'/.github'))->toBeFalse('the fixture must not carry any deployment tooling');

        $git = 'git -C '.escapeshellarg($source).' -c user.email=build@rateguru.test -c user.name=Build ';
        exec($git.'init -q -b main 2>&1');
        exec($git.'add -A 2>&1');
        exec($git.'commit -q -m "old application revision" 2>&1');

        $sourceSha = trim((string) shell_exec($git.'rev-parse HEAD'));
        expect($sourceSha)->toMatch('/^[0-9a-f]{40}$/');

        // The real archive step, extracted from the action and run exactly as
        // the action runs it: inside source-root.
        $script = 'set -Eeuo pipefail'."\n"
            .'cd '.escapeshellarg($source)."\n"
            .data_get(buildRateGuruStep('Build release archive'), 'run');

        $githubOutput = $scratch.'/github-output';
        touch($githubOutput);

        $env = [
            'RUNNER_TEMP='.escapeshellarg($runnerTemp),
            'GITHUB_OUTPUT='.escapeshellarg($githubOutput),
            'GITHUB_RUN_ID=4242',
            'GITHUB_RUN_NUMBER=7',
            'SOURCE_REF='.escapeshellarg('feature/an-old-branch'),
            'RELEASE_VERSION=v0.0.0',
            'WORKFLOW_ARTIFACT_PREFIX=rateguru-release',
            'RELEASE_METADATA='.escapeshellarg('{"environment": "staging"}'),
            'EXPECTED_SOURCE_SHA=',
        ];

        $output = [];
        $exit = 0;
        exec('env '.implode(' ', $env).' bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);

        expect($exit)->toBe(0, "the build refused an application tree with no deployment tooling:\n".implode("\n", $output));

        $outputs = [];
        foreach (preg_split('/\R/', (string) file_get_contents($githubOutput)) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $outputs[$key] = $value;
            }
        }

        // source_sha is the application commit — not the commit of whatever
        // tooling checkout the action itself came from.
        expect($outputs['source_sha'] ?? null)->toBe($sourceSha)
            ->and($outputs['release_id'] ?? '')->toMatch('/^v0\.0\.0-[0-9]{8}-[0-9]{6}-'.substr($sourceSha, 0, 7).'$/')
            ->and($outputs['artifact_name'] ?? '')->toBe('rateguru-'.$outputs['release_id'].'.tar.gz')
            ->and($outputs['workflow_artifact_name'] ?? '')->toBe('rateguru-release-'.$outputs['release_id']);

        expect(file_exists($outputs['artifact_path']))->toBeTrue()
            ->and(file_exists($outputs['checksum_path']))->toBeTrue();

        // The checksum sidecar describes the artifact that was actually built.
        $verify = [];
        $verifyExit = 0;
        exec('cd '.escapeshellarg(dirname($outputs['artifact_path'])).' && sha256sum -c '.escapeshellarg(basename($outputs['checksum_path'])).' 2>&1', $verify, $verifyExit);
        expect($verifyExit)->toBe(0, implode("\n", $verify));

        // The package holds the application's tree, and its release.json
        // records the ref the operator selected with that tree's own commit.
        $listing = [];
        exec('tar -tzf '.escapeshellarg($outputs['artifact_path']), $listing);

        expect($listing)->toContain('./app/OldBranchMarker.php')
            ->toContain('./release.json')
            ->and(collect($listing)->filter(fn (string $entry): bool => str_starts_with($entry, './.github'))->all())
            ->toBe([], 'the artifact must never carry the deployment tooling');

        $metadata = json_decode((string) shell_exec('tar -xzOf '.escapeshellarg($outputs['artifact_path']).' ./release.json'), true, 512, JSON_THROW_ON_ERROR);

        expect($metadata['source_ref'])->toBe('feature/an-old-branch')
            ->and($metadata['source_sha'])->toBe($sourceSha)
            ->and($metadata['release'])->toBe($outputs['release_id'])
            ->and($metadata['project'])->toBe('rateguru')
            ->and($metadata['environment'])->toBe('staging')
            ->and($metadata['workflow_run_id'])->toBe('4242');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

// =============================================================================
// The release declares the deployment protocol it requires
// =============================================================================
//
// An application artifact can be newer than the privileged operational bundle
// installed beside it, so it must be able to say what it needs of the engine
// that will deploy it. That declaration is read from the APPLICATION SOURCE's
// own committed contract — never a literal in the action — so the contract file
// stays the single source and a build cannot claim a protocol the source did not
// declare.

/**
 * Runs the action's real "Build release archive" step against a minimal but
 * honest application checkout, optionally carrying a protocol contract.
 *
 * @return array{0: int, 1: string, 2: array<string, mixed>|null}
 */
function buildRateGuruArchiveWithContract(string $scratch, ?string $contract): array
{
    // The same guard the sibling end-to-end build test uses: this runs the real
    // archive step, which needs the real toolchain. Without it these tests fail
    // on a machine missing jq instead of skipping, where every other test in this
    // file that shells out skips.
    foreach (['git', 'rsync', 'tar', 'jq', 'sha256sum'] as $tool) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($tool))) === '') {
            test()->markTestSkipped("{$tool} is not available on this machine.");
        }
    }

    $source = $scratch.'/source';
    $runnerTemp = $scratch.'/runner-temp';

    mkdir($source.'/public/build', 0o755, true);
    mkdir($source.'/vendor', 0o755, true);
    mkdir($source.'/infrastructure/scripts', 0o755, true);
    mkdir($source.'/infrastructure/config', 0o755, true);
    mkdir($runnerTemp, 0o755, true);

    file_put_contents($source.'/artisan', "#!/usr/bin/env php\n");
    file_put_contents($source.'/public/index.php', "<?php\n");
    file_put_contents($source.'/public/build/manifest.json', '{}');
    file_put_contents($source.'/vendor/autoload.php', "<?php\n");

    copy(base_path('infrastructure/config/required-clis.txt'), $source.'/infrastructure/config/required-clis.txt');
    copy(base_path('infrastructure/scripts/verify-required-clis'), $source.'/infrastructure/scripts/verify-required-clis');
    chmod($source.'/infrastructure/scripts/verify-required-clis', 0o755);

    foreach (requiredCliManifestNames() as $cli) {
        if (! file_exists($source.'/infrastructure/scripts/'.$cli)) {
            file_put_contents($source.'/infrastructure/scripts/'.$cli, "#!/usr/bin/env bash\n");
        }

        chmod($source.'/infrastructure/scripts/'.$cli, 0o755);
    }

    foreach (sourcedLibraryNames() as $library) {
        file_put_contents($source.'/infrastructure/scripts/'.$library, "#!/usr/bin/env bash\n");
        chmod($source.'/infrastructure/scripts/'.$library, 0o644);
    }

    // null is a source that predates the contract entirely.
    if ($contract !== null) {
        file_put_contents($source.'/infrastructure/config/deployment-protocol.json', $contract);
    }

    $git = 'git -C '.escapeshellarg($source).' -c user.email=build@rateguru.test -c user.name=Build ';
    exec($git.'init -q -b main 2>&1');
    exec($git.'add -A 2>&1');
    exec($git.'commit -q -m "an application revision" 2>&1');

    $githubOutput = $scratch.'/github-output';
    touch($githubOutput);

    $script = 'set -Eeuo pipefail'."\n"
        .'cd '.escapeshellarg($source)."\n"
        .data_get(buildRateGuruStep('Build release archive'), 'run');

    $env = [
        'RUNNER_TEMP='.escapeshellarg($runnerTemp),
        'GITHUB_OUTPUT='.escapeshellarg($githubOutput),
        'GITHUB_RUN_ID=4242',
        'GITHUB_RUN_NUMBER=7',
        'SOURCE_REF=develop',
        'RELEASE_VERSION=v0.0.0',
        'WORKFLOW_ARTIFACT_PREFIX=rateguru-release',
        'RELEASE_METADATA='.escapeshellarg('{}'),
        'EXPECTED_SOURCE_SHA=',
    ];

    $output = [];
    $exit = 0;
    exec('env '.implode(' ', $env).' bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);

    $metadata = null;

    foreach (preg_split('/\R/', (string) file_get_contents($githubOutput)) ?: [] as $line) {
        if (str_starts_with($line, 'artifact_path=')) {
            $path = substr($line, strlen('artifact_path='));
            $metadata = json_decode(
                (string) shell_exec('tar -xzOf '.escapeshellarg($path).' ./release.json'),
                true,
            );
        }
    }

    return [$exit, implode("\n", $output), $metadata];
}

it('records the protocol the application source declares as an integer', function () {
    // The committed contract itself, so this test states what the repository
    // actually requires rather than a number written twice.
    $scratch = sys_get_temp_dir().'/build-protocol-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        $declared = (int) data_get(
            json_decode(File::get(base_path('infrastructure/config/deployment-protocol.json')), true, 512, JSON_THROW_ON_ERROR),
            'artifact.minimum_required',
        );

        [$exit, $output, $metadata] = buildRateGuruArchiveWithContract(
            $scratch,
            File::get(base_path('infrastructure/config/deployment-protocol.json')),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('Candidate deployment protocol: '.$declared
            .' (from infrastructure/config/deployment-protocol.json)');

        // An integer, not a string: deploy compares it numerically, and jq's
        // --argjson is what keeps it one.
        expect($metadata['deployment_protocol_min'] ?? null)->toBe($declared);
        expect($metadata['deployment_protocol_min'])->toBeInt();
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('declares legacy protocol 1 explicitly for an application source with no contract', function () {
    // Trusted modern build tooling must still be able to package a historical
    // commit — the clean-host recovery path depends on exactly that — so a source
    // predating the contract resolves to 1 and SAYS so, rather than failing or
    // silently defaulting.
    $scratch = sys_get_temp_dir().'/build-protocol-legacy-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        [$exit, $output, $metadata] = buildRateGuruArchiveWithContract($scratch, null);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain(
            'application source has no infrastructure/config/deployment-protocol.json;'
                .' declaring legacy minimum deployment protocol 1'
        );

        expect($metadata['deployment_protocol_min'] ?? null)->toBe(1);
        expect($metadata['deployment_protocol_min'])->toBeInt();
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('fails the build when the application source carries a malformed protocol contract', function (string $contract) {
    // A source that HAS the file is held to it. Treating a broken contract as
    // "old" would let a release that cannot state what it needs be built anyway,
    // and the artifact would then be judged as legacy protocol 1 at deploy time.
    $scratch = sys_get_temp_dir().'/build-protocol-broken-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        [$exit, $output, $metadata] = buildRateGuruArchiveWithContract($scratch, $contract);

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain(
            'infrastructure/config/deployment-protocol.json is not a valid deployment protocol contract'
        );
        expect($output)->not->toContain('declaring legacy minimum deployment protocol');
        expect($metadata)->toBeNull('a refused contract must never produce an artifact');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'not JSON' => ['this is not json'],
    'not an object' => ['["schema"]'],
    'a wrong schema' => ['{"schema": 2, "artifact": {"minimum_required": 1}, "tooling": {"supported": 1}}'],
    'a string minimum' => ['{"schema": 1, "artifact": {"minimum_required": "1"}, "tooling": {"supported": 1}}'],
    'a float minimum' => ['{"schema": 1, "artifact": {"minimum_required": 1.5}, "tooling": {"supported": 1}}'],
    'a zero minimum' => ['{"schema": 1, "artifact": {"minimum_required": 0}, "tooling": {"supported": 1}}'],
    'no artifact section' => ['{"schema": 1, "tooling": {"supported": 1}}'],
]);

it('reads the required protocol from the source contract and never from a literal', function () {
    // The single-source rule. The only `1` the action may carry is the legacy
    // fallback for a source that has no contract at all; the value for a source
    // that HAS one must come from that file.
    $run = data_get(buildRateGuruStep('Build release archive'), 'run');

    expect(preg_match(
        '/# --- candidate deployment protocol \(begin\) ---\n(.*?)\n\s*# --- candidate deployment protocol \(end\) ---/s',
        $run,
        $matches,
    ))->toBe(1, 'could not locate the candidate deployment protocol block');

    $block = $matches[1];

    expect($block)->toContain('protocol_contract="infrastructure/config/deployment-protocol.json"');
    expect($block)->toContain('.artifact.minimum_required');

    // Exactly one assignment of a literal protocol, and it is the legacy branch.
    expect(preg_match_all('/deployment_protocol_min=[0-9]+/', $block))
        ->toBe(1, 'the only literal protocol may be the legacy fallback');
    expect($block)->toContain('deployment_protocol_min=1');
    expect($block)->toContain('declaring legacy minimum deployment protocol');

    // The build VALIDATES tooling.supported — it is half of the contract's own
    // internal consistency, and the build must not ship a release.json every host
    // would refuse — but it never USES it. What a host supports is the host's to
    // state, and install-target-operations is what states it, so the value the
    // build records is the artifact half and nothing else.
    expect($block)->toContain('else .artifact.minimum_required');
    expect($run)->toContain('deployment_protocol_min: $deployment_protocol_min');

    foreach (preg_split('/\R/', $run) ?: [] as $line) {
        $trimmed = ltrim($line);

        // Comments explain the rule; the rule itself is about executable lines.
        if (! str_contains($line, 'tooling.supported') || str_starts_with($trimmed, '#')) {
            continue;
        }

        // Every executable mention is a validation branch, never an assignment
        // or a recorded field.
        expect(str_contains($line, 'error('))
            ->toBeTrue("tooling.supported may only be validated, never used: {$line}");
    }
});

it('declares the same protocol ceiling deploy and the installer declare', function () {
    $declared = deploymentProtocolMaxDeclarations();

    expect($declared['build-rateguru'])->toBe($declared['deploy']);
    expect($declared['build-rateguru'])->toBe($declared['install-target-operations']);
    expect($declared['build-rateguru'])->toBe(2147483647);

    // And the bound is applied, not merely declared.
    $run = data_get(buildRateGuruStep('Build release archive'), 'run');
    expect($run)->toContain('--argjson max "${protocol_max}"')
        ->toContain('.artifact.minimum_required > $max');
});

it('fails the build when the application source declares a protocol above the ceiling', function (string $contract) {
    // An unbounded declaration is the fail-open: Bash signed arithmetic wraps
    // 9223372036854775808 to a negative, so a release requiring a protocol
    // nothing implements would have compared as "no bigger than 1" and deployed.
    // Refused at the build, so such an artifact is never produced.
    $scratch = sys_get_temp_dir().'/build-protocol-ceiling-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        [$exit, $output, $metadata] = buildRateGuruArchiveWithContract($scratch, $contract);

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain(
            'infrastructure/config/deployment-protocol.json is not a valid deployment protocol contract'
        );
        expect($metadata)->toBeNull('a refused contract must never produce an artifact');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'one above the ceiling' => ['{"schema": 1, "artifact": {"minimum_required": 2147483648}, "tooling": {"supported": 2147483648}}'],
    'the signed 64-bit maximum' => ['{"schema": 1, "artifact": {"minimum_required": 9223372036854775807}, "tooling": {"supported": 9223372036854775807}}'],
    'one above the signed 64-bit maximum' => ['{"schema": 1, "artifact": {"minimum_required": 9223372036854775808}, "tooling": {"supported": 9223372036854775808}}'],
]);

it('builds an artifact declaring the ceiling itself', function () {
    // Inclusive on this side too, so the bound is a limit rather than an
    // off-by-one that quietly forbids its own maximum.
    $scratch = sys_get_temp_dir().'/build-protocol-at-ceiling-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        [$exit, $output, $metadata] = buildRateGuruArchiveWithContract(
            $scratch,
            '{"schema": 1, "artifact": {"minimum_required": 2147483647}, "tooling": {"supported": 2147483647}}',
        );

        expect($exit)->toBe(0, $output);
        expect($metadata['deployment_protocol_min'] ?? null)->toBe(2147483647);
        expect($metadata['deployment_protocol_min'])->toBeInt();
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses a contract path that is a symlink', function () {
    // `-f` follows links, so a link planted in the source tree could point at a
    // file outside it and decide deployment_protocol_min — while the artifact
    // carried only the link, and no host could ever see what was read. An ABSENT
    // contract is the one historical fallback; a present one must be the source's
    // own regular file.
    foreach (['git', 'rsync', 'tar', 'jq', 'sha256sum'] as $tool) {
        if (trim((string) shell_exec('command -v '.escapeshellarg($tool))) === '') {
            test()->markTestSkipped("{$tool} is not available on this machine.");
        }
    }

    $scratch = sys_get_temp_dir().'/build-protocol-symlink-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        // An out-of-tree contract demanding a high protocol, reachable only
        // through the link.
        $outside = $scratch.'/outside-the-source.json';
        file_put_contents($outside, '{"schema": 1, "artifact": {"minimum_required": 7}, "tooling": {"supported": 7}}');

        // Build the source tree first, then replace the contract with a symlink.
        buildRateGuruArchiveWithContract($scratch, '{"schema": 1, "artifact": {"minimum_required": 1}, "tooling": {"supported": 1}}');

        $contract = $scratch.'/source/infrastructure/config/deployment-protocol.json';
        unlink($contract);
        symlink($outside, $contract);

        $githubOutput = $scratch.'/github-output-symlink';
        touch($githubOutput);

        $script = 'set -Eeuo pipefail'."\n"
            .'cd '.escapeshellarg($scratch.'/source')."\n"
            .data_get(buildRateGuruStep('Build release archive'), 'run');

        $env = [
            'RUNNER_TEMP='.escapeshellarg($scratch.'/runner-temp'),
            'GITHUB_OUTPUT='.escapeshellarg($githubOutput),
            'GITHUB_RUN_ID=4242',
            'GITHUB_RUN_NUMBER=7',
            'SOURCE_REF=develop',
            'RELEASE_VERSION=v0.0.0',
            'WORKFLOW_ARTIFACT_PREFIX=rateguru-release',
            'RELEASE_METADATA='.escapeshellarg('{}'),
            'EXPECTED_SOURCE_SHA=',
        ];

        $output = [];
        $exit = 0;
        exec('env '.implode(' ', $env).' bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        $text = implode("\n", $output);

        expect($exit)->not->toBe(0, $text);
        expect($text)->toContain('is a symlink — a release must declare its protocol from its own tree');

        // Never the out-of-tree value, and never the legacy fallback either.
        expect($text)
            ->not->toContain('Candidate deployment protocol: 7')
            ->not->toContain('declaring legacy minimum deployment protocol');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('fails the build on an internally inconsistent source contract', function (string $contract, string $why) {
    // The build and install-target-operations validate the SAME single source, so
    // they must not be able to disagree about it. A contract raising
    // artifact.minimum_required past tooling.supported would otherwise build
    // cleanly and ship a release.json every host must refuse — a failure
    // discovered at deploy time for a mistake visible at build time.
    $scratch = sys_get_temp_dir().'/build-protocol-inconsistent-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        [$exit, $output, $metadata] = buildRateGuruArchiveWithContract($scratch, $contract);

        expect($exit)->not->toBe(0, "{$why}:\n{$output}");
        expect($output)->toContain(
            'infrastructure/config/deployment-protocol.json is not a valid deployment protocol contract'
        );
        expect($metadata)->toBeNull('a refused contract must never produce an artifact');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'a minimum beyond what the tooling supports' => [
        '{"schema": 1, "artifact": {"minimum_required": 2}, "tooling": {"supported": 1}}',
        'requiring protocol 2 of artifacts while the tooling implements only 1',
    ],
    'a missing tooling section' => [
        '{"schema": 1, "artifact": {"minimum_required": 1}}',
        'a contract that never says what the tooling supports',
    ],
    'a string supported version' => [
        '{"schema": 1, "artifact": {"minimum_required": 1}, "tooling": {"supported": "1"}}',
        'a supported version that is not a number',
    ],
    'a float supported version' => [
        '{"schema": 1, "artifact": {"minimum_required": 1}, "tooling": {"supported": 1.5}}',
        'a supported version that is not an integer',
    ],
    'a zero supported version' => [
        '{"schema": 1, "artifact": {"minimum_required": 1}, "tooling": {"supported": 0}}',
        'a supported version below 1',
    ],
    'a supported version above the ceiling' => [
        '{"schema": 1, "artifact": {"minimum_required": 1}, "tooling": {"supported": 2147483648}}',
        'a supported version above the shared ceiling',
    ],
]);

it('validates the same contract invariants install-target-operations validates', function () {
    // Stated as a property of both sources, so the two validators of the single
    // source of truth cannot drift apart silently.
    $run = data_get(buildRateGuruStep('Build release archive'), 'run');
    $installer = File::get(base_path('infrastructure/scripts/install-target-operations'));

    foreach ([
        '.schema != 1',
        '(.artifact.minimum_required | type) != "number"',
        '.artifact.minimum_required < 1',
        '.artifact.minimum_required > $max',
        '(.tooling.supported | type) != "number"',
        '.tooling.supported < 1',
        '.tooling.supported > $max',
        '.artifact.minimum_required > .tooling.supported',
    ] as $invariant) {
        expect(str_contains($run, $invariant))
            ->toBeTrue("the build must validate: {$invariant}");
        expect(str_contains($installer, $invariant))
            ->toBeTrue("install-target-operations must validate: {$invariant}");
    }
});
