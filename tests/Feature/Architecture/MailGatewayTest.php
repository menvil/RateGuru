<?php

use Illuminate\Support\Facades\File;

/**
 * The host-global mail gateway: install-mail-gateway's rendering of the
 * routing plan and the installer as a whole — package, ports, modes,
 * idempotence and rollback — verify-mail-gateway, status-mail-gateway and the
 * gateway's own name. Its signing, its direct outbound routes, the policy it
 * records and the activation boundary are in the MailGateway*Test files beside
 * this one.
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
 * Every jq program a mail script runs: the program variables it defines
 * (sourced, so this is the exact text jq receives) and every single-quoted
 * program given to jq inline.
 *
 * @return array<string, string> label => program
 */
function mailGatewayJqPrograms(string $script): array
{
    $path = mailGatewayScript($script);
    $programs = [];

    // status-mail-gateway runs on load and has no program variables. Only the
    // variables sourcing defines count: an inherited one such as a terminal's
    // TERM_PROGRAM is not a jq program.
    if ($script !== 'status-mail-gateway') {
        $harness = 'inherited="$(compgen -v)"; source "$1" >/dev/null 2>&1 || exit 1; '
            .'for name in $(compgen -v); do grep -qxF "${name}" <<<"${inherited}" && continue; '
            .'case "${name}" in *_PROGRAM|*_DEFINITIONS|*_RULES) printf "%s\n%s\0" "${name}" "${!name}" ;; esac; done';
        $output = (string) shell_exec('bash -c '.escapeshellarg($harness).' _ '.escapeshellarg($path));

        foreach (array_filter(explode("\0", $output)) as $entry) {
            [$name, $program] = explode("\n", $entry, 2);
            $programs["{$script} \${$name}"] = $program;
        }

        if (in_array($script, ['mail-routing', 'install-mail-gateway', 'mail-identity', 'mail-inbound'], true)) {
            expect($programs)->not->toBe([], "no jq program variables were read from {$script}");
        }
    }

    preg_match_all("/\\bjq\\b[^'\\n]*'([^']*)'/", executableSourceLines(File::get($path)), $matches);

    foreach ($matches[1] as $index => $program) {
        $programs["{$script} inline #{$index}"] = $program;
    }

    return $programs;
}

/**
 * What Ubuntu 22.04's jq 1.6 — the jq on every host — refuses and jq 1.7, on
 * developer machines and in CI, accepts: an `if` with no `else`, a keyword used
 * as a `$variable` (the real `$label` this gateway's policy CLI once had), the
 * `?//` alternative operator, and builtins added after 1.6. Comments and string
 * contents are skipped; a string's `\(...)` interpolation is code, and is read.
 *
 * @return list<string>
 */
function mailGatewayJq16Problems(string $program): array
{
    $tokens = [];
    $frames = []; // 'string', or an int: the paren depth inside an interpolation
    $length = strlen($program);

    for ($i = 0; $i < $length; $i++) {
        $char = $program[$i];
        $inString = $frames !== [] && end($frames) === 'string';

        if ($inString) {
            if ($char === '\\') {
                if (($program[$i + 1] ?? '') === '(') {
                    $frames[] = 0;
                }

                $i++;
            } elseif ($char === '"') {
                array_pop($frames);
            }

            continue;
        }

        if ($char === '#') {
            $newline = strpos($program, "\n", $i);
            $i = $newline === false ? $length : $newline;
        } elseif ($char === '"') {
            $frames[] = 'string';
        } elseif ($char === '(' && $frames !== []) {
            $frames[count($frames) - 1]++;
        } elseif ($char === ')' && $frames !== []) {
            if (end($frames) === 0) {
                array_pop($frames);
            } else {
                $frames[count($frames) - 1]--;
            }
        } elseif ($char === '?' && substr($program, $i, 3) === '?//') {
            $tokens[] = '?//';
            $i += 2;
        } elseif (preg_match('/\G\$?[A-Za-z_][A-Za-z0-9_]*/', $program, $match, 0, $i)) {
            $tokens[] = $match[0];
            $i += strlen($match[0]) - 1;
        }
    }

    $problems = [];
    $counts = array_count_values($tokens);

    if (($counts['if'] ?? 0) !== ($counts['else'] ?? 0)) {
        $problems[] = sprintf('%d if but %d else: jq 1.6 requires an else on every if', $counts['if'] ?? 0, $counts['else'] ?? 0);
    }

    foreach (['__loc__', 'and', 'as', 'catch', 'def', 'elif', 'else', 'end', 'foreach', 'if', 'import', 'include', 'label', 'or', 'reduce', 'then', 'try'] as $keyword) {
        if (isset($counts['$'.$keyword])) {
            $problems[] = "\${$keyword} is a keyword jq 1.6 refuses as a variable name";
        }
    }

    foreach (['pick', 'abs', 'toarray', 'trim', 'ltrim', 'rtrim', 'have_decnum', 'have_literal_numbers', '?//'] as $newer) {
        if (isset($counts[$newer])) {
            $problems[] = "{$newer} does not exist in jq 1.6";
        }
    }

    return $problems;
}

/**
 * The shipped sender probe against a fake SMTP server that answers MAIL FROM
 * with $mailReply. Returns every line the probe sent and what it concluded.
 *
 * @return array{lines: list<string>, output: string}
 */
function mailGatewayProbeFakeServer(string $mailReply): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse("could not listen: {$error}");
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    $harness = 'source '.escapeshellarg(mailGatewayScript('verify-mail-gateway'))
        .' && if smtp_probe_sender 127.0.0.1 '.$port.' intruder@foreign.example;'
        .' then echo "accepted ${SMTP_STAGE}"; else echo "refused ${SMTP_STAGE} ${SMTP_REPLY}"; fi'
        .' && bad "reported after the session"';

    $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);

    try {
        $client = stream_socket_accept($server, 10);
        expect($client)->not->toBeFalse('the probe never connected');
        stream_set_timeout($client, 10);

        $lines = [];
        fwrite($client, "220 fake ESMTP\r\n");
        $lines[] = rtrim((string) fgets($client));
        fwrite($client, "250 fake\r\n");
        $lines[] = rtrim((string) fgets($client));
        fwrite($client, $mailReply."\r\n");

        // Everything else it sends, until it hangs up.
        while (($line = fgets($client)) !== false) {
            $lines[] = rtrim($line);
        }

        fclose($client);
    } finally {
        fclose($server);
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return ['lines' => $lines, 'output' => trim($output)];
}

// --- the simulated host: mailGatewayHost() and its helpers are in tests/Pest.php ---

// =============================================================================
// THE PLAN IS THE ONLY SOURCE OF ROUTES
// =============================================================================

it('renders the committed plan into exactly the reviewed listeners and routes', function () {
    $render = mailGatewayRender();
    $services = collect(mailGatewayMasterServices($render['master']));

    $inet = $services->where('type', 'inet')->values();

    // Exactly the plan's endpoints, in plan order, and nothing else listens.
    expect($inet->pluck('name')->all())->toBe(['127.0.0.1:2525', '127.0.0.1:2526']);
    expect($inet->pluck('command')->unique()->all())->toBe(['smtpd']);

    $staging = $inet->firstWhere('name', '127.0.0.1:2525');
    $titsGuru = $inet->firstWhere('name', '127.0.0.1:2526');

    // Capture: queued, then the target's own transport to its destination.
    expect($staging['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-staging-main',
        'smtpd_delay_reject' => 'no',
        'smtpd_reject_unlisted_recipient' => 'no',
        'smtpd_sender_restrictions' => '$rateguru_staging_main_sender_restrictions',
        'content_filter' => 'rateguru-capture-staging-main:[127.0.0.1]:1025',
    ]);

    $transport = $services->firstWhere('name', 'rateguru-capture-staging-main');
    expect($transport)->not->toBeNull();
    expect([$transport['type'], $transport['command']])->toBe(['unix', 'smtp']);
    expect($transport['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-capture-staging-main',
        'smtp_tls_security_level' => 'none',
        'smtp_sasl_auth_enable' => 'no',
    ]);

    // Held: HOLD, and an explicitly empty content filter. Signed, because
    // tits-guru has a reviewed identity: its mail passes the DKIM signer before
    // it is queued, and is deferred when the signer cannot sign.
    expect($titsGuru['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-tits-guru',
        'smtpd_delay_reject' => 'no',
        'smtpd_reject_unlisted_recipient' => 'no',
        'smtpd_sender_restrictions' => '$rateguru_tits_guru_sender_restrictions',
        'smtpd_recipient_restrictions' => 'check_client_access,static:HOLD,permit_mynetworks,reject',
        'content_filter' => '',
        'smtpd_milters' => 'inet:127.0.0.1:8891',
        'milter_protocol' => '6',
        'milter_default_action' => 'tempfail',
        'cleanup_service_name' => 'rateguru-cleanup-tits-guru',
    ]);

    // Its own cleanup service, whose header checks are its From policy.
    $cleanup = $services->firstWhere('name', 'rateguru-cleanup-tits-guru');
    expect([$cleanup['type'], $cleanup['command']])->toBe(['unix', 'cleanup']);
    expect($cleanup['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-cleanup-tits-guru',
        'header_checks' => 'regexp:/etc/postfix/rateguru-from-tits-guru.regexp',
        'nested_header_checks' => '',
        'always_add_missing_headers' => 'yes',
    ]);
    expect(array_keys($render['policies']))->toBe(['rateguru-from-tits-guru.regexp']);

    // Each listener's sender authorization: exactly its own domain — or empty,
    // except on a signed listener, whose author must be in its domain.
    $main = mailGatewayMainParameters($render['main']);
    expect($main['rateguru_staging_main_sender_restrictions'])->toBe('check_sender_access inline:{ staging.invalid=OK, <>=OK }, reject');
    expect($main['rateguru_tits_guru_sender_restrictions'])->toBe('check_sender_access inline:{ tits.guru=OK }, reject');
});

it('follows whatever plan it is given, never a table of its own', function () {
    $scratch = mailGatewayScratch();

    try {
        // A plan no committed file describes: two listeners on ports nothing
        // else uses, a capture destination that is not Mailpit.
        file_put_contents($scratch.'/synthetic.json', mailRoutingJson([
            'schema_version' => 2,
            'listeners' => [
                [
                    'identity' => 'alpha',
                    'environment_class' => 'staging',
                    'lifecycle' => 'active',
                    'listen' => ['host' => '127.0.0.1', 'port' => 3101],
                    'delivery_mode' => 'capture',
                    'sender' => ['allowed_domain' => 'alpha.invalid'],
                    'route' => ['kind' => 'capture', 'host' => '127.0.0.9', 'port' => 3999],
                ],
                [
                    'identity' => 'beta',
                    'environment_class' => 'production',
                    'lifecycle' => 'planned',
                    'listen' => ['host' => '127.0.0.1', 'port' => 3102],
                    'delivery_mode' => 'held',
                    'sender' => ['allowed_domain' => 'beta.example', 'default_from' => 'x@beta.example', 'bounce_domain' => 'b.beta.example', 'reply_domain' => 'r.beta.example'],
                    'route' => null,
                ],
            ],
        ]));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/synthetic.json');
        expect($render['status'])->toBe(0, $render['output']);

        $services = collect(mailGatewayMasterServices($render['master']));

        expect($services->where('type', 'inet')->pluck('name')->values()->all())->toBe(['127.0.0.1:3101', '127.0.0.1:3102']);
        expect($services->firstWhere('name', '127.0.0.1:3101')['options']['content_filter'])->toBe('rateguru-capture-alpha:[127.0.0.9]:3999');
        expect($services->firstWhere('name', '127.0.0.1:3102')['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
        expect($render['master'])->not->toContain('2525')->not->toContain('2526')->not->toContain('1025');
        expect(mailGatewayMainParameters($render['main']))->toHaveKey('rateguru_alpha_sender_restrictions');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses a delivery mode it has no Postfix spelling for, and a plan value that is not a plain token', function (array $listener, string $reason) {
    $scratch = mailGatewayScratch();

    try {
        file_put_contents($scratch.'/plan.json', mailRoutingJson(['schema_version' => 2, 'listeners' => [$listener]]));
        file_put_contents($scratch.'/mail-outbound.json', mailRoutingJson(mailGatewayOutboundContract()));

        // Against an ENABLED contract, so what refuses is the renderer itself.
        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', $scratch.'/mail-outbound.json');

        expect($render['status'])->not->toBe(0);
        expect($render['output'])->toContain($reason);
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'an outbound route of a kind it has no spelling for' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'outbound', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'relay', 'host' => 'smtp.example.com', 'port' => 587]],
        'no Postfix rendering is defined for delivery mode "outbound" of gamma',
    ],
    'outbound with no route' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'outbound', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => null],
        'no Postfix rendering is defined for delivery mode "outbound" of gamma',
    ],
    'a direct route on a held listener' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'direct']],
        'no Postfix rendering is defined for delivery mode "held" of gamma',
    ],
    'a direct route carrying a relay host' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'outbound', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'direct', 'host' => 'smtp.example.com', 'port' => 587]],
        'refusing to render around it',
    ],
    'held with a route' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'capture', 'host' => '127.0.0.1', 'port' => 1025]],
        'no Postfix rendering is defined for delivery mode "held" of gamma',
    ],
    'capture with no route' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'capture', 'sender' => ['allowed_domain' => 'gamma.invalid'], 'route' => null],
        'no Postfix rendering is defined for delivery mode "capture" of gamma',
    ],
    'a directive smuggled into a domain' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => "gamma.example\nrelayhost = evil.example"], 'route' => null],
        'refusing to render around it',
    ],
    'a space in a host' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1 0.0.0.0', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => null],
        'refusing to render around it',
    ],
]);

it('renders a target it has never heard of, generically', function () {
    $render = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry());
    $services = collect(mailGatewayMasterServices($render['master']));

    expect($services->where('type', 'inet')->pluck('name')->values()->all())
        ->toBe(['127.0.0.1:2599', '127.0.0.1:2525', '127.0.0.1:2526']);

    $demo = $services->firstWhere('name', '127.0.0.1:2599');
    expect($demo['options']['syslog_name'])->toBe('postfix/rateguru-demo-shop');
    expect($demo['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
    expect($demo['options']['content_filter'])->toBe('');
    expect(mailGatewayMainParameters($render['main'])['rateguru_demo_shop_sender_restrictions'])
        ->toBe('check_sender_access inline:{ demo-shop.example=OK, <>=OK }, reject');

    // And as a staging capture target, the same way.
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = [
        'submission' => ['host' => '127.0.0.1', 'port' => 2599],
        'delivery_mode' => 'capture',
        'allowed_from_domain' => 'demo-shop.invalid',
        'capture' => ['host' => '127.0.0.1', 'port' => 1025],
    ];

    $capture = collect(mailGatewayMasterServices(mailGatewayRender($policy, mailRoutingDemoShopRegistry(['environment_class' => 'staging']))['master']));
    expect($capture->firstWhere('name', '127.0.0.1:2599')['options']['content_filter'])->toBe('rateguru-capture-demo-shop:[127.0.0.1]:1025');
    expect($capture->firstWhere('name', 'rateguru-capture-demo-shop'))->not->toBeNull();
});

it('restates no policy rule and names no target, domain or port', function () {
    foreach (['install-mail-gateway', 'verify-mail-gateway', 'status-mail-gateway'] as $script) {
        $source = File::get(mailGatewayScript($script));
        $code = executableSourceLines($source);

        foreach (['staging-main', 'tits-guru', 'tits.guru', 'demo-shop', 'staging.invalid', 'bounce.tx', 'reply.tits'] as $name) {
            expect(str_contains($source, $name))->toBeFalse("{$script} names {$name}");
        }

        foreach (['2525', '2526', '2599'] as $port) {
            expect(preg_match('/\b'.$port.'\b/', $code))->toBe(0, "{$script} hard-codes the gateway port {$port}");
        }
    }

    // The installer reads the policy only through mail-routing, mail-identity
    // and mail-inbound, from its own bundle: the policy and registry files are
    // arguments to those CLIs and are never parsed here.
    $installer = executableSourceLines(File::get(mailGatewayScript()));

    expect($installer)->toContain('"${MAIL_ROUTING_CLI}" render-plan --file "${POLICY_FILE}" --registry "${REGISTRY_FILE}"');
    expect($installer)->toContain('--identity "${IDENTITY_FILE}" --routing "${POLICY_FILE}"');
    expect($installer)->toContain('"${MAIL_INBOUND_CLI}" render-receiver --inbound "${INBOUND_FILE}" --routing "${POLICY_FILE}"');
    expect(substr_count($installer, '${POLICY_FILE}'))->toBe(3);
    expect(substr_count($installer, '${REGISTRY_FILE}'))->toBe(3);
    expect(substr_count($installer, '${IDENTITY_FILE}'))->toBe(2);
    expect($installer)->toContain('MAIL_ROUTING_CLI="$(gated_default RATEGURU_MAILGW_MAIL_ROUTING_CLI "${SCRIPT_DIR}/mail-routing")"');

    // None of mail-routing's own rules live here.
    foreach (['non_deliverable_tld', 'class_modes', 'first_submission_port', 'is_domain', 'is_address', 'lifecycle'] as $rule) {
        expect(str_contains($installer, $rule))->toBeFalse("install-mail-gateway restates the policy rule {$rule}");
    }

    // Nor any of the host contract's: mail-identity judges mail-outbound.json,
    // from this same bundle, and the installer only asks it.
    expect($installer)
        ->toContain('MAIL_IDENTITY_CLI="$(gated_default RATEGURU_MAILGW_MAIL_IDENTITY_CLI "${SCRIPT_DIR}/mail-identity")"')
        ->toContain('"${MAIL_IDENTITY_CLI}" check-outbound --plan "${plan}" --outbound "${path}"');

    foreach (['private_tlds', 'hostname_re', 'hostname_problem', 'OUTBOUND_CONTRACT_PROGRAM', 'OUTBOUND_SCHEMA_VERSION'] as $rule) {
        expect(str_contains($installer, $rule))->toBeFalse("install-mail-gateway restates the host contract rule {$rule}");
    }
});

it('refuses with mail-routing\'s own verdict when the policy is invalid', function () {
    $host = mailGatewayHost();

    try {
        $policy = mailPreActivationPolicy()['routing'];
        $policy['targets']['tits-guru']['submission']['port'] = 2525;
        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));

        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_POLICY_FILE' => $host['scratch'].'/policy.json']);

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('submission port 2525 is claimed by more than one target: staging-main, tits-guru')
            ->toContain('mail-routing render-plan refused the reviewed policy');
    } finally {
        mailGatewayCleanup($host);
    }
});

// =============================================================================
// THE RENDERED CONFIGURATION IS FAIL-CLOSED, LOOPBACK-ONLY AND NON-PUBLIC
// =============================================================================

it('binds only loopback IPv4 endpoints, and has no smtp, submission or smtps listener', function () {
    $render = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry());
    $services = mailGatewayMasterServices($render['master']);
    $main = mailGatewayMainParameters($render['main']);

    expect($main['inet_interfaces'])->toBe('127.0.0.1');
    expect($main['inet_protocols'])->toBe('ipv4');
    expect($main['mynetworks'])->toBe('127.0.0.0/8');

    foreach ($services as $service) {
        if ($service['type'] !== 'inet') {
            continue;
        }

        expect(preg_match('/\A127\.0\.0\.1:(\d+)\z/', $service['name'], $matches))->toBe(1, "inet service on a non-loopback endpoint: {$service['name']}");
        expect((int) $matches[1])->not->toBeIn([25, 465, 587]);
    }

    foreach (['smtp', 'submission', 'smtps', '0.0.0.0', '::', '[::]'] as $public) {
        expect(collect($services)->where('type', 'inet')->pluck('name')->all())->not->toContain($public);
    }

    expect($render['master'])->not->toMatch('/^(smtp|submission|smtps|465|587|25)\s+inet\b/m');
});

it('delivers nothing it was not routed to deliver: every fallback is the error transport', function () {
    $render = mailGatewayRender();
    $main = mailGatewayMainParameters($render['main']);
    $services = collect(mailGatewayMasterServices($render['master']));

    foreach (['default_transport', 'relay_transport', 'local_transport', 'virtual_transport'] as $transport) {
        expect($main[$transport])->toStartWith('error:');
    }

    foreach (['relayhost', 'mydestination', 'relay_domains', 'transport_maps', 'content_filter', 'sender_dependent_relayhost_maps', 'sender_dependent_default_transport_maps', 'alias_maps'] as $empty) {
        expect($main)->toHaveKey($empty);
        expect($main[$empty])->toBe('', "{$empty} must be empty");
    }

    // No delivery agent anything could fall through to: the only smtp clients
    // are the per-target capture transports, and relay is the error transport.
    foreach (['smtp', 'local', 'virtual', 'lmtp'] as $agent) {
        expect($services->where('type', 'unix')->pluck('name')->all())->not->toContain($agent);
    }

    expect($services->firstWhere('name', 'relay')['command'])->toBe('error');

    expect($services->where('command', 'smtp')->pluck('name')->values()->all())->toBe(['rateguru-capture-staging-main']);
    expect($services->whereIn('command', ['local', 'virtual', 'lmtp', 'pipe'])->all())->toBe([]);
});

it('leaves the package master.cf repair nothing to add, so an upgrade cannot drift it', function () {
    // The jammy postfix postinst runs fix_master on every configure, upgrades
    // included: it appends each of these services when no line starts with its
    // name, and a missing relay as a working smtp client. Every one is already
    // here, matched the way fix_master matches it.
    $master = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry())['master'];

    foreach (['flush', 'proxymap', 'trace', 'verify', 'tlsmgr', 'anvil', 'scache', 'discard', 'retry', 'relay'] as $service) {
        expect(preg_match('/^'.$service.'[[:space:]]/m', $master))->toBe(1, "fix_master would append {$service}");
    }

    // Nor does its first rewrite apply: cleanup is already unprivileged.
    expect(preg_match('/^cleanup[[:space:]]+unix[[:space:]]+-/m', $master))->toBe(0);
    expect(preg_match('/^tlsmgr[[:space:]]*fifo/m', $master))->toBe(0);

    // And the package is told to configure nothing of its own.
    expect(executableSourceLines(File::get(mailGatewayScript())))
        ->toContain('"postfix postfix/main_mailer_type select No configuration"')
        ->not->toContain('select Local only');
});

it('waits only for a unit on its way to running, and answers at once for any other state', function (array $states, int $status, bool $waited) {
    $scratch = mailGatewayScratch();

    try {
        // Each ActiveState query answers the next state (the last one sticks);
        // SubState follows the state just answered.
        file_put_contents($scratch.'/states', implode("\n", $states)."\n");
        file_put_contents($scratch.'/bin/systemctl', <<<'STUB'
            #!/bin/bash
            case "$3" in
                --property=ActiveState)
                    echo x >> "${STATES}.queries"
                    state="$(head -n 1 "${STATES}")"
                    echo "${state}" > "${STATES}.last"
                    if [[ "$(wc -l < "${STATES}")" -gt 1 ]]; then tail -n +2 "${STATES}" > "${STATES}.next" && mv "${STATES}.next" "${STATES}"; fi
                    echo "${state}" ;;
                --property=SubState)
                    [[ "$(cat "${STATES}.last" 2>/dev/null)" == active ]] && echo running || echo dead ;;
            esac
            STUB."\n");
        chmod($scratch.'/bin/systemctl', 0o755);

        $harness = 'source '.escapeshellarg(mailGatewayScript())
            .' && SYSTEMCTL_BIN='.escapeshellarg($scratch.'/bin/systemctl').' RUNTIME_WAIT=3'
            .' && if wait_service_running postfix@-.service; then echo rc=0; else echo rc=1; fi';

        $output = (string) shell_exec('STATES='.escapeshellarg($scratch.'/states').' bash -c '.escapeshellarg($harness).' 2>&1');

        expect(preg_match('/rc=(\d)/', $output, $matches))->toBe(1, $output);
        expect((int) $matches[1])->toBe($status, $output);

        // One state reading means it answered at once; more means it waited.
        $queries = count(file($scratch.'/states.queries') ?: []);
        expect($queries > 1)->toBe($waited, "{$queries} state reading(s)");
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'running already' => [['active'], 0, false],
    'inactive' => [['inactive'], 1, false],
    'failed' => [['failed'], 1, false],
    'deactivating' => [['deactivating'], 1, false],
    'activating, then running' => [['activating', 'activating', 'active'], 0, true],
    'reloading, then running' => [['reloading', 'active'], 0, true],
    'activating, then failed' => [['activating', 'failed'], 1, true],
    'activating for longer than the window' => [['activating'], 1, true],
]);

it('selects a route by listener only, never by sender or recipient', function () {
    $render = mailGatewayRender();
    $main = mailGatewayMainParameters($render['main']);

    // No map anywhere that routes on an address.
    foreach (['transport_maps', 'sender_dependent_default_transport_maps', 'sender_dependent_relayhost_maps'] as $map) {
        expect($main[$map])->toBe('');
    }

    expect($render['main'].$render['master'])
        ->not->toContain('FILTER')
        ->not->toContain('check_recipient_access')
        ->not->toContain('recipient_bcc_maps')
        ->not->toContain('virtual_alias_maps');

    // Header checks exist only as a signed target's From policy, in its own
    // cleanup service, and only accept or refuse: none of them routes.
    expect($render['main'])->not->toContain('header_checks');
    expect(substr_count($render['master'], 'header_checks=regexp:'))->toBe(1);
    foreach ($render['policies'] as $policy) {
        foreach (preg_split('/\R/', $policy) as $line) {
            if ($line !== '' && ! str_starts_with($line, '#')) {
                expect($line)->toMatch('#^/.*/ (DUNNO|REJECT 5\.7\.1 .*)$#');
            }
        }
        foreach (['FILTER', 'HOLD', 'REDIRECT', 'PREPEND', 'REPLACE', 'DISCARD', 'BCC', 'OK'] as $action) {
            expect(preg_match('#/ '.$action.'\b#', $policy))->toBe(0, "a From policy uses {$action}");
        }
    }

    // The content filter is set per listener in master.cf, never globally.
    foreach (mailGatewayMasterServices($render['master']) as $service) {
        if ($service['type'] === 'inet') {
            expect($service['options'])->toHaveKey('content_filter');
        }
    }
});

it('has no SMTP AUTH, no TLS listener and no production delivery, and signs only where a listener says so', function () {
    $render = mailGatewayRender();
    $main = mailGatewayMainParameters($render['main']);
    $all = $render['main']."\n".$render['master'];

    expect($main['smtpd_sasl_auth_enable'])->toBe('no');
    expect($main['smtp_sasl_auth_enable'])->toBe('no');
    expect($main['smtpd_tls_security_level'])->toBe('none');
    expect($main['smtp_tls_security_level'])->toBe('none');

    // tlsmgr is present only as the internal service the package repair would
    // otherwise append; with no certificate and TLS off, nothing uses it.
    foreach (['smtpd_tls_cert_file', 'smtpd_tls_key_file', 'smtp_sasl_password_maps', 'smtpd_sasl_type', 'smtpd_tls_wrappermode', 'opendkim', 'spf', 'dmarc', 'relayhost = ['] as $absent) {
        expect(str_contains(mb_strtolower($all), mb_strtolower($absent)))->toBeFalse("the rendered gateway contains {$absent}");
    }

    // No global milter in main.cf: locally submitted mail and every unsigned
    // listener never reach the signer. Exactly one listener names it.
    expect(str_contains($render['main'], 'milter'))->toBeFalse('main.cf names a milter');
    $milters = collect(mailGatewayMasterServices($render['master']))
        ->filter(static fn (array $service): bool => collect($service['options'])->keys()->contains(static fn (string $key): bool => str_contains($key, 'milter')))
        ->pluck('name')->values()->all();
    expect($milters)->toBe(['127.0.0.1:2526']);
});

it('authorizes each listener\'s senders by exact domain, refused at MAIL FROM', function () {
    $render = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry());
    $main = mailGatewayMainParameters($render['main']);

    // A subdomain is a different identity; the parent never matches it.
    expect($main['parent_domain_matches_subdomains'])->toBe('');
    expect($main['smtpd_null_access_lookup_key'])->toBe('<>');

    foreach ($render['plan']['listeners'] as $listener) {
        $endpoint = $listener['listen']['host'].':'.$listener['listen']['port'];
        $service = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', $endpoint);
        $parameter = 'rateguru_'.str_replace('-', '_', $listener['identity']).'_sender_restrictions';

        expect($service['options']['smtpd_sender_restrictions'])->toBe('$'.$parameter);
        expect($service['options']['smtpd_delay_reject'])->toBe('no');

        // A signed listener's mail needs an author in its own domain, so it
        // admits no empty sender; every other listener does.
        $empty = $listener['identity'] === 'tits-guru' ? '' : ', <>=OK';
        expect($main[$parameter])->toBe("check_sender_access inline:{ {$listener['sender']['allowed_domain']}=OK{$empty} }, reject");
    }
});

// =============================================================================
// THE INSTALLER ON A SIMULATED HOST
// =============================================================================

it('installs the package safely on a host that has none, and only then activates the gateway', function () {
    $host = mailGatewayHost(['ownPolicyRc' => true]);

    try {
        [$status, $output] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $output);

        // Preseeded so the package configures nothing of its own, before it
        // was installed.
        expect(mailGatewayLog($host, 'debconf.log'))
            ->toContain('postfix postfix/main_mailer_type select No configuration')
            ->toContain('postfix postfix/protocols select ipv4')
            ->toContain('postfix postfix/relayhost string');

        // The package went in with service starts suppressed, never removing
        // anything to make room.
        expect(trim(mailGatewayLog($host, 'apt-suppression.log')))->toBe('service starts suppressed during install');
        $mutations = mailGatewayLog($host, 'mutations.log');
        expect($mutations)->toContain('apt-get install -y --no-install-recommends --no-remove')->toContain('[DEBIAN_FRONTEND=noninteractive]');

        // The host's own policy-rc.d is back, byte for byte, and ours is gone.
        expect(File::get($host['fs'].'/usr/sbin/policy-rc.d'))->toBe("#!/bin/sh\n# the host's own\nexit 101\n");
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/policy-rc.d.preserved'))->toBeFalse();

        // The rendered files are installed, and the service enabled and started
        // only after them.
        $render = mailGatewayRender();
        expect(File::get($host['fs'].'/etc/postfix/main.cf'))->toBe($render['main']);
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toBe($render['master']);
        expect(substr(sprintf('%o', fileperms($host['fs'].'/etc/postfix/main.cf')), -3))->toBe('644');

        $installed = strpos($output, 'installing /etc/postfix/master.cf');
        $started = strpos($output, 'starting postfix@-.service');
        expect($installed)->not->toBeFalse();
        expect($started)->toBeGreaterThan($installed);
        expect($mutations)->toContain('systemctl enable postfix.service')->toContain('systemctl start postfix@-.service');

        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installed');

        // The policy it was applied from is recorded beside the marker: the
        // whole canonical plan and host contract, and the host's first one is
        // this inert one.
        expect(json_decode(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/applied-plan.json'), true))->toEqual(mailRoutingPlan(mailPreActivationPolicy()['routing']));
        expect(json_decode(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/applied-outbound.json'), true))
            ->toEqual(mailPreActivationPolicy()['outbound']);
        expect(array_keys(json_decode(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/applied-outbound.json'), true)))->toBe(['direct', 'schema_version']);
        expect($output)->toContain('recording /var/lib/rateguru-mail-gateway/applied-plan.json');

        // And the contract holds.
        [$verify, $report] = mailGatewayRun($host, '--verify');
        expect($verify)->toBe(0, $report);
        expect($report)
            ->toContain('PASS     outbound:direct — direct delivery disabled on this host (mail-outbound.json); 0 outbound route(s) in the plan, and none could be rendered')
            ->toContain('PASS     signing — listeners of tits-guru hand their mail to the signer at inet:127.0.0.1:8891 (install-mail-signing --milter-endpoint), deferred when it cannot sign, each through its own cleanup service and From policy; no other listener is signed')
            ->toContain('PASS     file:/etc/postfix/rateguru-from-tits-guru.regexp — matches the current render')
            ->toContain('PASS     file:/var/lib/rateguru-mail-gateway/applied-plan.json — the recorded policy is exactly the one this bundle requests')
            ->toContain('PASS     file:/var/lib/rateguru-mail-gateway/applied-outbound.json — the recorded policy is exactly the one this bundle requests')
            ->toContain('SUMMARY  pass=12 missing=0 drift=0 conflict=0 deferred=0');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('writes the ownership marker before the package, so an interrupted install is recognisably RateGuru\'s', function () {
    $host = mailGatewayHost();

    try {
        touch($host['scratch'].'/toggles/apt-fail');

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installing');
        expect(file_exists($host['fs'].'/usr/sbin/policy-rc.d'))->toBeFalse('the suppression outlived a failed install');
        expect($output)->toContain('the ownership marker stays, so a re-run resumes');

        // The next run resumes it.
        unlink($host['scratch'].'/toggles/apt-fail');
        [$resumed, $log] = mailGatewayRun($host, '--apply');
        expect($resumed)->toBe(0, $log);
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installed');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('resumes an interrupted RateGuru installation, restoring the host\'s own policy-rc.d first', function () {
    $host = mailGatewayHost(['marker' => 'installing', 'package' => 'partial']);

    try {
        // What an interrupted package installation leaves: our suppression in
        // place, the host's own one preserved beside the marker.
        @mkdir($host['fs'].'/usr/sbin', 0o755, true);
        file_put_contents($host['fs'].'/usr/sbin/policy-rc.d', "#!/bin/sh\n# rateguru-mail-gateway: package service starts suppressed until the gateway configuration is in place\nexit 101\n");
        file_put_contents($host['fs'].'/var/lib/rateguru-mail-gateway/policy-rc.d.preserved', "#!/bin/sh\n# the host's own\nexit 0\n");

        [$check, $report] = mailGatewayRun($host, '--check');
        expect($check)->toBe(0, $report);
        expect($report)
            ->toContain('DRIFT    package:start-suppression')
            ->toContain('RateGuru ownership marker present (state=installing)');

        [$status, $output] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $output);

        expect($output)->toContain('removing the package start suppression an interrupted run left behind');
        expect(mailGatewayLog($host, 'mutations.log'))->toContain('dpkg --configure postfix');
        expect(File::get($host['fs'].'/usr/sbin/policy-rc.d'))->toBe("#!/bin/sh\n# the host's own\nexit 0\n");
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installed');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('fails closed on a mail transport agent RateGuru did not install, before changing anything', function (array $options, string $reason) {
    $host = mailGatewayHost($options);

    try {
        $before = mailGatewayTree($host);

        [$check, $report] = mailGatewayRun($host, '--check');
        expect($check)->not->toBe(0);
        expect($report)->toContain('CONFLICT');

        [$status, $output] = mailGatewayRun($host, '--apply');
        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('Nothing was changed');

        // No package, no preseed, no service change, no file, no marker.
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'an installed Postfix' => [['package' => 'installed'], 'without the RateGuru ownership marker'],
    'a half-installed Postfix' => [['package' => 'partial'], 'without the RateGuru ownership marker'],
    'a leftover /etc/postfix' => [['postfixDir' => true], 'without the RateGuru ownership marker'],
    'another mail transport agent' => [['otherMta' => 'exim4-daemon-light'], 'another mail transport agent is installed (exim4-daemon-light)'],
]);

it('refuses a gateway port another process already holds, before installing anything', function () {
    $host = mailGatewayHost(['listeners' => ['127.0.0.1:1025', '127.0.0.1:2526']]);

    try {
        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('gateway port 2526 is already in use by another process');
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('takes exactly one of --check, --apply or --verify, and nothing it does not know', function (array $arguments, int $exit, string $stdout, string $stderr) {
    // On a simulated host, so a parser that let a refused command line through
    // would still have nothing real to touch.
    $host = mailGatewayHost();

    try {
        $process = proc_open(['bash', mailGatewayScript(), ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $host['scratch'], $host['env']);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe($exit, $out.$err);

        // The usage text goes to stdout when it was asked for, and to stderr
        // ahead of the refusal when it was not.
        foreach ([[$out, $stdout], [$err, $stderr]] as [$actual, $expected]) {
            if ($expected === '') {
                expect($actual)->toBe('');
            } else {
                expect($actual)
                    ->toStartWith("Usage:\n  install-mail-gateway --check\n  install-mail-gateway --apply\n  install-mail-gateway --verify\n")
                    ->toEndWith($expected);
            }
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayLog($host, 'reads.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'asked for help' => [['--help'], 0, "from install-mail-signing --milter-endpoint, in the same bundle.\n", ''],
    'no mode' => [[], 1, '', "ERROR: one of --check, --apply or --verify is required\n"],
    'two modes' => [['--check', '--verify'], 1, '', "ERROR: mode given more than once\n"],
    'an unknown argument' => [['--check', '--force'], 1, '', "ERROR: unknown argument: --force\n"],
]);

it('changes nothing in --check or --verify: no package manager, no service, no file', function (array $options) {
    $host = mailGatewayHost($options);

    try {
        if (($options['marker'] ?? null) === 'installed') {
            // A converged gateway first, so --verify inspects a real state.
            $fresh = mailGatewayHost();
            [$applied] = mailGatewayRun($fresh, '--apply');
            expect($applied)->toBe(0);
            exec('cp -a '.escapeshellarg($fresh['fs'].'/.').' '.escapeshellarg($host['fs']));
            exec('cp -a '.escapeshellarg($fresh['scratch'].'/state/.').' '.escapeshellarg($host['scratch'].'/state'));
            mailGatewayCleanup($fresh);
        }

        $before = mailGatewayTree($host);

        foreach (['--check', '--verify'] as $mode) {
            mailGatewayRun($host, $mode);
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('', 'a read-only mode called a mutating command');
        expect(mailGatewayLog($host, 'debconf.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'a host with no Postfix' => [[]],
    'a converged gateway' => [['marker' => 'installed', 'package' => 'installed']],
]);

it('is idempotent: a second --apply rewrites nothing and restarts nothing', function () {
    $host = mailGatewayHost();

    try {
        [$first] = mailGatewayRun($host, '--apply');
        expect($first)->toBe(0);

        $before = mailGatewayTree($host);
        file_put_contents($host['scratch'].'/log/mutations.log', '');

        [$second, $output] = mailGatewayRun($host, '--apply');
        expect($second)->toBe(0, $output);

        expect($output)->toContain('gateway configuration unchanged')->not->toContain('installing /etc/postfix');
        $mutations = mailGatewayLog($host, 'mutations.log');
        expect($mutations)->not->toContain('apt-get')->not->toContain('start')->not->toContain('reload')->not->toContain('restart');

        // Only the run's own (empty) backup directory is new.
        $after = array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        $original = array_filter($before, static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        expect($after)->toBe($original);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('rolls back the configuration and the service state when the running gateway is unhealthy', function () {
    $host = mailGatewayHost();

    try {
        [$first] = mailGatewayRun($host, '--apply');
        expect($first)->toBe(0);
        $installed = mailGatewayTree($host);

        // A changed policy, and a capture destination that has gone away.
        $policy = mailPreActivationPolicy()['routing'];
        $policy['targets']['staging-main']['submission']['port'] = 2527;
        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));
        file_put_contents($host['scratch'].'/state/listeners', "\n");

        [$status, $output] = mailGatewayRun($host, '--apply', ['RATEGURU_MAILGW_POLICY_FILE' => $host['scratch'].'/policy.json']);

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('capture destination 127.0.0.1:1025 is not listening')
            ->toContain('rollback complete: configuration and service state restored')
            ->not->toContain('rollback INCOMPLETE');

        // Back exactly as it was, and running again.
        $restored = array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        $original = array_filter($installed, static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        expect($restored)->toBe($original);
        // Running all along: the restored configuration is taken by a reload,
        // so undoing a change never stops mail on the other listeners.
        expect(mailGatewayLog($host, 'mutations.log'))
            ->toContain('systemctl reload postfix@-.service')
            ->not->toContain('systemctl restart postfix@-.service')
            ->not->toContain('systemctl stop postfix@-.service');
        expect(file_exists($host['scratch'].'/state/postfix@-.service.active'))->toBeTrue();

        // Backups hold configuration only — never a queue, never a message.
        foreach (File::allFiles($host['fs'].'/var/backups/rateguru-mail-gateway', true) as $backup) {
            expect($backup->getRelativePathname())->toMatch('#/etc/postfix/(main|master)\.cf$#');
        }
    } finally {
        mailGatewayCleanup($host);
    }
});

it('never lets Postfix judge a configuration it did not accept into place', function () {
    $host = mailGatewayHost();

    try {
        touch($host['scratch'].'/toggles/postconf-warn');

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('Postfix does not accept the rendered gateway configuration');

        // The package is in (the marker says so, and a re-run resumes), but the
        // gateway configuration never was, and nothing was started.
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toContain('smtp      inet');
        expect(mailGatewayLog($host, 'mutations.log'))->not->toContain('systemctl start');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('reads its own unit names, and never touches the capture services it delivers into', function () {
    $installer = executableSourceLines(File::get(mailGatewayScript()));

    expect($installer)
        ->toContain('POSTFIX_UNIT="postfix.service"')
        ->toContain('POSTFIX_INSTANCE_UNIT="postfix@-.service"')
        ->not->toContain('staging-mailpit')
        ->not->toContain('staging-mailtrap')
        ->not->toContain('install-mail-capture');
});

// =============================================================================
// DIRECT OUTBOUND: ONE DEDICATED CLIENT PER TARGET, AND ONLY WHEN THE HOST SAYS SO
// =============================================================================

it('renders the pre-activation policy byte for byte as the gateway the host accepted, with no Internet route', function () {
    $render = mailGatewayRender();

    // The configuration the real shared host runs until tits-guru's
    // activation. A plan with no outbound target must render exactly this, or
    // the host drifts and its read-only verification fails until it is
    // reconverged: a change here is a host change, and has to be deliberate. It differs from the configuration the
    // staging host was accepted with in what signs tits-guru's held mail —
    // its listener's milter, its own cleanup service and From policy, and no
    // empty sender on it — and in the name Postfix calls itself, now the
    // host's reviewed MTA hostname: see the test below.
    expect(hash('sha256', $render['main']))->toBe('d06bc417c2fbd31c5ad9255c59b6755950a1cb44940f8b16c2ed1195c333cd90');
    expect(mailGatewayMainParameters($render['main'])['myhostname'])->toBe('mta1.tits.guru');
    expect(hash('sha256', $render['master']))->toBe('79d52c357f8fdda66e34047e8e504da624a4403eca2acd70793a84b6aa662e91');
    expect(hash('sha256', $render['policies']['rateguru-from-tits-guru.regexp']))->toBe('9eef170db8b201fa8a026d4a49c7280fef2e71dab8ddc646627de4e4581c691e');

    // No outbound transport, no MTA identity, no filter next-hop setting, and
    // the only smtp client is the staging capture transport.
    expect($render['main'].$render['master'])
        ->not->toContain('rateguru-outbound-')
        ->not->toContain('default_filter_nexthop')
        ->not->toContain('smtp_helo_name')
        ->not->toContain('smtp_tls_security_level=may');

    $services = collect(mailGatewayMasterServices($render['master']));
    expect($services->where('command', 'smtp')->pluck('name')->values()->all())->toBe(['rateguru-capture-staging-main']);

    // And the host contract it was rendered against keeps direct delivery off.
    expect(mailPreActivationPolicy()['outbound'])->toBe(mailGatewayOutboundContract(false, 'mta1.tits.guru'));
});

it('renders the committed request as that same gateway plus tits-guru\'s own direct route, and nothing else', function () {
    $committed = mailCommittedPolicy();
    $signing = mailIdentityRun(['render-signing-plan']);
    expect($signing['status'])->toBe(0, $signing['stderr']);

    $held = mailGatewayRender();
    $render = mailGatewayRender($committed['routing'], null, $committed['outbound'], json_decode($signing['stdout'], true, 512, JSON_THROW_ON_ERROR));

    // What activate-mail-outbound installs on the shared host, pinned.
    expect(hash('sha256', $render['main']))->toBe('c38944d27093b20155dc177ed44ecaf28cc6328fd73641fb621977eb300e40ec');
    expect(hash('sha256', $render['master']))->toBe('c670aa50959152d55e077fd23340862077be47ef4b697bb9a8bd1f4ad8c47330');

    // main.cf: one setting more — the queue manager's empty filter next hop,
    // so the recipient's own domain is the next hop — and the same name.
    $main = mailGatewayMainParameters($render['main']);
    expect($main)->toBe([...mailGatewayMainParameters($held['main']), 'default_filter_nexthop' => '']);
    expect($main['myhostname'])->toBe('mta1.tits.guru');
    expect($main['relayhost'])->toBe('');

    // master.cf: tits-guru's listener hands its mail to its own client instead
    // of holding it, and that client is the one new service.
    $before = collect(mailGatewayMasterServices($held['master']))->keyBy('name');
    $after = collect(mailGatewayMasterServices($render['master']))->keyBy('name');
    expect($after->keys()->diff($before->keys())->values()->all())->toBe(['rateguru-outbound-tits-guru']);
    expect($before->keys()->diff($after->keys())->values()->all())->toBe([]);

    $listener = $after['127.0.0.1:2526']['options'];
    expect($listener['content_filter'])->toBe('rateguru-outbound-tits-guru:');
    expect($listener)->not->toHaveKey('smtpd_recipient_restrictions');
    expect(array_diff_key($listener, ['content_filter' => 1]))
        ->toBe(array_diff_key($before['127.0.0.1:2526']['options'], ['content_filter' => 1, 'smtpd_recipient_restrictions' => 1]));
    expect($before['127.0.0.1:2526']['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');

    expect($after['rateguru-outbound-tits-guru'])->toBe([
        'name' => 'rateguru-outbound-tits-guru',
        'type' => 'unix',
        'command' => 'smtp',
        'options' => [
            'syslog_name' => 'postfix/rateguru-outbound-tits-guru',
            'smtp_helo_name' => 'mta1.tits.guru',
            'smtp_tls_security_level' => 'may',
            'smtp_tls_loglevel' => '1',
            'smtp_sasl_auth_enable' => 'no',
            'smtp_fallback_relay' => '',
        ],
    ]);

    // Every other service — staging's capture listener and transport among
    // them — is exactly as it was, and so is tits-guru's From policy.
    foreach ($before->keys()->reject(static fn (string $name): bool => $name === '127.0.0.1:2526') as $name) {
        expect($after[$name])->toBe($before[$name], "{$name} changed");
    }
    expect($render['policies'])->toBe($held['policies']);

    // No smart host, relay or credential anywhere: main.cf's relayhost stays
    // empty, and nothing else names one.
    expect($render['master'])->not->toContain('relayhost');
    expect($render['main'].$render['master'])
        ->not->toContain('smtp_sasl_password_maps')
        ->not->toContain('smtp_sasl_auth_enable=yes');
});

// =============================================================================
// SIGNING: ONLY THE SIGNED LISTENERS, AND NEVER UNSIGNED
// =============================================================================

it('renders exactly the gateway the staging host accepted when nothing is signed and no MTA hostname is reviewed', function () {
    // The milter lines and the host's name are the whole difference: without
    // a signing identity, and on a host contract that reviews no MTA hostname,
    // the committed policy renders the accepted configuration byte for byte.
    $unnamed = mailGatewayOutboundContract(false, '');
    $render = mailGatewayRender(outbound: $unnamed, signing: ['schema_version' => 1, 'targets' => []]);

    expect(hash('sha256', $render['main']))->toBe('69997052d869d451ee44815f714fdb92e47c90e791fc39306a6b2e55b64448ba');
    expect(mailGatewayMainParameters($render['main'])['myhostname'])->toBe('mail-gateway.rateguru.invalid');

    // The committed contract names the host's MTA, and that one line is what
    // it changes: the name Postfix calls itself in its banner and Received hops.
    $named = mailGatewayRender(signing: ['schema_version' => 1, 'targets' => []]);
    expect($named['master'])->toBe($render['master']);
    expect(array_values(array_diff(explode("\n", $render['main']), explode("\n", $named['main']))))->toBe(['myhostname = mail-gateway.rateguru.invalid']);
    expect(array_values(array_diff(explode("\n", $named['main']), explode("\n", $render['main']))))->toBe(['myhostname = mta1.tits.guru']);
    $render = $named;
    expect(hash('sha256', $render['master']))->toBe('2f7aea126dbd670889ca7599887d19794c78cd975a53c66d843e916bfe666f34');
    expect($render['master'])->not->toContain('milter')->not->toContain('signer');

    expect($render['policies'])->toBe([]);

    // And the signed render differs from it in tits-guru's signing alone: its
    // listener's milter and cleanup service, that service with the From
    // policy, and no empty sender on its listener.
    $signed = mailGatewayRender();
    $removed = array_values(array_diff(explode("\n", $render['master']), explode("\n", $signed['master'])));
    $added = array_values(array_diff(explode("\n", $signed['master']), explode("\n", $render['master'])));
    expect($removed)->toBe([]);
    expect($added)->toBe([
        '# A signed listener hands each message to the DKIM signer before it is',
        '# queued, and defers it when the signer cannot sign: never unsigned.',
        '# Signing routes nothing.',
        '  -o smtpd_milters=inet:127.0.0.1:8891',
        '  -o milter_protocol=6',
        '  -o milter_default_action=tempfail',
        '  -o cleanup_service_name=rateguru-cleanup-tits-guru',
        "# --- signed listeners' cleanup: each target's own From policy. A message",
        "# whose From is not exactly one address in its target's domain is refused",
        '# before it is queued. Nothing here routes.',
        'rateguru-cleanup-tits-guru unix  n       -       n       -       0       cleanup',
        '  -o syslog_name=postfix/rateguru-cleanup-tits-guru',
        '  -o header_checks=regexp:/etc/postfix/rateguru-from-tits-guru.regexp',
        '  -o nested_header_checks=',
        '  -o always_add_missing_headers=yes',
    ]);

    expect(array_values(array_diff(explode("\n", $render['main']), explode("\n", $signed['main']))))
        ->toBe(['rateguru_tits_guru_sender_restrictions = check_sender_access inline:{ tits.guru=OK, <>=OK }, reject']);
    expect(array_values(array_diff(explode("\n", $signed['main']), explode("\n", $render['main']))))->toBe([
        '# A signed listener admits no empty sender: a message without an author',
        '# in its own domain could never be signed.',
        'rateguru_tits_guru_sender_restrictions = check_sender_access inline:{ tits.guru=OK }, reject',
    ]);
});

it('keeps every jq program the mail scripts run within what jq 1.6 on the host accepts', function () {
    // The guard catches what it claims to, and nothing it should not.
    expect(mailGatewayJq16Problems('if . then 1 end'))->not->toBe([]);
    expect(mailGatewayJq16Problems('if . then 1 elif 2 then 3 end'))->not->toBe([]);
    expect(mailGatewayJq16Problems('def f($label): $label;'))->not->toBe([]);
    expect(mailGatewayJq16Problems('.a | pick(.b)'))->not->toBe([]);
    expect(mailGatewayJq16Problems('.[] as [$a] ?// $a | $a'))->not->toBe([]);
    expect(mailGatewayJq16Problems('if . then 1 elif 2 then 3 else 4 end'))->toBe([]);
    expect(mailGatewayJq16Problems('"\(if . then "if" else "x" end) if"'))->toBe([]);
    expect(mailGatewayJq16Problems("# if there is no else here\n. # nor here: if\n"))->toBe([]);
    expect(mailGatewayJq16Problems('"\(.a | join(", ")) and if"'))->toBe([]);

    $checked = 0;

    foreach (['mail-routing', 'mail-identity', 'mail-inbound', 'install-mail-gateway', 'verify-mail-gateway', 'status-mail-gateway', 'verify-infrastructure'] as $script) {
        foreach (mailGatewayJqPrograms($script) as $label => $program) {
            expect(mailGatewayJq16Problems($program))->toBe([], "{$label} would not run on jq 1.6");
            $checked++;
        }
    }

    // The policy rules, the renderer and the outbound contract are among them.
    expect($checked)->toBeGreaterThan(20);
});

// =============================================================================
// VERIFY AND STATUS
// =============================================================================

it('keeps --read-only read-only by delegating it to the installer\'s own --verify', function () {
    $verifier = File::get(mailGatewayScript('verify-mail-gateway'));
    $readOnly = shellFunctionBody($verifier, 'run_read_only');

    expect($readOnly)->toContain('"${INSTALLER}" --verify');
    expect(executableSourceLines($readOnly))
        ->not->toContain('smtp_submit')
        ->not->toContain('postsuper')
        ->not->toContain('systemctl');

    // There is no default mode: the mutating acceptance is always asked for.
    $output = [];
    exec('bash '.escapeshellarg(mailGatewayScript('verify-mail-gateway')).' 2>&1', $output, $status);
    expect($status)->not->toBe(0);
    expect(implode("\n", $output))->toContain('the mutating acceptance is never a default');

    // Run for real, beside the installer it ships with, on a converged
    // gateway: the installer's own verdict, and nothing changed.
    $host = mailGatewayHost();

    try {
        // The installer's stability window is a real sleep, which its own
        // tests exercise; here it would only cost a second per run.
        @mkdir($host['scratch'].'/no-wait', 0o755);
        file_put_contents($host['scratch'].'/no-wait/sleep', "#!/bin/sh\nexit 0\n");
        chmod($host['scratch'].'/no-wait/sleep', 0o755);
        $env = ['PATH' => $host['scratch'].'/no-wait:'.$host['env']['PATH']];

        [$applied, $log] = mailGatewayRun($host, '--apply', $env);
        expect($applied)->toBe(0, $log);
        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayTree($host);

        [$verified, $report] = mailGatewayRun($host, '--read-only', $env, 'verify-mail-gateway');
        expect($verified)->toBe(0, $report);
        expect($report)
            ->toStartWith("install-mail-gateway --verify\n")
            ->toContain('PASS     runtime — enabled, stably running, listening on exactly the plan\'s loopback endpoints')
            ->toEndWith("SUMMARY  pass=12 missing=0 drift=0 conflict=0 deferred=0\n");

        // A hand edit is the installer's drift, and fails --read-only with it.
        file_put_contents($host['fs'].'/etc/postfix/main.cf', "# a hand edit\n", FILE_APPEND);
        [$drifted, $report] = mailGatewayRun($host, '--read-only', $env, 'verify-mail-gateway');
        expect($drifted)->toBe(1, $report);
        expect($report)
            ->toContain('DRIFT    file:/etc/postfix/main.cf — differs from the current render')
            ->toEndWith("SUMMARY  pass=11 missing=0 drift=1 conflict=0 deferred=0\n");

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('', '--read-only called a mutating command');
        // Exactly the hand edit changed: no file was added, removed or rewritten.
        $expected = $before;
        $expected['etc/postfix/main.cf'] = hash_file('sha256', $host['fs'].'/etc/postfix/main.cf');
        expect(mailGatewayTree($host))->toBe($expected);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('probes a sender at MAIL FROM and never names a recipient, even when the gateway wrongly accepts it', function (string $reply, string $verdict) {
    $probe = mailGatewayProbeFakeServer($reply);

    expect($probe['lines'])->toBe([
        'EHLO mail-gateway-verify',
        'MAIL FROM:<intruder@foreign.example>',
        'RSET',
        'QUIT',
    ]);
    expect($probe['output'])->toStartWith($verdict);

    // The session opens and closes its socket with a bare exec; whatever it
    // silenced for that must not stay silenced, or every FAIL the acceptance
    // reports after its first session would reach nobody.
    expect($probe['output'])->toEndWith('FAIL reported after the session');
})->with([
    'a gateway that refuses the sender' => ['554 5.7.1 <intruder@foreign.example>: Sender address rejected', 'refused mail 554'],
    'a broken gateway that accepts it' => ['250 2.1.0 Ok', 'accepted mail'],
]);

it('never submits a message to an outbound listener, and refuses before it even connects', function () {
    $scratch = mailGatewayScratch();
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    try {
        $plan = static fn (string $mode, ?array $route): string => mailRoutingJson(['schema_version' => 2, 'listeners' => [[
            'identity' => 'demo-shop',
            'environment_class' => 'production',
            'lifecycle' => 'planned',
            'listen' => ['host' => '127.0.0.1', 'port' => $port],
            'delivery_mode' => $mode,
            'sender' => ['allowed_domain' => 'demo-shop.example', 'default_from' => 'hello@demo-shop.example', 'bounce_domain' => 'bounce.demo-shop.example', 'reply_domain' => 'reply.demo-shop.example'],
            'route' => $route,
        ]]]);

        $submit = static fn (string $planFile): string => 'source '.escapeshellarg(mailGatewayScript('verify-mail-gateway'))
            .' && PLAN_FILE='.escapeshellarg($planFile)
            .' && if smtp_submit 127.0.0.1 '.$port.' hello@demo-shop.example someone@recipient.example token;'
            .' then echo queued; else echo "stage=${SMTP_STAGE} ${SMTP_REPLY}"; fi';

        // An outbound listener — and an endpoint the plan does not name at
        // all — are refused before a connection is opened.
        file_put_contents($scratch.'/outbound.json', $plan('outbound', ['kind' => 'direct']));
        file_put_contents($scratch.'/empty.json', mailRoutingJson(['schema_version' => 2, 'listeners' => []]));

        foreach (['outbound.json', 'empty.json'] as $planFile) {
            $output = (string) shell_exec('bash -c '.escapeshellarg($submit($scratch.'/'.$planFile)).' 2>&1');
            expect($output)->toContain("stage=refused 127.0.0.1:{$port} is not a capture or held listener of the plan");
        }

        stream_set_blocking($server, false);
        expect(@stream_socket_accept($server, 0))->toBeFalse('smtp_submit connected to an outbound listener');

        // The same endpoint as a held listener is connected to: the refusal
        // above is the outbound rule, not an unreachable port.
        stream_set_blocking($server, true);
        file_put_contents($scratch.'/held.json', $plan('held', null));
        $process = proc_open(['bash', '-c', $submit($scratch.'/held.json')], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);

        $client = stream_socket_accept($server, 10);
        expect($client)->not->toBeFalse('smtp_submit did not connect to a held listener');
        fclose($client);

        expect(trim((string) stream_get_contents($pipes[1])))->toStartWith('stage=connect');
        fclose($pipes[1]);
        proc_close($process);
    } finally {
        fclose($server);
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('accepts an outbound listener by sender isolation alone, and submits nothing to it', function () {
    $scratch = mailGatewayScratch();

    try {
        ['policy' => $policy, 'registry' => $registry] = mailRoutingTwoOutboundTargets();
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson($policy, $registry));

        // Every way to put a message on the wire, replaced by a recorder.
        $harness = 'source '.escapeshellarg(mailGatewayScript('verify-mail-gateway'))
            .' && PLAN_FILE='.escapeshellarg($scratch.'/plan.json')
            .' && smtp_submit() { echo "SUBMIT $*"; return 1; }'
            .' && check_outbound';
        $output = (string) shell_exec('bash -c '.escapeshellarg($harness).' 2>&1');

        expect($output)
            ->toContain('PASS E demo-books: outbound (direct) on 127.0.0.1:2598 — no message submitted')
            ->toContain('PASS E demo-shop: outbound (direct) on 127.0.0.1:2599 — no message submitted')
            ->not->toContain('SUBMIT');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }

    $verifier = File::get(mailGatewayScript('verify-mail-gateway'));

    // It runs as part of the acceptance, and sender isolation only ever probes.
    expect(shellFunctionBody($verifier, 'run_e2e'))->toContain('check_outbound');
    expect(shellFunctionBody($verifier, 'check_sender_isolation'))
        ->toContain('smtp_probe_sender "${host}" "${port}" "intruder@${other}"')
        ->not->toContain('smtp_submit');

    // A whole message goes only to capture and held listeners.
    foreach (['check_capture_delivery' => 'listeners capture', 'check_held' => 'listeners held', 'check_capture_retry' => 'listeners capture'] as $function => $source) {
        expect(shellFunctionBody($verifier, $function))->toContain('smtp_submit')->toContain($source);
    }

    expect(substr_count(executableSourceLines($verifier), 'smtp_submit "'))->toBe(3);
});

it('cleans up only what the acceptance created, and never empties the queue', function () {
    $code = executableSourceLines(File::get(mailGatewayScript('verify-mail-gateway')));

    foreach (['postqueue -f', 'postsuper -d ALL', 'postsuper -H ALL', 'postsuper -r ALL', 'postsuper -d -', 'postqueue -p', '/api/v1/messages" >/dev/null 2>&1 || true'] as $forbidden) {
        // The last one is allowed only as the token-scoped delete below.
        if (str_starts_with($forbidden, '/api')) {
            continue;
        }

        expect(str_contains($code, $forbidden))->toBeFalse("verify-mail-gateway runs {$forbidden}");
    }

    // Queue entries are removed by the exact ID Postfix named; Mailpit and
    // Mailtrap messages by this run's unique token.
    expect($code)
        ->toContain('postsuper -d "${id}"')
        ->toContain('postsuper -d "${SMTP_QUEUE_ID}" hold')
        ->toContain('postqueue -i "${id}" >/dev/null 2>&1 || true')
        ->toContain('/api/v1/search?query=${token}')
        ->toContain("SMTP_QUEUE_ID=\"\$(sed -n 's/.*queued as \\([0-9A-Za-z]\\{1,\\}\\).*/\\1/p' <<<\"\${SMTP_REPLY}\")\"");

    // Every acceptance is driven by the plan, not by a list of targets.
    expect($code)
        ->toContain('listeners capture')
        ->toContain('listeners held')
        ->toContain('listeners any')
        ->toContain('"${MAIL_ROUTING_CLI}" render-plan');

    // Mailpit is put back on every exit.
    expect(shellFunctionBody(File::get(mailGatewayScript('verify-mail-gateway')), 'cleanup'))
        ->toContain('systemctl start "${MAILPIT_UNIT}"');
});

it('prints status without a body, an address or a credential', function () {
    $status = executableSourceLines(File::get(mailGatewayScript('status-mail-gateway')));

    // A queue it could not read is unknown, never reported as empty.
    expect($status)
        ->toContain("printf '  unknown (jq not installed)\\n'")
        ->toContain("printf '  unknown (postqueue could not read the queue)\\n'");
    expect(strpos($status, 'command -v jq'))->toBeLessThan(strpos($status, 'postqueue -j'));

    expect($status)
        ->toContain("jq -r '.queue_name'")
        ->not->toContain('postcat')
        ->not->toContain('.recipients')
        ->not->toContain('.sender')
        ->not->toContain('postqueue -p')
        ->not->toMatch('/\b(systemctl (start|stop|restart|reload|enable|disable)|postsuper|postfix (reload|start|stop|flush))\b/');
});

// =============================================================================
// HOST BOOTSTRAP OWNS IT AT HOST SCOPE, AFTER MAIL CAPTURE, AND ONLY THERE
// =============================================================================

it('converges the gateway after mail capture, at host scope only', function () {
    $services = File::get(base_path('infrastructure/scripts/install-bootstrap-services'));
    $apply = shellFunctionBody($services, 'perform_apply');

    $capture = strpos($apply, 'converge_mail_capture');
    $gateway = strpos($apply, 'converge_mail_gateway');
    expect($capture)->not->toBeFalse();
    expect($gateway)->toBeGreaterThan($capture);

    // The plan gate runs before the first mutation, beside the other gates.
    expect(strpos($apply, 'apply_gate_mail_gateway'))->toBeLessThan(strpos($apply, 'trap on_apply_error ERR EXIT'));
    expect(shellFunctionBody($services, 'apply_gate_mail_gateway'))->toContain('target_scoped && return 0');

    // Reported after mail capture, and only when not target-scoped.
    $report = shellFunctionBody($services, 'report_child_contracts');
    expect(strpos($report, '"mail-gateway:install-mail-gateway"'))->toBeGreaterThan(strpos($report, '"mail-capture:verify-mail-capture"'));
    expect($report)->toMatch('/if ! target_scoped; then\s+report_child_state "mail-capture:verify-mail-capture"/');

    // Never the mutating acceptance.
    expect(executableSourceLines($services))->not->toContain('verify-mail-gateway');
    expect(executableSourceLines($services))->not->toContain('--e2e');
});

it('keeps target-scoped repair and provisioning away from the host-global gateway', function () {
    foreach (['provision-target', 'repair-target', 'prepare-host', 'bootstrap-host'] as $orchestrator) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$orchestrator))))
            ->not->toContain('mail-gateway')
            ->not->toContain('postfix');
    }
});

// =============================================================================
// THE ACCEPTANCE IS A LOW-LEVEL PRIMITIVE, NOT A WORKFLOW OF ITS OWN
// =============================================================================

it('leaves no trace of the one-time gateway acceptance workflow, or of its action', function () {
    expect(file_exists(base_path('.github/workflows/verify-staging-mail-gateway.yml')))->toBeFalse();
    expect(is_dir(base_path('.github/actions/verify-rateguru-mail-gateway')))->toBeFalse();

    // Nothing tracked still names it — no workflow, action, script, runbook,
    // roadmap or test other than this one.
    exec('git -C '.escapeshellarg(base_path()).' ls-files -z', $output, $status);
    expect($status)->toBe(0);

    $self = 'tests/Feature/Architecture/MailGatewayTest.php';
    $files = array_filter(explode("\0", implode("\n", $output)), static fn (string $path): bool => $path !== '' && $path !== $self);

    foreach ($files as $path) {
        if (! is_file(base_path($path)) || str_starts_with($path, 'tests/.pest/')) {
            continue;
        }

        $source = (string) file_get_contents(base_path($path));

        foreach (['verify-staging-mail-gateway', 'verify-rateguru-mail-gateway', 'Verify staging mail gateway'] as $needle) {
            expect(str_contains($source, $needle))->toBeFalse("{$path} still refers to {$needle}");
        }
    }
});

it('keeps no composite action that nothing calls', function () {
    $callers = collect([...(glob(base_path('.github/workflows/*.yml')) ?: []), ...(glob(base_path('.github/actions/*/action.yml')) ?: [])])
        ->map(static fn (string $path): string => executableSourceLines((string) file_get_contents($path)))
        ->implode("\n");

    foreach (glob(base_path('.github/actions/*'), GLOB_ONLYDIR) ?: [] as $action) {
        $name = basename($action);

        expect(preg_match('#uses:\s*\./\.github/actions/'.preg_quote($name, '#').'\s*$#m', $callers))
            ->toBe(1, ".github/actions/{$name} has no caller");
    }
});

it('keeps verify-mail-gateway --e2e as the low-level acceptance primitive, run on the host by hand', function () {
    $verifier = mailGatewayScript('verify-mail-gateway');

    expect(is_executable($verifier))->toBeTrue();
    expect(requiredCliManifestNames())->toContain('verify-mail-gateway');

    exec('bash '.escapeshellarg($verifier).' --help 2>&1', $output, $status);
    expect($status)->toBe(0);
    expect(implode("\n", $output))->toContain('verify-mail-gateway --e2e');

    // No workflow or action drives it any more.
    foreach ([...(glob(base_path('.github/workflows/*.yml')) ?: []), ...(glob(base_path('.github/actions/*/action.yml')) ?: [])] as $path) {
        expect(executableSourceLines((string) file_get_contents($path)))
            ->not->toContain('verify-mail-gateway', basename(dirname($path)).'/'.basename($path).' runs the mail gateway acceptance');
    }
});

it('never runs the mutating acceptance from ordinary preparation', function () {
    foreach ([
        '.github/workflows/prepare-staging-host.yml',
        '.github/workflows/prepare-production-host.yml',
        '.github/actions/prepare-rateguru-host/action.yml',
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/bootstrap-host',
        'infrastructure/scripts/install-bootstrap-services',
    ] as $path) {
        expect(executableSourceLines(File::get(base_path($path))))->not->toContain('verify-mail-gateway');
    }
});

// =============================================================================
// WHAT DID NOT MOVE
// =============================================================================

it('keeps tits-guru planned and the production environment untouched while its committed mail is outbound', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
    $policy = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');
    expect($policy['targets']['tits-guru']['delivery_mode'])->toBe('outbound');
    expect($policy['targets']['tits-guru']['outbound'])->toBe(['kind' => 'direct']);

    // The staging template names the gateway; production's does not move.
    expect(envFileValues('infrastructure/templates/environment/staging.env.example')['MAIL_PORT'])->toBe('2525');

    $production = envFileValues('infrastructure/templates/environment/production.env.example');
    expect($production['MAIL_MAILER'])->toBe('');
    expect($production)->not->toHaveKey('MAIL_PORT');

    foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS'] as $key) {
        expect(envFileValues('infrastructure/templates/environment/tits-guru.env.example')[$key])->toBe('');
    }
});

it('records the gateway as accepted on the real host, and the direct outbound route as active and production-accepted on it', function () {
    $roadmap = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/ROADMAP.md')));

    expect($roadmap)
        ->toContain('**8.4B.2 Local mail gateway and the staging capture route — IMPLEMENTED AND ACCEPTED on the real staging host.**')
        ->toContain('The operator then changed the staging `shared/.env` to `MAIL_PORT=2525`, staging was redeployed, and a real Laravel password-reset email arrived in Mailpit and in Mailtrap Local.')
        ->toContain('**8.4B.3 Self-hosted direct outbound SMTP capability — IMPLEMENTED, not activated.**')
        ->toContain('*Unchanged on purpose:* `tits-guru` is still `held` and `lifecycle=planned`, the real `mail-outbound.json` keeps direct delivery disabled')
        ->toContain('no email was sent to the public Internet')
        ->toContain('**8.4B.4.1 Production mail identity foundation — IMPLEMENTED, nothing activated.**')
        ->toContain('**8.4B.4.2a DKIM signing foundation — PRODUCTION-ACCEPTED.**')
        ->toContain('**8.4B.4.2b Guarded outbound activation and the first real delivery — IMPLEMENTED and PRODUCTION-ACCEPTED 2026-10-08.**')
        ->toContain('**8.4B.4.2 Production outbound activation — PRODUCTION-ACCEPTED 2026-10-08.**')
        // The backup gate stays; acceptance moves to after activation.
        ->toContain('`backup-cycle` runs only for a `lifecycle=active` target')
        ->toContain('That gate is deliberate and is not weakened to take a backup early.')
        ->toContain('This is where the first real `backup-cycle --target tits-guru` runs — after activation and the first real production deploy, before any public traffic')
        ->not->toContain('implemented but not accepted')
        ->not->toContain('not yet installed or accepted on the real host');

    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/mail-gateway.md')));

    expect($runbook)
        ->toContain('| Gateway on the staging host | **Installed and accepted**')
        ->toContain('| Staging application mail | **Through the gateway**: the host\'s `shared/.env` says `MAIL_PORT=2525`')
        ->toContain('| `tits-guru` — committed policy | `lifecycle=planned`; **outbound requested**: `delivery_mode=outbound` with `outbound.kind=direct` |')
        ->toContain('| `tits-guru` — real host | **Outbound**, activated on 2026-10-08 through `activate-mail-outbound`')
        ->toContain('**Implemented and active on the shared host** for `tits-guru` since its guarded activation on 2026-10-08')
        ->toContain('| Production outbound delivery | **Production-accepted** on 2026-10-08')
        // Inbound mail is part of this one Postfix, and active on no host until its guarded activation.
        ->toContain('| Inbound mail (bounces, replies, support) | **Implemented on this Postfix, not active on any host**')
        ->not->toContain('/etc/postfix-inbound')
        ->not->toContain('rateguru-mail-inbound.service')
        ->not->toContain('**None yet**: no route to the Internet exists on any host')
        ->not->toContain('before `main` reaches `develop`');
});

// =============================================================================
// ITS NAME: THE HOST'S REVIEWED MTA HOSTNAME
// =============================================================================

it('calls itself by the reviewed MTA hostname, which a delivered message carries in its Received hop and HELO', function () {
    // The committed contract names the host: mta1.tits.guru.
    expect(mailGatewayMainParameters(mailGatewayRender()['main'])['myhostname'])->toBe('mta1.tits.guru');

    // And an outbound route greets with the same name.
    $request = mailActivationRequest();
    $render = mailGatewayRender($request['routing'], null, $request['outbound']);
    expect(mailGatewayMainParameters($render['main'])['myhostname'])->toBe('mta1.tits.guru');
    $transport = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', 'rateguru-outbound-tits-guru');
    expect($transport['options']['smtp_helo_name'])->toBe('mta1.tits.guru');

    // The non-deliverable fallback only when no hostname is reviewed, and
    // direct delivery is off.
    expect(mailGatewayMainParameters(mailGatewayRender(outbound: mailGatewayOutboundContract(false, ''))['main'])['myhostname'])->toBe('mail-gateway.rateguru.invalid');

    // Direct delivery with no reviewed name never falls back: refused.
    $scratch = mailGatewayScratch();
    try {
        file_put_contents($scratch.'/outbound.json', mailRoutingJson(mailGatewayOutboundContract(true, '')));
        exec('bash -c '.escapeshellarg('source '.escapeshellarg(mailGatewayScript()).' && gateway_hostname '.escapeshellarg($scratch.'/outbound.json')).' 2>&1', $output, $status);
        expect($status)->not->toBe(0);
        expect(implode("\n", $output))->not->toContain('mail-gateway.rateguru.invalid');
    } finally {
        removeScratchDir($scratch);
    }

    // Never from DNS, the OS or /etc/hosts.
    $code = executableSourceLines(shellFunctionBody(File::get(mailGatewayScript()), 'gateway_hostname'));
    foreach (['hostname -', 'uname', '/etc/hosts', '/etc/hostname', 'dig', 'getent', 'host '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("gateway_hostname reads {$forbidden}");
    }
});

it('reads its own name back, and refuses any other', function () {
    $host = mailGatewayEstablishedHost();

    try {
        $main = $host['fs'].'/etc/postfix/main.cf';
        file_put_contents($main, str_replace('myhostname = mta1.tits.guru', 'myhostname = mail-gateway.rateguru.invalid', File::get($main)));

        [$status, $report] = mailGatewayRun($host, '--verify');

        expect($status)->toBe(1, $report);
        expect($report)
            ->toContain('DRIFT    file:/etc/postfix/main.cf — differs from the current render')
            ->toContain('CONFLICT config:installed — myhostname is "mail-gateway.rateguru.invalid", not the host\'s reviewed MTA identity "mta1.tits.guru"');
    } finally {
        mailGatewayCleanup($host);
    }
});
