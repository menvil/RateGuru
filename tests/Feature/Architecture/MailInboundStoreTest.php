<?php

/**
 * install-mail-inbound: the store inbound mail is delivered to — its account,
 * its fixed-size volume, its mount and its mailboxes — the bind that may not
 * take Postfix down, and the public listener's certificate. Nothing of Postfix:
 * that is install-mail-gateway's alone.
 *
 * Every test runs the REAL installer against the simulated host
 * (mailInboundHost), whose account, volume and mount tools are stubs.
 */

/** @return array{0: int, 1: string} */
function mailInboundStoreRun(array $host, string $mode): array
{
    return mailInboundHostRun($host, 'install-mail-inbound', [$mode]);
}

it('creates the store: its account, its volume mounted nodev, nosuid and noexec, and a Maildir of each destination', function () {
    $host = mailInboundHost();

    try {
        $postfix = [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf')];

        [$status, $output] = mailInboundStoreRun($host, '--apply');
        expect($status)->toBe(0, $output);

        $mutations = mailInboundLog($host, 'mutations.log');
        expect($mutations)
            ->toContain('useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --comment RateGuru inbound mail store rateguru-mail-inbound')
            ->toContain('fallocate -l 1073741824 '.$host['fs'].'/var/lib/rateguru-mail-inbound/store.img')
            ->toContain('mkfs.ext4 -q -F -m 0 -L rgmailin '.$host['fs'].'/var/lib/rateguru-mail-inbound/store.img')
            ->toContain('systemctl enable var-lib-rateguru\x2dmail\x2dinbound-store.mount')
            ->toContain('systemctl start var-lib-rateguru\x2dmail\x2dinbound-store.mount');

        expect(mailInboundFile($host, '/etc/systemd/system/var-lib-rateguru\x2dmail\x2dinbound-store.mount'))
            ->toContain("Before=postfix@-.service\n")
            ->toContain("What=/var/lib/rateguru-mail-inbound/store.img\n")
            ->toContain("Where=/var/lib/rateguru-mail-inbound/store\n")
            ->toContain("Type=ext4\n")
            ->toContain("Options=loop,nodev,nosuid,noexec\n")
            ->not->toContain('Requires=')->not->toContain('PartOf=');

        foreach (['', '/targets/tits-guru/support', '/targets/tits-guru/bounce', '/targets/tits-guru/reply', '/host/postmaster'] as $mailbox) {
            expect(decoct(fileperms($host['fs'].'/var/lib/rateguru-mail-inbound/store'.$mailbox) & 0o777))->toBe('700', "store{$mailbox}");
        }

        // Nothing of Postfix, and no Postfix service touched.
        expect([mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf')])->toBe($postfix);
        expect($mutations)->not->toContain('postfix');
    } finally {
        mailInboundCleanup($host);
    }
});

it('allows a bind to an address the host does not have yet, so no single address can stop Postfix', function () {
    $host = mailInboundHost();

    try {
        [$status, $output] = mailInboundStoreRun($host, '--apply');
        expect($status)->toBe(0, $output);

        expect(mailInboundFile($host, '/etc/sysctl.d/60-rateguru-mail-inbound.conf'))->toEndWith("net.ipv4.ip_nonlocal_bind = 1\n");
        expect(mailInboundLog($host, 'mutations.log'))->toContain('sysctl -q -p '.$host['fs'].'/etc/sysctl.d/60-rateguru-mail-inbound.conf');
        expect(trim((string) file_get_contents($host['state'].'/nonlocal-bind')))->toBe('1');

        // Not in effect is drift, which the next apply restores.
        file_put_contents($host['state'].'/nonlocal-bind', "0\n");
        [$status, $output] = mailInboundStoreRun($host, '--verify');
        expect($status)->not->toBe(0);
        expect($output)->toContain('DRIFT    file:/etc/sysctl.d/60-rateguru-mail-inbound.conf');
    } finally {
        mailInboundCleanup($host);
    }
});

it('is idempotent: a second apply creates, formats and mounts nothing', function () {
    $host = mailInboundHost();

    try {
        [$status] = mailInboundStoreRun($host, '--apply');
        expect($status)->toBe(0);
        file_put_contents($host['log'].'/mutations.log', '');

        [$status, $output] = mailInboundStoreRun($host, '--apply');
        expect($status)->toBe(0, $output);
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('useradd')->not->toContain('fallocate')->not->toContain('mkfs')->not->toContain('systemctl start')->not->toContain('sysctl');

        [$status, $output] = mailInboundStoreRun($host, '--verify');
        expect($status)->toBe(0, $output);
    } finally {
        mailInboundCleanup($host);
    }
});

it('never takes over an account of the same name RateGuru did not create', function () {
    $host = mailInboundHost();

    try {
        file_put_contents($host['state'].'/store-user', "rateguru-mail-inbound:x:1001:1001:Someone:/home/someone:/bin/bash\n");

        [$status, $output] = mailInboundStoreRun($host, '--apply');
        expect($status)->not->toBe(0);
        expect($output)->toContain('rateguru-mail-inbound exists but was not created by RateGuru — it is never taken over. Nothing was changed');
        expect(mailInboundLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailInboundCleanup($host);
    }
});

it('creates no store without its volume and the host reserve free', function () {
    $host = mailInboundHost(['free' => 2147483648]);

    try {
        [$status, $output] = mailInboundStoreRun($host, '--check');
        expect($status)->not->toBe(0);
        expect($output)->toContain('2147483648 bytes are free; creating the store needs 3221225472 — its fixed-size volume and the host reserve');

        [$status, $output] = mailInboundStoreRun($host, '--apply');
        expect($status)->not->toBe(0);
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('fallocate');
    } finally {
        mailInboundCleanup($host);
    }
});

it('fails verify while the volume is not mounted, when delivery waits in the queue, and below the reserve', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        [$status, $output] = mailInboundStoreRun($host, '--verify');
        expect($status)->toBe(0, $output);
        expect($output)->toContain('stored   targets/tits-guru/support/')->toContain('PASS     storage');

        file_put_contents($host['state'].'/store-free', '1000');
        [$status, $output] = mailInboundStoreRun($host, '--verify');
        expect($status)->not->toBe(0);
        expect($output)->toContain('the store has 1000 bytes free, below its reserve of 104857600');

        unlink($host['state'].'/var-lib-rateguru\x2dmail\x2dinbound-store.mount.active');
        [$status, $output] = mailInboundStoreRun($host, '--verify');
        expect($status)->not->toBe(0);
        expect($output)->toContain('the store volume is not mounted at /var/lib/rateguru-mail-inbound/store — delivery waits in the queue until it is');
    } finally {
        mailInboundCleanup($host);
    }
});

it('reports how full the store is in counts only, never a byte of a stored message', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $dir = $host['fs'].'/var/lib/rateguru-mail-inbound/store/targets/tits-guru/support/new';
        @mkdir($dir, 0o700, true);
        file_put_contents($dir.'/1.message', "From: a secret sender <secret@example.net>\nSubject: a secret subject\n\na secret body\n");

        [$status, $output] = mailInboundStoreRun($host, '--verify');
        expect($status)->toBe(0, $output);
        expect($output)->toMatch('#stored   targets/tits-guru/support/\s+1 message\(s\)#')->not->toContain('secret');
    } finally {
        mailInboundCleanup($host);
    }
});

it('judges the certificate the public listener needs, and never prints it or its key', function (array $options, array $toggles, int $status, string $expected) {
    $host = mailInboundHost($options);

    try {
        foreach ($toggles as $toggle) {
            touch($host['toggles'].'/'.$toggle);
        }
        if (isset($options['tls']) && $options['tls'] === 'linked') {
            unlink($host['fs'].'/etc/rateguru-mail-inbound/tls/fullchain.pem');
        }

        [$got, $output] = mailInboundStoreRun($host, '--tls-check');
        expect($got)->toBe($status, $output);
        expect($output)->toContain($expected)->not->toContain('not a real key')->not->toContain('not a real certificate');
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'valid' => [[], [], 0, 'TLS READY: a certificate for mx1.tits.guru, valid for at least 14 days, its key matching'],
    'absent' => [['tls' => false], [], 1, 'TLS NOT READY: no certificate is installed at /etc/rateguru-mail-inbound/tls/fullchain.pem and /etc/rateguru-mail-inbound/tls/privkey.pem'],
    'expiring' => [[], ['tls-expiring'], 1, 'the certificate is not valid, or expires within 14 days'],
    'self-signed' => [[], ['tls-untrusted'], 1, 'does not chain to a trusted certificate authority'],
    'another name' => [[], ['tls-wrong-name'], 1, 'the certificate does not cover mx1.tits.guru'],
    'another key' => [[], ['tls-key-mismatch'], 1, 'the private key does not belong to the certificate'],
]);

it('requires the certificate once public SMTP is enabled, and defers it until then', function () {
    $disabled = mailInboundHost(['installed' => 'disabled', 'tls' => false]);
    $enabled = mailInboundHost(['installed' => 'enabled']);

    try {
        [$status, $output] = mailInboundStoreRun($disabled, '--verify');
        expect($status)->toBe(0, $output);
        expect($output)->toContain('DEFERRED tls')->toContain('required before public SMTP is enabled, not for the store');

        unlink($enabled['fs'].'/etc/rateguru-mail-inbound/tls/fullchain.pem');
        unlink($enabled['fs'].'/etc/rateguru-mail-inbound/tls/privkey.pem');
        [$status, $output] = mailInboundStoreRun($enabled, '--verify');
        expect($status)->not->toBe(0);
        expect($output)->toContain('FAIL     tls')->toContain('public SMTP is enabled');
    } finally {
        mailInboundCleanup($disabled);
        mailInboundCleanup($enabled);
    }
});

it('never writes a Postfix file, starts or reloads Postfix, or opens a port', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/install-mail-inbound')));

    foreach (['/etc/postfix', 'postconf', 'postfix -c', 'postfix@', 'POSTFIX_BIN', 'ufw', 'iptables', 'nft '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("install-mail-inbound uses {$forbidden}");
    }
    expect($code)->toContain('Before=${MAIL_INBOUND_POSTFIX_UNIT}');
});

it('is repository tooling, never part of host bootstrap', function () {
    expect(repositoryOnlyScriptNames())->toContain('install-mail-inbound');
    expect(requiredCliManifestNames())->not->toContain('install-mail-inbound');

    foreach (['bootstrap-host', 'prepare-host', 'repair-target', 'configure-target', 'provision-target'] as $script) {
        expect(executableSourceLines(File::get(base_path("infrastructure/scripts/{$script}"))))->not->toContain('install-mail-inbound');
    }
});
