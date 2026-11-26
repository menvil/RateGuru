<?php

use Symfony\Component\Process\Process;

/**
 * install-media-fetch-isolation: the account each target's yt-dlp runs as,
 * the sudo rule that lets each active target's runtime user run yt-dlp as its
 * own, and the nftables table that keeps them off the host's own network.
 *
 * Every test runs the REAL installer against a simulated host: getent,
 * useradd, visudo, nft and systemctl are stand-ins that record what they are
 * asked and keep the account, the loaded table and the unit's state in files.
 * That the real nftables refuses the accounts' traffic to a private address
 * and lets it reach the public internet is proved by a container rehearsal
 * (infrastructure/runbooks/bootstrap-host.md, "Media fetch isolation").
 */

/** A simulated host the installer can run against. */
function mediaFetchHost(array $options = []): array
{
    $root = makeScratchDir('media-fetch', ['', '/fs/etc', '/fs/run', '/bin', '/state', '/log']);
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
                -f) [[ -f "$STATE/nft-load-fail" ]] && exit 1
                    [[ -f "$STATE/nft-load-fail-new" ]] && grep -q 'fib daddr' "$2" && exit 1
                    cp "$2" "$STATE/table" ;;
                list) [[ -f "$STATE/table" ]] && cat "$STATE/table" ;;
                delete) rm -f "$STATE/table" ;;
                *) exit 64 ;;
            esac
            SH,
        'systemctl' => <<<'SH'
            #!/bin/bash
            echo "systemctl $*" >> "$LOG/systemctl.log"
            case "$1" in
                is-enabled) [[ "$3" == nftables.service ]] && { [[ -f "$STATE/nftables-enabled" ]]; exit; }; [[ -f "$STATE/enabled" ]] ;;
                enable) touch "$STATE/enabled" ;;
                disable) rm -f "$STATE/enabled" ;;
                restart) "$NFT_STUB" -f "$FS/etc/rateguru/media-fetch.nft" && touch "$FS/run/rateguru-media-fetch.armed" ;;
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

    // One account per target: a system account with no password, home or shell.
    expect(mediaFetchLog($host, 'mutations.log'))->toContain('useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --comment RateGuru media fetch for rateguru-staging (yt-dlp) rateguru-staging-fetch')
        ->toContain('--comment RateGuru media fetch for rateguru-tits-guru (yt-dlp) rateguru-tits-guru-fetch')
        ->and((string) file_get_contents($host['state'].'/shadow'))->toContain('rateguru-staging-fetch:!:')->toContain('rateguru-tits-guru-fetch:!:');

    // yt-dlp, and nothing else, as each target's own account.
    expect(mediaFetchRules($host['fs'].'/etc/sudoers.d/rateguru-media-fetch'))->toBe([
        'Defaults:rateguru-staging !requiretty',
        'rateguru-staging ALL=(rateguru-staging-fetch) NOPASSWD: /usr/local/bin/yt-dlp',
        'Defaults:rateguru-tits-guru !requiretty',
        'rateguru-tits-guru ALL=(rateguru-tits-guru-fetch) NOPASSWD: /usr/local/bin/yt-dlp',
    ]);

    // Its own table only: the resolver allowed; the host's own addresses and
    // every private range refused.
    $accounts = '{ "rateguru-staging-fetch", "rateguru-tits-guru-fetch" }';
    $ruleset = (string) file_get_contents($host['fs'].'/etc/rateguru/media-fetch.nft');
    expect($ruleset)->toContain("table inet rateguru_media_fetch\ndelete table inet rateguru_media_fetch\n")
        ->toContain("meta skuid {$accounts} ip daddr 127.0.0.53 udp dport 53 accept")
        ->toContain("meta skuid {$accounts} fib daddr type local reject")
        ->toContain("meta skuid {$accounts} ip daddr { 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16, 172.16.0.0/12")
        ->toContain('192.88.99.0/24, 192.168.0.0/16')
        ->toContain("meta skuid {$accounts} ip6 daddr { ::/96, ::1/128, ::ffff:0:0/96")
        ->toContain('2001::/32, 2001:db8::/32, 2002::/16, fc00::/7, fe80::/10, fec0::/10')
        ->not->toContain('flush');
    // The resolver's exceptions come before the host's own addresses are refused.
    expect(strpos($ruleset, 'dport 53 accept'))->toBeLessThan(strpos($ruleset, 'fib daddr type local reject'));

    // Early, never in an ordering cycle; the marker the application asks for.
    expect((string) file_get_contents($host['fs'].'/etc/systemd/system/rateguru-media-fetch-firewall.service'))
        ->toContain('DefaultDependencies=no')
        ->toContain('Before=network-pre.target shutdown.target')
        ->toContain('Conflicts=shutdown.target')
        ->toContain('ExecStart=/usr/sbin/nft -f /etc/rateguru/media-fetch.nft')
        ->toContain('ExecStartPost=/usr/bin/touch /run/rateguru-media-fetch.armed')
        ->toContain('ExecStopPost=/usr/bin/rm -f /run/rateguru-media-fetch.armed')
        ->toContain('WantedBy=multi-user.target');

    foreach (['/etc/sudoers.d/rateguru-media-fetch' => '440', '/etc/rateguru/media-fetch.nft' => '644', '/etc/systemd/system/rateguru-media-fetch-firewall.service' => '644'] as $path => $mode) {
        expect(decoct(fileperms($host['fs'].$path) & 0o777))->toBe($mode, $path);
    }

    // Checked before installing, loaded and enabled, and nothing of ufw.
    expect(mediaFetchLog($host, 'nft.log'))->toContain('nft -c -f ')
        ->and(mediaFetchLog($host, 'reads.log'))->toContain('visudo -cqf ')
        ->and(mediaFetchLog($host, 'systemctl.log'))->toContain('systemctl enable rateguru-media-fetch-firewall.service')
        ->toContain('systemctl restart rateguru-media-fetch-firewall.service')
        ->not->toContain('enable nftables.service')
        ->and(file_exists($host['state'].'/table'))->toBeTrue()
        ->and(file_exists($host['fs'].'/run/rateguru-media-fetch.armed'))->toBeTrue();

    [$status, $output] = mediaFetchRun($host, 'verify');
    expect($status)->toBe(0, $output)
        ->and($output)->toContain('SUMMARY  pass=10 fail=0 drift=0');

    [$status, $output] = mediaFetchRun($host, 'check');
    expect($status)->toBe(0, $output)
        ->and($output)->toContain('SUMMARY  pass=10 fail=0 drift=0');
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
    'someone else\'s' => ["rateguru-tits-guru-fetch:x:1500:1500:Someone:/home/someone:/bin/bash\n", "rateguru-tits-guru-fetch:!:19000::::::\n", 'rateguru-tits-guru-fetch exists but was not created by RateGuru — it is never taken over'],
    'with a password' => ["rateguru-staging-fetch:x:998:998:RateGuru media fetch for rateguru-staging (yt-dlp):/nonexistent:/usr/sbin/nologin\n", "rateguru-staging-fetch:\$6\$hash:19000::::::\n", 'rateguru-staging-fetch has a password'],
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
    'a password given' => [fn (array $host) => file_put_contents($host['state'].'/shadow', str_replace('rateguru-staging-fetch:!:', 'rateguru-staging-fetch:$6$hash:', (string) file_get_contents($host['state'].'/shadow'))), 'FAIL     account:rateguru-staging-fetch'],
    'the marker gone' => [fn (array $host) => unlink($host['fs'].'/run/rateguru-media-fetch.armed'), 'DRIFT    marker:/run/rateguru-media-fetch.armed'],
    'the distribution\'s nftables enabled' => [fn (array $host) => touch($host['state'].'/nftables-enabled'), 'FAIL     unit:nftables.service'],
    'an account missing from the table' => [fn (array $host) => file_put_contents($host['state'].'/table', str_replace('"rateguru-tits-guru-fetch"', '', (string) file_get_contents($host['state'].'/table'))), 'DRIFT    table:inet rateguru_media_fetch'],
]);

it('checks a host it has not installed on, changing nothing', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();

    [$status, $output] = mediaFetchRun($host, 'check');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('MISSING  account:rateguru-staging-fetch')
        ->and(mediaFetchLog($host, 'mutations.log'))->toBe('')
        ->and(mediaFetchLog($host, 'systemctl.log'))->not->toContain('systemctl enable ')->not->toContain('restart')
        ->and(file_exists($host['fs'].'/etc/rateguru'))->toBeFalse();
});

it('makes a directory it needs, but never re-permissions one that is there', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    mkdir($host['fs'].'/etc/sudoers.d', 0o750);
    chmod($host['fs'].'/etc/sudoers.d', 0o750);

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->toBe(0, $output)
        ->and(decoct(fileperms($host['fs'].'/etc/sudoers.d') & 0o777))->toBe('750');
});

it('puts back the earlier version, loaded and enabled as it was, when the new table will not load', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    [$status] = mediaFetchRun($host, 'apply');
    expect($status)->toBe(0);
    // An earlier version installed and loaded: no rule for the host's own addresses.
    $path = $host['fs'].'/etc/rateguru/media-fetch.nft';
    $earlier = implode("\n", array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $line): bool => ! str_contains($line, 'fib daddr')))."\n";
    file_put_contents($path, $earlier);
    copy($path, $host['state'].'/table');
    touch($host['state'].'/nft-load-fail-new');

    [$status, $output] = mediaFetchRun($host, 'apply');

    expect($status)->not->toBe(0)
        ->and($output)->toContain('[rollback]')->not->toContain('ROLLBACK INCOMPLETE')
        ->and((string) file_get_contents($path))->toBe($earlier)
        ->and((string) file_get_contents($host['state'].'/table'))->toBe($earlier)
        ->and(file_exists($host['state'].'/enabled'))->toBeTrue();
});

it('disables the unit it enabled when a first install rolls back', function () {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    touch($host['state'].'/nft-load-fail');

    [$status] = mediaFetchRun($host, 'apply');

    expect($status)->not->toBe(0)
        ->and(file_exists($host['state'].'/enabled'))->toBeFalse()
        ->and(mediaFetchLog($host, 'systemctl.log'))->toContain('systemctl disable rateguru-media-fetch-firewall.service');
});

it('refuses to run without root in every mode', function (string $mode) {
    $host = $this->mediaFetchHosts[] = mediaFetchHost();
    $process = new Process([base_path('infrastructure/scripts/install-media-fetch-isolation'), '--'.$mode], null, [
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MEDIAFETCH_EUID' => '1000',
        'PATH' => getenv('PATH'),
    ]);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain("--{$mode} must run as root");
})->with(['check', 'verify', 'apply']);
