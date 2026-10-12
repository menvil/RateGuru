<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The operator surface of the public HTTP site: Prepare, Verify and Rollback
 * tits.guru public HTTP, and the one composite action they share.
 *
 * Each runs from main only, behind the byte-identical main-only gate; the two
 * that change the host also behind a typed confirmation judged in a job that
 * holds no GitHub Environment, before any secret exists. None of them runs on
 * a merge or a schedule, issues a certificate or changes DNS.
 */

/** @return array<string, array{file: string, name: string, job: string, operation: string, confirmation: ?string}> */
function publicHttpWorkflows(): array
{
    return [
        'prepare' => ['file' => 'prepare-tits-guru-public-http.yml', 'name' => 'Prepare tits.guru public HTTP', 'job' => 'prepare', 'operation' => 'apply', 'confirmation' => 'PREPARE tits-guru public HTTP'],
        'verify' => ['file' => 'verify-tits-guru-public-http.yml', 'name' => 'Verify tits.guru public HTTP', 'job' => 'verify', 'operation' => 'verify', 'confirmation' => null],
        'rollback' => ['file' => 'rollback-tits-guru-public-http.yml', 'name' => 'Rollback tits.guru public HTTP', 'job' => 'rollback', 'operation' => 'rollback', 'confirmation' => 'ROLLBACK tits-guru public HTTP'],
    ];
}

it('runs each public HTTP operation from main only, for tits-guru only, behind the shared gate', function (string $key) {
    $spec = publicHttpWorkflows()[$key];
    $source = File::get(base_path(".github/workflows/{$spec['file']}"));
    $workflow = Yaml::parse($source);

    expect($workflow['name'])->toBe($spec['name']);
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    // The physical host tits-guru shares with staging: every operation on it waits in one group.
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    // The gate itself, run as the runner would: develop, a feature branch or a
    // tag never reach the job that holds the Environment.
    $gate = $workflow['jobs']['validate-ref']['steps'][0]['run'];
    foreach (['refs/heads/main' => 0, 'refs/heads/develop' => 1, 'refs/heads/feat/public-http' => 1, 'refs/tags/v9.9.9' => 1, '' => 1] as $ref => $refused) {
        $process = proc_open(['bash', '-c', $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['RUN_REF' => $ref, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        expect(proc_close($process) !== 0)->toBe((bool) $refused, "ref \"{$ref}\"");
    }

    $run = $workflow['jobs'][$spec['job']];
    expect($run['environment'])->toBe('production-tits-guru');
    expect($run['runs-on'])->toBe('ubuntu-24.04');
    expect($run['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($run['steps'][1]['uses'])->toBe('./.github/actions/prepare-rateguru-public-http');
    expect($run['steps'][1]['with'])->toBe([
        'operation' => $spec['operation'],
        'deployment-target' => 'tits-guru',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);

    // A run that produced no result says so in the summary.
    expect($run['steps'][2]['if'])->toBe("\${{ always() && steps.public.outputs.status == '' }}");

    // Only the job that owns the Environment holds a secret, and only the
    // bootstrap credential.
    foreach ($workflow['jobs'] as $id => $job) {
        expect(array_key_exists('environment', $job))->toBe($id === $spec['job'], "{$id} environment");
    }
    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);

    foreach (['DEPLOY_SSH_KEY', 'MAIL_DKIM_PRIVATE_KEY', 'LARAVEL_ENV', 'CLOUDFLARE', 'DNS_API', 'certbot ', '.pem'] as $forbidden) {
        expect(str_contains(executableSourceLines($source), $forbidden))->toBeFalse("{$spec['file']} uses {$forbidden}");
    }
})->with(array_keys(publicHttpWorkflows()));

it('requires the typed confirmation before the Environment, its approval or any secret', function (string $key) {
    $spec = publicHttpWorkflows()[$key];
    $workflow = Yaml::parse(File::get(base_path(".github/workflows/{$spec['file']}")));

    expect($workflow['on']['workflow_dispatch']['inputs'])->toBe([
        'confirmation' => [
            'description' => "Type exactly \"{$spec['confirmation']}\" to ".[
                'prepare' => 'install the public HTTP site of tits.guru on the production host',
                'rollback' => 'remove the public HTTP site of tits.guru from the production host',
            ][$key],
            'required' => true,
            'default' => '',
            'type' => 'string',
        ],
    ]);

    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'validate-confirmation', $spec['job']]);
    $confirm = $workflow['jobs']['validate-confirmation'];
    expect($confirm['needs'])->toBe(['validate-ref']);
    expect($confirm)->not->toHaveKey('environment');
    expect(json_encode($confirm))->not->toContain('secrets.');
    expect($workflow['jobs'][$spec['job']]['needs'])->toBe(['validate-ref', 'validate-confirmation']);

    // The step itself, run as the runner would: the exact phrase passes,
    // anything else — a near miss, other case, surrounding space — stops it.
    $script = $confirm['steps'][0]['run'];
    $run = static function (string $confirmation) use ($script): int {
        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['CONFIRMATION' => $confirmation, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process);
    };

    expect($run($spec['confirmation']))->toBe(0);
    foreach (['', strtolower($spec['confirmation']), $spec['confirmation'].' ', ' '.$spec['confirmation'], 'PREPARE tits-guru', 'ROLLBACK tits-guru inbound SMTP', str_replace('tits-guru', 'staging-main', $spec['confirmation'])] as $wrong) {
        expect($run($wrong))->not->toBe(0, "accepted \"{$wrong}\"");
    }
})->with(['prepare', 'rollback']);

it('takes no input in the read-only Verify', function () {
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/verify-tits-guru-public-http.yml')));

    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'verify']);
    expect($workflow['jobs']['verify']['needs'])->toBe(['validate-ref']);
});

it('runs no public HTTP operation on a merge, a push or a schedule', function () {
    $found = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $source = File::get($path);
        if (! str_contains($source, 'prepare-rateguru-public-http') && ! str_contains($source, 'prepare-public-http')) {
            continue;
        }

        $workflow = Yaml::parse($source);
        $triggers = array_keys((array) ($workflow['on'] ?? $workflow[true] ?? []));
        expect($triggers)->toBe(['workflow_dispatch'], basename($path).' runs a public HTTP operation on '.implode(', ', $triggers));
        $found[] = basename($path);
    }

    sort($found);
    expect($found)->toBe(['prepare-tits-guru-public-http.yml', 'rollback-tits-guru-public-http.yml', 'verify-tits-guru-public-http.yml']);
});

it('runs exactly prepare-public-http in the mode its workflow fixed, from infrastructure/ alone, and removes what it uploaded on every path', function () {
    $source = File::get(base_path('.github/actions/prepare-rateguru-public-http/action.yml'));
    $action = Yaml::parse($source);
    $code = executableSourceLines($source);

    expect(array_keys($action['inputs']))->toBe(['operation', 'deployment-target', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);
    expect(array_keys($action['outputs']))->toBe(['status', 'state']);

    expect($code)->toContain("case \"\${OPERATION}\" in\n          apply|verify|rollback) ;;");
    expect($code)->toMatch('#remote_command=\(\s+\$\{RATEGURU_PRIVILEGED_PREFIX:-\}\s+"\$\{RATEGURU_REMOTE_ROOT\}/infrastructure/scripts/prepare-public-http"\s+"--\$\{OPERATION\}"\s+--target "\$\{DEPLOYMENT_TARGET\}"\s+\)#');
    expect($code)
        ->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/prepare-public-http"')
        ->toContain("tar --create --gzip --file \"\${bundle_path}\" \\\n          --directory \"\${GITHUB_WORKSPACE}\" infrastructure")
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('ssh-keygen -y -f "${key_path}" >/dev/null');

    foreach (['StrictHostKeyChecking=no', 'StrictHostKeyChecking=accept-new', 'ssh-keyscan', 'DEPLOY_SSH_KEY', 'certbot', 'letsencrypt', 'nsupdate', 'systemctl', 'nginx -s'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the public HTTP action uses {$forbidden}");
    }

    $last = array_slice($action['runs']['steps'], -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote infrastructure bundle', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }

    // The credential is removed by its fixed path too: a key ssh-keygen refused
    // was written before its path was ever exported.
    expect($last[1]['run'])
        ->toContain('"${RUNNER_TEMP}/rateguru_public_http_key"')
        ->toContain('"${RUNNER_TEMP}/rateguru_public_http_known_hosts"')
        ->toContain('"${RUNNER_TEMP}/rateguru-public-http-bundle.tar.gz"');
    $configure = collect($action['runs']['steps'])->firstWhere('name', 'Configure bootstrap SSH')['run'];
    expect($configure)
        ->toContain('key_path="${RUNNER_TEMP}/rateguru_public_http_key"')
        ->toContain('known_hosts_path="${RUNNER_TEMP}/rateguru_public_http_known_hosts"');
});

/**
 * The action's "Run the public HTTP operation" step, run as the runner would,
 * with an ssh that answers OUTPUT and exits STATUS in place of the host.
 *
 * @return array{status: int, output: string, summary: string, outputs: string}
 */
function publicHttpActionStep(string $operation, string $output, int $status = 0): array
{
    $action = Yaml::parse(File::get(base_path('.github/actions/prepare-rateguru-public-http/action.yml')));
    $script = collect($action['runs']['steps'])->firstWhere('name', 'Run the public HTTP operation')['run'];

    $dir = makeScratchDir('public-http-action', ['', '/bin']);
    file_put_contents($dir.'/answer', $output);
    writeExecutable($dir.'/bin/ssh', "#!/bin/bash\nprintf '%s\\n' \"\$*\" > ".escapeshellarg($dir.'/ssh-args')."\ncat ".escapeshellarg($dir.'/answer')."\nexit {$status}\n");
    touch($dir.'/summary');
    touch($dir.'/outputs');

    $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $dir, [
        'PATH' => $dir.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'OPERATION' => $operation,
        'DEPLOYMENT_TARGET' => 'tits-guru',
        'BOOTSTRAP_HOST' => 'host.example',
        'BOOTSTRAP_PORT' => '22',
        'BOOTSTRAP_USER' => 'root',
        'RATEGURU_PRIVILEGED_PREFIX' => '',
        'RATEGURU_REMOTE_ROOT' => '/root/rateguru-public-http-1-1',
        'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $dir.'/key',
        'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $dir.'/known_hosts',
        'GITHUB_OUTPUT' => $dir.'/outputs',
        'GITHUB_STEP_SUMMARY' => $dir.'/summary',
    ]);
    $printed = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $result = [
        'status' => proc_close($process),
        'output' => $printed,
        'summary' => (string) file_get_contents($dir.'/summary'),
        'outputs' => (string) file_get_contents($dir.'/outputs'),
        'ssh' => (string) @file_get_contents($dir.'/ssh-args'),
    ];
    removeScratchDir($dir);

    return $result;
}

/** A result line of prepare-public-http for MODE, with FIELDS changed. */
function publicHttpActionResult(string $mode, array $fields = []): string
{
    return 'RATEGURU_PUBLIC_HTTP_RESULT='.json_encode([
        'target' => 'tits-guru', 'mode' => $mode, 'status' => 'pass', 'state' => 'http-acme-ready',
        'site' => 'rateguru-tits-guru-public', 'hostnames' => ['tits.guru'], 'lifecycle' => 'planned',
        'changed' => true, 'reloaded' => true, 'rolled_back' => false, ...$fields,
    ])."\n";
}

it('judges the one result before it shows any of it, and fails the job unless the operation passed', function (string $operation, string $answer, int $exit, int $expected, ?string $summary) {
    $step = publicHttpActionStep($operation, "report line\n".$answer, $exit);

    expect($step['status'])->toBe($expected, $step['output']);
    expect($step['output'])->toContain('report line');
    expect($step['ssh'])->toContain("'/root/rateguru-public-http-1-1/infrastructure/scripts/prepare-public-http' '--{$operation}' '--target' 'tits-guru'");

    if ($summary === null) {
        expect($step['summary'])->toBe('');
        expect($step['outputs'])->toBe('');
    } else {
        expect($step['summary'])->toContain($summary);
        expect($step['outputs'])->toContain('status=');
    }
})->with([
    'a pass of apply' => ['apply', publicHttpActionResult('apply'), 0, 0, 'http-acme-ready: the public hostnames answer the ACME HTTP-01 challenge from /var/www/html'],
    'a pass of verify' => ['verify', publicHttpActionResult('verify', ['changed' => false, 'reloaded' => false]), 0, 0, 'The host is in a consistent state: http-acme-ready.'],
    'a pass of rollback' => ['rollback', publicHttpActionResult('rollback', ['state' => 'not-prepared']), 0, 0, 'not-prepared: the file and link of the public site are removed'],
    'a refused apply' => ['apply', publicHttpActionResult('apply', ['status' => 'fail', 'state' => 'not-prepared', 'changed' => false, 'reloaded' => false]), 1, 1, 'The installation was refused before anything changed'],
    'a restored apply' => ['apply', publicHttpActionResult('apply', ['status' => 'fail', 'state' => 'not-prepared', 'changed' => false, 'reloaded' => false, 'rolled_back' => true]), 1, 1, 'The apply failed and the public site was put back as it was'],
    'a critical apply' => ['apply', publicHttpActionResult('apply', ['status' => 'critical', 'state' => 'unknown']), 2, 1, '**CRITICAL:**'],
    'an apply refusing a site that is not its own' => ['apply', publicHttpActionResult('apply', ['status' => 'fail', 'state' => 'foreign', 'changed' => false, 'reloaded' => false]), 1, 1, 'Refused: the public site on the host is not the one this operation installs'],
    'a rollback refusing a site that is not its own' => ['rollback', publicHttpActionResult('rollback', ['status' => 'fail', 'state' => 'foreign', 'changed' => false, 'reloaded' => false]), 1, 1, 'No file or link was changed and Nginx was not reloaded'],
    'a drifted verify' => ['verify', publicHttpActionResult('verify', ['status' => 'fail', 'state' => 'drift', 'changed' => false, 'reloaded' => false]), 1, 1, 'The host is not in a consistent state'],
    // A pass that does not mean what its mode promises is not a pass.
    'an apply passing on a live target' => ['apply', publicHttpActionResult('apply', ['lifecycle' => 'active']), 0, 1, null],
    'an apply passing without the site' => ['apply', publicHttpActionResult('apply', ['state' => 'not-prepared']), 0, 1, null],
    'a rollback passing with the site' => ['rollback', publicHttpActionResult('rollback'), 0, 1, null],
    'a verify that changed something' => ['verify', publicHttpActionResult('verify', ['changed' => true]), 0, 1, null],
    'a pass exiting non-zero' => ['apply', publicHttpActionResult('apply'), 1, 1, 'http-acme-ready'],
    'another target' => ['apply', publicHttpActionResult('apply', ['target' => 'staging-main']), 0, 1, null],
    'another mode' => ['apply', publicHttpActionResult('rollback', ['state' => 'not-prepared']), 0, 1, null],
    'an unknown state' => ['apply', publicHttpActionResult('apply', ['state' => 'public-active']), 0, 1, null],
    'no result' => ['apply', '', 1, 1, null],
    'two results' => ['apply', publicHttpActionResult('apply').publicHttpActionResult('apply'), 0, 1, null],
]);

it('keeps every apostrophe out of the jq programs of its bash steps', function () {
    $action = Yaml::parse(File::get(base_path('.github/actions/prepare-rateguru-public-http/action.yml')));

    foreach ($action['runs']['steps'] as $step) {
        if (! isset($step['run'])) {
            continue;
        }
        preg_match_all("/jq (?:-[a-z]+ )*(?:--arg \\S+ \"[^\"]*\" )*'(.*?)'(?= <<<| \\|| >)/s", $step['run'], $programs);
        foreach ($programs[1] as $program) {
            expect($program)->not->toContain("'");
        }

        $process = proc_open(['bash', '-n', '-c', $step['run']], [2 => ['pipe', 'w']], $pipes);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, "{$step['name']}: {$errors}");
    }
});
