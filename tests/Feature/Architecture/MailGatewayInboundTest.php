<?php

/**
 * The mail gateway's inbound part: the host's one Postfix receiving mail for
 * the inbound plan through listeners and a delivery route of its own, rendered
 * by install-mail-gateway — never by anything else — from `mail-inbound
 * render-receiver` and the host's recorded inbound state.
 *
 * Every test runs the REAL installer against the simulated host
 * (mailInboundHost). The read-back of every receiver requirement is
 * MailGatewayInboundRequirementsTest's; the transitions and their
 * authorization are MailGatewayInboundTransitionTest's and
 * MailGatewayInboundAuthorizationTest's; whether the render behaves on a real
 * Postfix is MailInboundRealPostfixTest's.
 */

/** The configuration a gateway apply renders with no inbound input at all. */
function mailGatewayInboundAbsentRender(array $host): array
{
    return [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf')];
}

// =============================================================================
// A HOST THAT NEVER INSTALLED INBOUND MAIL: NOTHING CHANGES
// =============================================================================

it('renders nothing inbound on a host that records no inbound mail, though the committed contract requests public SMTP', function () {
    $host = mailInboundHost();

    try {
        expect(json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true)['receiver']['public_smtp'])->toBe('enabled');

        [$main, $master] = mailGatewayInboundAbsentRender($host);
        expect(mailInboundApplied($host))->toBeNull();

        // Exactly what a render with no inbound input is: byte for byte what the
        // gateway rendered before inbound mail existed.
        $render = mailGatewayRender(mailPreActivationPolicy()['routing'], null, mailPreActivationPolicy()['outbound']);
        expect($main)->toBe($render['main']);
        expect($master)->toBe($render['master']);

        expect(mailInboundMainParameters($main)['virtual_transport'])->toStartWith('error:');
        expect($main)->not->toContain('virtual_mailbox');
        expect($master)->not->toMatch('/^\S+:25\s/m')->not->toContain('2580')->not->toContain('rateguru-inbound');
        expect(file_exists($host['fs'].'/etc/postfix/rateguru-inbound-recipients.regexp'))->toBeFalse();

        // An ordinary apply — Prepare, bootstrap, repair — installs none of it.
        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--apply']);
        expect($status)->toBe(0, $output);
        expect(mailGatewayInboundAbsentRender($host))->toBe([$main, $master]);
        expect(mailInboundApplied($host))->toBeNull();

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->toBe(0, $output);
        expect($output)->not->toContain('inbound:');
    } finally {
        mailInboundCleanup($host);
    }
});

// =============================================================================
// INSTALLED: THE LOOPBACK LISTENER AND THE DELIVERY, NO PUBLIC LISTENER
// =============================================================================

it('installs inbound mail as listeners and a delivery route of the same Postfix, every setting of the listeners their own', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $main = mailInboundMainParameters(mailInboundFile($host, '/etc/postfix/main.cf'));
        $services = mailInboundServices(mailInboundFile($host, '/etc/postfix/master.cf'));

        // Delivery: virtual(8), for exactly the plan's domains, into the store.
        expect($main)->toMatchArray([
            'virtual_transport' => 'virtual',
            'virtual_mailbox_domains' => 'bounce.tx.tits.guru, rateguru-mail-inbound.invalid, reply.tits.guru, tits.guru',
            'virtual_mailbox_maps' => 'regexp:/etc/postfix/rateguru-inbound-recipients.regexp',
            'virtual_mailbox_base' => '/var/lib/rateguru-mail-inbound/store',
            'virtual_uid_maps' => 'static:4321',
            'virtual_gid_maps' => 'static:4321',
            'virtual_minimum_uid' => '4321',
            'virtual_mailbox_limit' => '10485760',
        ]);

        // Everything else main.cf says is unchanged: one Postfix, one name,
        // loopback for every listener that does not name its own address.
        expect($main)->toMatchArray([
            'myhostname' => 'mta1.tits.guru',
            'inet_interfaces' => '127.0.0.1',
            'inet_protocols' => 'ipv4',
            'mydestination' => '',
            'relay_domains' => '',
            'relayhost' => '',
            'content_filter' => '',
            'smtpd_client_restrictions' => 'permit_mynetworks, reject',
            'smtpd_relay_restrictions' => 'permit_mynetworks, reject',
        ]);
        foreach (['default_transport', 'relay_transport', 'local_transport'] as $transport) {
            expect($main[$transport])->toStartWith('error:');
        }

        // The loopback listener: everything a public receiver needs, as its own
        // overrides — never inheriting the submission listeners' settings.
        expect($services['127.0.0.1:2580/inet'])->toMatchArray(['command' => 'smtpd', 'maxproc' => '2']);
        expect($services['127.0.0.1:2580/inet']['options'])->toBe([
            'syslog_name' => 'postfix/rateguru-inbound-loopback',
            'smtpd_client_restrictions' => '',
            'smtpd_helo_restrictions' => '',
            'smtpd_sender_restrictions' => '',
            'smtpd_relay_restrictions' => 'reject_unauth_destination',
            'smtpd_recipient_restrictions' => 'reject_unlisted_recipient',
            'smtpd_reject_unlisted_recipient' => 'yes',
            'smtpd_data_restrictions' => '',
            'smtpd_helo_required' => 'yes',
            'smtpd_sasl_auth_enable' => 'no',
            'smtpd_milters' => '',
            'content_filter' => '',
            'smtpd_discard_ehlo_keywords' => 'etrn',
            'smtpd_etrn_restrictions' => 'reject',
            'smtpd_recipient_limit' => '1',
            'smtpd_client_connection_count_limit' => '5',
            'smtpd_client_connection_rate_limit' => '30',
            'smtpd_client_message_rate_limit' => '60',
            'smtpd_client_event_limit_exceptions' => '',
            'message_size_limit' => '10485760',
            'queue_minfree' => '2147483648',
            'myhostname' => 'mx1.tits.guru',
            'cleanup_service_name' => 'rateguru-inbound-cleanup',
            'rewrite_service_name' => 'rateguru-inbound-rewrite',
        ]);

        // Its own cleanup — which signs, checks and routes nothing — and its own
        // address rewriting, which makes a bare <Postmaster> the host's.
        expect($services['rateguru-inbound-cleanup/unix'])->toMatchArray(['command' => 'cleanup']);
        expect($services['rateguru-inbound-cleanup/unix']['options'])->toBe([
            'syslog_name' => 'postfix/rateguru-inbound-cleanup',
            'rewrite_service_name' => 'rateguru-inbound-rewrite',
            'message_size_limit' => '10485760',
            'header_checks' => '',
            'nested_header_checks' => '',
            'body_checks' => '',
        ]);
        expect($services['rateguru-inbound-rewrite/unix'])->toMatchArray(['command' => 'trivial-rewrite']);
        expect($services['rateguru-inbound-rewrite/unix']['options']['myorigin'])->toBe('rateguru-mail-inbound.invalid');
        expect($services['virtual/unix'])->toMatchArray(['command' => 'virtual', 'options' => []]);

        // No public listener, and still no SMTP client but the plan's.
        expect(array_keys(array_filter($services, static fn (array $s): bool => $s['type'] === 'inet')))
            ->toBe(['127.0.0.1:2525/inet', '127.0.0.1:2526/inet', '127.0.0.1:2580/inet']);
        expect(array_keys(array_filter($services, static fn (array $s): bool => in_array($s['command'], ['smtp', 'lmtp', 'local', 'pipe'], true))))
            ->toBe(['rateguru-capture-staging-main/unix']);

        expect(mailInboundApplied($host))->toBe(['address' => null, 'kind' => 'rateguru-mail-gateway-applied-inbound', 'public_smtp' => 'disabled', 'schema_version' => 1]);
    } finally {
        mailInboundCleanup($host);
    }
});

it('renders the recipient table from mail-inbound render-receiver, and restates no rule', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $table = mailInboundFile($host, '/etc/postfix/rateguru-inbound-recipients.regexp');
        $receiver = mailInboundJson(['render-receiver']);

        $rows = array_values(array_filter(preg_split('/\R/', $table), static fn (string $line): bool => str_starts_with($line, '/')));
        expect($rows)->toBe(array_map(static fn (array $r): string => "/{$r['pattern']}/ {$r['mailbox']}", $receiver['recipients']));
        expect(array_column($receiver['recipients'], 'mailbox'))->each->toEndWith('/');
    } finally {
        mailInboundCleanup($host);
    }
});

it('leaves every submission listener exactly as it was: routed by its own filter, never judged against the inbound table', function () {
    $absent = mailInboundHost();
    $installed = mailInboundHost(['installed' => 'disabled']);
    $enabled = mailInboundHost(['installed' => 'enabled']);

    try {
        $outbound = mailInboundOutboundConfiguration($absent);
        expect(mailInboundOutboundConfiguration($installed))->toBe($outbound);
        expect(mailInboundOutboundConfiguration($enabled))->toBe($outbound);

        $services = mailInboundServices(mailInboundFile($enabled, '/etc/postfix/master.cf'));
        foreach (['127.0.0.1:2525/inet', '127.0.0.1:2526/inet'] as $listener) {
            expect($services[$listener]['options']['smtpd_reject_unlisted_recipient'])->toBe('no');
            expect($services[$listener]['options'])->not->toHaveKey('rewrite_service_name')->not->toHaveKey('queue_minfree');
            expect($services[$listener]['options']['content_filter'] ?? '')->not->toBe('virtual:');
        }
    } finally {
        mailInboundCleanup($absent);
        mailInboundCleanup($installed);
        mailInboundCleanup($enabled);
    }
});

// =============================================================================
// ENABLED: ONE PUBLIC LISTENER, ON THE RECORDED ADDRESS
// =============================================================================

it('opens exactly one public listener, on the recorded IPv4 address and port 25, with STARTTLS from the operator certificate', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        $services = mailInboundServices(mailInboundFile($host, '/etc/postfix/master.cf'));
        $public = $services['1.2.3.4:25/inet'];

        expect($public)->toMatchArray(['command' => 'smtpd', 'maxproc' => '20']);
        expect($public['options'])->toMatchArray([
            'syslog_name' => 'postfix/rateguru-inbound',
            'smtpd_relay_restrictions' => 'reject_unauth_destination',
            'smtpd_recipient_restrictions' => 'reject_unlisted_recipient',
            'smtpd_sasl_auth_enable' => 'no',
            'smtpd_tls_security_level' => 'may',
            'smtpd_tls_chain_files' => '/etc/rateguru-mail-inbound/tls/privkey.pem,/etc/rateguru-mail-inbound/tls/fullchain.pem',
            'smtpd_tls_protocols' => '>=TLSv1.2',
            'smtpd_tls_mandatory_protocols' => '>=TLSv1.2',
            'smtpd_tls_loglevel' => '0',
            'myhostname' => 'mx1.tits.guru',
        ]);
        // The same overrides as the loopback listener, and TLS besides.
        expect(array_diff_key($public['options'], ['syslog_name' => 1, 'smtpd_tls_security_level' => 1, 'smtpd_tls_chain_files' => 1, 'smtpd_tls_protocols' => 1, 'smtpd_tls_mandatory_protocols' => 1, 'smtpd_tls_loglevel' => 1, 'smtpd_tls_received_header' => 1]))
            ->toBe(array_diff_key($services['127.0.0.1:2580/inet']['options'], ['syslog_name' => 1]));

        // The only listener off loopback; nothing on 0.0.0.0, IPv6, 465 or 587.
        expect(array_values(array_filter(array_keys($services), static fn (string $s): bool => str_ends_with($s, '/inet') && ! str_starts_with($s, '127.'))))
            ->toBe(['1.2.3.4:25/inet']);
        expect(mailInboundMainParameters(mailInboundFile($host, '/etc/postfix/main.cf'))['inet_protocols'])->toBe('ipv4');

        expect(mailInboundApplied($host))->toBe(['address' => '1.2.3.4', 'kind' => 'rateguru-mail-gateway-applied-inbound', 'public_smtp' => 'enabled', 'schema_version' => 1]);

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('PASS     inbound:applied')->toContain('public inbound SMTP is enabled on 1.2.3.4:25');
    } finally {
        mailInboundCleanup($host);
    }
});

// =============================================================================
// AN ORDINARY APPLY KEEPS WHAT THE HOST RECORDS
// =============================================================================

it('keeps an enabled host enabled on an ordinary apply, and reloads nothing it does not change', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        $before = [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host)];

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--apply']);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('gateway configuration unchanged');

        expect([mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host)])->toBe($before);
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('systemctl reload')->not->toContain('systemctl restart')->not->toContain('systemctl stop');
    } finally {
        mailInboundCleanup($host);
    }
});

it('refuses, changing nothing, when this bundle no longer requests public SMTP on a host where it is open', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        $contract = mailInboundContractWith(['receiver.public_smtp' => 'disabled']);
        file_put_contents($host['bundle'].'/infrastructure/config/mail-inbound.json', mailRoutingJson($contract));
        $before = mailInboundOutboundConfiguration($host).mailInboundFile($host, '/etc/postfix/master.cf');

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--apply']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('public inbound SMTP is open on this host on 1.2.3.4:25, and this bundle does not request it — an ordinary apply never closes it: the inbound rollback (activate-mail-inbound --rollback) does. Nothing was changed');
        expect(mailInboundOutboundConfiguration($host).mailInboundFile($host, '/etc/postfix/master.cf'))->toBe($before);
        expect(mailInboundApplied($host)['public_smtp'])->toBe('enabled');

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--check']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('CONFLICT inbound:request');
    } finally {
        mailInboundCleanup($host);
    }
});

it('refuses a recorded inbound state it cannot trust, rather than guessing whether TCP 25 is open', function (string $content) {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        file_put_contents($host['fs'].'/var/lib/rateguru-mail-gateway/applied-inbound.json', $content);
        $before = mailInboundFile($host, '/etc/postfix/master.cf');

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--apply']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('the recorded inbound state (/var/lib/rateguru-mail-gateway/applied-inbound.json) is incomplete, unreadable or on a gateway not marked installed');
        expect(mailInboundFile($host, '/etc/postfix/master.cf'))->toBe($before);

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('CONFLICT inbound:applied');
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'not JSON' => ['{"public_smtp": "enabled"'],
    'enabled with no address' => ['{"address":null,"kind":"rateguru-mail-gateway-applied-inbound","public_smtp":"enabled","schema_version":1}'],
    'another kind' => ['{"address":"1.2.3.4","kind":"rateguru-mail-inbound-applied","public_smtp":"enabled","schema_version":1}'],
    'an extra property' => ['{"address":"1.2.3.4","kind":"rateguru-mail-gateway-applied-inbound","public_smtp":"enabled","schema_version":1,"force":true}'],
]);

it('renders no inbound mail without its store account, and changes nothing', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        unlink($host['state'].'/store-user');
        $before = mailInboundFile($host, '/etc/postfix/master.cf');

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--apply']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('the inbound store account rateguru-mail-inbound does not exist — install-mail-inbound --apply creates the store before inbound mail is installed; nothing was rendered');
        expect(mailInboundFile($host, '/etc/postfix/master.cf'))->toBe($before);
    } finally {
        mailInboundCleanup($host);
    }
});

it('names no target, domain, address or limit in the inbound render: everything comes from the plan and the host record', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/install-mail-gateway')));

    // postmaster is RFC 5321's mailbox at every domain, not a target's.
    foreach (['tits', 'mx1.', 'bounce.tx', 'reply.', '1.2.3.4', '10485760', '2147483648', 'support@'] as $literal) {
        expect(str_contains($code, $literal))->toBeFalse("install-mail-gateway names {$literal}");
    }
});
