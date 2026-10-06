<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Infrastructure verification: infrastructure/scripts/verify-infrastructure,
 * the composite action that runs it on a host, and the two permanent
 * operator workflows, Verify staging infrastructure and Verify production
 * infrastructure.
 *
 * The verifier is run as shipped. The primitives it composes for the host's
 * services — verify-mail-capture, verify-mail-gateway, health-check — are stubs
 * that record exactly how they were called; mail-identity is the real one, with
 * real keys and a DNS stub. What is proved is the verifier's own job: which
 * primitives the target's CURRENT reviewed state requires, in which modes, and
 * how their verdicts become PASS, FAIL, DEFERRED or N/A.
 */

/**
 * A simulated host for the verifier: recording stubs for the service
 * primitives, every runtime tool present, DNS and the target's key root from
 * mail-identity's own stubs.
 *
 * @param  array{fail?: list<string>, missing_tools?: list<string>, dns?: array<string, mixed>}  $options
 * @return array{scratch: string, env: array<string, string>}
 */
function verifyInfraHost(array $options = []): array
{
    $scratch = mailIdentityScratch();
    @mkdir($scratch.'/runtime', 0o755, true);
    @mkdir($scratch.'/toggles', 0o755, true);

    foreach (['verify-mail-capture', 'verify-mail-gateway', 'health-check'] as $primitive) {
        file_put_contents($scratch."/bin/{$primitive}", <<<STUB
            #!/bin/bash
            printf '%s %s\\n' '{$primitive}' "\$*" >> "\${STUB_CALLS}"
            if [[ -e "\${STUB_TOGGLES}/{$primitive}" ]]; then echo "  stub {$primitive}: FAIL"; exit 1; fi
            echo "  stub {$primitive}: ok"
            STUB."\n");
        chmod($scratch."/bin/{$primitive}", 0o755);
    }

    foreach ($options['fail'] ?? [] as $primitive) {
        touch($scratch."/toggles/{$primitive}");
    }

    foreach (array_diff(['jq', 'dig', 'openssl', 'curl', 'ss', 'ip'], $options['missing_tools'] ?? []) as $tool) {
        file_put_contents($scratch."/runtime/{$tool}", "#!/bin/sh\nexit 0\n");
        chmod($scratch."/runtime/{$tool}", 0o755);
    }

    $env = [
        ...mailIdentityDnsHost($scratch, $options['dns'] ?? []),
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_VERIFYINFRA_EUID' => '0',
        'RATEGURU_VERIFYINFRA_FS_ROOT' => $scratch.'/fs',
        'RATEGURU_VERIFYINFRA_RUNTIME_PATH' => $scratch.'/runtime',
        'RATEGURU_VERIFYINFRA_MAIL_CAPTURE_BIN' => $scratch.'/bin/verify-mail-capture',
        'RATEGURU_VERIFYINFRA_MAIL_GATEWAY_BIN' => $scratch.'/bin/verify-mail-gateway',
        'RATEGURU_VERIFYINFRA_HEALTH_CHECK_BIN' => $scratch.'/bin/health-check',
        'STUB_CALLS' => $scratch.'/calls.log',
        'STUB_TOGGLES' => $scratch.'/toggles',
    ];

    return ['scratch' => $scratch, 'env' => $env];
}

/**
 * @param  array<string, string>  $env
 * @return array{status: int, output: string, calls: list<string>, result: array<string, mixed>|null}
 */
function verifyInfraRun(array $host, string $target, ?string $script = null, array $env = []): array
{
    $process = proc_open(
        ['bash', $script ?? base_path('infrastructure/scripts/verify-infrastructure'), '--target', $target],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        null,
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), ...$host['env'], ...$env],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);

    $calls = is_file($host['scratch'].'/calls.log')
        ? array_values(array_filter(explode("\n", (string) file_get_contents($host['scratch'].'/calls.log'))))
        : [];

    preg_match_all('/^RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=(.*)$/m', $output, $matches);
    $result = count($matches[1]) === 1 ? json_decode($matches[1][0], true) : null;

    return ['status' => $status, 'output' => $output, 'calls' => $calls, 'result' => $result];
}

/**
 * A scratch checkout whose mail policy adds demo-shop, so the verifier runs
 * from a bundle where a production target is outbound — and, with
 * $lifecycle active, operating.
 */
function verifyInfraDemoShopBundle(string $scratch, string $mode, string $lifecycle = 'planned'): string
{
    $repo = provisionRepo($scratch.'/checkout', provisionRegistryJson([], $lifecycle), widenActiveAllowlist: $lifecycle === 'active');

    mailIdentityFixtureConfig($repo.'/infrastructure/config', [
        'mode' => $mode,
        'registry' => json_decode(provisionRegistryJson([], $lifecycle), true),
        'outbound' => ['schema_version' => 1, 'direct' => ['enabled' => $mode === 'outbound', 'mta_hostname' => 'mta1.example.net']],
    ]);

    return $repo.'/infrastructure/scripts/verify-infrastructure';
}

function expectVerifyItem(string $output, string $verdict, string $name, string $detail = ''): void
{
    expect(preg_match('/^  '.preg_quote($verdict, '/').' +'.preg_quote($name, '/').' — .*'.preg_quote($detail, '/').'/m', $output))
        ->toBe(1, "expected {$verdict} {$name} {$detail} in:\n{$output}");
}

// =============================================================================
// STAGING
// =============================================================================

it('verifies staging-main through the read-only capture, gateway and health primitives, and nothing else', function () {
    $host = verifyInfraHost();

    try {
        $run = verifyInfraRun($host, 'staging-main');

        expect($run['status'])->toBe(0, $run['output']);
        expect($run['calls'])->toBe([
            'verify-mail-capture --read-only',
            'verify-mail-gateway --read-only',
            'health-check --target staging-main',
        ]);

        expectVerifyItem($run['output'], 'PASS', 'verify-mail-capture --read-only');
        expectVerifyItem($run['output'], 'PASS', 'verify-mail-gateway --read-only');
        expectVerifyItem($run['output'], 'N/A', 'mail-identity', 'a staging target has no production mail identity');
        expectVerifyItem($run['output'], 'PASS', 'health-check', 'staging-main answers its health endpoint');

        expect($run['output'])->toContain('VERIFY INFRASTRUCTURE: PASS');
        expect($run['result'])->toBe([
            'target' => 'staging-main',
            'environment_class' => 'staging',
            'lifecycle' => 'active',
            'delivery_mode' => 'capture',
            'status' => 'pass',
            'pass' => 4,
            'fail' => 0,
            'deferred' => 0,
            'not_applicable' => 1,
        ]);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('fails staging when any check its current state requires fails', function (string $primitive, string $item) {
    $host = verifyInfraHost(['fail' => [$primitive]]);

    try {
        $run = verifyInfraRun($host, 'staging-main');

        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', $item);
        expect($run['output'])->toContain('VERIFY INFRASTRUCTURE: FAIL');
        expect($run['result']['status'])->toBe('fail');
        expect($run['result']['fail'])->toBe(1);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'the capture services' => ['verify-mail-capture', 'verify-mail-capture --read-only'],
    'the mail gateway' => ['verify-mail-gateway', 'verify-mail-gateway --read-only'],
    'the application' => ['health-check', 'health-check'],
]);

// =============================================================================
// PRODUCTION, BEFORE ITS LAUNCH
// =============================================================================

it('verifies the held, planned production target, with what activation requires deferred', function () {
    $host = verifyInfraHost();

    try {
        $run = verifyInfraRun($host, 'tits-guru');

        expect($run['status'])->toBe(0, $run['output']);

        // The host-global gateway is required today; capture belongs to
        // staging, and a planned target's application is never asked.
        expect($run['calls'])->toBe(['verify-mail-gateway --read-only']);

        expectVerifyItem($run['output'], 'N/A', 'mail-capture');
        expectVerifyItem($run['output'], 'PASS', 'verify-mail-gateway --read-only');
        expectVerifyItem($run['output'], 'PASS', 'identity contract', 'mail-identity validate');
        expectVerifyItem($run['output'], 'DEFERRED', 'dkim-key', '/etc/opendkim/keys/tits-guru/rg1.private is absent — not required while tits-guru\'s mail is held');
        expectVerifyItem($run['output'], 'DEFERRED', 'outbound-readiness', 'not activated (delivery_mode held, direct delivery disabled)');
        expectVerifyItem($run['output'], 'DEFERRED', 'health-check', 'tits-guru is planned');

        // The readiness picture is still shown, never hidden behind DEFERRED.
        expect($run['output'])->toContain('| mail-identity readiness --target tits-guru')->toContain('OUTBOUND READY: NO');

        expect($run['output'])
            ->toContain("Target: tits-guru\nEnvironment class: production\nLifecycle: planned\nMail delivery mode: held")
            ->toContain('VERIFY INFRASTRUCTURE: PASS');
        expect($run['result'])->toMatchArray(['status' => 'pass', 'pass' => 3, 'fail' => 0, 'deferred' => 3, 'lifecycle' => 'planned', 'delivery_mode' => 'held']);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('judges an installed DKIM key even while the target is held, and never prints it', function () {
    $host = verifyInfraHost();

    try {
        $key = mailIdentityInstallKey($host['scratch'], 'tits-guru', 'rg1');
        $run = verifyInfraRun($host, 'tits-guru');

        expect($run['status'])->toBe(0, $run['output']);
        expectVerifyItem($run['output'], 'PASS', 'dkim-key', '/etc/opendkim/keys/tits-guru/rg1.private is a usable RSA private key');
        expectNoKeyMaterial($run['output'], $key);

        // Broken key material on the host is broken now, held or not.
        $weak = mailIdentityInstallKey($host['scratch'], 'tits-guru', 'rg1', 'rsa1024');
        $run = verifyInfraRun($host, 'tits-guru');

        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', 'dkim-key', 'below the reviewed minimum of 2048 bits');
        expectNoKeyMaterial($run['output'], $weak);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

// =============================================================================
// PRODUCTION, ONCE IT DELIVERS OUTBOUND AND OPERATES
// =============================================================================

it('makes the DKIM key and outbound readiness mandatory once the target delivers outbound', function () {
    $host = verifyInfraHost();

    try {
        $script = verifyInfraDemoShopBundle($host['scratch'], 'outbound');

        // No key: both are failures now, not deferrals.
        $run = verifyInfraRun($host, 'demo-shop', $script);
        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', 'dkim-key', 'is absent, and demo-shop delivers outbound — its DKIM key is required');
        expectVerifyItem($run['output'], 'FAIL', 'outbound-readiness', 'demo-shop delivers outbound, so mail-identity readiness is required');

        // A valid key and correct public DNS: the key passes, and readiness is
        // still required — it fails on the one condition this release cannot
        // meet, which is exactly what keeps an outbound target from passing.
        $key = mailIdentityInstallKey($host['scratch'], 'demo-shop', 'shop2026');
        $host['env'] = [...$host['env'], ...mailIdentityDnsHost($host['scratch'], mailIdentityGoodDns(mailIdentityPublicKey($key), '203.0.113.10', 'demo-shop.example', 'shop2026', 'mta1.example.net'))];

        $run = verifyInfraRun($host, 'demo-shop', $script);
        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'PASS', 'dkim-key');
        expectVerifyItem($run['output'], 'FAIL', 'outbound-readiness');
        expect($run['output'])->toContain('FAIL   signing    no DKIM signing service is installed and verified on this host');
        expect($run['result']['status'])->toBe('fail');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('requires the application\'s health once the target is active, and never asks a planned one', function () {
    $host = verifyInfraHost(['fail' => ['health-check']]);

    try {
        $active = verifyInfraRun($host, 'demo-shop', verifyInfraDemoShopBundle($host['scratch'], 'outbound', 'active'));

        expect($active['calls'])->toContain('health-check --target demo-shop');
        expectVerifyItem($active['output'], 'FAIL', 'health-check', 'demo-shop is active and its application is not healthy');

        @unlink($host['scratch'].'/calls.log');
        exec('rm -rf '.escapeshellarg($host['scratch'].'/checkout'));

        $planned = verifyInfraRun($host, 'demo-shop', verifyInfraDemoShopBundle($host['scratch'], 'held'));

        expect($planned['calls'])->not->toContain('health-check --target demo-shop');
        expectVerifyItem($planned['output'], 'DEFERRED', 'health-check', 'demo-shop is planned');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('reports a production target with no reviewed identity yet as not applicable, not as broken', function () {
    $host = verifyInfraHost();

    try {
        $repo = provisionRepo($host['scratch'].'/checkout', provisionRegistryJson());
        mailIdentityFixtureConfig($repo.'/infrastructure/config', ['identity' => null]);

        $run = verifyInfraRun($host, 'demo-shop', $repo.'/infrastructure/scripts/verify-infrastructure');

        expect($run['status'])->toBe(0, $run['output']);
        expectVerifyItem($run['output'], 'N/A', 'dkim-key', 'demo-shop has no reviewed DKIM identity yet');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

// =============================================================================
// THE CONTRACT OF THE VERIFIER ITSELF
// =============================================================================

it('refuses rather than installs when the host runtime lacks a tool it needs', function () {
    $host = verifyInfraHost(['missing_tools' => ['dig']]);

    try {
        $run = verifyInfraRun($host, 'tits-guru');

        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', 'tool:dig', 'not installed (package bind9-dnsutils) — host runtime drift: run Prepare Host for this host, which converges the canonical runtime. Verify installs nothing');

        foreach (['apt-get', 'apt ', 'dpkg -i', 'snap '] as $installer) {
            expect(str_contains(executableSourceLines(File::get(base_path('infrastructure/scripts/verify-infrastructure'))), $installer))
                ->toBeFalse("verify-infrastructure runs {$installer}");
        }
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('prints exactly one machine-readable result, with counts and identity only', function () {
    $host = verifyInfraHost(['fail' => ['verify-mail-gateway']]);

    try {
        $key = mailIdentityInstallKey($host['scratch'], 'tits-guru', 'rg1');
        $run = verifyInfraRun($host, 'tits-guru');

        expect(preg_match_all('/^RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=/m', $run['output']))->toBe(1);
        expect(array_keys($run['result']))->toBe(['target', 'environment_class', 'lifecycle', 'delivery_mode', 'status', 'pass', 'fail', 'deferred', 'not_applicable']);
        expect($run['result']['status'])->toBe('fail');

        // The result is the last line.
        $lines = array_values(array_filter(explode("\n", $run['output'])));
        expect(end($lines))->toStartWith('RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=');

        expectNoKeyMaterial($run['output'], $key);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('runs every primitive in its read-only mode and nothing that could change the host', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/verify-infrastructure')));

    // Each service primitive appears exactly once, in its read-only mode.
    expect($code)
        ->toContain('run_child "${MAIL_CAPTURE_VERIFIER}" --read-only')
        ->toContain('run_child "${MAIL_GATEWAY_VERIFIER}" --read-only')
        ->toContain('run_child "${HEALTH_CHECK}" --target "${TARGET_ID}"');
    expect(substr_count($code, '"${MAIL_CAPTURE_VERIFIER}"'))->toBe(1);
    expect(substr_count($code, '"${MAIL_GATEWAY_VERIFIER}"'))->toBe(1);

    // mail-identity: only its read-only commands.
    preg_match_all('/"\$\{MAIL_IDENTITY\}" ([a-z-]+)/', $code, $commands);
    expect(array_values(array_unique($commands[1])))->toBe(['validate', 'dkim-key', 'check-key', 'readiness']);

    // The scripts it can reach at all are exactly the primitives it composes.
    preg_match_all('/\$\{SCRIPT_DIR\}\/([a-z-]+)/', $code, $scripts);
    expect(array_values(array_unique($scripts[1])))->toEqualCanonicalizing([
        'verify-mail-capture', 'verify-mail-gateway', 'health-check', 'mail-identity', 'mail-routing', 'targets',
    ]);

    foreach (['--e2e', '--apply', '--repair', '--provisioning', 'postsuper', 'postqueue', 'sendmail', 'swaks', '/dev/tcp', 'nsupdate', 'apt-get', 'install -', 'mkdir', 'chmod', 'chown', 'rm -', 'mv ', 'cp ', 'tee '] as $mutation) {
        expect(str_contains($code, $mutation))->toBeFalse("verify-infrastructure contains {$mutation}");
    }

    expect(preg_match('/systemctl\s+(start|stop|restart|reload|enable|disable)/', $code))->toBe(0);
});

it('runs only as root, for one known target, with no other selector', function () {
    $host = verifyInfraHost();

    try {
        $notRoot = verifyInfraRun($host, 'staging-main', null, ['RATEGURU_VERIFYINFRA_EUID' => '1000']);
        expect($notRoot['status'])->not->toBe(0);
        expect($notRoot['output'])->toContain('verify-infrastructure must run as root');

        $unknown = verifyInfraRun($host, 'food-guru');
        expect($unknown['status'])->not->toBe(0);
        expect($unknown['output'])->toContain('unknown target: food-guru');
        expect($unknown['calls'])->toBe([]);
    } finally {
        removeScratchDir($host['scratch']);
    }

    exec('bash '.escapeshellarg(base_path('infrastructure/scripts/verify-infrastructure')).' --target staging-main --environment production 2>&1', $output, $status);
    expect($status)->not->toBe(0);
    expect(implode("\n", $output))->toContain('unknown argument: --environment');
});

it('is repository tooling, run from the uploaded trusted bundle and never installed', function () {
    expect(repositoryOnlyScriptNames())->toContain('verify-infrastructure');
    expect(requiredCliManifestNames())->not->toContain('verify-infrastructure');
    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/install-target-operations'))))
        ->not->toContain('verify-infrastructure');
});

// =============================================================================
// THE OPERATOR SURFACE: TWO WORKFLOWS, ONE ACTION
// =============================================================================

/** @return array<string, mixed> */
function verifyInfraWorkflow(string $file): array
{
    return Yaml::parseFile(base_path(".github/workflows/{$file}"));
}

/** @return array<string, mixed> */
function verifyInfraAction(): array
{
    return Yaml::parseFile(base_path('.github/actions/verify-rateguru-infrastructure/action.yml'));
}

function verifyInfraActionStep(string $name): string
{
    foreach (verifyInfraAction()['runs']['steps'] as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step['run'];
        }
    }

    throw new RuntimeException("no step named {$name}");
}

it('offers exactly two permanent verification workflows, both through the same action', function (string $file, string $name, string $target, string $environment, string $githubEnvironment, string $ref) {
    $workflow = verifyInfraWorkflow($file);

    expect($workflow['name'])->toBe($name);
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'])->toBeNull('no target, environment or ref is ever chosen');
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'verify']);
    expect($workflow['jobs']['validate-ref'])->not->toHaveKey('environment');

    $verify = $workflow['jobs']['verify'];
    expect($verify['needs'])->toBe(['validate-ref']);
    expect($verify['environment'])->toBe($githubEnvironment);
    expect($verify['runs-on'])->toBe('ubuntu-24.04');

    expect($verify['steps'][0]['uses'])->toStartWith('actions/checkout@');
    expect($verify['steps'][0]['with'])->toBe(['ref' => $ref, 'fetch-depth' => 1, 'persist-credentials' => false]);

    expect($verify['steps'][1]['uses'])->toBe('./.github/actions/verify-rateguru-infrastructure');
    expect($verify['steps'][1]['with'])->toBe([
        'deployment-target' => $target,
        'environment' => $environment,
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);

    expect(trustedToolingRef($file))->toBe($ref);

    expect(executableSourceLines(File::get(base_path(".github/workflows/{$file}"))))
        ->not->toContain('DEPLOY_SSH_KEY')
        ->not->toContain('MAIL_DKIM_PRIVATE_KEY');
})->with([
    'staging' => ['verify-staging-infrastructure.yml', 'Verify staging infrastructure', 'staging-main', 'staging', 'staging', 'develop'],
    'production' => ['verify-production-infrastructure.yml', 'Verify production infrastructure', 'tits-guru', 'production', 'production-tits-guru', 'main'],
]);

it('gates production verification to main before any job holds production credentials', function () {
    $workflow = verifyInfraWorkflow('verify-production-infrastructure.yml');
    $gate = $workflow['jobs']['validate-ref']['steps'][0]['run'];

    expect($gate)
        ->toContain('if [[ "${RUN_REF}" != "refs/heads/main" ]]; then')
        ->not->toContain('refs/heads/develop');

    // Byte for byte the gate every other manual production operation uses.
    expect($gate)->toBe(verifyInfraWorkflow('configure-tits-guru.yml')['jobs']['validate-ref']['steps'][0]['run']);

    $staging = verifyInfraWorkflow('verify-staging-infrastructure.yml')['jobs']['validate-ref']['steps'][0]['run'];
    expect($staging)->toBe(verifyInfraWorkflow('prepare-staging-host.yml')['jobs']['validate-ref']['steps'][0]['run']);
});

it('creates no subsystem-specific verification workflow', function () {
    $workflows = array_map('basename', glob(base_path('.github/workflows/*.yml')) ?: []);
    $verify = array_values(array_filter($workflows, static fn (string $file): bool => str_starts_with($file, 'verify-')));

    expect($verify)->toBe(['verify-production-infrastructure.yml', 'verify-staging-infrastructure.yml']);

    // The deep primitives stay on the host, and no workflow or action runs them.
    foreach ([...(glob(base_path('.github/workflows/*.yml')) ?: []), ...(glob(base_path('.github/actions/*/action.yml')) ?: [])] as $path) {
        expect(executableSourceLines(File::get($path)))
            ->not->toContain('--e2e')
            ->not->toContain('verify-mail-gateway')
            ->not->toContain('scripts/verify-mail-capture');
    }

    foreach (['verify-mail-gateway', 'verify-mail-capture'] as $primitive) {
        exec('bash '.escapeshellarg(base_path("infrastructure/scripts/{$primitive}")).' --help 2>&1', $help, $status);
        expect($status)->toBe(0);
        expect(implode("\n", $help))->toContain('--e2e')->toContain('--read-only');
    }
});

it('carries only connection inputs, and uses the bootstrap credential only', function () {
    $action = verifyInfraAction();

    expect(array_keys($action['inputs']))->toBe(['deployment-target', 'environment', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);
    expect(array_keys($action['outputs']))->toBe(['status', 'pass', 'fail', 'deferred']);

    $code = executableSourceLines(File::get(base_path('.github/actions/verify-rateguru-infrastructure/action.yml')));

    preg_match_all('/-i\s+"\$\{([A-Z_]+)\}"/', $code, $identities);
    expect(array_unique($identities[1]))->toBe(['RATEGURU_BOOTSTRAP_SSH_KEY_PATH']);
    expect($code)->not->toContain('DEPLOY_SSH_KEY')->not->toContain('StrictHostKeyChecking=no')->not->toContain('ssh-keyscan');
});

it('uploads a temporary trusted infrastructure bundle, runs exactly the verifier, and removes everything whatever happened', function () {
    $action = verifyInfraAction();
    $steps = collect($action['runs']['steps']);

    expect(verifyInfraActionStep('Package trusted infrastructure bundle'))
        ->toMatch('/--directory "\$\{GITHUB_WORKSPACE\}" infrastructure/');

    expect(verifyInfraActionStep('Upload the trusted infrastructure bundle'))
        ->toContain('remote_root="/root/rateguru-verify-${run_tag}"')
        ->toContain('install -d -m 0700 -o root -g root %q')
        ->toContain('rm -rf %q');

    $verify = verifyInfraActionStep('Verify the infrastructure');
    expect($verify)
        ->toContain('"${RATEGURU_REMOTE_ROOT}/infrastructure/scripts/verify-infrastructure"')
        ->toContain('--target "${DEPLOYMENT_TARGET}"')
        ->toContain("grep -c '^RATEGURU_INFRASTRUCTURE_VERIFY_RESULT='");

    // One remote command that does anything, and it is the verifier.
    $code = executableSourceLines(File::get(base_path('.github/actions/verify-rateguru-infrastructure/action.yml')));
    foreach (['--apply', '--e2e', 'scripts/prepare-host', 'scripts/bootstrap-host', 'scripts/deploy', 'restore-target', 'repair-target', 'install-target', '--material-dir', 'shared/.env', 'show-dns'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the verification action contains {$forbidden}");
    }

    $cleanup = ['Remove the remote infrastructure bundle', 'Remove temporary local files'];
    expect($steps->slice(-2)->pluck('name')->values()->all())->toBe($cleanup);
    foreach ($cleanup as $name) {
        expect($steps->firstWhere('name', $name)['if'])->toBe('${{ always() }}');
    }

    expect(verifyInfraActionStep('Remove the remote infrastructure bundle'))->toContain("'%s rm -rf %q && rm -rf %q'");
});

/** @return array{0: int, 1: string, 2: string} exit, output, GITHUB_OUTPUT */
function verifyInfraRunVerifyStep(string $remoteOutput, int $remoteStatus = 0): array
{
    $scratch = sys_get_temp_dir().'/verify-action-'.bin2hex(random_bytes(6));
    @mkdir($scratch.'/bin', 0o700, true);

    file_put_contents($scratch.'/remote-output', $remoteOutput);
    file_put_contents($scratch.'/bin/ssh', "#!/bin/bash\ncat \"\${STUB_REMOTE_OUTPUT}\"\nexit {$remoteStatus}\n");
    chmod($scratch.'/bin/ssh', 0o755);
    touch($scratch.'/github-output');
    file_put_contents($scratch.'/step.sh', verifyInfraActionStep('Verify the infrastructure'));

    try {
        $process = proc_open(['bash', $scratch.'/step.sh'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $scratch,
            'STUB_REMOTE_OUTPUT' => $scratch.'/remote-output',
            'GITHUB_OUTPUT' => $scratch.'/github-output',
            'RATEGURU_PRIVILEGED_PREFIX' => '',
            'RATEGURU_REMOTE_ROOT' => '/root/rateguru-verify-1-1',
            'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $scratch.'/key',
            'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $scratch.'/known',
            'BOOTSTRAP_HOST' => 'host.example',
            'BOOTSTRAP_PORT' => '22',
            'BOOTSTRAP_USER' => 'root',
            'DEPLOYMENT_TARGET' => 'tits-guru',
        ]);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output, (string) file_get_contents($scratch.'/github-output')];
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
}

it('accepts exactly one result for the target it asked about, and fails with the verifier', function () {
    $result = static fn (string $status, int $fail = 0): string => 'RATEGURU_INFRASTRUCTURE_VERIFY_RESULT='.json_encode([
        'target' => 'tits-guru', 'environment_class' => 'production', 'lifecycle' => 'planned', 'delivery_mode' => 'held',
        'status' => $status, 'pass' => 3, 'fail' => $fail, 'deferred' => 3, 'not_applicable' => 1,
    ]);

    [$exit, $output, $outputs] = verifyInfraRunVerifyStep("report\n".$result('pass')."\n");
    expect($exit)->toBe(0, $output);
    expect($output)->toContain('report');
    expect($outputs)->toBe("status=pass\npass=3\nfail=0\ndeferred=3\n");

    [$exit, $output] = verifyInfraRunVerifyStep("report\n".$result('fail', 1)."\n", 1);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('Infrastructure verification failed for tits-guru (exit 1)');

    [$exit, $output] = verifyInfraRunVerifyStep($result('pass')."\n".$result('pass')."\n");
    expect($exit)->not->toBe(0);
    expect($output)->toContain('Expected exactly one RATEGURU_INFRASTRUCTURE_VERIFY_RESULT line, got 2');

    [$exit, $output] = verifyInfraRunVerifyStep("ERROR: unknown target\n", 1);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('got 0');

    [$exit, $output] = verifyInfraRunVerifyStep(str_replace('tits-guru', 'staging-main', $result('pass'))."\n");
    expect($exit)->not->toBe(0);
    expect($output)->toContain('is not a verification result for tits-guru');
});

// =============================================================================
// THE REPOSITORY STAYS AS REVIEWED
// =============================================================================

it('keeps the real production target planned and held, with direct delivery disabled', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
    $routing = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);
    $outbound = json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');
    expect($routing['targets']['tits-guru']['delivery_mode'])->toBe('held');
    expect($outbound['direct']['enabled'])->toBeFalse();
});

it('documents the order from merge to the first production verification', function () {
    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/infrastructure-verification.md')));

    foreach ([
        'merge the pull request into `develop`',
        'Run **Prepare staging host**',
        'Run **Verify staging infrastructure**',
        'Promote `develop` to `main`',
        'Add `MAIL_DKIM_PRIVATE_KEY`',
        'Run **Configure tits.guru**',
        'Run **Verify production infrastructure**',
    ] as $step) {
        expect($runbook)->toContain($step);
    }

    expect(strpos($runbook, 'Run **Prepare staging host**'))->toBeLessThan(strpos($runbook, 'Run **Verify staging infrastructure**'));
    expect(strpos($runbook, 'Promote `develop` to `main`'))->toBeLessThan(strpos($runbook, 'Run **Configure tits.guru**'));
    expect(strpos($runbook, 'Run **Configure tits.guru**'))->toBeLessThan(strpos($runbook, 'Run **Verify production infrastructure**'));
});
