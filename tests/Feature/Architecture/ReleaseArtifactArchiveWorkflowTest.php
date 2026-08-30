<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 7.1: the durable archive as a hard precondition for deployment.
 *
 * The scripts' own behaviour is proven in ReleaseArtifactArchiveTest.php.
 * This file proves the orchestration around them: that the archive really
 * does sit between build and deploy, that a failed archive stops the
 * deployment, that the composite action is transport rather than a second
 * implementation of the rules, that no credential can reach a log or a
 * summary, and that nothing outside this slice's scope moved.
 */
function releaseArchiveWorkflow(): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
}

function releaseArchiveAction(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/archive-release-artifact/action.yml')));
}

/**
 * A file with its comment and blank lines removed. Both the action and the
 * scripts document exactly what they refuse to do, so a whole-file search
 * would flag the sentences stating the guarantee rather than a violation.
 */
function releaseArchiveCodeLines(string $path): string
{
    $lines = preg_split('/\R/', File::get(base_path($path)));

    return implode("\n", array_filter($lines, function (string $line): bool {
        $trimmed = ltrim($line);

        return $trimmed !== '' && ! str_starts_with($trimmed, '#');
    }));
}

/**
 * @return array{0: bool, 1: string, 2: string}
 */
function releaseArchiveDevelopContent(string $path): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open('git show origin/develop:'.escapeshellarg($path), $descriptors, $pipes);

    expect($process)->not->toBeFalse('could not start git show');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    return [$exit === 0, $stdout, $stderr];
}

it('archives durably between build and deploy, and lets nothing deploy without it', function () {
    $workflow = releaseArchiveWorkflow();

    // resolve -> build -> archive -> deploy -> observability, with deploy
    // depending on the archive so a failed archive can never be followed by a
    // deployment. GitHub only starts a job when every job it `needs` has
    // succeeded, so this edge is the enforcement.
    expect(array_keys($workflow['jobs']))->toBe(['resolve', 'build', 'archive', 'deploy', 'observability'])
        ->and(data_get($workflow, 'jobs.build.needs'))->toBe('resolve')
        ->and(data_get($workflow, 'jobs.archive.needs'))->toBe('build')
        ->and(data_get($workflow, 'jobs.deploy.needs'))->toBe(['resolve', 'build', 'archive'])
        ->and(data_get($workflow, 'jobs.observability.needs'))->toBe(['build', 'deploy']);

    // Nothing may make the archive optional: no continue-on-error, and no
    // condition that could let deploy start while the archive did not.
    expect(data_get($workflow, 'jobs.archive.continue-on-error'))->toBeNull()
        ->and(data_get($workflow, 'jobs.archive.if'))->toBeNull()
        ->and(data_get($workflow, 'jobs.deploy.if'))->toBeNull()
        ->and(data_get($workflow, 'jobs.deploy.continue-on-error'))->toBeNull();

    foreach (data_get($workflow, 'jobs.archive.steps') as $step) {
        expect($step['continue-on-error'] ?? null)->toBeNull('no archive step may be allowed to fail open');
    }

    // Repository-level credentials, so the archive is deliberately not bound
    // to the staging GitHub Environment: the durable archive is a project
    // concern, not a target concern.
    expect(data_get($workflow, 'jobs.archive.environment'))->toBeNull()
        ->and(data_get($workflow, 'jobs.deploy.environment'))->toBe('staging');
});

it('gives the archive job the exact build output and the trusted tooling', function () {
    $workflow = releaseArchiveWorkflow();
    $steps = collect(data_get($workflow, 'jobs.archive.steps'))->keyBy('name');

    expect($steps->keys()->all())->toBe([
        'Checkout archive tooling',
        'Download immutable release artifact',
        'Archive release artifact to durable storage',
    ]);

    // Archive tooling comes from the trusted develop branch, never from the
    // arbitrary application ref being built — the same rule the deploy job
    // already follows.
    expect(data_get($steps->get('Checkout archive tooling'), 'uses'))
        ->toMatch('/^actions\/checkout@[0-9a-f]{40}$/')
        ->and(data_get($steps->get('Checkout archive tooling'), 'with.ref'))->toBe('develop')
        ->and(data_get($steps->get('Checkout archive tooling'), 'with.persist-credentials'))->toBeFalse();

    // The archive job never rebuilds anything: it consumes the exact artifact
    // the build job produced.
    expect(data_get($steps->get('Download immutable release artifact'), 'uses'))
        ->toMatch('/^actions\/download-artifact@[0-9a-f]{40}$/')
        ->and(data_get($steps->get('Download immutable release artifact'), 'with.name'))
        ->toBe('${{ needs.build.outputs.workflow-artifact-name }}');

    $archiveStep = $steps->get('Archive release artifact to durable storage');

    expect(data_get($archiveStep, 'uses'))->toBe('./.github/actions/archive-release-artifact')
        ->and(data_get($archiveStep, 'with.release-id'))->toBe('${{ needs.build.outputs.release-id }}')
        ->and(data_get($archiveStep, 'with.b2-key-id'))->toBe('${{ secrets.B2_ARTIFACT_KEY_ID }}')
        ->and(data_get($archiveStep, 'with.b2-application-key'))->toBe('${{ secrets.B2_ARTIFACT_APPLICATION_KEY }}')
        ->and(data_get($archiveStep, 'with.b2-bucket'))->toBe('${{ vars.B2_ARTIFACT_BUCKET }}');

    // No composer, npm, rsync or tar anywhere in the archive job: it moves
    // bytes it was handed, it does not produce them.
    $source = Yaml::dump($workflow['jobs']['archive']);

    expect($source)
        ->not->toContain('composer')
        ->not->toContain('npm ')
        ->not->toContain('rsync')
        ->not->toContain('tar ');
});

it('exports the artifact\'s own release.json beside it at build time', function () {
    $workflow = releaseArchiveWorkflow();
    $buildSteps = collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name');
    $run = data_get($buildSteps->get('Build release archive'), 'run');

    // One release.json document: the copy exported for the archive is taken
    // from the package root *after* it was frozen into the tarball, never
    // generated a second time.
    expect($run)->toContain('cp "${package_root}/release.json" "${output_root}/release.json"');

    expect(mb_strpos($run, 'tar \\'))
        ->toBeLessThan(mb_strpos($run, 'cp "${package_root}/release.json"'));
    expect(mb_substr_count($run, '> "${package_root}/release.json"'))->toBe(1);

    // It travels to the archive job through the same short-lived workflow
    // artifact, whose retention is unchanged and explicitly not the durable
    // copy.
    $upload = $buildSteps->get('Upload immutable release artifact');

    expect(data_get($upload, 'with.path'))
        ->toContain('${{ runner.temp }}/rateguru-output/release.json')
        ->and(data_get($upload, 'with.retention-days'))->toBe(3)
        ->and(data_get($upload, 'with.if-no-files-found'))->toBe('error');
});

it('keeps the deployment artifact contract exactly as it was', function () {
    $workflow = releaseArchiveWorkflow();
    $deploySteps = collect(data_get($workflow, 'jobs.deploy.steps'))->keyBy('name');
    $deployStep = $deploySteps->get('Deploy to staging');

    // The bytes deployed are still the same downloaded artifact, addressed the
    // same way, through the same unchanged composite action.
    expect(data_get($deployStep, 'uses'))->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($deployStep, 'with.deployment-target'))->toBe('staging-main')
        ->and(data_get($deployStep, 'with.release-id'))->toBe('${{ needs.build.outputs.release-id }}')
        ->and(data_get($deployStep, 'with.artifact-path'))
        ->toBe('${{ runner.temp }}/rateguru-release/${{ needs.build.outputs.artifact-name }}')
        ->and(data_get($deployStep, 'with.checksum-path'))
        ->toBe('${{ runner.temp }}/rateguru-release/${{ needs.build.outputs.artifact-name }}.sha256');

    $buildRun = data_get(collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name')->get('Build release archive'), 'run');

    expect($buildRun)
        ->toContain('artifact_name="rateguru-${release_id}.tar.gz"')
        ->toContain('release_id="${release_version}-${timestamp}-${short_sha}"')
        ->toContain('sha256sum "${artifact_name}"');
});

it('declares one closed set of repository variables and secrets', function () {
    $source = File::get(base_path('.github/workflows/deploy-staging.yml'));

    // Adding a variable or a secret to the staging deploy pipeline has to be
    // a deliberate edit here. B2_ARTIFACT_BUCKET is a coordinate and is a
    // variable; the two B2_ARTIFACT_* credentials are secrets — the same
    // split DEPLOY_* and SENTRY_* already follow.
    preg_match_all('/\$\{\{\s*vars\.([A-Z0-9_]+)\s*\}\}/', $source, $varMatches);
    preg_match_all('/\$\{\{\s*secrets\.([A-Z0-9_]+)\s*\}\}/', $source, $secretMatches);

    expect(array_values(array_unique($varMatches[1])))->toEqualCanonicalizing([
        'DEPLOY_HOST',
        'DEPLOY_PORT',
        'DEPLOY_USER',
        'DEPLOY_INCOMING',
        'DEPLOY_WRAPPER',
        'DEPLOY_ROOT',
        'SENTRY_ORG',
        'SENTRY_PROJECT',
        'B2_ARTIFACT_BUCKET',
    ]);

    expect(array_values(array_unique($secretMatches[1])))->toEqualCanonicalizing([
        'DEPLOY_SSH_KEY',
        'DEPLOY_KNOWN_HOSTS',
        'SENTRY_AUTH_TOKEN',
        'B2_ARTIFACT_KEY_ID',
        'B2_ARTIFACT_APPLICATION_KEY',
    ]);

    // No release-artifact credential is ever exposed to the deploy job, the
    // staging host or the application environment.
    $deployJob = Yaml::dump(releaseArchiveWorkflow()['jobs']['deploy']);
    expect($deployJob)
        ->not->toContain('B2_ARTIFACT_KEY_ID')
        ->not->toContain('B2_ARTIFACT_APPLICATION_KEY');
});

// --- the composite action ----------------------------------------------------

it('keeps the archive action transport-only and delegates every rule to the repository script', function () {
    $action = releaseArchiveAction();
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');

    expect(data_get($action, 'runs.using'))->toBe('composite')
        ->and(array_keys(data_get($action, 'inputs')))->toBe([
            'release-id',
            'source-dir',
            'b2-key-id',
            'b2-application-key',
            'b2-bucket',
        ]);

    expect($steps->keys()->all())->toBe([
        'Validate archive inputs',
        'Install pinned rclone',
        'Configure release-artifact storage credentials',
        'Archive release artifact durably',
        'Write archive summary',
        'Remove temporary storage credentials',
    ]);

    // The archive itself is one call into the real, shipped script — never a
    // reimplementation of the validation, idempotency or verification rules
    // in YAML.
    $run = data_get($steps->get('Archive release artifact durably'), 'run');

    expect($run)
        ->toContain('infrastructure/scripts/archive-release-artifact \\')
        ->toContain('--release "${RELEASE_ID}"')
        ->toContain('--source-dir "${SOURCE_DIR}"')
        ->toContain('--rclone-config "${RATEGURU_ARTIFACT_RCLONE_CONFIG}"')
        ->toContain('--bucket "${B2_BUCKET}"')
        ->toContain('--rclone-bin "${RUNNER_TEMP}/rateguru-rclone/rclone"')
        ->toContain('--report "${report_path}"');

    // rclone comes from the repository's own pinned, signature-verified
    // installer, never from an unversioned runner binary or `curl | bash`.
    expect(data_get($steps->get('Install pinned rclone'), 'run'))
        ->toContain('infrastructure/scripts/fetch-verified-rclone \\')
        ->toContain('--into "${RUNNER_TEMP}/rateguru-rclone"');

    $source = releaseArchiveCodeLines('.github/actions/archive-release-artifact/action.yml');

    expect($source)
        ->not->toContain('| bash')
        ->not->toContain('| sh')
        ->not->toContain('apt-get install')
        ->not->toMatch('/\bset -x\b/')
        ->not->toContain('--target');

    // Every external action referenced anywhere in the archive path is pinned
    // by full commit SHA.
    $externalActions = collect(data_get(releaseArchiveWorkflow(), 'jobs.archive.steps'))
        ->pluck('uses')
        ->filter(fn (mixed $uses): bool => is_string($uses) && ! str_starts_with($uses, './'));

    expect($externalActions)->not->toBeEmpty();

    foreach ($externalActions as $uses) {
        expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
    }
});

it('fails the archive closed when its inputs or credentials are not configured', function () {
    $action = releaseArchiveAction();
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');
    $validate = $steps->get('Validate archive inputs');

    // Validation runs first, before rclone is installed and before any
    // credential is written anywhere.
    expect(collect(data_get($action, 'runs.steps'))->pluck('name')->search('Validate archive inputs'))->toBe(0);

    // Credentials are tested for presence only — never interpolated into the
    // script body, which is what makes a missing secret an error rather than
    // a leaked one.
    expect(data_get($validate, 'env.B2_KEY_ID_PRESENT'))->toBe('${{ inputs.b2-key-id != \'\' }}')
        ->and(data_get($validate, 'env.B2_APPLICATION_KEY_PRESENT'))->toBe('${{ inputs.b2-application-key != \'\' }}')
        ->and(data_get($validate, 'env'))->not->toHaveKey('B2_KEY_ID');

    expect(data_get($validate, 'run'))
        ->toContain('release_regex=')
        ->toContain('refusing to deploy an unarchived release')
        ->toContain('exit 1');

    // Unlike the Sentry action, a missing credential is fatal here: the
    // archive is a precondition, not observability.
    expect(data_get($validate, 'run'))->not->toContain('skipping');

    // The real regex, exercised: the validation step's release check is
    // extracted and run against real values rather than only read.
    $script = <<<'BASH'
        set -Eeuo pipefail
        release_regex='^v[0-9]+\.[0-9]+\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$'
        [[ "${RELEASE_ID}" =~ ${release_regex} ]] || exit 1
        BASH;

    expect(data_get($validate, 'run'))->toContain("release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'");

    foreach ([
        'v0.0.0-20260830-120000-abcdef1' => 0,
        'v1.2.3-20260830-235959-abcdef1234567890abcdef1234567890abcdef12' => 0,
        'latest' => 1,
        'v0.0.0-20260830-120000-ABCDEF1' => 1,
        '../v0.0.0-20260830-120000-abcdef1' => 1,
    ] as $release => $expected) {
        $output = [];
        $exit = 0;
        exec(
            'env RELEASE_ID='.escapeshellarg($release).' bash -c '.escapeshellarg($script).' 2>&1',
            $output,
            $exit,
        );

        expect($exit)->toBe($expected, "release ID {$release} was classified wrongly by the workflow guard");
    }
});

it('handles storage credentials without ever writing them to a log, a summary or the repository', function () {
    $action = releaseArchiveAction();
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');

    $configure = $steps->get('Configure release-artifact storage credentials');

    // Passed through the environment (never a command line, so they cannot
    // leak through a process list), written 0600 into RUNNER_TEMP, and never
    // echoed back.
    expect(data_get($configure, 'env.B2_KEY_ID'))->toBe('${{ inputs.b2-key-id }}')
        ->and(data_get($configure, 'env.B2_APPLICATION_KEY'))->toBe('${{ inputs.b2-application-key }}')
        ->and(data_get($configure, 'run'))
        ->toContain('install -m 0600 /dev/null')
        ->toContain('config_path="${RUNNER_TEMP}/rateguru_artifact_rclone.conf"')
        ->not->toContain('cat "${config_path}"')
        ->not->toContain('echo "${B2_APPLICATION_KEY}')
        ->not->toContain('echo "${B2_KEY_ID}');

    // Removed on every path, including a failed archive.
    $cleanup = $steps->get('Remove temporary storage credentials');

    expect(data_get($cleanup, 'if'))->toBe('${{ always() }}')
        ->and(data_get($cleanup, 'run'))->toContain('rm -f "${RATEGURU_ARTIFACT_RCLONE_CONFIG:-}"');

    // The summary reports the release, never a credential.
    $summary = $steps->get('Write archive summary');

    expect(data_get($summary, 'if'))->toBe('${{ always() }}')
        ->and(array_keys(data_get($summary, 'env')))
        ->toBe(['RELEASE_ID', 'B2_BUCKET', 'REPORT_PATH', 'ARCHIVE_OUTCOME'])
        ->and(data_get($summary, 'run'))
        ->toContain('RateGuru Release Archive')
        ->toContain("printf 'Project:        %s\\n'")
        ->toContain("printf 'Release:        %s\\n'")
        ->toContain("printf 'Source SHA:     %s\\n'")
        ->toContain("printf 'Bucket:         %s\\n'")
        ->toContain("printf 'Remote path:    %s\\n'")
        ->toContain("printf 'Checksum:       %s\\n'")
        ->toContain("printf 'Archive upload: %s\\n'")
        ->toContain("printf 'Remote verify:  %s\\n'")
        ->toContain("printf 'Result:         %s\\n'")
        ->toContain('GITHUB_STEP_SUMMARY');

    // No run script anywhere in the action takes an input through direct
    // `${{ }}` interpolation into the script body.
    foreach (data_get($action, 'runs.steps') as $step) {
        if (! isset($step['run'])) {
            continue;
        }

        expect($step['run'])->not->toContain('${{');
    }

    // gitignore keeps a generated rclone configuration out of the repository
    // in the first place.
    expect(File::get(base_path('.gitignore')))->toContain('rclone.conf');
});

it('renders a passing and a failing archive summary from the real script report', function () {
    // The summary block is extracted from the action and executed for real,
    // against a genuine report document, so the operator-facing output is
    // proven rather than described.
    $action = releaseArchiveAction();
    $steps = collect(data_get($action, 'runs.steps'))->keyBy('name');
    $run = data_get($steps->get('Write archive summary'), 'run');

    $scratch = sys_get_temp_dir().'/release-archive-summary-'.uniqid('', true);
    mkdir($scratch, 0o755, true);

    try {
        file_put_contents($scratch.'/report.json', json_encode([
            'project' => 'rateguru',
            'release' => 'v0.0.0-20260830-120000-abcdef1',
            'source_sha' => 'abcdef1234567890abcdef1234567890abcdef12',
            'bucket' => 'rateguru-release-artifacts',
            'remote_path' => 'rateguru/artifacts/v0.0.0-20260830-120000-abcdef1/',
            'artifact' => 'rateguru-v0.0.0-20260830-120000-abcdef1.tar.gz',
            'sha256' => str_repeat('b', 64),
            'upload' => 'upload',
            'verify' => 'pass',
            'result' => 'pass',
        ], JSON_PRETTY_PRINT));

        $environment = [
            'RELEASE_ID=v0.0.0-20260830-120000-abcdef1',
            'B2_BUCKET=rateguru-release-artifacts',
            'REPORT_PATH='.$scratch.'/report.json',
            'ARCHIVE_OUTCOME=success',
            'GITHUB_STEP_SUMMARY='.$scratch.'/summary.md',
        ];

        exec(
            'env '.implode(' ', array_map('escapeshellarg', $environment)).' bash -c '.escapeshellarg($run).' 2>&1',
            $output,
            $exit,
        );

        expect($exit)->toBe(0, "the summary block failed:\n".implode("\n", $output));

        $summary = File::get($scratch.'/summary.md');

        expect($summary)
            ->toContain('## RateGuru Release Archive')
            ->toContain('Project:        rateguru')
            ->toContain('Release:        v0.0.0-20260830-120000-abcdef1')
            ->toContain('Source SHA:     abcdef1234567890abcdef1234567890abcdef12')
            ->toContain('Bucket:         rateguru-release-artifacts')
            ->toContain('Remote path:    rateguru/artifacts/v0.0.0-20260830-120000-abcdef1/')
            ->toContain('Checksum:       '.str_repeat('b', 64))
            ->toContain('Archive upload: PASS')
            ->toContain('Remote verify:  PASS')
            ->toContain('Result:         PASS');

        // A failed archive reports FAIL rather than silently rendering
        // nothing — the release ID stays visible so the run is diagnosable.
        unlink($scratch.'/summary.md');

        $failing = [
            'RELEASE_ID=v0.0.0-20260830-120000-abcdef1',
            'B2_BUCKET=rateguru-release-artifacts',
            'REPORT_PATH=',
            'ARCHIVE_OUTCOME=failure',
            'GITHUB_STEP_SUMMARY='.$scratch.'/summary.md',
        ];

        $failureOutput = [];
        $failureExit = 0;
        exec(
            'env '.implode(' ', array_map('escapeshellarg', $failing)).' bash -c '.escapeshellarg($run).' 2>&1',
            $failureOutput,
            $failureExit,
        );

        expect($failureExit)->toBe(0, "the summary block failed on a failed archive:\n".implode("\n", $failureOutput));

        expect(File::get($scratch.'/summary.md'))
            ->toContain('Release:        v0.0.0-20260830-120000-abcdef1')
            ->toContain('Archive upload: FAIL')
            ->toContain('Remote verify:  FAIL')
            ->toContain('Result:         FAIL');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

// --- out of scope: nothing else changed --------------------------------------

it('leaves rollback, deployment and every backup script byte-identical to develop', function () {
    // This repo's CI checks out shallow for the jobs that run the suite, so
    // origin/develop is often simply not resolvable here. Probing first lets
    // the test degrade honestly rather than fail for a reason unrelated to
    // this slice.
    exec('git rev-parse --verify -q origin/develop >/dev/null 2>&1', $probeOutput, $probeExit);

    if ($probeExit !== 0) {
        test()->markTestSkipped('origin/develop is not available in this checkout (shallow clone) — run locally for full history to exercise this check.');
    }

    $unchanged = [
        // Rollback already exists and is explicitly not Phase 7 work.
        '.github/workflows/rollback-staging.yml',
        // The deployment contract itself: the artifact the deploy job hands
        // to the host, and how it is verified there.
        '.github/actions/deploy-rateguru/action.yml',
        '.github/actions/sentry-release/action.yml',
        '.github/workflows/release.yml',
        'infrastructure/scripts/deploy',
        'infrastructure/scripts/rollback',
        // Backups and offsite storage: a completely separate bucket, a
        // completely separate retention policy, untouched by this slice.
        'infrastructure/scripts/backup',
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/restore-test',
        'infrastructure/scripts/common',
        'infrastructure/scripts/verify-required-clis',
        // The pinned external-runtime contract is read, never moved.
        'infrastructure/config/external-runtimes/versions.env',
        'infrastructure/config/external-runtimes/rclone-release-signing-key.asc',
        'infrastructure/config/deployment-targets.json',
    ];

    foreach ($unchanged as $path) {
        expect(File::exists(base_path($path)))->toBeTrue("expected file to exist: {$path}");

        [$ok, $developContent, $stderr] = releaseArchiveDevelopContent($path);

        expect($ok)->toBeTrue("origin/develop is unavailable for {$path}: could not resolve it with git show ({$stderr})");

        expect(File::get(base_path($path)))->toBe(
            $developContent,
            "{$path} must be byte-identical to origin/develop in this slice",
        );
    }
});

it('adds no retention, deletion or garbage collection to the release archive', function () {
    // Phase 7.1 deliberately implements no retention: nothing here may ever
    // remove a previously archived release. Retention is designed separately,
    // once recovery-point references exist.
    foreach ([
        'infrastructure/scripts/archive-release-artifact',
        'infrastructure/scripts/fetch-release-artifact',
        'infrastructure/scripts/release-artifact-common',
        '.github/actions/archive-release-artifact/action.yml',
    ] as $path) {
        $source = releaseArchiveCodeLines($path);

        // No age-based selection and no reference to any retention tool: the
        // archive path has no mechanism through which a release could ever be
        // aged out or swept up.
        expect($source)
            ->not->toContain('--max-age')
            ->not->toContain('--min-age')
            ->not->toContain('offsite-retention')
            ->not->toContain('scripts/cleanup');

        foreach (['delete', 'deletefile', 'purge', 'rmdir', 'rmdirs', 'sync', 'move', 'moveto'] as $verb) {
            expect($source)->not->toMatch('/\brclone(_run)?\s+'.$verb.'\b/');
        }
    }

    // The archive path is never given to any retention script either.
    foreach (['offsite-retention', 'cleanup', 'backup-cycle'] as $script) {
        expect(File::get(base_path("infrastructure/scripts/{$script}")))
            ->not->toContain('rateguru-release-artifacts')
            ->not->toContain('rateguru/artifacts/');
    }
});
