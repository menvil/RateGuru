<?php

use Illuminate\Support\Facades\File;

/**
 * install-mail-inbound: the host's recorded public SMTP state, which only a
 * one-use authorization moves, and only with a usable certificate on an address
 * the host has.
 *
 * Every test runs the shipped installer against a simulated host
 * (mailInboundHost) whose systemd, mount, volume tools, store account,
 * Postfix tools, ss, ip and openssl are stubs that read back what the installer
 * wrote. The rules the receiver applies are mail-inbound's: nothing here
 * restates one.
 */

// =============================================================================
// THE HOST'S RECORDED STATE: ONLY AN AUTHORIZATION MOVES IT
// =============================================================================

it('keeps public SMTP disabled on an ordinary apply, though the committed contract requests it', function () {
    $host = mailInboundHost(['tls' => true]);

    try {
        expect(json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true)['receiver']['public_smtp'])->toBe('enabled');

        mailInboundInstalled($host);
        mailInboundInstalled($host);

        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
        expect(mailInboundFile($host, '/etc/postfix-inbound/master.cf'))->not->toContain(':25 inet');
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('ufw');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('enables public SMTP on exactly the authorized address, and consumes the authorization', function () {
    $host = mailInboundHost(['tls' => true]);

    try {
        mailInboundInstalled($host);
        mailInboundAuthorize($host, 'enable');
        $nonce = json_decode(mailInboundFile($host, '/var/lib/rateguru-mail-inbound/transition-authorization.json'), true)['nonce'];

        $output = mailInboundInstalled($host);

        expect($output)->toContain('the enable of public SMTP is authorized by activate-mail-inbound');
        expect(mailInboundApplied($host))->toMatchArray(['public_smtp' => 'enabled', 'address' => '1.2.3.4']);

        $inet = array_values(array_filter(mailInboundServices(mailInboundFile($host, '/etc/postfix-inbound/master.cf')), static fn (array $s): bool => $s['type'] === 'inet'));
        expect($inet)->toBe([
            ['name' => '127.0.0.1:2580', 'type' => 'inet', 'maxproc' => '2', 'command' => 'smtpd'],
            ['name' => '1.2.3.4:25', 'type' => 'inet', 'maxproc' => '20', 'command' => 'smtpd'],
        ]);
        expect(mailInboundMainParameters(mailInboundFile($host, '/etc/postfix-inbound/main.cf')))->toMatchArray([
            'smtpd_tls_security_level' => 'may',
            'smtpd_tls_chain_files' => '/etc/rateguru-mail-inbound/tls/privkey.pem, /etc/rateguru-mail-inbound/tls/fullchain.pem',
        ]);

        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-inbound/transition-authorization.json'))->toBeFalse();
        expect(mailInboundFile($host, '/var/lib/rateguru-mail-inbound/consumed-transition-authorizations'))->toContain($nonce);

        // A later ordinary apply from a stale bundle requesting nothing never
        // closes it: it stops before touching the running receiver.
        $contract = json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true);
        $contract['receiver']['public_smtp'] = 'disabled';
        file_put_contents($host['scratch'].'/inbound.json', mailRoutingJson($contract));
        file_put_contents($host['log'].'/mutations.log', '');

        [$status, $stale] = mailInboundRunInstaller($host, '--apply', ['RATEGURU_MAILINBOUND_INBOUND_FILE' => $host['scratch'].'/inbound.json']);

        expect($status)->not->toBe(0);
        expect($stale)->toContain('public SMTP is enabled on this host, but this bundle\'s inbound contract does not request it — an older or another bundle never closes it');
        expect(mailInboundLog($host, 'mutations.log'))->toBe('');
        expect(mailInboundApplied($host))->toMatchArray(['public_smtp' => 'enabled', 'address' => '1.2.3.4']);
        expect(mailInboundFile($host, '/etc/postfix-inbound/master.cf'))->toContain("1.2.3.4:25 inet n - n - 20 smtpd\n");
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('disables public SMTP only with a disable authorization', function () {
    $host = mailInboundHost(['tls' => true]);

    try {
        mailInboundInstalled($host);
        mailInboundAuthorize($host, 'enable');
        mailInboundInstalled($host);

        mailInboundAuthorize($host, 'disable');
        mailInboundInstalled($host);

        expect(mailInboundApplied($host))->toMatchArray(['public_smtp' => 'disabled', 'address' => null]);
        expect(mailInboundFile($host, '/etc/postfix-inbound/master.cf'))->not->toContain(':25 inet');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('refuses an authorization it cannot trust, and changes nothing', function (string $direction, array $changes, ?int $mode, string $reason) {
    $host = mailInboundHost(['tls' => true]);

    try {
        mailInboundInstalled($host);
        mailInboundAuthorize($host, $direction, $changes);
        if ($mode !== null) {
            chmod($host['fs'].'/var/lib/rateguru-mail-inbound/transition-authorization.json', $mode);
        }
        $tree = mailInboundTree($host, 'etc');

        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('a public SMTP transition authorization is present but cannot be used')->toContain($reason);
        expect(mailInboundTree($host, 'etc'))->toBe($tree);
        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'expired' => ['enable', ['created_at' => time() - 7200, 'expires_at' => time() - 3600], null, 'not a well-formed, unexpired authorization'],
    'too long-lived' => ['enable', ['expires_at' => time() + 86400], null, 'not a well-formed, unexpired authorization'],
    'from the wrong state' => ['disable', [], null, 'to move the receiver from disabled'],
    'another kind' => ['enable', ['kind' => 'rateguru-mail-gateway-transition-authorization'], null, 'not a well-formed'],
    'an address that is not one' => ['enable', ['address' => 'mx1.tits.guru'], null, 'not a well-formed'],
    'readable by others' => ['enable', [], 0o644, 'not '],
]);

it('never uses one authorization twice', function () {
    $host = mailInboundHost(['tls' => true]);

    try {
        mailInboundInstalled($host);
        mailInboundAuthorize($host, 'enable', ['nonce' => str_repeat('a', 32)]);
        mailInboundInstalled($host);
        mailInboundAuthorize($host, 'disable', ['nonce' => str_repeat('b', 32)]);
        mailInboundInstalled($host);

        // The first one, again.
        mailInboundAuthorize($host, 'enable', ['nonce' => str_repeat('a', 32)]);
        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('it was already used');
        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('never enables public SMTP without a usable certificate, or on an address the host does not have', function (array $options, array $toggles, array $changes, string $reason) {
    $host = mailInboundHost($options);

    try {
        mailInboundInstalled($host);
        foreach ($toggles as $toggle) {
            touch($host['scratch'].'/toggles/'.$toggle);
        }
        mailInboundAuthorize($host, 'enable', $changes);

        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
        expect(mailInboundFile($host, '/etc/postfix-inbound/master.cf'))->not->toContain(':25 inet');
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'no certificate' => [['tls' => false], [], [], 'public SMTP needs a valid certificate: no certificate is installed'],
    'an expiring certificate' => [['tls' => true], ['tls-expiring'], [], 'expires within 14 days'],
    'an untrusted certificate' => [['tls' => true], ['tls-untrusted'], [], 'does not chain to a trusted certificate authority'],
    'another name' => [['tls' => true], ['tls-wrong-name'], [], 'the certificate does not cover mx1.tits.guru'],
    'a key of another certificate' => [['tls' => true], ['tls-key-mismatch'], [], 'the private key does not belong to the certificate'],
    'an address of another host' => [['tls' => true], [], ['address' => '5.6.7.8'], '5.6.7.8 is not an address of this host'],
]);

it('refuses a recorded state it cannot read, rather than guessing whether port 25 is open', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        file_put_contents($host['fs'].'/var/lib/rateguru-mail-inbound/applied.json', '{"public_smtp": "maybe"}');

        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('the receiver\'s recorded state (/var/lib/rateguru-mail-inbound/applied.json) is incomplete or unreadable');
    } finally {
        removeScratchDir($host['scratch']);
    }
});
