<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 5 slice 5.1: infrastructure/scripts/bootstrap-host-preflight — the
 * strictly read-only clean-VPS host contract inspection.
 *
 * Every test below executes the real, shipped script as a subprocess —
 * never a reimplementation — against a fully simulated host: fixture
 * os-release/meminfo/passwd/group/timezone files, a constrained tool PATH,
 * and stub systemctl/ss/df/ip/getent/stat binaries, all injected through
 * RATEGURU_PREFLIGHT_* overrides that the script only honors alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true (the identical gate common, backup and
 * every installer already use). Nothing here requires the CI host to run
 * nginx, PostgreSQL or even systemd.
 *
 * The two profiles that matter most mirror the two real situations the
 * preflight must serve: a clean VPS (everything missing — the report tells
 * Phase 5 what to build) and the current staging host (everything present —
 * recognized as PASS, never misreported as a conflict).
 */

// =============================================================================
// Harness
// =============================================================================

function bootstrapPreflightScript(): string
{
    return base_path('infrastructure/scripts/bootstrap-host-preflight');
}

function bootstrapPreflightSource(): string
{
    return File::get(bootstrapPreflightScript());
}

function bootstrapPreflightScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-preflight-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/tools'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function bootstrapPreflightCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bootstrapPreflightRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bootstrapPreflightScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start bootstrap-host-preflight subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bootstrapPreflightWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * The canonical tool inventory the script probes, mirrored here so the
 * compliant fixture can satisfy every probe. apt-get/dpkg belong to the
 * HOST section rather than TOOLS but resolve through the same fixture PATH.
 *
 * @return list<string>
 */
function bootstrapPreflightAllTools(): array
{
    return [
        // required base
        'bash', 'jq', 'curl', 'tar', 'gzip', 'sha256sum', 'install', 'stat',
        'readlink', 'mktemp', 'sort', 'cut', 'env', 'tr', 'head', 'tail',
        'date', 'id', 'rm', 'mv', 'cp', 'ls', 'cat', 'chmod', 'chown', 'ln',
        'od', 'du', 'df', 'sleep', 'uname', 'find', 'grep', 'sed', 'awk',
        'cmp', 'diff', 'flock', 'namei', 'runuser', 'hostname', 'useradd',
        'getent', 'visudo', 'ss', 'ip', 'setfacl', 'getfacl',
        // runtime/service
        'nginx', 'systemctl', 'pg_dump', 'pg_restore', 'psql', 'createdb',
        'dropdb', 'rclone', 'php8.5',
        // optional development/validation
        'shellcheck', 'actionlint', 'wget', 'journalctl',
        // HOST section probes
        'apt-get', 'dpkg',
    ];
}

/**
 * The full compliant-host stat table: every path the FILESYSTEM and SECRETS
 * sections probe, in the shape the stub stat prints (%F|%U|%G|%a).
 *
 * @return list<string>
 */
function bootstrapPreflightCompliantStatTable(): array
{
    $rows = [
        '/home/www/rateguru|directory|root|root|755',
        '/home/www/rateguru/config|directory|root|root|755',
        '/home/www/rateguru/bin|directory|root|root|755',
        '/home/www/rateguru/backups|directory|root|root|700',
        '/home/www/rateguru/run|directory|root|root|700',
        '/home/www/rateguru/config/deployment-targets.json|regular file|root|root|640',
        '/home/www/rateguru/config/deployment.conf|regular file|root|root|640',
        '/home/www/rateguru/bin/common|regular file|root|root|644',
        '/home/www/rateguru/staging|directory|root|root|755',
        '/home/www/rateguru/staging/releases|directory|root|root|755',
        '/home/www/rateguru/staging/shared|directory|root|root|750',
        '/home/www/rateguru/staging/current|symbolic link|root|root|777',
        '/home/www/rateguru/staging/locks|directory|root|root|755',
        '/home/www/rateguru/staging/deployments|directory|root|root|755',
        '/home/deploy-rateguru-staging/incoming|directory|deploy-rateguru-staging|deploy-rateguru-staging|755',
        '/var/log/rateguru|directory|root|root|755',
        '/usr/local/sbin/rateguru-deploy|regular file|root|root|755',
        '/usr/local/sbin/rateguru-rollback|regular file|root|root|755',
        '/usr/local/sbin/rateguru-cleanup|regular file|root|root|755',
        '/etc/sudoers.d/rateguru-deploy|regular file|root|root|440',
        '/etc/cron.d/rateguru-backups|regular file|root|root|644',
        '/etc/cron.d/rateguru-staging-scheduler|regular file|root|root|644',
        '/etc/ssh/sshd_config.d/70-rateguru-deploy.conf|regular file|root|root|644',
        '/etc/nginx/sites-available/rateguru-staging|regular file|root|root|644',
        '/etc/nginx/sites-enabled/rateguru-staging|symbolic link|root|root|777',
        '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf|regular file|root|root|644',
        '/etc/supervisor/conf.d/rateguru-staging-queue.conf|regular file|root|root|644',
        '/etc/systemd/system/staging-mailpit.service|regular file|root|root|644',
        '/etc/systemd/system/staging-mailtrap-local.service|regular file|root|root|644',
        '/etc/staging-mail-capture|directory|root|root|755',
        '/var/lib/staging-mail-capture|directory|staging-mailpit|staging-mailpit|750',
        '/usr/local/bin/staging-mailpit|regular file|root|root|755',
        '/usr/local/bin/staging-mailtrap-local|regular file|root|root|755',
        // Secret material: presence rows only — no content exists anywhere.
        '/home/www/rateguru/staging/shared/.env|regular file|rateguru-staging|rateguru-staging|640',
        '/home/deploy-rateguru-staging/.ssh/authorized_keys|regular file|deploy-rateguru-staging|deploy-rateguru-staging|600',
        '/root/.config/rclone/rclone.conf|regular file|root|root|600',
        '/etc/nginx/rateguru-staging.htpasswd|regular file|root|root|640',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem|regular file|root|root|644',
        '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem|regular file|root|root|644',
    ];

    foreach ([
        'targets', 'health-check', 'status', 'cleanup', 'deploy', 'rollback',
        'backup', 'restore-test', 'offsite-backup', 'offsite-retention',
        'offsite-restore-test', 'backup-cycle',
    ] as $cli) {
        $rows[] = "/home/www/rateguru/bin/{$cli}|regular file|root|root|755";
    }

    return $rows;
}

function bootstrapPreflightCompliantPasswd(): string
{
    return implode("\n", [
        'root:x:0:0:root:/root:/bin/bash',
        'www-data:x:33:33::/var/www:/usr/sbin/nologin',
        'postgres:x:110:118::/var/lib/postgresql:/bin/bash',
        'rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:1002:1002::/home/deploy-rateguru-staging:/bin/bash',
        'staging-mailpit:x:990:990::/var/lib/staging-mail-capture:/usr/sbin/nologin',
        'staging-mailtrap-local:x:991:991::/var/lib/staging-mail-capture:/usr/sbin/nologin',
    ])."\n";
}

function bootstrapPreflightCompliantGroup(): string
{
    return implode("\n", [
        'root:x:0:',
        'www-data:x:33:',
        'postgres:x:118:',
        'rateguru-staging:x:1001:',
        'deploy-rateguru-staging:x:1002:',
        'rateguru-staging-code:x:1010:rateguru-staging,deploy-rateguru-staging',
        'staging-mailpit:x:990:',
        'staging-mailtrap-local:x:991:',
    ])."\n";
}

/**
 * Build a fully simulated host and return the environment to run the script
 * against it. The default is the compliant profile — the current staging
 * host — and every option knocks one aspect back toward a clean or broken
 * host.
 *
 * Options:
 *   os:              'ubuntu-24.04' | 'ubuntu-22.04' | 'debian' | 'absent'
 *   systemd:         bool
 *   tools:           'all' | list<string>
 *   services:        array<string,string> unit => running|stopped ('all-running' default)
 *   passwd/group:    file content overrides
 *   statTable:       list<string> rows (default compliant)
 *   tcpPorts:        list<int> occupied TCP ports
 *   unixSockets:     list<string> occupied unix socket paths
 *   runtimeRegistry: 'parity' | 'drift' | 'absent'
 *   runtimeConf:     'parity' | 'drift' | 'absent'
 *   euid:            string
 *   swap:            bool
 *   timezone:        string
 *   loopback:        bool
 *   dns:             bool
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bootstrapPreflightFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';

    $os = $options['os'] ?? 'ubuntu-24.04';
    $osRelease = match ($os) {
        'ubuntu-24.04' => "ID=ubuntu\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n",
        'ubuntu-22.04' => "ID=ubuntu\nVERSION_ID=\"22.04\"\nPRETTY_NAME=\"Ubuntu 22.04 LTS\"\n",
        'debian' => "ID=debian\nVERSION_ID=\"12\"\nVERSION=\"12 (sentinel-bookworm)\"\n",
        'absent' => null,
    };

    if ($osRelease !== null) {
        file_put_contents($fs.'/os-release', $osRelease);
    }

    $swapKib = ($options['swap'] ?? true) ? 2097152 : 0;
    file_put_contents($fs.'/meminfo', "MemTotal:        4046844 kB\nMemAvailable:    3000000 kB\nSwapTotal:       {$swapKib} kB\n");

    file_put_contents($fs.'/passwd', $options['passwd'] ?? bootstrapPreflightCompliantPasswd());
    file_put_contents($fs.'/group', $options['group'] ?? bootstrapPreflightCompliantGroup());
    file_put_contents($fs.'/timezone', ($options['timezone'] ?? 'Etc/UTC')."\n");

    if ($options['systemd'] ?? true) {
        @mkdir($fs.'/run-systemd', 0o755, true);
    }

    $tools = $options['tools'] ?? 'all';
    $toolNames = $tools === 'all' ? bootstrapPreflightAllTools() : $tools;

    foreach ($toolNames as $tool) {
        bootstrapPreflightWriteStub($scratch.'/tools/'.$tool, "#!/bin/sh\nexit 0\n");
    }

    $services = $options['services'] ?? 'all-running';

    if ($services === 'all-running') {
        $services = [
            'nginx.service' => 'running',
            'php8.5-fpm.service' => 'running',
            'postgresql.service' => 'running',
            'redis-server.service' => 'running',
            'supervisor.service' => 'running',
            'staging-mailpit.service' => 'running',
            'staging-mailtrap-local.service' => 'running',
        ];
    }

    $serviceRows = '';

    foreach ($services as $unit => $state) {
        $serviceRows .= "{$unit}={$state}\n";
    }

    file_put_contents($scratch.'/services.txt', $serviceRows);

    $statTable = $options['statTable'] ?? bootstrapPreflightCompliantStatTable();

    $runtimeRegistry = $options['runtimeRegistry'] ?? 'parity';
    $runtimeRegistryPath = $fs.'/deployment-targets.json';

    if ($runtimeRegistry === 'parity') {
        copy(base_path('infrastructure/config/deployment-targets.json'), $runtimeRegistryPath);
    } elseif ($runtimeRegistry === 'drift') {
        $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
        $registry['x-test-sentinel'] = 'DRIFT-SECRET-SENTINEL-hunter2';
        file_put_contents($runtimeRegistryPath, json_encode($registry, JSON_PRETTY_PRINT)."\n");
    }

    if ($runtimeRegistry !== 'absent') {
        $statTable[] = "{$runtimeRegistryPath}|regular file|root|root|640";
    }

    $runtimeConf = $options['runtimeConf'] ?? 'parity';
    $runtimeConfPath = $fs.'/deployment.conf';

    if ($runtimeConf === 'parity') {
        copy(base_path('infrastructure/templates/deployment.conf.example'), $runtimeConfPath);
    } elseif ($runtimeConf === 'drift') {
        file_put_contents(
            $runtimeConfPath,
            File::get(base_path('infrastructure/templates/deployment.conf.example'))."# drifted\n",
        );
    }

    if ($runtimeConf !== 'absent') {
        $statTable[] = "{$runtimeConfPath}|regular file|root|root|640";
    }

    file_put_contents($scratch.'/stat-table.txt', implode("\n", $statTable)."\n");

    $tcpPorts = $options['tcpPorts'] ?? [80, 443, 5432, 6379, 1025, 8025, 3535, 3550];
    $tcpRows = '';

    foreach ($tcpPorts as $port) {
        $tcpRows .= "LISTEN 0 511 0.0.0.0:{$port} 0.0.0.0:*\n";
    }

    file_put_contents($scratch.'/ss-tcp.txt', $tcpRows);

    $unixSockets = $options['unixSockets'] ?? ['/run/php/rateguru-staging.sock', '/var/run/supervisor.sock'];
    $unixRows = '';

    foreach ($unixSockets as $socket) {
        $unixRows .= "u_str LISTEN 0 4096 {$socket} 12345\n";
    }

    file_put_contents($scratch.'/ss-unix.txt', $unixRows);

    $statTablePath = $scratch.'/stat-table.txt';
    bootstrapPreflightWriteStub($scratch.'/bin/stat', <<<SH
#!/usr/bin/env bash
# Test stub: honors only `stat -c '%F|%U|%G|%a' -- PATH`.
path="\${!#}"
awk -F'|' -v p="\${path}" '\$1 == p { print \$2 "|" \$3 "|" \$4 "|" \$5; found = 1; exit } END { exit !found }' '{$statTablePath}'
SH);

    $servicesPath = $scratch.'/services.txt';
    bootstrapPreflightWriteStub($scratch.'/bin/systemctl', <<<SH
#!/usr/bin/env bash
unit="\${!#}"
case "\$1" in
    list-unit-files)
        if grep -q "^\${unit}=" '{$servicesPath}' 2>/dev/null; then
            printf '%s enabled enabled\\n' "\${unit}"
        fi
        exit 0
        ;;
    is-active)
        if grep -q "^\${unit}=running\$" '{$servicesPath}' 2>/dev/null; then
            exit 0
        fi
        exit 3
        ;;
esac
exit 1
SH);

    bootstrapPreflightWriteStub($scratch.'/bin/ss', <<<SH
#!/usr/bin/env bash
for arg in "\$@"; do
    case "\${arg}" in
        -t) cat '{$scratch}/ss-tcp.txt' 2>/dev/null ;;
        -x) cat '{$scratch}/ss-unix.txt' 2>/dev/null ;;
    esac
done
exit 0
SH);

    bootstrapPreflightWriteStub($scratch.'/bin/df', <<<'SH'
#!/usr/bin/env bash
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n'
printf '/dev/vda1 40000000 8000000 28000000 23%% /\n'
SH);

    $loopbackLine = ($options['loopback'] ?? true)
        ? "printf '1: lo    inet 127.0.0.1/8 scope host lo\\n'"
        : ':';
    bootstrapPreflightWriteStub($scratch.'/bin/ip', "#!/usr/bin/env bash\n{$loopbackLine}\nexit 0\n");

    $dnsBody = ($options['dns'] ?? true)
        ? "printf '185.125.190.36 archive.ubuntu.com\\n'\nexit 0"
        : 'exit 2';
    bootstrapPreflightWriteStub($scratch.'/bin/getent', "#!/usr/bin/env bash\n{$dnsBody}\n");

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_PREFLIGHT_OS_RELEASE_FILE' => $fs.'/os-release',
        'RATEGURU_PREFLIGHT_MEMINFO_FILE' => $fs.'/meminfo',
        'RATEGURU_PREFLIGHT_PASSWD_FILE' => $fs.'/passwd',
        'RATEGURU_PREFLIGHT_GROUP_FILE' => $fs.'/group',
        'RATEGURU_PREFLIGHT_TIMEZONE_FILE' => $fs.'/timezone',
        'RATEGURU_PREFLIGHT_SYSTEMD_RUNTIME_DIR' => $fs.'/run-systemd',
        'RATEGURU_PREFLIGHT_TOOL_PATH' => $scratch.'/tools',
        'RATEGURU_PREFLIGHT_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_PREFLIGHT_SS_BIN' => $scratch.'/bin/ss',
        'RATEGURU_PREFLIGHT_DF_BIN' => $scratch.'/bin/df',
        'RATEGURU_PREFLIGHT_IP_BIN' => $scratch.'/bin/ip',
        'RATEGURU_PREFLIGHT_GETENT_BIN' => $scratch.'/bin/getent',
        'RATEGURU_PREFLIGHT_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_PREFLIGHT_HOSTNAME' => 'preflight-fixture-host',
        'RATEGURU_PREFLIGHT_KERNEL' => '6.8.0-fixture',
        'RATEGURU_PREFLIGHT_ARCH' => 'x86_64',
        'RATEGURU_PREFLIGHT_EUID' => $options['euid'] ?? '0',
        'RATEGURU_PREFLIGHT_RUNTIME_REGISTRY_FILE' => $runtimeRegistryPath,
        'RATEGURU_PREFLIGHT_RUNTIME_DEPLOYMENT_CONF_FILE' => $runtimeConfPath,
    ];
}

/**
 * The clean-VPS profile: a fresh Ubuntu 24.04 host with only a minimal tool
 * set, no services, no RateGuru accounts, and an empty filesystem.
 *
 * @return array<string, string>
 */
function bootstrapPreflightCleanHostFixture(string $scratch): array
{
    return bootstrapPreflightFixture($scratch, [
        'tools' => ['bash', 'grep', 'sed', 'awk', 'tar', 'apt-get', 'dpkg', 'systemctl'],
        'services' => [],
        'passwd' => "root:x:0:0:root:/root:/bin/bash\n",
        'group' => "root:x:0:\n",
        'statTable' => [],
        'tcpPorts' => [],
        'unixSockets' => [],
        'runtimeRegistry' => 'absent',
        'runtimeConf' => 'absent',
    ]);
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function bootstrapPreflightTreeSnapshot(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $snapshot[$path] = $entry->isFile() ? md5_file($path) : 'dir';
    }

    ksort($snapshot);

    return $snapshot;
}

// =============================================================================
// Static contract
// =============================================================================

it('never sources common and offers no --apply mode', function () {
    // common aborts when deployment.conf is missing — exactly the clean-host
    // situation this script exists for — so the preflight must not source it
    // (or anything else).
    expect(preg_match('/^\s*(source|\.)\s/m', bootstrapPreflightSource()))
        ->toBe(0, 'preflight must not source anything');

    // --apply is not a mode in this slice: it falls through the generic
    // unknown-argument rejection, proven behaviorally.
    [$exit, $output] = bootstrapPreflightRun(['--apply'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --apply');
});

it('contains no mutating service or package commands', function () {
    $source = bootstrapPreflightSource();

    foreach ([
        'apt-get install', 'apt install', 'add-apt-repository', 'apt-key',
        'systemctl start', 'systemctl stop', 'systemctl restart',
        'systemctl reload', 'systemctl enable', 'systemctl disable',
        'ufw ', 'iptables', 'timedatectl set', 'hostnamectl set',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('is listed in the required CLI manifest', function () {
    expect(requiredCliManifestNames())->toContain('bootstrap-host-preflight');
});

// =============================================================================
// CLI semantics
// =============================================================================

it('prints usage on --help and exits 0', function () {
    [$exit, $output] = bootstrapPreflightRun(['--help'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);

    expect($exit)->toBe(0);
    expect($output)->toContain('--check');
    expect($output)->toContain('--report');
    expect($output)->toContain('read-only');
});

it('rejects unknown arguments, a missing mode, and a duplicated mode', function () {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

    [$exit, $output] = bootstrapPreflightRun(['--bogus'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --bogus');

    [$exit, $output] = bootstrapPreflightRun([], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('one of --check or --report is required');

    [$exit, $output] = bootstrapPreflightRun(['--check', '--report'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('mode given more than once');
});

// =============================================================================
// Compliant host (the current staging VPS)
// =============================================================================

it('passes --check on a fully compliant host with zero MISSING, WARN and CONFLICT', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('MISSING: 0');
        expect($output)->toContain('WARN: 0');
        expect($output)->toContain('CONFLICT: 0');
        expect($output)->toContain('HOST READY: YES');
        expect($exit)->toBe(0, "compliant host must pass --check:\n{$output}");
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('recognizes the existing installation as present rather than conflicting', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        foreach ([
            'service:nginx.service — STATE: installed-running',
            'service:php8.5-fpm.service — STATE: installed-running',
            'user:rateguru-staging — exists',
            'user:deploy-rateguru-staging — exists',
            'membership:rateguru-staging:rateguru-staging-code — rateguru-staging is a member',
            'path:/home/www/rateguru — directory, root:root, mode 755',
            'path:/home/www/rateguru/staging/current — symbolic link',
            'registry:runtime — byte-identical to the source registry (parity)',
            'deployment-conf:runtime — byte-identical to the committed template (parity)',
            'port:80 — occupied by expected service nginx.service',
            'socket:/run/php/rateguru-staging.sock — occupied by expected service php8.5-fpm.service',
            'secret:laravel-env:staging-main — present at /home/www/rateguru/staging/shared/.env',
        ] as $needle) {
            expect($output)->toContain($needle);
        }
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Clean host
// =============================================================================

it('fails --check on a clean host while still printing every section and the summary', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('HOST READY: NO');

        foreach (['HOST', 'TOOLS', 'SERVICES', 'USERS/GROUPS', 'FILESYSTEM', 'NETWORK', 'SECRETS REQUIRED LATER', 'SUMMARY'] as $sectionHeader) {
            expect($output)->toContain("\n{$sectionHeader}\n");
        }

        foreach ([
            'MISSING  tool:rclone',
            'MISSING  tool:jq',
            'MISSING  service:nginx.service',
            'MISSING  user:rateguru-staging',
            'MISSING  group:rateguru-staging-code',
            'MISSING  path:/home/www/rateguru — absent',
            'MISSING  registry:runtime — absent',
            'MISSING  secret:laravel-env:staging-main',
            'PASS     os-release — ID=ubuntu VERSION_ID=24.04',
        ] as $needle) {
            expect($output)->toContain($needle);
        }
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('keeps --report usable on a clean host: exit 0 plus intended bootstrap actions', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--report'], $env);

        expect($exit)->toBe(0, "--report is inventory, never a gate:\n{$output}");
        expect($output)->toContain('HOST READY: NO');
        expect($output)->toContain('-> bootstrap: install rclone (slice 5.2)');
        expect($output)->toContain('-> bootstrap: create via slice 5.3 (never by preflight)');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports a partially configured host with both PASS and MISSING items and fails --check', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // Users and tools exist; services and the filesystem do not yet.
        $env = bootstrapPreflightFixture($scratch, [
            'services' => [],
            'statTable' => [],
            'tcpPorts' => [],
            'unixSockets' => [],
            'runtimeRegistry' => 'absent',
            'runtimeConf' => 'absent',
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('PASS     user:rateguru-staging — exists');
        expect($output)->toContain('PASS     tool:jq');
        expect($output)->toContain('MISSING  service:nginx.service');
        expect($output)->toContain('MISSING  path:/home/www/rateguru — absent');

        preg_match('/^PASS: (\d+)$/m', $output, $pass);
        preg_match('/^MISSING: (\d+)$/m', $output, $missing);
        expect((int) $pass[1])->toBeGreaterThan(0);
        expect((int) $missing[1])->toBeGreaterThan(0);
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// OS contract
// =============================================================================

it('treats a wrong OS family as CONFLICT and fails --check even on an otherwise compliant host', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['os' => 'debian']);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT os-release — unsupported OS family ID=debian');
        expect($output)->toContain('HOST READY: NO');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('treats a different Ubuntu version as WARN, not a failure', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['os' => 'ubuntu-22.04']);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     os-release — ID=ubuntu VERSION_ID=22.04 differs from pinned baseline 24.04');
        expect($output)->toContain('HOST READY: YES');
        expect($exit)->toBe(0, "a version drift alone must not fail --check:\n{$output}");
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports missing systemd as MISSING and degrades every service to missing', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['systemd' => false]);
        $env['RATEGURU_PREFLIGHT_SYSTEMCTL_BIN'] = '';
        // Remove systemctl from the fixture PATH too: systemd truly absent.
        unlink($scratch.'/tools/systemctl');

        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  systemd — systemd not detected');
        expect($output)->toContain('MISSING  service:nginx.service — STATE: missing');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Services and network
// =============================================================================

it('reports installed-stopped services as WARN and never starts them', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'services' => [
                'nginx.service' => 'stopped',
                'php8.5-fpm.service' => 'running',
                'postgresql.service' => 'running',
                'redis-server.service' => 'running',
                'supervisor.service' => 'running',
                'staging-mailpit.service' => 'running',
                'staging-mailtrap-local.service' => 'running',
            ],
            // A stopped nginx must not leave 80/443 occupied.
            'tcpPorts' => [5432, 6379, 1025, 8025, 3535, 3550],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     service:nginx.service — STATE: installed-stopped');
        expect($output)->toContain('PASS     port:80 — free');
        expect($exit)->toBe(0, "installed-stopped is a WARN, not a blocker:\n{$output}");
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('labels the mail capture as a shared-host-service, never a per-target service', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        preg_match('/^.*service:staging-mailpit\.service.*$/m', $output, $mailpit);
        preg_match('/^.*service:staging-mailtrap-local\.service.*$/m', $output, $mailtrap);

        expect($mailpit[0])->toContain('shared-host-service');
        expect($mailtrap[0])->toContain('shared-host-service');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('flags a port occupied by an unknown listener as CONFLICT and fails --check', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // Port 80 is occupied but nginx is not even installed.
        $env = bootstrapPreflightFixture($scratch, [
            'services' => [
                'php8.5-fpm.service' => 'running',
                'postgresql.service' => 'running',
                'redis-server.service' => 'running',
                'supervisor.service' => 'running',
                'staging-mailpit.service' => 'running',
                'staging-mailtrap-local.service' => 'running',
            ],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT port:80 — occupied, but expected service nginx.service is not running — occupied/unknown');

        // The intended action (manual resolution, never killing processes)
        // is part of the --report inventory.
        [, $reportOutput] = bootstrapPreflightRun(['--report'], $env);
        expect($reportOutput)->toContain('preflight never kills processes');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Users and groups
// =============================================================================

it('reports a missing runtime user and its impossible group membership as MISSING', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $passwd = str_replace(
            "rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/usr/sbin/nologin\n",
            '',
            bootstrapPreflightCompliantPasswd(),
        );

        $env = bootstrapPreflightFixture($scratch, ['passwd' => $passwd]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  user:rateguru-staging — missing');
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — user or group absent');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports a runtime user missing from the code group as a MISSING membership', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $group = str_replace(
            'rateguru-staging-code:x:1010:rateguru-staging,deploy-rateguru-staging',
            'rateguru-staging-code:x:1010:deploy-rateguru-staging',
            bootstrapPreflightCompliantGroup(),
        );

        $env = bootstrapPreflightFixture($scratch, ['group' => $group]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — rateguru-staging is not a member');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Filesystem contract
// =============================================================================

it('flags wrong ownership, wrong mode, and a symlink where a directory is required as CONFLICT', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = bootstrapPreflightCompliantStatTable();
        $statTable = array_map(fn (string $row): string => match (true) {
            str_starts_with($row, '/home/www/rateguru|') => '/home/www/rateguru|directory|deploy-rateguru-staging|root|755',
            str_starts_with($row, '/etc/sudoers.d/rateguru-deploy|') => '/etc/sudoers.d/rateguru-deploy|regular file|root|root|644',
            str_starts_with($row, '/home/www/rateguru/staging/releases|') => '/home/www/rateguru/staging/releases|symbolic link|root|root|777',
            default => $row,
        }, $statTable);

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru — owned by deploy-rateguru-staging:root, expected owner root');
        expect($output)->toContain('CONFLICT path:/etc/sudoers.d/rateguru-deploy — mode 644, expected 440');
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/releases — is a symbolic link, expected directory');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('flags a regular directory where the current symlink belongs as CONFLICT', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_map(fn (string $row): string => str_starts_with($row, '/home/www/rateguru/staging/current|')
            ? '/home/www/rateguru/staging/current|directory|root|root|755'
            : $row, bootstrapPreflightCompliantStatTable());

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/current — is a directory, expected symbolic link');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Source/runtime registry parity
// =============================================================================

it('reports runtime registry and deployment.conf drift as WARN without modifying either file', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'runtimeRegistry' => 'drift',
            'runtimeConf' => 'drift',
        ]);

        $registryBefore = md5_file($scratch.'/fs/deployment-targets.json');
        $confBefore = md5_file($scratch.'/fs/deployment.conf');

        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     registry:runtime — differs from the source registry (drift)');
        expect($output)->toContain('WARN     deployment-conf:runtime — differs from the committed template (drift)');
        expect($exit)->toBe(0, "drift alone is WARN, not a blocker:\n{$output}");

        expect(md5_file($scratch.'/fs/deployment-targets.json'))->toBe($registryBefore, 'preflight must never modify the runtime registry');
        expect(md5_file($scratch.'/fs/deployment.conf'))->toBe($confBefore, 'preflight must never modify the runtime deployment.conf');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('validates the source registry through the standalone targets CLI', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('PASS     registry:source — valid (1 active target(s): staging-main)');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Secrets
// =============================================================================

it('never prints secret content, even when a probed file contains a sentinel', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // The drifted runtime registry contains a sentinel the script cmp's
        // (bytes read, content never echoed); the .env sentinel lives in a
        // real file the script has no reason to ever open.
        file_put_contents($scratch.'/fs/shared.env', "DB_PASSWORD=env-secret-sentinel-hunter2\n");

        $env = bootstrapPreflightFixture($scratch, ['runtimeRegistry' => 'drift']);
        [, $output] = bootstrapPreflightRun(['--report'], $env);

        expect($output)->not->toContain('hunter2');
        expect($output)->not->toContain('DRIFT-SECRET-SENTINEL');
        expect($output)->not->toContain('env-secret-sentinel');
        expect($output)->toContain('content never read or validated');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('degrades absent secret material to WARN when not running as root', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/root/.config/rclone/rclone.conf|'),
        );

        $rootEnv = bootstrapPreflightFixture($scratch, ['statTable' => array_values($statTable)]);
        [, $rootOutput] = bootstrapPreflightRun(['--check'], $rootEnv);
        expect($rootOutput)->toContain('MISSING  secret:rclone-credentials — absent');

        $userEnv = bootstrapPreflightFixture($scratch, [
            'statTable' => array_values($statTable),
            'euid' => '1000',
        ]);
        [, $userOutput] = bootstrapPreflightRun(['--check'], $userEnv);
        expect($userOutput)->toContain('WARN     secret:rclone-credentials — absent or unverifiable without root');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Read-only and deterministic execution
// =============================================================================

it('mutates nothing in either mode: the simulated host is byte-identical before and after', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        $before = bootstrapPreflightTreeSnapshot($scratch);

        [$checkExit] = bootstrapPreflightRun(['--check'], $env);
        [$reportExit] = bootstrapPreflightRun(['--report'], $env);

        expect($checkExit)->toBe(0);
        expect($reportExit)->toBe(0);
        expect(bootstrapPreflightTreeSnapshot($scratch))->toBe($before, 'preflight must never create, modify or delete anything');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('produces byte-identical output and the same exit code across repeated runs', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);

        [$firstExit, $firstOutput] = bootstrapPreflightRun(['--report'], $env);
        [$secondExit, $secondOutput] = bootstrapPreflightRun(['--report'], $env);

        expect($secondExit)->toBe($firstExit);
        expect($secondOutput)->toBe($firstOutput);
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports summary counts that exactly match the counted item lines', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        foreach (['PASS', 'MISSING', 'WARN', 'CONFLICT'] as $status) {
            preg_match("/^{$status}: (\\d+)$/m", $output, $summary);
            $counted = preg_match_all("/^  {$status}\\s{2}/m", $output);

            expect($counted)->toBe(
                (int) $summary[1],
                "summary {$status} count must match the item lines",
            );
        }
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('ignores every RATEGURU_PREFLIGHT_* override unless test overrides are explicitly allowed', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['os' => 'debian']);
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        [$exit, $output] = bootstrapPreflightRun(['--report'], $env);

        expect($exit)->toBe(0);
        // The debian fixture os-release would print this marker if the
        // ungated override were honored.
        expect($output)->not->toContain('ID=debian');
        expect($output)->not->toContain('sentinel-bookworm');
        expect($output)->not->toContain('preflight-fixture-host');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});
