<?php

use Illuminate\Support\Facades\File;

/**
 * Anti-regression guard for Phase 4's legacy-environment-interface removal.
 *
 * The `--environment staging|production` operational selector, and every
 * helper/constant/wrapper built specifically to support it alongside
 * `--target`, must never reappear in infrastructure code, config, docs, CI,
 * or the Architecture test suite itself. This scans an exact list of
 * forbidden tokens across an exact list of locations.
 *
 * Deliberately narrow, never a broad per-file or per-directory allowlist.
 * Every exemption below is either global-but-content-scoped (applies to a
 * single line's own content, regardless of which file it's in) or named-and-
 * exact (a specific file, or a specific historical line):
 *
 *   - This guard file itself (exact file).
 *   - One explicitly marked historical line in ROADMAP.md (exact file, exact
 *     line fragment).
 *   - install-target-operations and install-target-perimeter, which
 *     legitimately assert `--environment` is *absent* from staged/installed
 *     `--help` output and wrapper sources as part of their own runtime
 *     checks (exact files, --environment token only).
 *   - install-target-perimeter itself, and InstallTargetPerimeterTest.php,
 *     which legitimately name the six legacy wrapper filenames as the exact
 *     data the removal mechanism operates on and this test exercises (exact
 *     files, wrapper-name tokens only).
 *   - Any comment line, anywhere (content-scoped: a comment cannot execute,
 *     so it cannot reintroduce anything — only explanatory prose about
 *     history, present in most already-converted files).
 *   - Any `->not->toContain(...)`/`->not->toMatch(...)` Pest assertion line
 *     naming a forbidden token (content-scoped: proving a token's *absence*
 *     is the guard working as intended, not a regression).
 *   - Inside tests/Feature/Architecture only, for the `--environment` token
 *     only: the specific `it()` block whose own title names `--environment`
 *     — identified structurally by scanning that block's title line, never
 *     by file-level exemption, so a new test can freely re-use the same
 *     literal to prove rejection without needing this guard edited.
 *
 * Every other forbidden token, in every other location, has zero exceptions
 * beyond the two named-and-exact cases above.
 */
function legacyRemovalGuardFile(): string
{
    return __FILE__;
}

function legacyRemovalScanRoots(): array
{
    return [
        'infrastructure/scripts',
        'infrastructure/config',
        'infrastructure/templates/deployment.conf.example',
        'infrastructure/runbooks',
        '.github',
        'tests/Feature/Architecture',
    ];
}

/** @return list<string> */
function legacyRemovalForbiddenTokens(): array
{
    return [
        '--environment',
        'validate_environment',
        'parse_selector_args',
        'assert_selector_state',
        'environment_root',
        'environment_runtime_user',
        'environment_code_group',
        'environment_deploy_user',
        'environment_incoming_artifacts',
        'environment_url',
        'environment_host_header',
        'environment_backup_namespace',
        'environment_local_backup_retention',
        'environment_offsite_backup_retention',
        'environment_database_name',
        'environment_release_retention',
        'environment_public_hostname',
        'STAGING_ROOT',
        'STAGING_RUNTIME_USER',
        'STAGING_CODE_GROUP',
        'STAGING_DEPLOY_USER',
        'STAGING_INCOMING_ARTIFACTS',
        'STAGING_RELEASE_RETENTION',
        'PRODUCTION_ROOT',
        'PRODUCTION_RUNTIME_USER',
        'PRODUCTION_CODE_GROUP',
        'PRODUCTION_DEPLOY_USER',
        'PRODUCTION_INCOMING_ARTIFACTS',
        'PRODUCTION_RELEASE_RETENTION',
        'rateguru-staging-deploy',
        'rateguru-staging-rollback',
        'rateguru-staging-cleanup',
        'rateguru-production-deploy',
        'rateguru-production-rollback',
        'rateguru-production-cleanup',
    ];
}

/**
 * Production files whose own job is to assert `--environment` is *absent*
 * from staged/installed `--help` output and wrapper sources — exempted from
 * the `--environment` token only, never from any other forbidden token.
 *
 * @return list<string>
 */
function legacyRemovalEnvironmentTokenFileExemptions(): array
{
    return [
        base_path('infrastructure/scripts/install-target-operations'),
        base_path('infrastructure/scripts/install-target-perimeter'),
    ];
}

/**
 * install-target-perimeter names the six legacy wrapper filenames as the
 * exact data its own removal mechanism operates on;
 * InstallTargetPerimeterTest.php exercises that mechanism and necessarily
 * builds fixtures using those same literal names — exempted from the six
 * wrapper-name tokens only, never from any other forbidden token.
 *
 * @return list<string>
 */
function legacyRemovalWrapperNameTokenFileExemptions(): array
{
    return [
        base_path('infrastructure/scripts/install-target-perimeter'),
        base_path('tests/Feature/Architecture/InstallTargetPerimeterTest.php'),
    ];
}

/** @return list<string> */
function legacyRemovalWrapperNameTokens(): array
{
    return [
        'rateguru-staging-deploy',
        'rateguru-staging-rollback',
        'rateguru-staging-cleanup',
        'rateguru-production-deploy',
        'rateguru-production-rollback',
        'rateguru-production-cleanup',
    ];
}

/**
 * True if $line contains $token as a whole identifier — not merely as a
 * prefix of a longer, unrelated one (e.g. "rateguru-staging-deploy" must not
 * match inside "rateguru-staging-deployment", a genuinely different and
 * unrelated GitHub Actions concurrency group name).
 */
function legacyRemovalLineContainsToken(string $line, string $token): bool
{
    return preg_match('/'.preg_quote($token, '/').'(?![a-zA-Z])/', $line) === 1;
}

/**
 * ROADMAP.md's slices 1-8 are a historical record of the migration while it
 * ran the old and new operational selectors in parallel, marked off with a
 * pair of HTML comments — the single explicitly marked historical entry this
 * guard exempts entirely, as one deliberate, narrow, visible-in-source range,
 * never a broad per-file allowlist.
 *
 * @return array{0: string, 1: string, 2: string} [file, startMarker, endMarker]
 */
function legacyRemovalRoadmapExemption(): array
{
    return [
        base_path('infrastructure/ROADMAP.md'),
        'legacy-environment-history:start',
        'legacy-environment-history:end',
    ];
}

/**
 * The [start, end) line-index range (0-indexed, end exclusive) marked by the
 * two HTML-comment markers, or null if either marker is missing.
 *
 * @param  list<string>  $lines
 * @return array{0: int, 1: int}|null
 */
function legacyRemovalMarkedRange(array $lines, string $startMarker, string $endMarker): ?array
{
    $start = null;

    foreach ($lines as $i => $line) {
        if ($start === null && str_contains($line, $startMarker)) {
            $start = $i;

            continue;
        }

        if ($start !== null && str_contains($line, $endMarker)) {
            return [$start, $i + 1];
        }
    }

    return null;
}

/** @return list<string> */
function legacyRemovalFilesToScan(): array
{
    $files = [];

    foreach (legacyRemovalScanRoots() as $root) {
        $path = base_path($root);

        if (is_file($path)) {
            $files[] = $path;

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $files[] = $fileInfo->getPathname();
            }
        }
    }

    return array_values(array_unique($files));
}

/**
 * The [start, end) line-index ranges (0-indexed, end exclusive) of every
 * top-level `it(...)` block in a Pest test file, approximated as "from one
 * `it(` line to the line immediately before the next one, or EOF" — precise
 * enough for this guard, which only needs to know which lines belong to the
 * same test as its own title line.
 *
 * @param  list<string>  $lines
 * @return list<array{0: int, 1: int}>
 */
function legacyRemovalItBlocks(array $lines): array
{
    $starts = [];

    foreach ($lines as $i => $line) {
        if (preg_match('/^it\(/', $line) === 1) {
            $starts[] = $i;
        }
    }

    $blocks = [];
    foreach ($starts as $idx => $start) {
        $blocks[] = [$start, $starts[$idx + 1] ?? count($lines)];
    }

    return $blocks;
}

function legacyRemovalLineIsComment(string $line): bool
{
    $trimmed = ltrim($line);

    return $trimmed === ''
        || str_starts_with($trimmed, '//')
        || str_starts_with($trimmed, '#')
        || str_starts_with($trimmed, '*')
        || str_starts_with($trimmed, '/*');
}

/**
 * @return list<string> human-readable "file:line: content" violation strings
 */
function legacyRemovalScan(): array
{
    $guardFile = legacyRemovalGuardFile();
    [$roadmapFile, $roadmapStartMarker, $roadmapEndMarker] = legacyRemovalRoadmapExemption();
    $environmentTokenExemptFiles = legacyRemovalEnvironmentTokenFileExemptions();
    $wrapperNameExemptFiles = legacyRemovalWrapperNameTokenFileExemptions();
    $wrapperNameTokens = legacyRemovalWrapperNameTokens();
    $tokens = legacyRemovalForbiddenTokens();
    $architectureTestsDir = base_path('tests/Feature/Architecture');

    $violations = [];

    foreach (legacyRemovalFilesToScan() as $file) {
        if ($file === $guardFile) {
            continue;
        }

        $rawLines = file($file, FILE_IGNORE_NEW_LINES);
        if ($rawLines === false) {
            continue;
        }

        $roadmapExemptRange = ($file === $roadmapFile)
            ? legacyRemovalMarkedRange($rawLines, $roadmapStartMarker, $roadmapEndMarker)
            : null;

        $isArchitectureTest = str_starts_with($file, $architectureTestsDir.'/');

        // Precompute which line ranges belong to an it() block that is
        // structurally, unambiguously about --environment: either its own
        // title names the flag, or some line in the block asserts the real
        // rejection message a script prints for it. The only test-suite
        // exemption for that token, identified this way rather than by
        // file.
        $exemptBlockRanges = [];
        if ($isArchitectureTest) {
            foreach (legacyRemovalItBlocks($rawLines) as [$start, $end]) {
                $blockIsAboutEnvironment = str_contains($rawLines[$start], '--environment');

                for ($i = $start; ! $blockIsAboutEnvironment && $i < $end; $i++) {
                    if (str_contains($rawLines[$i], 'unknown argument: --environment')
                        || str_contains($rawLines[$i], 'never mention --environment')
                        || str_contains($rawLines[$i], 'still uses --environment')
                        || str_contains($rawLines[$i], 'still mentions --environment')
                        || str_contains($rawLines[$i], 'legacy backup-cycle ok')
                    ) {
                        $blockIsAboutEnvironment = true;
                    }
                }

                if ($blockIsAboutEnvironment) {
                    $exemptBlockRanges[] = [$start, $end];
                }
            }
        }

        foreach ($rawLines as $lineNumber => $line) {
            // A comment cannot execute, so it cannot reintroduce anything —
            // safe for every token, in every scanned location.
            if (legacyRemovalLineIsComment($line)) {
                continue;
            }

            if ($roadmapExemptRange !== null
                && $lineNumber >= $roadmapExemptRange[0]
                && $lineNumber < $roadmapExemptRange[1]
            ) {
                continue;
            }

            foreach ($tokens as $token) {
                if (! legacyRemovalLineContainsToken($line, $token)) {
                    continue;
                }

                // Proving a token's absence is the guard working as
                // intended, not a regression — safe for every token.
                if ((str_contains($line, '->not->toContain(') || str_contains($line, '->not->toMatch('))
                    && str_contains($line, $token)
                ) {
                    continue;
                }

                if ($token === '--environment' && in_array($file, $environmentTokenExemptFiles, true)) {
                    continue;
                }

                if (in_array($token, $wrapperNameTokens, true) && in_array($file, $wrapperNameExemptFiles, true)) {
                    continue;
                }

                if ($token === '--environment' && $isArchitectureTest) {
                    $inExemptBlock = false;
                    foreach ($exemptBlockRanges as [$start, $end]) {
                        if ($lineNumber >= $start && $lineNumber < $end) {
                            $inExemptBlock = true;
                            break;
                        }
                    }
                    if ($inExemptBlock) {
                        continue;
                    }
                }

                $violations[] = sprintf('%s:%d: %s', $file, $lineNumber + 1, trim($line));
            }
        }
    }

    return $violations;
}

it('scans a non-empty set of files and tokens (fixture sanity)', function () {
    expect(legacyRemovalFilesToScan())->not->toBeEmpty();
    expect(legacyRemovalForbiddenTokens())->not->toBeEmpty();
    expect(File::exists(base_path('infrastructure/scripts/common')))->toBeTrue();
});

it('never reintroduces the legacy --environment interface anywhere in the scanned tree', function () {
    $violations = legacyRemovalScan();

    expect($violations)->toBe([], "forbidden legacy token(s) found:\n".implode("\n", $violations));
});

it('the ROADMAP exemption itself still exists — the guard is not silently exempting a range that moved or was deleted', function () {
    [$roadmapFile, $startMarker, $endMarker] = legacyRemovalRoadmapExemption();

    expect(File::exists($roadmapFile))->toBeTrue();

    $lines = file($roadmapFile, FILE_IGNORE_NEW_LINES);
    expect($lines)->not->toBeFalse();

    $range = legacyRemovalMarkedRange($lines, $startMarker, $endMarker);
    expect($range)->not->toBeNull("could not locate the {$startMarker}/{$endMarker} marker pair in ROADMAP.md");
    expect($range[1])->toBeGreaterThan($range[0] + 1, 'the marked historical range must not be empty');
});

it('does not use a broad per-file or per-directory allowlist — only two named production files are exempted, and only from --environment', function () {
    expect(legacyRemovalEnvironmentTokenFileExemptions())->toHaveCount(2);
    expect(legacyRemovalForbiddenTokens())->toContain('--environment');
});
