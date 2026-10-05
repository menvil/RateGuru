<?php

use Illuminate\Support\Facades\File;
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
 * is shown to reject the thing it exists to reject.
 */

function shardGuard(string $mode, array $inputs): array
{
    $script = base_path('tools/pest/bin/verify-shard-partition.php');
    $paths = [];

    foreach ($inputs as $index => $content) {
        $paths[] = $path = sys_get_temp_dir().'/rateguru-shard-guard-'.bin2hex(random_bytes(5))."-{$index}.txt";
        File::put($path, $content);
    }

    $process = proc_open(
        [PHP_BINARY, $script, $mode, ...$paths],
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
            File::delete($path);
        }
    }

    return ['status' => $status, 'output' => $stdout.$stderr];
}

/** The line Pest prints, as it appears in a run log. */
function shardLine(int $index, int $shards, int $ran, int $files): string
{
    return "  Tests:    412 passed\n  Shard:    {$index} of {$shards} — {$ran} files ran, out of {$files} (time-balanced).\n";
}

it('accepts a leg that ran its own share', function () {
    $result = shardGuard('leg', [shardLine(2, 4, 17, 68)]);

    expect($result['status'])->toBe(0, $result['output'])
        // And hands the combined pass the numbers it parsed.
        ->and(trim($result['output']))->toBe('2 17 68 4');
});

it('refuses a leg that ran the entire suite', function () {
    // The failure the old grep accepted.
    $result = shardGuard('leg', [shardLine(1, 4, 68, 68)]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('the whole suite')
        ->and($result['output'])->toContain('green for the wrong reason');
});

it('refuses a leg that ran nothing', function () {
    $result = shardGuard('leg', [shardLine(3, 4, 0, 68)]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('ran no files');
});

it('refuses a run with no shard line at all', function () {
    $result = shardGuard('leg', ["  Tests:    412 passed\n"]);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('no Pest shard line');
});

it('reads the shard line through CI colour codes', function () {
    $coloured = "  \e[90mShard:\e[39m    \e[39m2 of 4\e[39m — 17 files ran, out of 68 \e[90m(time-balanced)\e[39m.\n";

    expect(shardGuard('leg', [$coloured])['status'])->toBe(0);
});

it('accepts four legs that add up to the suite', function () {
    $result = shardGuard('combined', ['1 17 68 4', '2 17 68 4', '3 17 68 4', '4 17 68 4']);

    expect($result['status'])->toBe(0, $result['output']);
});

it('accepts legs of deliberately unequal size', function () {
    // Time-balanced, so one slow file can be a leg of its own. The contract is
    // that the parts add up, never that they are the same size.
    $result = shardGuard('combined', ['1 1 68 4', '2 30 68 4', '3 30 68 4', '4 7 68 4']);

    expect($result['status'])->toBe(0, $result['output']);
});

it('refuses four legs that each ran the whole suite', function () {
    // The exact scenario the review asked be made to fail: 68 of 68, four times.
    $result = shardGuard('combined', ['1 68 68 4', '2 68 68 4', '3 68 68 4', '4 68 68 4']);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('ran 272 files between them')
        ->and($result['output'])->toContain('files ran more than once');
});

it('refuses a missing leg', function () {
    $result = shardGuard('combined', ['1 17 68 4', '2 17 68 4', '4 17 68 4']);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('missing: 3');
});

it('refuses legs that do not cover the suite', function () {
    $result = shardGuard('combined', ['1 17 68 4', '2 17 68 4', '3 17 68 4', '4 10 68 4']);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('files ran nowhere');
});

it('refuses legs that disagree about the suite', function () {
    $result = shardGuard('combined', ['1 17 68 4', '2 17 70 4', '3 17 68 4', '4 17 68 4']);

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('disagree about how many test files');
});

it('refuses a duplicated shard index', function () {
    $result = shardGuard('combined', ['1 17 68 4', '1 17 68 4', '3 17 68 4', '4 17 68 4']);

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

    expect($legStep['run'])->toContain('verify-shard-partition.php leg');
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

    // And its result has to reach the run's verdict.
    expect($ci['jobs']['ci-summary']['needs'])->toContain('verify-shard-partition');
});
