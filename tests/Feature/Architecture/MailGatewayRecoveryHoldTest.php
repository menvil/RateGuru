<?php

use Illuminate\Support\Facades\File;

/**
 * The recovery-time outbound hold in the host-global mail gateway: on a
 * replacement machine a clean-host recovery prepared, a target whose reviewed
 * mail goes out directly is installed, verified and re-applied HELD while its
 * marker stands — its listener holding every message, no direct route at all —
 * and only the release's authorization crosses to outbound.
 *
 * Every test runs the shipped install-mail-gateway against a simulated host
 * (FS_ROOT plus stubs for the package manager, systemd, ss and Postfix's own
 * tools); its postconf stub reads back what the rendered files say. What a
 * real Postfix does with the same configuration is
 * tests/Support/real-postfix/recovery-outbound-hold.sh's.
 */

/** tits-guru's slice of the installed master.cf: its listener, and a direct route if there is one. */
function mailGatewayTitsGuruServices(array $host): array
{
    $services = collect(mailGatewayMasterServices(File::get($host['fs'].'/etc/postfix/master.cf')))->keyBy('name');

    return [
        'listener' => $services['127.0.0.1:2526'] ?? null,
        'direct' => $services['rateguru-outbound-tits-guru'] ?? null,
    ];
}

it('installs a recovered host with tits-guru held while its recovery hold stands, though its bundle requests outbound delivery', function () {
    $host = mailGatewayRecoveredHost();

    try {
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);

        // The listener holds every message; there is no direct route at all.
        $titsGuru = mailGatewayTitsGuruServices($host);
        expect($titsGuru['direct'])->toBeNull();
        expect($titsGuru['listener']['options']['content_filter'])->toBe('');
        expect($titsGuru['listener']['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');

        // Still signed exactly as it will be once it leaves, and named by the
        // host's reviewed MTA hostname.
        expect($titsGuru['listener']['options']['smtpd_milters'])->toBe('inet:127.0.0.1:8891');
        expect(mailGatewayMainParameters(File::get($host['fs'].'/etc/postfix/main.cf'))['myhostname'])->toBe('mta1.tits.guru');

        // The host records the held state: the plan the bundle requests with
        // exactly tits-guru's mail held, and direct delivery disabled.
        $requested = mailRoutingPlan(mailCommittedPolicy()['routing'], committedRegistryWithLifecycles([]));
        $held = $requested;
        foreach ($held['listeners'] as $index => $listener) {
            if ($listener['identity'] === 'tits-guru') {
                $held['listeners'][$index]['delivery_mode'] = 'held';
                $held['listeners'][$index]['route'] = null;
            }
        }
        expect(json_decode(File::get(mailGatewayApplied($host)), true))->toEqual($held);
        expect(json_decode(File::get(mailGatewayApplied($host, 'applied-outbound.json')), true)['direct'])
            ->toBe(['enabled' => false, 'mta_hostname' => 'mta1.tits.guru']);

        expect($log)->toContain("APPLY    tits-guru's outbound mail stays HELD: a clean-host recovery hold stands on this host");

        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(0, $report);
        expect($report)->toContain("PASS     recovery-hold:tits-guru — tits-guru's outbound mail is HELD on this host by a clean-host recovery (backup 20261020-030000, operation not bound yet)");

        // Exactly what the renderer makes of that held plan, and of nothing else.
        $planFile = $host['scratch'].'/held-plan.json';
        file_put_contents($planFile, json_encode($held, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $outboundFile = $host['scratch'].'/held-outbound.json';
        file_put_contents($outboundFile, json_encode(['schema_version' => 1, 'direct' => ['enabled' => false, 'mta_hostname' => 'mta1.tits.guru']], JSON_THROW_ON_ERROR));
        $render = mailGatewayRenderPlanFile($host['scratch'], $planFile, $outboundFile);
        expect($render['status'])->toBe(0, $render['output']);
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toBe($render['master']);
        expect(File::get($host['fs'].'/etc/postfix/main.cf'))->toBe($render['main']);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses the same replacement host without its recovery hold: a host never records outbound delivery first', function () {
    $host = mailGatewayRecoveredHost(hold: null);

    try {
        $before = mailGatewayMutableState($host);

        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(1, $log);
        expect($log)->toContain("a host's first recorded policy is always the inert one");
        expect(mailGatewayMutableState($host))->toBe($before);
        expect(mailGatewayLog($host, 'mutations.log'))->not->toContain('apt-get install');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('keeps the hold through every later apply, check and verify: a Prepare, a repair or a bootstrap converges to held and releases nothing', function () {
    $host = mailGatewayRecoveredHost();

    try {
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        $held = mailGatewayMutableState($host);
        file_put_contents($host['scratch'].'/log/mutations.log', '');

        foreach (['--check', '--apply', '--verify', '--apply'] as $mode) {
            [$status, $output] = mailGatewayRun($host, $mode);
            expect($status)->toBe(0, "{$mode}:\n{$output}");
        }

        expect(mailGatewayMutableState($host))->toBe($held);
        expect(mailGatewayRecordedMode($host))->toBe('held');
        expect(mailGatewayLog($host, 'mutations.log'))->not->toContain('reload');
        expect(recoveryOutboundHoldRead($host['fs']))->toBe(recoveryOutboundHoldDocument());
    } finally {
        mailGatewayCleanup($host);
    }
});

it('releases nothing when the hold is removed by hand: the next apply is refused at the activation boundary, and verify reports it', function () {
    $host = mailGatewayRecoveredHost();

    try {
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);

        File::deleteDirectory(recoveryOutboundHoldDir($host['fs']));
        $held = mailGatewayMutableState($host);

        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(1, $log);
        expect($log)->toContain("this bundle moves tits-guru's mail from held to outbound — the activation boundary");
        expect(mailGatewayMutableState($host))->toBe($held);
        expect(mailGatewayTitsGuruServices($host)['direct'])->toBeNull();

        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(1, $report);
        expect($report)->toContain('DRIFT    policy:transition');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('crosses a released hold to outbound only with the one-use authorization the release writes', function () {
    $host = mailGatewayRecoveredHost(['operation' => '20261020-120000-abcdef']);

    try {
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        $staging = mailGatewayStagingCapture($host);

        recoveryOutboundHoldWrite($host['fs'], 'tits-guru', [
            'operation' => '20261020-120000-abcdef',
            'state' => 'released',
            'released_at' => '2026-10-21T10:00:00Z',
            'released_address' => '203.0.113.10',
        ]);
        $released = mailGatewayMutableState($host);

        // Released, and still nothing crosses without the authorization.
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(1, $log);
        expect(mailGatewayMutableState($host))->toBe($released);
        expect(mailGatewayRecordedMode($host))->toBe('held');

        mailGatewayAuthorize($host, 'activate', 'tits-guru');
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        expect(mailGatewayRecordedMode($host))->toBe('outbound');
        expect(mailGatewayTitsGuruServices($host)['direct'])->not->toBeNull();
        expect(mailGatewayTitsGuruServices($host)['listener']['options']['content_filter'])->toBe('rateguru-outbound-tits-guru:');
        expect(mailGatewayStagingCapture($host))->toBe($staging);

        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(0, $report);
        expect($report)->toContain("tits-guru's recovery outbound hold was released at 2026-10-21T10:00:00Z for 203.0.113.10");
    } finally {
        mailGatewayCleanup($host);
    }
});

it('never stops a live host\'s outbound mail silently: a hold that appears on a host that delivers outbound is refused at the boundary, and nothing changes', function () {
    $host = mailGatewayRecoveredHost(['operation' => '20261020-120000-abcdef']);

    try {
        // A host whose first policy was established held and was then crossed
        // to outbound — a live production host.
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['state' => 'released', 'operation' => '20261020-120000-abcdef', 'released_at' => '2026-10-21T10:00:00Z', 'released_address' => '203.0.113.10']);
        mailGatewayAuthorize($host, 'activate', 'tits-guru');
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        expect(mailGatewayRecordedMode($host))->toBe('outbound');

        recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['operation' => '20261020-120000-abcdef']);
        $outbound = mailGatewayMutableState($host);

        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(1, $log);
        expect($log)->toContain("this bundle moves tits-guru's mail from outbound to held");
        expect(mailGatewayMutableState($host))->toBe($outbound);
        expect(mailGatewayTitsGuruServices($host)['direct'])->not->toBeNull();
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses every mode on a hold it cannot trust, renders nothing and changes nothing', function (string $case) {
    $host = mailGatewayRecoveredHost();

    try {
        $dir = recoveryOutboundHoldDir($host['fs']);

        match ($case) {
            'readable by others' => chmod("{$dir}/tits-guru.json", 0o644),
            'a symlink' => (static function () use ($dir): void {
                rename("{$dir}/tits-guru.json", "{$dir}/../elsewhere.json");
                symlink("{$dir}/../elsewhere.json", "{$dir}/tits-guru.json");
            })(),
            'another target' => recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['target' => 'staging-main']),
            'a field too many' => recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['released_by' => 'someone']),
            'a state that is not one' => recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['state' => 'open']),
            'a release without its record' => recoveryOutboundHoldWrite($host['fs'], 'tits-guru', ['state' => 'released']),
            'not JSON' => recoveryOutboundHoldWrite($host['fs'], 'tits-guru', "held\n"),
            'a stranger in the directory' => file_put_contents("{$dir}/notes.txt", "release on monday\n"),
            'an open directory' => chmod($dir, 0o755),
        };

        $before = mailGatewayMutableState($host);

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$status, $output] = mailGatewayRun($host, $mode);
            expect($status)->not->toBe(0, "{$mode}:\n{$output}");
            expect($output)->toContain('a recovery outbound hold on this host cannot be trusted');
            expect(mailGatewayMutableState($host))->toBe($before);
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'readable by others',
    'a symlink',
    'another target',
    'a field too many',
    'a state that is not one',
    'a release without its record',
    'not JSON',
    'a stranger in the directory',
    'an open directory',
]);

it('changes nothing for a target whose mail does not go out directly: a hold over staging leaves its capture route as it is', function () {
    $host = mailGatewayEstablishedHost();

    try {
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        $established = mailGatewayMutableState($host);

        recoveryOutboundHoldWrite($host['fs'], 'staging-main');

        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        expect(array_diff_key(mailGatewayMutableState($host), ['var/lib/rateguru-mail-gateway/recovery-outbound-holds/staging-main.json' => true]))
            ->toBe($established);
        expect(mailGatewayStagingCapture($host)[0]['options']['content_filter'])->toBe('rateguru-capture-staging-main:[127.0.0.1]:1025');
    } finally {
        mailGatewayCleanup($host);
    }
});
