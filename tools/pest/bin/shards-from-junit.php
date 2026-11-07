#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Writes tests/.pest/shards.json — the per-class timings Pest's `--shard`
 * uses to balance test files across CI legs — from one or more JUnit reports.
 *
 *   php tools/pest/bin/shards-from-junit.php architecture-junit-*.xml > tests/.pest/shards.json
 *
 * Pest can write this file itself (`--update-shards`), but only after a run
 * that passed in full and never from a sharded run, so the legs' own JUnit
 * artifacts are the source: download them from a green run of develop and
 * regenerate whenever Pest warns that the file is out of date (a test file was
 * added, split or renamed). Only `timings` is read back; `checksum` and
 * `updated_at` describe the file for a reviewer.
 *
 * A class seen in several reports keeps the largest time: the point is to
 * place the heaviest files first, and the slowest observation is the safe one.
 */

$reports = array_slice($argv, 1);

if ($reports === []) {
    fwrite(STDERR, "usage: shards-from-junit.php REPORT.xml [REPORT.xml ...] > tests/.pest/shards.json\n");
    exit(2);
}

$timings = [];

foreach ($reports as $report) {
    $xml = @simplexml_load_file($report);

    if ($xml === false) {
        fwrite(STDERR, "not a readable JUnit report: {$report}\n");
        exit(1);
    }

    // Pest records one <testsuite> per test class, carrying its file and the
    // summed time of its tests; the per-file suites are the ones with a `file`.
    foreach ($xml->xpath('//testsuite[@file]') as $suite) {
        $class = (string) $suite['name'];
        $seconds = round((float) $suite['time'], 4);

        if (! str_starts_with($class, 'Tests\\')) {
            continue;
        }

        $timings[$class] = max($timings[$class] ?? 0.0, $seconds);
    }
}

if ($timings === []) {
    fwrite(STDERR, "no test classes found in the given reports\n");
    exit(1);
}

ksort($timings);

$classes = array_keys($timings);

echo json_encode([
    'timings' => $timings,
    'checksum' => md5(implode("\n", $classes)),
    'updated_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
