#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Checks that a sharded Architecture run actually partitioned its files.
 *
 *   php tools/pest/bin/verify-shard-partition.php leg architecture-pest.log
 *   php tools/pest/bin/verify-shard-partition.php combined shard-*.txt
 *
 * Why this exists. The leg used to be checked with
 *
 *   grep -E 'files? ran, out of [1-9][0-9]*'
 *
 * which matches "40 files ran, out of 40" exactly as happily as "11 files ran,
 * out of 40". So a planner that stopped partitioning — every leg running the
 * whole suite, four times the work for the same coverage — reported green, and
 * so did a leg that ran nothing at all. The numbers were printed and never read.
 *
 * Two modes, because one leg cannot see the others:
 *
 *   leg       parses Pest's own Shard line and refuses a leg that ran nothing,
 *             or that ran the entire suite while claiming to be one of several.
 *             Writes `INDEX RAN TOTAL_FILES TOTAL_SHARDS` for the combined pass.
 *
 *   combined  reads every leg's line and refuses a set that is not a partition:
 *             a disagreement about how many files exist, a leg that never
 *             reported, a duplicate index, or counts that do not add up to the
 *             total. Over-counting means files ran twice; under-counting means
 *             files ran nowhere, which is the dangerous direction.
 *
 * File counts per leg are deliberately NOT expected to be equal: the legs are
 * balanced by committed per-file timings, so one slow file can legitimately be a
 * leg of its own. What must hold is that the parts add up to the whole.
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

/** @param array{index: int, ran: int, files: int, shards: int} $leg */
function assertLegIsAShare(array $leg): void
{
    if ($leg['files'] < 1) {
        throw new ShardPartitionError('the suite reported no test files at all');
    }

    if ($leg['ran'] < 1) {
        throw new ShardPartitionError("shard {$leg['index']} of {$leg['shards']} ran no files: its share of the suite was not executed anywhere");
    }

    if ($leg['shards'] > 1 && $leg['ran'] >= $leg['files']) {
        throw new ShardPartitionError(
            "shard {$leg['index']} of {$leg['shards']} ran {$leg['ran']} of {$leg['files']} files — the whole suite. "
            .'The partition is not being applied, so every leg is repeating the same work and the run is green for the wrong reason'
        );
    }
}

/**
 * @param  list<string>  $reports  one "INDEX RAN FILES SHARDS" line each
 */
function assertCombinedIsAPartition(array $reports): void
{
    $legs = [];

    foreach ($reports as $report) {
        $parts = preg_split('/\s+/', trim($report)) ?: [];

        if (count($parts) !== 4) {
            throw new ShardPartitionError("unreadable leg report: {$report}");
        }

        [$index, $ran, $files, $shards] = array_map('intval', $parts);

        if (isset($legs[$index])) {
            throw new ShardPartitionError("shard {$index} reported twice: the legs are not distinct shards");
        }

        $legs[$index] = ['ran' => $ran, 'files' => $files, 'shards' => $shards];
    }

    if ($legs === []) {
        throw new ShardPartitionError('no leg reports at all');
    }

    $shards = array_unique(array_column($legs, 'shards'));
    $files = array_unique(array_column($legs, 'files'));

    if (count($shards) !== 1) {
        throw new ShardPartitionError('the legs disagree about how many shards there are: '.implode(', ', $shards));
    }

    if (count($files) !== 1) {
        throw new ShardPartitionError('the legs disagree about how many test files exist: '.implode(', ', $files));
    }

    $expectedShards = $shards[array_key_first($shards)];
    $expectedFiles = $files[array_key_first($files)];

    if (count($legs) !== $expectedShards) {
        $missing = array_values(array_diff(range(1, $expectedShards), array_keys($legs)));

        throw new ShardPartitionError(
            'only '.count($legs)." of {$expectedShards} shards reported; missing: ".implode(', ', $missing)
        );
    }

    $ran = array_sum(array_column($legs, 'ran'));

    if ($ran !== $expectedFiles) {
        $direction = $ran > $expectedFiles
            ? 'files ran more than once'
            : 'files ran nowhere';

        throw new ShardPartitionError(
            "the legs ran {$ran} files between them, but the suite has {$expectedFiles}: {$direction}"
        );
    }
}

if (PHP_SAPI !== 'cli' || basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'verify-shard-partition.php') {
    return;
}

$mode = $argv[1] ?? '';
$paths = array_slice($argv, 2);

if (! in_array($mode, ['leg', 'combined'], true) || $paths === []) {
    fwrite(STDERR, "usage: verify-shard-partition.php leg RUN.log\n       verify-shard-partition.php combined SHARD.txt [SHARD.txt ...]\n");
    exit(2);
}

try {
    if ($mode === 'leg') {
        $leg = parseShardLine((string) file_get_contents($paths[0]));
        assertLegIsAShare($leg);

        fwrite(STDOUT, "{$leg['index']} {$leg['ran']} {$leg['files']} {$leg['shards']}\n");
        exit(0);
    }

    assertCombinedIsAPartition(array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        $paths,
    ));

    fwrite(STDOUT, "the Architecture legs partition the suite\n");
    exit(0);
} catch (ShardPartitionError $error) {
    fwrite(STDERR, 'Architecture sharding is broken: '.$error->getMessage()."\n");
    exit(1);
}
