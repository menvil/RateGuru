<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/*
 * Every GitHub Actions job runs on an explicitly named runner image, never on
 * a moving label.
 *
 * `ubuntu-latest` is repointed by GitHub on its own schedule, gradually and
 * without a change in this repository: during a migration some runs of the
 * same workflow land on the old image and some on the new one. The jobs here
 * do not only build and test — they deploy, roll back, restore and recover
 * real hosts, and a large part of the Architecture suite drives the shell
 * scripts those operations ship. An image change is therefore a change that
 * has to be tested and made deliberately, in one commit that names the new
 * image, not something that happens to a production rollback mid-flight.
 */

const PINNED_RUNNER_IMAGE = 'ubuntu-24.04';

/** @return array<string, array<string, mixed>> workflow file => parsed workflow */
function workflowsForRunnerCheck(): array
{
    $workflows = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflows[basename($path)] = Yaml::parse(File::get($path));
    }

    return $workflows;
}

it('runs every job on the pinned runner image', function () {
    $workflows = workflowsForRunnerCheck();
    $jobs = 0;
    $offenders = [];

    expect($workflows)->not->toBeEmpty();

    foreach ($workflows as $file => $workflow) {
        foreach ((array) data_get($workflow, 'jobs', []) as $name => $job) {
            // A job that calls a reusable workflow has no runner of its own.
            if (isset($job['uses'])) {
                continue;
            }

            $jobs++;

            if (($job['runs-on'] ?? null) !== PINNED_RUNNER_IMAGE) {
                $offenders[] = "{$file}:{$name} runs on ".json_encode($job['runs-on'] ?? null);
            }
        }
    }

    expect($jobs)->toBeGreaterThan(0)
        ->and($offenders)->toBe([]);
});

it('never names a moving runner label anywhere in a workflow', function () {
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        expect(File::get($path))->not->toMatch('/\bubuntu-latest\b/');
    }
});
