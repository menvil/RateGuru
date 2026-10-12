<?php

/**
 * install-mail-inbound-tls and the Certbot deploy hook it renders: the public
 * inbound listener's certificate, copied from the Certbot lineage that holds
 * it, and kept current by every renewal of that lineage alone.
 *
 * Every test runs the REAL installer from a trusted bundle against the
 * simulated inbound host (mailInboundHost), with a Certbot lineage laid out as
 * Certbot lays it out (mailInboundCertbotLineage) — archive/ files, live/
 * links, the renewal configuration — and the REAL rendered hook, run as
 * Certbot runs it (mailInboundDeployHook). The simulated openssl judges each
 * certificate by what it says of itself (mailInboundTestCertificate); the
 * real install(1) and mv(1) do the copying unless a toggle makes them fail.
 * So what is asserted is what lands on disk, what Postfix is asked to do, and
 * what is printed — never only what the script says.
 */
beforeEach(function () {
    $this->host = mailInboundHost(['tls' => false]);
    mailInboundCertbotLineage($this->host);
});

afterEach(function () {
    mailInboundCleanup($this->host);
});

describe('the first installation', function () {
    it('copies the pair Certbot holds into place as regular files, installs the hook, reloads Postfix once and verifies', function () {
        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->toBe(0, $output);
        mailInboundTlsExpectInstalled($this->host, 1);

        // The hook: a regular file, executable, the bundle's render, no link to anything.
        $hook = $this->host['fs'].MAIL_TLS_HOOK;
        expect(is_link($hook))->toBeFalse();
        expect(mailInboundTlsMode($this->host, MAIL_TLS_HOOK))->toBe('0755');
        expect(File::get($hook))
            ->toStartWith("#!/bin/bash\n")
            ->toContain('LINEAGE='.$this->host['fs'].'/etc/letsencrypt/live/mx1.tits.guru')
            ->toContain('POSTFIX_UNIT=postfix@-.service')
            ->not->toContain($this->host['bundle']);

        expect(mailInboundTlsSystemdRequests($this->host))->toBe(['systemctl reload postfix@-.service']);
        expect($output)
            ->toContain('installed the certificate for mx1.tits.guru into')
            ->toContain('PASS     tls-check')
            ->toContain('TLS READY: a certificate for mx1.tits.guru')
            ->toContain('SUMMARY  pass=7 fail=0 drift=0')
            ->toContain('apply complete');
        mailInboundTlsNoStaging($this->host);

        // The listener's own judge agrees, run on its own.
        [$status, $output] = mailInboundHostRun($this->host, 'install-mail-inbound', ['--tls-check']);
        expect($status)->toBe(0, $output);
    });

    it('changes nothing at all when run again', function () {
        expect(mailInboundTlsRun($this->host, 'apply')[0])->toBe(0);
        $before = array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY, MAIL_TLS_HOOK]);
        $tree = provisionTreeSnapshot($this->host['fs']);
        mailInboundTlsResetLogs($this->host);
        sleep(1);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('the deploy hook '.MAIL_TLS_HOOK.' is already as rendered')
            ->toContain('the installed pair is already the certificate Certbot holds — nothing to change');
        expect(array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY, MAIL_TLS_HOOK]))->toBe($before);
        expect(provisionTreeSnapshot($this->host['fs']))->toBe($tree);
        expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
    });

    it('replaces a pair installed by hand with the one Certbot holds', function () {
        @mkdir($this->host['fs'].'/etc/rateguru-mail-inbound/tls', 0o755, true);
        file_put_contents($this->host['fs'].MAIL_TLS_CERT, mailInboundTestCertificate(['key' => 'old']));
        file_put_contents($this->host['fs'].MAIL_TLS_KEY, mailInboundTestKey('old'));
        chmod($this->host['fs'].MAIL_TLS_KEY, 0o644);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->toBe(0, $output);
        mailInboundTlsExpectInstalled($this->host, 1);
    });

    it('says what --apply would do, and changes nothing while it says so', function () {
        $tree = provisionTreeSnapshot($this->host['fs']);

        [$status, $output] = mailInboundTlsRun($this->host, 'check');

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('PASS     certbot')
            ->toContain('PASS     renewal:mx1.tits.guru')
            ->toContain('INFO     timer                                            certbot.timer is enabled')
            ->toContain('MISSING  file:'.MAIL_TLS_HOOK)
            ->toContain('PASS     source:/etc/letsencrypt/live/mx1.tits.guru')
            ->toContain('MISSING  pair:/etc/rateguru-mail-inbound/tls');
        expect(provisionTreeSnapshot($this->host['fs']))->toBe($tree);
        expect(mailInboundLog($this->host, 'mutations.log'))->toBe('');
    });
});

describe('what the source must be', function () {
    it('refuses a pair that may not be served, before anything changes', function (array $card, ?string $key, string $reason) {
        mailInboundCertbotLineage($this->host, 1, $card, $key);
        $tree = provisionTreeSnapshot($this->host['fs']);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain($reason)
            ->toContain('may not be served (above) — nothing was changed');
        expect(provisionTreeSnapshot($this->host['fs']))->toBe($tree);
        expect(mailInboundLog($this->host, 'mutations.log'))->toBe('');
    })->with([
        'a wrong name' => [['names' => 'mail.example.org'], null, 'the certificate does not cover mx1.tits.guru'],
        'a key of another certificate' => [[], 'k9', 'the private key does not belong to the certificate'],
        'expired' => [['days' => -1], null, 'the certificate has expired, or expires within 14 days'],
        'no trusted chain' => [['trusted' => 'no'], null, 'the certificate does not chain to a trusted certificate authority'],
    ]);

    it('refuses a lineage that is not Certbot\'s own, root-owned and writable by no one else', function (Closure $tamper, string $reason) {
        $tamper($this->host['fs'].'/etc/letsencrypt', $this->host['scratch']);
        $tree = provisionTreeSnapshot($this->host['fs'].'/etc/rateguru-mail-inbound');

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('nothing was changed');
        expect(file_exists($this->host['fs'].MAIL_TLS_HOOK))->toBeFalse();
        expect(provisionTreeSnapshot($this->host['fs'].'/etc/rateguru-mail-inbound'))->toBe($tree);
        expect(mailInboundLog($this->host, 'mutations.log'))->toBe('');
    })->with([
        'a live file linking outside the archive' => [function (string $le, string $scratch) {
            file_put_contents($scratch.'/elsewhere.pem', mailInboundTestCertificate());
            unlink($le.'/live/mx1.tits.guru/fullchain.pem');
            symlink($scratch.'/elsewhere.pem', $le.'/live/mx1.tits.guru/fullchain.pem');
        }, 'fullchain.pem does not lead to a regular file'],
        'a live key linking into another lineage' => [function (string $le) {
            @mkdir($le.'/archive/other.example', 0o755);
            file_put_contents($le.'/archive/other.example/privkey1.pem', mailInboundTestKey('k1'));
            chmod($le.'/archive/other.example/privkey1.pem', 0o600);
            unlink($le.'/live/mx1.tits.guru/privkey.pem');
            symlink('../../archive/other.example/privkey1.pem', $le.'/live/mx1.tits.guru/privkey.pem');
        }, 'privkey.pem does not lead to a regular file'],
        'an archived file that is itself a link' => [function (string $le, string $scratch) {
            file_put_contents($scratch.'/elsewhere.pem', mailInboundTestKey('k1'));
            unlink($le.'/archive/mx1.tits.guru/privkey1.pem');
            symlink($scratch.'/elsewhere.pem', $le.'/archive/mx1.tits.guru/privkey1.pem');
        }, 'privkey.pem does not lead to a regular file'],
        'a live directory that is a link' => [function (string $le, string $scratch) {
            rename($le.'/live/mx1.tits.guru', $scratch.'/live-elsewhere');
            symlink($scratch.'/live-elsewhere', $le.'/live/mx1.tits.guru');
        }, 'live/mx1.tits.guru is not a real directory'],
        'an archive anyone may write' => [function (string $le) {
            chmod($le.'/archive/mx1.tits.guru', 0o777);
        }, 'archive/mx1.tits.guru is not a real directory'],
        'a certificate anyone may write' => [function (string $le) {
            chmod($le.'/archive/mx1.tits.guru/fullchain1.pem', 0o666);
        }, 'fullchain.pem does not lead to a regular file'],
        'a private key anyone may read' => [function (string $le) {
            chmod($le.'/archive/mx1.tits.guru/privkey1.pem', 0o644);
        }, 'is readable by everyone — that private key is not trusted'],
    ]);

    it('runs no Certbot, and refuses without Certbot or its renewal of the lineage', function (Closure $tamper, array $env, string $reason) {
        $tamper($this->host['fs'].'/etc/letsencrypt');
        $tree = provisionTreeSnapshot($this->host['fs']);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply', $env);

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('nothing was changed');
        expect(provisionTreeSnapshot($this->host['fs']))->toBe($tree);
        expect(mailInboundLog($this->host, 'mutations.log'))->toBe('');
    })->with([
        'no Certbot' => [fn () => null, ['RATEGURU_MAILINBOUNDTLS_CERTBOT_BIN' => '/nonexistent/certbot'], 'FAIL     certbot'],
        'no renewal configuration' => [fn (string $le) => unlink($le.'/renewal/mx1.tits.guru.conf'), [], 'Certbot holds no renewal configuration for mx1.tits.guru'],
        'no lineage at all' => [fn (string $le) => exec('rm -rf '.escapeshellarg($le.'/live/mx1.tits.guru')), [], 'live/mx1.tits.guru is not a real directory'],
    ]);
});

describe('what the target must be', function () {
    it('refuses to write through a link where the pair or its directory belongs, and leaves the link alone', function (string $link) {
        @mkdir($this->host['fs'].'/etc/rateguru-mail-inbound/tls', 0o755, true);
        @mkdir($this->host['scratch'].'/elsewhere', 0o755);
        if ($link === '/etc/rateguru-mail-inbound/tls') {
            rmdir($this->host['fs'].$link);
        }
        symlink($this->host['scratch'].'/elsewhere'.($link === '/etc/rateguru-mail-inbound/tls' ? '' : '/'.basename($link)), $this->host['fs'].$link);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->not->toBe(0, $output);
        expect($output)->toMatch('/is not a (regular file|real directory)/');
        expect(is_link($this->host['fs'].$link))->toBeTrue();
        expect(glob($this->host['scratch'].'/elsewhere/*') ?: [])->toBe([]);
        expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
    })->with([MAIL_TLS_KEY, MAIL_TLS_CERT, '/etc/rateguru-mail-inbound/tls']);

    it('replaces a hook that is a link with a file of its own, and never writes through it', function () {
        @mkdir($this->host['fs'].'/etc/letsencrypt/renewal-hooks/deploy', 0o755, true);
        file_put_contents($this->host['scratch'].'/someone-elses-hook', "#!/bin/bash\nexit 0\n");
        symlink($this->host['scratch'].'/someone-elses-hook', $this->host['fs'].MAIL_TLS_HOOK);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');

        expect($status)->toBe(0, $output);
        expect(is_link($this->host['fs'].MAIL_TLS_HOOK))->toBeFalse();
        expect(File::get($this->host['scratch'].'/someone-elses-hook'))->toBe("#!/bin/bash\nexit 0\n");
    });

    it('reports a pair or a hook that is not what it must be, and --apply puts both back', function (Closure $tamper, string $reported, int $version = 1) {
        expect(mailInboundTlsRun($this->host, 'apply')[0])->toBe(0);
        $tamper($this->host);

        [$status, $output] = mailInboundTlsRun($this->host, 'verify');

        expect($status)->toBe(1, $output);
        expect($output)->toContain($reported);

        [$status, $output] = mailInboundTlsRun($this->host, 'apply');
        expect($status)->toBe(0, $output);
        mailInboundTlsExpectInstalled($this->host, $version);
        [$status, $output] = mailInboundTlsRun($this->host, 'verify');
        expect($status)->toBe(0, $output);
    })->with([
        // The pair installed is still valid, so the listener's own judge
        // passes: only the comparison with Certbot's tells.
        'a renewal that did not reach the listener' => [fn (array $host) => mailInboundCertbotLineage($host, 2), 'FAIL     pair:/etc/rateguru-mail-inbound/tls', 2],
        'a key readable by its group' => [fn (array $host) => chmod($host['fs'].MAIL_TLS_KEY, 0o640), 'TLS NOT READY: '.MAIL_TLS_KEY.' is'],
        'a certificate of another name' => [fn (array $host) => file_put_contents($host['fs'].MAIL_TLS_CERT, mailInboundTestCertificate(['names' => 'mail.example.org', 'key' => 'k1'])), 'TLS NOT READY: the certificate does not cover mx1.tits.guru'],
        'a hook edited by hand' => [fn (array $host) => file_put_contents($host['fs'].MAIL_TLS_HOOK, "\n# edited\n", FILE_APPEND), 'DRIFT    file:'.MAIL_TLS_HOOK],
        'no hook' => [fn (array $host) => unlink($host['fs'].MAIL_TLS_HOOK), 'MISSING  file:'.MAIL_TLS_HOOK],
    ]);
});

it('never prints a byte of the private key, whatever happens', function () {
    $outputs = [];
    $outputs[] = mailInboundTlsRun($this->host, 'check')[1];
    $outputs[] = mailInboundTlsRun($this->host, 'apply')[1];
    $outputs[] = mailInboundTlsRun($this->host, 'verify')[1];
    $outputs[] = mailInboundCertbotRenewal($this->host, 2)[1];
    $outputs[] = mailInboundCertbotRenewal($this->host, 3, [], 'k9')[1];
    touch($this->host['toggles'].'/tls-mv-fail');
    $outputs[] = mailInboundCertbotRenewal($this->host, 4)[1];
    $outputs[] = mailInboundDeployHook($this->host, null, ['--verify'])[1];
    chmod($this->host['fs'].MAIL_TLS_KEY, 0o644);
    $outputs[] = mailInboundTlsRun($this->host, 'verify')[1];

    expect(count(array_filter($outputs)))->toBe(8);
    foreach ([...$outputs, mailInboundLog($this->host, 'mutations.log'), mailInboundLog($this->host, 'reads.log'), File::get($this->host['fs'].MAIL_TLS_HOOK)] as $text) {
        expect($text)->not->toContain(MAIL_TLS_KEY_SECRET)->not->toContain('BEGIN PRIVATE KEY');
    }
});

it('handles its arguments strictly, and refuses to run unprivileged', function (array $arguments, array $env, string $reason) {
    [$status, $output] = mailInboundHostRun($this->host, 'install-mail-inbound-tls', $arguments, $env);

    expect($status)->not->toBe(0);
    expect($output)->toContain($reason);
})->with([
    'no mode' => [['--target', 'tits-guru'], [], 'one of --check, --apply or --verify is required'],
    'two modes' => [['--apply', '--verify', '--target', 'tits-guru'], [], 'mode given more than once'],
    'no target' => [['--apply'], [], '--apply needs --target with a valid target ID'],
    'a target the plan does not receive for' => [['--apply', '--target', 'staging-main'], [], 'staging-main is not a target the trusted bundle\'s inbound plan receives mail for — nothing was changed'],
    'a certificate path' => [['--apply', '--target', 'tits-guru', '--cert', '/tmp/cert.pem'], [], 'unknown argument: --cert'],
    'unprivileged' => [['--check', '--target', 'tits-guru'], ['RATEGURU_MAILINBOUNDTLS_EUID' => '1000'], '--check must run as root'],
]);

it('renders a hook that issues nothing, runs no Certbot, and asks Postfix for a reload alone', function () {
    expect(mailInboundTlsRun($this->host, 'apply')[0])->toBe(0);
    $hook = executableSourceLines(File::get($this->host['fs'].MAIL_TLS_HOOK));
    $installer = executableSourceLines(File::get(base_path('infrastructure/scripts/install-mail-inbound-tls')));

    foreach ([$hook, $installer] as $code) {
        expect($code)
            ->not->toMatch('/(^|[;&|(]|\$\()\s*"\$\{CERTBOT_BIN\}"/m')
            ->not->toMatch('/\b(certonly|acme\.sh)\b|--force-renewal|--webroot|--standalone|certbot\s+renew\b/')
            ->not->toMatch('/\b(restart|try-restart|reload-or-restart|stop|start|kill)\b\s+"?\$\{?POSTFIX_UNIT/')
            ->not->toMatch('/\bpostfix\s+(stop|start|reload|flush)\b/')
            ->not->toMatch('/\b(postconf|postsuper|postqueue|ufw|nginx)\b/');
    }
    expect(preg_match_all('/"\$\{SYSTEMCTL\}" (\S+)/', $hook, $calls))->toBe(2);
    expect($calls[1])->toBe(['is-active', 'reload']);
});
