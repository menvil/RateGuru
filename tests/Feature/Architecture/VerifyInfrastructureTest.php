<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Infrastructure verification: infrastructure/scripts/verify-infrastructure,
 * the composite action that runs it on a host, and the two permanent
 * operator workflows, Verify staging infrastructure and Verify production
 * infrastructure.
 *
 * The verifier is run as shipped. Every contract owner it composes —
 * prepare-host, repair-target, bootstrap-host, configure-target,
 * bootstrap-host-preflight, install-target-perimeter, verify-mail-capture,
 * verify-mail-gateway, health-check — is a stub that records exactly how it was
 * called; mail-identity is the real one, with real keys and a DNS stub. What is
 * proved is the verifier's own job: which contracts the target's CURRENT
 * reviewed state requires, in which read-only modes, and how their verdicts
 * become groups of PASS, FAIL, DEFERRED or N/A.
 */

/** Every contract owner the verifier composes, and the override that points it at a stub. */
const VERIFY_INFRA_PRIMITIVES = [
    'prepare-host' => 'RATEGURU_VERIFYINFRA_PREPARE_HOST_BIN',
    'repair-target' => 'RATEGURU_VERIFYINFRA_REPAIR_TARGET_BIN',
    'bootstrap-host' => 'RATEGURU_VERIFYINFRA_BOOTSTRAP_HOST_BIN',
    'configure-target' => 'RATEGURU_VERIFYINFRA_CONFIGURE_TARGET_BIN',
    'bootstrap-host-preflight' => 'RATEGURU_VERIFYINFRA_HOST_PREFLIGHT_BIN',
    'install-target-perimeter' => 'RATEGURU_VERIFYINFRA_TARGET_PERIMETER_BIN',
    'verify-mail-capture' => 'RATEGURU_VERIFYINFRA_MAIL_CAPTURE_BIN',
    'verify-mail-gateway' => 'RATEGURU_VERIFYINFRA_MAIL_GATEWAY_BIN',
    'health-check' => 'RATEGURU_VERIFYINFRA_HEALTH_CHECK_BIN',
];

/**
 * A simulated host for the verifier: a recording stub for every contract owner,
 * DNS and the target's key root from mail-identity's own stubs.
 *
 * Each option lists primitives: `fail` exit 1, `signal` are killed by SIGTERM,
 * `unrunnable` exit 127 as a command that could not be executed, `missing` are
 * left out of the bundle altogether.
 *
 * @param  array{fail?: list<string>, signal?: list<string>, unrunnable?: list<string>, missing?: list<string>, dns?: array<string, mixed>}  $options
 * @return array{scratch: string, env: array<string, string>}
 */
function verifyInfraHost(array $options = []): array
{
    $scratch = mailIdentityScratch();
    @mkdir($scratch.'/toggles', 0o755, true);

    foreach (array_keys(VERIFY_INFRA_PRIMITIVES) as $primitive) {
        if (in_array($primitive, $options['missing'] ?? [], true)) {
            continue;
        }

        file_put_contents($scratch."/bin/{$primitive}", <<<STUB
            #!/bin/bash
            printf '%s %s\\n' '{$primitive}' "\$*" >> "\${STUB_CALLS}"
            if [[ -e "\${STUB_TOGGLES}/{$primitive}.signal" ]]; then kill -TERM \$\$; fi
            if [[ -e "\${STUB_TOGGLES}/{$primitive}.unrunnable" ]]; then echo "{$primitive}: No such file or directory"; exit 127; fi
            if [[ -e "\${STUB_TOGGLES}/{$primitive}.fail" ]]; then echo "  stub {$primitive}: MISSING something"; exit 1; fi
            echo "  stub {$primitive}: ok"
            STUB."\n");
        chmod($scratch."/bin/{$primitive}", 0o755);
    }

    foreach (['fail', 'signal', 'unrunnable'] as $how) {
        foreach ($options[$how] ?? [] as $primitive) {
            touch($scratch."/toggles/{$primitive}.{$how}");
        }
    }

    $env = [
        ...mailIdentityDnsHost($scratch, $options['dns'] ?? []),
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_VERIFYINFRA_EUID' => '0',
        'RATEGURU_VERIFYINFRA_FS_ROOT' => $scratch.'/fs',
        'STUB_CALLS' => $scratch.'/calls.log',
        'STUB_TOGGLES' => $scratch.'/toggles',
    ];

    foreach (VERIFY_INFRA_PRIMITIVES as $primitive => $override) {
        $env[$override] = $scratch."/bin/{$primitive}";
    }

    return ['scratch' => $scratch, 'env' => $env];
}

/**
 * @param  array<string, string>  $env
 * @return array{status: int, output: string, calls: list<string>, result: array<string, mixed>|null, groups: array<string, string>}
 */
function verifyInfraRun(array $host, string $target, ?string $script = null, array $env = [], string $bash = 'bash'): array
{
    @unlink($host['scratch'].'/calls.log');

    $process = proc_open(
        [$bash, $script ?? base_path('infrastructure/scripts/verify-infrastructure'), '--target', $target],
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
    $groups = collect($result['groups'] ?? [])->mapWithKeys(fn (array $group): array => [$group['id'] => $group['status']])->all();

    return ['status' => $status, 'output' => $output, 'calls' => $calls, 'result' => $result, 'groups' => $groups];
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

/** The calls a run made, as the primitives' names alone. */
function verifyInfraCalled(array $run): array
{
    return array_map(static fn (string $call): string => strtok($call, ' '), $run['calls']);
}

const VERIFY_INFRA_STAGING_CALLS = [
    'prepare-host --verify --target staging-main',
    'bootstrap-host-preflight --report',
    'repair-target --verify --target staging-main',
    'install-target-perimeter --verify',
    'verify-mail-capture --read-only',
    'verify-mail-gateway --read-only',
    'health-check --target staging-main',
];

const VERIFY_INFRA_PLANNED_CALLS = [
    'bootstrap-host --verify',
    'bootstrap-host-preflight --report',
    'configure-target --verify --target tits-guru',
    'install-target-perimeter --verify',
    'verify-mail-gateway --read-only',
];

// =============================================================================
// AN ACTIVE TARGET: STAGING
// =============================================================================

it('verifies active staging-main through its preparation and live-target contracts, the perimeter, mail and health', function () {
    $host = verifyInfraHost();

    try {
        $run = verifyInfraRun($host, 'staging-main');

        expect($run['status'])->toBe(0, $run['output']);

        // The contracts an active target is held to, in this order, each in its
        // read-only mode — and configure-target, the planned-target contract,
        // and bootstrap-host, which preparation already composes, never.
        expect($run['calls'])->toBe(VERIFY_INFRA_STAGING_CALLS);
        expect(verifyInfraCalled($run))->not->toContain('configure-target')->not->toContain('bootstrap-host');

        expect($run['groups'])->toBe([
            'preparation' => 'pass',
            'live-target' => 'pass',
            'operations-perimeter' => 'pass',
            'mail-capture' => 'pass',
            'mail-gateway' => 'pass',
            'mail-identity' => 'not_applicable',
            'application' => 'pass',
        ]);

        foreach (['PREPARATION CONTRACT', 'HOST INVENTORY', 'LIVE TARGET CONTRACT', 'OPERATIONS & BACKUP PERIMETER', 'MAIL CAPTURE', 'MAIL GATEWAY', 'MAIL IDENTITY', 'APPLICATION', 'SUMMARY'] as $section) {
            expect($run['output'])->toContain("\n{$section}\n");
        }

        expectVerifyItem($run['output'], 'PASS', 'prepare-host --verify --target staging-main', 'runtime, host prerequisites, host bootstrap, target prerequisites, target database');
        expectVerifyItem($run['output'], 'PASS', 'repair-target --verify --target staging-main', 'Repair would change nothing');
        expectVerifyItem($run['output'], 'PASS', 'install-target-perimeter --verify', 'backup cron');
        expectVerifyItem($run['output'], 'PASS', 'verify-mail-capture --read-only');
        expectVerifyItem($run['output'], 'PASS', 'verify-mail-gateway --read-only');
        expectVerifyItem($run['output'], 'N/A', 'mail-identity', 'a staging target has no production mail identity');
        expectVerifyItem($run['output'], 'PASS', 'health-check', 'staging-main answers its health endpoint');

        // Every child's own report stays in the log.
        expect($run['output'])->toContain('|   stub prepare-host: ok')->toContain('|   stub repair-target: ok')->toContain('|   stub bootstrap-host-preflight: ok');

        expect($run['output'])->toContain('VERIFY INFRASTRUCTURE: PASS')->not->toContain('does NOT mean');
        expect(collect($run['result'])->except('groups')->all())->toBe([
            'target' => 'staging-main',
            'environment_class' => 'staging',
            'lifecycle' => 'active',
            'delivery_mode' => 'capture',
            'status' => 'pass',
            'pass' => 6,
            'fail' => 0,
            'deferred' => 0,
            'not_applicable' => 1,
        ]);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('fails staging when any required group fails, and still inspects every other group', function (string $primitive, string $item, string $group) {
    $host = verifyInfraHost(['fail' => [$primitive]]);

    try {
        $run = verifyInfraRun($host, 'staging-main');

        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', $item, 'exit 1');
        expect($run['output'])->toContain('VERIFY INFRASTRUCTURE: FAIL');
        expect($run['result']['status'])->toBe('fail');
        expect($run['result']['fail'])->toBe(1);

        // One run diagnoses as much as it can: every independent read-only
        // group still ran, and only the failing one failed.
        expect($run['calls'])->toBe(VERIFY_INFRA_STAGING_CALLS);
        expect(array_keys(array_filter($run['groups'], static fn (string $status): bool => $status === 'fail')))->toBe([$group]);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'the preparation contract' => ['prepare-host', 'prepare-host --verify --target staging-main', 'preparation'],
    'the live-target contract' => ['repair-target', 'repair-target --verify --target staging-main', 'live-target'],
    'the operations perimeter' => ['install-target-perimeter', 'install-target-perimeter --verify', 'operations-perimeter'],
    'the capture services' => ['verify-mail-capture', 'verify-mail-capture --read-only', 'mail-capture'],
    'the mail gateway' => ['verify-mail-gateway', 'verify-mail-gateway --read-only', 'mail-gateway'],
    'the application' => ['health-check', 'health-check', 'application'],
]);

it('names the operation that converges each failing contract, and repairs nothing itself', function () {
    $host = verifyInfraHost(['fail' => ['prepare-host', 'repair-target', 'install-target-perimeter']]);

    try {
        $run = verifyInfraRun($host, 'staging-main');

        expectVerifyItem($run['output'], 'FAIL', 'prepare-host --verify --target staging-main', 'run Prepare Host for staging-main. Verify changes nothing');
        expectVerifyItem($run['output'], 'FAIL', 'repair-target --verify --target staging-main', 'run Repair Target for staging-main. Verify changes nothing');
        expectVerifyItem($run['output'], 'FAIL', 'install-target-perimeter --verify', 'run Prepare Host, which installs it. Verify changes nothing');
        expect($run['calls'])->toBe(VERIFY_INFRA_STAGING_CALLS);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('prints the full host inventory, and never lets it decide anything', function () {
    $host = verifyInfraHost(['fail' => ['bootstrap-host-preflight']]);

    try {
        // --report is inventory: here it even reports missing state and exits
        // non-zero, and the verdict is still the contracts'.
        $run = verifyInfraRun($host, 'staging-main');

        expect($run['status'])->toBe(0, $run['output']);
        expect($run['calls'])->toContain('bootstrap-host-preflight --report');
        expect($run['output'])
            ->toContain("\nHOST INVENTORY\n")
            ->toContain('|   stub bootstrap-host-preflight: MISSING something')
            ->toContain('Inventory, never a verdict.')
            ->toContain('The inventory did not complete (exit 1). It decides nothing');

        expect($run['result']['status'])->toBe('pass');
        expect(array_keys($run['groups']))->not->toContain('host-inventory');
        expect($run['result']['pass'] + $run['result']['fail'] + $run['result']['deferred'] + $run['result']['not_applicable'])->toBe(7);

        // It is only ever --report: never the preflight's gate mode.
        expect(collect($run['calls'])->filter(fn (string $call): bool => str_starts_with($call, 'bootstrap-host-preflight'))->values()->all())
            ->toBe(['bootstrap-host-preflight --report']);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('never turns a child that could not run, or that a signal ended, into a pass', function (string $how, string $primitive, string $item, string $reason) {
    $host = verifyInfraHost([$how => [$primitive]]);

    try {
        $run = verifyInfraRun($host, 'staging-main');

        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', $item, $reason);
        expect($run['result']['status'])->toBe('fail');
        expect($run['output'])->not->toMatch('/^  (PASS|DEFERRED) +'.preg_quote($item, '/').' /m');

        // The others still ran.
        expect($run['calls'])->toBe(VERIFY_INFRA_STAGING_CALLS);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'preparation killed' => ['signal', 'prepare-host', 'prepare-host --verify --target staging-main', 'terminated abnormally by signal 15 — an interrupted verification never passes'],
    'repair killed' => ['signal', 'repair-target', 'repair-target --verify --target staging-main', 'terminated abnormally by signal 15'],
    'gateway killed' => ['signal', 'verify-mail-gateway', 'verify-mail-gateway --read-only', 'terminated abnormally by signal 15'],
    'perimeter not runnable' => ['unrunnable', 'install-target-perimeter', 'install-target-perimeter --verify', 'could not be executed (exit 127) — nothing was verified, so nothing passes'],
    'health not runnable' => ['unrunnable', 'health-check', 'health-check', 'could not be executed (exit 127)'],
]);

it('stops before verifying anything when the trusted bundle is incomplete', function (string $target, string $missing) {
    $host = verifyInfraHost(['missing' => [$missing]]);

    try {
        $run = verifyInfraRun($host, $target);

        expect($run['status'])->not->toBe(0);
        expect($run['output'])->toContain('the trusted bundle is incomplete: '.$host['scratch']."/bin/{$missing} is missing or not executable — nothing was verified");
        expect($run['calls'])->toBe([]);
        expect($run['result'])->toBeNull();
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'staging without repair-target' => ['staging-main', 'repair-target'],
    'staging without the capture verifier' => ['staging-main', 'verify-mail-capture'],
    'production without configure-target' => ['tits-guru', 'configure-target'],
    'production without the perimeter verifier' => ['tits-guru', 'install-target-perimeter'],
    'production without the inventory' => ['tits-guru', 'bootstrap-host-preflight'],
]);

// =============================================================================
// A PLANNED PRODUCTION TARGET, BEFORE ITS LAUNCH
// =============================================================================

it('verifies planned tits-guru through the host bootstrap and its planned-target contract, never preparation or repair', function () {
    $host = verifyInfraHost();

    try {
        $run = verifyInfraRun($host, 'tits-guru');

        expect($run['status'])->toBe(0, $run['output']);

        // prepare-host and repair-target refuse a planned target, and their gate
        // is kept: the host baseline and the planned-target contract are
        // verified instead, and a planned target's application is never asked.
        expect($run['calls'])->toBe(VERIFY_INFRA_PLANNED_CALLS);
        expect(verifyInfraCalled($run))
            ->not->toContain('prepare-host')
            ->not->toContain('repair-target')
            ->not->toContain('health-check')
            ->not->toContain('verify-mail-capture');

        expect($run['groups'])->toBe([
            'host-bootstrap' => 'pass',
            'planned-target' => 'pass',
            'live-target' => 'deferred',
            'operations-perimeter' => 'pass',
            'mail-capture' => 'not_applicable',
            'mail-gateway' => 'pass',
            'mail-identity' => 'pass',
            'application' => 'deferred',
        ]);

        expectVerifyItem($run['output'], 'PASS', 'bootstrap-host --verify', 'the physical host baseline verifies');
        expectVerifyItem($run['output'], 'PASS', 'configure-target --verify --target tits-guru', 'still planned and undeployed');
        expectVerifyItem($run['output'], 'DEFERRED', 'repair-target', 'target lifecycle is planned; Repair is a live-target contract — not invoked');
        expectVerifyItem($run['output'], 'PASS', 'install-target-perimeter --verify');
        expectVerifyItem($run['output'], 'N/A', 'mail-capture');
        expectVerifyItem($run['output'], 'PASS', 'verify-mail-gateway --read-only');
        expectVerifyItem($run['output'], 'PASS', 'identity contract', 'mail-identity validate');
        expectVerifyItem($run['output'], 'DEFERRED', 'dkim-key', '/etc/opendkim/keys/tits-guru/rg1.private is absent — not required while tits-guru\'s mail is held');
        expectVerifyItem($run['output'], 'DEFERRED', 'outbound-readiness', 'not activated (delivery_mode held, direct delivery disabled)');
        expectVerifyItem($run['output'], 'DEFERRED', 'health-check', 'tits-guru is planned: it is intentionally not deployed or active');

        // The readiness picture is still shown, never hidden behind DEFERRED.
        expect($run['output'])->toContain('| mail-identity readiness --target tits-guru')->toContain('OUTBOUND READY: NO');

        expect($run['output'])
            ->toContain("Target: tits-guru\nEnvironment class: production\nLifecycle: planned\nMail delivery mode: held")
            ->toContain('  Mail identity                   PASS (2 deferred)')
            ->toContain("VERIFY INFRASTRUCTURE: PASS\nPASS means the CURRENT reviewed planned state is correct. It does NOT mean production is live.");
        expect($run['result'])->toMatchArray(['status' => 'pass', 'pass' => 5, 'fail' => 0, 'deferred' => 4, 'not_applicable' => 1, 'lifecycle' => 'planned', 'delivery_mode' => 'held']);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('fails planned production when any of its required groups fails, and still inspects the rest', function (string $primitive, string $item, string $group) {
    $host = verifyInfraHost(['fail' => [$primitive]]);

    try {
        $run = verifyInfraRun($host, 'tits-guru');

        expect($run['status'])->not->toBe(0);
        expectVerifyItem($run['output'], 'FAIL', $item);
        expect($run['output'])->toContain('VERIFY INFRASTRUCTURE: FAIL')->not->toContain('does NOT mean production is live');
        expect($run['calls'])->toBe(VERIFY_INFRA_PLANNED_CALLS);
        expect(array_keys(array_filter($run['groups'], static fn (string $status): bool => $status === 'fail')))->toBe([$group]);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'the host bootstrap' => ['bootstrap-host', 'bootstrap-host --verify', 'host-bootstrap'],
    'the planned-target contract' => ['configure-target', 'configure-target --verify --target tits-guru', 'planned-target'],
    'the operations perimeter' => ['install-target-perimeter', 'install-target-perimeter --verify', 'operations-perimeter'],
    'the mail gateway' => ['verify-mail-gateway', 'verify-mail-gateway --read-only', 'mail-gateway'],
]);

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
        expect($run['groups']['mail-identity'])->toBe('fail');
        expectNoKeyMaterial($run['output'], $weak);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

// =============================================================================
// A PRODUCTION TARGET, ONCE IT DELIVERS OUTBOUND AND OPERATES
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

it('holds an active production target to the preparation, live-target and application contracts', function () {
    $host = verifyInfraHost(['fail' => ['health-check']]);

    try {
        $active = verifyInfraRun($host, 'demo-shop', verifyInfraDemoShopBundle($host['scratch'], 'outbound', 'active'));

        expect($active['calls'])->toBe([
            'prepare-host --verify --target demo-shop',
            'bootstrap-host-preflight --report',
            'repair-target --verify --target demo-shop',
            'install-target-perimeter --verify',
            'verify-mail-gateway --read-only',
            'health-check --target demo-shop',
        ]);
        expectVerifyItem($active['output'], 'FAIL', 'health-check', 'demo-shop is active and its application is not healthy');
        expect($active['groups']['application'])->toBe('fail');

        exec('rm -rf '.escapeshellarg($host['scratch'].'/checkout'));

        $planned = verifyInfraRun($host, 'demo-shop', verifyInfraDemoShopBundle($host['scratch'], 'held'));

        expect(verifyInfraCalled($planned))->not->toContain('health-check')->not->toContain('prepare-host')->not->toContain('repair-target');
        expect($planned['calls'])->toContain('configure-target --verify --target demo-shop');
        expectVerifyItem($planned['output'], 'DEFERRED', 'health-check', 'demo-shop is planned');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('judges a disabled target by the host it sits on, and asks nothing of it as a live target', function () {
    $host = verifyInfraHost();

    try {
        $repo = provisionRepo($host['scratch'].'/checkout', provisionRegistryJson([], 'disabled'));
        mailIdentityFixtureConfig($repo.'/infrastructure/config', ['registry' => json_decode(provisionRegistryJson([], 'disabled'), true)]);

        $run = verifyInfraRun($host, 'demo-shop', $repo.'/infrastructure/scripts/verify-infrastructure');

        expect($run['status'])->toBe(0, $run['output']);
        expect(verifyInfraCalled($run))->toContain('bootstrap-host')->not->toContain('prepare-host')->not->toContain('repair-target')->not->toContain('configure-target')->not->toContain('health-check');
        expect($run['groups'])->toMatchArray(['host-bootstrap' => 'pass', 'live-target' => 'not_applicable', 'application' => 'not_applicable']);
        expect($run['groups'])->not->toHaveKey('planned-target')->not->toHaveKey('preparation');
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

it('keeps no host tool inventory of its own: the runtime is its owner\'s contract, and only jq is needed to start', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/verify-infrastructure')));

    foreach (['bind9-dnsutils', 'iproute2', 'openssl:', 'RUNTIME_PATH', 'HOST RUNTIME', 'apt-get', 'dpkg'] as $inventory) {
        expect(str_contains($code, $inventory))->toBeFalse("verify-infrastructure keeps its own runtime inventory: {$inventory}");
    }

    preg_match_all('/command -v (\S+)/', $code, $probes);
    expect($probes[1])->toBe(['jq']);

    // Without jq it cannot even resolve the target, and refuses before any
    // contract is asked.
    $host = verifyInfraHost();
    $noJq = $host['scratch'].'/no-jq';
    @mkdir($noJq, 0o755, true);
    symlink(trim((string) shell_exec('command -v dirname')), $noJq.'/dirname');

    try {
        $run = verifyInfraRun($host, 'staging-main', null, ['PATH' => $noJq], trim((string) shell_exec('command -v bash')));

        expect($run['status'])->not->toBe(0);
        expect($run['output'])->toContain('jq is required (package jq) — host runtime drift: run Prepare Host for this host');
        expect($run['calls'])->toBe([]);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('prints exactly one machine-readable result, with identity, counts and each group once', function () {
    $host = verifyInfraHost(['fail' => ['verify-mail-gateway']]);

    try {
        $key = mailIdentityInstallKey($host['scratch'], 'tits-guru', 'rg1');
        $run = verifyInfraRun($host, 'tits-guru');

        expect(preg_match_all('/^RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=/m', $run['output']))->toBe(1);
        expect(array_keys($run['result']))->toBe(['target', 'environment_class', 'lifecycle', 'delivery_mode', 'status', 'pass', 'fail', 'deferred', 'not_applicable', 'groups']);
        expect($run['result']['status'])->toBe('fail');

        $ids = array_column($run['result']['groups'], 'id');
        expect($ids)->toBe(array_values(array_unique($ids)));

        foreach ($run['result']['groups'] as $group) {
            expect(array_keys($group))->toBe(['id', 'title', 'status', 'pass', 'fail', 'deferred', 'not_applicable']);
            expect($group['status'])->toBeIn(['pass', 'fail', 'deferred', 'not_applicable']);
        }

        // The groups add up to the totals.
        foreach (['pass', 'fail', 'deferred', 'not_applicable'] as $count) {
            expect(array_sum(array_column($run['result']['groups'], $count)))->toBe($run['result'][$count]);
        }

        // The result is the last line, and carries nothing secret.
        $lines = array_values(array_filter(explode("\n", $run['output'])));
        expect(end($lines))->toStartWith('RATEGURU_INFRASTRUCTURE_VERIFY_RESULT=');
        expect(end($lines))->not->toContain('/etc/opendkim')->not->toContain('.private');

        expectNoKeyMaterial($run['output'], $key);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('runs every primitive in its read-only mode and nothing that could change the host', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/verify-infrastructure')));

    // The contract owners, each called in exactly one read-only mode.
    foreach ([
        'run_child "${PREPARATION_VERIFIER}" --verify --target "${TARGET_ID}"',
        'run_child "${LIVE_TARGET_VERIFIER}" --verify --target "${TARGET_ID}"',
        'run_child "${HOST_BOOTSTRAP_VERIFIER}" --verify',
        'run_child "${PLANNED_TARGET_VERIFIER}" --verify --target "${TARGET_ID}"',
        'run_child "${HOST_INVENTORY}" --report',
        'run_child "${PERIMETER_VERIFIER}" --verify',
        'run_child "${MAIL_CAPTURE_VERIFIER}" --read-only',
        'run_child "${MAIL_GATEWAY_VERIFIER}" --read-only',
        'run_child "${HEALTH_CHECK}" --target "${TARGET_ID}"',
    ] as $call) {
        expect(substr_count($code, $call))->toBe(1, "expected exactly one {$call}");
    }

    foreach (['PREPARATION_VERIFIER', 'LIVE_TARGET_VERIFIER', 'HOST_BOOTSTRAP_VERIFIER', 'PLANNED_TARGET_VERIFIER', 'HOST_INVENTORY', 'PERIMETER_VERIFIER', 'MAIL_CAPTURE_VERIFIER', 'MAIL_GATEWAY_VERIFIER', 'HEALTH_CHECK'] as $child) {
        expect(substr_count($code, 'run_child "${'.$child.'}"'))->toBe(1, "{$child} is run more than once");
    }

    // mail-identity: only its read-only commands.
    preg_match_all('/"\$\{MAIL_IDENTITY\}" ([a-z-]+)/', $code, $commands);
    expect(array_values(array_unique($commands[1])))->toBe(['validate', 'dkim-key', 'check-key', 'readiness']);

    // The scripts it can reach at all are exactly the primitives it composes:
    // no backup, restore, deploy, rollback or installer in --apply.
    preg_match_all('/\$\{SCRIPT_DIR\}\/([a-z-]+)/', $code, $scripts);
    expect(array_values(array_unique($scripts[1])))->toEqualCanonicalizing([
        'prepare-host', 'repair-target', 'bootstrap-host', 'configure-target', 'bootstrap-host-preflight', 'install-target-perimeter',
        'verify-mail-capture', 'verify-mail-gateway', 'health-check', 'mail-identity', 'mail-routing', 'targets',
    ]);

    foreach (['--apply', '--check', '--e2e', '--repair', '--provisioning', '--material-dir', '--recovery-backup', 'postsuper', 'postqueue', 'postfix ', 'sendmail', 'swaks', 'smtp', '/dev/tcp', 'nsupdate', 'apt-get', 'install -', 'mkdir', 'chmod', 'chown', 'setfacl', 'rm -', 'mv ', 'cp ', 'tee ', 'opendkim-', 'milter', 'genpkey', 'backup-cycle', 'restore-test', 'restore-target', 'offsite-', 'deploy ', 'rollback ', 'migrate', 'shared/.env'] as $mutation) {
        expect(str_contains($code, $mutation))->toBeFalse("verify-infrastructure contains {$mutation}");
    }

    expect(preg_match('/systemctl\s+(start|stop|restart|reload|enable|disable)/', $code))->toBe(0);
});

it('asks every child for nothing but a read-only mode, on any path', function () {
    $host = verifyInfraHost();

    try {
        $calls = [
            ...verifyInfraRun($host, 'staging-main')['calls'],
            ...verifyInfraRun($host, 'tits-guru')['calls'],
            ...verifyInfraRun($host, 'demo-shop', verifyInfraDemoShopBundle($host['scratch'], 'outbound', 'active'))['calls'],
        ];

        foreach ($calls as $call) {
            expect(preg_match('/^[a-z-]+ (--verify( --target [a-z-]+)?|--report|--read-only|--target [a-z-]+)$/', $call))->toBe(1, "not a read-only invocation: {$call}");
        }
    } finally {
        removeScratchDir($host['scratch']);
    }
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

/** @return array{0: int, 1: string, 2: string, 3: string} exit, output, GITHUB_OUTPUT, step summary */
function verifyInfraRunVerifyStep(string $remoteOutput, int $remoteStatus = 0): array
{
    $scratch = makeScratchDir('verify-action', ['/bin'], 0o700);

    file_put_contents($scratch.'/remote-output', $remoteOutput);
    file_put_contents($scratch.'/bin/ssh', "#!/bin/bash\ncat \"\${STUB_REMOTE_OUTPUT}\"\nexit {$remoteStatus}\n");
    chmod($scratch.'/bin/ssh', 0o755);
    touch($scratch.'/github-output');
    touch($scratch.'/summary');
    file_put_contents($scratch.'/step.sh', verifyInfraActionStep('Verify the infrastructure'));

    try {
        $process = proc_open(['bash', $scratch.'/step.sh'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $scratch,
            'STUB_REMOTE_OUTPUT' => $scratch.'/remote-output',
            'GITHUB_OUTPUT' => $scratch.'/github-output',
            'GITHUB_STEP_SUMMARY' => $scratch.'/summary',
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

        return [proc_close($process), $output, (string) file_get_contents($scratch.'/github-output'), (string) file_get_contents($scratch.'/summary')];
    } finally {
        removeScratchDir($scratch);
    }
}

/** A result line for tits-guru as the verifier prints it today, with $changes applied. */
function verifyInfraResultLine(array $changes = []): string
{
    $group = static fn (string $id, string $title, string $status, int $pass = 0, int $deferred = 0, int $na = 0, int $fail = 0): array => [
        'id' => $id, 'title' => $title, 'status' => $status, 'pass' => $pass, 'fail' => $fail, 'deferred' => $deferred, 'not_applicable' => $na,
    ];

    $result = [
        'target' => 'tits-guru', 'environment_class' => 'production', 'lifecycle' => 'planned', 'delivery_mode' => 'held',
        'status' => 'pass', 'pass' => 5, 'fail' => 0, 'deferred' => 4, 'not_applicable' => 1,
        'groups' => [
            $group('host-bootstrap', 'Host bootstrap', 'pass', 1),
            $group('planned-target', 'Planned target contract', 'pass', 1),
            $group('live-target', 'Live target contract', 'deferred', 0, 1),
            $group('operations-perimeter', 'Operations & backup perimeter', 'pass', 1),
            $group('mail-capture', 'Mail capture', 'not_applicable', 0, 0, 1),
            $group('mail-gateway', 'Mail gateway', 'pass', 1),
            $group('mail-identity', 'Mail identity', 'pass', 1, 2),
            $group('application', 'Application', 'deferred', 0, 1),
        ],
    ];

    foreach ($changes as $path => $value) {
        data_set($result, $path, $value);
    }

    return 'RATEGURU_INFRASTRUCTURE_VERIFY_RESULT='.json_encode($result);
}

it('accepts exactly one result for the target it asked about, and fails with the verifier', function () {
    [$exit, $output, $outputs] = verifyInfraRunVerifyStep("report\n".verifyInfraResultLine()."\n");
    expect($exit)->toBe(0, $output);
    expect($output)->toContain('report');
    expect($outputs)->toBe("status=pass\npass=5\nfail=0\ndeferred=4\n");

    $failed = verifyInfraResultLine(['status' => 'fail', 'fail' => 1, 'groups.5.status' => 'fail', 'groups.5.fail' => 1, 'groups.5.pass' => 0]);
    [$exit, $output] = verifyInfraRunVerifyStep("report\n{$failed}\n", 1);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('Infrastructure verification failed for tits-guru (exit 1)');

    [$exit, $output] = verifyInfraRunVerifyStep(verifyInfraResultLine()."\n".verifyInfraResultLine()."\n");
    expect($exit)->not->toBe(0);
    expect($output)->toContain('Expected exactly one RATEGURU_INFRASTRUCTURE_VERIFY_RESULT line, got 2');

    [$exit, $output] = verifyInfraRunVerifyStep("ERROR: unknown target\n", 1);
    expect($exit)->not->toBe(0);
    expect($output)->toContain('got 0');

    [$exit, $output] = verifyInfraRunVerifyStep(verifyInfraResultLine(['target' => 'staging-main'])."\n");
    expect($exit)->not->toBe(0);
    expect($output)->toContain('is not a verification result for tits-guru');
});

it('writes the group table into the job summary, with the overall verdict and what a planned PASS does not mean', function () {
    [$exit, $output, , $summary] = verifyInfraRunVerifyStep("report\n".verifyInfraResultLine()."\n");

    expect($exit)->toBe(0, $output);
    expect($summary)->toContain(implode("\n", [
        '## tits-guru infrastructure',
        '',
        'production, lifecycle planned',
        '',
        '| Group | Result |',
        '| --- | --- |',
        '| Host bootstrap | PASS |',
        '| Planned target contract | PASS |',
        '| Live target contract | DEFERRED |',
        '| Operations & backup perimeter | PASS |',
        '| Mail capture | N/A |',
        '| Mail gateway | PASS |',
        '| Mail identity | PASS (2 deferred) |',
        '| Application | DEFERRED |',
        '| **Overall** | **PASS** |',
    ]));
    expect($summary)
        ->toContain('PASS means the CURRENT reviewed planned state is correct. It does NOT mean production is live.')
        ->toContain('Read-only: nothing on the host was changed. The complete child reports are in the job log.');

    // A failing run shows its table too, and an active target carries no
    // planned-state caveat.
    $failed = verifyInfraResultLine(['status' => 'fail', 'fail' => 1, 'lifecycle' => 'active', 'groups.0' => ['id' => 'preparation', 'title' => 'Preparation contract', 'status' => 'fail', 'pass' => 0, 'fail' => 1, 'deferred' => 0, 'not_applicable' => 0]]);
    [$exit, , , $summary] = verifyInfraRunVerifyStep("{$failed}\n", 1);

    expect($exit)->not->toBe(0);
    expect($summary)
        ->toContain('| Preparation contract | **FAIL** |')
        ->toContain('| **Overall** | **FAIL** |')
        ->not->toContain('does NOT mean');
});

it('validates every group before any of the result reaches the summary', function (array $changes) {
    [$exit, $output, $outputs, $summary] = verifyInfraRunVerifyStep(verifyInfraResultLine($changes)."\n");

    expect($exit)->not->toBe(0, $output);
    expect($output)->toContain('is not a verification result for tits-guru');
    expect($outputs)->toBe('');
    expect($summary)->toBe('');
})->with([
    'no groups' => [['groups' => null]],
    'an empty group list' => [['groups' => []]],
    'groups not a list' => [['groups' => 'all fine']],
    'a group twice' => [['groups.1.id' => 'host-bootstrap']],
    'an unknown status' => [['groups.0.status' => 'ok']],
    'a group without counts' => [['groups.0.pass' => '1']],
    'markup in a title' => [['groups.0.title' => 'Host | **PASS**']],
    'an id that is not a word' => [['groups.0.id' => 'Host bootstrap']],
    'a failing group under an overall pass' => [['groups.0.status' => 'fail']],
    'an overall fail without a failing group' => [['status' => 'fail']],
    'no lifecycle' => [['lifecycle' => null]],
]);

it('says so in the summary of either workflow when a verification produced no result at all', function (string $file, string $target) {
    $steps = verifyInfraWorkflow($file)['jobs']['verify']['steps'];

    expect($steps)->toHaveCount(3);
    expect($steps[1]['id'])->toBe('verify');
    expect($steps[2]['name'])->toBe('Report an incomplete verification');
    expect($steps[2]['if'])->toBe("\${{ always() && steps.verify.outputs.status == '' }}");
    expect($steps[2]['run'])
        ->toContain("echo \"## {$target} infrastructure\"")
        ->toContain('The verification did not complete, so there is no result.')
        ->not->toContain('PASS');
})->with([
    'staging' => ['verify-staging-infrastructure.yml', 'staging-main'],
    'production' => ['verify-production-infrastructure.yml', 'tits-guru'],
]);

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

it('documents the operator model: Verify diagnoses, and which operation converges each finding', function () {
    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/infrastructure-verification.md')));

    expect($runbook)
        ->toContain('| **Verify infrastructure** | diagnose all currently managed infrastructure, read-only |')
        ->toContain('| **Prepare Host** | converge the host and its global preparation state |')
        ->toContain('| **Repair Target** | converge the infrastructure around an already-live release |')
        ->toContain('| **Configure Target** | establish a planned production target\'s material and database |')
        ->toContain('| **Deep acceptance** | deliberately active or disruptive tests: mail end-to-end, restore tests |')
        ->toContain('A host or preparation finding → **Prepare Host**')
        ->toContain('A live target finding → **Repair Target**')
        ->toContain('An incomplete planned production target → **Configure Target**')
        ->toContain('Verify itself never repairs anything.');

    foreach (['prepare-host --verify --target', 'repair-target --verify --target', 'bootstrap-host --verify', 'configure-target --verify --target', 'bootstrap-host-preflight --report', 'install-target-perimeter --verify'] as $primitive) {
        expect($runbook)->toContain($primitive);
    }
});
