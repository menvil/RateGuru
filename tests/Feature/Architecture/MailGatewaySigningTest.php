<?php

use Illuminate\Support\Facades\File;

/**
 * The host-global mail gateway's signing: the signer endpoint each signed
 * listener is wired to, read back through Postfix, and the From policy that
 * admits only the reviewed domain.
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
 * Postfix's read-back of a configuration directory, through the shipped
 * postfix_contract_problems and the simulated postconf, with PLAN and the
 * pre-activation host contract and signing plan.
 */
function mailGatewayContractProblems(array $host, string $dir, string $plan, ?string $signing = null): string
{
    $harness = 'source '.escapeshellarg(mailGatewayScript())
        .' && PLAN_FILE='.escapeshellarg($plan)
        .' OUTBOUND_FILE='.escapeshellarg(mailGatewayPreActivationOutboundFile())
        .' SIGNING_FILE='.escapeshellarg($signing ?? mailGatewayPreActivationSigningPlan())
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
}

/**
 * What a From policy answers for HEADER, read the way Postfix reads a regexp
 * table — the first matching pattern's result, case-insensitive unless the
 * pattern carries the i flag — but through PCRE, a witness independent of the
 * renderer and of the postmap test double.
 */
function mailGatewayFromPolicyVerdict(string $policy, string $header): ?string
{
    foreach (preg_split('/\R/', $policy) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        expect(preg_match('#^/(.*)/([a-z]*) (.+)$#', $line, $matches))->toBe(1, "not a regexp table line: {$line}");
        $flags = str_contains($matches[2], 'i') ? 'D' : 'Di';

        if (preg_match('/'.str_replace('/', '\/', $matches[1]).'/'.$flags, $header) === 1) {
            return $matches[3];
        }
    }

    return null;
}

// =============================================================================
// SIGNING: ONLY THE SIGNED LISTENERS, AND NEVER UNSIGNED
// =============================================================================

it('wires a signed listener to whatever endpoint the signer\'s installer names, and spells none itself', function () {
    $render = mailGatewayRender(milter: 'inet:127.0.0.1:18891');
    $titsGuru = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', '127.0.0.1:2526');

    expect($titsGuru['options']['smtpd_milters'])->toBe('inet:127.0.0.1:18891');

    // The endpoint reaches the gateway only from install-mail-signing, in the
    // same bundle: the installer names no milter port of its own.
    $installer = executableSourceLines(File::get(mailGatewayScript()));
    expect($installer)
        ->toContain('MAIL_SIGNING_CLI="$(gated_default RATEGURU_MAILGW_MAIL_SIGNING_CLI "${SCRIPT_DIR}/install-mail-signing")"')
        ->toContain('MILTER_ENDPOINT="$("${MAIL_SIGNING_CLI}" --milter-endpoint)"')
        ->toContain('"${MAIL_IDENTITY_CLI}" render-signing-plan')
        ->not->toContain('8891');
    expect(trim((string) shell_exec('bash '.escapeshellarg(mailGatewayScript('install-mail-signing')).' --milter-endpoint')))->toBe('inet:127.0.0.1:8891');
});

it('refuses a signer endpoint that is not on loopback', function (string $endpoint) {
    $scratch = mailGatewayScratch();

    try {
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson(mailPreActivationPolicy()['routing']));
        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', null, null, $endpoint);

        expect($render['status'])->not->toBe(0);
        expect($render['output'])->toContain("the signer's milter endpoint is \"{$endpoint}\", not a loopback inet endpoint");
        expect($render['master'])->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with(['inet:0.0.0.0:8891', 'inet:10.0.0.5:8891', 'inet:[::1]:8891', 'unix:/run/opendkim/opendkim.sock', 'inet:127.0.0.1:8891 -o x=y', '']);

it('signs exactly the listeners of the targets in the signing plan, held or outbound, and never a capture listener', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $registry = mailRoutingDemoShopRegistry();
    $signing = mailGatewaySigningPlanFor($policy, $registry, mailGatewayOutboundContract(), mailGatewayIdentityWithDemoShop());

    $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract(), $signing);
    $services = collect(mailGatewayMasterServices($render['master']))->where('type', 'inet');

    foreach ($services as $service) {
        $signed = in_array($service['name'], ['127.0.0.1:2526', '127.0.0.1:2599'], true);
        expect(array_key_exists('smtpd_milters', $service['options']))->toBe($signed, "{$service['name']} signing");

        if ($signed) {
            expect([$service['options']['smtpd_milters'], $service['options']['milter_protocol'], $service['options']['milter_default_action']])
                ->toBe(['inet:127.0.0.1:8891', '6', 'tempfail']);
        }
    }

    // Signing routes nothing: the held listener still holds, with no filter,
    // and the outbound one still delivers through its own client only.
    $held = $services->firstWhere('name', '127.0.0.1:2526');
    expect($held['options']['content_filter'])->toBe('');
    expect($held['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
    expect($services->firstWhere('name', '127.0.0.1:2599')['options']['content_filter'])->toBe('rateguru-outbound-demo-shop:');
});

it('reads the signing wiring back through Postfix, and refuses every way it could be weakened', function (string $file, string $from, string $to, string $problem) {
    $host = mailGatewayHost();

    try {
        $render = mailGatewayRender();
        $dir = $host['scratch'].'/etc';
        @mkdir($dir, 0o755, true);
        file_put_contents($host['scratch'].'/plan.json', mailRoutingPlanJson(mailPreActivationPolicy()['routing']));

        file_put_contents($dir.'/main.cf', $render['main']);
        file_put_contents($dir.'/master.cf', $render['master']);
        foreach ($render['policies'] as $name => $policy) {
            file_put_contents("{$dir}/{$name}", $policy);
        }
        expect(mailGatewayContractProblems($host, $dir, $host['scratch'].'/plan.json'))->toBe('', 'the untouched render must read back clean');

        [$original, $path] = match ($file) {
            'main' => [$render['main'], $dir.'/main.cf'],
            'master' => [$render['master'], $dir.'/master.cf'],
            'policy' => [$render['policies']['rateguru-from-tits-guru.regexp'], $dir.'/rateguru-from-tits-guru.regexp'],
        };
        expect(substr_count($original, $from))->toBe(1, "the tamper anchor is not unique: {$from}");
        file_put_contents($path, str_replace($from, $to, $original));

        expect(mailGatewayContractProblems($host, $dir, $host['scratch'].'/plan.json'))->toContain($problem);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'the milter removed' => ['master', "  -o smtpd_milters=inet:127.0.0.1:8891\n", '', 'tits-guru (127.0.0.1:2526) is signed but hands its mail to "", not the signer at inet:127.0.0.1:8891'],
    'another milter' => ['master', 'smtpd_milters=inet:127.0.0.1:8891', 'smtpd_milters=inet:127.0.0.1:9999', 'hands its mail to "inet:127.0.0.1:9999", not the signer'],
    'unsigned mail accepted when the signer fails' => ['master', 'milter_default_action=tempfail', 'milter_default_action=accept', 'has milter_default_action "accept", not tempfail'],
    'no failure policy at all' => ['master', "  -o milter_default_action=tempfail\n", '', 'has milter_default_action "", not tempfail'],
    'another milter protocol' => ['master', 'milter_protocol=6', 'milter_protocol=2', 'has milter_protocol "2", not 6'],
    'the capture listener signed too' => ['master', "  -o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025\n", "  -o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025\n  -o smtpd_milters=inet:127.0.0.1:8891\n", 'staging-main (127.0.0.1:2525) is not signed but hands its mail to the milter "inet:127.0.0.1:8891"'],
    'a global milter' => ['main', "disable_vrfy_command = yes\n", "disable_vrfy_command = yes\nsmtpd_milters = inet:127.0.0.1:8891\n", 'smtpd_milters is "inet:127.0.0.1:8891", not empty — the signer is wired per listener, never globally'],
    'locally submitted mail signed' => ['main', "disable_vrfy_command = yes\n", "disable_vrfy_command = yes\nnon_smtpd_milters = inet:127.0.0.1:8891\n", 'non_smtpd_milters is "inet:127.0.0.1:8891", not empty'],
    'the cleanup service missing' => ['master', "rateguru-cleanup-tits-guru unix  n       -       n       -       0       cleanup\n  -o syslog_name=postfix/rateguru-cleanup-tits-guru\n  -o header_checks=regexp:/etc/postfix/rateguru-from-tits-guru.regexp\n  -o nested_header_checks=\n  -o always_add_missing_headers=yes\n", '', 'tits-guru has 0 cleanup services named rateguru-cleanup-tits-guru, not exactly one'],
    'the listener on the shared cleanup' => ['master', '  -o cleanup_service_name=rateguru-cleanup-tits-guru', '  -o cleanup_service_name=cleanup', 'tits-guru (127.0.0.1:2526) is signed but uses the cleanup service "cleanup", not its own rateguru-cleanup-tits-guru'],
    'another From policy file' => ['master', 'header_checks=regexp:/etc/postfix/rateguru-from-tits-guru.regexp', 'header_checks=regexp:/etc/postfix/other.regexp', 'rateguru-cleanup-tits-guru has header_checks "regexp:/etc/postfix/other.regexp", not its own From policy'],
    'no missing From added' => ['master', '  -o always_add_missing_headers=yes', '  -o always_add_missing_headers=no', 'has always_add_missing_headers "no", not yes'],
    'header checks on attached messages' => ['master', '  -o nested_header_checks=', '  -o nested_header_checks=regexp:/etc/postfix/rateguru-from-tits-guru.regexp', 'has nested_header_checks "regexp:/etc/postfix/rateguru-from-tits-guru.regexp", not empty'],
    'staging given the production cleanup' => ['master', "  -o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025\n", "  -o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025\n  -o cleanup_service_name=rateguru-cleanup-tits-guru\n", 'staging-main (127.0.0.1:2525) is not signed but uses the cleanup service "rateguru-cleanup-tits-guru"'],
    'a global header check' => ['main', "disable_vrfy_command = yes\n", "disable_vrfy_command = yes\nheader_checks = regexp:/etc/postfix/rateguru-from-tits-guru.regexp\n", 'header_checks is "regexp:/etc/postfix/rateguru-from-tits-guru.regexp", not empty'],
    'an empty sender on the signed listener' => ['main', 'inline:{ tits.guru=OK }', 'inline:{ tits.guru=OK, <>=OK }', 'tits-guru (127.0.0.1:2526) is signed but admits an empty sender'],
    'a policy admitting any From' => ['policy', '/^From:/ REJECT', '/^From:/ DUNNO', 'tits-guru From policy admits "From: intruder@foreign.invalid"'],
    'a policy admitting another domain' => ['policy', "\n/^From:/ REJECT", "\n/^From:[[:space:]]*[[:alnum:]._%+-]+@foreign\\.invalid[[:space:]]*$/ DUNNO\n/^From:/ REJECT", 'tits-guru From policy admits "From: intruder@foreign.invalid" (DUNNO)'],
    'a policy matching a substring' => ['policy', '/^From:[[:space:]]*[[:alnum:]._%+-]+@tits\\.guru[[:space:]]*$/ DUNNO', '/@tits\\.guru/ DUNNO', 'tits-guru From policy admits "From: noreply@tits.guru, intruder@foreign.invalid"'],
]);

it('gives each signed target its own cleanup service and From policy, from the domain the plan reviews', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $registry = mailRoutingDemoShopRegistry();
    $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract(), mailGatewaySigningPlanFor($policy, $registry, mailGatewayOutboundContract(), mailGatewayIdentityWithDemoShop()));
    $services = collect(mailGatewayMasterServices($render['master']));

    foreach (['tits-guru' => ['127.0.0.1:2526', 'tits.guru'], 'demo-shop' => ['127.0.0.1:2599', 'demo-shop.example']] as $target => [$endpoint, $domain]) {
        expect($services->firstWhere('name', $endpoint)['options']['cleanup_service_name'])->toBe("rateguru-cleanup-{$target}");
        expect($services->firstWhere('name', "rateguru-cleanup-{$target}")['options']['header_checks'])->toBe("regexp:/etc/postfix/rateguru-from-{$target}.regexp");

        $file = $render['policies']["rateguru-from-{$target}.regexp"];
        expect($file)->toStartWith("# RateGuru mail gateway — From policy for {$target}\n");
        expect(mailGatewayFromPolicyVerdict($file, "From: noreply@{$domain}"))->toBe('DUNNO');
    }

    // Each policy is its own target's: neither admits the other's domain.
    expect(mailGatewayFromPolicyVerdict($render['policies']['rateguru-from-tits-guru.regexp'], 'From: hello@demo-shop.example'))->toStartWith('REJECT');
    expect(mailGatewayFromPolicyVerdict($render['policies']['rateguru-from-demo-shop.regexp'], 'From: noreply@tits.guru'))->toStartWith('REJECT');

    // The staging capture listener has none of it.
    $staging = $services->firstWhere('name', '127.0.0.1:2525');
    expect($staging['options'])->not->toHaveKey('cleanup_service_name')->not->toHaveKey('smtpd_milters');
    expect(array_keys($render['policies']))->toBe(['rateguru-from-demo-shop.regexp', 'rateguru-from-tits-guru.regexp']);
    expect($services->pluck('name')->filter(static fn (string $name): bool => str_starts_with($name, 'rateguru-cleanup-'))->values()->all())
        ->toEqualCanonicalizing(['rateguru-cleanup-tits-guru', 'rateguru-cleanup-demo-shop']);
});

it('admits exactly one From address in the reviewed domain, and refuses every other From', function (string $header, bool $admitted) {
    $policy = mailGatewayRender()['policies']['rateguru-from-tits-guru.regexp'];
    $verdict = mailGatewayFromPolicyVerdict($policy, $header);

    if ($admitted) {
        expect($verdict)->toBe('DUNNO', "{$header} must pass");
    } else {
        expect($verdict)->toBe('REJECT 5.7.1 RateGuru mail gateway: the From header must be exactly one address in the reviewed sender domain', "{$header} must be refused");
    }
})->with([
    'a bare address' => ['From: noreply@tits.guru', true],
    'an angle address' => ['From: <noreply@tits.guru>', true],
    'a display name' => ['From: RateGuru <noreply@tits.guru>', true],
    'a quoted display name' => ['From: "RateGuru" <noreply@tits.guru>', true],
    'the canary\'s display name' => ['From: TitsGuru <noreply@tits.guru>', true],
    'the canary\'s display name before another domain' => ['From: TitsGuru <noreply@example.net>', false],
    'the canary\'s display name beside a foreign mailbox' => ['From: TitsGuru <noreply@tits.guru>, Intruder <intruder@example.net>', false],
    'an encoded-word display name' => ['From: =?utf-8?Q?Rate_Guru?= <noreply@tits.guru>', true],
    'the domain in capitals, signed all the same' => ['From: noreply@TITS.GURU', true],
    'another domain' => ['From: intruder@example.net', false],
    'another domain with a name' => ['From: Intruder <intruder@example.net>', false],
    'a list ending in another domain' => ['From: noreply@tits.guru, intruder@example.net', false],
    'a list starting in another domain' => ['From: intruder@example.net, noreply@tits.guru', false],
    'two named mailboxes, the foreign one first' => ['From: Intruder <intruder@example.net>, RateGuru <noreply@tits.guru>', false],
    'two named mailboxes, the foreign one last' => ['From: RateGuru <noreply@tits.guru>, Intruder <intruder@example.net>', false],
    'a subdomain' => ['From: user@mail.tits.guru', false],
    'a longer name ending elsewhere' => ['From: user@tits.guru.attacker.example', false],
    'a name that only ends in it' => ['From: user@eviltits.guru', false],
    'an address hidden in the display name' => ['From: "a <x@evil.example>" <noreply@tits.guru>', false],
    'a group' => ['From: undisclosed-recipients:;', false],
    'an empty From' => ['From: ', false],
    'a comment' => ['From: noreply@tits.guru (hidden@evil.example)', false],
]);

it('renders no From policy where nothing is signed, and spells no domain itself', function () {
    expect(mailGatewayRender(signing: ['schema_version' => 1, 'targets' => []])['policies'])->toBe([]);

    $code = executableSourceLines(File::get(mailGatewayScript()));
    expect($code)
        ->toContain('.sender.allowed_domain as $domain')
        ->not->toContain('tits')
        ->not->toContain('header_checks=regexp:/etc/postfix/rateguru-from-tits');
    foreach (['FILTER', 'REDIRECT', 'PREPEND', 'REPLACE'] as $action) {
        expect(preg_match('#/ '.$action.'\b#', $code))->toBe(0, "the From policy renderer uses {$action}");
    }
});

it('refuses to write a From policy for a domain it cannot spell as a plain pattern', function () {
    $scratch = mailGatewayScratch();

    try {
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson(mailPreActivationPolicy()['routing']));
        $plan = json_decode((string) file_get_contents($scratch.'/plan.json'), true);
        $plan['listeners'][1]['sender']['allowed_domain'] = 'tits.guru|x';
        file_put_contents($scratch.'/plan.json', json_encode($plan));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json');
        expect($render['status'])->not->toBe(0);
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('installs, repairs and retires the From policies in its transaction, and never touches a file it did not write', function () {
    $host = mailGatewayHost();

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        $policy = $host['fs'].'/etc/postfix/rateguru-from-tits-guru.regexp';
        expect(File::get($policy))->toBe(mailGatewayRender()['policies']['rateguru-from-tits-guru.regexp']);
        expect(substr(sprintf('%o', fileperms($policy)), -3))->toBe('644');

        // Weakened by hand: drift, and Postfix's own lookup says it admits a
        // foreign From; --apply puts the render back.
        file_put_contents($policy, str_replace('/^From:/ REJECT', '/^From:/ DUNNO', File::get($policy)));
        [$weakened, $report] = mailGatewayRun($host, '--verify');
        expect($weakened)->not->toBe(0);
        expect($report)
            ->toContain('DRIFT    file:/etc/postfix/rateguru-from-tits-guru.regexp — differs from the current render')
            ->toContain('tits-guru From policy admits "From: intruder@foreign.invalid"');

        [$repaired, $log] = mailGatewayRun($host, '--apply');
        expect($repaired)->toBe(0, $log);
        expect($log)->toContain('installing /etc/postfix/rateguru-from-tits-guru.regexp');
        expect(File::get($policy))->toBe(mailGatewayRender()['policies']['rateguru-from-tits-guru.regexp']);

        // The policy of a target no longer signed: drift, removed by the next
        // apply with a backup, and nothing else.
        $stale = $host['fs'].'/etc/postfix/rateguru-from-old-target.regexp';
        file_put_contents($stale, "# RateGuru mail gateway — From policy for old-target\n/^From:/ DUNNO\n");
        file_put_contents($host['fs'].'/etc/postfix/sender_access', "somebody's own map\n");

        [$check, $report] = mailGatewayRun($host, '--verify');
        expect($check)->not->toBe(0);
        expect($report)->toContain('DRIFT    file:/etc/postfix/rateguru-from-old-target.regexp — the From policy of a target that is no longer signed — --apply removes it');

        [$retired, $log] = mailGatewayRun($host, '--apply');
        expect($retired)->toBe(0, $log);
        expect($log)->toContain('removing /etc/postfix/rateguru-from-old-target.regexp');
        expect(file_exists($stale))->toBeFalse();
        expect(glob($host['fs'].'/var/backups/rateguru-mail-gateway/*/etc/postfix/rateguru-from-old-target.regexp') ?: [])->toHaveCount(1);

        // Named like a policy, but not written by this installer: refused in
        // every mode, never used or removed.
        $foreign = $host['fs'].'/etc/postfix/rateguru-from-other-one.regexp';
        file_put_contents($foreign, "somebody else's\n");
        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayTree($host);

        [$conflicted, $report] = mailGatewayRun($host, '--check');
        expect($conflicted)->not->toBe(0);
        expect($report)->toContain('CONFLICT file:/etc/postfix/rateguru-from-other-one.regexp — named like a RateGuru From policy, but not written by this installer');

        [$refused, $log] = mailGatewayRun($host, '--apply');
        expect($refused)->not->toBe(0);
        expect($log)->toContain('/etc/postfix/rateguru-from-other-one.regexp is named like a RateGuru From policy but was not written by this installer — refusing to use or remove it. Nothing was changed');
        expect(mailGatewayTree($host))->toBe($before);
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');

        // A file outside the name shape is never even looked at.
        expect(File::get($host['fs'].'/etc/postfix/sender_access'))->toBe("somebody's own map\n");
    } finally {
        mailGatewayCleanup($host);
    }
});

it('puts a retired From policy back when the apply that retired it rolls back', function () {
    $host = mailGatewayHost();

    try {
        [$first, $log] = mailGatewayRun($host, '--apply');
        expect($first)->toBe(0, $log);
        $installed = array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);

        // No signing identity any more, so the policy is retired — and the
        // capture destination has gone, so the apply fails after that.
        file_put_contents($host['scratch'].'/identity.json', '{"schema_version": 1, "targets": {}}');
        file_put_contents($host['scratch'].'/state/listeners', "127.0.0.1:8891\n");

        [$status, $output] = mailGatewayRun($host, '--apply', ['RATEGURU_MAILGW_IDENTITY_FILE' => $host['scratch'].'/identity.json']);

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('removing /etc/postfix/rateguru-from-tits-guru.regexp')
            ->toContain('rollback complete: configuration and service state restored');

        $restored = array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        expect($restored)->toBe($installed);
        expect($restored)->toHaveKey('etc/postfix/rateguru-from-tits-guru.regexp');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('requires the signer listening before it calls a signing gateway healthy', function () {
    // Without the signer, the apply fails at its runtime check and puts the
    // host back; the listener would otherwise defer everything it was given.
    $host = mailGatewayHost(['signer' => false]);

    try {
        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('the signer is not listening on 127.0.0.1:8891 — every signed listener would defer its mail')
            ->toContain('rollback complete');
        expect(file_exists($host['fs'].'/etc/postfix/main.cf'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }

    // On a converged host, a signer that stops is a CONFLICT of --verify.
    $host = mailGatewayHost();

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        file_put_contents($host['scratch'].'/state/listeners', "127.0.0.1:1025\n");
        [$verified, $report] = mailGatewayRun($host, '--verify');

        expect($verified)->not->toBe(0);
        expect($report)->toContain('CONFLICT runtime — the signer is not listening on 127.0.0.1:8891');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('renders nothing from an identity contract mail-identity refuses, and changes nothing', function () {
    $identity = json_decode(File::get(base_path('infrastructure/config/mail-identity.json')), true);
    $identity['targets']['tits-guru']['dkim']['selector'] = 'RG1';
    $host = mailGatewayHost(['identity' => $identity]);

    try {
        $before = mailGatewayTree($host);

        foreach (['--check', '--apply'] as $mode) {
            [$status, $output] = mailGatewayRun($host, $mode);
            expect($status)->not->toBe(0);
            expect($output)
                ->toContain('tits-guru: dkim.selector must be one lowercase DNS label')
                ->toContain('mail-identity render-signing-plan refused the reviewed identity');
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
});
