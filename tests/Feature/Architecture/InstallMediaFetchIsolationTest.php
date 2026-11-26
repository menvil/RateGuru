<?php

use Symfony\Component\Process\Process;

/**
 * install-media-fetch-isolation: the account yt-dlp runs as, the sudo rule
 * that lets each active target's runtime user run yt-dlp as it, and the
 * nftables table that keeps it off the host's own network.
 *
 * Every test runs the REAL installer against a simulated host: getent,
 * useradd, visudo, nft and systemctl are stand-ins that record what they are
 * asked and keep the account, the loaded table and the unit's state in files.
 * That the real nftables refuses the account's traffic to a private address
 * and lets it reach the public internet is proved by a container rehearsal
 * (infrastructure/runbooks/bootstrap-host.md, "Media fetch isolation").
 */

/** A simulated host the installer can run against. */
function mediaFetchHost(array $options = []): array
{
    $root = makeScratchDir('media-fetch', ['', '/fs/etc', '/bin', '/state', '/log']);
    file_put_contents($root.'/fs/etc/resolv.conf', $options['resolv'] ?? "nameserver 127.0.0.53\noptions edns0 trust-ad\n");
    file_put_contents($root.'/state/passwd', $options['passwd'] ?? "root:x:0:0:root:/root:/bin/bash\n");
    file_put_contents($root.'/state/shadow', $options['shadow'] ?? "root:*:19000:0:99999:7:::\n");

    $stubs = [
        'getent' => <<<'SH'
            #!/bin/bash
            file="$STATE/$1"; [[ -f "$file" ]] || exit 2
            grep -m1 "^$2:" "$file" || exit 2
            SH,
        'useradd' => <<<'SH'
            #!/bin/bash
            echo "useradd $*" >> "$LOG/mutations.log"
            while [[ $# -gt 1 ]]; do
                case "$1" in
                    --home-dir) home="$2"; shift 2 ;;
                    --shell) shell="$2"; shift 2 ;;
                    --comment) gecos="$2"; shift 2 ;;
                    *) shift ;;
                esac
            done
            echo "$1:x:998:998:$gecos:$home:$shell" >> "$STATE/passwd"
            echo "$1:!:19000::::::" >> "$STATE/shadow"
            SH,
        'visudo' => <<<'SH'
            #!/bin/bash
            echo "visudo $*" >> "$LOG/reads.log"
            [[ ! -f "$STATE/visudo-fail" ]]
            SH,
        'nft' => <<<'SH'
            #!/bin/bash
            echo "nft $*" >> "$LOG/nft.log"
            case "$1" in
                -c) [[ ! -f "$STATE/nft-check-fail" ]] ;;
                -f) [[ -f "$STATE/nft-load-fail" ]] && exit 1; cp "$2" "$STATE/table" ;;
                list) [[ -f "$STATE/table" ]] && cat "$STATE/table" ;;
                delete) rm -f "$STATE/table" ;;
                *) exit 64 ;;
            esac
            SH,
        'systemctl' => <<<'SH'
            #!/bin/bash
            echo "systemctl $*" >> "$LOG/systemctl.log"
            case "$1" in
                is-enabled) [[ -f "$STATE/enabled" ]] ;;
                enable) touch "$STATE/enabled" ;;
                restart) "$NFT_STUB" -f "$FS/etc/rateguru/media-fetch.nft" ;;
                daemon-reload) : ;;
                *) exit 64 ;;
            esac
            SH,
    ];

    foreach ($stubs as $name => $body) {
        file_put_contents($root.'/bin/'.$name, preg_replace('/^ {4}/m', '', $body)."\n");
        chmod($root.'/bin/'.$name, 0o755);
    }

    return ['root' => $root, 'fs' => $root.'/fs', 'state' => $root.'/state', 'log' => $root.'/log', 'bin' => $root.'/bin'];
}

/** @return array{int, string} */
function mediaFetchRun(array $host, string $mode): array
{
    $owner = posix_getpwuid(posix_geteuid())['name'];
    $group = posix_getgrgid(posix_getegid())['name'];

    $process = new Process([base_path('infrastructure/scripts/install-media-fetch-isolation'), '--'.$mode], null, [
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MEDIAFETCH_EUID' => '0',
        'RATEGURU_MEDIAFETCH_FS_ROOT' => $host['fs'],
        'RATEGURU_MEDIAFETCH_GETENT_BIN' => $host['bin'].'/getent',
        'RATEGURU_MEDIAFETCH_USERADD_BIN' => $host['bin'].'/useradd',
        'RATEGURU_MEDIAFETCH_VISUDO_BIN' => $host['bin'].'/visudo',
        'RATEGURU_MEDIAFETCH_NFT_BIN' => $host['bin'].'/nft',
        'RATEGURU_MEDIAFETCH_SYSTEMCTL_BIN' => $host['bin'].'/systemctl',
        'RATEGURU_MEDIAFETCH_FILE_OWNER' => $owner,
        'RATEGURU_MEDIAFETCH_FILE_GROUP' => $group,
        'STATE' => $host['state'],
        'LOG' => $host['log'],
        'FS' => $host['fs'],
        'NFT_STUB' => $host['bin'].'/nft',
        'PATH' => getenv('PATH'),
    ], null, 60);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

function mediaFetchLog(array $host, string $name): string
{
    return is_file($host['log'].'/'.$name) ? (string) file_get_contents($host['log'].'/'.$name) : '';
}

/** @return list<string> the file's lines that are not comments */
function mediaFetchRules(string $path): array
{
    return array_values(array_filter(preg_split('/\R/', (string) file_get_contents($path)), static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#')));
}

afterEach(function () {
    foreach ($this->mediaFetchHosts ?? [] as $host) {
        removeScratchDir($host['root']);
    }
});

it('installs the account, a sudo rule for each active target\'s runtime user, the table and its unit — and loads it', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('apply complete');

    // A system account with no password, home or shell.
    expect(mediaFetchLog($host, 'mutations.log'))->toContain('useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --comment RateGuru media fetch (yt-dlp) rateguru-media-fetch')
        ->and((string) file_get_contents($host['state'].'/shadow'))->toContain('rateguru-media-fetch:!:');

    // yt-dlp, and nothing else, for each active target's runtime user.
    expect(mediaFetchRules($host['fs'].'/etc/sudoers.d/rateguru-media-fetch'))->toBe([
        'Defaults:rateguru-staging !requiretty',
        'rateguru-staging ALL=(rateguru-media-fetch) NOPASSWD: /usr/local/bin/yt-dlp',
        'Defaults:rateguru-tits-guru !requiretty',
        'rateguru-tits-guru ALL=(rateguru-media-fetch) NOPASSWD: /usr/local/bin/yt-dlp',
    ]);

    // Its own table only: the resolver allowed, every private range refused.
    $ruleset = (string) file_get_contents($host['fs'].'/etc/rateguru/media-fetch.nft');
    expect($ruleset)->toContain("table inet rateguru_media_fetch\ndelete table inet rateguru_media_fetch\n")
        ->toContain('meta skuid "rateguru-media-fetch" ip daddr 127.0.0.53 udp dport 53 accept')
        ->toContain('meta skuid "rateguru-media-fetch" ip daddr { 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16, 172.16.0.0/12')
        ->toContain('192.168.0.0/16')
        ->toContain('meta skuid "rateguru-media-fetch" ip6 daddr { ::/128, ::1/128, ::ffff:0:0/96')
        ->toContain('fc00::/7, fe80::/10')
        ->not->toContain('flush');

    expect((string) file_get_contents($host['fs'].'/etc/systemd/system/rateguru-media-fetch-firewall.service'))
        ->toContain('ExecStart=/usr/sbin/nft -f /etc/rateguru/media-fetch.nft')
        ->toContain('Before=network-pre.target')
        ->toContain('WantedBy=multi-user.target');

    foreach (['/etc/sudoers.d/rateguru-media-fetch' => '440', '/etc/rateguru/media-fetch.nft' => '644', '/etc/systemd/system/rateguru-media-fetch-firewall.service' => '644'] as $path => $mode) {
        expect(decoct(fileperms($host['fs'].$path) & 0o777))->toBe($mode, $path);
    }

    // Checked before installing, loaded and enabled, and nothing of ufw.
    expect(mediaFetchLog($host, 'nft.log'))->toContain('nft -c -f ')
        ->and(mediaFetchLog($host, 'reads.log'))->toContain('visudo -cqf ')
        ->and(mediaFetchLog($host, 'systemctl.log'))->toContain('systemctl enable rateguru-media-fetch-firewall.service')
        ->toContain('systemctl restart rateguru-media-fetch-firewall.service')
        ->not->toContain('nftables.service')
        ->and(file_exists($host['state'].'/table'))->toBeTrue();

    [$status, $output] = mediaFetchRun($host, 'verify');
    expect($status)->toBe(0, $output)
        ->and($output)->toContain('SUMMARY  pass=7 fail=0 drift=0');
});

it('loads the table before the sudo rule lets anyone run yt-dlp as the account', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->toBe(0, $output)
        ->and(strpos($output, 'loading the table'))->toBeLessThan(strpos($output, 'installing the sudoers rule'));
});

it('never takes over an account of that name it did not create, nor one with a password, and installs nothing', function (string $passwd, string $shadow, string $finding) {
    $host = $this->mediaFetchHosts[] = mediaFetchHost(['passwd' => "root:x:0:0:root:/root:/bin/bash\n".$passwd, 'shadow' => "root:*:19000:0:99999:7:::\n".$shadow]);

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->not->toBe(0)
        ->and($output)->toContain($finding)
        ->and(file_exists($host['fs'].'/etc/sudoers.d/rateguru-media-fetch'))->toBeFalse()
        ->and(file_exists($host['state'].'/table'))->toBeFalse()
        ->and(mediaFetchLog($host, 'mutations.log'))->not->toContain('useradd');
})->with([
    'someone else\'s' => ["rateguru-media-fetch:x:1500:1500:Someone:/home/someone:/bin/bash\n", "rateguru-media-fetch:!:19000::::::\n", 'was not created by RateGuru — it is never taken over'],
    'with a password' => ["rateguru-media-fetch:x:998:998:RateGuru media fetch (yt-dlp):/nonexistent:/usr/sbin/nologin\n", "rateguru-media-fetch:\$6\$hash:19000::::::\n", 'has a password'],
]);

it('refuses a host that does not resolve through systemd-resolved\'s stub, the one resolver the account may reach', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost(['resolv' => "nameserver 10.0.0.2\n"]);

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->not->toBe(0)
        ->and($output)->toContain('does not name 127.0.0.53 alone')
        ->and(mediaFetchLog($host, 'mutations.log'))->toBe('');
});

it('installs nothing when visudo or nft refuses what it rendered', function (string $toggle, string $finding) {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    touch($host['state'].'/'.$toggle);

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->not->toBe(0)
        ->and($output)->toContain($finding)
        ->and(file_exists($host['fs'].'/etc/rateguru/media-fetch.nft'))->toBeFalse()
        ->and(file_exists($host['fs'].'/etc/sudoers.d/rateguru-media-fetch'))->toBeFalse();
})->with([
    'visudo' => ['visudo-fail', 'visudo refuses the rendered sudoers rule'],
    'nft' => ['nft-check-fail', 'nft refuses the rendered ruleset'],
]);

it('puts back what it replaced when the table will not load, and leaves no rule behind', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    touch($host['state'].'/nft-load-fail');

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->not->toBe(0)
        ->and($output)->toContain('[rollback]')
        ->and(file_exists($host['fs'].'/etc/rateguru/media-fetch.nft'))->toBeFalse()
        ->and(file_exists($host['fs'].'/etc/systemd/system/rateguru-media-fetch-firewall.service'))->toBeFalse()
        ->and(file_exists($host['fs'].'/etc/sudoers.d/rateguru-media-fetch'))->toBeFalse();
});

it('verifies as drift a rule that grants more, a table not loaded and a unit not enabled', function (Closure $tamper, string $finding) {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    [$status] = mediaFetchRun($host, 'apply');
    expect($status)->toBe(0);

    $tamper($host);
    [$status, $output] = mediaFetchRun($host, 'verify');

    expect($status)->not->toBe(0)
        ->and($output)->toContain($finding);
})->with([
    'sudo for more' => [function (array $host): void {
        $path = $host['fs'].'/etc/sudoers.d/rateguru-media-fetch';
        chmod($path, 0o640);
        file_put_contents($path, "rateguru-staging ALL=(root) NOPASSWD: ALL\n", FILE_APPEND);
        chmod($path, 0o440);
    }, 'DRIFT    file:/etc/sudoers.d/rateguru-media-fetch'],
    'the table flushed' => [fn (array $host) => unlink($host['state'].'/table'), 'DRIFT    table:inet rateguru_media_fetch'],
    'the unit disabled' => [fn (array $host) => unlink($host['state'].'/enabled'), 'DRIFT    unit:rateguru-media-fetch-firewall.service'],
    'a password given' => [fn (array $host) => file_put_contents($host['state'].'/shadow', str_replace('rateguru-media-fetch:!:', 'rateguru-media-fetch:$6$hash:', (string) file_get_contents($host['state'].'/shadow'))), 'FAIL     account'],
]);

it('checks without root and changes nothing', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();

    [$status, $output] = mediaFetchRun($host, 'check');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('MISSING  account')
        ->and(mediaFetchLog($host, 'mutations.log'))->toBe('')
        ->and(mediaFetchLog($host, 'systemctl.log'))->not->toContain('systemctl enable ')->not->toContain('restart')
        ->and(file_exists($host['fs'].'/etc/rateguru'))->toBeFalse();
});
