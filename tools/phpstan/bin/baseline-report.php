<?php

declare(strict_types=1);

use RateGuru\PHPStan\Support\BaselineInventory;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$options = getopt('', ['baseline::', 'base::', 'github-output::']);
$root = dirname(__DIR__, 3);
$baselinePath = is_string($options['baseline'] ?? null)
    ? $options['baseline']
    : $root.'/phpstan-baseline.neon';
$inventory = BaselineInventory::fromFile($baselinePath);
$base = is_string($options['base'] ?? null)
    ? BaselineInventory::fromFile($options['base'])
    : null;
$report = [
    'findings' => $inventory->findings(),
    'patterns' => $inventory->patterns(),
    'files' => $inventory->files(),
    'architecture_findings' => $inventory->architectureFindings(),
    'delta' => $base instanceof BaselineInventory ? $inventory->findingsDeltaFrom($base) : 0,
    'by_identifier' => $inventory->findingsByIdentifier(),
    'entries' => $inventory->entries(),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

$githubOutput = $options['github-output'] ?? null;

if (is_string($githubOutput) && $githubOutput !== '') {
    $lines = [
        'baseline-findings='.$report['findings'],
        'baseline-patterns='.$report['patterns'],
        'baseline-files='.$report['files'],
        'baseline-delta='.$report['delta'],
        'architecture-suppressions='.$report['architecture_findings'],
    ];

    file_put_contents($githubOutput, implode(PHP_EOL, $lines).PHP_EOL, FILE_APPEND);
}
