<?php

/*
 * tools/ci/bin/compare-runs.php is what a pull request that claims to make CI
 * faster quotes as its evidence, so its arithmetic is pinned here: medians, not
 * single runs; the run's wall time measured from the first job queued; steps
 * under ten seconds left out; JUnit time grouped by test directory.
 */

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $files  name => contents, written to a scratch directory passed as {dir}
 * @return array{status: int, output: string}
 */
function compareRunsTool(array $arguments, array $files = []): array
{
    $directory = sys_get_temp_dir().'/rateguru-compare-runs-'.bin2hex(random_bytes(5));
    mkdir($directory);

    foreach ($files as $name => $contents) {
        file_put_contents("{$directory}/{$name}", $contents);
    }

    $process = proc_open(
        [PHP_BINARY, base_path('tools/ci/bin/compare-runs.php'), ...str_replace('{dir}', $directory, $arguments)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );

    try {
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        foreach (glob("{$directory}/*") ?: [] as $path) {
            unlink($path);
        }

        rmdir($directory);
    }

    return ['status' => $status, 'output' => $stdout.$stderr];
}

/**
 * One run as GitHub's jobs API returns it: a Postgres test job whose test step
 * takes $testSeconds, and a 30-second build job. Every run is queued at 10:00:00.
 */
function compareRunsJobs(int $testSeconds): string
{
    $at = fn (int $seconds): string => gmdate('Y-m-d\TH:i:s\Z', 1_800_000_000 + $seconds);

    return json_encode(['jobs' => [
        [
            'name' => 'Tests (Pest + PostgreSQL)',
            'created_at' => $at(0),
            'started_at' => $at(20),
            'completed_at' => $at(80 + $testSeconds),
            'steps' => [
                ['name' => 'Checkout', 'conclusion' => 'success', 'started_at' => $at(20), 'completed_at' => $at(22)],
                ['name' => 'Run PostgreSQL tests', 'conclusion' => 'success', 'started_at' => $at(60), 'completed_at' => $at(60 + $testSeconds)],
                ['name' => 'Upload Laravel logs on failure', 'conclusion' => 'skipped', 'started_at' => $at(60 + $testSeconds), 'completed_at' => $at(80 + $testSeconds)],
            ],
        ],
        [
            'name' => 'Frontend build (Vite)',
            'created_at' => $at(0),
            'started_at' => $at(5),
            'completed_at' => $at(35),
            'steps' => [],
        ],
    ]], JSON_THROW_ON_ERROR);
}

it('compares the medians of two groups of runs, job by job and step by step', function () {
    $result = compareRunsTool(['jobs', '1,2', '3,4', '--from={dir}'], [
        '1.json' => compareRunsJobs(180),
        '2.json' => compareRunsJobs(240),
        '3.json' => compareRunsJobs(60),
        '4.json' => compareRunsJobs(80),
    ]);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])
        ->toContain('| | before (median of 2) | after (median of 2) | change |')
        // Wall time runs from the first job queued (10:00:00) to the last job
        // done: 80 s of setup and teardown around the test step.
        ->toContain('| run wall time | 4:50 | 2:30 | −140 s (−48 %) |')
        ->toContain('| Tests (Pest + PostgreSQL) | 4:30 | 2:10 | −140 s (−52 %) |')
        ->toContain('|   Tests (Pest + PostgreSQL) › Run PostgreSQL tests | 3:30 | 1:10 | −140 s (−67 %) |')
        ->toContain('| Frontend build (Vite) | 30 s | 30 s | ±0 s (±0 %) |')
        // A two-second step is noise, and a skipped step did not run at all.
        ->not->toContain('Checkout')
        ->not->toContain('Upload Laravel logs on failure');
});

it('summarises a single group of runs without a change column', function () {
    $result = compareRunsTool(['jobs', '1,2,3', '--from={dir}'], [
        '1.json' => compareRunsJobs(100),
        '2.json' => compareRunsJobs(300),
        '3.json' => compareRunsJobs(110),
    ]);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])
        ->toContain('| | median of 3 |')
        // The median, not the mean: one slow runner does not move it.
        ->toContain('|   Tests (Pest + PostgreSQL) › Run PostgreSQL tests | 1:50 |')
        ->not->toContain('change');
});

it('groups JUnit test time by test directory, slowest first', function () {
    $report = fn (float $auth, float $media): string => <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites><testsuite name="" tests="4">
          <testcase name="logs in" class="Tests\\Feature\\Auth\\LoginTest" time="{$auth}"/>
          <testcase name="resizes" class="P\\Tests\\Feature\\Services\\Media\\ResizeTest" time="{$media}"/>
          <testcase name="has a role" class="Tests\\Unit\\Enums\\RoleTest" time="1.0"/>
          <testcase name="boots" class="Tests\\Feature\\ShellTest" time="2.0"/>
        </testsuite></testsuites>
        XML;

    $result = compareRunsTool(['junit', '{dir}/before.xml', '{dir}/after.xml'], [
        'before.xml' => $report(30.0, 12.0),
        'after.xml' => $report(10.0, 12.0),
    ]);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])
        ->toContain('| all tests (summed) | 45 s | 25 s | −20 s (−44 %) |')
        ->toContain('| tests/Feature/Auth | 30 s | 10 s | −20 s (−67 %) |')
        // Nested directories fold into their top-level area under Feature or Unit.
        ->toContain('| tests/Feature/Services | 12 s | 12 s | ±0 s (±0 %) |')
        ->toContain('| tests/Unit/Enums | 1 s | 1 s | ±0 s (±0 %) |')
        ->toContain('| tests/Feature | 2 s | 2 s | ±0 s (±0 %) |')
        // Ordered by the before-time, so the directory worth looking at comes first.
        ->toMatch('/all tests \\(summed\\).*\\n.*tests\\/Feature\\/Auth .*\\n.*tests\\/Feature\\/Services .*\\n.*tests\\/Feature .*\\n.*tests\\/Unit\\/Enums /');
});

it('refuses input it cannot measure', function (array $arguments, array $files, int $status, string $message) {
    $result = compareRunsTool($arguments, $files);

    expect($result['status'])->toBe($status)
        ->and($result['output'])->toContain($message);
})->with([
    'no mode' => [[], [], 2, 'usage:'],
    'unknown mode' => [['steps', '1'], [], 2, 'usage:'],
    'a run id that is not a number' => [['jobs', '1;rm', '--from={dir}'], [], 1, 'not a run id: 1;rm'],
    'a run with no jobs' => [['jobs', '7', '--from={dir}'], ['7.json' => '{"jobs":[]}'], 1, 'no jobs found for run 7'],
    'an unreadable report' => [['junit', '{dir}/missing.xml'], [], 1, 'not a readable JUnit report'],
    'a report with no test cases' => [['junit', '{dir}/empty.xml'], ['empty.xml' => '<testsuites/>'], 1, 'no test cases in'],
]);
