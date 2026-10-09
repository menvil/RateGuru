<?php

use Illuminate\Support\Facades\File;

/**
 * The inbound receiver: a second, isolated Postfix instance that
 * install-mail-inbound installs from the reviewed inbound plan — its
 * installation and isolation, and the agreement of its recipient table with
 * `mail-inbound route` itself. Its recorded public SMTP state and its
 * verification have files of their own:
 * MailInboundReceiver{Authorization,Verify}Test.
 *
 * Every test runs the shipped installer against a simulated host
 * (mailInboundHost) whose systemd, mount, volume tools, store account,
 * Postfix tools, ss, ip and openssl are stubs that read back what the installer
 * wrote. The rules the receiver applies are mail-inbound's: nothing here
 * restates one.
 */

// =============================================================================
// INSTALLATION AND ISOLATION
// =============================================================================

it('installs the receiver as a Postfix instance of its own: configuration, volume, store, unit and recorded state', function () {
    $host = mailInboundHost();

    try {
        $gateway = mailInboundGatewayDigests($host);
        $output = mailInboundInstalled($host);

        expect($output)
            ->toContain('creating the store\'s system user rateguru-mail-inbound')
            ->toContain('creating the store\'s fixed-size volume: /var/lib/rateguru-mail-inbound/store.img (1073741824 bytes, ext4, label rgmailin)')
            ->toContain('starting rateguru-mail-inbound.service')
            ->toContain('apply complete — the receiver is installed, validated and running; public SMTP disabled');

        foreach (['/etc/postfix-inbound/main.cf', '/etc/postfix-inbound/master.cf', '/etc/postfix-inbound/recipients.regexp', '/etc/systemd/system/rateguru-mail-inbound.service', '/etc/systemd/system/var-spool-rateguru\x2dmail\x2dinbound.mount'] as $path) {
            expect(is_file($host['fs'].$path))->toBeTrue("{$path} was not installed");
        }

        foreach (['queue', 'store', 'data', 'log'] as $dir) {
            expect(is_dir($host['fs'].'/var/spool/rateguru-mail-inbound/'.$dir))->toBeTrue();
        }

        expect(filesize($host['fs'].'/var/lib/rateguru-mail-inbound/store.img'))->toBe(1073741824);
        expect(mailInboundFile($host, '/var/lib/rateguru-mail-inbound/ownership'))->toContain("component=mail-inbound\nstate=installed\n");
        expect(mailInboundApplied($host))->toMatchArray(['kind' => 'rateguru-mail-inbound-applied', 'schema_version' => 1, 'public_smtp' => 'disabled', 'address' => null]);

        // The gateway is never touched: its files byte for byte, and nothing
        // under /etc/postfix written.
        expect(mailInboundGatewayDigests($host))->toBe($gateway);
        expect(mailInboundLog($host, 'mutations.log'))->not->toContain('/etc/postfix/')->not->toContain('postfix@-');

        // Its own unit, enabled and running; the mount for boot too.
        $mutations = mailInboundLog($host, 'mutations.log');
        expect($mutations)
            ->toContain('systemctl enable rateguru-mail-inbound.service')
            ->toContain('systemctl start rateguru-mail-inbound.service')
            ->toContain('systemctl enable var-spool-rateguru\x2dmail\x2dinbound.mount')
            ->toContain("postfix -c {$host['fs']}/etc/postfix-inbound check")
            ->toContain('useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin rateguru-mail-inbound')
            ->toContain('mkfs.ext4 -q -F -m 0 -L rgmailin');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('renders a configuration of its own: its queue, data and log on its volume, every fallback transport error, no milter', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        $main = mailInboundMainParameters(mailInboundFile($host, '/etc/postfix-inbound/main.cf'));

        expect($main)->toMatchArray([
            'queue_directory' => '/var/spool/rateguru-mail-inbound/queue',
            'data_directory' => '/var/spool/rateguru-mail-inbound/data',
            'maillog_file' => '/var/spool/rateguru-mail-inbound/log/maillog',
            'meta_directory' => '/etc/postfix',
            'syslog_name' => 'rateguru-mail-inbound',
            'inet_interfaces' => '127.0.0.1',
            'inet_protocols' => 'ipv4',
            'mydestination' => '',
            'relay_domains' => '',
            'virtual_mailbox_domains' => 'bounce.tx.tits.guru, rateguru-mail-inbound.invalid, reply.tits.guru, tits.guru',
            'virtual_mailbox_maps' => 'regexp:/etc/postfix-inbound/recipients.regexp',
            'virtual_mailbox_base' => '/var/spool/rateguru-mail-inbound/store',
            'virtual_uid_maps' => 'static:4321',
            'local_transport' => 'error:5.1.1 local delivery is disabled on the inbound receiver',
            'default_transport' => 'error:5.7.1 the inbound receiver never delivers off the host',
            'relay_transport' => 'error:5.7.1 the inbound receiver never relays',
            'smtpd_relay_restrictions' => 'reject_unauth_destination',
            'smtpd_recipient_restrictions' => 'reject_unlisted_recipient',
            'smtpd_sasl_auth_enable' => 'no',
            'smtpd_milters' => '',
            'non_smtpd_milters' => '',
            'myorigin' => '$mydomain',
            'mydomain' => 'rateguru-mail-inbound.invalid',
            'myhostname' => 'mx1.tits.guru',
            'smtpd_tls_security_level' => 'none',
            'notify_classes' => '',
        ]);

        // Never the gateway's paths.
        foreach ($main as $key => $value) {
            expect($value)->not->toContain('/var/spool/postfix')->not->toContain('/var/lib/postfix');
        }
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('runs only its loopback listener and the services a receiver needs — no SMTP client, local delivery, command or pickup', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        $services = mailInboundServices(mailInboundFile($host, '/etc/postfix-inbound/master.cf'));

        expect(array_values(array_filter($services, static fn (array $s): bool => $s['type'] === 'inet')))->toBe([
            ['name' => '127.0.0.1:2580', 'type' => 'inet', 'maxproc' => '2', 'command' => 'smtpd'],
        ]);

        expect(array_column(array_filter($services, static fn (array $s): bool => $s['type'] !== 'inet'), 'name'))
            ->toBe(['cleanup', 'qmgr', 'rewrite', 'bounce', 'defer', 'trace', 'error', 'retry', 'anvil', 'virtual', 'showq', 'proxymap', 'tlsmgr', 'postlog']);

        foreach (['smtp', 'lmtp', 'local', 'pipe', 'pickup', 'spawn'] as $command) {
            expect(array_column($services, 'command'))->not->toContain($command);
        }
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('stores accepted mail on a fixed-size volume of its own, mounted nodev, nosuid and noexec', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);

        $mount = mailInboundFile($host, '/etc/systemd/system/var-spool-rateguru\x2dmail\x2dinbound.mount');
        expect($mount)
            ->toContain("What=/var/lib/rateguru-mail-inbound/store.img\n")
            ->toContain("Where=/var/spool/rateguru-mail-inbound\n")
            ->toContain("Type=ext4\n")
            ->toContain("Options=loop,nodev,nosuid,noexec\n");

        $unit = mailInboundFile($host, '/etc/systemd/system/rateguru-mail-inbound.service');
        expect($unit)
            ->toContain("RequiresMountsFor=/var/spool/rateguru-mail-inbound\n")
            ->toContain("ExecStart=/usr/sbin/postfix -c /etc/postfix-inbound start\n")
            ->toContain("ExecStop=/usr/sbin/postfix -c /etc/postfix-inbound stop\n")
            ->toContain("ExecReload=/usr/sbin/postfix -c /etc/postfix-inbound reload\n")
            ->not->toContain('PartOf=postfix.service')
            ->not->toContain('postmulti');

        $main = mailInboundMainParameters(mailInboundFile($host, '/etc/postfix-inbound/main.cf'));
        expect($main['queue_minfree'])->toBe('104857600');
        expect($main['message_size_limit'])->toBe('10485760');
        expect($main['virtual_mailbox_limit'])->toBe('10485760');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('is idempotent: a second apply changes nothing and reloads nothing', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        $tree = mailInboundTree($host, 'etc');
        file_put_contents($host['log'].'/mutations.log', '');

        $output = mailInboundInstalled($host);

        expect($output)->toContain('receiver configuration unchanged — rateguru-mail-inbound.service left running as it is');
        expect(mailInboundTree($host, 'etc'))->toBe($tree);

        $mutations = mailInboundLog($host, 'mutations.log');
        expect($mutations)
            ->not->toContain('systemctl reload')
            ->not->toContain('systemctl start')
            ->not->toContain('useradd')
            ->not->toContain('fallocate')
            ->not->toContain('mkfs');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('refuses a host it cannot take the receiver onto, before changing anything', function (array $options, ?string $prepare, string $reason) {
    $host = mailInboundHost($options);

    try {
        if ($prepare !== null) {
            @mkdir(dirname($host['fs'].$prepare), 0o755, true);
            file_put_contents($host['fs'].$prepare, "somebody else's\n");
        }
        $before = mailInboundTree($host);

        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect(mailInboundTree($host))->toBe($before);
        expect(mailInboundLog($host, 'mutations.log'))->toBe('');
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'no postfix package' => [['package' => false], null, 'postfix is not installed — the receiver uses the package the mail gateway installed, and this installer never installs it'],
    'no mail gateway' => [['gateway' => false], null, 'the mail gateway is not installed on this host'],
    'a foreign configuration directory' => [[], '/etc/postfix-inbound/main.cf', 'receiver paths exist without RateGuru\'s ownership marker (/etc/postfix-inbound)'],
    'a foreign store image' => [[], '/var/lib/rateguru-mail-inbound/store.img', 'refusing to take over a receiver RateGuru did not install'],
    'a foreign unit' => [[], '/etc/systemd/system/rateguru-mail-inbound.service', 'receiver paths exist without RateGuru\'s ownership marker (/etc/systemd/system/rateguru-mail-inbound.service)'],
    'its loopback port taken' => [['listeners' => ['127.0.0.1:2580 4999 something-else.service']], null, '127.0.0.1:2580 is already in use by another process'],
]);

it('refuses a store account RateGuru did not create', function () {
    $host = mailInboundHost();

    try {
        touch($host['state'].'/user');
        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('the user rateguru-mail-inbound');
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('refuses to create the store without its volume and the host reserve free', function () {
    $host = mailInboundHost(['free' => 3000000000]);

    try {
        [$status, $output] = mailInboundRunInstaller($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('3000000000 bytes are free on the host; creating the store needs 3221225472');
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-inbound/store.img'))->toBeFalse();
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('fails back to the previous configuration and service state when Postfix refuses, or the service does not come up', function (string $toggle) {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        $tree = mailInboundTree($host, 'etc');
        $applied = mailInboundApplied($host);

        // A new limit: the configuration changes, then the apply fails.
        $contract = json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true);
        $contract['receiver']['limits']['message_size_bytes'] = 20971520;
        file_put_contents($host['scratch'].'/inbound.json', mailRoutingJson($contract));
        touch($host['scratch'].'/toggles/'.$toggle);

        [$status, $output] = mailInboundRunInstaller($host, '--apply', ['RATEGURU_MAILINBOUND_INBOUND_FILE' => $host['scratch'].'/inbound.json']);

        expect($status)->not->toBe(0);
        expect($output)->toContain('rollback complete: configuration, recorded state and service state restored');
        expect(mailInboundTree($host, 'etc'))->toBe($tree);
        expect(mailInboundApplied($host))->toBe($applied);
        expect(file_exists($host['state'].'/rateguru-mail-inbound.service.active'))->toBeTrue();
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with(['postfix-check-fail', 'reload-fail']);

// =============================================================================
// THE RULES ARE mail-inbound'S
// =============================================================================

it('accepts exactly the recipients mail-inbound route accepts, into the mailbox of the destination it names', function () {
    $host = mailInboundHost();

    try {
        mailInboundInstalled($host);
        $table = [];

        foreach (preg_split('/\R/', mailInboundFile($host, '/etc/postfix-inbound/recipients.regexp')) as $line) {
            if (preg_match('#^/(.*)/ (\S+)$#', $line, $match)) {
                $table[] = [$match[1], $match[2]];
            }
        }

        $id = '01hzx3k9q2w8e7r6t5y4v3p2m1';
        $corpus = [
            'support@tits.guru' => 'targets/tits-guru/support/',
            'SUPPORT@Tits.Guru' => 'targets/tits-guru/support/',
            'postmaster@tits.guru' => 'targets/tits-guru/support/',
            'postmaster@bounce.tx.tits.guru' => 'targets/tits-guru/support/',
            'postmaster@reply.tits.guru' => 'targets/tits-guru/support/',
            "b-{$id}@bounce.tx.tits.guru" => 'targets/tits-guru/bounce/',
            "r-{$id}@reply.tits.guru" => 'targets/tits-guru/reply/',
            'postmaster@rateguru-mail-inbound.invalid' => 'host/postmaster/',
            'noreply@tits.guru' => null,
            'anything@tits.guru' => null,
            'support+x@tits.guru' => null,
            "b-{$id}@reply.tits.guru" => null,
            "r-{$id}@bounce.tx.tits.guru" => null,
            'b-01hzx3k9q2w8e7r6t5y4u3p2m1@bounce.tx.tits.guru' => null,
            "b-{$id}x@bounce.tx.tits.guru" => null,
            'someone@gmail.com' => null,
            'support@tits.guru.evil.example' => null,
            'xsupport@tits.guru' => null,
        ];

        foreach ($corpus as $address => $expected) {
            $mailbox = null;
            foreach ($table as [$pattern, $box]) {
                if (preg_match('/'.$pattern.'/i', $address) === 1) {
                    $mailbox = $box;
                    break;
                }
            }

            $route = json_decode((string) shell_exec('bash '.escapeshellarg(base_path('infrastructure/scripts/mail-inbound')).' route --recipient '.escapeshellarg($address)), true);

            expect($mailbox)->toBe($expected, "the receiver table puts {$address} in ".var_export($mailbox, true));
            expect($route['verdict'])->toBe($expected === null ? 'reject' : 'accept', "route and the receiver disagree about {$address}");
        }
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('names no target, domain or limit in its implementation: everything comes from the plan', function () {
    $source = File::get(base_path('infrastructure/scripts/install-mail-inbound'));

    foreach (['tits', 'mx1', 'mta1', '10485760', '1073741824', 'support@', 'bounce.tx'] as $value) {
        expect(str_contains($source, $value))->toBeFalse("install-mail-inbound names {$value}");
    }

    $code = executableSourceLines($source);
    expect($code)
        ->toContain('"${INBOUND_CLI}" render-receiver')
        ->not->toContain('apt-get')
        ->not->toContain('postmulti')
        ->not->toContain('ufw')
        ->not->toContain('iptables')
        ->not->toContain('nft ');

    // It writes nothing under the gateway's configuration directory.
    expect(preg_match('#(install|cp|mv|rm|tee|>)[^\n]*/etc/postfix/#', $code))->toBe(0);
});

it('is repository tooling, never part of host bootstrap', function () {
    expect(repositoryOnlyScriptNames())->toContain('install-mail-inbound')->toContain('activate-mail-inbound');
    expect(requiredCliManifestNames())->not->toContain('install-mail-inbound')->not->toContain('activate-mail-inbound');

    foreach (['bootstrap-host', 'install-bootstrap-services', 'prepare-host', 'repair-target', 'configure-target', 'provision-target', 'recover-host'] as $script) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script))))
            ->not->toContain('install-mail-inbound')
            ->not->toContain('activate-mail-inbound');
    }
});
