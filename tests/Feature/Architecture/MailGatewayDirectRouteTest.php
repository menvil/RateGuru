<?php

use Illuminate\Support\Facades\File;

/**
 * The host-global mail gateway's direct outbound routes, and the host-global
 * outbound contract (infrastructure/config/mail-outbound.json) they need:
 * rendered, installed only once the host enables direct delivery, and read
 * back through Postfix.
 *
 * Two kinds of test, both against the shipped scripts:
 *
 *   * the renderer, sourced from install-mail-gateway and driven by plans the
 *     real mail-routing CLI renders — including synthetic demo-shop and
 *     demo-books targets the implementation never names;
 *   * the installer as a whole, run against a simulated host: FS_ROOT plus
 *     stubs for dpkg-query, apt-get, debconf, systemctl, ss, postconf and
 *     postfix. The postconf stub reads back what the rendered files say; it is
 *     a test double, not Postfix.
 *
 * CI does not run the Postfix binary, and nothing here claims it does. Postfix's
 * own acceptance of this configuration, and the daemon's behaviour, are what a
 * real-host acceptance (verify-mail-gateway --e2e) and a disposable rehearsal
 * with real Postfix exist for.
 */

/**
 * status-mail-gateway on the simulated host: the same stubs first on PATH, plus
 * an empty queue and an empty journal.
 */
function mailGatewayStatus(array $host): string
{
    foreach (['postqueue', 'journalctl'] as $tool) {
        file_put_contents($host['scratch']."/bin/{$tool}", "#!/bin/bash\nexit 0\n");
        chmod($host['scratch']."/bin/{$tool}", 0o755);
    }

    $process = proc_open(
        ['bash', mailGatewayScript('status-mail-gateway')],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], 'PATH' => $host['scratch'].'/bin:'.$host['env']['PATH']],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return $output;
}

/**
 * The demo-shop outbound plan's gateway, rendered against an enabled contract.
 *
 * @return array{main: string, master: string, plan: array<string, mixed>}
 */
function mailGatewayDirectRender(?array $outbound = null): array
{
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();

    return mailGatewayRender($policy, mailRoutingDemoShopRegistry(), $outbound ?? mailGatewayOutboundContract());
}

// =============================================================================
// SIGNING: ONLY THE SIGNED LISTENERS, AND NEVER UNSIGNED
// =============================================================================

it('renders exactly one dedicated direct smtp client for an outbound target, selected only by its own listener', function () {
    $render = mailGatewayDirectRender();
    $services = collect(mailGatewayMasterServices($render['master']));

    // The listener names its own transport and NO next hop: the queue manager
    // then uses each recipient's own domain, so the client looks up its MX.
    $listener = $services->firstWhere('name', '127.0.0.1:2599');
    expect($listener['command'])->toBe('smtpd');
    expect($listener['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-demo-shop',
        'smtpd_delay_reject' => 'no',
        'smtpd_reject_unlisted_recipient' => 'no',
        'smtpd_sender_restrictions' => '$rateguru_demo_shop_sender_restrictions',
        'content_filter' => 'rateguru-outbound-demo-shop:',
    ]);
    expect(mailGatewayMainParameters($render['main']))->toHaveKey('default_filter_nexthop');
    expect(mailGatewayMainParameters($render['main'])['default_filter_nexthop'])->toBe('');

    // Exactly one dedicated client, with the host's MTA identity, opportunistic
    // STARTTLS, no SMTP AUTH and no fallback relay.
    expect($services->where('name', 'rateguru-outbound-demo-shop')->count())->toBe(1);
    $transport = $services->firstWhere('name', 'rateguru-outbound-demo-shop');
    expect([$transport['type'], $transport['command']])->toBe(['unix', 'smtp']);
    expect($transport['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-outbound-demo-shop',
        'smtp_helo_name' => 'mta1.example.net',
        'smtp_tls_security_level' => 'may',
        'smtp_tls_loglevel' => '1',
        'smtp_sasl_auth_enable' => 'no',
        'smtp_fallback_relay' => '',
    ]);

    // Only its own listener names it.
    $naming = $services->filter(static fn (array $service): bool => str_contains($service['options']['content_filter'] ?? '', 'rateguru-outbound-'));
    expect($naming->pluck('name')->values()->all())->toBe(['127.0.0.1:2599']);

    // The smtp clients are the capture transport and this one, nothing else.
    expect($services->where('command', 'smtp')->pluck('name')->sort()->values()->all())
        ->toBe(['rateguru-capture-staging-main', 'rateguru-outbound-demo-shop']);
});

it('gives every outbound target its own client, and no listener can reach another target\'s', function () {
    ['policy' => $policy, 'registry' => $registry] = mailRoutingTwoOutboundTargets();
    $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract());
    $services = collect(mailGatewayMasterServices($render['master']));

    $routes = $services->where('type', 'inet')->mapWithKeys(
        static fn (array $service): array => [$service['name'] => $service['options']['content_filter'] ?? null],
    )->all();

    // The two synthetic brands beside the committed tits-guru, outbound too.
    expect($routes)->toBe([
        '127.0.0.1:2598' => 'rateguru-outbound-demo-books:',
        '127.0.0.1:2599' => 'rateguru-outbound-demo-shop:',
        '127.0.0.1:2525' => 'rateguru-capture-staging-main:[127.0.0.1]:1025',
        '127.0.0.1:2526' => 'rateguru-outbound-tits-guru:',
    ]);

    foreach (['demo-books', 'demo-shop', 'tits-guru'] as $identity) {
        expect($services->where('name', "rateguru-outbound-{$identity}")->count())->toBe(1);
    }

    // All share the host's one MTA identity: it is the host's, not a brand's.
    expect($services->whereIn('name', ['rateguru-outbound-demo-books', 'rateguru-outbound-demo-shop', 'rateguru-outbound-tits-guru'])->pluck('options.smtp_helo_name')->unique()->values()->all())
        ->toBe(['mta1.example.net']);
});

it('keeps every fallback undeliverable and nothing public once an outbound route exists', function () {
    $render = mailGatewayDirectRender();
    $main = mailGatewayMainParameters($render['main']);
    $services = collect(mailGatewayMasterServices($render['master']));

    foreach (['default_transport', 'relay_transport', 'local_transport', 'virtual_transport'] as $transport) {
        expect($main[$transport])->toStartWith('error:');
    }

    foreach (['relayhost', 'mydestination', 'relay_domains', 'transport_maps', 'content_filter', 'sender_dependent_relayhost_maps', 'sender_dependent_default_transport_maps'] as $empty) {
        expect($main[$empty])->toBe('', "{$empty} must be empty");
    }

    // No generic client: no smtp service, and relay is the error transport.
    expect($services->pluck('name')->all())->not->toContain('smtp');
    expect($services->firstWhere('name', 'relay')['command'])->toBe('error');

    // No SMTP AUTH anywhere, and the listeners stay plain loopback IPv4.
    expect($main['smtpd_sasl_auth_enable'])->toBe('no');
    expect($main['smtp_sasl_auth_enable'])->toBe('no');
    expect($main['smtpd_tls_security_level'])->toBe('none');
    expect($main['inet_interfaces'])->toBe('127.0.0.1');
    expect($main['inet_protocols'])->toBe('ipv4');

    foreach ($services->where('type', 'inet') as $service) {
        expect(preg_match('/\A127\.0\.0\.1:(\d+)\z/', $service['name'], $matches))->toBe(1);
        expect((int) $matches[1])->not->toBeIn([25, 465, 587]);
    }

    foreach (['smtp_sasl_password_maps', 'smtpd_tls_cert_file', 'opendkim', 'relayhost = ['] as $absent) {
        expect(str_contains(mb_strtolower($render['main'].$render['master']), $absent))->toBeFalse("the rendered gateway contains {$absent}");
    }

    // The outbound route adds no milter: only the signing plan's listeners
    // name the signer, and demo-shop is not in it here.
    expect(str_contains($render['main'], 'milter'))->toBeFalse();
    expect($services->filter(static fn (array $service): bool => isset($service['options']['smtpd_milters']))->pluck('name')->values()->all())
        ->toBe(['127.0.0.1:2526']);
});

it('renders a direct route identically every time, however its inputs are ordered', function () {
    $first = mailGatewayDirectRender();
    $second = mailGatewayDirectRender();

    expect($second['main'])->toBe($first['main']);
    expect($second['master'])->toBe($first['master']);

    $reverse = function (mixed $node) use (&$reverse): mixed {
        return is_array($node) && ! array_is_list($node) ? array_map($reverse, array_reverse($node, true)) : $node;
    };

    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $reordered = mailGatewayRender($reverse($policy), $reverse(mailRoutingDemoShopRegistry()), $reverse(mailGatewayOutboundContract()));

    expect($reordered['main'])->toBe($first['main']);
    expect($reordered['master'])->toBe($first['master']);
});

it('refuses to render a direct route unless the host contract enables direct delivery under a public name', function (array $contract, string $reason) {
    $scratch = mailGatewayScratch();

    try {
        $policy = mailGatewayDemoShopPolicy();
        $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson($policy, mailRoutingDemoShopRegistry()));
        file_put_contents($scratch.'/mail-outbound.json', mailRoutingJson($contract));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', $scratch.'/mail-outbound.json');

        expect($render['status'])->not->toBe(0);
        expect($render['output'])->toContain($reason);
        expect($render['main'].$render['master'])->toBe('', 'a refused contract still produced a configuration');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'disabled' => [mailGatewayOutboundContract(false, ''), 'demo-shop: its mail routing plan delivers by direct SMTP, but direct outbound delivery is not enabled on this host (mail-outbound.json direct.enabled is false)'],
    'disabled, with a hostname ready' => [mailGatewayOutboundContract(false), 'direct outbound delivery is not enabled on this host'],
    'enabled with no hostname' => [mailGatewayOutboundContract(true, ''), 'direct.enabled is true but direct.mta_hostname is empty'],
    'enabled under .invalid' => [mailGatewayOutboundContract(true, 'mail-gateway.rateguru.invalid'), 'direct.mta_hostname "mail-gateway.rateguru.invalid" is under the reserved .invalid domain'],
    'enabled under .test' => [mailGatewayOutboundContract(true, 'mta.rehearsal.test'), 'is under the reserved .test domain'],
    'enabled under .localhost' => [mailGatewayOutboundContract(true, 'mta.localhost'), 'is under the reserved .localhost domain'],
    'enabled under .example' => [mailGatewayOutboundContract(true, 'mta.demo-shop.example'), 'is under the reserved .example domain'],
    'enabled under .localdomain' => [mailGatewayOutboundContract(true, 'ubuntu.localdomain'), 'is under the reserved .localdomain domain'],
    'enabled with a bare label' => [mailGatewayOutboundContract(true, 'mta1'), 'direct.mta_hostname must be a lowercase fully qualified hostname, got "mta1"'],
    'enabled with an IP address' => [mailGatewayOutboundContract(true, '203.0.113.25'), 'must be a lowercase fully qualified hostname, got "203.0.113.25"'],
    'enabled with uppercase' => [mailGatewayOutboundContract(true, 'MTA1.example.net'), 'must be a lowercase fully qualified hostname'],
    'enabled with a trailing dot' => [mailGatewayOutboundContract(true, 'mta1.example.net.'), 'must be a lowercase fully qualified hostname'],
    'enabled with a second directive' => [mailGatewayOutboundContract(true, 'mta1.example.net relayhost=evil.example'), 'must be a lowercase fully qualified hostname'],
    'enabled with a newline' => [mailGatewayOutboundContract(true, "mta1.example.net\nrelayhost = evil.example"), 'must not contain control characters in any key or value'],
    'enabled as a string' => [mailGatewayOutboundContract('true'), 'direct.enabled must be true or false, got "true"'],
    'a credential beside it' => [['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net', 'password' => 'x']], 'direct must be exactly {enabled, mta_hostname}, found ["enabled","mta_hostname","password"]'],
    'a relay beside it' => [['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net'], 'relayhost' => '[smtp.example.com]:587'], 'mail-outbound.json must be exactly {schema_version, direct}'],
    'another schema' => [['schema_version' => 2, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net']], 'unsupported mail-outbound.json schema_version: 2 (expected 1)'],
    'no direct section' => [['schema_version' => 1], 'mail-outbound.json must be exactly {schema_version, direct}, found ["schema_version"]'],
]);

it('refuses an outbound route in --check, --apply and --verify while direct delivery is disabled, changing nothing', function (array $contract, string $reason) {
    // A converged gateway first: the real plan, the committed contract.
    $host = mailGatewayHost();

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        $before = mailGatewayTree($host);
        file_put_contents($host['scratch'].'/log/mutations.log', '');

        // Only the policy moves to outbound; the host contract does not allow it.
        $policy = mailGatewayDemoShopPolicy();
        $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));
        file_put_contents($host['scratch'].'/registry.json', mailRoutingJson(mailRoutingDemoShopRegistry()));
        file_put_contents($host['scratch'].'/outbound.json', mailRoutingJson($contract));

        $env = [
            'RATEGURU_MAILGW_POLICY_FILE' => $host['scratch'].'/policy.json',
            'RATEGURU_MAILGW_REGISTRY_FILE' => $host['scratch'].'/registry.json',
            'RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/outbound.json',
        ];

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$status, $output] = mailGatewayRun($host, $mode, $env);

            expect($status)->not->toBe(0, "{$mode} accepted an outbound route the host has not enabled:\n{$output}");
            expect($output)
                ->toContain($reason)
                ->toContain('nothing was rendered, and nothing on the host was changed')
                ->not->toContain('APPLY    installing')
                ->not->toContain('SUMMARY');
        }

        // No file, no package, no service, no reload — not even a backup.
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'the committed contract' => [mailGatewayOutboundContract(false, 'mta1.tits.guru'), 'demo-shop: its mail routing plan delivers by direct SMTP, but direct outbound delivery is not enabled on this host'],
    'enabled with no hostname' => [mailGatewayOutboundContract(true, ''), 'direct.enabled is true but direct.mta_hostname is empty'],
    'enabled under .invalid' => [mailGatewayOutboundContract(true, 'mail.rateguru.invalid'), 'is under the reserved .invalid domain'],
]);

it('never installs Postfix for an outbound route the host has not enabled', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();

    $host = mailGatewayHost(['policy' => $policy, 'registry' => mailRoutingDemoShopRegistry()]);

    try {
        $before = mailGatewayTree($host);

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('direct outbound delivery is not enabled on this host');
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayLog($host, 'debconf.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses a host contract it cannot trust as the reviewed file', function () {
    $host = mailGatewayHost();

    try {
        // Missing.
        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/absent.json']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('the host outbound contract is unavailable');

        // Reached through a symlink.
        file_put_contents($host['scratch'].'/real.json', mailRoutingJson(mailGatewayOutboundContract(false, '')));
        symlink($host['scratch'].'/real.json', $host['scratch'].'/link.json');
        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/link.json']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('the host outbound contract must not be a symlink');

        // A key declared twice: the reviewer and the parser would read different files.
        file_put_contents($host['scratch'].'/twice.json', '{"schema_version": 1, "direct": {"enabled": true, "enabled": false, "mta_hostname": ""}}');
        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/twice.json']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('declares the same key twice in one object');

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('installs, verifies and reports a direct route once the host enables direct delivery', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $registry = mailRoutingDemoShopRegistry();
    $identity = mailGatewayIdentityWithDemoShop();

    // An outbound target always has a reviewed identity, so it is signed too.
    // The host first records its inert policy — demo-shop held, direct
    // delivery disabled — and crosses to outbound only with the one-use
    // authorization activate-mail-outbound would write.
    $host = mailGatewayHost(['policy' => mailGatewayDemoShopPolicy(), 'registry' => $registry, 'outbound' => mailGatewayOutboundContract(false, 'mta1.example.net'), 'identity' => $identity]);

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));
        file_put_contents($host['scratch'].'/outbound.json', mailRoutingJson(mailGatewayOutboundContract()));

        [$refused, $log] = mailGatewayRun($host, '--apply');
        expect($refused)->toBe(1, $log);
        expect($log)->toContain("this bundle moves demo-shop's mail from held to outbound — the activation boundary, which only activate-mail-outbound crosses");

        mailGatewayAuthorize($host, 'activate', 'demo-shop');
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);
        expect($log)->toContain('the activate of demo-shop is authorized by activate-mail-outbound for exactly this recorded and requested policy — consuming that one-use authorization');
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();

        $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract(), mailGatewaySigningPlanFor($policy, $registry, mailGatewayOutboundContract(), $identity));
        $direct = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', '127.0.0.1:2599');
        expect($direct['options']['smtpd_milters'])->toBe('inet:127.0.0.1:8891');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toBe($render['master']);
        expect(File::get($host['fs'].'/etc/postfix/main.cf'))->toBe($render['main']);

        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(0, $report);
        expect($report)
            ->toContain('PASS     outbound:direct — direct delivery enabled on this host as mta1.example.net; 1 outbound route(s) in the plan')
            ->toContain('PASS     signing — listeners of demo-shop, tits-guru hand their mail to the signer')
            ->toContain('PASS     file:/etc/postfix/rateguru-from-demo-shop.regexp — matches the current render')
            ->toContain('SUMMARY  pass=13 missing=0 drift=0 conflict=0 deferred=0');

        // The read-only status shows the route as what it is, and no address.
        $status = mailGatewayStatus($host);
        expect($status)
            ->toContain('127.0.0.1:2599  rateguru-demo-shop  outbound, queued -> direct SMTP -> recipient MX (rateguru-outbound-demo-shop, HELO mta1.example.net, TLS may)')
            ->toContain('127.0.0.1:2526  rateguru-tits-guru  HELD')
            ->toContain('127.0.0.1:2525  rateguru-staging-main  capture, queued -> [127.0.0.1]:1025');
        expect(preg_match('/[a-z0-9._-]+@[a-z0-9][a-z0-9-]*\.[a-z]/i', $status))->toBe(0, "status printed an address:\n{$status}");
    } finally {
        mailGatewayCleanup($host);
    }
});

it('reads a direct route back through Postfix, and refuses every way it could be weakened', function (string $file, string $from, string $to, string $problem) {
    $scratch = mailGatewayScratch();

    try {
        $policy = mailGatewayDemoShopPolicy();
        $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson($policy, mailRoutingDemoShopRegistry()));
        file_put_contents($scratch.'/mail-outbound.json', mailRoutingJson(mailGatewayOutboundContract()));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', $scratch.'/mail-outbound.json');
        expect($render['status'])->toBe(0, $render['output']);

        $host = mailGatewayHost();
        $dir = $host['scratch'].'/etc';
        @mkdir($dir, 0o755, true);

        $read = function () use ($host, $dir, $scratch): string {
            $harness = 'source '.escapeshellarg(mailGatewayScript())
                .' && PLAN_FILE='.escapeshellarg($scratch.'/plan.json')
                .' OUTBOUND_FILE='.escapeshellarg($scratch.'/mail-outbound.json')
                .' SIGNING_FILE='.escapeshellarg(mailGatewayPreActivationSigningPlan())
                .' MILTER_ENDPOINT=inet:127.0.0.1:8891'
                .' POSTCONF_BIN='.escapeshellarg($host['scratch'].'/bin/postconf')
                .' POSTMAP_BIN='.escapeshellarg($host['scratch'].'/bin/postmap')
                .' EFFECTIVE_UID=1000'
                .' && postfix_contract_problems '.escapeshellarg($dir);

            $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $host['scratch'], $host['env']);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            return $output;
        };

        try {
            file_put_contents($dir.'/main.cf', $render['main']);
            file_put_contents($dir.'/master.cf', $render['master']);
            foreach ($render['policies'] as $name => $policy) {
                file_put_contents("{$dir}/{$name}", $policy);
            }
            expect($read())->toBe('', 'the untouched render must read back clean');

            $original = $file === 'main' ? $render['main'] : $render['master'];
            expect(substr_count($original, $from))->toBe(1, "the tamper anchor is not unique: {$from}");
            file_put_contents($dir."/{$file}.cf", str_replace($from, $to, $original));

            expect($read())->toContain($problem);
        } finally {
            mailGatewayCleanup($host);
        }
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'a target domain as the HELO name' => [
        'master', '-o smtp_helo_name=mta1.example.net', '-o smtp_helo_name=demo-shop.example',
        'rateguru-outbound-demo-shop greets as "demo-shop.example", not the host MTA identity "mta1.example.net"',
    ],
    'mandatory TLS' => [
        'master', '-o smtp_tls_security_level=may', '-o smtp_tls_security_level=encrypt',
        'rateguru-outbound-demo-shop has smtp_tls_security_level "encrypt", not may',
    ],
    'no TLS at all' => [
        'master', '-o smtp_tls_security_level=may', '-o smtp_tls_security_level=none',
        'rateguru-outbound-demo-shop has smtp_tls_security_level "none", not may',
    ],
    'SMTP AUTH' => [
        'master', "  -o smtp_sasl_auth_enable=no\n  -o smtp_fallback_relay=", "  -o smtp_sasl_auth_enable=yes\n  -o smtp_fallback_relay=",
        'rateguru-outbound-demo-shop has smtp_sasl_auth_enable "yes"',
    ],
    'a fallback relay' => [
        'master', '-o smtp_fallback_relay=', '-o smtp_fallback_relay=[smtp.example.com]:587',
        'rateguru-outbound-demo-shop has a fallback relay "[smtp.example.com]:587"',
    ],
    'a relay host on the filter' => [
        'master', '-o content_filter=rateguru-outbound-demo-shop:', '-o content_filter=rateguru-outbound-demo-shop:[smtp.example.com]:587',
        'demo-shop (127.0.0.1:2599) routes to "rateguru-outbound-demo-shop:[smtp.example.com]:587", not its own direct transport',
    ],
    'another listener naming it' => [
        'master', '-o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025', '-o content_filter=rateguru-outbound-demo-shop:',
        'staging-main (127.0.0.1:2525) routes to "rateguru-outbound-demo-shop:"',
    ],
    'held mail sent outbound' => [
        'master', "  -o smtpd_recipient_restrictions=check_client_access,static:HOLD,permit_mynetworks,reject\n  -o content_filter=\n", "  -o smtpd_recipient_restrictions=check_client_access,static:HOLD,permit_mynetworks,reject\n  -o content_filter=rateguru-outbound-demo-shop:\n",
        'tits-guru (127.0.0.1:2526) is held but names a route: rateguru-outbound-demo-shop:',
    ],
    'a generic smtp client' => [
        'master', "\n# --- Postfix internal services.", "\nsmtp      unix  -       -       n       -       -       smtp\n# --- Postfix internal services.",
        'smtp delivery agents are [rateguru-capture-staging-main rateguru-outbound-demo-shop smtp]',
    ],
    'relay as a working smtp client' => [
        'master', 'relay          unix  -       -       n       -       -       error', 'relay          unix  -       -       n       -       -       smtp',
        'smtp delivery agents are [rateguru-capture-staging-main rateguru-outbound-demo-shop relay]',
    ],
    'a second service of the same name' => [
        'master', "  -o smtp_fallback_relay=\n", "  -o smtp_fallback_relay=\nrateguru-outbound-demo-shop unix  -       -       n       -       -       smtp\n",
        'demo-shop has 2 services named rateguru-outbound-demo-shop, not exactly one',
    ],
    'a relayhost' => [
        'main', "\nrelayhost =\n", "\nrelayhost = [smtp.example.com]:587\n",
        'relayhost is "[smtp.example.com]:587", not empty',
    ],
    'a default filter next hop' => [
        'main', "\ndefault_filter_nexthop =", "\ndefault_filter_nexthop = smtp.example.com",
        'default_filter_nexthop is "smtp.example.com", not empty — an outbound route would deliver there instead of to the recipient domain MX',
    ],
    'a smarthost as the default transport' => [
        'main', "\ndefault_transport = error:", "\ndefault_transport = smtp:[smtp.example.com]:587\n# was: error:",
        'default_transport is "smtp:[smtp.example.com]:587", not the error transport',
    ],
]);
