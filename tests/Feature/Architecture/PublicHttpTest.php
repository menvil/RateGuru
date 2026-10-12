<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/prepare-public-http: the public HTTP site of a
 * planned production target, beside its internal site — the ACME HTTP-01
 * challenge of every public hostname answered from /var/www/html, and 503 with
 * Retry-After for everything else — installed, verified and removed without
 * ever handing it a name it does not list, the MX host's above all.
 *
 * Run against a simulated host (tests/Pest.php, publicHttpHost()) whose
 * stand-ins answer as Nginx routes; tests/Support/real-nginx/scenario.sh proves
 * the same against a real Nginx.
 */
beforeEach(function () {
    $this->host = publicHttpHost();
});

afterEach(function () {
    publicHttpCleanup($this->host);
});

function publicHttpRegistryWith(callable $change): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
    $registry = $change($registry);

    return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/** A registry in the scratch directory, for RATEGURU_PUBLICHTTP_REGISTRY. */
function publicHttpTrustedRegistry(array $host, string $json): string
{
    file_put_contents($host['scratch'].'/trusted-registry.json', $json);

    return $host['scratch'].'/trusted-registry.json';
}

it('renders a site that answers the ACME challenge from the webroot and 503 for everything else, and nothing of the application', function () {
    $render = publicHttpRender();
    $target = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets']['tits-guru'];

    expect($render)
        ->toStartWith("# RateGuru target tits-guru — public HTTP endpoint.\n")
        ->toContain("    listen 80;\n    listen [::]:80;\n")
        ->toContain("    server_name tits.guru;\n")
        ->toContain("    location ~ ^/\\.well-known/acme-challenge/[A-Za-z0-9_-]+\$ {\n        root /var/www/html;\n        disable_symlinks on from=\$document_root;\n        types { }\n        default_type text/plain;\n        try_files \$uri =404;\n    }\n")
        ->toContain("    location /.well-known/acme-challenge/ {\n        return 404;\n    }\n")
        ->toContain("    location / {\n        add_header Retry-After 3600 always;\n        default_type text/plain;\n        return 503 \"Service Unavailable\\n\";\n    }\n");

    // Never the default server, never TLS, never a redirect, never the application.
    $directives = implode("\n", array_filter(explode("\n", $render), static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')));
    foreach (['default_server', '443', 'ssl', 'return 301', 'return 302', 'https://', 'fastcgi', 'proxy_pass', 'php', 'index ', $target['application_root'], $target['php_fpm']['socket'], $target['nginx']['internal_hostname'], 'letsencrypt'] as $forbidden) {
        expect(str_contains($directives, $forbidden))->toBeFalse("the public site renders {$forbidden}");
    }
    expect(substr_count($directives, 'server {'))->toBe(1);
    expect(substr_count($directives, 'root '))->toBe(1);
});

it('checks a planned production target without changing anything, and plans exactly its own file and link', function () {
    $before = publicHttpTree($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'check');

    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray([
        'target' => 'tits-guru', 'mode' => 'check', 'status' => 'pass', 'state' => 'not-prepared',
        'site' => 'rateguru-tits-guru-public', 'hostnames' => ['tits.guru'], 'lifecycle' => 'planned',
        'changed' => false, 'reloaded' => false, 'rolled_back' => false,
    ]);
    expect($output)
        ->toContain('PASS     nginx:default:*:80                           explicit default_server: /etc/nginx/sites-enabled/default#0')
        ->toContain('PASS     nginx:default:[::]:80')
        ->toContain('PASS     route:mx1.tits.guru:*:80                     answered by /etc/nginx/sites-enabled/default#0 (default_server, root /var/www/html) — not the public site')
        ->toContain('PASS     answer:mx1.tits.guru@127.0.0.1')
        ->toContain('PASS     answer:mx1.tits.guru@[::1]')
        ->toContain('WRITE    '.PUBLIC_HTTP_FILE.' ')
        ->toContain('LINK     '.PUBLIC_HTTP_LINK.' -> '.PUBLIC_HTTP_FILE)
        ->toContain('RELOAD   systemctl reload nginx — never a restart or a stop');

    // Every other site is listed as kept, the internal one among them, and judged as rendered.
    foreach (['default', 'mailpit-staging', 'mailtrap-local-staging', 'rateguru-staging', 'rateguru-tits-guru'] as $site) {
        expect($output)->toContain("KEEP     /etc/nginx/sites-enabled/{$site}\n");
    }
    expect($output)->toContain('PASS     internal:/etc/nginx/sites-available/rateguru-tits-guru');

    expect(publicHttpTree($this->host))->toBe($before);
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('installs the site with one validated reload, and every other file stays as it was', function () {
    $before = publicHttpTree($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray([
        'mode' => 'apply', 'status' => 'pass', 'state' => 'http-acme-ready', 'lifecycle' => 'planned',
        'changed' => true, 'reloaded' => true, 'rolled_back' => false,
    ]);

    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
    expect(substr(sprintf('%o', fileperms($this->host['fs'].PUBLIC_HTTP_FILE)), -4))->toBe('0644');
    expect(is_link($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(readlink($this->host['fs'].PUBLIC_HTTP_LINK))->toBe(PUBLIC_HTTP_FILE);

    // One reload, and nginx -t on the candidate before it.
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");
    $reads = publicHttpLog($this->host, 'reads.log');
    expect(substr_count($reads, "nginx -t\n"))->toBeGreaterThanOrEqual(2);

    // The candidate was judged before the reload, and the answers proved after it.
    expect($output)
        ->toContain('PASS     nginx:public-site                            loaded once: tits.guru on *:80 [::]:80, never a default_server')
        ->toContain('[apply] the candidate keeps every MX host name and default server where it was')
        ->toContain('[apply] every MX host and unlisted name answers exactly as before')
        ->toContain('PASS     answer:tits.guru@127.0.0.1                   / is 503 with Retry-After 3600 — nothing of the application')
        ->toContain('PASS     answer:tits.guru@[::1]:php')
        ->toContain('PASS     answer:tits.guru@127.0.0.1:acme');
    expect(strpos($output, 'CANDIDATE'))->toBeLessThan(strpos($output, '[apply] Nginx reloaded'));

    expect(publicHttpTree($this->host))->toBe($before);
});

it('is a no-op the second time: nothing written, nothing reloaded', function () {
    publicHttpInstalled($this->host);
    clearstatcache();
    $identity = fileinode($this->host['fs'].PUBLIC_HTTP_FILE).':'.filemtime($this->host['fs'].PUBLIC_HTTP_FILE);
    sleep(1);

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'pass', 'state' => 'http-acme-ready', 'changed' => false, 'reloaded' => false]);
    expect($output)->toContain('no-op: the public site is in place and answering');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
    clearstatcache();
    expect(fileinode($this->host['fs'].PUBLIC_HTTP_FILE).':'.filemtime($this->host['fs'].PUBLIC_HTTP_FILE))->toBe($identity);
});

it('reloads, and writes nothing, when the site is in place but Nginx never loaded it', function () {
    publicHttpInstalled($this->host);
    unlink($this->host['state'].'/live-public');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'pass', 'state' => 'http-acme-ready', 'reloaded' => true]);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");
    expect($output)->not->toContain('[apply] wrote');
});

it('converges its own file when it is not the render, and nothing else', function () {
    publicHttpInstalled($this->host);
    file_put_contents($this->host['fs'].PUBLIC_HTTP_FILE, str_replace('Retry-After 3600', 'Retry-After 60', publicHttpRender()));
    $before = publicHttpTree($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'check');
    expect($exit)->toBe(0, $output);
    expect($output)->toContain('UPDATE   file:'.PUBLIC_HTTP_FILE." this script's, but not the render of the trusted bundle — replaced by --apply");

    [$exit, $output] = publicHttpRun($this->host, 'apply');
    expect($exit)->toBe(0, $output);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
    expect(publicHttpTree($this->host))->toBe($before);
});

it('refuses staging, an unknown target and a malformed target, changing nothing', function (string $target, string $message) {
    [$exit, $output] = publicHttpRun($this->host, 'apply', [], $target);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($message);
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'staging' => ['staging-main', 'staging-main is environment_class=staging: a public site is prepared for a production target only'],
    'unknown' => ['demo-shop', "unknown target: demo-shop — not in the trusted bundle's registry"],
    'malformed' => ['Tits_Guru', 'invalid target ID: Tits_Guru'],
]);

it('refuses a public hostname it cannot render, or one another site owns', function (array $hostnames, string $problem) {
    $registry = publicHttpTrustedRegistry($this->host, publicHttpRegistryWith(function (array $registry) use ($hostnames): array {
        $registry['targets']['tits-guru']['public_hostnames'] = $hostnames;

        return $registry;
    }));

    [$exit, $output] = publicHttpRun($this->host, 'apply', ['RATEGURU_PUBLICHTTP_REGISTRY' => $registry]);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($problem);
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'an address' => [['203.0.113.10'], "public hostname '203.0.113.10' is not under a public top-level domain"],
    'one label' => [['localhost'], "public hostname 'localhost' is not a lowercase DNS name of two or more labels"],
    'a label starting with a hyphen' => [['www.-tits.guru'], "public hostname 'www.-tits.guru' is not a lowercase DNS name of two or more labels"],
    'an internal name' => [['tits.internal'], "public hostname 'tits.internal' is a reserved or internal name"],
    'a reserved name' => [['tits.invalid'], "public hostname 'tits.invalid' is a reserved or internal name"],
    'the MX host' => [['mx1.tits.guru'], "public hostname 'mx1.tits.guru' is an MX host"],
    'another target\'s internal endpoint' => [['rateguru-staging.internal'], "public hostname 'rateguru-staging.internal' is a reserved or internal name"],
]);

it('refuses a path of the public site it does not own, and leaves it as it is', function (callable $arrange, string $reported) {
    $arrange($this->host);
    $before = publicHttpTree($this->host);
    $identity = static function (string $path): ?string {
        clearstatcache();

        return is_link($path) ? 'link:'.readlink($path) : (is_dir($path) ? 'dir' : (file_exists($path) ? hash_file('sha256', $path) : null));
    };
    $paths = [$this->host['fs'].PUBLIC_HTTP_FILE, $this->host['fs'].PUBLIC_HTTP_LINK];
    $kept = array_map($identity, $paths);

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('APPLY: REFUSED');
    expect(publicHttpResult($output))->toMatchArray(['status' => 'fail', 'changed' => false, 'rolled_back' => false]);
    expect(array_map($identity, $paths))->toBe($kept);
    expect(publicHttpTree($this->host))->toBe($before);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');

    [$exit, $output] = publicHttpRun($this->host, 'rollback');
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('ROLLBACK: REFUSED');
    expect(array_map($identity, $paths))->toBe($kept);
})->with([
    'the file is a link' => [function (array $host): void {
        file_put_contents($host['fs'].'/etc/nginx/sites-available/elsewhere', "# not the public site\n");
        symlink('/etc/nginx/sites-available/elsewhere', $host['fs'].PUBLIC_HTTP_FILE);
    }, 'CONFLICT file:'.PUBLIC_HTTP_FILE.' a symbolic link'],
    'the file is somebody else\'s' => [function (array $host): void {
        file_put_contents($host['fs'].PUBLIC_HTTP_FILE, "server {\n    listen 80;\n    server_name tits.guru;\n}\n");
    }, 'CONFLICT file:'.PUBLIC_HTTP_FILE." it does not begin with this script's own first line"],
    'the file is a directory' => [function (array $host): void {
        mkdir($host['fs'].PUBLIC_HTTP_FILE);
    }, 'CONFLICT file:'.PUBLIC_HTTP_FILE.' not a regular file'],
    'the link leads elsewhere' => [function (array $host): void {
        symlink('/etc/nginx/sites-available/default', $host['fs'].PUBLIC_HTTP_LINK);
    }, 'CONFLICT link:'.PUBLIC_HTTP_LINK.' a link to /etc/nginx/sites-available/default'],
    'the link is a file' => [function (array $host): void {
        file_put_contents($host['fs'].PUBLIC_HTTP_LINK, "# a site of its own\n");
    }, 'CONFLICT link:'.PUBLIC_HTTP_LINK.' not a symbolic link'],
    'another link loads the file' => [function (array $host): void {
        symlink(PUBLIC_HTTP_FILE, $host['fs'].'/etc/nginx/sites-enabled/zz-public');
    }, 'CONFLICT link:/etc/nginx/sites-enabled/zz-public      another link to the public site\'s file — it would be loaded twice'],
]);

it('refuses a layout it cannot trust: a linked sites directory, or a webroot others may write', function (callable $arrange, string $reported) {
    $arrange($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('APPLY: REFUSED');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'sites-available is a link' => [function (array $host): void {
        rename($host['fs'].'/etc/nginx/sites-available', $host['fs'].'/etc/nginx/sites-real');
        symlink($host['fs'].'/etc/nginx/sites-real', $host['fs'].'/etc/nginx/sites-available');
    }, 'FAIL     layout:/etc/nginx/sites-available'],
    'sites-enabled is writable by others' => [function (array $host): void {
        chmod($host['fs'].'/etc/nginx/sites-enabled', 0o777);
    }, 'FAIL     layout:/etc/nginx/sites-enabled'],
    'the webroot is writable by others' => [function (array $host): void {
        chmod($host['fs'].'/var/www/html', 0o777);
    }, 'FAIL     webroot:/var/www/html '],
    'the challenge directory is a link' => [function (array $host): void {
        mkdir($host['fs'].'/var/www/html/.well-known', 0o755);
        mkdir($host['scratch'].'/elsewhere', 0o755);
        symlink($host['scratch'].'/elsewhere', $host['fs'].'/var/www/html/.well-known/acme-challenge');
    }, 'FAIL     webroot:/var/www/html/.well-known/acme-challenge'],
]);

it('refuses while another server claims a public hostname', function (string $serverName, string $match) {
    file_put_contents($this->host['fs'].'/etc/nginx/sites-available/claim', "server {\n    listen 80;\n    server_name {$serverName};\n    return 200;\n}\n");
    symlink('/etc/nginx/sites-available/claim', $this->host['fs'].'/etc/nginx/sites-enabled/claim');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)->toContain('CONFLICT nginx:names                                  /etc/nginx/sites-enabled/claim#0 has server_name ')->toContain("({$match}), which can answer tits.guru");
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'the exact name' => ['tits.guru', 'exact'],
    'in capitals' => ['TITS.GURU', 'exact'],
    'among other names' => ['example.org tits.guru', 'exact'],
    'a dot wildcard' => ['.tits.guru', 'wildcard'],
    'a leading wildcard' => ['*.guru', 'wildcard'],
    'a trailing wildcard' => ['tits.*', 'wildcard'],
    'a regular expression' => ['"~^tits\.(guru|example)$"', 'regex'],
]);

it('refuses where the order of the sites, not a default_server, decides who answers the MX host', function (array $sites, array $extra, string $reported) {
    publicHttpCleanup($this->host);
    $this->host = publicHttpHost(['sites' => $sites]);
    foreach ($extra as $path => $content) {
        file_put_contents($this->host['fs'].$path, $content);
    }
    $before = publicHttpTree($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'check');
    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported);

    [$exit, $output] = publicHttpRun($this->host, 'apply');
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('APPLY: REFUSED');
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpTree($this->host))->toBe($before);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    // With no default_server, the server for mx1.tits.guru is whichever loads
    // first — here the internal site, and the public site would load before
    // zz-default too. The real-Nginx scenario shows the public site taking it.
    'no default_server, unlisted names going to whichever site loads first' => [
        ['zz-default' => str_replace(' default_server;', ';', PUBLIC_HTTP_DEFAULT_SITE)], [],
        'FAIL     nginx:default:*:80                           no explicit default_server in another site (the server for unlisted names — the MX host among them — is whichever loads first: /etc/nginx/sites-enabled/rateguru-tits-guru#0)',
    ],
    'no default_server, the default site loading first only by its name' => [
        ['default' => str_replace(' default_server;', ';', PUBLIC_HTTP_DEFAULT_SITE)], [],
        'whichever loads first: /etc/nginx/sites-enabled/default#0), and adding a site could change it',
    ],
    'a default_server on IPv4 only' => [
        ['default' => str_replace('listen [::]:80 default_server;', 'listen [::]:80;', PUBLIC_HTTP_DEFAULT_SITE)], [],
        'FAIL     nginx:default:[::]:80',
    ],
    'a port-80 listener on one address' => [
        ['default' => PUBLIC_HTTP_DEFAULT_SITE, 'loopback' => "server {\n    listen 127.0.0.1:80;\n    server_name loopback.invalid;\n}\n"], [],
        'FAIL     nginx:addresses                              /etc/nginx/sites-enabled/loopback 127.0.0.1:80',
    ],
    'a listen in a fragment' => [
        ['default' => PUBLIC_HTTP_DEFAULT_SITE], ['/etc/nginx/conf.d/listen.conf' => "listen 8080;\n"],
        'FAIL     nginx:fragments                              listen outside any server block in /etc/nginx/conf.d/listen.conf',
    ],
]);

it('refuses when the MX host does not answer its absent challenge 404 today', function (string $toggle, string $reported) {
    publicHttpToggle($this->host, $toggle);

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('APPLY: REFUSED');
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'a redirect' => ['mx-redirects', 'FAIL     answer:mx1.tits.guru@127.0.0.1               an absent challenge answered HTTP 301, not 404'],
    'nothing answering' => ['curl-down', 'FAIL     answer:mx1.tits.guru@127.0.0.1               an absent challenge answered HTTP 000, not 404'],
    'Nginx stopped' => ['nginx-inactive', 'FAIL     nginx:service'],
    'nginx -t failing already' => ['nginx-t-fail', 'FAIL     nginx:test'],
]);

it('puts the previous state back when nginx -t refuses the candidate, without a reload', function () {
    $before = publicHttpTree($this->host);
    publicHttpToggle($this->host, 'nginx-t-fail-with-public');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'fail', 'state' => 'not-prepared', 'changed' => false, 'reloaded' => false, 'rolled_back' => true]);
    expect($output)->toContain('nginx -t fails with the public site in place')->toContain('RESTORED');
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(is_link($this->host['fs'].PUBLIC_HTTP_LINK))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
    expect(publicHttpTree($this->host))->toBe($before);
});

it('puts its own previous file back, byte for byte, when the change fails', function () {
    publicHttpInstalled($this->host);
    $older = str_replace('Retry-After 3600', 'Retry-After 60', publicHttpRender());
    file_put_contents($this->host['fs'].PUBLIC_HTTP_FILE, $older);
    publicHttpToggle($this->host, 'reload-fail-once');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'fail', 'rolled_back' => true, 'state' => 'drift']);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe($older);
    expect(readlink($this->host['fs'].PUBLIC_HTTP_LINK))->toBe(PUBLIC_HTTP_FILE);
});

it('puts the previous state back and reloads it when the reload fails', function () {
    $before = publicHttpTree($this->host);
    publicHttpToggle($this->host, 'reload-fail-once');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'fail', 'state' => 'not-prepared', 'rolled_back' => true]);
    expect($output)->toContain('systemctl reload nginx failed')->toContain('restore: Nginx reloaded with the previous state');
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\nsystemctl reload nginx\n");
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(file_exists($this->host['state'].'/live-public'))->toBeFalse();
    expect(publicHttpTree($this->host))->toBe($before);
});

it('puts the previous state back when, after the reload, the public site answers a name it must not', function () {
    publicHttpToggle($this->host, 'public-takes-every-name');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(1, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'fail', 'state' => 'not-prepared', 'rolled_back' => true]);
    expect($output)->toContain('after the reload, an MX host or an unlisted name answers differently than before');
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(file_exists($this->host['state'].'/live-public'))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\nsystemctl reload nginx\n");
});

it('says CRITICAL, and exits 2, when the previous state cannot be proved', function () {
    publicHttpToggle($this->host, 'reload-fail');

    [$exit, $output] = publicHttpRun($this->host, 'apply');

    expect($exit)->toBe(2, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'critical', 'state' => 'unknown']);
    expect($output)->toContain('CRITICAL: the apply of the public site failed AND the return to the previous state could not be proved');
    // The files are put back even so; only the reload could not be proved.
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
});

it('removes exactly its own file and link with one reload, and a second rollback is a no-op', function () {
    $before = publicHttpTree($this->host);
    publicHttpInstalled($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'rollback');

    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['mode' => 'rollback', 'status' => 'pass', 'state' => 'not-prepared', 'changed' => true, 'reloaded' => true, 'rolled_back' => false]);
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(is_link($this->host['fs'].PUBLIC_HTTP_LINK))->toBeFalse();
    expect(file_exists($this->host['state'].'/live-public'))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe("systemctl reload nginx\n");
    expect($output)->toContain('PASS     answer:tits.guru@127.0.0.1                   HTTP 200 — not the public site');
    expect(publicHttpTree($this->host))->toBe($before);

    publicHttpResetLogs($this->host);
    [$exit, $output] = publicHttpRun($this->host, 'rollback');

    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'pass', 'state' => 'not-prepared', 'changed' => false, 'reloaded' => false]);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('removes an unlinked file of its own without reloading', function () {
    publicHttpInstalled($this->host);
    unlink($this->host['fs'].PUBLIC_HTTP_LINK);

    [$exit, $output] = publicHttpRun($this->host, 'rollback');

    expect($exit)->toBe(0, $output);
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
});

it('puts the site back when the rollback cannot complete', function (string $toggle, string $mutations) {
    publicHttpInstalled($this->host);
    publicHttpToggle($this->host, $toggle);

    [$exit, $output] = publicHttpRun($this->host, 'rollback');

    expect($exit)->toBe(1, $output);
    expect(publicHttpResult($output))->toMatchArray(['mode' => 'rollback', 'status' => 'fail', 'state' => 'http-acme-ready', 'rolled_back' => true]);
    expect(file_get_contents($this->host['fs'].PUBLIC_HTTP_FILE))->toBe(publicHttpRender());
    expect(readlink($this->host['fs'].PUBLIC_HTTP_LINK))->toBe(PUBLIC_HTTP_FILE);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe($mutations);
})->with([
    'nginx -t fails' => ['nginx-t-fail-without-public', ''],
    'the reload fails' => ['reload-fail-once', "systemctl reload nginx\nsystemctl reload nginx\n"],
]);

it('verifies without changing anything, before the site exists and once it is in place', function () {
    $before = publicHttpTree($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'verify');
    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['mode' => 'verify', 'status' => 'pass', 'state' => 'not-prepared', 'changed' => false, 'reloaded' => false]);
    expect($output)->toContain('PASS     answer:tits.guru@127.0.0.1                   HTTP 200 — not the public site');

    publicHttpInstalled($this->host);
    [$exit, $output] = publicHttpRun($this->host, 'verify');
    expect($exit)->toBe(0, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'pass', 'state' => 'http-acme-ready', 'changed' => false, 'reloaded' => false]);
    expect($output)
        ->toContain('PASS     file:'.PUBLIC_HTTP_FILE." this script's, exactly the render")
        ->toContain('PASS     target:lifecycle                             lifecycle=planned')
        ->toContain('PASS     target:installed-registry                    the host\'s own registry says lifecycle=planned too')
        ->toContain('PASS     answer:tits.guru@[::1]                       / is 503 with Retry-After 3600')
        ->toContain('PASS     answer:mx1.tits.guru@[::1]');

    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
    expect(publicHttpTree($this->host))->toBe($before);
});

it('verifies drift as a failure: an edited file, a missing link, a stale Nginx', function (callable $arrange, string $reported) {
    publicHttpInstalled($this->host);
    $arrange($this->host);

    [$exit, $output] = publicHttpRun($this->host, 'verify');

    expect($exit)->toBe(1, $output);
    expect(publicHttpResult($output))->toMatchArray(['status' => 'fail', 'state' => 'drift']);
    expect($output)->toContain($reported);
    expect(publicHttpLog($this->host, 'mutations.log'))->toBe('');
})->with([
    'an edited file' => [function (array $host): void {
        file_put_contents($host['fs'].PUBLIC_HTTP_FILE, publicHttpRender()."# edited on the host\n");
    }, 'DRIFT    file:'.PUBLIC_HTTP_FILE." this script's, but not the render of the trusted bundle"],
    'a missing link' => [function (array $host): void {
        unlink($host['fs'].PUBLIC_HTTP_LINK);
    }, 'DRIFT    state'],
    'a default_server added to it' => [function (array $host): void {
        file_put_contents($host['fs'].PUBLIC_HTTP_FILE, str_replace('listen 80;', 'listen 80 default_server;', publicHttpRender()));
        file_put_contents($host['fs'].'/etc/nginx/sites-available/default', str_replace(' default_server;', ';', PUBLIC_HTTP_DEFAULT_SITE));
    }, 'FAIL     nginx:public-site'],
    'Nginx not serving it' => [function (array $host): void {
        unlink($host['state'].'/live-public');
    }, 'FAIL     answer:tits.guru@127.0.0.1                   / answered HTTP 200, not the public site\'s 503'],
]);

it('refuses, and verifies as a failure, a target that is not planned on either registry', function (string $variable, string $reported) {
    $registry = publicHttpRegistryWith(function (array $registry): array {
        $registry['targets']['tits-guru']['lifecycle'] = 'disabled';

        return $registry;
    });
    $env = $variable === 'trusted'
        ? ['RATEGURU_PUBLICHTTP_REGISTRY' => publicHttpTrustedRegistry($this->host, $registry)]
        : [];
    if ($variable === 'installed') {
        file_put_contents($this->host['scratch'].'/installed-registry.json', str_replace('"lifecycle": "planned"', '"lifecycle": "active"', File::get(base_path('infrastructure/config/deployment-targets.json'))));
    }

    [$exit, $output] = publicHttpRun($this->host, 'apply', $env);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported)->toContain('APPLY: REFUSED');
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();

    [$exit, $output] = publicHttpRun($this->host, 'verify', $env);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain($reported);
})->with([
    'the trusted bundle' => ['trusted', 'FAIL     target:lifecycle                             lifecycle=disabled in the trusted bundle\'s registry'],
    'the host' => ['installed', 'FAIL     target:installed-registry                    the host\'s own registry says lifecycle=active'],
]);

it('refuses while another operation holds the host lock, changing nothing', function () {
    $lock = fopen($this->host['run'].'/host-infrastructure.lock', 'c');
    expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        [$exit, $output] = publicHttpRun($this->host, 'apply');
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('another operation is already mutating this host\'s shared infrastructure');
        expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();

        [$exit, $output] = publicHttpRun($this->host, 'rollback');
        expect($exit)->toBe(1, $output);

        // A read-only mode takes no lock.
        [$exit, $output] = publicHttpRun($this->host, 'check');
        expect($exit)->toBe(0, $output);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

it('runs as root only, and honours no override without the test gate', function () {
    if (testProcessIsRoot()) {
        $this->markTestSkipped('as root, a run without the test gate would read the real /etc/nginx');
    }

    [$exit, $output] = publicHttpRun($this->host, 'check', ['RATEGURU_PUBLICHTTP_EUID' => null]);
    expect($exit)->toBe(1, $output);
    expect($output)->toContain('prepare-public-http --check must run as root');

    // Without the gate, the overrides are not read: not the EUID, and not the
    // scratch host — the run stops at root, or reads the real /etc/nginx.
    [$exit, $output] = publicHttpRun($this->host, 'apply', ['RATEGURU_ALLOW_TEST_OVERRIDES' => null]);
    expect($exit)->not->toBe(0, $output);
    expect(file_exists($this->host['fs'].PUBLIC_HTTP_FILE))->toBeFalse();
    expect(publicHttpLog($this->host, 'mutations.log').publicHttpLog($this->host, 'reads.log'))->toBe('');
});

it('never starts, stops or restarts Nginx, and asks systemd nothing else', function () {
    publicHttpInstalled($this->host);
    publicHttpRun($this->host, 'verify');
    publicHttpRun($this->host, 'rollback');
    publicHttpToggle($this->host, 'reload-fail-once');
    publicHttpRun($this->host, 'apply');

    $requests = array_filter(explode("\n", publicHttpLog($this->host, 'mutations.log').publicHttpLog($this->host, 'reads.log')), static fn (string $line): bool => str_starts_with($line, 'systemctl ') || str_starts_with($line, 'nginx '));
    foreach ($requests as $request) {
        expect($request)->toBeIn(['systemctl reload nginx', 'systemctl is-active --quiet nginx', 'nginx -t', 'nginx -T']);
    }

    $code = executableSourceLines(File::get(infraScript('prepare-public-http')));
    expect($code)->not->toMatch('/SYSTEMCTL_BIN}"\s+(restart|stop|start|enable|disable)\b/');
    preg_match_all('/"\$\{SYSTEMCTL_BIN\}" ([a-z-]+)/', $code, $verbs);
    expect(array_values(array_unique($verbs[1])))->toBe(['is-active', 'reload']);
});

it('writes nothing outside its own file and link, and never a certificate, DNS or another service', function () {
    $code = executableSourceLines(File::get(infraScript('prepare-public-http')));

    // No program of another service is ever run, and the registry is never written.
    expect($code)->not->toMatch('/(?:^|[;&|(]|\$\(|\bthen|\bdo)\s*(?:certbot|postfix|postconf|opendkim|ufw|iptables|nft|nsupdate|dig|supervisorctl|php-fpm\S*|service)\s/m');
    expect($code)->not->toContain('/etc/letsencrypt')->not->toContain('deployment-targets.json" >');

    // Every removal and every write names the public site's own paths, or its private work directory.
    preg_match_all('/^\s*(?:rm|ln|install|mv)\b.*$/m', $code, $writes);
    foreach ($writes[0] as $write) {
        expect($write)->toMatch('/PUBLIC_FILE|PUBLIC_LINK|\$\{file\}|\$\{link\}|\$\{dst\}|\$\{tmp\}|\$\{WORK\}/', "an unexpected write: {$write}");
    }
});
