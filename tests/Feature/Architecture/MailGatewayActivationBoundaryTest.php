<?php

use Illuminate\Support\Facades\File;

/**
 * The boundary between held and outbound delivery in the host-global mail
 * gateway: crossed only with exactly one valid authorization, which Prepare
 * never has.
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

/** The gateway files and the recorded policy, for proving nothing changed. */
function mailGatewayMutableState(array $host): array
{
    return array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
}

/** The tits-guru delivery mode the host's recorded policy says. */
function mailGatewayRecordedMode(array $host): string
{
    return collect(json_decode(File::get(mailGatewayApplied($host)), true)['listeners'])->firstWhere('identity', 'tits-guru')['delivery_mode'];
}

/** An established held host, activated to outbound through a valid authorization. */
function mailGatewayActivatedHost(): array
{
    $host = mailGatewayEstablishedHost();
    mailGatewayRequest($host, mailActivationRequest());
    mailGatewayAuthorize($host, 'activate', 'tits-guru');

    [$status, $log] = mailGatewayRun($host, '--apply');
    expect($status)->toBe(0, $log);
    expect(mailGatewayRecordedMode($host))->toBe('outbound');

    return $host;
}

/**
 * staging-main's slice of the installed master.cf: its listener and its
 * capture transport, exactly as master.cf holds them.
 *
 * @return list<array<string, mixed>>
 */
function mailGatewayStagingCapture(array $host): array
{
    $services = collect(mailGatewayMasterServices(File::get($host['fs'].'/etc/postfix/master.cf')))->keyBy('name');

    return [$services['127.0.0.1:2525'], $services['rateguru-capture-staging-main']];
}

// =============================================================================
// THE ACTIVATION BOUNDARY: ONLY activate-mail-outbound CROSSES IT
// =============================================================================

it('takes the committed activation request across a held host only through the guarded activation, and never moves staging\'s capture', function () {
    $host = mailGatewayEstablishedHost();

    try {
        $staging = mailGatewayStagingCapture($host);
        expect($staging[0]['options']['content_filter'])->toBe('rateguru-capture-staging-main:[127.0.0.1]:1025');

        // Nothing real runs here: every tool the installer calls is a stub in
        // the scratch host, so no test can put a message on a network.
        foreach ($host['env'] as $variable => $value) {
            if (str_ends_with($variable, '_BIN')) {
                expect($value)->toStartWith($host['scratch'], "{$variable} is not the scratch host's");
            }
        }

        // The pre-activation host meets the committed request.
        mailGatewayRequest($host, mailCommittedPolicy());
        $held = mailGatewayMutableState($host);

        // A: an ordinary apply refuses the crossing, and changes nothing.
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(1, $log);
        expect(mailGatewayMutableState($host))->toBe($held);
        expect(mailGatewayRecordedMode($host))->toBe('held');

        // E: Verify, read-only, reports the difference, and changes nothing.
        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(1, $report);
        expect($report)->toContain('DRIFT    policy:transition');
        expect(mailGatewayMutableState($host))->toBe($held);
        expect(mailGatewayStagingCapture($host))->toBe($staging);

        // B: the guarded activation crosses, records exactly the committed
        // request, and turns tits-guru's direct route on.
        mailGatewayAuthorize($host, 'activate', 'tits-guru');
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        expect(json_decode(File::get(mailGatewayApplied($host)), true))->toEqual(mailRoutingPlan());
        expect(json_decode(File::get(mailGatewayApplied($host, 'applied-outbound.json')), true))->toEqual(mailCommittedPolicy()['outbound']);
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toContain('-o content_filter=rateguru-outbound-tits-guru:');
        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(0, $report);
        expect(mailGatewayStagingCapture($host))->toBe($staging);
        $activated = mailGatewayMutableState($host);

        // C: a stale Prepare with the held documents refuses, and the direct
        // route keeps working.
        mailGatewayRequest($host, mailPreActivationPolicy());
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(1, $log);
        expect(mailGatewayMutableState($host))->toBe($activated);
        expect(mailGatewayRecordedMode($host))->toBe('outbound');
        expect(mailGatewayStagingCapture($host))->toBe($staging);

        // D: the guarded rollback returns the runtime to held.
        mailGatewayAuthorize($host, 'rollback', 'tits-guru');
        [$status, $log] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $log);
        expect(mailGatewayRecordedMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');
        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(0, $report);
        expect(mailGatewayStagingCapture($host))->toBe($staging);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses Prepare with the activation request before Activate ran: the host stays held', function () {
    // Sequence 1 on the shared host: the committed policy requests the
    // activation — merged into develop, promoted to main — and a host
    // preparation, not Activate, applies the gateway from it.
    $host = mailGatewayEstablishedHost();

    try {
        mailGatewayRequest($host, mailActivationRequest());
        expect(mailActivationRequest())->toEqual(mailCommittedPolicy());
        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayMutableState($host);

        // Verify, read-only, reports the difference before the activation, and
        // changes nothing.
        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(1, $report);
        expect($report)->toContain('DRIFT    policy:transition — the host\'s recorded policy and this bundle differ across the activation boundary (tits-guru from held to outbound) — only activate-mail-outbound crosses it');
        expect(mailGatewayMutableState($host))->toBe($before);

        [$checked, $report] = mailGatewayRun($host, '--check');
        expect($checked)->toBe(1, $report);
        expect($report)->toContain('CONFLICT policy:transition — this bundle moves tits-guru from held to outbound — the activation boundary, which only activate-mail-outbound crosses; ordinary --apply refuses it (no transition authorization is pending)');

        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(1, $log);
        expect($log)->toContain("this bundle moves tits-guru's mail from held to outbound — the activation boundary, which only activate-mail-outbound crosses, and ordinary install-mail-gateway --apply never does (no transition authorization is pending). Nothing was changed");

        // Still held: not a file, not a reload.
        expect(mailGatewayMutableState($host))->toBe($before);
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayRecordedMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses Prepare with the stale held configuration after Activate ran: the host keeps delivering', function () {
    // Sequence 2 on the shared host: production was activated, and a host
    // preparation from a bundle that still holds tits-guru — the pre-activation
    // documents — applies the gateway.
    $host = mailGatewayActivatedHost();

    try {
        mailGatewayRequest($host, mailPreActivationPolicy());
        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayMutableState($host);

        [$checked, $report] = mailGatewayRun($host, '--check');
        expect($checked)->toBe(1, $report);
        expect($report)->toContain('CONFLICT policy:transition — this bundle moves tits-guru from outbound to held — the activation boundary, which only activate-mail-outbound crosses');

        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(1, $log);
        expect($log)->toContain("this bundle moves tits-guru's mail from outbound to held — the activation boundary, which only activate-mail-outbound crosses, and ordinary install-mail-gateway --apply never does");

        expect(mailGatewayMutableState($host))->toBe($before);
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayRecordedMode($host))->toBe('outbound');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toContain('rateguru-outbound-tits-guru');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('crosses the boundary with exactly one valid authorization, consumes it, and crosses back only with another', function () {
    $host = mailGatewayEstablishedHost();

    try {
        mailGatewayRequest($host, mailActivationRequest());
        $authorization = mailGatewayAuthorize($host, 'activate', 'tits-guru');
        $path = $host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json';
        $bytes = File::get($path);

        // --check sees it as authorized and consumes nothing.
        [$checked, $report] = mailGatewayRun($host, '--check');
        expect($checked)->toBe(0, $report);
        expect($report)->toContain('PASS     policy:transition — this bundle moves tits-guru from held to outbound, authorized by activate-mail-outbound for exactly this recorded and requested policy (one use)');
        expect(File::get($path))->toBe($bytes);

        // D: the activation, and the authorization used up.
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);
        expect(file_exists($path))->toBeFalse();
        $ledger = File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations');
        expect($ledger)->toStartWith(hash('sha256', $bytes).' '.$authorization['nonce'].' activate tits-guru ');
        expect(mailGatewayRecordedMode($host))->toBe('outbound');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toContain('rateguru-outbound-tits-guru');
        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(0, $report);

        // I: back to held with no authorization — refused, still outbound.
        mailGatewayRequest($host, mailPreActivationPolicy());
        [$refused, $log] = mailGatewayRun($host, '--apply');
        expect($refused)->toBe(1, $log);
        expect(mailGatewayRecordedMode($host))->toBe('outbound');

        // J: with a rollback authorization — held again, and it is used up.
        mailGatewayAuthorize($host, 'rollback', 'tits-guru');
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);
        expect($log)->toContain('the rollback of tits-guru is authorized by activate-mail-outbound');
        expect(file_exists($path))->toBeFalse();
        expect(mailGatewayRecordedMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');

        // H: the first authorization, put back word for word now that the host
        // is in its FROM state again — refused, it was used.
        mailGatewayRequest($host, mailActivationRequest());
        file_put_contents($path, $bytes);
        chmod($path, 0o600);
        [$reused, $log] = mailGatewayRun($host, '--apply');
        expect($reused)->toBe(1, $log);
        expect($log)->toContain('this authorization was already used — one is consumed by the transition it permits, and never honoured twice');
        expect(mailGatewayRecordedMode($host))->toBe('held');

        // K: and with none at all, the outbound request stays refused.
        unlink($path);
        [$refused, $log] = mailGatewayRun($host, '--apply');
        expect($refused)->toBe(1, $log);
        expect($log)->toContain('no transition authorization is pending');
        expect(mailGatewayRecordedMode($host))->toBe('held');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses an authorization that does not permit exactly this transition, and keeps the host as it was', function (string $case, string $problem) {
    $host = mailGatewayEstablishedHost();

    try {
        mailGatewayRequest($host, mailActivationRequest());
        $path = $host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json';
        $now = time();

        match ($case) {
            'wrong target' => mailGatewayAuthorize($host, 'activate', 'staging-main'),
            'wrong direction' => mailGatewayAuthorize($host, 'rollback', 'tits-guru'),
            'wrong FROM' => mailGatewayAuthorize($host, 'activate', 'tits-guru', ['from' => ['plan' => str_repeat('0', 64)]]),
            'wrong TO' => mailGatewayAuthorize($host, 'activate', 'tits-guru', ['to' => ['outbound' => str_repeat('0', 64)]]),
            'another kind' => mailGatewayAuthorize($host, 'activate', 'tits-guru', ['kind' => 'something-else']),
            'no nonce' => mailGatewayAuthorize($host, 'activate', 'tits-guru', ['nonce' => 'abc']),
            'expired' => mailGatewayAuthorize($host, 'activate', 'tits-guru', ['created_at' => $now - 7200, 'expires_at' => $now - 3600]),
            'too long a lifetime' => mailGatewayAuthorize($host, 'activate', 'tits-guru', ['expires_at' => $now + 86400]),
            'readable by others' => [mailGatewayAuthorize($host, 'activate', 'tits-guru'), chmod($path, 0o644)],
            'a symlink' => [mailGatewayAuthorize($host, 'activate', 'tits-guru'), rename($path, $path.'.real'), symlink($path.'.real', $path)],
            'not JSON' => [mailGatewayAuthorize($host, 'activate', 'tits-guru'), file_put_contents($path, "not json\n"), chmod($path, 0o600)],
        };

        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayMutableState($host);

        [$applied, $log] = mailGatewayRun($host, '--apply');

        expect($applied)->toBe(1, $log);
        expect($log)->toContain('only activate-mail-outbound crosses')->toContain($problem)->toContain('Nothing was changed');
        expect(mailGatewayMutableState($host))->toBe($before);
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayRecordedMode($host))->toBe('held');
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'wrong target' => ['wrong target', 'it authorizes target "staging-main", and this transition is for tits-guru'],
    'wrong direction' => ['wrong direction', 'it authorizes "rollback", and this transition is activate'],
    'wrong FROM' => ['wrong FROM', 'it was issued for another recorded policy than the one this host recorded'],
    'wrong TO' => ['wrong TO', 'it was issued for another policy than the one this bundle requests'],
    'another kind' => ['another kind', 'it is not a mail gateway transition authorization of schema 1'],
    'no nonce' => ['no nonce', 'it carries no nonce'],
    'expired' => ['expired', 'it has expired, or claims a lifetime no authorization has'],
    'too long a lifetime' => ['too long a lifetime', 'it has expired, or claims a lifetime no authorization has'],
    'readable by others' => ['readable by others', 'mode 600 — an authorization anyone else could have written is never honoured'],
    'a symlink' => ['a symlink', '/var/lib/rateguru-mail-gateway/transition-authorization.json is not a regular file'],
    'not JSON' => ['not JSON', 'it is not a JSON document'],
]);

it('refuses any change of outbound delivery no single guarded activation makes', function (string $case) {
    $host = mailGatewayEstablishedHost();

    try {
        $request = mailPreActivationPolicy();

        match ($case) {
            // Direct delivery switched on with no target crossing.
            'direct delivery alone' => $request['outbound']['direct']['enabled'] = true,
            // A target crossing while direct delivery stays off.
            'a target without direct delivery' => [$request['routing']['targets']['tits-guru']['delivery_mode'] = 'outbound', $request['routing']['targets']['tits-guru']['outbound'] = ['kind' => 'direct']],
        };

        mailGatewayRequest($host, $request);
        $before = mailGatewayMutableState($host);

        [$applied, $log] = mailGatewayRun($host, '--apply');

        expect($applied)->toBe(1, $log);
        expect(mailGatewayMutableState($host))->toBe($before);
        expect(mailGatewayRecordedMode($host))->toBe('held');
    } finally {
        mailGatewayCleanup($host);
    }
})->with(['direct delivery alone', 'a target without direct delivery']);

it('reports the policy digests an authorization is bound to, from the one place that canonicalizes them', function () {
    $host = mailGatewayEstablishedHost();

    try {
        $digest = mailGatewayPolicyDigest($host);

        expect($digest['recorded'])->toBe([
            'plan' => hash_file('sha256', mailGatewayApplied($host)),
            'outbound' => hash_file('sha256', mailGatewayApplied($host, 'applied-outbound.json')),
        ]);
        expect($digest['requested'])->toBe($digest['recorded']);
        expect($digest['authorization'])->toBe($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json');

        mailGatewayRequest($host, mailActivationRequest());
        $requested = mailGatewayPolicyDigest($host)['requested'];
        expect($requested['plan'])->not->toBe($digest['recorded']['plan']);
        expect($requested['outbound'])->not->toBe($digest['recorded']['outbound']);

        unlink(mailGatewayApplied($host));
        expect(mailGatewayPolicyDigest($host)['recorded'])->toBeNull();
    } finally {
        mailGatewayCleanup($host);
    }
});

it('has no flag, variable or mode that crosses the boundary without an authorization', function () {
    $source = File::get(mailGatewayScript());
    $code = executableSourceLines($source);

    // Four modes and help, and nothing else is an argument.
    $parser = executableSourceLines(shellFunctionBody($source, 'parse_args'));
    expect($parser)->toContain('--check|--apply|--verify|--policy-digest)')->toContain('-h|--help)');
    preg_match_all('/^\s+(-[-a-z|]+)\)/m', $parser, $arguments);
    expect($arguments[1])->toBe(['--check|--apply|--verify|--policy-digest', '-h|--help']);

    foreach (['--allow-outbound', 'SKIP_ACTIVATION', 'ACTIVATION_GUARD', 'BYPASS', 'RATEGURU_MAILGW_AUTHORIZ', 'RATEGURU_MAILGW_TRANSITION', 'RATEGURU_MAILGW_APPLIED', 'RATEGURU_MAILGW_STATE'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("install-mail-gateway carries {$forbidden}");
    }

    // The boundary is judged in --apply before anything changes, and the one
    // way across consumes the authorization first.
    $apply = executableSourceLines(shellFunctionBody($source, 'perform_apply'));
    expect(strpos($apply, 'verdict="$(boundary_verdict)"'))->toBeLessThan(strpos($apply, 'BACKUP_DIR='));
    expect(strpos($apply, 'consume_authorization "${kind}" "${target}"'))->toBeLessThan(strpos($apply, 'BACKUP_DIR='));
    expect(strpos($apply, 'install_applied_policy'))->toBeGreaterThan(strpos($apply, 'problems="$(runtime_problems)"'));
});
