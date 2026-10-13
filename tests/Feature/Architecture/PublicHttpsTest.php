<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/prepare-public-https: the public site of tits-guru
 * from http-acme-ready to tls-ready — HTTPS with Certbot's certificate and 503
 * to everyone, ACME kept on port 80 — and, at GO LIVE only, to public-active
 * and back; with the renewal hook that keeps the certificate served.
 *
 * Run against the simulated host of PublicHttpTest (tests/Pest.php); the same
 * against a real Nginx with real certificates is
 * tests/Support/real-nginx/scenario.sh.
 */
beforeEach(function () {
    $this->host = publicHttpHost();
});

afterEach(function () {
    publicHttpCleanup($this->host);
});

it('renders tls-ready: ACME and a redirect on port 80, Certbot\'s certificate and a 503 on 443 — and nothing of the application', function () {
    $render = publicHttpsRender('tls-ready');

    expect($render)
        ->toStartWith("# RateGuru target tits-guru — public HTTP endpoint.\n#\n# State tls-ready:")
        ->toContain("    location ~ ^/\\.well-known/acme-challenge/[A-Za-z0-9_-]+\$ {\n        root /var/www/html;\n        disable_symlinks on from=\$document_root;")
        ->toContain("    location / {\n        return 301 https://\$host\$request_uri;\n    }")
        ->toContain("    listen 443 ssl;\n    listen [::]:443 ssl;\n")
        ->toContain("    ssl_certificate /etc/letsencrypt/live/tits.guru/fullchain.pem;\n    ssl_certificate_key /etc/letsencrypt/live/tits.guru/privkey.pem;\n")
        ->toContain("        add_header Retry-After 3600 always;\n        add_header Cache-Control \"no-store\" always;\n        default_type text/plain;\n        return 503 \"Service Unavailable\\n\";");

    $directives = implode("\n", array_filter(explode("\n", $render), static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')));
    foreach (['default_server', 'fastcgi', 'php', 'proxy_pass', 'auth_basic', 'Strict-Transport-Security', '/home/www/rateguru', 'index ', 'archive'] as $forbidden) {
        expect(str_contains($directives, $forbidden))->toBeFalse("tls-ready renders {$forbidden}");
    }
    expect(substr_count($directives, 'root '))->toBe(1);
    expect(substr_count($directives, 'server {'))->toBe(2);
});

it('renders public-active through the target\'s own release and PHP-FPM pool, never staging\'s', function () {
    $render = publicHttpsRender('public-active');
    $target = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets'];

    $directives = implode("\n", array_filter(explode("\n", $render), static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')));
    expect($directives)
        ->toContain("    root {$target['tits-guru']['application_root']}/current/public;")
        ->toContain("        fastcgi_pass unix:{$target['tits-guru']['php_fpm']['socket']};")
        ->toContain('        fastcgi_param HTTPS on;')
        ->toContain("    location / {\n        return 301 https://\$host\$request_uri;\n    }")
        ->not->toContain($target['staging-main']['application_root'])
        ->not->toContain($target['staging-main']['php_fpm']['socket'])
        ->not->toContain('default_server')
        ->not->toContain('auth_basic')
        ->not->toContain('Strict-Transport-Security');
});

it('takes http-acme-ready to tls-ready with one validated reload, installing the renewal hook and changing nothing else', function () {
    publicHttpsReady($this->host);
    $tree = publicHttpTree($this->host);
    $mailHook = publicHttpsIdentity($this->host, PUBLIC_HTTPS_MAIL_HOOK);

    [$exit, $output] = publicHttpsRun($this->host, 'check');
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'check', 'status' => 'pass', 'state' => 'http-acme-ready', 'certificate' => 'valid', 'hook' => 'absent', 'changed' => false]);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray([
        'mode' => 'apply', 'status' => 'pass', 'state' => 'tls-ready', 'lifecycle' => 'planned', 'certificate' => 'valid',
        'hook' => 'installed', 'changed' => true, 'reloaded' => true, 'rolled_back' => false, 'ipv6_loopback' => 'available',
    ]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('tls-ready'));
    expect(readlink($this->host['fs'].PUBLIC_HTTP_LINK))->toBe(PUBLIC_HTTP_FILE);
    expect(substr(sprintf('%o', fileperms($this->host['fs'].PUBLIC_HTTPS_HOOK)), -4))->toBe('0755');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");

    expect($output)
        ->toContain('PASS     certificate:tits.guru')
        ->toContain('PASS     certificate:renewal')
        ->toContain('PASS     nginx:default:*:443                          the default server for HTTPS names nobody lists is /etc/nginx/sites-enabled/mailpit-staging#1, not the public site')
        ->toContain('PASS     route:mailpit.staging.myprojects.pp.ua:*:443')
        ->toContain('[apply] the candidate keeps every MX host, every other HTTPS name and every default server where it was')
        ->toContain('[apply] every MX host, every other HTTPS site and every unlisted name answers exactly as before')
        ->toContain('PASS     https:tits.guru@127.0.0.1                    a verified certificate, and / is 503 with Retry-After 3600 and Cache-Control: no-store')
        ->toContain('PASS     https:tits.guru@[::1]:php                    /index.php is the same 503 — no PHP is executed')
        ->toContain('PASS     http:tits.guru@127.0.0.1                     everything but the challenge is a 301 to https://tits.guru')
        ->toContain('PASS     http:tits.guru@127.0.0.1:acme                an absent challenge is 404 from /var/www/html — HTTP-01 stays')
        ->toContain('PASS     answer:mx1.tits.guru@127.0.0.1');
    expect(strpos($output, 'CANDIDATE'))->toBeLessThan(strpos($output, '[apply] Nginx reloaded'));

    // Nothing else: every other site, the webroot, Certbot's certificates and the mail listener's hook.
    expect(publicHttpTree($this->host))->toBe($tree);
    expect(publicHttpsIdentity($this->host, PUBLIC_HTTPS_MAIL_HOOK))->toBe($mailHook);
});

it('is a no-op the second time: nothing written, nothing reloaded', function () {
    publicHttpsTlsReady($this->host);
    $site = publicHttpsIdentity($this->host, PUBLIC_HTTP_FILE);
    $hook = publicHttpsIdentity($this->host, PUBLIC_HTTPS_HOOK);

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'pass', 'state' => 'tls-ready', 'changed' => false, 'reloaded' => false]);
    expect($output)->toContain('no-op: the public site is tls-ready and answering');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
    expect(publicHttpsIdentity($this->host, PUBLIC_HTTP_FILE))->toBe($site);
    expect(publicHttpsIdentity($this->host, PUBLIC_HTTPS_HOOK))->toBe($hook);
});

it('works the same on a host that disables IPv6, asking 127.0.0.1 alone', function () {
    publicHttpIpv6($this->host, 'disabled');
    publicHttpsReady($this->host);

    foreach (['apply' => 'tls-ready', 'verify' => 'tls-ready', 'rollback' => 'http-acme-ready'] as $mode => $state) {
        publicHttpResetLogs($this->host);
        [$exit, $output] = publicHttpsRun($this->host, $mode);
        expect($exit)->toBe(0, $output);
        expect(publicHttpsResult($output))->toMatchArray(['status' => 'pass', 'state' => $state, 'ipv6_loopback' => 'disabled']);
        expect($output)->toContain('SKIP     probes:[::1]');
        expect(publicHttpLog($this->host, 'reads.log'))->not->toContain('[::1]');
    }
});

it('keeps prepare-public-http off the site once it is tls-ready: its apply and rollback refuse', function () {
    publicHttpsTlsReady($this->host);
    $site = publicHttpsIdentity($this->host, PUBLIC_HTTP_FILE);

    foreach (['apply' => 'APPLY: REFUSED', 'rollback' => 'ROLLBACK: REFUSED'] as $mode => $verdict) {
        [$exit, $output] = publicHttpRun($this->host, $mode);
        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('it holds an HTTPS configuration (a 443 listener or a certificate) — the public site has passed to the HTTPS installer')
            ->toContain($verdict);
    }

    expect(publicHttpsIdentity($this->host, PUBLIC_HTTP_FILE))->toBe($site);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('rolls tls-ready back to the exact http-acme-ready, removing its hook and keeping every certificate', function () {
    publicHttpsReady($this->host);
    $tree = publicHttpTree($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'apply');
    expect($exit)->toBe(0, $output);
    $certbot = publicHttpsCertbotTree($this->host);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'rollback');

    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'rollback', 'status' => 'pass', 'state' => 'http-acme-ready', 'hook' => 'absent', 'changed' => true, 'reloaded' => true]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
    expect(file_exists($this->host['fs'].PUBLIC_HTTPS_HOOK))->toBeFalse();
    expect(publicHttpsCertbotTree($this->host))->toBe($certbot);
    expect(publicHttpTree($this->host))->toBe($tree);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");

    // prepare-public-http owns it again, and verifies it.
    [$exit, $output] = publicHttpRun($this->host, 'verify');
    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['state' => 'http-acme-ready']);

    // And a second rollback is a no-op.
    publicHttpResetLogs($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'rollback');
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'pass', 'state' => 'http-acme-ready', 'changed' => false, 'reloaded' => false]);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('refuses a certificate it cannot serve, changing nothing', function (callable $arrange, string $reported) {
    publicHttpsReady($this->host);
    $arrange($this->host);
    $tree = publicHttpTree($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('APPLY: REFUSED');
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'fail', 'state' => 'http-acme-ready', 'changed' => false, 'reloaded' => false]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
    expect(file_exists($this->host['fs'].PUBLIC_HTTPS_HOOK))->toBeFalse();
    expect(publicHttpTree($this->host))->toBe($tree);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
    expect($output)->not->toContain(MAIL_TLS_KEY_SECRET);
})->with([
    'no lineage' => [function (array $host): void {
        exec('rm -rf '.escapeshellarg($host['fs'].'/etc/letsencrypt/live/tits.guru'));
    }, 'FAIL     certificate:tits.guru'],
    'expired' => [function (array $host): void {
        publicHttpsLineage($host, ['days' => 0], null, 2);
    }, 'the certificate has expired, or expires within 14 days'],
    'expiring within 14 days' => [function (array $host): void {
        publicHttpsLineage($host, ['days' => 10], null, 2);
    }, 'the certificate has expired, or expires within 14 days'],
    'another name' => [function (array $host): void {
        publicHttpsLineage($host, ['names' => 'www.tits.guru'], null, 2);
    }, 'the certificate does not cover tits.guru'],
    'untrusted' => [function (array $host): void {
        publicHttpsLineage($host, ['trusted' => 'no'], null, 2);
    }, 'the certificate does not chain to a trusted certificate authority'],
    'another key' => [function (array $host): void {
        publicHttpsLineage($host, [], 'k9', 2);
    }, 'the private key does not belong to the certificate'],
    'no certificate in the file' => [function (array $host): void {
        file_put_contents($host['fs'].'/etc/letsencrypt/archive/tits.guru/fullchain1.pem', "not a certificate\n");
    }, 'holds no certificate'],
    'a live file, not a link' => [function (array $host): void {
        $live = $host['fs'].'/etc/letsencrypt/live/tits.guru/fullchain.pem';
        $content = file_get_contents($live);
        unlink($live);
        file_put_contents($live, $content);
    }, '/etc/letsencrypt/live/tits.guru/fullchain.pem is not a link to a regular file'],
    'a link out of the archive' => [function (array $host): void {
        $live = $host['fs'].'/etc/letsencrypt/live/tits.guru/privkey.pem';
        file_put_contents($host['scratch'].'/elsewhere.pem', mailInboundTestKey());
        unlink($live);
        symlink($host['scratch'].'/elsewhere.pem', $live);
    }, '/etc/letsencrypt/live/tits.guru/privkey.pem is not a link to a regular file'],
    'a key everyone can read' => [function (array $host): void {
        chmod($host['fs'].'/etc/letsencrypt/archive/tits.guru/privkey1.pem', 0o644);
    }, 'is readable by its group or by everyone — it is not trusted'],
    'a key its group can read' => [function (array $host): void {
        chmod($host['fs'].'/etc/letsencrypt/archive/tits.guru/privkey1.pem', 0o640);
    }, 'is readable by its group or by everyone — it is not trusted'],
    'an archive others may write' => [function (array $host): void {
        chmod($host['fs'].'/etc/letsencrypt/archive/tits.guru', 0o777);
    }, 'is not a real directory of'],
    'no renewal configuration' => [function (array $host): void {
        unlink($host['fs'].'/etc/letsencrypt/renewal/tits.guru.conf');
    }, 'FAIL     certificate:renewal'],
    'a renewal that edits Nginx' => [function (array $host): void {
        $conf = $host['fs'].'/etc/letsencrypt/renewal/tits.guru.conf';
        file_put_contents($conf, str_replace('authenticator = webroot', 'authenticator = nginx', file_get_contents($conf)));
    }, 'does not renew through the webroot /var/www/html'],
    'no deploy hook directory' => [function (array $host): void {
        exec('rm -rf '.escapeshellarg($host['fs'].'/etc/letsencrypt/renewal-hooks'));
    }, 'FAIL     certificate:hooks'],
]);

it('serves the key as Certbot writes it, 0600, and refuses a renewed key its group can read — in the installer and the renewal hook alike', function () {
    publicHttpsTlsReady($this->host);
    expect(fileperms($this->host['fs'].'/etc/letsencrypt/archive/tits.guru/privkey1.pem') & 0o777)->toBe(0o600);

    [$exit, $output] = publicHttpsRun($this->host, 'verify');
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'pass', 'certificate' => 'valid']);

    // Certbot renews, and the renewed key is left readable by its group.
    publicHttpsLineage($this->host, [], null, 2);
    chmod($this->host['fs'].'/etc/letsencrypt/archive/tits.guru/privkey2.pem', 0o640);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsHook($this->host, $this->host['fs'].'/etc/letsencrypt/live/tits.guru');
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('is refused, and Nginx is not reloaded')->toContain('is readable by its group or by everyone — it is not trusted');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');

    [$exit, $output] = publicHttpsRun($this->host, 'verify');
    expect($exit)->toBe(1, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'fail', 'certificate' => 'invalid']);
});

it('starts only from http-acme-ready: not before Prepare tits.guru public HTTP, and never from a site it does not know', function (callable $arrange, string $reported) {
    publicHttpsLineage($this->host);
    $arrange($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('APPLY: REFUSED');
    expect(file_exists($this->host['fs'].PUBLIC_HTTPS_HOOK))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'not prepared' => [fn (array $host) => null, 'FAIL     state                                        not-prepared — the public site does not exist yet'],
    'a hand-edited site' => [function (array $host): void {
        publicHttpInstalled($host);
        file_put_contents($host['fs'].PUBLIC_HTTP_FILE, publicHttpRender()."# edited\n");
    }, 'it is none of the known renders'],
]);

it('refuses where the public site would become the default server for HTTPS', function () {
    publicHttpCleanup($this->host);
    $this->host = publicHttpHost(['sites' => ['default' => PUBLIC_HTTP_DEFAULT_SITE]]);
    publicHttpsReady($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)
        ->toContain('FAIL     nginx:default:*:443                          no other site listens on *:443: the public site would become the default server for every HTTPS name')
        ->toContain('APPLY: REFUSED');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
});

it('puts http-acme-ready back, hook and all, when the change fails', function (string $toggle, string $reported, string $mutations) {
    publicHttpsReady($this->host);
    $tree = publicHttpTree($this->host);
    $certbot = publicHttpsCertbotTree($this->host);
    publicHttpToggle($this->host, $toggle);

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'fail', 'state' => 'http-acme-ready', 'rolled_back' => true, 'changed' => false]);
    expect($output)->toContain($reported)->toContain('RESTORED: the public site is http-acme-ready again');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
    expect(file_exists($this->host['fs'].PUBLIC_HTTPS_HOOK))->toBeFalse();
    expect(publicHttpTree($this->host))->toBe($tree);
    expect(publicHttpsCertbotTree($this->host))->toBe($certbot);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe($mutations);
})->with([
    'nginx -t refuses the candidate' => ['nginx-t-fail-with-https', 'nginx -t fails with tls-ready in place', ''],
    'the reload fails' => ['reload-fail-once', 'systemctl reload nginx failed', "systemctl reload nginx\nsystemctl reload nginx\n"],
    'HTTPS does not verify after the reload' => ['https-untrusted', 'does not answer as tls-ready', "systemctl reload nginx\nsystemctl reload nginx\n"],
    'the site takes other HTTPS names after the reload' => ['https-takes-every-name', 'answers differently', "systemctl reload nginx\nsystemctl reload nginx\n"],
]);

it('says CRITICAL, and exits 2, when http-acme-ready cannot be proved back', function () {
    publicHttpsReady($this->host);
    publicHttpToggle($this->host, 'reload-fail');

    [$exit, $output] = publicHttpsRun($this->host, 'apply');

    expect($exit)->toBe(2, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'critical', 'state' => 'unknown']);
    expect($output)->toContain('CRITICAL: the apply of the public site failed AND the return to http-acme-ready could not be proved');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
});

it('ends its wait only past the reload\'s overlap: the connection after it reaches the new configuration, not an old worker', function (callable $arrange, string $state) {
    $arrange($this->host);
    file_put_contents($this->host['fs'].PUBLIC_HTTP_FILE, $state === 'http-acme-ready' ? publicHttpRender() : publicHttpsRender($state));
    // For a second after the reload, every connection but the first still
    // reaches an old worker.
    file_put_contents($this->host['toggles'].'/reload-overlap', '1000');

    $next = publicHttpBash(<<<'BASH'
        set -Eeuo pipefail
        source infrastructure/scripts/prepare-public-https
        TARGET_ID=tits-guru
        MODE=verify
        begin_https_run >/dev/null
        "${SYSTEMCTL_BIN}" reload nginx
        wait_for_state "${STATE}"
        case "${STATE}" in
            http-acme-ready) is_ours "$(probe 127.0.0.1 tits.guru /)" && echo new || echo old ;;
            tls-ready) is_https_closed "$(https_probe 127.0.0.1 tits.guru / verified)" && echo new || echo old ;;
            public-active) is_https_closed "$(https_probe 127.0.0.1 tits.guru / verified)" && echo old || echo new ;;
        esac
        rm -rf -- "${WORK}"
        BASH, [...$this->host['env'], 'STATE' => $state, 'RATEGURU_PUBLICHTTP_RELOAD_OVERLAP' => '1.2']);

    expect($next)->toBe("new\n");
})->with([
    'to tls-ready' => [fn (array $host) => publicHttpsReady($host), 'tls-ready'],
    'back to http-acme-ready' => [fn (array $host) => publicHttpsTlsReady($host), 'http-acme-ready'],
    'to public-active' => [fn (array $host) => publicHttpsTlsReady($host), 'public-active'],
]);

it('passes every change through a reload\'s overlap', function (callable $arrange, string $mode, string $state) {
    $env = $arrange($this->host);
    // For a second after the reload, every connection but the first still
    // reaches an old worker, as Nginx's own overlap of the two does briefly.
    file_put_contents($this->host['toggles'].'/reload-overlap', '1000');

    [$exit, $output] = publicHttpsRun($this->host, $mode, [...$env, 'RATEGURU_PUBLICHTTP_RELOAD_OVERLAP' => '1.2']);

    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'pass', 'state' => $state, 'changed' => true, 'reloaded' => true, 'rolled_back' => false]);
})->with([
    'apply' => [function (array $host): array {
        publicHttpsReady($host);

        return [];
    }, 'apply', 'tls-ready'],
    'rollback' => [function (array $host): array {
        publicHttpsTlsReady($host);

        return [];
    }, 'rollback', 'http-acme-ready'],
    'deactivate' => [function (array $host): array {
        publicHttpsTlsReady($host);
        $env = publicHttpsActiveTarget($host);
        publicHttpsDeployed($host);
        [$exit, $output] = publicHttpsRun($host, 'activate', $env, 'tits-guru', ['--confirm-public']);
        expect($exit)->toBe(0, $output);

        return $env;
    }, 'deactivate', 'tls-ready'],
]);

it('verifies each state read-only, and a drifted hook or site as a failure', function () {
    publicHttpsReady($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'verify');
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'verify', 'status' => 'pass', 'state' => 'http-acme-ready', 'changed' => false]);

    [$exit] = publicHttpsRun($this->host, 'apply');
    expect($exit)->toBe(0);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'verify');
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'pass', 'state' => 'tls-ready', 'certificate' => 'valid', 'hook' => 'installed']);

    file_put_contents($this->host['fs'].PUBLIC_HTTPS_HOOK, "#!/bin/bash\nexit 0\n");
    [$exit, $output] = publicHttpsRun($this->host, 'verify');
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('CONFLICT hook:'.PUBLIC_HTTPS_HOOK.' it is not the hook this bundle renders');

    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('renews: the hook judges the renewed tits.guru pair, runs nginx -t and only then reloads, and leaves every other lineage alone', function () {
    publicHttpsTlsReady($this->host);
    $lineage = $this->host['fs'].'/etc/letsencrypt/live/tits.guru';

    // Certbot renews tits.guru: version 2, judged, nginx -t, one reload.
    publicHttpsLineage($this->host, [], null, 2);
    publicHttpResetLogs($this->host);
    [$exit, $output] = publicHttpsHook($this->host, $lineage);
    expect($exit)->toBe(0, $output);
    expect($output)->toContain('the renewed certificate of '.$lineage.' holds, nginx -t passed, Nginx reloaded');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");
    expect(publicHttpLog($this->host, 'reads.log'))->toContain("nginx -t\n");

    // Certbot renews mx1.tits.guru: this hook does nothing at all.
    mailInboundCertbotLineage($this->host);
    publicHttpResetLogs($this->host);
    [$exit, $output] = publicHttpsHook($this->host, $this->host['fs'].'/etc/letsencrypt/live/mx1.tits.guru');
    expect($exit)->toBe(0, $output);
    expect($output)->toBe('');
    expect(publicHttpLog($this->host, 'mutations.log').publicHttpLog($this->host, 'reads.log'))->toBe('');

    // A renewal it cannot serve: refused, nothing reloaded, the key never printed.
    publicHttpsLineage($this->host, ['days' => 3], null, 3);
    publicHttpResetLogs($this->host);
    [$exit, $output] = publicHttpsHook($this->host, $lineage);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('is refused, and Nginx is not reloaded')->toContain('expires within 14 days')->not->toContain(MAIL_TLS_KEY_SECRET);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('reloads nothing on a renewal while nginx -t fails, or while Nginx is not running', function (string $toggle, int $status, string $reported) {
    publicHttpsTlsReady($this->host);
    publicHttpToggle($this->host, $toggle);

    [$exit, $output] = publicHttpsHook($this->host, $this->host['fs'].'/etc/letsencrypt/live/tits.guru');

    expect($exit)->toBe($status, $output);
    expect($output)->toContain($reported);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'nginx -t fails' => ['nginx-t-fail', 1, 'nginx -t fails: Nginx is not reloaded'],
    'Nginx stopped' => ['nginx-inactive', 0, 'Nginx is not running, so nothing is reloaded'],
]);

it('renders a hook that reloads Nginx only, never restarts it, and touches nothing of the mail gateway', function () {
    publicHttpsTlsReady($this->host);
    $hook = executableSourceLines(file_get_contents($this->host['fs'].PUBLIC_HTTPS_HOOK));

    expect($hook)
        ->toContain('[[ "${RENEWED_LINEAGE:-}" == "${CERT_LINEAGE}" ]] || exit 0')
        ->toContain('"${HOOK_SYSTEMCTL}" reload nginx')
        ->not->toMatch('/\b(restart|stop|start)\b\s+nginx/')
        ->not->toMatch('/postfix|postconf|opendkim/i')
        ->not->toContain('/infrastructure/scripts/');
    expect(strpos($hook, '"${HOOK_NGINX}" -t'))->toBeLessThan(strpos($hook, '"${HOOK_SYSTEMCTL}" reload nginx'));
});

it('refuses GO LIVE for a planned target, whatever else holds', function () {
    publicHttpsTlsReady($this->host);
    publicHttpsDeployed($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'activate', [], 'tits-guru', ['--confirm-public']);

    expect($exit)->toBe(1, $output);
    expect($output)
        ->toContain('FAIL     target:lifecycle                             lifecycle=planned in the trusted bundle\'s registry — --activate needs active')
        ->toContain('ACTIVATE: REFUSED');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('tls-ready'));
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('refuses GO LIVE until everything it needs holds', function (callable $arrange, array $arguments, string $reported) {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);
    $arrange($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', $arguments);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('ACTIVATE: REFUSED');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('tls-ready'));
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'no confirmation' => [fn (array $host) => null, [], '--activate opens the application to the public, and needs --confirm-public'],
    'no release' => [function (array $host): void {
        unlink($host['fs'].'/home/www/rateguru/production/tits-guru/current');
    }, ['--confirm-public'], 'no release is deployed at /home/www/rateguru/production/tits-guru/current'],
    'no PHP-FPM pool' => [function (array $host): void {
        unlink($host['fs'].'/run/php/rateguru-tits-guru.sock');
    }, ['--confirm-public'], '/run/php/rateguru-tits-guru.sock is not a socket'],
    'an unhealthy application' => [function (array $host): void {
        publicHttpToggle($host, 'app-down');
    }, ['--confirm-public'], 'answered HTTP 502 — the application is not healthy'],
    'a certificate that no longer holds' => [function (array $host): void {
        publicHttpsLineage($host, ['days' => 5], null, 2);
    }, ['--confirm-public'], 'the certificate has expired, or expires within 14 days'],
    'the host still planned' => [function (array $host): void {
        file_put_contents($host['scratch'].'/installed-registry.json', File::get(base_path('infrastructure/config/deployment-targets.json')));
    }, ['--confirm-public'], 'the host\'s own registry says lifecycle=planned — --activate needs active in both'],
]);

it('goes live, and closes again: public-active through PHP-FPM, then back to tls-ready with HTTPS and ACME kept', function () {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'activate', 'status' => 'pass', 'state' => 'public-active', 'lifecycle' => 'active', 'changed' => true, 'reloaded' => true]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('public-active'));
    expect($output)
        ->toContain('PASS     https:tits.guru@127.0.0.1                    a verified certificate, and / is HTTP 200 from the application')
        ->toContain('PASS     http:tits.guru@127.0.0.1:acme');

    // Once live, there is no rollback to HTTP, and no apply over it.
    foreach (['rollback', 'apply'] as $mode) {
        [$exit, $output] = publicHttpsRun($this->host, $mode, $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('public-active — the site is open to the public; --'.$mode.' never takes HTTPS from a live site');
    }
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('public-active'));

    // Closing it again: HTTPS and ACME stay, everyone gets the 503.
    publicHttpResetLogs($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'deactivate', $env);
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'deactivate', 'status' => 'pass', 'state' => 'tls-ready', 'hook' => 'installed', 'changed' => true, 'reloaded' => true]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('tls-ready'));
    expect(file_exists($this->host['fs'].PUBLIC_HTTPS_HOOK))->toBeTrue();
    expect($output)->toContain('PASS     https:tits.guru@127.0.0.1                    Host and SNI tits.guru, the certificate not judged, and / is 503');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");
});

it('closes a live site even when what it needed to go live no longer holds', function () {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(0, $output);

    // The application is down: closing it must not wait on it.
    publicHttpToggle($this->host, 'app-down');
    [$exit, $output] = publicHttpsRun($this->host, 'deactivate', $env);
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['state' => 'tls-ready']);
});

it('closes a live site whose certificate no longer verifies — and --verify still reports the certificate', function (array $card, string $reported) {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(0, $output);

    // Certbot's lineage now holds a certificate no browser accepts, and Nginx serves it.
    publicHttpsLineage($this->host, $card, null, 2);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'deactivate', $env);
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'deactivate', 'status' => 'pass', 'state' => 'tls-ready', 'changed' => true, 'reloaded' => true, 'rolled_back' => false]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('tls-ready'));
    expect($output)
        ->toContain('PASS     https:tits.guru@127.0.0.1                    Host and SNI tits.guru, the certificate not judged, and / is 503 with Retry-After 3600 and Cache-Control: no-store')
        ->toContain('PASS     https:tits.guru@127.0.0.1:php')
        ->not->toContain('RESTORED');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");

    // Closed again, and the certificate is still reported.
    [$exit, $output] = publicHttpsRun($this->host, 'verify', $env);
    expect($exit)->toBe(1, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'fail', 'state' => 'tls-ready', 'certificate' => 'invalid']);
    expect($output)->toContain($reported)->toContain('no verified HTTPS answer (curl exit 60)');
})->with([
    'expired' => [['days' => 0], 'the certificate has expired, or expires within 14 days'],
    'untrusted' => [['trusted' => 'no'], 'the certificate does not chain to a trusted certificate authority'],
]);

it('still puts public-active back when the closed site cannot be put in place, whatever the certificate', function (string $toggle, string $reported) {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(0, $output);

    publicHttpsLineage($this->host, ['days' => 0], null, 2);
    publicHttpToggle($this->host, $toggle);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'deactivate', $env);
    expect($exit)->toBe(1, $output);
    expect(publicHttpsResult($output))->toMatchArray(['status' => 'fail', 'state' => 'public-active', 'rolled_back' => true, 'changed' => false]);
    expect($output)->toContain($reported)->toContain('RESTORED: the public site is public-active again');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('public-active'));
})->with([
    'the reload fails' => ['reload-fail-once', 'systemctl reload nginx failed'],
    'other HTTPS names answer differently after the reload' => ['https-takes-every-name', 'answers differently'],
]);

it('closes a live site whatever the two registries say of its lifecycle — and --verify reports the difference', function (callable $arrange, string $trusted, string $installed) {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(0, $output);

    $env = $arrange($this->host, $env);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'deactivate', $env);
    expect($exit)->toBe(0, $output);
    expect(publicHttpsResult($output))->toMatchArray(['mode' => 'deactivate', 'status' => 'pass', 'state' => 'tls-ready', 'lifecycle' => $trusted, 'changed' => true, 'rolled_back' => false]);
    expect($output)->toContain("WARN     target:lifecycle                             the trusted bundle's registry says lifecycle={$trusted}, the host's own says {$installed} — --deactivate takes any");
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpsRender('tls-ready'));
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");

    [$exit, $output] = publicHttpsRun($this->host, 'verify', $env);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain("FAIL     target:lifecycle                             the trusted bundle's registry says lifecycle={$trusted}, the host's own says {$installed}");
})->with([
    'the host\'s own registry still planned' => [function (array $host, array $env): array {
        file_put_contents($host['scratch'].'/installed-registry.json', File::get(base_path('infrastructure/config/deployment-targets.json')));

        return $env;
    }, 'active', 'planned'],
    'the trusted bundle back to planned' => [fn (array $host, array $env): array => [], 'planned', 'active'],
    'the trusted bundle saying disabled' => [function (array $host, array $env): array {
        $registry = json_decode(File::get($env['RATEGURU_PUBLICHTTP_REGISTRY']), true);
        $registry['targets']['tits-guru']['lifecycle'] = 'disabled';
        file_put_contents($env['RATEGURU_PUBLICHTTP_REGISTRY'], json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $env;
    }, 'disabled', 'active'],
]);

it('still refuses to close what it cannot prove is this target\'s live site', function (callable $arrange, string $reported) {
    publicHttpsTlsReady($this->host);
    $env = publicHttpsActiveTarget($this->host);
    publicHttpsDeployed($this->host);
    [$exit, $output] = publicHttpsRun($this->host, 'activate', $env, 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(0, $output);

    $arrange($this->host);
    $tree = publicHttpTree($this->host);
    $site = file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE);
    publicHttpResetLogs($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'deactivate', $env);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('DEACTIVATE: REFUSED');
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe($site);
    expect(publicHttpTree($this->host))->toBe($tree);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'the host\'s own registry does not name the target' => [function (array $host): void {
        $path = $host['scratch'].'/installed-registry.json';
        $registry = json_decode(File::get($path), true);
        unset($registry['targets']['tits-guru']);
        file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }, "FAIL     target:installed-registry                    the host's own registry does not name tits-guru"],
    'a hand-edited live site' => [function (array $host): void {
        file_put_contents($host['fs'].PUBLIC_HTTP_FILE, publicHttpsRender('public-active')."# edited\n");
    }, 'it is none of the known renders'],
]);

it('keeps --confirm-public for --activate alone, and runs as root only', function () {
    publicHttpsReady($this->host);

    [$exit, $output] = publicHttpsRun($this->host, 'apply', [], 'tits-guru', ['--confirm-public']);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('--confirm-public belongs to --activate alone');

    if (! testProcessIsRoot()) {
        [$exit, $output] = publicHttpsRun($this->host, 'check', ['RATEGURU_PUBLICHTTP_EUID' => null]);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('prepare-public-https --check must run as root');
    }
});

it('never issues, renews or removes a certificate, runs Certbot, or touches the mail gateway', function () {
    $code = executableSourceLines(File::get(infraScript('prepare-public-https')));

    expect($code)->not->toMatch('/(?:^|[;&|(]|\$\(|\bthen|\bdo)\s*(?:certbot|postfix|postconf|opendkim|ufw|iptables|nft|nsupdate|dig|supervisorctl|service)\s/m');
    preg_match_all('/"\$\{(?:SYSTEMCTL_BIN|HOOK_SYSTEMCTL)\}" ([a-z-]+)/', $code, $verbs);
    expect(array_values(array_unique($verbs[1])))->toEqualCanonicalizing(['is-active', 'reload']);

    // Every write or removal names the site's file, its hook, or the run's own work directory.
    preg_match_all('/^\s*(?:rm|ln|install|mv|cp)\b.*$/m', $code, $writes);
    foreach ($writes[0] as $write) {
        expect($write)->toMatch('/HOOK_FILE|\$\{hook\}|\$\{site\}|\$\{incoming\}|\$\{WORK\}|\$\{source\}/', "an unexpected write: {$write}");
    }
});
