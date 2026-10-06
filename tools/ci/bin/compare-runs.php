#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Compares CI runs, so that "this change made CI faster" is a measurement and
 * not an impression. The same commit takes anywhere from 1x to 1.5x as long
 * depending on the runner it lands on, so every figure here is a median over
 * several runs — one run against one run proves nothing.
 *
 *   php tools/ci/bin/compare-runs.php jobs  BEFORE_RUN_IDS [AFTER_RUN_IDS] [--repo=OWNER/NAME]
 *   php tools/ci/bin/compare-runs.php junit BEFORE_REPORTS [AFTER_REPORTS]
 *
 * Each list is comma-separated. `jobs` reads every run's jobs through
 * `gh api` and reports the run's wall time (first job queued to last job
 * done), each job, and each step that takes ten seconds or more. With
 * --from=DIR it reads DIR/<run id>.json instead of calling GitHub. `junit`
 * reads one JUnit report per run — the reports the test jobs upload as
 * artifacts — and reports the summed test time of each test directory, which
 * shows where time moved when a job's total changed.
 *
 * Both print a Markdown table that can go straight into a pull request.
 */

const STEP_THRESHOLD_SECONDS = 10.0;

$arguments = array_slice($argv, 1);
$options = [];

foreach ($arguments as $index => $argument) {
    if (preg_match('/^--(repo|from)=(.+)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
        unset($arguments[$index]);
    }
}

[$mode, $before, $after] = array_pad(array_values($arguments), 3, null);

if (! in_array($mode, ['jobs', 'junit'], true) || $before === null) {
    fwrite(STDERR, "usage: compare-runs.php jobs BEFORE_RUN_IDS [AFTER_RUN_IDS] [--repo=OWNER/NAME] [--from=DIR]\n");
    fwrite(STDERR, "       compare-runs.php junit BEFORE_REPORTS [AFTER_REPORTS]\n");
    exit(2);
}

$groups = array_values(array_filter([$before, $after], fn (?string $list): bool => $list !== null));
$groups = array_map(fn (string $list): array => array_values(array_filter(explode(',', $list))), $groups);

try {
    $samples = array_map(
        fn (array $items): array => array_map(
            fn (string $item): array => $mode === 'jobs' ? jobSample($item, $options) : junitSample($item),
            $items,
        ),
        $groups,
    );
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

echo table($samples, array_map('count', $groups), sortByTime: $mode === 'junit');

/**
 * One run's metrics: label => seconds. Labels keep the order they were first
 * seen in, which is the order GitHub lists jobs and steps.
 *
 * @param  array{repo?: string, from?: string}  $options
 * @return array<string, float>
 */
function jobSample(string $runId, array $options): array
{
    if (preg_match('/^\d+$/', $runId) !== 1) {
        throw new RuntimeException("not a run id: {$runId}");
    }

    $json = isset($options['from'])
        ? @file_get_contents($options['from']."/{$runId}.json")
        : gh(['api', 'repos/'.($options['repo'] ?? currentRepository())."/actions/runs/{$runId}/jobs?per_page=100"]);

    $jobs = is_string($json) ? (json_decode($json, true)['jobs'] ?? null) : null;

    if (! is_array($jobs) || $jobs === []) {
        throw new RuntimeException("no jobs found for run {$runId}");
    }

    $metrics = ['run wall time' => 0.0];
    $queued = INF;
    $finished = -INF;

    foreach ($jobs as $job) {
        $start = seconds($job['started_at'] ?? null);
        $end = seconds($job['completed_at'] ?? null);

        if ($start === null || $end === null) {
            continue;
        }

        $queued = min($queued, seconds($job['created_at'] ?? null) ?? $start);
        $finished = max($finished, $end);
        $metrics[$job['name']] = $end - $start;

        foreach ($job['steps'] ?? [] as $step) {
            $stepStart = seconds($step['started_at'] ?? null);
            $stepEnd = seconds($step['completed_at'] ?? null);

            if ($stepStart !== null && $stepEnd !== null && ($step['conclusion'] ?? null) !== 'skipped') {
                $metrics[$job['name'].' › '.$step['name']] = $stepEnd - $stepStart;
            }
        }
    }

    $metrics['run wall time'] = $finished - $queued;

    return $metrics;
}

/**
 * One report's metrics: summed test time per test directory, plus the total.
 * The directory comes from the test's class, so class-based and closure-based
 * tests are grouped the same way.
 *
 * @return array<string, float>
 */
function junitSample(string $path): array
{
    $xml = @simplexml_load_file($path);

    if ($xml === false) {
        throw new RuntimeException("not a readable JUnit report: {$path}");
    }

    $metrics = ['all tests (summed)' => 0.0];

    foreach ($xml->xpath('//testcase') as $case) {
        $segments = explode('\\', preg_replace('/^P\\\\/', '', (string) $case['class']));
        $directory = 'tests/'.implode('/', array_slice($segments, 1, max(1, min(2, count($segments) - 2))));
        $time = (float) $case['time'];

        $metrics['all tests (summed)'] += $time;
        $metrics[$directory] = ($metrics[$directory] ?? 0.0) + $time;
    }

    if (count($metrics) === 1) {
        throw new RuntimeException("no test cases in {$path}");
    }

    return $metrics;
}

/**
 * @param  list<list<array<string, float>>>  $samples  one list of run samples per group
 * @param  list<int>  $sizes
 * @param  bool  $sortByTime  slowest first after the leading total, instead of the order first seen
 */
function table(array $samples, array $sizes, bool $sortByTime = false): string
{
    $labels = [];

    foreach ($samples as $group) {
        foreach ($group as $sample) {
            $labels += array_fill_keys(array_keys($sample), true);
        }
    }

    $medians = array_map(function (array $group) use ($labels): array {
        $values = [];

        foreach (array_keys($labels) as $label) {
            $observed = array_values(array_filter(array_column($group, $label), 'is_float'));
            $values[$label] = $observed === [] ? null : median($observed);
        }

        return $values;
    }, $samples);

    if ($sortByTime) {
        $order = array_slice(array_keys($labels), 1);
        usort($order, fn (string $a, string $b): int => ($medians[0][$b] ?? 0.0) <=> ($medians[0][$a] ?? 0.0));
        $labels = array_fill_keys([array_key_first($labels), ...$order], true);
    }

    $compare = count($medians) === 2;
    $lines = $compare
        ? ["| | before (median of {$sizes[0]}) | after (median of {$sizes[1]}) | change |", '|---|---:|---:|---:|']
        : ["| | median of {$sizes[0]} |", '|---|---:|'];

    foreach (array_keys($labels) as $label) {
        $values = array_column($medians, $label);
        $isStep = str_contains($label, ' › ');

        if ($isStep && max(array_map(fn (?float $value): float => $value ?? 0.0, $values)) < STEP_THRESHOLD_SECONDS) {
            continue;
        }

        $cells = array_map('duration', $values);

        if ($compare) {
            $cells[] = $values[0] !== null && $values[1] !== null ? change($values[0], $values[1]) : '—';
        }

        $lines[] = '| '.($isStep ? '  '.$label : $label).' | '.implode(' | ', $cells).' |';
    }

    return implode("\n", $lines)."\n";
}

/** @param  list<float>  $values */
function median(array $values): float
{
    sort($values);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

function duration(?float $seconds): string
{
    if ($seconds === null) {
        return '—';
    }

    $rounded = (int) round($seconds);

    return $rounded < 60 ? "{$rounded} s" : sprintf('%d:%02d', intdiv($rounded, 60), $rounded % 60);
}

function change(float $before, float $after): string
{
    $delta = (int) round($after - $before);
    $percent = $before > 0 ? (int) round(($after - $before) / $before * 100) : 0;

    return sprintf('%s%d s (%s%d %%)', $delta > 0 ? '+' : ($delta < 0 ? '−' : '±'), abs($delta), $percent > 0 ? '+' : ($percent < 0 ? '−' : '±'), abs($percent));
}

function seconds(?string $timestamp): ?float
{
    $parsed = $timestamp === null ? false : strtotime($timestamp);

    return $parsed === false ? null : (float) $parsed;
}

function currentRepository(): string
{
    return trim(gh(['repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner']));
}

/** @param  list<string>  $arguments */
function gh(array $arguments): string
{
    $process = proc_open(['gh', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('could not start gh');
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new RuntimeException('gh '.implode(' ', $arguments).' failed: '.trim($stderr));
    }

    return $stdout;
}
