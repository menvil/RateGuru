<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 7.1: durable immutable release artifact archive.
 *
 * These tests execute the real shipped
 * infrastructure/scripts/archive-release-artifact and
 * infrastructure/scripts/fetch-release-artifact — never a reimplementation of
 * their logic — against a genuine local rclone "remote": a minimal rclone.conf
 * declaring a `type = alias` remote named exactly like the production one
 * (`rateguru-artifacts-b2`) pointed at a scratch directory tree.
 *
 * That shape matters. The bucket stays the canonical
 * `rateguru-release-artifacts` and the remote path is built by the real code,
 * so `rclone lsf`, `rclone copy --immutable --check-first --checksum`,
 * `rclone check` (with its differ/missing-on-src/missing-on-dst/error
 * classification) and `rclone cat` all run for real, with all their real
 * semantics, over the exact production path — and Backblaze B2 is never
 * contacted. No test here reaches the network.
 */
function releaseArchiveScript(string $name): string
{
    return base_path("infrastructure/scripts/{$name}");
}

/**
 * The real, installed rclone binary. Production defaults to the managed
 * external runtime at /usr/bin/rclone, which does not exist on a development
 * machine, so every test passes --rclone-bin explicitly — exactly as CI does
 * with the pinned binary it installs into the runner's temp directory.
 */
function releaseArchiveRcloneBin(): string
{
    static $path = null;

    if ($path === null) {
        $path = trim((string) shell_exec('command -v rclone'));
        expect($path)->not->toBe('', 'rclone must be installed on PATH to run these tests (e.g. `brew install rclone`)');
    }

    return $path;
}

function releaseArchiveScratch(): string
{
    $dir = sys_get_temp_dir().'/release-artifact-archive-'.uniqid('', true).'-'.getmypid();

    expect(@mkdir($dir.'/b2/rateguru-release-artifacts', 0o755, true))
        ->toBeTrue("could not create scratch directory: {$dir}");

    return $dir;
}

function releaseArchiveCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * A credential-shaped decoy lives in every configuration these tests use: a
 * second remote section carrying fake B2 material. Nothing in the archive or
 * fetch path may ever echo it, so any output containing it is a leak.
 */
const RELEASE_ARCHIVE_DECOY_KEY_ID = 'FAKEKEYID0123456789abcdef';
const RELEASE_ARCHIVE_DECOY_APPLICATION_KEY = 'FAKEAPPLICATIONKEYsupersecretvalue';

function releaseArchiveRcloneConfig(string $scratch, ?string $remoteRoot = null): string
{
    $path = $scratch.'/rclone-'.uniqid('', true).'.conf';
    $remoteRoot ??= $scratch.'/b2';

    file_put_contents($path, implode("\n", [
        '[rateguru-artifacts-b2]',
        'type = alias',
        'remote = '.$remoteRoot,
        '',
        '[decoy-credentials]',
        'type = b2',
        'account = '.RELEASE_ARCHIVE_DECOY_KEY_ID,
        'key = '.RELEASE_ARCHIVE_DECOY_APPLICATION_KEY,
        '',
    ]));

    chmod($path, 0o600);

    return $path;
}

function releaseArchiveRemoteDir(string $scratch, string $release): string
{
    return $scratch.'/b2/rateguru-release-artifacts/rateguru/artifacts/'.$release;
}

/**
 * A script with its comments and blank lines removed. These scripts document
 * what they deliberately do *not* depend on, so a naive whole-file search
 * would flag the very sentences that explain the guarantee.
 */
function releaseArchiveExecutableLines(string $path): string
{
    $lines = preg_split('/\R/', File::get($path));

    $code = array_filter($lines, function (string $line): bool {
        $trimmed = ltrim($line);

        return $trimmed !== '' && ! str_starts_with($trimmed, '#');
    });

    return implode("\n", $code);
}

/**
 * One release.json document. Passing null for a key removes it, which is how
 * "missing source_sha" is exercised without hand-writing JSON.
 *
 * @param  array<string, mixed>  $overrides
 */
function releaseArchiveMetadata(string $release, string $sourceSha, array $overrides = []): string
{
    $document = array_merge([
        'project' => 'rateguru',
        'environment' => 'staging',
        'source_ref' => 'develop',
        'source_sha' => $sourceSha,
        'release' => $release,
        'built_at' => '2026-08-30T12:00:00Z',
        'workflow_run_id' => '4242',
        'workflow_run_number' => '17',
    ], $overrides);

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($document[$key]);
        }
    }

    return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * Builds exactly what the build job produces: an immutable tarball with
 * release.json frozen inside it, a sha256sum sidecar, and the same
 * release.json exported beside the tarball.
 *
 * Options:
 *   release / source_sha      the canonical identity
 *   embedded / external       release.json overrides for the copy inside the
 *                             tarball and the copy beside it (external
 *                             defaults to a byte-identical copy of embedded)
 *   artifact_name             override the artifact filename
 *   corrupt_artifact          append bytes after the sidecar is written
 *   sidecar_names             make the sidecar describe a different file
 *   omit                      list of canonical files to leave out
 *
 * @param  array<string, mixed>  $options
 * @return array{release: string, dir: string, artifact: string, sha256: string, source_sha: string}
 */
function releaseArchivePackage(string $scratch, array $options = []): array
{
    $release = $options['release'] ?? 'v0.0.0-20260830-120000-abcdef1';
    $sourceSha = $options['source_sha'] ?? 'abcdef1234567890abcdef1234567890abcdef12';

    $id = uniqid('', true);
    $packageRoot = $scratch.'/pkg-'.$id;
    $dir = $scratch.'/out-'.$id;

    expect(@mkdir($packageRoot, 0o755, true))->toBeTrue("could not create {$packageRoot}");
    expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create {$dir}");

    file_put_contents($packageRoot.'/artisan', "#!/usr/bin/env php\n".($options['payload'] ?? 'application'));

    $embedded = releaseArchiveMetadata($release, $sourceSha, $options['embedded'] ?? []);
    file_put_contents($packageRoot.'/release.json', $embedded);

    $artifactName = $options['artifact_name'] ?? "rateguru-{$release}.tar.gz";

    $output = [];
    $exit = 0;
    exec(
        'tar -C '.escapeshellarg($packageRoot).' -czf '.escapeshellarg($dir.'/'.$artifactName).' . 2>&1',
        $output,
        $exit,
    );
    expect($exit)->toBe(0, 'could not build the fixture artifact: '.implode("\n", $output));

    $digest = hash_file('sha256', $dir.'/'.$artifactName);
    $sidecarNames = $options['sidecar_names'] ?? $artifactName;
    file_put_contents($dir.'/'.$artifactName.'.sha256', "{$digest}  {$sidecarNames}\n");

    if ($options['corrupt_artifact'] ?? false) {
        file_put_contents($dir.'/'.$artifactName, 'tampered', FILE_APPEND);
    }

    if (array_key_exists('external', $options)) {
        file_put_contents($dir.'/release.json', releaseArchiveMetadata($release, $sourceSha, $options['external']));
    } else {
        // The default is a byte-identical copy, exactly as the build job's
        // `cp` produces — one document, never two generated separately.
        copy($packageRoot.'/release.json', $dir.'/release.json');
    }

    foreach ($options['omit'] ?? [] as $name) {
        @unlink($dir.'/'.$name);
    }

    return [
        'release' => $release,
        'dir' => $dir,
        'artifact' => $artifactName,
        'sha256' => $digest,
        'source_sha' => $sourceSha,
    ];
}

/**
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function releaseArchiveRun(string $script, array $arguments): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', releaseArchiveScript($script)], $arguments),
        $descriptors,
        $pipes,
    );

    expect($process)->not->toBeFalse("could not start {$script}");

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * @param  list<string>  $extra
 * @return array{0: int, 1: string}
 */
function releaseArchiveArchive(string $release, string $sourceDir, string $config, array $extra = []): array
{
    return releaseArchiveRun('archive-release-artifact', array_merge([
        '--release', $release,
        '--source-dir', $sourceDir,
        '--rclone-config', $config,
        '--rclone-bin', releaseArchiveRcloneBin(),
    ], $extra));
}

/**
 * @param  list<string>  $extra
 * @return array{0: int, 1: string}
 */
function releaseArchiveFetch(string $release, string $destination, string $config, array $extra = []): array
{
    return releaseArchiveRun('fetch-release-artifact', array_merge([
        '--release', $release,
        '--destination', $destination,
        '--rclone-config', $config,
        '--rclone-bin', releaseArchiveRcloneBin(),
    ], $extra));
}

// --- the archive path --------------------------------------------------------

it('archives a valid release under the project-scoped remote path and verifies it', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);
        $report = $scratch.'/report.json';

        [$exit, $output] = releaseArchiveArchive(
            $package['release'],
            $package['dir'],
            $config,
            ['--report', $report],
        );

        expect($exit)->toBe(0, "archiving a valid release failed:\n{$output}");
        expect($output)->toContain('is durably archived and verified');

        // The canonical remote layout, asserted as a real directory tree:
        // <bucket>/rateguru/artifacts/<release-id>/ holding exactly three
        // files. No deployment target appears anywhere in the path.
        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);

        expect(File::isDirectory($remoteDir))->toBeTrue("expected remote release directory: {$remoteDir}");

        $entries = array_values(array_diff(scandir($remoteDir), ['.', '..']));
        sort($entries);

        expect($entries)->toBe([
            $package['artifact'],
            $package['artifact'].'.sha256',
            'release.json',
        ]);

        // The archived bytes are the built bytes, not a re-packed copy.
        expect(hash_file('sha256', $remoteDir.'/'.$package['artifact']))->toBe($package['sha256']);
        expect(File::get($remoteDir.'/release.json'))->toBe(File::get($package['dir'].'/release.json'));

        $decoded = json_decode(File::get($report), true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toMatchArray([
            'project' => 'rateguru',
            'release' => $package['release'],
            'source_sha' => $package['source_sha'],
            'bucket' => 'rateguru-release-artifacts',
            'remote_path' => 'rateguru/artifacts/'.$package['release'].'/',
            'artifact' => $package['artifact'],
            'sha256' => $package['sha256'],
            'upload' => 'upload',
            'verify' => 'pass',
            'result' => 'pass',
        ]);
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('uses the project, never the deployment target, as the archive namespace', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($exit)->toBe(0);

        // The exact bucket-relative prefix, and nothing target-shaped
        // anywhere in the produced tree: the same immutable application
        // artifact is a project artifact, and both staging-main and the
        // planned tits-guru would have to share it.
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scratch.'/b2', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $paths[] = str_replace($scratch.'/b2/', '', $file->getPathname());
        }

        expect($paths)->not->toBeEmpty();

        foreach ($paths as $path) {
            expect($path)->toStartWith('rateguru-release-artifacts/rateguru/artifacts/'.$package['release'].'/')
                ->not->toContain('staging-main')
                ->not->toContain('tits-guru')
                ->not->toContain('staging')
                ->not->toContain('production');
        }
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('uploads only the three canonical files, whatever else sits beside the artifact', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        // A build log, a stray debug note, an operator's scratch file — none
        // of it may ever be pushed into the release namespace. The upload
        // source is a private staging directory holding exactly three files,
        // never the caller's directory.
        file_put_contents($package['dir'].'/stray-debug-notes.txt', "SECRET-LEFTOVER\n");
        mkdir($package['dir'].'/nested');
        file_put_contents($package['dir'].'/nested/also-not-mine.txt', "nope\n");

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->toBe(0, "archiving failed:\n{$output}");

        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);
        $entries = array_values(array_diff(scandir($remoteDir), ['.', '..']));
        sort($entries);

        expect($entries)->toBe([
            $package['artifact'],
            $package['artifact'].'.sha256',
            'release.json',
        ]);
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('is idempotent when the identical release is already archived', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);
        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);
        $report = $scratch.'/report.json';

        [$firstExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($firstExit)->toBe(0);

        clearstatcache();
        $firstMtime = filemtime($remoteDir.'/'.$package['artifact']);
        $firstInode = fileinode($remoteDir.'/'.$package['artifact']);

        [$secondExit, $secondOutput] = releaseArchiveArchive(
            $package['release'],
            $package['dir'],
            $config,
            ['--report', $report],
        );

        expect($secondExit)->toBe(0, "a repeated archive of the identical release failed:\n{$secondOutput}");
        expect($secondOutput)
            ->toContain('The existing archive is already identical to this release')
            ->toContain('Nothing to upload')
            ->not->toContain('Uploading immutable release artifact');

        // Idempotent means untouched, not "re-uploaded with the same bytes".
        clearstatcache();
        expect(filemtime($remoteDir.'/'.$package['artifact']))->toBe($firstMtime);
        expect(fileinode($remoteDir.'/'.$package['artifact']))->toBe($firstInode);

        $decoded = json_decode(File::get($report), true, 512, JSON_THROW_ON_ERROR);
        expect($decoded['upload'])->toBe('none')
            ->and($decoded['result'])->toBe('pass');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('completes an incomplete archive without touching the objects already there', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);
        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);
        $report = $scratch.'/report.json';

        [$firstExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($firstExit)->toBe(0);

        // Simulate an upload that died after two of the three objects.
        unlink($remoteDir.'/release.json');

        clearstatcache();
        $artifactInode = fileinode($remoteDir.'/'.$package['artifact']);

        [$exit, $output] = releaseArchiveArchive(
            $package['release'],
            $package['dir'],
            $config,
            ['--report', $report],
        );

        expect($exit)->toBe(0, "resuming an incomplete archive failed:\n{$output}");
        expect($output)->toContain('The existing archive is incomplete; every object already there is identical');
        expect(File::exists($remoteDir.'/release.json'))->toBeTrue();

        clearstatcache();
        expect(fileinode($remoteDir.'/'.$package['artifact']))->toBe($artifactInode);

        $decoded = json_decode(File::get($report), true, 512, JSON_THROW_ON_ERROR);
        expect($decoded['upload'])->toBe('resume');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('hard-fails rather than overwrite a release ID already archived with different content', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);
        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);

        [$firstExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($firstExit)->toBe(0);

        $archivedDigest = hash_file('sha256', $remoteDir.'/'.$package['artifact']);

        // A second build claiming the same release ID, with different bytes.
        $conflicting = releaseArchivePackage($scratch, [
            'release' => $package['release'],
            'payload' => 'a completely different application build',
        ]);

        [$exit, $output] = releaseArchiveArchive($package['release'], $conflicting['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('already archived with different content — refusing to overwrite an immutable release');

        // Nothing was replaced, deleted or mutated.
        clearstatcache();
        expect(hash_file('sha256', $remoteDir.'/'.$package['artifact']))->toBe($archivedDigest);
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('hard-fails when an unexpected object already exists under the release ID', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);
        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);

        mkdir($remoteDir, 0o755, true);
        file_put_contents($remoteDir.'/rateguru-somebody-elses-build.tar.gz', 'foreign');

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('an unexpected object already exists under release');
        expect(File::get($remoteDir.'/rateguru-somebody-elses-build.tar.gz'))->toBe('foreign');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

// --- local validation, all of it before any upload ---------------------------

it('rejects malformed release IDs before doing anything else', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        $invalid = [
            'latest',
            'v0.0.0',
            'v0.0.0-20260830-120000',
            'v0.0.0-20260830-120000-ABCDEF1',
            'v0.0.0-20260830-120000-abcdef1/../../etc',
            'v0.0.0-2026083-120000-abcdef1',
            '../v0.0.0-20260830-120000-abcdef1',
            'v0.0.0-20260830-120000-abcdefg',
        ];

        foreach ($invalid as $release) {
            [$archiveExit, $archiveOutput] = releaseArchiveArchive($release, $package['dir'], $config);
            expect($archiveExit)->not->toBe(0, "archive accepted an invalid release ID: {$release}");
            expect($archiveOutput)->toContain("invalid release ID: {$release}");

            [$fetchExit, $fetchOutput] = releaseArchiveFetch($release, $scratch.'/never', $config);
            expect($fetchExit)->not->toBe(0, "fetch accepted an invalid release ID: {$release}");
            expect($fetchOutput)->toContain("invalid release ID: {$release}");
        }

        // Nothing invalid ever reached the remote.
        expect(File::isDirectory($scratch.'/b2/rateguru-release-artifacts/rateguru'))->toBeFalse();
        expect(File::isDirectory($scratch.'/never'))->toBeFalse();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects an artifact whose filename does not match the requested release', function () {
    $scratch = releaseArchiveScratch();

    try {
        // A package built for one release, archived as another.
        $package = releaseArchivePackage($scratch, ['release' => 'v0.0.0-20260830-120000-abcdef1']);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveArchive('v0.0.0-20260830-130000-abcdef1', $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release package is missing its artifact: rateguru-v0.0.0-20260830-130000-abcdef1.tar.gz');
        expect(File::isDirectory($scratch.'/b2/rateguru-release-artifacts/rateguru'))->toBeFalse();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects a checksum sidecar that describes a different file', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch, ['sidecar_names' => 'rateguru-some-other-build.tar.gz']);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('checksum sidecar describes rateguru-some-other-build.tar.gz');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects an artifact whose bytes do not match its checksum sidecar', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch, ['corrupt_artifact' => true]);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('artifact checksum mismatch');
        expect(File::isDirectory($scratch.'/b2/rateguru-release-artifacts/rateguru'))->toBeFalse();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects release.json that is not valid JSON', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        file_put_contents($package['dir'].'/release.json', "{ this is not json\n");

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release.json is not a valid JSON object');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects a release.json belonging to another project', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch, [
            'embedded' => ['project' => 'cataloghub'],
        ]);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release.json project is cataloghub, expected rateguru');
        expect(File::isDirectory($scratch.'/b2/rateguru-release-artifacts/rateguru'))->toBeFalse();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects a release.json naming a different release', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch, [
            'embedded' => ['release' => 'v0.0.0-20260101-000000-abcdef1'],
        ]);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release.json release is v0.0.0-20260101-000000-abcdef1, expected '.$package['release']);
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects a release.json with a missing or malformed source_sha', function () {
    $scratch = releaseArchiveScratch();

    try {
        $config = releaseArchiveRcloneConfig($scratch);

        $missing = releaseArchivePackage($scratch, ['embedded' => ['source_sha' => null]]);
        [$missingExit, $missingOutput] = releaseArchiveArchive($missing['release'], $missing['dir'], $config);

        expect($missingExit)->not->toBe(0);
        expect($missingOutput)->toContain('release.json source_sha is not a Git commit SHA: <missing>');

        $malformed = releaseArchivePackage($scratch, ['embedded' => ['source_sha' => 'not-a-sha']]);
        [$malformedExit, $malformedOutput] = releaseArchiveArchive($malformed['release'], $malformed['dir'], $config);

        expect($malformedExit)->not->toBe(0);
        expect($malformedOutput)->toContain('release.json source_sha is not a Git commit SHA: not-a-sha');

        // A well-formed SHA that simply is not this release's commit.
        $foreign = releaseArchivePackage($scratch, [
            'embedded' => ['source_sha' => str_repeat('9', 40)],
        ]);
        [$foreignExit, $foreignOutput] = releaseArchiveArchive($foreign['release'], $foreign['dir'], $config);

        expect($foreignExit)->not->toBe(0);
        expect($foreignOutput)->toContain('does not belong to source_sha');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects a release.json beside the tarball that differs from the one inside it', function () {
    $scratch = releaseArchiveScratch();

    try {
        // Same release, same project, same commit — only built_at differs.
        // Two independently generated documents are exactly what must never
        // be archived, because recovery could then read metadata the artifact
        // itself does not carry.
        $package = releaseArchivePackage($scratch, [
            'external' => ['built_at' => '2026-08-30T23:59:59Z'],
        ]);
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release.json inside the artifact differs from the release.json beside it');
        expect(File::isDirectory($scratch.'/b2/rateguru-release-artifacts/rateguru'))->toBeFalse();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('rejects a package missing any one of the three canonical files', function () {
    $scratch = releaseArchiveScratch();

    try {
        $config = releaseArchiveRcloneConfig($scratch);

        foreach ([
            'release.json' => 'release package is missing release.json',
            'sidecar' => 'release package is missing its checksum sidecar',
            'artifact' => 'release package is missing its artifact',
        ] as $missing => $expected) {
            $package = releaseArchivePackage($scratch);

            $name = match ($missing) {
                'release.json' => 'release.json',
                'sidecar' => $package['artifact'].'.sha256',
                default => $package['artifact'],
            };

            unlink($package['dir'].'/'.$name);

            [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

            expect($exit)->not->toBe(0, "archive accepted a package without {$name}");
            expect($output)->toContain($expected);
        }
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('requires its flags and rejects malformed arguments', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        $cases = [
            ['archive-release-artifact', [], '--release is required'],
            ['archive-release-artifact', ['--release', $package['release']], '--source-dir is required'],
            ['archive-release-artifact', ['--release', $package['release'], '--source-dir', $package['dir']], '--rclone-config is required'],
            ['archive-release-artifact', ['--release', $package['release'], '--release', $package['release']], '--release given more than once'],
            ['archive-release-artifact', ['--bogus'], 'unknown argument: --bogus'],
            ['archive-release-artifact', ['--release'], '--release requires a value'],
            ['archive-release-artifact', ['--release', '--source-dir'], '--release requires a value, got: --source-dir'],
            ['fetch-release-artifact', [], '--release is required'],
            ['fetch-release-artifact', ['--release', $package['release']], '--destination is required'],
            ['fetch-release-artifact', ['--release', $package['release'], '--destination', $scratch.'/never'], '--rclone-config is required'],
            ['fetch-release-artifact', ['--destination', $scratch.'/never', '--destination', $scratch.'/never'], '--destination given more than once'],
            ['fetch-release-artifact', ['--bogus'], 'unknown argument: --bogus'],
            ['fetch-verified-rclone', [], '--into is required'],
            ['fetch-verified-rclone', ['--bogus'], 'unknown argument: --bogus'],
        ];

        foreach ($cases as [$script, $arguments, $expected]) {
            [$exit, $output] = releaseArchiveRun($script, $arguments);

            expect($exit)->not->toBe(0, "{$script} accepted ".json_encode($arguments));
            expect($output)->toContain($expected);
        }

        foreach (['archive-release-artifact', 'fetch-release-artifact', 'fetch-verified-rclone'] as $script) {
            [$helpExit, $helpOutput] = releaseArchiveRun($script, ['--help']);
            expect($helpExit)->toBe(0);
            expect($helpOutput)->toContain($script);
        }

        [$bucketExit, $bucketOutput] = releaseArchiveArchive(
            $package['release'],
            $package['dir'],
            $config,
            ['--bucket', 'Not A Bucket'],
        );
        expect($bucketExit)->not->toBe(0);
        expect($bucketOutput)->toContain('invalid bucket name: Not A Bucket');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('fails closed when the object store cannot be reached, and never leaks credentials', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        // A remote pointed at a directory that does not exist: the bucket
        // probe must fail loudly rather than treating it as "nothing archived
        // yet" and silently reporting success.
        $config = releaseArchiveRcloneConfig($scratch, $scratch.'/no-such-remote-root');

        [$exit, $output] = releaseArchiveArchive($package['release'], $package['dir'], $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('cannot access release-artifact bucket rateguru-release-artifacts');
        expect($output)
            ->not->toContain(RELEASE_ARCHIVE_DECOY_KEY_ID)
            ->not->toContain(RELEASE_ARCHIVE_DECOY_APPLICATION_KEY);
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('never prints storage credentials on a successful archive or fetch', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        [$archiveExit, $archiveOutput] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($archiveExit)->toBe(0);

        [$fetchExit, $fetchOutput] = releaseArchiveFetch($package['release'], $scratch.'/recovered', $config);
        expect($fetchExit)->toBe(0);

        foreach ([$archiveOutput, $fetchOutput] as $output) {
            expect($output)
                ->not->toContain(RELEASE_ARCHIVE_DECOY_KEY_ID)
                ->not->toContain(RELEASE_ARCHIVE_DECOY_APPLICATION_KEY)
                ->not->toContain('account =')
                ->not->toContain('key =');
        }
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

// --- retrieval ---------------------------------------------------------------

it('retrieves an archived release and re-proves it end to end', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);
        $report = $scratch.'/fetch-report.json';

        [$archiveExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($archiveExit)->toBe(0);

        $destination = $scratch.'/recovered';

        [$exit, $output] = releaseArchiveFetch(
            $package['release'],
            $destination,
            $config,
            ['--report', $report],
        );

        expect($exit)->toBe(0, "retrieval failed:\n{$output}");
        expect($output)->toContain('retrieved and verified at '.$destination.'/'.$package['artifact']);

        // Byte-identical to what was built, not merely "a file arrived".
        expect(hash_file('sha256', $destination.'/'.$package['artifact']))->toBe($package['sha256']);
        expect(File::get($destination.'/release.json'))->toBe(File::get($package['dir'].'/release.json'));
        expect(File::get($destination.'/'.$package['artifact'].'.sha256'))
            ->toBe(File::get($package['dir'].'/'.$package['artifact'].'.sha256'));

        $decoded = json_decode(File::get($report), true, 512, JSON_THROW_ON_ERROR);
        expect($decoded)->toMatchArray([
            'project' => 'rateguru',
            'release' => $package['release'],
            'source_sha' => $package['source_sha'],
            'remote_path' => 'rateguru/artifacts/'.$package['release'].'/',
            'sha256' => $package['sha256'],
            'artifact_path' => $destination.'/'.$package['artifact'],
            'result' => 'pass',
        ]);
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('refuses to retrieve a release that was never archived', function () {
    $scratch = releaseArchiveScratch();

    try {
        $config = releaseArchiveRcloneConfig($scratch);

        [$exit, $output] = releaseArchiveFetch(
            'v0.0.0-20260101-000000-0000000',
            $scratch.'/recovered',
            $config,
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('is not archived in rateguru-release-artifacts/rateguru/artifacts/v0.0.0-20260101-000000-0000000/');
        expect(File::files($scratch.'/recovered'))->toBeEmpty();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('refuses to retrieve an incomplete archive', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        [$archiveExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($archiveExit)->toBe(0);

        unlink(releaseArchiveRemoteDir($scratch, $package['release']).'/'.$package['artifact'].'.sha256');

        [$exit, $output] = releaseArchiveFetch($package['release'], $scratch.'/recovered', $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('is incomplete or contains unexpected objects');
        expect(File::files($scratch.'/recovered'))->toBeEmpty();
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('fails when a retrieved artifact does not match its archived checksum', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        [$archiveExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($archiveExit)->toBe(0);

        // Bit rot / tampering after the fact, in storage.
        $remoteDir = releaseArchiveRemoteDir($scratch, $package['release']);
        file_put_contents($remoteDir.'/'.$package['artifact'], 'rotted', FILE_APPEND);

        [$exit, $output] = releaseArchiveFetch($package['release'], $scratch.'/recovered', $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('artifact checksum mismatch');
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

it('refuses to retrieve into a destination that already holds a canonical file', function () {
    $scratch = releaseArchiveScratch();

    try {
        $package = releaseArchivePackage($scratch);
        $config = releaseArchiveRcloneConfig($scratch);

        [$archiveExit] = releaseArchiveArchive($package['release'], $package['dir'], $config);
        expect($archiveExit)->toBe(0);

        $destination = $scratch.'/recovered';
        mkdir($destination, 0o755, true);
        file_put_contents($destination.'/release.json', "{}\n");

        [$exit, $output] = releaseArchiveFetch($package['release'], $destination, $config);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('destination already contains release.json — retrieve into a clean directory');
        expect(File::get($destination.'/release.json'))->toBe("{}\n");
    } finally {
        releaseArchiveCleanup($scratch);
    }
});

// --- the shape of the primitives themselves ----------------------------------

it('keeps both primitives independent of the installed target operations', function () {
    // A future consumer of fetch-release-artifact is a completely new host,
    // before any RateGuru target operation exists on it. Neither script may
    // depend on /home/www/rateguru/bin/common, a target registry, a
    // deployment configuration or a deployment target.
    foreach (['archive-release-artifact', 'fetch-release-artifact', 'release-artifact-common', 'fetch-verified-rclone'] as $name) {
        $source = releaseArchiveExecutableLines(releaseArchiveScript($name));

        expect($source)
            ->not->toContain('/home/www/rateguru/bin/common')
            ->not->toContain('deployment.conf')
            ->not->toContain('deployment-targets.json')
            ->not->toContain('require_active_target')
            ->not->toContain('target_environment_class')
            ->not->toContain('--target')
            ->not->toContain('staging-main')
            ->not->toContain('tits-guru');

        // The only file either script sources is the shared, self-contained
        // release-artifact library next to it.
        preg_match_all('/^\s*(?:source|\.)\s+(\S+)/m', $source, $matches);

        foreach ($matches[1] as $sourced) {
            expect($sourced)->toBe('"${SCRIPT_DIR}/release-artifact-common"');
        }
    }
});

it('never deletes, replaces or mutates anything already archived', function () {
    // Immutable semantics are a property of the code, not just of one test
    // run: no destructive rclone verb may appear in either script at all.
    foreach (['archive-release-artifact', 'fetch-release-artifact'] as $name) {
        $source = File::get(releaseArchiveScript($name));

        foreach (['delete', 'deletefile', 'purge', 'rmdir', 'rmdirs', 'sync', 'move', 'moveto', 'cleanup'] as $verb) {
            expect($source)->not->toMatch('/rclone_run\s+'.$verb.'\b/');
        }
    }

    $archive = File::get(releaseArchiveScript('archive-release-artifact'));

    // The one upload call is immutable and check-first, and there is exactly
    // one of them.
    expect(substr_count($archive, 'rclone_run copy'))->toBe(1);
    expect($archive)->toContain('--immutable');
});

it('builds the remote path from the fixed project namespace alone', function () {
    $library = File::get(releaseArchiveScript('release-artifact-common'));

    expect($library)
        ->toContain('RELEASE_ARTIFACT_PROJECT="rateguru"')
        ->toContain("printf '%s/artifacts/%s\\n' \"\${RELEASE_ARTIFACT_PROJECT}\" \"\$1\"")
        ->toContain('RELEASE_ARTIFACT_BUCKET_DEFAULT="rateguru-release-artifacts"');

    // The project namespace is never operator input: no script exposes a
    // --project flag that could move a RateGuru artifact into another
    // project's namespace.
    foreach (['archive-release-artifact', 'fetch-release-artifact', 'release-artifact-common'] as $name) {
        expect(releaseArchiveExecutableLines(releaseArchiveScript($name)))->not->toContain('--project');
    }
});
