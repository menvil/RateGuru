<?php

/**
 * The one-use inbound transition authorization the gateway's installer
 * consumes: one that does not permit exactly the transition this host can
 * make — another state, address or direction, expired, without a nonce, or
 * readable by others — is never honoured and changes nothing, and one left
 * waiting is reported by --verify.
 *
 * Every test runs the REAL installer against the simulated host
 * (mailInboundHost).
 */
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
    'readable by others' => ['disabled', 'enable', [], 0o644, 'mode 600 — an authorization anyone else could have written is never honoured'],
]);

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
