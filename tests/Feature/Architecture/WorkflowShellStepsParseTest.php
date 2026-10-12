<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Every bash step of every workflow and composite action parses.
 *
 * Bash reads a step's script one command at a time, so a quoting mistake late
 * in a step — an apostrophe inside a single-quoted jq program, say — is found
 * only when the runner reaches it: after everything before it has already run,
 * an operation on a host included. `bash -n` finds it here, before any run.
 */
it('parses every bash step of every workflow and composite action', function () {
    $files = [...glob(base_path('.github/workflows/*.yml')) ?: [], ...glob(base_path('.github/actions/*/action.yml')) ?: []];
    $checked = 0;

    foreach ($files as $path) {
        $document = Yaml::parse(File::get($path));
        $steps = $document['runs']['steps'] ?? [];
        foreach ($document['jobs'] ?? [] as $job) {
            $steps = [...$steps, ...$job['steps'] ?? []];
        }

        foreach ($steps as $step) {
            if (! isset($step['run']) || ! str_starts_with($step['shell'] ?? 'bash', 'bash')) {
                continue;
            }

            $process = proc_open(['bash', '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $step['run']);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);

            expect(proc_close($process))->toBe(0, basename(dirname($path)).'/'.basename($path).' — "'.($step['name'] ?? '(unnamed)')."\" does not parse:\n{$errors}");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(200);
});
