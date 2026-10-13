<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The operator surface of the public site's HTTPS states: Prepare, Verify and
 * Rollback tits.guru HTTPS; GO LIVE tits.guru and Close tits.guru public
 * site; and the one composite action they share.
 *
 * Each runs from main only, behind the byte-identical main-only gate; every one
 * that changes the host also behind a typed confirmation judged in a job that
 * holds no GitHub Environment, before any secret exists — and GO LIVE behind
 * main's own registry saying tits-guru is active. None of them runs on a merge
 * or a schedule, issues a certificate or changes DNS.
 */

/** @return array<string, array{file: string, name: string, job: string, operation: string, confirmation: ?string, what: ?string}> */
function publicHttpsWorkflows(): array
{
    return [
        'prepare' => ['file' => 'prepare-tits-guru-https.yml', 'name' => 'Prepare tits.guru HTTPS', 'job' => 'prepare', 'operation' => 'apply', 'confirmation' => 'PREPARE tits-guru HTTPS', 'what' => 'serve tits.guru over HTTPS, still closed, on the production host'],
        'verify' => ['file' => 'verify-tits-guru-https.yml', 'name' => 'Verify tits.guru HTTPS', 'job' => 'verify', 'operation' => 'verify', 'confirmation' => null, 'what' => null],
        'rollback' => ['file' => 'rollback-tits-guru-https.yml', 'name' => 'Rollback tits.guru HTTPS', 'job' => 'rollback', 'operation' => 'rollback', 'confirmation' => 'ROLLBACK tits-guru HTTPS', 'what' => 'take HTTPS off the public site of tits.guru on the production host'],
        'go-live' => ['file' => 'go-live-tits-guru.yml', 'name' => 'GO LIVE tits.guru', 'job' => 'activate', 'operation' => 'activate', 'confirmation' => 'OPEN tits.guru TO THE PUBLIC', 'what' => 'open the production application of tits.guru to the public'],
        'close' => ['file' => 'close-tits-guru-public-site.yml', 'name' => 'Close tits.guru public site', 'job' => 'deactivate', 'operation' => 'deactivate', 'confirmation' => 'CLOSE tits.guru TO THE PUBLIC', 'what' => 'close the production application of tits.guru to the public'],
    ];
}

it('runs each HTTPS operation from main only, for tits-guru only, behind the shared gate', function (string $key) {
    $spec = publicHttpsWorkflows()[$key];
    $source = File::get(base_path(".github/workflows/{$spec['file']}"));
    $workflow = Yaml::parse($source);

    expect($workflow['name'])->toBe($spec['name']);
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    $gate = $workflow['jobs']['validate-ref']['steps'][0]['run'];
    foreach (['refs/heads/main' => 0, 'refs/heads/develop' => 1, 'refs/heads/feat/public-https' => 1, 'refs/tags/v9.9.9' => 1, '' => 1] as $ref => $refused) {
        $process = proc_open(['bash', '-c', $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['RUN_REF' => $ref, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        expect(proc_close($process) !== 0)->toBe((bool) $refused, "ref \"{$ref}\"");
    }

    $run = $workflow['jobs'][$spec['job']];
    expect($run['environment'])->toBe('production-tits-guru');
    expect($run['runs-on'])->toBe('ubuntu-24.04');
    expect($run['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($run['steps'][1]['uses'])->toBe('./.github/actions/prepare-rateguru-public-https');
    expect($run['steps'][1]['with'])->toBe([
        'operation' => $spec['operation'],
        'deployment-target' => 'tits-guru',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);
    expect($run['steps'][2]['if'])->toBe("\${{ always() && steps.public.outputs.status == '' }}");

    foreach ($workflow['jobs'] as $id => $job) {
        expect(array_key_exists('environment', $job))->toBe($id === $spec['job'], "{$id} environment");
    }
    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);

    foreach (['DEPLOY_SSH_KEY', 'MAIL_DKIM_PRIVATE_KEY', 'CLOUDFLARE', 'DNS_API', 'certbot ', '.pem', 'scp '] as $forbidden) {
        expect(str_contains(executableSourceLines($source), $forbidden))->toBeFalse("{$spec['file']} uses {$forbidden}");
    }
})->with(array_keys(publicHttpsWorkflows()));

it('requires the typed confirmation before the Environment, its approval or any secret', function (string $key) {
    $spec = publicHttpsWorkflows()[$key];
    $workflow = Yaml::parse(File::get(base_path(".github/workflows/{$spec['file']}")));

    expect($workflow['on']['workflow_dispatch']['inputs'])->toBe([
        'confirmation' => [
            'description' => "Type exactly \"{$spec['confirmation']}\" to {$spec['what']}",
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

    $script = $confirm['steps'][0]['run'];
    $run = static function (string $confirmation) use ($script): int {
        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['CONFIRMATION' => $confirmation, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process);
    };

    expect($run($spec['confirmation']))->toBe(0);
    foreach (['', strtolower($spec['confirmation']), $spec['confirmation'].' ', 'PREPARE tits-guru public HTTP', 'ROLLBACK tits-guru public HTTP', str_replace('tits', 'demo', $spec['confirmation'])] as $wrong) {
        expect($run($wrong))->not->toBe(0, "accepted \"{$wrong}\"");
    }
})->with(['prepare', 'rollback', 'go-live', 'close']);

it('refuses GO LIVE before the Environment while main\'s registry has tits-guru anything but active', function () {
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/go-live-tits-guru.yml')));
    $steps = $workflow['jobs']['validate-confirmation']['steps'];

    expect($steps[1]['uses'])->toBe('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1');
    expect($steps[1]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($steps[2]['name'])->toBe('Require tits-guru lifecycle=active');

    $run = static function (string $lifecycle) use ($steps): int {
        $dir = makeScratchDir('go-live-lifecycle', ['', '/infrastructure/config']);
        $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
        $registry['targets']['tits-guru']['lifecycle'] = $lifecycle;
        file_put_contents($dir.'/infrastructure/config/deployment-targets.json', json_encode($registry));

        $process = proc_open(['bash', '-c', $steps[2]['run']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, ['PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $status = proc_close($process);
        removeScratchDir($dir);

        return $status;
    };

    expect($run('active'))->toBe(0);
    expect($run('planned'))->not->toBe(0);
    expect($run('disabled'))->not->toBe(0);

    // Today's registry: refused.
    expect(json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets']['tits-guru']['lifecycle'])->toBe('planned');
});

it('takes no input in the read-only Verify', function () {
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/verify-tits-guru-https.yml')));

    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'verify']);
});

it('runs no HTTPS operation on a merge, a push or a schedule', function () {
    $found = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $source = File::get($path);
        if (! str_contains($source, 'prepare-rateguru-public-https') && ! str_contains($source, 'prepare-public-https')) {
            continue;
        }

        $workflow = Yaml::parse($source);
        $triggers = array_keys((array) ($workflow['on'] ?? $workflow[true] ?? []));
        expect($triggers)->toBe(['workflow_dispatch'], basename($path).' runs an HTTPS operation on '.implode(', ', $triggers));
        $found[] = basename($path);
    }

    sort($found);
    expect($found)->toBe(['close-tits-guru-public-site.yml', 'go-live-tits-guru.yml', 'prepare-tits-guru-https.yml', 'rollback-tits-guru-https.yml', 'verify-tits-guru-https.yml']);
});

it('runs exactly prepare-public-https in the mode its workflow fixed, from infrastructure/ alone, and removes what it uploaded on every path', function () {
    $source = File::get(base_path('.github/actions/prepare-rateguru-public-https/action.yml'));
    $action = Yaml::parse($source);
    $code = executableSourceLines($source);

    expect(array_keys($action['inputs']))->toBe(['operation', 'deployment-target', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);
    expect(array_keys($action['outputs']))->toBe(['status', 'state']);

    expect($code)->toContain("case \"\${OPERATION}\" in\n          apply|verify|rollback|activate|deactivate) ;;");
    expect($code)->toMatch('#remote_command=\(\s+\$\{RATEGURU_PRIVILEGED_PREFIX:-\}\s+"\$\{RATEGURU_REMOTE_ROOT\}/infrastructure/scripts/prepare-public-https"\s+"--\$\{OPERATION\}"\s+--target "\$\{DEPLOYMENT_TARGET\}"\s+\)#');
    // --confirm-public reaches the host for GO LIVE, and for nothing else.
    expect($code)->toContain("if [[ \"\${OPERATION}\" == activate ]]; then\n          remote_command+=(--confirm-public)\n        fi");
    expect(substr_count($code, '--confirm-public'))->toBe(1);
    expect($code)
        ->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/prepare-public-https"')
        ->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/prepare-public-http"')
        ->toContain("tar --create --gzip --file \"\${bundle_path}\" \\\n          --directory \"\${GITHUB_WORKSPACE}\" infrastructure")
        ->toContain('-o StrictHostKeyChecking=yes');

    foreach (['StrictHostKeyChecking=no', 'StrictHostKeyChecking=accept-new', 'ssh-keyscan', 'DEPLOY_SSH_KEY', 'certbot', 'nsupdate', 'systemctl', 'nginx -s'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the public HTTPS action uses {$forbidden}");
    }

    $last = array_slice($action['runs']['steps'], -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote infrastructure bundle', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }
    expect($last[1]['run'])
        ->toContain('"${RUNNER_TEMP}/rateguru_public_https_key"')
        ->toContain('"${RUNNER_TEMP}/rateguru_public_https_known_hosts"')
        ->toContain('"${RUNNER_TEMP}/rateguru-public-https-bundle.tar.gz"');
});

/**
 * The action's "Run the public HTTPS operation" step, run as the runner would,
 * with an ssh that answers OUTPUT and exits STATUS in place of the host.
 *
 * @return array{status: int, output: string, summary: string, outputs: string, ssh: string}
 */
function publicHttpsActionStep(string $operation, string $output, int $status = 0): array
{
    $action = Yaml::parse(File::get(base_path('.github/actions/prepare-rateguru-public-https/action.yml')));
    $script = collect($action['runs']['steps'])->firstWhere('name', 'Run the public HTTPS operation')['run'];

    $dir = makeScratchDir('public-https-action', ['', '/bin']);
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
        'RATEGURU_REMOTE_ROOT' => '/root/rateguru-public-https-1-1',
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

/** A result line of prepare-public-https for MODE, with FIELDS changed. */
function publicHttpsActionResult(string $mode, array $fields = []): string
{
    return 'RATEGURU_PUBLIC_HTTPS_RESULT='.json_encode([
        'target' => 'tits-guru', 'mode' => $mode, 'status' => 'pass', 'state' => 'tls-ready',
        'site' => 'rateguru-tits-guru-public', 'hostnames' => ['tits.guru'], 'lifecycle' => 'planned',
        'certificate' => 'valid', 'certificate_expires' => 'Jan 10 12:00:00 2027 GMT', 'hook' => 'installed',
        'changed' => true, 'reloaded' => true, 'rolled_back' => false, 'ipv6_loopback' => 'disabled', ...$fields,
    ])."\n";
}

it('judges the one result before it shows any of it, and fails the job unless the operation passed', function (string $operation, string $answer, int $exit, int $expected, ?string $summary) {
    $step = publicHttpsActionStep($operation, "report line\n".$answer, $exit);

    expect($step['status'])->toBe($expected, $step['output']);
    expect($step['ssh'])->toContain("'/root/rateguru-public-https-1-1/infrastructure/scripts/prepare-public-https' '--{$operation}' '--target' 'tits-guru'");
    expect(str_contains($step['ssh'], '--confirm-public'))->toBe($operation === 'activate');

    if ($summary === null) {
        expect($step['summary'])->toBe('');
    } else {
        expect($step['summary'])->toContain($summary);
    }
})->with([
    'a pass of apply' => ['apply', publicHttpsActionResult('apply'), 0, 0, 'tls-ready: the public hostnames answer over HTTPS with a verified certificate and 503 to everyone'],
    'a pass of verify' => ['verify', publicHttpsActionResult('verify', ['changed' => false, 'reloaded' => false]), 0, 0, '| Certificate | valid — until Jan 10 12:00:00 2027 GMT |'],
    'a pass of rollback' => ['rollback', publicHttpsActionResult('rollback', ['state' => 'http-acme-ready', 'hook' => 'absent']), 0, 0, 'http-acme-ready: HTTPS is withdrawn from the public site'],
    'a pass of activate' => ['activate', publicHttpsActionResult('activate', ['state' => 'public-active', 'lifecycle' => 'active']), 0, 0, 'public-active: the application is open to the public over HTTPS'],
    'a pass of deactivate' => ['deactivate', publicHttpsActionResult('deactivate', ['lifecycle' => 'active']), 0, 0, 'tls-ready: the application is closed to the public again'],
    'a refused apply' => ['apply', publicHttpsActionResult('apply', ['status' => 'fail', 'state' => 'http-acme-ready', 'certificate' => 'invalid', 'hook' => 'absent', 'changed' => false, 'reloaded' => false]), 1, 1, 'The apply was refused before anything changed'],
    'a restored apply' => ['apply', publicHttpsActionResult('apply', ['status' => 'fail', 'state' => 'http-acme-ready', 'hook' => 'absent', 'changed' => false, 'reloaded' => false, 'rolled_back' => true]), 1, 1, 'The apply failed and the public site was put back as it was'],
    'a critical apply' => ['apply', publicHttpsActionResult('apply', ['status' => 'critical', 'state' => 'unknown']), 2, 1, '**CRITICAL:**'],
    'a foreign site' => ['rollback', publicHttpsActionResult('rollback', ['status' => 'fail', 'state' => 'foreign', 'changed' => false, 'reloaded' => false]), 1, 1, 'Refused: the public site on the host is none of the states this operation knows'],
    // A pass that does not mean what its mode promises is not a pass.
    'an apply passing on a live target' => ['apply', publicHttpsActionResult('apply', ['lifecycle' => 'active']), 0, 1, null],
    'an apply passing without the hook' => ['apply', publicHttpsActionResult('apply', ['hook' => 'absent']), 0, 1, null],
    'an apply passing with a bad certificate' => ['apply', publicHttpsActionResult('apply', ['certificate' => 'invalid']), 0, 1, null],
    'an activation passing on a planned target' => ['activate', publicHttpsActionResult('activate', ['state' => 'public-active']), 0, 1, null],
    'an activation passing without opening' => ['activate', publicHttpsActionResult('activate', ['lifecycle' => 'active']), 0, 1, null],
    'a rollback passing with the hook left' => ['rollback', publicHttpsActionResult('rollback', ['state' => 'http-acme-ready']), 0, 1, null],
    'a deactivation passing while open' => ['deactivate', publicHttpsActionResult('deactivate', ['state' => 'public-active']), 0, 1, null],
    'a verify that changed something' => ['verify', publicHttpsActionResult('verify', ['changed' => true]), 0, 1, null],
    'a pass exiting non-zero' => ['apply', publicHttpsActionResult('apply'), 1, 1, 'tls-ready'],
    'another target' => ['apply', publicHttpsActionResult('apply', ['target' => 'staging-main']), 0, 1, null],
    'an HTTP result' => ['apply', str_replace('RATEGURU_PUBLIC_HTTPS_RESULT=', 'RATEGURU_PUBLIC_HTTP_RESULT=', publicHttpsActionResult('apply')), 0, 1, null],
    'no result' => ['apply', '', 1, 1, null],
    'two results' => ['apply', publicHttpsActionResult('apply').publicHttpsActionResult('apply'), 0, 1, null],
]);

it('keeps every apostrophe out of the jq programs of its bash steps', function () {
    $action = Yaml::parse(File::get(base_path('.github/actions/prepare-rateguru-public-https/action.yml')));

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
