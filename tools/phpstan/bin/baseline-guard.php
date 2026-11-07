<?php

declare(strict_types=1);

use RateGuru\PHPStan\Support\BaselineInventory;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: baseline-guard.php <base-baseline> [current-baseline]\n");
    exit(2);
}

$base = BaselineInventory::fromFile($argv[1]);
$current = BaselineInventory::fromFile($argv[2] ?? dirname(__DIR__, 3).'/phpstan-baseline.neon');
$violations = $current->growthComparedTo($base);

if ($current->architectureFindings() > 0) {
    $violations[] = 'Architecture findings with rateguru.* identifiers must never be suppressed.';
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations).PHP_EOL);
    exit(1);
}

printf(
    "PHPStan baseline did not grow (%d -> %d findings).\n",
    $base->findings(),
    $current->findings(),
);
