<?php

/*
 * infrastructure/scripts/health-check: what a deploy, a restore and a recovery
 * each run to decide whether a target serves. It needs /up and the home page
 * to answer, both through the target's loopback vhost, with its host header.
 *
 * The real script runs against the real common and the committed registry.
 * curl and sleep are stubs: curl answers each path with the statuses the test
 * sets, one per request and the last one from then on, and records what it
 * was asked.
 */

/**
 * @param  array<string, int|list<int>>  $statuses  HTTP statuses by path: '/up', '/'
 * @return array{exit: int, output: string, requests: list<string>}
 */
function healthCheckRun(array $statuses): array
{
    $scratch = deployOpsScratchDir();

    try {
        file_put_contents($scratch.'/http-statuses', implode('', array_map(
            static fn (string $path, int|array $status): string => $path."\t".implode(',', (array) $status)."\n",
            array_keys($statuses),
            $statuses,
        )));

        writeExecutable($scratch.'/bin/curl', <<<'BASH'
            #!/bin/bash
            url="${*: -1}"
            host=""
            previous=""
            for argument in "$@"; do
                [[ "${previous}" == --header ]] && host="${argument}"
                previous="${argument}"
            done
            path="${url#http://127.0.0.1}"
            asked="$(grep -c "^${path} " "${STUB_STATE}/requests" 2>/dev/null || true)"
            printf '%s %s\n' "${path}" "${host}" >> "${STUB_STATE}/requests"
            IFS=, read -r -a statuses <<< "$(awk -F '\t' -v path="${path}" '$1 == path { print $2 }' "${STUB_STATE}/http-statuses")"
            [[ ${#statuses[@]} -gt 0 ]] || { printf '000'; exit 0; }
            (( asked < ${#statuses[@]} )) || asked=$(( ${#statuses[@]} - 1 ))
            printf '%s' "${statuses[asked]}"
            BASH);
        writeExecutable($scratch.'/bin/sleep', "#!/bin/sh\nexit 0\n");

        $process = proc_open(
            ['bash', base_path('infrastructure/scripts/health-check'), '--target', 'staging-main'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            null,
            deployOpsBaseEnv($scratch, ['STUB_STATE' => $scratch]),
        );
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'output' => $output,
            'requests' => array_values(array_filter(explode("\n", (string) @file_get_contents($scratch.'/requests')))),
        ];
    } finally {
        deployOpsCleanup($scratch);
    }
}

it('passes once /up and the home page both answer, asking each with the target\'s host header', function () {
    $run = healthCheckRun(['/up' => 200, '/' => 200]);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['requests'])->toBe(['/up Host: rateguru-staging.internal', '/ Host: rateguru-staging.internal'])
        ->and($run['output'])->toContain('staging-main health check passed: http://127.0.0.1/up and http://127.0.0.1/');
});

it('fails a target whose home page fails while /up still answers', function () {
    $run = healthCheckRun(['/up' => 200, '/' => 500]);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('http://127.0.0.1/up answered, but the home page failed with HTTP 500')
        ->toContain('ERROR: staging-main health check failed')
        ->and(count($run['requests']))->toBe(20);
});

it('asks for the home page only once /up answers', function () {
    $run = healthCheckRun(['/up' => 500, '/' => 200]);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('failed with HTTP 500')
        ->and(array_unique($run['requests']))->toBe(['/up Host: rateguru-staging.internal']);
});

it('passes on a later attempt once the home page starts answering', function () {
    // Right after a release switch the first requests may still fail.
    $run = healthCheckRun(['/up' => 200, '/' => [503, 503, 200]]);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['output'])->toContain('attempt 2/10: http://127.0.0.1/up answered, but the home page failed with HTTP 503')
        ->toContain('health check passed');
});

it('fails a home page that answers with anything but 200, a redirect included', function () {
    // A redirect is not an answer: the home page has to serve, not send the
    // check somewhere else.
    expect(healthCheckRun(['/up' => 200, '/' => 302])['exit'])->toBe(1)
        ->and(healthCheckRun(['/up' => 200, '/' => 404])['exit'])->toBe(1);
});
