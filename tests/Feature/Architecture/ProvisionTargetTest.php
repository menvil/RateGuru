<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/provision-target — create the non-secret
 * infrastructure of ONE planned production target on an already-bootstrapped
 * RateGuru host.
 *
 * Every behavioural test here executes the real, shipped orchestrator AND the
 * real, shipped installers it delegates to, as subprocesses, against a fully
 * simulated host: fixture passwd/group files, a fixture filesystem root every
 * canonical path is mapped onto, a layered stat stub that reads real types and
 * modes but fixture ownership, and logging install/chown/chmod/groupadd/
 * useradd/usermod/systemctl/supervisorctl stubs that perform the real work
 * inside the scratch directory while recording every invocation. Nothing here
 * is a reimplementation, and nothing here needs root.
 *
 * The target under test is `demo-shop`: a synthetic production brand that
 * exists nowhere in this repository except in the fixture registry. That is
 * the point — provisioning has to be generic, and a mechanism that only works
 * for tits-guru would pass a tits-guru test and fail the first real second
 * brand. The fixture also carries an ACTIVE staging target and a SECOND
 * planned production target, so isolation is proved against real neighbours
 * rather than against an empty host.
 */

// =============================================================================
// Harness
// =============================================================================

function provisionScript(): string
{
    return base_path('infrastructure/scripts/provision-target');
}

function provisionSource(): string
{
    return File::get(provisionScript());
}

function provisionScratchDir(): string
{
    $dir = sys_get_temp_dir().'/provision-target-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/log', '/svc', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function provisionCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

function provisionWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function provisionRun(array $arguments, array $env, ?string $script = null): array
{
    // The scratch bundle, not the repository's own copy. provision-target
    // resolves its library, its registry and every installer relative to
    // itself, so running the repository's copy against a fixture registry
    // would test a bundle nobody ships: half this file, half that one. The
    // fixture names its bundle, and this is a harness detail rather than
    // something the script reads, so it never reaches the subprocess.
    $script ??= $env['RATEGURU_PROVISION_BUNDLE_SCRIPT'] ?? provisionScript();
    unset($env['RATEGURU_PROVISION_BUNDLE_SCRIPT']);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', $script], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start the provision-target subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function provisionLog(string $scratch, string $name): string
{
    $path = $scratch.'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

// =============================================================================
// The fixture registry: one active staging target, two planned production ones
// =============================================================================

/**
 * A synthetic production brand whose every identity, path, pool, socket,
 * program, queue, scheduler and site name is unique and appears nowhere in
 * this repository.
 *
 * @return array<string, mixed>
 */
function provisionDemoTarget(): array
{
    return [
        'id' => 'demo-shop',
        'lifecycle' => 'planned',
        'environment_class' => 'production',
        'application_root' => '/home/www/rateguru/production/demo-shop',
        'runtime_user' => 'rateguru-demo-shop',
        'runtime_group' => 'rateguru-demo-shop',
        'deploy_user' => 'deploy-rateguru-demo-shop',
        'code_group' => 'rateguru-demo-shop-code',
        'incoming_artifacts' => '/home/deploy-rateguru-demo-shop/incoming',
        'release_retention' => 10,
        'database' => ['name' => 'rateguru_demo_shop', 'application_role' => 'rateguru_demo_shop_app'],
        'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'demo-shop.internal'],
        // Present in the registry and deliberately never rendered anywhere:
        // provisioning must not be able to put a target on the public internet.
        'public_hostnames' => ['demo-shop.example'],
        'backup' => [
            'namespace' => 'demo-shop',
            'local_retention_days' => 30,
            'offsite_retention_days' => 90,
            'minimum_retained_backups' => 2,
        ],
        'php_fpm' => ['pool' => 'rateguru-demo-shop', 'socket' => '/run/php/rateguru-demo-shop.sock'],
        'supervisor' => ['program' => 'rateguru-demo-shop-queue', 'queue' => 'rateguru-demo-shop'],
        'scheduler' => ['name' => 'rateguru-demo-shop-scheduler'],
        'nginx' => ['site_name' => 'rateguru-demo-shop', 'internal_hostname' => 'demo-shop.internal'],
        'environment_template' => 'infrastructure/templates/environment/demo-shop.env.example',
    ];
}

/**
 * The fixture registry: the committed one plus demo-shop.
 *
 * @param  array<string, mixed>  $overrides  dot-free key => value applied to demo-shop
 */
function provisionRegistryJson(array $overrides = [], ?string $lifecycle = null): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);

    $demo = provisionDemoTarget();

    foreach ($overrides as $key => $value) {
        $demo[$key] = $value;
    }

    if ($lifecycle !== null) {
        $demo['lifecycle'] = $lifecycle;
    }

    $registry['targets']['demo-shop'] = $demo;

    return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

/**
 * A scratch copy of the whole infrastructure tree, so the shipped orchestrator
 * and the shipped installers run from one trusted bundle whose registry is the
 * fixture's.
 */
function provisionRepo(string $scratch, string $registryJson, bool $widenActiveAllowlist = false): string
{
    $repo = $scratch.'/repo';

    if (! is_dir($repo)) {
        @mkdir($repo, 0o755, true);
        exec('cp -R '.escapeshellarg(base_path('infrastructure')).' '.escapeshellarg($repo.'/infrastructure').' 2>&1', $out, $code);
        expect($code)->toBe(0, 'could not copy the infrastructure tree: '.implode("\n", $out));
    }

    file_put_contents($repo.'/infrastructure/config/deployment-targets.json', $registryJson);

    // The shipped validator allows exactly one named active target. The
    // activation-parity test needs two, so it widens the allowlist in this
    // scratch copy only — the shipped rule is asserted in its own test.
    $targets = $repo.'/infrastructure/scripts/targets';
    $source = File::get(base_path('infrastructure/scripts/targets'));

    if ($widenActiveAllowlist) {
        $source = str_replace(
            '[[ "${lifecycle}" == "active" ]] && [[ "${target_id}" != "${ACTIVE_ALLOWLIST}" ]]',
            'false',
            $source,
        );
        expect($source)->toContain("if false; then\n        problem \"\${target_id}: lifecycle=active is currently allowed");
    }

    file_put_contents($targets, $source);
    chmod($targets, 0o755);

    return $repo;
}

// =============================================================================
// Stubs
// =============================================================================

function provisionWriteStubs(string $scratch): void
{
    // stat: type from the real scratch filesystem (with a type-table override
    // so a plain fixture file can present as a socket), owner/group from the
    // fixture ownership table, mode real.
    provisionWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        if [[ -L "${path}" ]]; then ftype="symbolic link"
        elif [[ -d "${path}" ]]; then ftype="directory"
        elif [[ -S "${path}" ]]; then ftype="socket"
        elif [[ -f "${path}" ]]; then
            ftype="regular file"
            row_t="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 == "TYPE" { print $3; exit }' "${STUB_TYPE_TABLE}" 2>/dev/null)"
            [[ -n "${row_t}" ]] && ftype="${row_t}"
        elif [[ -e "${path}" ]]; then ftype="other"
        else exit 1; fi
        mode="$(PATH="${STUB_REAL_PATH}" stat -c '%a' -- "${path}" 2>/dev/null)" \
            || mode="$(PATH="${STUB_REAL_PATH}" stat -f '%Mp%Lp' "${path}" 2>/dev/null)" || exit 1
        mode="$(printf '%o' $(( 8#${mode} )))"
        row="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 != "TYPE" { print $2 "|" $3; found = 1; exit } END { exit !found }' "${STUB_OWNER_TABLE}" 2>/dev/null)" || row=""
        if [[ -z "${row}" ]]; then
            row="$(PATH="${STUB_REAL_PATH}" stat -c '%U|%G' -- "${path}" 2>/dev/null)" \
                || row="$(PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg' "${path}" 2>/dev/null)" || exit 1
        fi
        printf '%s|%s|%s\n' "${ftype}" "${row}" "${mode}"
        STUB);

    // chown: records the invocation and upserts the ownership row for exactly
    // the path given — never recursive, exactly like the real tool.
    provisionWriteStub($scratch.'/bin/chown', <<<'STUB'
        #!/bin/bash
        printf 'chown %s\n' "$*" >> "${STUB_LOG}/chown.log"
        owner_group=""; path=""
        for arg in "$@"; do
            case "${arg}" in
                --) ;;
                -*) ;;
                *) if [[ -z "${owner_group}" ]]; then owner_group="${arg}"; else path="${arg}"; fi ;;
            esac
        done
        owner="${owner_group%%:*}"; group="${owner_group##*:}"
        tmp="${STUB_OWNER_TABLE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 != p' "${STUB_OWNER_TABLE}" > "${tmp}" 2>/dev/null || : > "${tmp}"
        printf '%s|%s|%s\n' "${path}" "${owner}" "${group}" >> "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_OWNER_TABLE}"
        exit 0
        STUB);

    provisionWriteStub($scratch.'/bin/chmod', <<<'STUB'
        #!/bin/bash
        printf 'chmod %s\n' "$*" >> "${STUB_LOG}/chmod.log"
        PATH="${STUB_REAL_PATH}" chmod "$@"
        STUB);

    provisionWriteStub($scratch.'/bin/install', <<<'STUB'
        #!/bin/bash
        printf 'install %s\n' "$*" >> "${STUB_LOG}/install.log"
        PATH="${STUB_REAL_PATH}" install "$@"
        STUB);

    // groupadd/useradd/usermod: mutate the fixture group/passwd files the way
    // the real shadow tools mutate /etc — never deleting, never renumbering.
    provisionWriteStub($scratch.'/bin/groupadd', <<<'STUB'
        #!/bin/bash
        printf 'groupadd %s\n' "$*" >> "${STUB_LOG}/identity.log"
        name="${!#}"
        if PATH="${STUB_REAL_PATH}" grep -q "^${name}:" "${STUB_GROUP_FILE}"; then exit 9; fi
        max="$(PATH="${STUB_REAL_PATH}" awk -F: 'BEGIN { m = 4999 } $3 > m && $3 < 60000 { m = $3 } END { print m }' "${STUB_GROUP_FILE}")"
        printf '%s:x:%s:\n' "${name}" "$((max + 1))" >> "${STUB_GROUP_FILE}"
        exit 0
        STUB);

    provisionWriteStub($scratch.'/bin/useradd', <<<'STUB'
        #!/bin/bash
        printf 'useradd %s\n' "$*" >> "${STUB_LOG}/identity.log"
        login="${!#}"
        gid_name=""; home=""; shell=""; prev=""
        for arg in "$@"; do
            case "${prev}" in
                --gid) gid_name="${arg}" ;;
                --home-dir) home="${arg}" ;;
                --shell) shell="${arg}" ;;
            esac
            prev="${arg}"
        done
        if PATH="${STUB_REAL_PATH}" grep -q "^${login}:" "${STUB_PASSWD_FILE}"; then exit 9; fi
        gid="$(PATH="${STUB_REAL_PATH}" awk -F: -v g="${gid_name}" '$1 == g { print $3; exit }' "${STUB_GROUP_FILE}")"
        [[ -n "${gid}" ]] || exit 6
        max="$(PATH="${STUB_REAL_PATH}" awk -F: 'BEGIN { m = 4999 } $3 > m && $3 < 60000 { m = $3 } END { print m }' "${STUB_PASSWD_FILE}")"
        printf '%s:x:%s:%s::%s:%s\n' "${login}" "$((max + 1))" "${gid}" "${home}" "${shell}" >> "${STUB_PASSWD_FILE}"
        exit 0
        STUB);

    provisionWriteStub($scratch.'/bin/usermod', <<<'STUB'
        #!/bin/bash
        printf 'usermod %s\n' "$*" >> "${STUB_LOG}/identity.log"
        login="${!#}"
        groups=""; prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "--groups" || "${prev}" == "-G" ]]; then groups="${arg}"; fi
            prev="${arg}"
        done
        tmp="${STUB_GROUP_FILE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F: -v OFS=: -v g="${groups}" -v u="${login}" '
            $1 == g {
                if ($4 == "") { $4 = u }
                else if (index("," $4 ",", "," u ",") == 0) { $4 = $4 "," u }
            }
            { print }
        ' "${STUB_GROUP_FILE}" > "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_GROUP_FILE}"
        exit 0
        STUB);

    // systemctl: stateful. A reload respawns the simulated Nginx workers with
    // whatever supplementary groups www-data holds AT THAT MOMENT, which is
    // exactly why a reload is what fixes stale workers on a real host.
    provisionWriteStub($scratch.'/bin/systemctl', <<<'STUB'
        #!/bin/bash
        printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/systemctl.log"
        respawn_nginx_workers() {
            gids="$(PATH="${STUB_REAL_PATH}" awk -F: -v u=www-data '
                $1 == u { own = $3 }
                ($4 ~ ("(^|,)" u "(,|$)")) { extra = extra " " $3 }
                END { printf "%s%s", own, extra }
            ' "${STUB_GROUP_FILE}")"
            PATH="${STUB_REAL_PATH}" rm -rf "${STUB_FS}/proc"
            : > "${STUB_FS}/nginx-worker-pids.txt"
            for pid in 9001 9002; do
                PATH="${STUB_REAL_PATH}" mkdir -p "${STUB_FS}/proc/${pid}"
                printf 'Name:\tnginx\nGroups:\t%s \n' "${gids}" > "${STUB_FS}/proc/${pid}/status"
                printf '%s\n' "${pid}" >> "${STUB_FS}/nginx-worker-pids.txt"
            done
        }
        cmd=""; unit=""
        for arg in "$@"; do
            case "${arg}" in
                --quiet) ;;
                *) if [[ -z "${cmd}" ]]; then cmd="${arg}"; else unit="${arg}"; fi ;;
            esac
        done
        unit="${unit%.service}"
        case "${cmd}" in
            is-enabled) [[ -e "${STUB_SVC_STATE}/${unit}.enabled" ]] ;;
            is-active)  [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] ;;
            enable)  touch "${STUB_SVC_STATE}/${unit}.enabled" ;;
            disable) rm -f "${STUB_SVC_STATE}/${unit}.enabled" ;;
            start)
                touch "${STUB_SVC_STATE}/${unit}.active"
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                ;;
            stop)    rm -f "${STUB_SVC_STATE}/${unit}.active" ;;
            reload|restart)
                [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] || exit 1
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                ;;
            *) exit 0 ;;
        esac
        STUB);

    provisionWriteStub($scratch.'/bin/pgrep', <<<'STUB'
        #!/bin/bash
        [[ -s "${STUB_FS}/nginx-worker-pids.txt" ]] || exit 1
        PATH="${STUB_REAL_PATH}" cat "${STUB_FS}/nginx-worker-pids.txt"
        STUB);

    foreach (['nginx' => 'nginx', 'sshd' => 'sshd', 'php-fpm8.5' => 'php-fpm'] as $bin => $log) {
        provisionWriteStub($scratch.'/bin/'.$bin, <<<STUB
            #!/bin/bash
            printf '{$log} %s\\n' "\$*" >> "\${STUB_LOG}/{$log}.log"
            [[ -e "\${STUB_TOGGLES}/{$log}-t-fail" ]] && exit 1
            exit 0
            STUB);
    }

    provisionWriteStub($scratch.'/bin/supervisorctl', <<<'STUB'
        #!/bin/bash
        printf 'supervisorctl %s\n' "$*" >> "${STUB_LOG}/supervisorctl.log"
        case "${1:-}" in
            reread)
                [[ -e "${STUB_TOGGLES}/supervisor-reread-fail" ]] && { echo "ERROR: CANT_REREAD bad config"; exit 0; }
                echo "No config updates to processes"
                ;;
            status)
                echo "${2:-unknown}: ERROR (no such process)"
                exit 1
                ;;
        esac
        exit 0
        STUB);

    // One stub per child installer the services installer coordinates but
    // this operation does not exercise directly. Each answers verify from its
    // own compliance toggle and records every invocation, so a test can prove
    // both what was asked and what was never asked.
    foreach ([
        'runtime-installer', 'operations-installer', 'perimeter-installer',
        'public-storage-installer', 'mail-capture-installer', 'verify-mail-capture',
        'nightwatch-installer',
    ] as $child) {
        provisionWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            case "$*" in
                # The closed allowlist question, answered the way the real
                # installer answers it: staging-main records a deployment
                # marker, and no other target does.
                *--supports-deployment-marker*)
                    [[ "$*" == *"--target staging-main"* ]] && exit 0
                    exit 1
                    ;;
                *--apply*)
                    [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]] && exit 1
                    touch "${STUB_TOGGLES}/${me}-compliant"
                    exit 0
                    ;;
                *)
                    [[ -e "${STUB_TOGGLES}/${me}-compliant" ]] && exit 0
                    exit 1
                    ;;
            esac
            STUB);
    }
}

// =============================================================================
// Filesystem fixture
// =============================================================================

function provisionOwnerTableAdd(string $scratch, string $physical, string $owner, string $group): void
{
    $table = $scratch.'/fs/owner-table.txt';
    $rows = array_filter(
        explode("\n", (string) @file_get_contents($table)),
        fn (string $row): bool => $row !== '' && ! str_starts_with($row, $physical.'|'),
    );
    $rows[] = "{$physical}|{$owner}|{$group}";
    file_put_contents($table, implode("\n", $rows)."\n");
}

/**
 * @return array<string, array{0: string, 1: string}> physical => [owner, group]
 */
function provisionOwnerTableRows(string $scratch): array
{
    $rows = [];

    foreach (explode("\n", (string) @file_get_contents($scratch.'/fs/owner-table.txt')) as $line) {
        if ($line === '') {
            continue;
        }

        [$path, $owner, $group] = explode('|', $line);
        $rows[$path] = [$owner, $group];
    }

    return $rows;
}

/**
 * The host roots install-bootstrap-host-layout treats as prerequisites in
 * every target-scoped run: logical path => [owner, group, mode].
 *
 * @return array<string, array{0: string, 1: string, 2: int}>
 */
function provisionHostRoots(): array
{
    return [
        '/home/www/rateguru' => ['root', 'root', 0o755],
        '/home/www/rateguru/config' => ['root', 'root', 0o755],
        '/home/www/rateguru/bin' => ['root', 'root', 0o755],
        '/home/www/rateguru/backups' => ['root', 'root', 0o700],
        '/home/www/rateguru/run' => ['root', 'root', 0o700],
        '/var/log/rateguru' => ['root', 'root', 0o750],
    ];
}

/**
 * The active staging neighbour, built exactly as a converged host has it, so
 * "provisioning a new target changed nothing about the live one" is proved
 * against real state rather than against absence.
 */
function provisionBuildStagingNeighbour(string $scratch): void
{
    $fs = $scratch.'/fs';
    $root = $fs.'/home/www/rateguru/staging';

    foreach ([
        '/releases/20240101120000', '/shared/storage/logs', '/shared/storage/app/public',
        '/locks', '/deployments',
    ] as $sub) {
        @mkdir($root.$sub, 0o755, true);
    }

    chmod($root.'/releases', 0o2750);
    chmod($root.'/shared', 0o2770);
    chmod($root.'/shared/storage', 0o2770);
    chmod($root.'/shared/storage/logs', 0o2770);
    chmod($root.'/locks', 0o2750);
    chmod($root.'/deployments', 0o2750);

    file_put_contents($root.'/shared/.env', "APP_KEY=base64:STAGING-ENV-SENTINEL\n");
    file_put_contents($root.'/shared/storage/app/public/upload.jpg', 'STAGING-UPLOAD-SENTINEL');
    file_put_contents($root.'/shared/storage/logs/laravel.log', "STAGING-LOG-SENTINEL\n");
    file_put_contents($root.'/releases/20240101120000/artisan', "<?php // STAGING-RELEASE-SENTINEL\n");
    symlink($root.'/releases/20240101120000', $root.'/current');
    symlink($root.'/releases/20240101120000', $root.'/previous');

    @mkdir($fs.'/home/deploy-rateguru-staging/.ssh', 0o700, true);
    @mkdir($fs.'/home/deploy-rateguru-staging/incoming', 0o750, true);
    file_put_contents($fs.'/home/deploy-rateguru-staging/.ssh/authorized_keys', "ssh-ed25519 AAAA-STAGING-KEY sentinel\n");

    foreach ([
        '/etc/nginx/sites-available/rateguru-staging' => 'infrastructure/config/nginx/rateguru-staging',
        '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf' => 'infrastructure/config/php-fpm/rateguru-staging.conf',
        '/etc/supervisor/conf.d/rateguru-staging-queue.conf' => 'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        '/etc/cron.d/rateguru-staging-scheduler' => 'infrastructure/config/cron/rateguru-staging-scheduler',
    ] as $installed => $committed) {
        copy(base_path($committed), $fs.$installed);
        chmod($fs.$installed, 0o644);
        provisionOwnerTableAdd($scratch, $fs.$installed, 'root', 'root');
    }

    symlink('/etc/nginx/sites-available/rateguru-staging', $fs.'/etc/nginx/sites-enabled/rateguru-staging');

    touch($fs.'/run/php/rateguru-staging.sock');
    chmod($fs.'/run/php/rateguru-staging.sock', 0o660);
    file_put_contents($fs.'/type-table.txt', $fs."/run/php/rateguru-staging.sock|TYPE|socket\n", FILE_APPEND);
    provisionOwnerTableAdd($scratch, $fs.'/run/php/rateguru-staging.sock', 'www-data', 'www-data');
}

/**
 * Everything about `demo-shop` a converged host would have, for the
 * idempotency and drift scenarios that start from an already-provisioned
 * target rather than an empty one.
 */
function provisionSnapshotDemoState(string $scratch): array
{
    $fs = $scratch.'/fs';
    $snapshot = [];

    foreach ([
        '/home/www/rateguru/production/demo-shop',
        '/home/deploy-rateguru-demo-shop',
        '/etc/nginx/sites-available/rateguru-demo-shop',
        '/etc/nginx/sites-enabled/rateguru-demo-shop',
        '/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf',
        '/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf',
        '/etc/cron.d/rateguru-demo-shop-scheduler',
    ] as $logical) {
        $snapshot += provisionTreeSnapshot($fs.$logical);
    }

    return $snapshot;
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function provisionTreeSnapshot(string $path): array
{
    if (! file_exists($path) && ! is_link($path)) {
        return [];
    }

    if (is_link($path)) {
        return [$path => 'link:'.readlink($path)];
    }

    if (is_file($path)) {
        return [$path => md5_file($path).':'.substr(sprintf('%o', fileperms($path)), -4)];
    }

    $snapshot = [$path => 'dir:'.substr(sprintf('%o', fileperms($path)), -4)];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if (is_link($entryPath)) {
            $snapshot[$entryPath] = 'link:'.readlink($entryPath);
        } elseif ($entry->isFile()) {
            $snapshot[$entryPath] = md5_file($entryPath).':'.substr(sprintf('%o', fileperms($entryPath)), -4);
        } else {
            $snapshot[$entryPath] = 'dir:'.substr(sprintf('%o', fileperms($entryPath)), -4);
        }
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * Build the simulated host and return the environment provision-target and
 * every installer it delegates to run against.
 *
 * Options:
 *   registry:             JSON override for the whole fixture registry
 *   demoOverrides:        dot-free key => value applied to the demo-shop entry
 *   demoLifecycle:        lifecycle override for demo-shop
 *   widenActiveAllowlist: allow more than one active target in the scratch
 *                         validator (activation-parity scenario only)
 *   euid:                 string (default '0')
 *   passwdExtra:          extra fixture /etc/passwd lines
 *   groupExtra:           extra fixture /etc/group lines
 *   omitHostRoots:        list<string> host roots NOT created
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function provisionFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';

    $registryJson = $options['registry']
        ?? provisionRegistryJson($options['demoOverrides'] ?? [], $options['demoLifecycle'] ?? null);

    $repo = provisionRepo($scratch, $registryJson, (bool) ($options['widenActiveAllowlist'] ?? false));

    foreach ([
        '/home', '/var/log', '/etc/nginx/sites-available', '/etc/nginx/sites-enabled',
        '/etc/php/8.5/fpm/pool.d', '/etc/supervisor/conf.d', '/etc/cron.d',
        '/etc/ssh/sshd_config.d', '/usr/bin', '/run/php', '/var/backups',
    ] as $sub) {
        @mkdir($fs.$sub, 0o755, true);
    }

    touch($fs.'/usr/bin/php8.5');

    file_put_contents($fs.'/owner-table.txt', '');
    file_put_contents($fs.'/type-table.txt', '');

    $omitted = $options['omitHostRoots'] ?? [];

    foreach (provisionHostRoots() as $logical => [$owner, $group, $mode]) {
        if (in_array($logical, $omitted, true)) {
            continue;
        }

        @mkdir($fs.$logical, 0o755, true);
        chmod($fs.$logical, $mode);
        provisionOwnerTableAdd($scratch, $fs.$logical, $owner, $group);
    }

    // The production namespace directory itself is a plain parent, not a
    // managed entry: install-bootstrap-host-layout creates the target root
    // inside it and requires only that it be a real directory.
    @mkdir($fs.'/home/www/rateguru/production', 0o755, true);
    provisionOwnerTableAdd($scratch, $fs.'/home/www/rateguru/production', 'root', 'root');

    file_put_contents($fs.'/etc-passwd', implode("\n", array_merge([
        'root:x:0:0:root:/root:/bin/bash',
        'www-data:x:33:33::/var/www:/usr/sbin/nologin',
        'postgres:x:110:118::/var/lib/postgresql:/bin/bash',
        'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash',
    ], $options['passwdExtra'] ?? []))."\n");

    file_put_contents($fs.'/etc-group', implode("\n", array_merge([
        'root:x:0:',
        'www-data:x:33:',
        'postgres:x:118:',
        'rateguru-staging:x:5001:',
        'deploy-rateguru-staging:x:5002:',
        'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
    ], $options['groupExtra'] ?? []))."\n");

    provisionWriteStubs($scratch);
    provisionBuildStagingNeighbour($scratch);

    // The host's installed runtime registry. A prepared host has the same
    // revision the trusted bundle carries; the tests that matter here are the
    // ones that make it differ.
    @mkdir($fs.'/home/www/rateguru/etc', 0o755, true);
    file_put_contents(
        $fs.'/home/www/rateguru/etc/deployment-targets.json',
        $options['installedRegistryJson'] ?? File::get($repo.'/infrastructure/config/deployment-targets.json'),
    );

    // The demo pool's socket, as a running PHP-FPM would present it.
    touch($fs.'/run/php/rateguru-demo-shop.sock');
    chmod($fs.'/run/php/rateguru-demo-shop.sock', 0o660);
    file_put_contents($fs.'/type-table.txt', $fs."/run/php/rateguru-demo-shop.sock|TYPE|socket\n", FILE_APPEND);
    provisionOwnerTableAdd($scratch, $fs.'/run/php/rateguru-demo-shop.sock', 'www-data', 'www-data');

    // Nginx workers that predate every RateGuru code group — the state a real
    // host is in before its first reload.
    @mkdir($fs.'/proc/4101', 0o755, true);
    file_put_contents($fs.'/proc/4101/status', "Name:\tnginx\nGroups:\t33 \n");
    file_put_contents($fs.'/nginx-worker-pids.txt', "4101\n");

    // Every base host service is already enabled and running: provisioning
    // runs on a prepared host and never starts one.
    foreach (['ssh', 'nginx', 'postgresql', 'redis-server', 'supervisor', 'php8.5-fpm'] as $unit) {
        touch($scratch.'/svc/'.$unit.'.enabled');
        touch($scratch.'/svc/'.$unit.'.active');
    }

    // The host-wide children a target-scoped run never converges are already
    // compliant, so their state can never be mistaken for something this
    // operation did.
    foreach ([
        'runtime-installer', 'operations-installer', 'perimeter-installer',
        'public-storage-installer', 'mail-capture-installer', 'verify-mail-capture',
        'nightwatch-installer',
    ] as $child) {
        touch($scratch.'/toggles/'.$child.'-compliant');
    }

    $realPath = getenv('PATH') ?: '/usr/bin:/bin';

    return [
        'PATH' => $realPath,
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',

        // The orchestrator, run from the scratch bundle. There is deliberately
        // no seam for the library or the registry it resolves: it reads the
        // `common` and the `config/deployment-targets.json` beside itself, so
        // the bundle under test is the one that decides what provisioning
        // means.
        'RATEGURU_PROVISION_BUNDLE_SCRIPT' => $repo.'/infrastructure/scripts/provision-target',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),

        // The host's installed operational bundle, as a prerequisite rather
        // than as an implementation: a verify gate that answers from its own
        // compliance toggle, and the runtime registry it would have installed.
        'RATEGURU_PROVISION_OPERATIONS_BIN' => $scratch.'/bin/operations-installer',
        'RATEGURU_PROVISION_INSTALLED_REGISTRY' => $options['installedRegistry'] ?? $fs.'/home/www/rateguru/etc/deployment-targets.json',
        'RATEGURU_TARGET_REGISTRY_FILE' => $repo.'/infrastructure/config/deployment-targets.json',
        'RATEGURU_TARGETS_CLI' => $repo.'/infrastructure/scripts/targets',
        'RATEGURU_PROVISION_EUID' => $options['euid'] ?? '0',
        'RATEGURU_PROVISION_FS_ROOT' => $fs,
        'RATEGURU_PROVISION_RUNTIME_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_PROVISION_HOSTLAYOUT_BIN' => $repo.'/infrastructure/scripts/install-bootstrap-host-layout',
        'RATEGURU_PROVISION_SERVICES_BIN' => $repo.'/infrastructure/scripts/install-bootstrap-services',

        // The real host-layout installer, against the simulated host.
        'RATEGURU_HOSTLAYOUT_EUID' => '0',
        'RATEGURU_HOSTLAYOUT_FS_ROOT' => $fs,
        'RATEGURU_HOSTLAYOUT_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_HOSTLAYOUT_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_HOSTLAYOUT_SOURCE_REGISTRY' => $repo.'/infrastructure/config/deployment-targets.json',
        'RATEGURU_HOSTLAYOUT_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_HOSTLAYOUT_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_HOSTLAYOUT_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_HOSTLAYOUT_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_HOSTLAYOUT_GROUPADD_BIN' => $scratch.'/bin/groupadd',
        'RATEGURU_HOSTLAYOUT_USERADD_BIN' => $scratch.'/bin/useradd',
        'RATEGURU_HOSTLAYOUT_USERMOD_BIN' => $scratch.'/bin/usermod',

        // The real services installer, against the same simulated host.
        'RATEGURU_BOOTSTRAPSVC_EUID' => '0',
        'RATEGURU_BOOTSTRAPSVC_FS_ROOT' => $fs,
        'RATEGURU_BOOTSTRAPSVC_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_BOOTSTRAPSVC_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_BOOTSTRAPSVC_SOURCE_REGISTRY' => $repo.'/infrastructure/config/deployment-targets.json',
        'RATEGURU_BOOTSTRAPSVC_PGREP_BIN' => $scratch.'/bin/pgrep',
        'RATEGURU_BOOTSTRAPSVC_NGINX_WORKER_WAIT_ATTEMPTS' => '2',
        'RATEGURU_BOOTSTRAPSVC_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_BOOTSTRAPSVC_HOSTLAYOUT_INSTALLER_BIN' => $repo.'/infrastructure/scripts/install-bootstrap-host-layout',
        'RATEGURU_BOOTSTRAPSVC_OPERATIONS_INSTALLER_BIN' => $scratch.'/bin/operations-installer',
        'RATEGURU_BOOTSTRAPSVC_PERIMETER_INSTALLER_BIN' => $scratch.'/bin/perimeter-installer',
        'RATEGURU_BOOTSTRAPSVC_PUBLIC_STORAGE_INSTALLER_BIN' => $scratch.'/bin/public-storage-installer',
        'RATEGURU_BOOTSTRAPSVC_NIGHTWATCH_INSTALLER_BIN' => $scratch.'/bin/nightwatch-installer',
        'RATEGURU_BOOTSTRAPSVC_MAIL_CAPTURE_INSTALLER_BIN' => $scratch.'/bin/mail-capture-installer',
        'RATEGURU_BOOTSTRAPSVC_VERIFY_MAIL_CAPTURE_BIN' => $scratch.'/bin/verify-mail-capture',
        'RATEGURU_BOOTSTRAPSVC_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_BOOTSTRAPSVC_NGINX_BIN' => $scratch.'/bin/nginx',
        'RATEGURU_BOOTSTRAPSVC_SSHD_BIN' => $scratch.'/bin/sshd',
        'RATEGURU_BOOTSTRAPSVC_PHP_FPM_BIN' => $scratch.'/bin/php-fpm8.5',
        'RATEGURU_BOOTSTRAPSVC_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_BOOTSTRAPSVC_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_BOOTSTRAPSVC_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_BOOTSTRAPSVC_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_BOOTSTRAPSVC_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_BOOTSTRAPSVC_SOCKET_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_STABILITY_WAIT' => '0',
        'RATEGURU_BOOTSTRAPSVC_RETRY_DELAY' => '0',

        'STUB_LOG' => $scratch.'/log',
        'STUB_REAL_PATH' => $realPath,
        'STUB_OWNER_TABLE' => $fs.'/owner-table.txt',
        'STUB_TYPE_TABLE' => $fs.'/type-table.txt',
        'STUB_PASSWD_FILE' => $fs.'/etc-passwd',
        'STUB_GROUP_FILE' => $fs.'/etc-group',
        'STUB_SVC_STATE' => $scratch.'/svc',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];
}

/**
 * The one machine-readable line, decoded.
 *
 * @return array<string, mixed>
 */
function provisionResultLine(string $output): array
{
    $matches = [];
    preg_match_all('/^RATEGURU_PROVISION_RESULT=(.*)$/m', $output, $matches);

    expect($matches[1])->toHaveCount(1, "expected exactly one RATEGURU_PROVISION_RESULT line:\n{$output}");

    return json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR);
}

// =============================================================================
// Genericity: the whole operation, end to end, on a synthetic brand
// =============================================================================

it('reports a planned production target as unprovisioned, and names every mutation an apply would make', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, "an unprovisioned target must not report as provisioned:\n{$output}");

        expect($output)
            ->toContain('RateGuru provision target (check mode)')
            ->toContain('target:demo-shop — lifecycle=planned, environment_class=production')
            ->toContain('TARGET INFRASTRUCTURE: NOT PROVISIONED')
            ->toContain('MISSING  layout:install-bootstrap-host-layout')
            ->toContain('MISSING  services:install-bootstrap-services')
            ->toContain('install-bootstrap-host-layout --apply --target demo-shop --provisioning')
            ->toContain('install-bootstrap-services --apply --target demo-shop --provisioning');

        // The host it runs on is already a RateGuru host, and says so — the
        // operational bundle it has installed is the one this bundle expects,
        // down to the runtime registry every decision here was read from.
        expect($output)
            ->toContain('PASS     host:install-bootstrap-runtime')
            ->toContain('PASS     host:install-target-operations')
            ->toContain('PASS     host:runtime-registry')
            ->toContain('PASS     state:demo-shop — no deployment-owned state');

        // Everything a later phase owns is named rather than silently absent.
        expect($output)
            ->toContain('DEFERRED deferred:database')
            ->toContain('DEFERRED deferred:environment')
            ->toContain('DEFERRED deferred:deploy-authorization')
            ->toContain('DEFERRED deferred:public-routing')
            ->toContain('DEFERRED deferred:queue-runtime');

        // Strictly read-only: not one mutation of any kind.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
        expect(provisionLog($scratch, 'systemctl.log'))
            ->not->toContain('systemctl enable')
            ->not->toContain('systemctl start')
            ->not->toContain('systemctl reload');
    } finally {
        provisionCleanup($scratch);
    }
});

it('provisions the whole target from the registry alone, and proves every generic value reached its config', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';
        $root = $fs.'/home/www/rateguru/production/demo-shop';

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('PROVISION TARGET: demo-shop')
            ->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            ->toContain('LIFECYCLE: planned')
            ->toContain('APPLICATION: NOT DEPLOYED')
            ->toContain('PUBLIC TRAFFIC: NOT ACTIVATED')
            ->toContain('SECRETS: DEFERRED')
            ->toContain('DATABASE: DEFERRED')
            ->toContain('QUEUE: DEFERRED')
            // The one thing a success line must never claim.
            ->not->toContain('PRODUCTION READY');

        $result = provisionResultLine($output);
        expect($result)->toBe([
            'status' => 'infrastructure-provisioned',
            'target' => 'demo-shop',
            'lifecycle' => 'planned',
            'environment_class' => 'production',
            'application_state' => 'not-deployed',
            'public_state' => 'not-activated',
        ]);

        // --- identities -----------------------------------------------------
        $group = (string) file_get_contents($fs.'/etc-group');
        $passwd = (string) file_get_contents($fs.'/etc-passwd');

        expect($group)
            ->toMatch('/^rateguru-demo-shop:x:\d+:/m')
            ->toMatch('/^rateguru-demo-shop-code:x:\d+:.*rateguru-demo-shop/m')
            ->toMatch('/^rateguru-demo-shop-code:x:\d+:.*www-data/m')
            ->toMatch('/^deploy-rateguru-demo-shop:x:\d+:/m');

        // www-data joins the CODE group and never a runtime group.
        expect($group)->not->toMatch('/^rateguru-demo-shop:x:\d+:.*www-data/m');

        expect($passwd)
            ->toMatch('#^rateguru-demo-shop:x:\d+:\d+::[^:]*:/usr/sbin/nologin$#m')
            ->toMatch('#^deploy-rateguru-demo-shop:x:\d+:\d+::/home/deploy-rateguru-demo-shop:/bin/bash$#m');

        // --- filesystem, with the proven staging boundaries -----------------
        $rows = provisionOwnerTableRows($scratch);
        $mode = fn (string $path): string => substr(sprintf('%o', fileperms($path)), -4);

        $expected = [
            $root => ['root', 'root', '0755'],
            $root.'/releases' => ['deploy-rateguru-demo-shop', 'rateguru-demo-shop-code', '2750'],
            $root.'/shared' => ['rateguru-demo-shop', 'rateguru-demo-shop', '2770'],
            $root.'/shared/storage' => ['rateguru-demo-shop', 'rateguru-demo-shop', '2770'],
            $root.'/shared/storage/logs' => ['rateguru-demo-shop', 'rateguru-demo-shop', '2770'],
            $root.'/locks' => ['deploy-rateguru-demo-shop', 'rateguru-demo-shop-code', '2750'],
            $root.'/deployments' => ['deploy-rateguru-demo-shop', 'rateguru-demo-shop-code', '2750'],
            $fs.'/home/deploy-rateguru-demo-shop' => ['deploy-rateguru-demo-shop', 'deploy-rateguru-demo-shop', '0750'],
            $fs.'/home/deploy-rateguru-demo-shop/.ssh' => ['deploy-rateguru-demo-shop', 'deploy-rateguru-demo-shop', '0700'],
            $fs.'/home/deploy-rateguru-demo-shop/incoming' => ['deploy-rateguru-demo-shop', 'deploy-rateguru-demo-shop', '0750'],
        ];

        foreach ($expected as $path => [$owner, $ownerGroup, $expectedMode]) {
            expect(is_dir($path))->toBeTrue("missing provisioned directory: {$path}");
            expect($rows[$path] ?? null)->toBe([$owner, $ownerGroup], "wrong ownership on {$path}");
            expect($mode($path))->toBe($expectedMode, "wrong mode on {$path}");
        }

        // Deployment-owned paths are never fabricated.
        expect(file_exists($root.'/current'))->toBeFalse('provisioning must never create current');
        expect(file_exists($root.'/previous'))->toBeFalse('provisioning must never create previous');
        expect(is_link($root.'/current'))->toBeFalse();
        expect(scandir($root.'/releases'))->toBe(['.', '..'], 'provisioning must never create a release');

        // --- the rendered service configuration -----------------------------
        $nginx = (string) file_get_contents($fs.'/etc/nginx/sites-available/rateguru-demo-shop');
        expect($nginx)
            ->toContain('server_name demo-shop.internal;')
            ->toContain('root /home/www/rateguru/production/demo-shop/current/public;')
            ->toContain('fastcgi_pass unix:/run/php/rateguru-demo-shop.sock;')
            ->toContain('allow 127.0.0.1;')
            ->toContain('deny all;');

        // The public half of the registry never reaches the rendered vhost.
        expect($nginx)
            ->not->toContain('demo-shop.example')
            ->not->toContain('listen 443')
            ->not->toContain('ssl_certificate')
            ->not->toContain('letsencrypt')
            ->not->toContain('return 301');

        expect(is_link($fs.'/etc/nginx/sites-enabled/rateguru-demo-shop'))->toBeTrue();

        $pool = (string) file_get_contents($fs.'/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf');
        expect($pool)
            ->toContain('[rateguru-demo-shop]')
            ->toContain('user = rateguru-demo-shop')
            ->toContain('group = rateguru-demo-shop')
            ->toContain('listen = /run/php/rateguru-demo-shop.sock')
            ->toContain('/home/www/rateguru/production/demo-shop/shared/storage/logs/php-fpm-error.log');

        $supervisor = (string) file_get_contents($fs.'/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf');
        expect($supervisor)
            ->toContain('[program:rateguru-demo-shop-queue]')
            // The working directory is the application root, which exists the
            // moment provisioning finishes; current/ is entered by the command
            // once it is really there. See the PRE_DEPLOY-safety tests below.
            ->toContain('directory=/home/www/rateguru/production/demo-shop'."\n")
            ->toContain('cd /home/www/rateguru/production/demo-shop/current')
            ->toContain('--queue=rateguru-demo-shop ')
            ->toContain('user=rateguru-demo-shop')
            ->toContain('environment=APP_ENV="production"');

        $cron = (string) file_get_contents($fs.'/etc/cron.d/rateguru-demo-shop-scheduler');
        expect($cron)
            // Guarded on current/ existing, because a provisioned target has
            // no release yet and cron mails root whatever a job writes: an
            // unguarded cd would send a failure a minute from here until the
            // first deployment.
            ->toContain('rateguru-demo-shop [ -d /home/www/rateguru/production/demo-shop/current ] || exit 0;')
            ->toContain('cd /home/www/rateguru/production/demo-shop/current')
            ->toContain('/usr/bin/php8.5 artisan schedule:run');

        // Nothing rendered mentions the target this repository happens to ship
        // a planned entry for: the mechanism is generic, not a brand installer.
        foreach ([$nginx, $pool, $supervisor, $cron] as $rendered) {
            expect($rendered)->not->toContain('tits');
        }

        // --- what provisioning must never have done -------------------------
        // The queue worker is configured and deliberately not started.
        expect(provisionLog($scratch, 'supervisorctl.log'))
            ->toContain('supervisorctl reread')
            ->not->toContain('supervisorctl start')
            ->not->toContain('supervisorctl update');
        expect($output)->toContain('activation DEFERRED until the first release exists');

        // No database, no secret material, no offsite credential. The two
        // installers that would create them are never reachable from here at
        // all: neither the orchestrator nor the service installer names them.
        expect(file_exists($root.'/shared/.env'))->toBeFalse();
        expect(file_exists($fs.'/home/deploy-rateguru-demo-shop/.ssh/authorized_keys'))->toBeFalse();

        foreach ([
            'infrastructure/scripts/provision-target',
            'infrastructure/scripts/install-bootstrap-services',
            'infrastructure/scripts/install-bootstrap-host-layout',
        ] as $script) {
            expect(executableSourceLines(File::get(base_path($script))))
                ->not->toContain('install-target-database')
                ->not->toContain('install-target-prerequisites');
        }

        // Host-wide families are never converged by a target-scoped run.
        $children = provisionLog($scratch, 'children.log');
        expect($children)
            ->not->toContain('operations-installer --apply')
            ->not->toContain('perimeter-installer --apply')
            ->not->toContain('mail-capture-installer --apply');

        // No base service was enabled or started: this host was already
        // prepared, and provisioning never starts one.
        $systemctl = provisionLog($scratch, 'systemctl.log');
        expect($systemctl)
            ->not->toContain('systemctl enable')
            ->not->toContain('systemctl start')
            ->not->toContain('systemctl restart');
        expect($systemctl)->toContain('systemctl reload');
    } finally {
        provisionCleanup($scratch);
    }
});

it('verifies a provisioned target read-only, and a second apply converges nothing', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0);

        $afterFirstApply = provisionSnapshotDemoState($scratch);
        $identityLog = provisionLog($scratch, 'identity.log');

        // --- --verify --------------------------------------------------------
        [$verifyExit, $verifyOutput] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($verifyExit)->toBe(0, $verifyOutput);
        expect($verifyOutput)
            ->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            ->toContain('LIFECYCLE: planned')
            ->toContain('APPLICATION: NOT DEPLOYED')
            ->toContain('PUBLIC TRAFFIC: NOT ACTIVATED')
            ->toContain('PASS     layout:install-bootstrap-host-layout')
            ->toContain('PASS     services:install-bootstrap-services');

        expect(provisionResultLine($verifyOutput)['status'])->toBe('infrastructure-provisioned');
        expect(provisionSnapshotDemoState($scratch))->toBe($afterFirstApply, '--verify must change nothing');

        // --- second --apply --------------------------------------------------
        [$secondExit, $secondOutput] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($secondExit)->toBe(0, $secondOutput);
        expect($secondOutput)
            ->toContain('CHANGED: FALSE')
            ->toContain("nothing to converge — this target's infrastructure is already provisioned");

        expect(provisionSnapshotDemoState($scratch))
            ->toBe($afterFirstApply, 'a second apply on a correct target must perform zero meaningful mutation');

        expect(provisionLog($scratch, 'identity.log'))
            ->toBe($identityLog, 'a second apply must create no account, group or membership');
    } finally {
        provisionCleanup($scratch);
    }
});

it('leaves the live staging target byte-identical while a new production target is built beside it', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';

        $stagingBefore = provisionTreeSnapshot($fs.'/home/www/rateguru/staging')
            + provisionTreeSnapshot($fs.'/home/deploy-rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-available/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-enabled/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf')
            + provisionTreeSnapshot($fs.'/etc/supervisor/conf.d/rateguru-staging-queue.conf')
            + provisionTreeSnapshot($fs.'/etc/cron.d/rateguru-staging-scheduler');

        $ownersBefore = array_filter(
            provisionOwnerTableRows($scratch),
            fn (string $path): bool => str_contains($path, 'staging'),
            ARRAY_FILTER_USE_KEY,
        );

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        $stagingAfter = provisionTreeSnapshot($fs.'/home/www/rateguru/staging')
            + provisionTreeSnapshot($fs.'/home/deploy-rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-available/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-enabled/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf')
            + provisionTreeSnapshot($fs.'/etc/supervisor/conf.d/rateguru-staging-queue.conf')
            + provisionTreeSnapshot($fs.'/etc/cron.d/rateguru-staging-scheduler');

        expect($stagingAfter)->toBe($stagingBefore, 'provisioning a new target must not touch the live one');

        $ownersAfter = array_filter(
            provisionOwnerTableRows($scratch),
            fn (string $path): bool => str_contains($path, 'staging'),
            ARRAY_FILTER_USE_KEY,
        );

        expect($ownersAfter)->toBe($ownersBefore, 'no staging path may be re-owned');

        // Its identities keep every membership they had, and gain none.
        $group = (string) file_get_contents($fs.'/etc-group');
        expect($group)->toContain('rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data');
        expect($group)->toMatch('/^rateguru-staging:x:5001:$/m');

        // The staging queue was never touched, and its scheduler cron is
        // exactly the committed file it always was.
        expect(provisionLog($scratch, 'supervisorctl.log'))->not->toContain('rateguru-staging-queue');
        expect(file_get_contents($fs.'/etc/cron.d/rateguru-staging-scheduler'))
            ->toBe(File::get(base_path('infrastructure/config/cron/rateguru-staging-scheduler')));

        // A host-service reload is the only permitted side effect, and it is
        // not a mutation of the staging target: additive configuration has to
        // be picked up somehow.
        $systemctl = provisionLog($scratch, 'systemctl.log');
        foreach (explode("\n", trim($systemctl)) as $line) {
            if ($line === '' || str_contains($line, 'is-enabled') || str_contains($line, 'is-active')) {
                continue;
            }

            expect($line)->toMatch('/^systemctl reload (nginx|php8\.5-fpm)$/', "unexpected service mutation: {$line}");
        }
    } finally {
        provisionCleanup($scratch);
    }
});

it('produces configuration a later active-mode run accepts without rewriting a byte', function () {
    $scratch = provisionScratchDir();

    try {
        // Provision while planned...
        $env = provisionFixture($scratch, ['widenActiveAllowlist' => true]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        $installed = provisionSnapshotDemoState($scratch);

        // ...then activate the target in the registry, exactly as a later,
        // deliberate registry change would, and ask the ORDINARY target-scoped
        // installers — no --provisioning anywhere — whether it is correct.
        $repo = $scratch.'/repo';
        file_put_contents(
            $repo.'/infrastructure/config/deployment-targets.json',
            provisionRegistryJson([], 'active'),
        );

        [$layoutExit, $layoutOutput] = provisionRun(
            ['--verify', '--target', 'demo-shop'],
            $env,
            $repo.'/infrastructure/scripts/install-bootstrap-host-layout',
        );

        expect($layoutExit)->toBe(0, "an activated provisioned target must satisfy the ordinary layout contract:\n{$layoutOutput}");
        expect($layoutOutput)->toContain('lifecycle=active — provisioned by this slice');

        [$servicesExit, $servicesOutput] = provisionRun(
            ['--verify', '--target', 'demo-shop'],
            $env,
            $repo.'/infrastructure/scripts/install-bootstrap-services',
        );

        expect($servicesExit)->toBe(0, "an activated provisioned target must satisfy the ordinary services contract:\n{$servicesOutput}");
        expect($servicesOutput)
            ->toContain('TARGET SERVICES CONTRACT (demo-shop): SATISFIED')
            ->toContain('byte-identical to its source')
            // The renderer produced these files; the active-mode run renders
            // the same bytes and therefore reports no drifted item at all.
            ->toContain('DRIFT: 0')
            ->not->toContain('DRIFT    ');

        expect(provisionSnapshotDemoState($scratch))
            ->toBe($installed, 'activating the target must not require rewriting anything provisioning installed');
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// Lifecycle: the closed authorization, refused four different ways
// =============================================================================

it('refuses every target that is not a planned production one', function (
    array $options,
    string $target,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $options);

        [$exit, $output] = provisionRun(['--apply', '--target', $target], $env);

        expect($exit)->toBe(1, "provisioning must refuse {$target}:\n{$output}");
        expect($output)->toContain($expected);

        // Refused before anything at all was touched.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'an active target' => [
        [], 'staging-main',
        'target staging-main has lifecycle=active — it is already in the operational lifecycle',
    ],
    'a disabled target' => [
        ['demoLifecycle' => 'disabled'], 'demo-shop',
        'target demo-shop has lifecycle=disabled, not planned',
    ],
    'a planned staging target' => [
        ['demoOverrides' => ['environment_class' => 'staging']], 'demo-shop',
        'target demo-shop has environment_class=staging, not production',
    ],
    'an unknown target' => [
        [], 'no-such-target',
        'unknown target: no-such-target',
    ],
]);

it('refuses a registry that is invalid anywhere, not merely invalid for this target', function (
    array $options,
    string $expectedFragment,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $options);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expectedFragment);
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'malformed JSON' => [
        ['registry' => "{ not json at all\n"],
        'target registry is not valid JSON',
    ],
    'a collision with another target' => [
        // Two targets sharing a pool socket would have them fighting over the
        // same file the moment both ran.
        ['demoOverrides' => ['php_fpm' => ['pool' => 'rateguru-demo-shop', 'socket' => '/run/php/rateguru-tits-guru.sock']]],
        'target registry is invalid',
    ],
    'a service name that could never be safely rendered' => [
        ['demoOverrides' => ['supervisor' => ['program' => 'demo shop queue', 'queue' => 'rateguru-demo-shop']]],
        'target registry is invalid',
    ],
]);

// =============================================================================
// Host prerequisites and new-target safety: every refusal before any mutation
// =============================================================================

it('refuses a host that is not already a RateGuru host, and says whose job that is', function (
    array $options,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $options['fixture'] ?? []);

        foreach ($options['clearToggles'] ?? [] as $toggle) {
            @unlink($scratch.'/toggles/'.$toggle);
        }

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
        expect($output)->toContain('No mutation was performed');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'an unconverged runtime' => [
        ['clearToggles' => ['runtime-installer-compliant']],
        'this is a host prerequisite, not this target',
    ],
    'a missing host root' => [
        ['fixture' => ['omitHostRoots' => ['/home/www/rateguru/run']]],
        'host-level prerequisites are not satisfied',
    ],
]);

// =============================================================================
// A queue program that is safe to load before the first deployment
// =============================================================================
//
// Provisioning installs the queue program and deliberately does not add it to
// the running supervisor. That is not enough on its own: the file sits in
// supervisord's own configuration directory, and a supervisord restart or a
// host reboot loads it whether anybody asked or not. A planned production
// target can wait weeks for its first deployment, so the CONFIGURATION has to
// be the thing that is safe, not the sequence of commands that installed it.
//
// The tests below run the program's real command line and then apply
// supervisord's own documented decision rule to the result, so "no crash loop"
// is a computed outcome rather than a comment.

/**
 * The command supervisord would spawn, ready to run here.
 *
 * Two substitutions, both the same fixture translation every probe in this
 * file performs: the canonical RateGuru root becomes the fixture's, and the
 * template PHP binary — an absolute path no test host has — becomes a stub
 * that records how it was called. The guard's logic, its exit codes and its
 * argv are the shipped ones.
 */
function provisionQueueCommand(string $config, string $fs, string $phpStub): string
{
    $pattern = '/^command=\/bin\/bash -c \'(.*)\'$/m';

    expect($config)->toMatch($pattern);

    preg_match($pattern, $config, $matches);

    return str_replace(
        ['/home/www/rateguru', '/usr/bin/php8.5'],
        [$fs.'/home/www/rateguru', $phpStub],
        $matches[1],
    );
}

/**
 * supervisord's decision after a program exits, from its documented rules:
 *
 *   - an exit before startsecs never made it out of STARTING, so supervisord
 *     backs off and retries regardless of the exit code;
 *   - otherwise autorestart=unexpected restarts only codes outside exitcodes,
 *     autorestart=true restarts everything, autorestart=false restarts nothing.
 *
 * @param  list<int>  $exitcodes
 */
function supervisorOutcome(int $code, float $elapsed, float $startsecs, string $autorestart, array $exitcodes): string
{
    if ($elapsed < $startsecs) {
        return 'BACKOFF';
    }

    return match ($autorestart) {
        'unexpected' => in_array($code, $exitcodes, true) ? 'EXITED' : 'RESTART',
        'true' => 'RESTART',
        default => 'EXITED',
    };
}

it('installs a queue program that a supervisord restart can load before the first deployment', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';

        [$exit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0);

        $config = (string) file_get_contents($fs.'/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf');

        // The policy supervisord reads.
        expect($config)
            ->toContain("autostart=true\n")
            ->toContain("autorestart=unexpected\n")
            ->toContain("exitcodes=99\n")
            ->toContain("startsecs=3\n")
            // directory= must be a path that exists on a target with no
            // release: supervisord chdirs there before spawning, and a missing
            // one is a spawn error no guard in the command could catch.
            ->toContain("directory=/home/www/rateguru/production/demo-shop\n");

        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop'))->toBeTrue();
        expect(file_exists($fs.'/home/www/rateguru/production/demo-shop/current'))->toBeFalse();

        // Now actually run what supervisord would spawn, with no current.
        $phpStub = $scratch.'/bin/php-queue-probe';
        provisionWriteStub($phpStub, <<<'STUB'
            #!/bin/bash
            printf 'php %s\\n' "$*" >> "${STUB_LOG}/queue-worker.log"
            exit 0
            STUB);

        $command = provisionQueueCommand($config, $fs, $phpStub);

        $started = microtime(true);
        exec('STUB_LOG='.escapeshellarg($scratch.'/log').' bash -c '.escapeshellarg($command).' 2>&1', $output, $code);
        $elapsed = microtime(true) - $started;

        // Laravel was never invoked, and the program said "nothing to run"
        // rather than failing.
        expect(provisionLog($scratch, 'queue-worker.log'))->toBe('');
        expect($code)->toBe(99, 'the guard must exit with the code declared expected: '.implode('
', $output));

        // It outlived startsecs, so supervisord saw a successful start.
        expect($elapsed)->toBeGreaterThan(3.0);

        // Therefore: EXITED. Not BACKOFF, and not a restart.
        expect(supervisorOutcome($code, $elapsed, 3.0, 'unexpected', [99]))->toBe('EXITED');
    } finally {
        provisionCleanup($scratch);
    }
});

it('runs the real worker as soon as a release exists, and restarts it on its ordinary turnover', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';
        $root = $fs.'/home/www/rateguru/production/demo-shop';

        [$exit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0);

        $config = (string) file_get_contents($fs.'/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf');

        // The first deployment: a release, and current pointing at it. The
        // configuration is NOT rewritten — the same file now means something
        // different because the host does.
        @mkdir($root.'/releases/20260101120000', 0o755, true);
        symlink($root.'/releases/20260101120000', $root.'/current');

        $phpStub = $scratch.'/bin/php-queue-probe';
        provisionWriteStub($phpStub, <<<'STUB'
            #!/bin/bash
            printf 'php %s\\n' "$*" >> "${STUB_LOG}/queue-worker.log"
            printf 'cwd %s\\n' "$(pwd -P)" >> "${STUB_LOG}/queue-worker.log"
            exit 0
            STUB);

        $command = provisionQueueCommand($config, $fs, $phpStub);

        exec('STUB_LOG='.escapeshellarg($scratch.'/log').' bash -c '.escapeshellarg($command).' 2>&1', $output, $code);

        $worker = provisionLog($scratch, 'queue-worker.log');

        expect($worker)
            ->toContain('artisan queue:work redis --queue=rateguru-demo-shop')
            ->toContain('--max-time=3600')
            ->toContain('cwd '.realpath($root.'/releases/20260101120000'));

        // The worker's own successful exit — what --max-time and --max-jobs
        // produce every hour — must bring it back, or a deployed target
        // silently stops processing its queue after the first turnover.
        expect($code)->toBe(0);
        expect(supervisorOutcome(0, 3600.0, 3.0, 'unexpected', [99]))->toBe('RESTART');

        // And a genuinely crash-looping worker still reaches BACKOFF, so the
        // startsecs/startretries backstop was not traded away for the guard.
        expect(supervisorOutcome(1, 0.2, 3.0, 'unexpected', [99]))->toBe('BACKOFF');

        // A host restart starts it again by itself.
        expect($config)->toContain("autostart=true\n");
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// One authority: this bundle, and a host that agrees with it
// =============================================================================
//
// provision-target reads the lifecycle, the application_root and the target's
// identities out of THIS bundle's registry, and the installers it delegates to
// read the same file. The host's own installed bundle is independently
// versioned and legitimately older — a host prepared before this tooling
// existed has an installed `common` with no lifecycle gate in it at all — so
// it is treated as a prerequisite to prove, never as a second opinion to
// consult. The tests below are about that boundary in both directions.

it('proves the host operational bundle before it inspects any target state', function () {
    // The ordering IS the contract: a target's lifecycle, root and identities
    // read against a stale host are not facts worth collecting, so nothing
    // about the target is looked at until the host is known to agree.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        @unlink($scratch.'/toggles/operations-installer-compliant');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('install-target-operations --verify does not pass')
            ->toContain('the installed RateGuru operational bundle is stale')
            ->toContain('refresh it through Prepare Host')
            ->toContain('No target state was inspected. No mutation was performed');

        // Target-scoped, and it says so: provisioning refuses rather than
        // refreshing host-global tooling on its own initiative.
        expect($output)->toContain('never updates host-global tooling itself');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');

        // The children were never asked anything about this target either.
        expect(provisionLog($scratch, 'children.log'))
            ->not->toContain('--target demo-shop');
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses a host whose runtime registry is not this bundle\'s', function (string $shape) {
    // A host that disagrees about this target's lifecycle or root is a host
    // whose operational bundle is behind. Provisioning does not pick a winner
    // between two registry revisions — there is no correct winner, only a
    // target created from one description and operated by another.
    $scratch = provisionScratchDir();

    try {
        $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);
        $demo = provisionDemoTarget();

        switch ($shape) {
            case 'root':
                $demo['application_root'] = '/home/www/rateguru/production/demo-shop-elsewhere';
                $registry['targets']['demo-shop'] = $demo;
                break;
            case 'lifecycle':
                $demo['lifecycle'] = 'active';
                $registry['targets']['demo-shop'] = $demo;
                break;
            case 'absent':
                // The host predates the target entirely — the ordinary state
                // of a host prepared before this brand was declared.
                break;
        }

        $env = provisionFixture($scratch, [
            'installedRegistryJson' => json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        ]);

        $fs = $scratch.'/fs';

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain("the host's runtime registry differs from this bundle's")
            ->toContain("demo-shop's lifecycle and application_root disagree between them")
            ->toContain('the installed RateGuru operational bundle is stale')
            ->toContain('No target state was inspected. No mutation was performed');

        // NEITHER root was created — not the one this bundle names, and not
        // the one the host's registry names.
        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop'))
            ->toBeFalse("the trusted registry's root must not be created");
        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop-elsewhere'))
            ->toBeFalse("the installed registry's root must not be created");

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'a different application_root' => ['root'],
    'a different lifecycle' => ['lifecycle'],
    'a target the host has never heard of' => ['absent'],
]);

it('refuses a host with no runtime registry at all', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, [
            'installedRegistry' => $scratch.'/fs/home/www/rateguru/etc/does-not-exist.json',
        ]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('the host has no readable runtime target registry')
            ->toContain('the installed RateGuru operational bundle is stale')
            ->toContain('No target state was inspected. No mutation was performed');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('reads the lifecycle and the root from this bundle, never from the host', function () {
    // The positive half, and the one that makes the refusals meaningful: with
    // an exactly current host, provisioning proceeds — and every path it
    // creates is the one THIS bundle's registry names.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TARGET INFRASTRUCTURE: PROVISIONED');

        expect(is_dir($scratch.'/fs/home/www/rateguru/production/demo-shop/releases'))->toBeTrue();

        // The report says which host it agreed with, in the modes that print
        // one: --apply is a transcript of what it did, --verify is the report.
        [$verifyExit, $verifyOutput] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($verifyExit)->toBe(0, $verifyOutput);
        expect($verifyOutput)
            ->toContain('PASS     host:install-target-operations')
            ->toContain('PASS     host:runtime-registry');

        // install-target-operations was asked to verify, and never to apply:
        // updating the host's operational bundle is a host operation.
        $children = provisionLog($scratch, 'children.log');

        expect($children)->toContain('operations-installer --verify');
        expect($children)->not->toContain('operations-installer --apply');
    } finally {
        provisionCleanup($scratch);
    }
});

it('says a bundle missing the lifecycle gate is a broken bundle, never a missing command', function () {
    // The other direction: this file sources the `common` beside itself, so a
    // library that cannot answer the lifecycle question means the BUNDLE is
    // incomplete. It says that, rather than reaching the gate as a bare
    // "command not found" partway through a run.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $common = $scratch.'/repo/infrastructure/scripts/common';

        file_put_contents($common, preg_replace(
            '/^require_provisionable_target\(\) \{.*?\n\}\n/ms',
            '',
            File::get($common),
        ));

        expect(File::get($common))->not->toContain('require_provisionable_target() {');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('this infrastructure bundle is inconsistent')
            ->toContain('has no require_provisionable_target')
            ->toContain('nothing was changed')
            ->not->toContain('command not found');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses a target that already carries deployment-owned state', function (
    string $shape,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $root = $scratch.'/fs/home/www/rateguru/production/demo-shop';

        @mkdir($root.'/releases/20240101120000', 0o755, true);
        @mkdir($root.'/shared', 0o755, true);

        switch ($shape) {
            case 'current':
                symlink($root.'/releases/20240101120000', $root.'/current');
                break;
            case 'previous':
                symlink($root.'/releases/20240101120000', $root.'/previous');
                break;
            case 'release':
                // The release directory alone, with no pointer to it.
                break;
            case 'env':
                rmdir($root.'/releases/20240101120000');
                file_put_contents($root.'/shared/.env', "APP_KEY=base64:SOMEBODY-ELSE\n");
                break;
        }

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
        expect($output)->toContain('never deletes, moves or re-owns anything to make a target look new');

        // Nothing was created, and above all nothing was removed. Each shape
        // is asked about the artifact it actually planted: the env case has no
        // release directory, and letting it fall through to the release
        // assertion would have proved nothing about the file it is named for.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');

        if ($shape === 'env') {
            expect(File::get($root.'/shared/.env'))
                ->toBe("APP_KEY=base64:SOMEBODY-ELSE\n", 'the foreign environment file must be untouched');
        } else {
            expect(is_dir($root.'/releases/20240101120000'))->toBeTrue();
        }

        // --check reports the same conflict rather than pretending it is drift.
        [$checkExit, $checkOutput] = provisionRun(['--check', '--target', 'demo-shop'], $env);
        expect($checkExit)->toBe(1);
        expect($checkOutput)
            ->toContain('CONFLICT state:demo-shop')
            ->toContain('TARGET INFRASTRUCTURE: BLOCKED');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'current' => ['current', 'current already exists'],
    'previous' => ['previous', 'previous already exists'],
    'an existing release' => ['release', 'releases already contains'],
    'an environment file' => ['env', 'shared/.env already exists'],
]);

it('refuses an existing account whose metadata is incompatible, rather than rewriting it', function () {
    $scratch = provisionScratchDir();

    try {
        // A runtime account that already exists with a login shell: rewriting
        // it could break unrelated automation, so it is a decision for an
        // operator, not for an installer.
        $env = provisionFixture($scratch, [
            'passwdExtra' => ['rateguru-demo-shop:x:6001:6001::/home/www/rateguru/production/demo-shop:/bin/bash'],
            'groupExtra' => ['rateguru-demo-shop:x:6001:'],
        ]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            // The refusal names the specific account and the specific drift,
            // not merely "something is unresolvable".
            ->toContain('CONFLICT user:rateguru-demo-shop')
            ->toContain('shell is /bin/bash, required /usr/sbin/nologin')
            ->toContain('incompatible existing account');
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses a path conflict instead of deleting whatever is in the way', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $root = $scratch.'/fs/home/www/rateguru/production/demo-shop';

        @mkdir($root, 0o755, true);
        // A regular file where a managed directory belongs. Resolving it would
        // mean deleting something, which this never does.
        file_put_contents($root.'/locks', "NOT-A-DIRECTORY-SENTINEL\n");

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('CONFLICT path:/home/www/rateguru/production/demo-shop/locks')
            ->toContain('is a regular file, expected directory')
            ->toContain('never deletes, replaces or follows a conflicting path');
        expect(file_get_contents($root.'/locks'))->toBe("NOT-A-DIRECTORY-SENTINEL\n");
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('never activates a service on configuration its own parser rejected', function (
    string $toggle,
    string $installedPath,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        touch($scratch.'/toggles/'.$toggle);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('install-bootstrap-services --apply --target demo-shop --provisioning failed');

        // The candidate was rolled back by the services installer's own
        // transaction, so nothing invalid is left installed.
        expect(file_exists($scratch.'/fs'.$installedPath))
            ->toBeFalse("a rejected candidate must not survive: {$installedPath}");

        // The layout converged first and is deliberately left converged: a
        // rerun resumes rather than starting over.
        expect(is_dir($scratch.'/fs/home/www/rateguru/production/demo-shop/releases'))->toBeTrue();
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'a rejected PHP-FPM pool' => ['php-fpm-t-fail', '/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf'],
    'a rejected Nginx vhost' => ['nginx-t-fail', '/etc/nginx/sites-available/rateguru-demo-shop'],
    'a rejected Supervisor program' => ['supervisor-reread-fail', '/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf'],
]);

it('aborts when a delegated child installer fails, and never reports success', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        // The public-storage ACL is owned by its own installer; a failure
        // there is a failure of the whole provisioning run.
        @unlink($scratch.'/toggles/public-storage-installer-compliant');
        touch($scratch.'/toggles/public-storage-installer-apply-fail');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('install-public-storage-access --apply --target demo-shop --provisioning failed')
            ->not->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            ->not->toContain('RATEGURU_PROVISION_RESULT=');
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// CLI surface
// =============================================================================

it('documents the operation, its boundary and its machine-readable result', function () {
    // No fixture host: --help is answered from the committed tree, and the
    // only environment it needs is what sourcing `common` requires.
    [$exit, $output] = provisionRun(['--help'], [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => sys_get_temp_dir(),
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_TARGET_REGISTRY_FILE' => base_path('infrastructure/config/deployment-targets.json'),
        'RATEGURU_TARGETS_CLI' => base_path('infrastructure/scripts/targets'),
    ]);

    expect($exit)->toBe(0, $output);
    expect($output)
        ->toContain('provision-target --check  --target TARGET_ID')
        ->toContain('provision-target --apply  --target TARGET_ID')
        ->toContain('provision-target --verify --target TARGET_ID')
        ->toContain('All modes require root')
        ->toContain('lifecycle=planned AND environment_class=production')
        ->toContain('RATEGURU_PROVISION_RESULT=')
        ->toContain('never activates the target');
});

it('refuses to run without root, and refuses a request with no target', function (
    array $arguments,
    array $envOverrides,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $envOverrides);

        [$exit, $output] = provisionRun($arguments, $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'no root, apply' => [['--apply', '--target', 'demo-shop'], ['euid' => '1000'], '--apply must run as root'],
    'no root, check' => [['--check', '--target', 'demo-shop'], ['euid' => '1000'], '--check must run as root'],
    'no root, verify' => [['--verify', '--target', 'demo-shop'], ['euid' => '1000'], '--verify must run as root'],
    'no target' => [['--apply'], [], '--target is required'],
    'no mode' => [['--target', 'demo-shop'], [], 'one of --check, --apply or --verify is required'],
    'two modes' => [['--check', '--apply', '--target', 'demo-shop'], [], 'only one of --check, --apply or --verify may be given'],
    'an unknown flag' => [['--apply', '--target', 'demo-shop', '--force'], [], 'unknown argument: --force'],
]);

// =============================================================================
// The deploy perimeter is untouched by provisioning
// =============================================================================

it('leaves a provisioned target undeployable through the existing wrappers', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        // The infrastructure now exists, and the deploy account with it. The
        // wrapper still refuses, because require_active_target is unchanged and
        // provisioning never wrote the registry — knowing the deploy user's
        // name buys nobody anything.
        $harness = $scratch.'/deploy-wrapper-harness.sh';
        file_put_contents($harness, implode("\n", [
            'set -Eeuo pipefail',
            'source '.escapeshellarg(base_path('infrastructure/config/wrappers/rateguru-deploy')),
            'parse_wrapper_args --target demo-shop',
            'authorize_caller',
            'require_active_target "${TARGET_ID}"',
            'printf "REACHED-DEPLOY\n"',
            '',
        ]));

        $stub = $scratch.'/bin/stub-deploy';
        provisionWriteStub($stub, "#!/bin/bash\nprintf 'DEPLOY-RAN\\n' >> \"\${STUB_LOG}/deploy.log\"\n");

        [$wrapperExit, $wrapperOutput] = provisionRun([], array_merge($env, [
            'SUDO_USER' => 'deploy-rateguru-demo-shop',
            'RATEGURU_DEPLOY_BIN' => $stub,
            // The wrapper's own seam for the library it sources. It is an
            // installed-bundle caller, unlike provision-target, which reads
            // the one beside itself.
            'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        ]), $harness);

        expect($wrapperExit)->not->toBe(0);
        expect($wrapperOutput)
            ->toContain('target demo-shop has lifecycle=planned, not active')
            ->not->toContain('REACHED-DEPLOY');
        expect(provisionLog($scratch, 'deploy.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// Source guards: an orchestrator, never a second owner
// =============================================================================

it('re-implements nothing an owning installer already does', function (string $construct) {
    expect(executableSourceLines(provisionSource()))
        ->not->toContain($construct, "provision-target must delegate rather than contain: {$construct}");
})->with([
    // identities and filesystem — install-bootstrap-host-layout
    'useradd', 'groupadd', 'usermod', 'userdel', 'groupdel', 'chown', 'chmod',
    // ACLs — install-public-storage-access
    'setfacl', 'getfacl',
    // service configuration text — install-bootstrap-services renders it
    'server_name', 'fastcgi_pass', 'listen 80', 'php_admin_value', '[program:',
    'autostart', 'supervisorctl', 'crontab', 'schedule:run',
    // databases — install-target-database
    'psql', 'createdb', 'dropdb', 'pg_dump', 'pg_restore',
    // TLS, mail, offsite, DNS — later slices, none of them this one
    'certbot', 'letsencrypt', 'postfix', 'postconf', 'postmap', 'rclone',
    'nsupdate', 'dkim',
    // the application — deploy owns it
    'artisan', 'migrate', 'composer', 'rateguru-deploy',
    // and nothing is ever removed to make room
    'rm -rf',
]);

it('names no brand anywhere, in the orchestrator or in the production renderer', function (string $file) {
    $executable = executableSourceLines(File::get(base_path($file)));

    foreach (['tits-guru', 'tits.guru', 'demo-shop', 'food-guru', 'animals-guru'] as $brand) {
        expect($executable)->not->toContain(
            $brand,
            "{$file} must be generic: a production target is described by the registry, never by a name compiled into a script ({$brand})",
        );
    }
})->with([
    'infrastructure/scripts/provision-target',
    'infrastructure/scripts/install-bootstrap-services',
    'infrastructure/scripts/install-bootstrap-host-layout',
]);

it('renders a production target from the registry rather than from the old shared-production config', function () {
    $services = executableSourceLines(File::get(base_path('infrastructure/scripts/install-bootstrap-services')));

    // The committed config/nginx/rateguru-production describes the OLD shared
    // production-root model — one /home/www/rateguru/production tree for every
    // brand — which the target registry replaced. It must never be reachable as
    // a target's service source. It still exists, because
    // install-target-operations installs and verifies it as part of the
    // operational bundle the recovery prerequisite machinery reads.
    expect($services)->not->toContain('rateguru-production');

    expect(executableSourceLines(provisionSource()))->not->toContain('rateguru-production');

    // The file is still committed, and still installed by its actual owner.
    expect(File::exists(base_path('infrastructure/config/nginx/rateguru-production')))->toBeTrue();
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->toContain('SRC_NGINX_SOURCE_PRODUCTION="${REPO_ROOT}/infrastructure/config/nginx/rateguru-production"');

    // And it describes the model it always did, so nothing can mistake it for
    // a per-target source: no registry-derived target root appears in it.
    expect(File::get(base_path('infrastructure/config/nginx/rateguru-production')))
        ->toContain('/home/www/rateguru/production/current/public')
        ->not->toContain('/home/www/rateguru/production/tits-guru');
});
