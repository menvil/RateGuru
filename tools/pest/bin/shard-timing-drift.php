#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Says where tests/.pest/shards.json no longer describes what one Architecture
 * leg's files cost: Markdown for the job summary, and nothing at all while it
 * still does.
 *
 *   php tools/pest/bin/shard-timing-drift.php JUNIT.xml SHARDS.json
 *
 * The legs are planned from those timings. A file that now takes far longer
 * than recorded, or one with no timing at all (Pest hands those out
 * round-robin, after balancing the rest), makes its leg run long while the
 * other legs wait, and nothing fails. This is how that becomes visible before
 * anyone notices the run got slower. It never fails the run: a stale timings
 * file costs balance, not coverage.
 *
 * A slower runner makes every file slower. So the leg's own median of actual
 * against planned time is taken as this runner's speed, and a file is reported
 * only when it took at least half as long again as that speed predicts, and at
 * least fifteen seconds more.
 */

const DRIFT_RATIO = 1.5;
const DRIFT_SECONDS = 15.0;

// Files this short say more about start-up than about the runner's speed.
const SPEED_SAMPLE_SECONDS = 2.0;

[$junitPath, $shardsPath] = array_slice($argv, 1) + [null, null];

if ($junitPath === null || $shardsPath === null) {
    fwrite(STDERR, "usage: shard-timing-drift.php JUNIT.xml SHARDS.json\n");
    exit(2);
}

$planned = json_decode((string) @file_get_contents($shardsPath), true)['timings'] ?? null;
$junit = @simplexml_load_file($junitPath);

// Nothing to compare: a leg that stopped before it wrote its report, which the
// partition check already fails, or no timings file at all.
if (! is_array($planned) || $junit === false) {
    exit(0);
}

/** @var array<string, float> $actual */
$actual = [];

foreach ($junit->xpath('//testsuite[@file]') ?: [] as $suite) {
    $class = (string) $suite['name'];

    if (str_starts_with($class, 'Tests\\')) {
        $actual[$class] = max($actual[$class] ?? 0.0, (float) $suite['time']);
    }
}

$ratios = [];

foreach ($actual as $class => $seconds) {
    if (($planned[$class] ?? 0.0) >= SPEED_SAMPLE_SECONDS) {
        $ratios[] = $seconds / $planned[$class];
    }
}

sort($ratios);
$middle = intdiv(count($ratios), 2);
$speed = match (true) {
    $ratios === [] => 1.0,
    count($ratios) % 2 === 1 => $ratios[$middle],
    // Of two files, the slower one would raise its own threshold if the two
    // were averaged; the faster one is the better guess at the runner.
    count($ratios) === 2 => $ratios[0],
    default => ($ratios[$middle - 1] + $ratios[$middle]) / 2,
};

/** @var list<array{class: string, planned: ?float, took: float}> $rows */
$rows = [];

foreach ($actual as $class => $seconds) {
    if (! isset($planned[$class])) {
        $rows[] = ['class' => $class, 'planned' => null, 'took' => $seconds];

        continue;
    }

    $expected = $planned[$class] * $speed;

    if ($seconds >= $expected * DRIFT_RATIO && $seconds - $expected >= DRIFT_SECONDS) {
        $rows[] = ['class' => $class, 'planned' => (float) $planned[$class], 'took' => $seconds];
    }
}

if ($rows === []) {
    exit(0);
}

usort($rows, static fn (array $a, array $b): int => $b['took'] <=> $a['took']);

echo "### tests/.pest/shards.json no longer describes this leg\n\n";
echo 'The legs are planned from those timings, so these files make this leg run longer than planned while the others wait. ';
echo "Regenerate the file from the `architecture-junit-*` artifacts of a green develop run:\n\n";
echo "    php tools/pest/bin/shards-from-junit.php architecture-junit-*.xml > tests/.pest/shards.json\n\n";
echo "| Test class | Planned | Took |\n|---|---|---|\n";

foreach ($rows as $row) {
    $name = substr(strrchr('\\'.$row['class'], '\\') ?: $row['class'], 1);
    $plannedText = $row['planned'] === null ? 'not timed' : sprintf('%.0f s', $row['planned']);
    printf("| `%s` | %s | %.0f s |\n", $name, $plannedText, $row['took']);
}

printf("\nThis runner ran the leg's files at %.2f× their planned time; the planned times above are compared at that speed.\n", $speed);
