#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Checks that a sharded Architecture run actually partitioned its files.
 *
 *   php tools/pest/bin/verify-shard-partition.php leg RUN.log JUNIT.xml > shard-N.json
 *   php tools/pest/bin/verify-shard-partition.php combined shard-*.json
 *
 * Why this exists. The leg used to be checked with
 *
 *   grep -E 'files? ran, out of [1-9][0-9]*'
 *
 * which matches "40 files ran, out of 40" exactly as happily as "11 files ran,
 * out of 40". So a planner that stopped partitioning — every leg running the
 * whole suite, four times the work for the same coverage — reported green, and
 * so did a leg that ran nothing. The numbers were printed and never read.
 *
 * Two modes, because one leg cannot see the others:
 *
 *   leg       parses Pest's own Shard line, refuses a leg that ran nothing or
 *             ran the entire suite while claiming to be one of several, and
 *             writes what it ran — index, counts, and the NAMES of the test
 *             classes, taken from its JUnit report.
 *
 *   combined  refuses a set of legs that is not a partition: indices that are
 *             not exactly 1..N, a disagreement about how many files exist,
 *             counts that do not add up, a class two legs both ran, or a class
 *             no leg ran.
 *
 * The names matter and counts alone are not enough: two legs can overlap on one
 * file while a third file goes unrun, and the sums still reach the total. That
 * is the "incorrect shard assignment" case, and it is invisible to arithmetic.
 *
 * File counts per leg are deliberately NOT expected to be equal: the legs are
 * balanced by committed per-file timings, so one slow file can legitimately be a
 * leg of its own. What must hold is that the parts are disjoint and complete.
 */
final class ShardPartitionError extends RuntimeException {}

/** @return array{index: int, ran: int, files: int, shards: int} */
function parseShardLine(string $log): array
{
    // Pest: "  Shard:    2 of 4 — 17 files ran, out of 68 (time-balanced)."
    // Colour codes are stripped first; a CI log keeps them.
    $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $log);

    if (preg_match('/Shard:\s*(\d+)\s+of\s+(\d+)\s*\S*\s*(\d+)\s+files?\s+ran,\s+out\s+of\s+(\d+)/', $plain, $m) !== 1) {
        throw new ShardPartitionError('no Pest shard line in the run output: the leg did not shard at all, or Pest changed how it reports it');
    }

    return ['index' => (int) $m[1], 'shards' => (int) $m[2], 'ran' => (int) $m[3], 'files' => (int) $m[4]];
}

/**
 * The test classes a leg actually ran, from its own JUnit report.
 *
 * @return list<string>
 */
function parseRanClasses(string $junitPath): array
{
    $xml = @simplexml_load_string((string) @file_get_contents($junitPath));

    if ($xml === false) {
        throw new ShardPartitionError("unreadable JUnit report: {$junitPath}");
    }

    $classes = [];

    // Pest nests one testsuite per class inside an outer suite.
    foreach ($xml->xpath('//testsuite[@file]') ?: [] as $suite) {
        $classes[] = (string) $suite['name'];
    }

    $classes = array_values(array_unique($classes));

    if ($classes === []) {
        throw new ShardPartitionError("the JUnit report names no test class: {$junitPath}");
    }

    return $classes;
}

/** @param array{index: int, ran: int, files: int, shards: int} $leg */
function assertLegIsAShare(array $leg): void
{
    if ($leg['files'] < 1) {
        throw new ShardPartitionError('the suite reported no test files at all');
    }

    if ($leg['ran'] < 1) {
        throw new ShardPartitionError("shard {$leg['index']} of {$leg['shards']} ran no files: its share of the suite was not executed anywhere");
    }

    if ($leg['index'] < 1 || $leg['index'] > $leg['shards']) {
        throw new ShardPartitionError("shard index {$leg['index']} is outside 1..{$leg['shards']}");
    }

    if ($leg['shards'] > 1 && $leg['ran'] >= $leg['files']) {
        throw new ShardPartitionError(
            "shard {$leg['index']} of {$leg['shards']} ran {$leg['ran']} of {$leg['files']} files — the whole suite. "
            .'The partition is not being applied, so every leg is repeating the same work and the run is green for the wrong reason'
        );
    }
}

/**
 * @param  list<array{index: int, ran: int, files: int, shards: int, classes: list<string>}>  $reports
 */
function assertCombinedIsAPartition(array $reports): void
{
    if ($reports === []) {
        throw new ShardPartitionError('no leg reports at all');
    }

    $legs = [];

    foreach ($reports as $report) {
        foreach (['index', 'ran', 'files', 'shards', 'classes'] as $key) {
            if (! array_key_exists($key, $report)) {
                throw new ShardPartitionError("a leg report is missing [{$key}]");
            }
        }

        if (isset($legs[$report['index']])) {
            throw new ShardPartitionError("shard {$report['index']} reported twice: the legs are not distinct shards");
        }

        $legs[$report['index']] = $report;
    }

    $shards = array_values(array_unique(array_column($legs, 'shards')));
    $files = array_values(array_unique(array_column($legs, 'files')));

    if (count($shards) !== 1) {
        throw new ShardPartitionError('the legs disagree about how many shards there are: '.implode(', ', $shards));
    }

    if (count($files) !== 1) {
        throw new ShardPartitionError('the legs disagree about how many test files exist: '.implode(', ', $files));
    }

    [$expectedShards, $expectedFiles] = [$shards[0], $files[0]];

    // Always, not only when the count happens to be wrong: four legs numbered
    // 1, 2, 3, 5 are four legs, add up, and agree about everything — and one
    // shard of the four was never run while another ran twice.
    ksort($legs);
    $indices = array_keys($legs);
    $expected = range(1, $expectedShards);

    if ($indices !== $expected) {
        throw new ShardPartitionError(
            'the legs are not shards 1..'.$expectedShards.': got '.implode(', ', $indices)
            .'; missing '.(implode(', ', array_diff($expected, $indices)) ?: 'none')
        );
    }

    $ran = array_sum(array_column($legs, 'ran'));

    if ($ran !== $expectedFiles) {
        $direction = $ran > $expectedFiles ? 'files ran more than once' : 'files ran nowhere';

        throw new ShardPartitionError(
            "the legs ran {$ran} files between them, but the suite has {$expectedFiles}: {$direction}"
        );
    }

    // And the names, because arithmetic cannot see an overlap that a gap pays
    // for: two legs both running one file while a third file goes unrun leaves
    // the sum exactly right.
    $seen = [];

    foreach ($legs as $index => $leg) {
        foreach ($leg['classes'] as $class) {
            if (isset($seen[$class])) {
                throw new ShardPartitionError(
                    "{$class} ran on shard {$seen[$class]} and on shard {$index}: the legs overlap"
                );
            }

            $seen[$class] = $index;
        }
    }

    if (count($seen) !== $expectedFiles) {
        throw new ShardPartitionError(
            'the legs ran '.count($seen)." distinct test classes between them, but the suite has {$expectedFiles} files: "
            .(count($seen) < $expectedFiles ? 'something ran nowhere' : 'the reports name more classes than the suite has files')
        );
    }
}

if (PHP_SAPI !== 'cli' || basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'verify-shard-partition.php') {
    return;
}

$mode = $argv[1] ?? '';
$paths = array_slice($argv, 2);

if (! in_array($mode, ['leg', 'combined'], true) || $paths === []) {
    fwrite(STDERR, "usage: verify-shard-partition.php leg RUN.log JUNIT.xml > shard-N.json\n       verify-shard-partition.php combined SHARD.json [SHARD.json ...]\n");
    exit(2);
}

try {
    if ($mode === 'leg') {
        if (count($paths) !== 2) {
            throw new ShardPartitionError('leg mode needs the run log and the JUnit report');
        }

        $leg = parseShardLine((string) file_get_contents($paths[0]));
        assertLegIsAShare($leg);

        $leg['classes'] = parseRanClasses($paths[1]);

        if (count($leg['classes']) !== $leg['ran']) {
            throw new ShardPartitionError(
                'shard '.$leg['index'].' reported '.$leg['ran'].' files but its JUnit names '
                .count($leg['classes']).' test classes: the report and the run disagree'
            );
        }

        fwrite(STDOUT, (string) json_encode($leg, JSON_PRETTY_PRINT)."\n");
        exit(0);
    }

    assertCombinedIsAPartition(array_map(
        static function (string $path): array {
            $report = json_decode((string) file_get_contents($path), true);

            if (! is_array($report)) {
                throw new ShardPartitionError("unreadable leg report: {$path}");
            }

            return $report;
        },
        $paths,
    ));

    fwrite(STDOUT, "the Architecture legs partition the suite: disjoint, complete, one shard each\n");
    exit(0);
} catch (ShardPartitionError $error) {
    fwrite(STDERR, 'Architecture sharding is broken: '.$error->getMessage()."\n");
    exit(1);
}
