<?php

use Symfony\Component\Yaml\Yaml;

/*
 * The guard that CI's Architecture legs really are a partition of the suite.
 *
 * It exists because the check it replaces could not fail: the leg was validated
 * with `grep -E 'files? ran, out of [1-9][0-9]*'`, which matches "40 files ran,
 * out of 40" as readily as "11 files ran, out of 40". A planner that stopped
 * partitioning ran the whole suite on all four legs — four times the cost, same
 * coverage — and reported green, and so did a leg that ran nothing.
 *
 * The negative cases below are the point. A validator is only worth having if it
 * is shown to reject the thing it exists to reject — and two of these reject
 * failures that arithmetic alone cannot see.
 */

/**
 * @param  list<string>  $inputs  file contents, in argument order
 * @return array{status: int, output: string}
 */
function shardGuard(string $mode, array $inputs): array
{
    $paths = [];

    foreach ($inputs as $index => $content) {
        // The extension matters to nothing but readability; the tool reads what it
        // is given, in the order it is given.
        $paths[] = $path = sys_get_temp_dir().'/rateguru-shard-guard-'.bin2hex(random_bytes(5))."-{$index}";
        file_put_contents($path, $content);
    }

    $process = proc_open(
        [PHP_BINARY, base_path('tools/pest/bin/verify-shard-partition.php'), $mode, ...$paths],
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

        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    return ['status' => $status, 'output' => $stdout.$stderr];
}

/** The line Pest prints, as it appears in a run log. */
function shardLine(int $index, int $shards, int $ran, int $files): string
{
    return "  Tests:    412 passed\n  Shard:    {$index} of {$shards} — {$ran} files ran, out of {$files} (time-balanced).\n";
}

/**
 * A JUnit report shaped the way Pest writes one: an outer suite, one nested suite
 * per test class, each carrying the file it came from and the seconds it took.
 *
 * @param  list<string>  $classes
 * @param  array<string, float|int>  $seconds  by class; one second where not given
 */
function shardJunit(array $classes, array $seconds = []): string
{
    $suites = '';

    foreach ($classes as $class) {
        $file = 'tests/Feature/Architecture/'.str_replace('\\', '/', $class).'.php';
        $time = $seconds[$class] ?? 1;
        $suites .= "    <testsuite name=\"{$class}\" file=\"{$file}\" tests=\"3\" time=\"{$time}\"><testcase name=\"a\"/></testsuite>\n";
    }

    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n  <testsuite name=\"CLI Arguments\" tests=\"3\">\n{$suites}  </testsuite>\n</testsuites>\n";
}

/**
 * What shard-timing-drift.php says about a leg that took SECONDS, planned from
 * TIMINGS.
 *
 * @param  array<string, float|int>  $seconds  by class
 * @param  array<string, float|int>  $timings  by class, as tests/.pest/shards.json holds them
 * @return array{status: int, output: string}
 */
function shardDrift(array $seconds, array $timings): array
{
    $junit = sys_get_temp_dir().'/rateguru-shard-drift-'.bin2hex(random_bytes(5)).'.xml';
    $shards = sys_get_temp_dir().'/rateguru-shard-drift-'.bin2hex(random_bytes(5)).'.json';
    file_put_contents($junit, shardJunit(array_keys($seconds), $seconds));
    file_put_contents($shards, (string) json_encode(['timings' => $timings]));

    try {
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, base_path('tools/pest/bin/shard-timing-drift.php'), $junit, $shards])).' 2>&1', $output, $status);
    } finally {
        @unlink($junit);
        @unlink($shards);
    }

    return ['status' => $status, 'output' => implode("\n", $output)];
}

/** @param list<string> $classes */
function shardReport(int $index, int $shards, int $files, array $classes): string
{
    return (string) json_encode([
        'index' => $index,
        'shards' => $shards,
        'ran' => count($classes),
        'files' => $files,
        'classes' => $classes,
    ]);
}

/** @return list<string> */
function shardClasses(int $from, int $to): array
{
    return array_map(static fn (int $n): string => "Case{$n}Test", range($from, $to));
}

it('accepts a leg that ran its own share, and writes what it ran', function () {
    $result = shardGuard('leg', [shardLine(2, 4, 3, 68), shardJunit(shardClasses(1, 3))]);

    expect($result['status'])->toBe(0, $result['output']);

    $report = json_decode($result['output'], true);

    expect($report)->toMatchArray(['index' => 2, 'shards' => 4, 'ran' => 3, 'files' => 68])
        ->and($report['classes'])->toBe(['Case1Test', 'Case2Test', 'Case3Test']);
});

it('refuses a leg that ran the entire suite', function () {
    // The failure the old grep accepted.
    $result = shardGuard('leg', [shardLine(1, 4, 68, 68), shardJunit(shardClasses(1, 68))]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('the whole suite')
        ->and($result['output'])->toContain('green for the wrong reason');
});

it('refuses a leg that ran nothing', function () {
    $result = shardGuard('leg', [shardLine(3, 4, 0, 68), shardJunit(shardClasses(1, 1))]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('ran no files');
});

it('refuses a run with no shard line at all', function () {
    $result = shardGuard('leg', ["  Tests:    412 passed\n", shardJunit(shardClasses(1, 3))]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('no Pest shard line');
});

it('refuses a leg whose JUnit disagrees with the count it reported', function () {
    // The two sources have to describe the same run, or the names the combined
    // pass relies on are not the files that were executed.
    $result = shardGuard('leg', [shardLine(2, 4, 17, 68), shardJunit(shardClasses(1, 3))]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('the report and the run disagree');
});

it('reads the shard line through CI colour codes', function () {
    $coloured = "  \e[90mShard:\e[39m    \e[39m2 of 4\e[39m — 3 files ran, out of 68 \e[90m(time-balanced)\e[39m.\n";

    expect(shardGuard('leg', [$coloured, shardJunit(shardClasses(1, 3))])['status'])->toBe(0);
});

it('accepts four legs that partition the suite', function () {
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 2)),
        shardReport(2, 4, 8, shardClasses(3, 4)),
        shardReport(3, 4, 8, shardClasses(5, 6)),
        shardReport(4, 4, 8, shardClasses(7, 8)),
    ]);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('disjoint, complete, one shard each');
});

it('accepts legs of deliberately unequal size', function () {
    // Time-balanced, so one slow file can be a leg of its own. The contract is
    // that the parts are disjoint and complete, never that they are equal.
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 1)),
        shardReport(2, 4, 8, shardClasses(2, 5)),
        shardReport(3, 4, 8, shardClasses(6, 7)),
        shardReport(4, 4, 8, shardClasses(8, 8)),
    ]);

    expect($result['status'])->toBe(0, $result['output']);
});

it('refuses four legs that each ran the whole suite', function () {
    // The scenario the review asked be made to fail: 8 of 8, four times.
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 8)),
        shardReport(2, 4, 8, shardClasses(1, 8)),
        shardReport(3, 4, 8, shardClasses(1, 8)),
        shardReport(4, 4, 8, shardClasses(1, 8)),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('ran 32 files between them')
        ->and($result['output'])->toContain('files ran more than once');
});

it('refuses indices that are not shards 1 through N even when they add up', function () {
    // 1, 2, 3, 5: four legs, counts correct, every leg agreeing about everything —
    // and shard 4 was never run while shard 5 does not exist. Checked always, not
    // only when the number of legs is wrong, which is how this slipped through.
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 2)),
        shardReport(2, 4, 8, shardClasses(3, 4)),
        shardReport(3, 4, 8, shardClasses(5, 6)),
        shardReport(5, 4, 8, shardClasses(7, 8)),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('not shards 1..4')
        ->and($result['output'])->toContain('missing 4');
});

it('refuses an overlap that a gap pays for', function () {
    // The case arithmetic cannot see: shards 1 and 2 both run Case2Test, and
    // Case5Test runs nowhere. Eight files reported, eight files summed, one file
    // never executed.
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, ['Case1Test', 'Case2Test']),
        shardReport(2, 4, 8, ['Case2Test', 'Case3Test']),
        shardReport(3, 4, 8, ['Case4Test', 'Case6Test']),
        shardReport(4, 4, 8, ['Case7Test', 'Case8Test']),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('Case2Test ran on shard 1 and on shard 2')
        ->and($result['output'])->toContain('the legs overlap');
});

it('refuses legs whose names do not cover the suite', function () {
    // Counts right, indices right, nothing overlapping — and one fewer class named
    // than the suite has files, because a leg's count overstates its own list.
    //
    // Leg mode rejects that inconsistency at the source, so this is the combined
    // pass refusing to trust a report it was handed rather than re-deriving it: the
    // names are the authority on what ran, and if they fall short of the suite,
    // something ran nowhere however the arithmetic looks.
    $overstated = (string) json_encode([
        'index' => 4, 'shards' => 4, 'ran' => 2, 'files' => 8,
        'classes' => ['Case7Test'],
    ]);

    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 2)),
        shardReport(2, 4, 8, shardClasses(3, 4)),
        shardReport(3, 4, 8, shardClasses(5, 6)),
        $overstated,
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('something ran nowhere');
});

it('refuses a missing leg', function () {
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 2)),
        shardReport(2, 4, 8, shardClasses(3, 4)),
        shardReport(4, 4, 8, shardClasses(7, 8)),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('not shards 1..4');
});

it('refuses legs that disagree about the suite', function () {
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 2)),
        shardReport(2, 4, 9, shardClasses(3, 4)),
        shardReport(3, 4, 8, shardClasses(5, 6)),
        shardReport(4, 4, 8, shardClasses(7, 8)),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('disagree about how many test files');
});

it('refuses a duplicated shard index', function () {
    $result = shardGuard('combined', [
        shardReport(1, 4, 8, shardClasses(1, 2)),
        shardReport(1, 4, 8, shardClasses(3, 4)),
        shardReport(3, 4, 8, shardClasses(5, 6)),
        shardReport(4, 4, 8, shardClasses(7, 8)),
    ]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('reported twice');
});

it('is wired into the Architecture job, both per leg and across them', function () {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

    $legStep = collect($ci['jobs']['tests-architecture']['steps'])
        ->firstWhere('name', 'Check the shard ran its share');

    $combinedStep = collect($ci['jobs']['verify-shard-partition']['steps'])
        ->firstWhere('name', 'Verify the legs partition the suite');

    expect($legStep)->not->toBeNull()
        ->and($combinedStep)->not->toBeNull();

    // Both inputs, because the names the combined pass needs come from the JUnit.
    expect($legStep['run'])
        ->toContain('verify-shard-partition.php leg')
        ->toContain('architecture-pest.log')
        ->toContain('architecture-junit.xml');

    expect($combinedStep['run'])->toContain('verify-shard-partition.php combined');

    // And the check that could not fail is gone from what RUNS — asserted on the
    // step's own command, not on the file, because the comment above it quotes the
    // old grep to say why it went.
    expect(str_contains($legStep['run'], 'grep -E'))
        ->toBeFalse("the leg check must not be a grep for the numbers any more:\n{$legStep['run']}");

    // A failed leg proves nothing about the partition, so the cross-leg job must
    // not run on one.
    expect($ci['jobs']['verify-shard-partition']['needs'])->toBe(['tests-architecture'])
        ->and($ci['jobs']['verify-shard-partition'])->not->toHaveKey('if');

    // And its result has to reach the run's verdict — which is two things, not one:
    // the job has to be a dependency, and the step that decides the run's outcome
    // has to actually read it. A `needs` entry on its own only makes the summary
    // wait for the job.
    expect($ci['jobs']['ci-summary']['needs'])->toContain('verify-shard-partition');

    $verdict = collect($ci['jobs']['ci-summary']['steps'])->firstWhere('name', 'Verify CI result');

    expect($verdict)->not->toBeNull();

    expect($verdict['env'])->toHaveKey('SHARD_PARTITION_RESULT')
        ->and($verdict['env']['SHARD_PARTITION_RESULT'])->toContain("needs['verify-shard-partition'].result");

    expect(str_contains($verdict['run'], '$SHARD_PARTITION_RESULT'))
        ->toBeTrue("the verdict must read the shard partition result:\n{$verdict['run']}");
});

// shards.json drift -------------------------------------------------------------

it('says nothing while every file of a leg takes about what it was planned to', function () {
    expect(shardDrift(
        ['Tests\\AlphaTest' => 31, 'Tests\\BravoTest' => 19, 'Tests\\CharlieTest' => 10],
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10],
    ))->toBe(['status' => 0, 'output' => '']);
});

it('names a file that took far longer than planned, with both times and how to regenerate', function () {
    $drift = shardDrift(
        ['Tests\\AlphaTest' => 75, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10],
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10],
    );

    expect($drift['status'])->toBe(0)
        ->and($drift['output'])->toContain('| `AlphaTest` | 30 s | 75 s |')
        ->toContain('shards-from-junit.php architecture-junit-*.xml > tests/.pest/shards.json')
        ->not->toContain('BravoTest')
        ->not->toContain('CharlieTest');
});

it('tells a slower runner from stale timings: every file twice as slow is no drift', function () {
    expect(shardDrift(
        ['Tests\\AlphaTest' => 60, 'Tests\\BravoTest' => 40, 'Tests\\CharlieTest' => 20],
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10],
    ))->toBe(['status' => 0, 'output' => '']);
});

it('names a file the timings do not know, which Pest hands out round-robin', function () {
    $drift = shardDrift(
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20, 'Tests\\NewTest' => 40],
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20],
    );

    expect($drift['status'])->toBe(0)
        ->and($drift['output'])->toContain('| `NewTest` | not timed | 40 s |')
        ->not->toContain('AlphaTest');
});

it('lets a small overrun go, however large as a ratio', function () {
    expect(shardDrift(
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 12],
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 4],
    ))->toBe(['status' => 0, 'output' => '']);
});

it('names a file that took exactly half as long again as planned, well over fifteen seconds more', function () {
    $drift = shardDrift(
        ['Tests\\AlphaTest' => 60, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10, 'Tests\\DeltaTest' => 10],
        ['Tests\\AlphaTest' => 40, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10, 'Tests\\DeltaTest' => 10],
    );

    expect($drift['status'])->toBe(0)
        ->and($drift['output'])->toContain('| `AlphaTest` | 40 s | 60 s |');
});

it('names a file that took exactly fifteen seconds more than planned, well over half as long again', function () {
    $drift = shardDrift(
        ['Tests\\AlphaTest' => 35, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10, 'Tests\\DeltaTest' => 10],
        ['Tests\\AlphaTest' => 20, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10, 'Tests\\DeltaTest' => 10],
    );

    expect($drift['status'])->toBe(0)
        ->and($drift['output'])->toContain('| `AlphaTest` | 20 s | 35 s |');
});

it('lets a large overrun go when it stays under half as long again', function () {
    expect(shardDrift(
        ['Tests\\AlphaTest' => 140, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10, 'Tests\\DeltaTest' => 10],
        ['Tests\\AlphaTest' => 100, 'Tests\\BravoTest' => 20, 'Tests\\CharlieTest' => 10, 'Tests\\DeltaTest' => 10],
    ))->toBe(['status' => 0, 'output' => '']);
});

it('names the slow file of a leg with only two, which an average of the two would hide', function () {
    // Averaged, 30 -> 80 s and 20 -> 20 s give a runner speed of x1.83, and
    // 80 s is then under the x1.5 threshold of what that speed predicts.
    $drift = shardDrift(
        ['Tests\\AlphaTest' => 80, 'Tests\\BravoTest' => 20],
        ['Tests\\AlphaTest' => 30, 'Tests\\BravoTest' => 20],
    );

    expect($drift['status'])->toBe(0)
        ->and($drift['output'])->toContain('| `AlphaTest` | 30 s | 80 s |')
        ->not->toContain('BravoTest');
});

it('reports drift in every leg\'s job summary as a warning that never fails the run', function () {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $legStep = collect($ci['jobs']['tests-architecture']['steps'])->firstWhere('name', 'Check the shard ran its share');

    expect($legStep['run'])
        ->toContain('php tools/pest/bin/shard-timing-drift.php architecture-junit.xml tests/.pest/shards.json || true')
        ->toContain('>> "${GITHUB_STEP_SUMMARY}"')
        ->toContain('::warning');
});
