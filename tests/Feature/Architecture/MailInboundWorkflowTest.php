<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The operator surface of the inbound receiver: Activate, Verify and Rollback
 * tits.guru inbound SMTP, Install tits.guru inbound mail TLS, and the one
 * composite action they share.
 *
 * Each runs from main only, behind the byte-identical main-only gate; the
 * three that change the host also behind a typed confirmation judged in a job
 * that holds no GitHub Environment, before any secret exists. None of them runs on a
 * merge or a schedule, publishes DNS, or shows a received message.
 */

/** @return array<string, array{file: string, name: string, job: string, operation: string, confirmation: ?string}> */
function mailInboundWorkflows(): array
{
    return [
        'activate' => ['file' => 'activate-tits-guru-inbound-smtp.yml', 'name' => 'Activate tits.guru inbound SMTP', 'job' => 'activate', 'operation' => 'apply', 'confirmation' => 'ACTIVATE tits-guru inbound SMTP'],
        'verify' => ['file' => 'verify-tits-guru-inbound-smtp.yml', 'name' => 'Verify tits.guru inbound SMTP', 'job' => 'verify', 'operation' => 'verify', 'confirmation' => null],
        'rollback' => ['file' => 'rollback-tits-guru-inbound-smtp.yml', 'name' => 'Rollback tits.guru inbound SMTP', 'job' => 'rollback', 'operation' => 'rollback', 'confirmation' => 'ROLLBACK tits-guru inbound SMTP'],
        'tls' => ['file' => 'install-tits-guru-inbound-mail-tls.yml', 'name' => 'Install tits.guru inbound mail TLS', 'job' => 'install', 'operation' => 'install-tls', 'confirmation' => 'INSTALL tits-guru inbound TLS'],
    ];
}

it('runs each inbound operation from main only, for tits-guru only, behind the shared gate', function (string $key) {
    $spec = mailInboundWorkflows()[$key];
    $source = File::get(base_path(".github/workflows/{$spec['file']}"));
    $workflow = Yaml::parse($source);

    expect($workflow['name'])->toBe($spec['name']);
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    // The gate itself, run as the runner would: develop, a feature branch or a
    // tag never reach the job that holds the Environment.
    $gate = $workflow['jobs']['validate-ref']['steps'][0]['run'];
    foreach (['refs/heads/main' => 0, 'refs/heads/develop' => 1, 'refs/heads/feat/mail-inbound' => 1, 'refs/tags/v9.9.9' => 1, '' => 1] as $ref => $refused) {
        $process = proc_open(['bash', '-c', $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['RUN_REF' => $ref, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        expect(proc_close($process) !== 0)->toBe((bool) $refused, "ref \"{$ref}\"");
    }

    $run = $workflow['jobs'][$spec['job']];
    expect($run['environment'])->toBe('production-tits-guru');
    expect($run['runs-on'])->toBe('ubuntu-24.04');
    expect($run['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($run['steps'][1]['uses'])->toBe('./.github/actions/activate-rateguru-mail-inbound');
    expect($run['steps'][1]['with'])->toBe([
        'operation' => $spec['operation'],
        'deployment-target' => 'tits-guru',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);

    // Only the job that owns the Environment holds a secret, and only the
    // bootstrap credential.
    foreach ($workflow['jobs'] as $id => $job) {
        expect(array_key_exists('environment', $job))->toBe($id === $spec['job'], "{$id} environment");
    }
    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);

    foreach (['MAIL_DKIM_PRIVATE_KEY', 'MAIL_CANARY_RECIPIENT', 'DEPLOY_SSH_KEY', 'LARAVEL_ENV', 'CLOUDFLARE', 'DNS_API'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with(array_keys(mailInboundWorkflows()));

it('requires the typed confirmation before the Environment, its approval or any secret', function (string $key) {
    $spec = mailInboundWorkflows()[$key];
    $workflow = Yaml::parse(File::get(base_path(".github/workflows/{$spec['file']}")));

    expect($workflow['on']['workflow_dispatch']['inputs'])->toBe([
        'confirmation' => [
            'description' => "Type exactly \"{$spec['confirmation']}\" to ".[
                'activate' => 'open public TCP 25 on the production host',
                'rollback' => 'close public TCP 25 on the production host',
                'tls' => "install the inbound listener's certificate on the production host",
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
    foreach (['', strtolower($spec['confirmation']), $spec['confirmation'].' ', 'ACTIVATE tits-guru', str_replace('tits-guru', 'staging-main', $spec['confirmation'])] as $wrong) {
        expect($run($wrong))->not->toBe(0, "accepted \"{$wrong}\"");
    }
})->with(['activate', 'rollback', 'tls']);

it('takes no input in the read-only Verify', function () {
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/verify-tits-guru-inbound-smtp.yml')));

    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'verify']);
    expect($workflow['jobs']['verify']['needs'])->toBe(['validate-ref']);
});

it('runs no inbound operation on a merge, a push or a schedule', function () {
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));
        $triggers = array_keys((array) ($workflow['on'] ?? $workflow[true] ?? []));
        $source = File::get($path);

        if (str_contains($source, 'activate-rateguru-mail-inbound') || str_contains($source, 'activate-mail-inbound')) {
            expect($triggers)->toBe(['workflow_dispatch'], basename($path).' runs an inbound operation on '.implode(', ', $triggers));
        }
    }
});

it('runs exactly activate-mail-inbound in the mode its workflow fixed, judges its result and removes its bundle on every path', function () {
    $source = File::get(base_path('.github/actions/activate-rateguru-mail-inbound/action.yml'));
    $action = Yaml::parse($source);
    $code = executableSourceLines($source);

    expect(array_keys($action['inputs']))->toBe(['operation', 'deployment-target', 'monitor-public-key', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);
    expect(array_keys($action['outputs']))->toBe(['status', 'state']);

    expect($code)->toContain("case \"\${OPERATION}\" in\n          apply|verify|rollback|install-monitor|install-tls) ;;");
    // The monitor's public key reaches the host only for install-monitor, as one argument.
    expect($code)->toContain("if [[ \"\${OPERATION}\" == install-monitor ]]; then\n          remote_command+=(--monitor-public-key \"\${MONITOR_PUBLIC_KEY}\")");
    expect($code)->toMatch('#remote_command=\(\s+\$\{RATEGURU_PRIVILEGED_PREFIX:-\}\s+"\$\{RATEGURU_REMOTE_ROOT\}/infrastructure/scripts/activate-mail-inbound"\s+"--\$\{OPERATION\}"\s+--target "\$\{DEPLOYMENT_TARGET\}"\s+\)#');

    expect($code)
        ->toContain("grep -c '^RATEGURU_MAIL_INBOUND_ACTIVATION_RESULT='")
        ->toContain('and (.state | IN("not-installed", "installed-disabled", "installed-enabled", "enabled-verified", "drift", "unknown"))')
        ->toContain('or ($mode == "apply" and .requested and .state == "enabled-verified" and .public_smtp == "enabled" and (.rolled_back | not))')
        ->toContain('or ($mode == "rollback" and .public_smtp == "disabled" and (.state | IN("installed-disabled", "not-installed")))')
        ->toContain('or ($mode == "install-tls" and (.state | IN("not-installed", "installed-disabled", "installed-enabled"))))')
        ->toContain('**Check the published MX records**');

    // Nothing of a received message, the DNS or the outbound path.
    foreach (['MAIL_CANARY_RECIPIENT', 'postsuper', 'postqueue', 'postcat', 'sendmail', 'nsupdate', 'install-mail-gateway --apply', 'mail-routing.json"'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the inbound action uses {$forbidden}");
    }

    $last = array_slice($action['runs']['steps'], -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote infrastructure bundle', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }

    // The credential is removed by its fixed path too: a key ssh-keygen refused
    // was written before its path was ever exported.
    expect($last[1]['run'])
        ->toContain('"${RUNNER_TEMP}/rateguru_mail_inbound_key"')
        ->toContain('"${RUNNER_TEMP}/rateguru_mail_inbound_known_hosts"')
        ->toContain('"${RUNNER_TEMP}/rateguru-mail-inbound-bundle.tar.gz"');
    $configure = collect($action['runs']['steps'])->firstWhere('name', 'Configure bootstrap SSH')['run'];
    expect($configure)
        ->toContain('key_path="${RUNNER_TEMP}/rateguru_mail_inbound_key"')
        ->toContain('known_hosts_path="${RUNNER_TEMP}/rateguru_mail_inbound_known_hosts"');
});

it('tells the operator, after a rollback, to check the MX records that still name the host', function () {
    $rollback = preg_replace('/\s+/', ' ', preg_replace('/^#\s?/m', '', File::get(base_path('.github/workflows/rollback-tits-guru-inbound-smtp.yml'))));

    expect($rollback)->toContain('It changes no DNS record. After it, the published MX records still name this host');

    $activate = preg_replace('/\s+/', ' ', preg_replace('/^#\s?/m', '', File::get(base_path('.github/workflows/activate-tits-guru-inbound-smtp.yml'))));
    expect($activate)->toContain('It publishes no DNS');
});

it('installs the listener\'s certificate from what is already on the host: no certificate, key or Certbot command passes through GitHub', function () {
    $source = File::get(base_path('.github/workflows/install-tits-guru-inbound-mail-tls.yml'));
    $workflow = Yaml::parse($source);
    $code = executableSourceLines($source);

    // One input, the confirmation: no certificate, no key, no path, no domain.
    expect(array_keys($workflow['on']['workflow_dispatch']['inputs']))->toBe(['confirmation']);
    foreach (['certbot', 'letsencrypt', '.pem', 'PRIVATE KEY', 'acme', 'scp ', 'upload-artifact', 'download-artifact'] as $forbidden) {
        expect(str_contains(strtolower($code), strtolower($forbidden)))->toBeFalse("the TLS workflow's steps mention {$forbidden}");
    }

    // The action uploads infrastructure/ alone, and checks the installer is in it.
    $action = File::get(base_path('.github/actions/activate-rateguru-mail-inbound/action.yml'));
    expect($action)
        ->toContain('test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/install-mail-inbound-tls"')
        ->toContain("tar --create --gzip --file \"\${bundle_path}\" \\\n          --directory \"\${GITHUB_WORKSPACE}\" infrastructure");
});

/**
 * The action's "Run the inbound operation" step, run as the runner would, with
 * an ssh that answers OUTPUT and exits STATUS in place of the host.
 *
 * @return array{status: int, output: string, summary: string, outputs: string}
 */
function mailInboundActionStep(string $operation, string $output, int $status = 0): array
{
    $action = Yaml::parse(File::get(base_path('.github/actions/activate-rateguru-mail-inbound/action.yml')));
    $script = collect($action['runs']['steps'])->firstWhere('name', 'Run the inbound operation')['run'];

    $dir = sys_get_temp_dir().'/inbound-action-'.bin2hex(random_bytes(6));
    mkdir($dir.'/bin', 0o755, true);
    file_put_contents($dir.'/answer', $output);
    file_put_contents($dir.'/bin/ssh', "#!/bin/bash\ncat ".escapeshellarg($dir.'/answer')."\nexit {$status}\n");
    chmod($dir.'/bin/ssh', 0o755);
    touch($dir.'/summary');
    touch($dir.'/outputs');

    $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $dir, [
        'PATH' => $dir.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'OPERATION' => $operation,
        'DEPLOYMENT_TARGET' => 'tits-guru',
        'MONITOR_PUBLIC_KEY' => $operation === 'install-monitor' ? MAIL_MONITOR_KEY : '',
        'BOOTSTRAP_HOST' => 'host.example',
        'BOOTSTRAP_PORT' => '22',
        'BOOTSTRAP_USER' => 'root',
        'RATEGURU_PRIVILEGED_PREFIX' => '',
        'RATEGURU_REMOTE_ROOT' => '/root/rateguru-mail-inbound-1-1',
        'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $dir.'/key',
        'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $dir.'/known_hosts',
        'GITHUB_OUTPUT' => $dir.'/outputs',
        'GITHUB_STEP_SUMMARY' => $dir.'/summary',
    ]);
    $printed = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $result = ['status' => proc_close($process), 'output' => $printed, 'summary' => (string) file_get_contents($dir.'/summary'), 'outputs' => (string) file_get_contents($dir.'/outputs')];
    exec('rm -rf '.escapeshellarg($dir));

    return $result;
}

/** A result line of activate-mail-inbound for MODE, with FIELDS changed. */
function mailInboundActionResult(string $mode, array $fields = []): string
{
    return 'RATEGURU_MAIL_INBOUND_ACTIVATION_RESULT='.json_encode([
        'target' => 'tits-guru', 'mode' => $mode, 'status' => 'pass', 'requested' => true, 'changed' => true,
        'rolled_back' => false, 'state' => 'installed-disabled', 'public_smtp' => 'disabled', 'address' => null,
        'firewall' => 'ufw', 'queue' => null, ...$fields,
    ])."\n";
}

it('judges every operation\'s result and writes its summary, as the runner runs the step', function (string $operation, array $fields, string $said) {
    $run = mailInboundActionStep($operation, "the host's report\n".mailInboundActionResult($operation, $fields));

    expect($run['status'])->toBe(0, $run['output']);
    expect($run['output'])->toContain("the host's report");
    expect($run['summary'])
        ->toContain("## tits-guru inbound SMTP — {$operation}")
        ->toContain('| **Overall** | **PASS** |')
        ->toContain($said);
    expect($run['outputs'])->toContain('status=pass');
})->with([
    'apply' => ['apply', ['state' => 'enabled-verified', 'public_smtp' => 'enabled', 'address' => '1.2.3.4'], 'accepts mail on 1.2.3.4:25'],
    'verify' => ['verify', [], 'The host is in a consistent state: installed-disabled.'],
    'rollback' => ['rollback', [], 'Public SMTP is closed'],
    'install-monitor' => ['install-monitor', [], 'The read-only access of the automatic health check is installed'],
    'install-tls, before inbound mail' => ['install-tls', ['state' => 'not-installed'], 'The certificate Certbot holds for the MX host is in place for the public listener'],
    'install-tls, public SMTP open' => ['install-tls', ['state' => 'installed-enabled', 'public_smtp' => 'enabled', 'address' => '1.2.3.4'], 'Public SMTP was not changed.'],
]);

it('fails a certificate installation that did not pass, or claims a state it cannot have', function (string $answer, int $status, string $reason) {
    $run = mailInboundActionStep('install-tls', $answer, $status);

    expect($run['status'])->not->toBe(0, $run['output']);
    expect($run['output'])->toContain($reason);
})->with([
    'a failure' => [mailInboundActionResult('install-tls', ['status' => 'fail', 'changed' => false]), 1, 'The inbound SMTP install-tls for tits-guru did not pass (exit 1)'],
    'a pass in drift' => [mailInboundActionResult('install-tls', ['state' => 'drift']), 0, 'The machine-readable result is not an inbound result for tits-guru (install-tls)'],
    'a pass for another mode' => [mailInboundActionResult('install-monitor'), 0, 'The machine-readable result is not an inbound result for tits-guru (install-tls)'],
    'no result' => ["the run did not complete\n", 255, 'Expected exactly one RATEGURU_MAIL_INBOUND_ACTIVATION_RESULT line, got 0'],
]);
