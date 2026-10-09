<?php

/**
 * The host's inbound state moves only by one of three transitions — install
 * (absent to disabled), enable (disabled to enabled on one address) and
 * disable (enabled to disabled) — each with the one-use authorization
 * activate-mail-inbound writes after its proof, consumed by the gateway's
 * installer. Every test runs the REAL installer against the simulated host
 * (mailInboundHost).
 */

/** The gateway's apply on HOST. @return array{0: int, 1: string} */
function mailGatewayInboundApply(array $host): array
{
    return mailInboundHostRun($host, 'install-mail-gateway', ['--apply']);
}

it('makes each transition only with its authorization, consumes it, and reloads Postfix — never a restart', function (string $from, string $direction, ?string $address, string $to) {
    $host = mailInboundHost(['installed' => $from]);

    try {
        // No authorization: an ordinary apply keeps the recorded state.
        [$status, $output] = mailGatewayInboundApply($host);
        expect($status)->toBe(0, $output);
        expect(mailInboundApplied($host)['public_smtp'] ?? 'absent')->toBe($from);

        if ($direction === 'install') {
            // The store comes first: inbound mail is never rendered without it.
            [$status, $output] = mailInboundHostRun($host, 'install-mail-inbound', ['--apply']);
            expect($status)->toBe(0, $output);
        }

        $path = mailInboundAuthorize($host, $direction, $address);
        file_put_contents($host['log'].'/mutations.log', '');

        [$status, $output] = mailGatewayInboundApply($host);
        expect($status)->toBe(0, $output);
        expect($output)->toContain("the inbound {$direction} ")->toContain('is authorized by activate-mail-inbound — consuming that one-use authorization');

        expect(mailInboundApplied($host))->toMatchArray(['public_smtp' => $to, 'address' => $to === 'enabled' ? $address : null]);
        expect(file_exists($path))->toBeFalse();
        expect(mailInboundFile($host, '/var/lib/rateguru-mail-gateway/consumed-inbound-transition-authorizations'))->toContain(" {$direction} ");

        expect(mailInboundLog($host, 'mutations.log'))
            ->toContain('systemctl reload postfix@-.service')
            ->not->toContain('systemctl restart postfix@-.service')
            ->not->toContain('systemctl stop postfix@-.service');

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->toBe(0, $output);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'install' => ['absent', 'install', null, 'disabled'],
    'enable' => ['disabled', 'enable', '1.2.3.4', 'enabled'],
    'disable' => ['enabled', 'disable', null, 'disabled'],
]);

it('keeps the loopback listener, the delivery and the store when public SMTP is disabled: mail already queued is still delivered', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundTransition($host, 'disable');
        $main = mailInboundMainParameters(mailInboundFile($host, '/etc/postfix/main.cf'));
        $services = mailInboundServices(mailInboundFile($host, '/etc/postfix/master.cf'));

        expect($main['virtual_transport'])->toBe('virtual');
        expect($services)->toHaveKey('127.0.0.1:2580/inet')->toHaveKey('virtual/unix')->not->toHaveKey('1.2.3.4:25/inet');
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-inbound/store.img'))->toBeTrue();
    } finally {
        mailInboundCleanup($host);
    }
});

it('never honours an authorization that does not permit exactly this transition, and changes nothing', function (string $from, string $direction, array $changes, ?int $mode, string $reason) {
    $host = mailInboundHost(['installed' => $from]);

    try {
        $before = [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host)];
        mailInboundAuthorize($host, $direction, $direction === 'enable' ? '1.2.3.4' : null, $changes, $mode);

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--check']);
        expect($output)->toContain('CONFLICT inbound:transition')->toContain($reason);

        [$status, $output] = mailGatewayInboundApply($host);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('a pending inbound transition authorization is not honoured')->toContain('the recorded inbound state is kept');
        expect([mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host)])->toBe($before);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'enable on a host without inbound mail' => ['absent', 'enable', ['from.public_smtp' => 'disabled'], 0o600, 'it was issued for a host whose inbound state is "disabled", and this host records "absent"'],
    'install twice' => ['disabled', 'install', [], 0o600, 'it was issued for a host whose inbound state is "absent", and this host records "disabled"'],
    'disable a disabled host' => ['disabled', 'disable', ['from.address' => '1.2.3.4'], 0o600, 'it was issued for a host whose inbound state is "enabled", and this host records "disabled"'],
    'disable another address' => ['enabled', 'disable', ['from.address' => '5.6.7.8'], 0o600, 'and this host records "enabled"'],
    'a direction that moves elsewhere' => ['disabled', 'enable', ['to.public_smtp' => 'disabled'], 0o600, 'it names enable, which moves disabled to enabled, not "disabled" to "disabled"'],
    'enable on no address' => ['disabled', 'enable', ['to.address' => 'mx1.tits.guru'], 0o600, 'it enables public SMTP on no IPv4 address'],
    'an unknown direction' => ['disabled', 'open', [], 0o600, 'it authorizes "open", which is not install, enable or disable'],
    'another kind' => ['disabled', 'enable', ['kind' => 'rateguru-mail-gateway-transition-authorization'], 0o600, 'it is not an inbound transition authorization of schema 1'],
    'expired' => ['disabled', 'enable', ['created_at' => 1000, 'expires_at' => 1900], 0o600, 'it has expired, or claims a lifetime no authorization has'],
    'a lifetime of a day' => ['disabled', 'enable', ['expires_at' => time() + 86400], 0o600, 'it has expired, or claims a lifetime no authorization has'],
    'no nonce' => ['disabled', 'enable', ['nonce' => 'x'], 0o600, 'it carries no nonce'],
    'readable by others' => ['disabled', 'enable', [], 0o644, 'not '],
]);

it('honours an authorization once, never twice', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $path = mailInboundAuthorize($host, 'enable', '1.2.3.4');
        $authorization = (string) file_get_contents($path);
        [$status, $output] = mailGatewayInboundApply($host);
        expect($status)->toBe(0, $output);

        mailInboundTransition($host, 'disable');

        // The very same authorization, put back.
        file_put_contents($path, $authorization);
        chmod($path, 0o600);
        [$status, $output] = mailGatewayInboundApply($host);
        expect($output)->toContain('this authorization was already used — one is consumed by the transition it permits, and never honoured twice');
        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
    } finally {
        mailInboundCleanup($host);
    }
});

it('neither installs nor enables inbound mail for a bundle that does not request public SMTP, and still lets it close', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        file_put_contents($host['bundle'].'/infrastructure/config/mail-inbound.json', mailRoutingJson(mailInboundContractWith(['receiver.public_smtp' => 'disabled'])));

        mailInboundTransition($host, 'disable');
        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');

        mailInboundAuthorize($host, 'enable', '1.2.3.4');
        [$status, $output] = mailGatewayInboundApply($host);
        expect($output)->toContain('this bundle does not request public SMTP, so it neither installs nor enables it');
        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
    } finally {
        mailInboundCleanup($host);
    }
});

it('reports a pending authorization in --verify, and judges the record itself', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        mailInboundAuthorize($host, 'enable', '1.2.3.4');

        [$status, $output] = mailInboundHostRun($host, 'install-mail-gateway', ['--verify']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('DRIFT    inbound:authorization — an unused inbound transition authorization is waiting');
        expect(mailInboundFile($host, '/etc/postfix/master.cf'))->not->toContain('1.2.3.4:25');
    } finally {
        mailInboundCleanup($host);
    }
});

it('restores the previous configuration and record when Postfix will not reload with the public listener — by a reload, never a restart', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $before = [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host)];
        mailInboundAuthorize($host, 'enable', '1.2.3.4');
        touch($host['toggles'].'/reload-fail');
        file_put_contents($host['log'].'/mutations.log', '');

        [$status, $output] = mailGatewayInboundApply($host);
        expect($status)->not->toBe(0);
        expect($output)->toContain('rollback complete: configuration and service state restored');
        expect([mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host)])->toBe($before);
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('systemctl stop postfix@-.service');
        expect(file_exists($host['state'].'/postfix@-.service.active'))->toBeTrue();
    } finally {
        mailInboundCleanup($host);
    }
});
