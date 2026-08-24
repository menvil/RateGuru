<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 5 slice 5.2: infrastructure/scripts/install-bootstrap-runtime — the
 * reproducible base/runtime package installer for a clean Ubuntu 22.04 host.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against a fully simulated host: fixture os-release and
 * apt sources/keyrings directories, a constrained tool PATH, and stub
 * apt-get/dpkg-query/curl/gpg/php/psql binaries, all injected through
 * RATEGURU_BOOTSTRAP_* overrides the script only honors alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here runs apt or touches the
 * CI runner: every stub is pure bash builtins (the constrained PATH the
 * script hands to subprocesses contains no coreutils).
 *
 * The two profiles that matter most mirror the two real situations the
 * installer must serve: a clean Ubuntu 22.04 VPS (everything missing —
 * --apply builds it) and the current staging host (runtime already present,
 * repositories configured by the operator, unrelated NodeSource/ClickHouse/
 * Datadog sources on the side — recognized, satisfied, and never touched).
 */

// =============================================================================
// Harness
// =============================================================================

function bootstrapRuntimeScript(): string
{
    return base_path('infrastructure/scripts/install-bootstrap-runtime');
}

function bootstrapRuntimeSource(): string
{
    return File::get(bootstrapRuntimeScript());
}

function bootstrapRuntimeScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-runtime-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/tools', '/log', '/apt/sources.list.d', '/keyrings'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function bootstrapRuntimeCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bootstrapRuntimeRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bootstrapRuntimeScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start install-bootstrap-runtime subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bootstrapRuntimeWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * The repository/package contract, mirrored from the script so fixtures can
 * satisfy every probe. Order matters: apt-install assertions compare against
 * the script's own array order.
 *
 * @return list<string>
 */
function bootstrapRuntimeRequiredPackages(): array
{
    $base = [
        'acl', 'bash', 'ca-certificates', 'certbot', 'coreutils', 'curl',
        'diffutils', 'findutils', 'gnupg', 'grep', 'gzip', 'hostname',
        'iproute2', 'jq', 'libc-bin', 'mawk', 'nginx', 'openssh-server',
        'passwd', 'rclone', 'redis-server', 'rsync', 'sed', 'sudo',
        'supervisor', 'tar', 'util-linux',
    ];

    $php = array_map(
        fn (string $component): string => "php8.5-{$component}",
        ['cli', 'common', 'fpm', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'pgsql', 'redis', 'xml', 'zip'],
    );

    return array_merge($base, $php, ['postgresql-18', 'postgresql-client-18']);
}

/**
 * @return list<string>
 */
function bootstrapRuntimeRequiredPhpModules(): array
{
    return ['bcmath', 'curl', 'exif', 'gd', 'intl', 'mbstring', 'pcntl', 'pdo_pgsql', 'pgsql', 'redis', 'xml', 'zip'];
}

/**
 * Plain exit-0 tools the fixture PATH carries on a compliant host. The
 * version-bearing binaries (php8.5, php-fpm8.5, psql, pg_dump, pg_restore)
 * are separate behavioral stubs added by the fixture builder.
 *
 * @return list<string>
 */
function bootstrapRuntimeAllTools(): array
{
    return [
        'apt-get', 'dpkg',
        'setfacl', 'getfacl', 'certbot', 'curl', 'cmp', 'diff', 'find',
        'gpg', 'grep', 'gzip', 'hostname', 'ss', 'ip', 'jq', 'getent',
        'awk', 'nginx', 'sshd', 'useradd', 'rclone', 'redis-server',
        'rsync', 'sed', 'sudo', 'visudo', 'supervisord', 'tar', 'flock',
        'namei', 'runuser', 'createdb', 'dropdb',
    ];
}

function bootstrapRuntimePhpPpaFingerprint(): string
{
    return '14AA40EC0831756756D7F66C4F4EA0AAE5267A6C';
}

function bootstrapRuntimePgdgFingerprint(): string
{
    return 'B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8';
}

/**
 * The exact deb822 content the installer owns for one repository — must stay
 * byte-identical to sources_file_content() in the script.
 */
function bootstrapRuntimeExpectedSources(string $label, string $uri, string $suite, string $keyring): string
{
    return implode("\n", [
        "# RateGuru {$label} repository — managed by install-bootstrap-runtime",
        '# (Phase 5 slice 5.2). Do not edit: re-run --apply to reconcile.',
        'Types: deb',
        "URIs: {$uri}",
        "Suites: {$suite}",
        'Components: main',
        'Architectures: amd64',
        "Signed-By: {$keyring}",
    ])."\n";
}

/**
 * Host identity plus the apt sources/keyrings landscape.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteHostFiles(string $scratch, array $options): void
{
    $os = $options['os'] ?? 'ubuntu-22.04';
    $osRelease = match ($os) {
        'ubuntu-22.04' => "ID=ubuntu\nVERSION_ID=\"22.04\"\nPRETTY_NAME=\"Ubuntu 22.04.4 LTS\"\n",
        'ubuntu-24.04' => "ID=ubuntu\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n",
        'debian' => "ID=debian\nVERSION_ID=\"12\"\nVERSION=\"12 (sentinel-bookworm)\"\n",
        'absent' => null,
    };

    if ($osRelease !== null) {
        file_put_contents($scratch.'/fs/os-release', $osRelease);
    }

    // Unrelated host-wide repositories the real staging host carries — never
    // RateGuru dependencies, never managed, never removed. One deb822 file
    // proves the .sources scan ignores foreign stanzas too.
    file_put_contents(
        $scratch.'/apt/sources.list.d/nodesource.list',
        "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main\n",
    );
    file_put_contents(
        $scratch.'/apt/sources.list.d/clickhouse.list',
        "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg] https://packages.clickhouse.com/deb stable main\n",
    );
    file_put_contents(
        $scratch.'/apt/sources.list.d/datadog-vector.sources',
        "Types: deb\nURIs: https://apt.vector.dev\nSuites: stable\nComponents: vector\nSigned-By: /usr/share/keyrings/datadog-archive-keyring.gpg\n",
    );

    file_put_contents(
        $scratch.'/apt/sources.list',
        "deb http://archive.ubuntu.com/ubuntu jammy main restricted universe multiverse\n"
        ."deb http://archive.ubuntu.com/ubuntu jammy-updates main restricted universe multiverse\n"
        ."deb http://security.ubuntu.com/ubuntu jammy-security main restricted universe multiverse\n",
    );

    // PHP PPA: the current staging host configured it via add-apt-repository
    // (a classic .list under the operator's own file name).
    if (($options['phpRepo'] ?? 'preexisting') === 'preexisting') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/ondrej-ubuntu-php-jammy.list',
            "deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main\n",
        );
    } elseif (($options['phpRepo'] ?? null) === 'installer-owned') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/rateguru-php.sources',
            bootstrapRuntimeExpectedSources(
                'php',
                'https://ppa.launchpadcontent.net/ondrej/php/ubuntu',
                'jammy',
                $scratch.'/keyrings/rateguru-php.gpg',
            ),
        );
        file_put_contents($scratch.'/keyrings/rateguru-php.gpg', "EXISTING-PHP-KEYRING\n");
    }

    if (($options['pgdgRepo'] ?? 'preexisting') === 'preexisting') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/pgdg.list',
            "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg] https://apt.postgresql.org/pub/repos/apt jammy-pgdg main\n",
        );
    } elseif (($options['pgdgRepo'] ?? null) === 'installer-owned') {
        file_put_contents(
            $scratch.'/apt/sources.list.d/rateguru-pgdg.sources',
            bootstrapRuntimeExpectedSources(
                'pgdg',
                'https://apt.postgresql.org/pub/repos/apt',
                'jammy-pgdg',
                $scratch.'/keyrings/rateguru-pgdg.gpg',
            ),
        );
        file_put_contents($scratch.'/keyrings/rateguru-pgdg.gpg', "EXISTING-PGDG-KEYRING\n");
    }

    // A decoy RateGuru runtime tree: slice 5.2 must never touch application
    // paths, so its continued byte-identity is asserted after --apply.
    expect(@mkdir($scratch.'/fs/home-www-rateguru', 0o755, true))->toBeTrue();
    file_put_contents($scratch.'/fs/home-www-rateguru/decoy.txt', "application files — never touched by slice 5.2\n");

    // dpkg database fixture: one package name per line.
    $packages = $options['packages'] ?? 'all';
    $installed = match (true) {
        $packages === 'all' => bootstrapRuntimeRequiredPackages(),
        $packages === 'none' => [],
        default => $packages,
    };
    file_put_contents($scratch.'/fs/dpkg-installed.txt', implode("\n", $installed).($installed === [] ? '' : "\n"));
}

/**
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteToolStubs(string $scratch, array $options): void
{
    $tools = $options['tools'] ?? 'all';

    if ($tools === 'all') {
        $tools = bootstrapRuntimeAllTools();
    } elseif ($tools === 'minimal') {
        $tools = ['apt-get', 'dpkg'];
    }

    foreach ($tools as $tool) {
        bootstrapRuntimeWriteStub($scratch.'/tools/'.$tool, "#!/bin/bash\nexit 0\n");
    }

    // Version-bearing runtime binaries. Pure builtins: the constrained PATH
    // has no cat/sed, so stubs may only use printf/read/[[ ]].
    $phpStub = <<<'STUB'
        #!/bin/bash
        if [[ "$1" == "-v" ]]; then
            printf 'PHP %s (%s) (built: Jan  1 2026 00:00:00) (NTS)\n' "${STUB_PHP_VERSION}" "${STUB_PHP_SAPI}"
            exit 0
        fi
        if [[ "$1" == "-m" ]]; then
            printf '[PHP Modules]\n'
            for module in ${STUB_PHP_MODULES}; do
                printf '%s\n' "${module}"
            done
            exit 0
        fi
        exit 1
        STUB;

    bootstrapRuntimeWriteStub($scratch.'/tools/php8.5', str_replace('${STUB_PHP_SAPI}', 'cli', $phpStub));
    bootstrapRuntimeWriteStub($scratch.'/tools/php-fpm8.5', str_replace('${STUB_PHP_SAPI}', 'fpm-fcgi', $phpStub));

    $pgStub = <<<'STUB'
        #!/bin/bash
        if [[ "$1" == "--version" ]]; then
            printf '%s (PostgreSQL) %s (Ubuntu %s-1.pgdg22.04+1)\n' "${0##*/}" "${STUB_PG_VERSION}" "${STUB_PG_VERSION}"
            exit 0
        fi
        exit 1
        STUB;

    foreach (['psql', 'pg_dump', 'pg_restore'] as $tool) {
        bootstrapRuntimeWriteStub($scratch.'/tools/'.$tool, $pgStub);
    }
}

function bootstrapRuntimeWriteMutationStubs(string $scratch): void
{
    // dpkg-query: reads the fixture package database; builtins only.
    bootstrapRuntimeWriteStub($scratch.'/bin/dpkg-query', <<<'STUB'
        #!/bin/bash
        pkg="${!#}"
        while IFS= read -r line; do
            if [[ "${line}" == "${pkg}" ]]; then
                printf 'installed'
                exit 0
            fi
        done < "${STUB_DPKG_STATE}"
        exit 1
        STUB);

    // apt-get: logs every invocation; a successful install marks its
    // packages installed in the fixture dpkg database (so a second --apply
    // sees the converged state, exactly like a real host).
    bootstrapRuntimeWriteStub($scratch.'/bin/apt-get', <<<'STUB'
        #!/bin/bash
        printf 'apt-get %s\n' "$*" >> "${STUB_LOG}/apt.log"
        if [[ "$1" == "update" && "${STUB_APT_UPDATE_FAIL:-}" == "true" ]]; then
            exit 100
        fi
        if [[ "$1" == "install" ]]; then
            if [[ "${STUB_APT_INSTALL_FAIL:-}" == "true" ]]; then
                exit 100
            fi
            for arg in "$@"; do
                case "${arg}" in
                    install|--|-*) ;;
                    *) printf '%s\n' "${arg}" >> "${STUB_DPKG_STATE}" ;;
                esac
            done
        fi
        exit 0
        STUB);

    // curl: records the request and materializes fake key material carrying
    // the requested URL, so the gpg stub can answer per-repository.
    bootstrapRuntimeWriteStub($scratch.'/bin/curl', <<<'STUB'
        #!/bin/bash
        printf 'curl %s\n' "$*" >> "${STUB_LOG}/curl.log"
        url="${!#}"
        if [[ -n "${STUB_CURL_FAIL_PATTERN:-}" && "${url}" == *"${STUB_CURL_FAIL_PATTERN}"* ]]; then
            exit 22
        fi
        out=""
        prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "--output" ]]; then
                out="${arg}"
            fi
            prev="${arg}"
        done
        printf 'KEY-FROM:%s' "${url}" > "${out}"
        exit 0
        STUB);

    // gpg: dearmor copies the staged key with a marker; --show-keys answers
    // with the per-repository fingerprint the test configured.
    bootstrapRuntimeWriteStub($scratch.'/bin/gpg', <<<'STUB'
        #!/bin/bash
        printf 'gpg %s\n' "$*" >> "${STUB_LOG}/gpg.log"
        if [[ " $* " == *" --dearmor "* ]]; then
            out=""
            prev=""
            for arg in "$@"; do
                if [[ "${prev}" == "--output" ]]; then
                    out="${arg}"
                fi
                prev="${arg}"
            done
            src="${!#}"
            printf 'DEARMORED:%s\n' "$(<"${src}")" > "${out}"
            exit 0
        fi
        if [[ " $* " == *" --show-keys "* ]]; then
            keyfile="${!#}"
            content="$(<"${keyfile}")"
            fpr=""
            [[ "${content}" == *keyserver.ubuntu.com* ]] && fpr="${STUB_FPR_PHP}"
            [[ "${content}" == *postgresql.org* ]] && fpr="${STUB_FPR_PGDG}"
            printf 'pub:-:4096:1:AAAAAAAAAAAAAAAA:1:::-:::scESC::::::23::0:\n'
            printf 'fpr:::::::::%s:\n' "${fpr}"
            if [[ "${STUB_GPG_EXTRA_KEY:-}" == "true" ]]; then
                printf 'pub:-:4096:1:BBBBBBBBBBBBBBBB:1:::-:::scESC::::::23::0:\n'
                printf 'fpr:::::::::0000000000000000000000000000000000000000:\n'
            fi
            exit 0
        fi
        exit 1
        STUB);
}

/**
 * Build a fully simulated host and return the environment to run the script
 * against it. The default is the compliant staging-like profile: runtime
 * installed, repositories pre-existing, unrelated repos on the side. Every
 * option knocks one aspect back toward a clean or broken host.
 *
 * Options:
 *   os:              'ubuntu-22.04' | 'ubuntu-24.04' | 'debian' | 'absent'
 *   arch:            machine string (default x86_64)
 *   euid:            string (default '0')
 *   packages:        'all' | 'none' | list<string>
 *   phpRepo:         'preexisting' | 'installer-owned' | 'absent'
 *   pgdgRepo:        'preexisting' | 'installer-owned' | 'absent'
 *   tools:           'all' | 'minimal' | list<string>
 *   phpVersion:      reported by the php stubs (default 8.5.8)
 *   phpModules:      space-separated module list for `php8.5 -m`
 *   pgVersion:       reported by the pg client stubs (default 18.4)
 *   fprPhp/fprPgdg:  fingerprints the gpg stub reports per repository
 *   curlFailPattern: URL substring that makes the curl stub fail
 *   aptUpdateFail/aptInstallFail: bool
 *   gpgExtraKey:     bool — key material bundles a second key
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bootstrapRuntimeFixture(string $scratch, array $options = []): array
{
    bootstrapRuntimeWriteHostFiles($scratch, $options);
    bootstrapRuntimeWriteToolStubs($scratch, $options);
    bootstrapRuntimeWriteMutationStubs($scratch);

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_BOOTSTRAP_OS_RELEASE_FILE' => $scratch.'/fs/os-release',
        'RATEGURU_BOOTSTRAP_ARCH' => $options['arch'] ?? 'x86_64',
        'RATEGURU_BOOTSTRAP_EUID' => $options['euid'] ?? '0',
        'RATEGURU_BOOTSTRAP_APT_SOURCES_MAIN' => $scratch.'/apt/sources.list',
        'RATEGURU_BOOTSTRAP_APT_SOURCES_DIR' => $scratch.'/apt/sources.list.d',
        'RATEGURU_BOOTSTRAP_APT_KEYRINGS_DIR' => $scratch.'/keyrings',
        'RATEGURU_BOOTSTRAP_TOOL_PATH' => $scratch.'/tools',
        'RATEGURU_BOOTSTRAP_APT_GET_BIN' => $scratch.'/bin/apt-get',
        'RATEGURU_BOOTSTRAP_DPKG_QUERY_BIN' => $scratch.'/bin/dpkg-query',
        'RATEGURU_BOOTSTRAP_GPG_BIN' => $scratch.'/bin/gpg',
        'RATEGURU_BOOTSTRAP_CURL_BIN' => $scratch.'/bin/curl',
        'RATEGURU_BOOTSTRAP_PHP_CLI_BIN' => $scratch.'/tools/php8.5',
        'RATEGURU_BOOTSTRAP_PHP_FPM_BIN' => $scratch.'/tools/php-fpm8.5',
        'RATEGURU_BOOTSTRAP_PSQL_BIN' => $scratch.'/tools/psql',
        'RATEGURU_BOOTSTRAP_PG_DUMP_BIN' => $scratch.'/tools/pg_dump',
        'RATEGURU_BOOTSTRAP_PG_RESTORE_BIN' => $scratch.'/tools/pg_restore',
        'STUB_LOG' => $scratch.'/log',
        'STUB_DPKG_STATE' => $scratch.'/fs/dpkg-installed.txt',
        'STUB_PHP_VERSION' => $options['phpVersion'] ?? '8.5.8',
        'STUB_PHP_MODULES' => $options['phpModules'] ?? implode(' ', bootstrapRuntimeRequiredPhpModules()),
        'STUB_PG_VERSION' => $options['pgVersion'] ?? '18.4',
        'STUB_FPR_PHP' => $options['fprPhp'] ?? bootstrapRuntimePhpPpaFingerprint(),
        'STUB_FPR_PGDG' => $options['fprPgdg'] ?? bootstrapRuntimePgdgFingerprint(),
    ];

    if (isset($options['curlFailPattern'])) {
        $env['STUB_CURL_FAIL_PATTERN'] = $options['curlFailPattern'];
    }

    if ($options['aptUpdateFail'] ?? false) {
        $env['STUB_APT_UPDATE_FAIL'] = 'true';
    }

    if ($options['aptInstallFail'] ?? false) {
        $env['STUB_APT_INSTALL_FAIL'] = 'true';
    }

    if ($options['gpgExtraKey'] ?? false) {
        $env['STUB_GPG_EXTRA_KEY'] = 'true';
    }

    return $env;
}

/**
 * The clean-VPS profile: fresh Ubuntu 22.04, no RateGuru repositories, no
 * packages, only the package manager itself (fixture keeps the full tool
 * PATH so the closing --verify of a successful --apply models the
 * post-install host).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, string>
 */
function bootstrapRuntimeCleanHostFixture(string $scratch, array $extra = []): array
{
    return bootstrapRuntimeFixture($scratch, array_merge([
        'packages' => 'none',
        'phpRepo' => 'absent',
        'pgdgRepo' => 'absent',
    ], $extra));
}

/**
 * Recursive path => content snapshot for mutation-free assertions.
 *
 * @return array<string, string>
 */
function bootstrapRuntimeTreeSnapshot(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $snapshot[substr($file->getPathname(), strlen($dir))] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($snapshot);

    return $snapshot;
}

function bootstrapRuntimeAptLog(string $scratch): string
{
    $path = $scratch.'/log/apt.log';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

// =============================================================================
// CLI contract
// =============================================================================

it('prints usage on --help and rejects unknown or duplicated modes', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);

        [$exit, $output] = bootstrapRuntimeRun(['--help'], $env);
        expect($exit)->toBe(0);
        expect($output)->toContain('--check')->toContain('--apply')->toContain('--verify');

        [$exit, $output] = bootstrapRuntimeRun([], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('one of --check, --apply or --verify is required');

        [$exit, $output] = bootstrapRuntimeRun(['--check', '--verify'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('mode given more than once');

        [$exit, $output] = bootstrapRuntimeRun(['--frobnicate'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('unknown argument: --frobnicate');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --check
// =============================================================================

it('recognizes the current staging host as satisfied: pre-existing repos, installed runtime, unrelated repos ignored', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, "the already-bootstrapped staging host must satisfy --check:\n{$output}");
        expect($output)->toContain('SLICE 5.2 CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     os-release — ID=ubuntu VERSION_ID=22.04');
        expect($output)->toContain('PASS     repo:php — provided by a pre-existing apt source');
        expect($output)->toContain('PASS     repo:pgdg — provided by a pre-existing apt source');
        expect($output)->toContain('PASS     php-cli — PHP 8.5.8');
        expect($output)->toContain('PASS     psql — PostgreSQL 18.4');
        expect($output)->toContain("MISSING: 0\n");
        expect($output)->toContain("CONFLICT: 0\n");

        // Unrelated repositories are never inspected, reported or required
        // absent — they simply do not appear.
        expect($output)->not->toContain('nodesource');
        expect($output)->not->toContain('clickhouse');
        expect($output)->not->toContain('datadog');
        expect($output)->not->toContain('vector');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports the full work list on a clean Ubuntu 22.04 host and exits non-zero', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['tools' => 'minimal']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1, "a clean host cannot satisfy the slice 5.2 contract:\n{$output}");
        expect($output)->toContain('SLICE 5.2 CONTRACT: NOT SATISFIED');
        expect($output)->toContain('MISSING  repo:php');
        expect($output)->toContain('MISSING  repo:pgdg');
        expect($output)->toContain('MISSING  package:php8.5-fpm — not installed');
        expect($output)->toContain('MISSING  package:postgresql-18 — not installed');
        expect($output)->toContain('MISSING  package:nginx — not installed');

        // --check annotates every unsatisfied item with the intended action.
        expect($output)->toContain('-> apply: install '.$scratch.'/apt/sources.list.d/rateguru-php.sources');
        expect($output)->toContain('-> apply: apt-get install postgresql-18');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports a missing package manager as MISSING and fails --check', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['tools' => ['dpkg']]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  apt-dpkg — apt-get/dpkg not available');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats a wrong OS family as CONFLICT', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['os' => 'debian']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT os-release — unsupported OS family ID=debian');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('rejects Ubuntu 24.04: only the exact 22.04 staging baseline is supported', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['os' => 'ubuntu-24.04']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT os-release — ID=ubuntu VERSION_ID=24.04 is not the supported baseline ubuntu 22.04');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats an unproven architecture as CONFLICT instead of silently claiming support', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['arch' => 'aarch64']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT architecture — aarch64 is not a supported architecture (supported: x86_64 amd64)');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports each RateGuru repository independently when only one is missing', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'absent']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  repo:php — no apt source provides ppa.launchpadcontent.net/ondrej/php/ubuntu jammy');
        expect($output)->toContain('PASS     repo:pgdg');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['pgdgRepo' => 'absent']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  repo:pgdg — no apt source provides apt.postgresql.org/pub/repos/apt jammy-pgdg');
        expect($output)->toContain('PASS     repo:php');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('recognizes installer-owned repository files and flags a sources file whose keyring vanished', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PASS     repo:php — configured by this installer');
        expect($output)->toContain('PASS     repo:pgdg — configured by this installer');

        unlink($scratch.'/keyrings/rateguru-php.gpg');

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT repo:php');
        expect($output)->toContain('keyring');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports missing packages individually against the dpkg database', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $installed = array_values(array_diff(
            bootstrapRuntimeRequiredPackages(),
            ['php8.5-fpm', 'postgresql-18', 'rclone'],
        ));
        $env = bootstrapRuntimeFixture($scratch, ['packages' => $installed]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  package:php8.5-fpm');
        expect($output)->toContain('MISSING  package:postgresql-18');
        expect($output)->toContain('MISSING  package:rclone');
        expect($output)->toContain('PASS     package:nginx — installed');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats a wrong PHP series as CONFLICT for both SAPIs', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => '8.4.13']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT php-cli — reports PHP 8.4.13, required series is 8.5');
        expect($output)->toContain('CONFLICT php-fpm — reports PHP 8.4.13, required series is 8.5');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats a wrong PostgreSQL major as CONFLICT for every client tool', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['pgVersion' => '16.6']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT psql — reports PostgreSQL 16.6, required major is 18');
        expect($output)->toContain('CONFLICT pg_dump — reports PostgreSQL 16.6, required major is 18');
        expect($output)->toContain('CONFLICT pg_restore — reports PostgreSQL 16.6, required major is 18');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('keeps --check strictly read-only: no apt, no curl, no gpg, no file mutation', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['tools' => 'minimal']);
        $before = bootstrapRuntimeTreeSnapshot($scratch);

        [, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before, "--check mutated the fixture:\n{$output}");
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('--check invoked apt-get');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('--check invoked curl');
        expect(is_file($scratch.'/log/gpg.log'))->toBeFalse('--check invoked gpg');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('warns about a non-root --check without failing a satisfied host', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['euid' => '1000']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('WARN     effective-uid — 1000');
        expect($output)->toContain('SLICE 5.2 CONTRACT: SATISFIED');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --apply
// =============================================================================

it('bootstraps a clean host: pinned repositories, one apt update, one install, closing verify', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "apply on a clean compliant host must converge and verify:\n{$output}");
        expect($output)->toContain('SLICE 5.2 CONTRACT: SATISFIED');

        // Both installer-owned repositories exist with the exact deb822
        // content: HTTPS URI, pinned dedicated keyring, amd64 only.
        expect((string) file_get_contents($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBe(
            bootstrapRuntimeExpectedSources(
                'php',
                'https://ppa.launchpadcontent.net/ondrej/php/ubuntu',
                'jammy',
                $scratch.'/keyrings/rateguru-php.gpg',
            ),
        );
        expect((string) file_get_contents($scratch.'/apt/sources.list.d/rateguru-pgdg.sources'))->toBe(
            bootstrapRuntimeExpectedSources(
                'pgdg',
                'https://apt.postgresql.org/pub/repos/apt',
                'jammy-pgdg',
                $scratch.'/keyrings/rateguru-pgdg.gpg',
            ),
        );

        // Keyrings hold the dearmored (validated) key material.
        expect((string) file_get_contents($scratch.'/keyrings/rateguru-php.gpg'))->toStartWith('DEARMORED:KEY-FROM:https://keyserver.ubuntu.com/');
        expect((string) file_get_contents($scratch.'/keyrings/rateguru-pgdg.gpg'))->toStartWith('DEARMORED:KEY-FROM:https://www.postgresql.org/');

        // Exactly one update, then exactly one deterministic noninteractive
        // install of every required package.
        $aptLog = bootstrapRuntimeAptLog($scratch);
        $expectedInstall = 'apt-get install -y --no-install-recommends -- '.implode(' ', bootstrapRuntimeRequiredPackages());
        expect($aptLog)->toBe("apt-get update\n{$expectedInstall}\n");

        // Key material never leaks into the report.
        expect($output)->not->toContain('KEY-FROM');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('never runs apt upgrade in any form', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        bootstrapRuntimeRun(['--apply'], $env);

        $aptLog = bootstrapRuntimeAptLog($scratch);
        expect($aptLog)->not->toBe('');
        expect($aptLog)->not->toContain('upgrade');

        foreach (explode("\n", trim($aptLog)) as $line) {
            expect(
                str_starts_with($line, 'apt-get update') || str_starts_with($line, 'apt-get install'),
            )->toBeTrue("unexpected apt invocation: {$line}");
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('preserves unrelated repositories byte-for-byte across --apply', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);

        $unrelated = [
            '/apt/sources.list.d/nodesource.list',
            '/apt/sources.list.d/clickhouse.list',
            '/apt/sources.list.d/datadog-vector.sources',
            '/apt/sources.list',
        ];
        $before = [];
        foreach ($unrelated as $file) {
            $before[$file] = (string) file_get_contents($scratch.$file);
        }

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        foreach ($unrelated as $file) {
            expect((string) file_get_contents($scratch.$file))->toBe($before[$file], "{$file} was modified");
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('is idempotent: a second --apply performs no apt call, no key fetch and no file rewrite', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        $sourcesBefore = bootstrapRuntimeTreeSnapshot($scratch.'/apt');
        $keyringsBefore = bootstrapRuntimeTreeSnapshot($scratch.'/keyrings');

        foreach (['apt.log', 'curl.log', 'gpg.log'] as $log) {
            file_put_contents($scratch.'/log/'.$log, '');
        }

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "second apply must converge trivially:\n{$output}");
        expect($output)->toContain('repo:php already configured by this installer — nothing to do');
        expect($output)->toContain('repo:pgdg already configured by this installer — nothing to do');
        expect($output)->toContain('packages: all 41 required packages already installed');

        expect((string) file_get_contents($scratch.'/log/apt.log'))->toBe('', 'second apply ran apt-get');
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('', 'second apply re-fetched key material');
        expect((string) file_get_contents($scratch.'/log/gpg.log'))->toBe('', 'second apply re-imported keys');

        expect(bootstrapRuntimeTreeSnapshot($scratch.'/apt'))->toBe($sourcesBefore);
        expect(bootstrapRuntimeTreeSnapshot($scratch.'/keyrings'))->toBe($keyringsBefore);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('leaves pre-existing operator-configured repositories untouched while installing missing packages', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'packages' => array_values(array_diff(bootstrapRuntimeRequiredPackages(), ['php8.5-zip'])),
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('repo:php provided by a pre-existing apt source — left untouched');
        expect($output)->toContain('repo:pgdg provided by a pre-existing apt source — left untouched');

        expect(is_file($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBeFalse('installer duplicated a pre-existing PHP source');
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-pgdg.sources'))->toBeFalse('installer duplicated a pre-existing PGDG source');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('installer fetched keys for repositories it does not own');

        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update\napt-get install -y --no-install-recommends -- php8.5-zip\n",
        );
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when one repository key cannot be fetched: earlier repo intact, no partial files, no install', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['curlFailPattern' => 'postgresql.org']);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: repo:pgdg: cannot download signing key');

        // The PHP repository completed transactionally before the failure.
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBeTrue();
        expect(is_file($scratch.'/keyrings/rateguru-php.gpg'))->toBeTrue();

        // No partial PGDG artifacts and no staged temp files anywhere.
        $leftovers = array_merge(
            glob($scratch.'/apt/sources.list.d/rateguru-pgdg*') ?: [],
            glob($scratch.'/keyrings/rateguru-pgdg*') ?: [],
            glob($scratch.'/apt/sources.list.d/*.XXXXXX*') ?: [],
            glob($scratch.'/apt/sources.list.d/*.sources.*') ?: [],
            glob($scratch.'/keyrings/*.gpg.*') ?: [],
        );
        expect($leftovers)->toBe([]);

        // Dependent package installation never started.
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('refuses key material whose fingerprint does not match the pin and stops before any mutation', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, [
            'fprPhp' => str_repeat('DEADBEEF', 5),
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('signing key fingerprint does not match the pinned '.bootstrapRuntimePhpPpaFingerprint());

        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('refuses key material that bundles extra keys beyond the pinned one', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['gpgExtraKey' => true]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('refusing to install it');
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('aborts when apt-get update fails, before any package installation', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['aptUpdateFail' => true]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get update failed');
        expect(bootstrapRuntimeAptLog($scratch))->toBe("apt-get update\n");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('propagates an apt-get install failure as its own error', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['aptInstallFail' => true]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get install failed');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('requires root for --apply and mutates nothing without it', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['euid' => '1000']);
        $before = bootstrapRuntimeTreeSnapshot($scratch);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: --apply must run as root');
        expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --apply closed on an unsupported OS or architecture before any mutation', function () {
    foreach ([
        ['os' => 'ubuntu-24.04'],
        ['os' => 'debian'],
        ['arch' => 'aarch64'],
    ] as $brokenHost) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeCleanHostFixture($scratch, $brokenHost);
            $before = bootstrapRuntimeTreeSnapshot($scratch);

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, $output);
            expect($output)->toContain('ERROR: unsupported');
            expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before, 'a hard-gated apply still mutated the fixture');
            expect(is_file($scratch.'/log/apt.log'))->toBeFalse();
            expect(is_file($scratch.'/log/curl.log'))->toBeFalse();
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('never touches RateGuru application paths, users or unrelated host state during --apply', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        $fsBefore = bootstrapRuntimeTreeSnapshot($scratch.'/fs/home-www-rateguru');

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        // The decoy application tree is byte-identical.
        expect(bootstrapRuntimeTreeSnapshot($scratch.'/fs/home-www-rateguru'))->toBe($fsBefore);

        // Every mutation is accounted for: apt log holds only update/install,
        // and the only new files are the four installer-owned repo files.
        foreach (explode("\n", trim(bootstrapRuntimeAptLog($scratch))) as $line) {
            expect(
                str_starts_with($line, 'apt-get update') || str_starts_with($line, 'apt-get install'),
            )->toBeTrue("unexpected apt invocation: {$line}");
        }

        $newRepoFiles = array_map(
            fn (string $path): string => basename($path),
            array_merge(
                glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [],
                glob($scratch.'/keyrings/*') ?: [],
            ),
        );
        sort($newRepoFiles);
        expect($newRepoFiles)->toBe([
            'rateguru-pgdg.gpg', 'rateguru-pgdg.sources',
            'rateguru-php.gpg', 'rateguru-php.sources',
        ]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --verify
// =============================================================================

it('verifies the full contract on a compliant host without printing apply hints', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('SLICE 5.2 CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     php-modules — all required modules loaded');
        expect($output)->toContain('PASS     tool:createdb');
        expect($output)->toContain('PASS     tool:dropdb');
        expect($output)->not->toContain('-> apply:');

        // Optional development tooling is never part of the runtime
        // contract: the compliant fixture has no shellcheck/actionlint and
        // verify does not even mention them.
        expect($output)->not->toContain('shellcheck');
        expect($output)->not->toContain('actionlint');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --verify when a required runtime binary is missing', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'tools' => array_values(array_diff(bootstrapRuntimeAllTools(), ['rclone'])),
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  tool:rclone — not found (package: rclone)');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('verifies PHP extensions through php -m, not dpkg alone', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $modules = implode(' ', array_diff(bootstrapRuntimeRequiredPhpModules(), ['redis', 'pdo_pgsql']));
        $env = bootstrapRuntimeFixture($scratch, ['phpModules' => $modules]);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1, 'all packages are installed, so only the module probe can catch this');
        expect($output)->toContain('PASS     package:php8.5-redis — installed');
        expect($output)->toContain('MISSING  php-modules — not loaded: pdo_pgsql redis');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails --verify on a wrong PostgreSQL client major even with all packages installed', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['pgVersion' => '17.2']);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT psql — reports PostgreSQL 17.2, required major is 18');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('keeps --verify strictly read-only', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['tools' => 'minimal']);
        $before = bootstrapRuntimeTreeSnapshot($scratch);

        bootstrapRuntimeRun(['--verify'], $env);

        expect(bootstrapRuntimeTreeSnapshot($scratch))->toBe($before);
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse();
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse();
        expect(is_file($scratch.'/log/gpg.log'))->toBeFalse();
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// Security posture
// =============================================================================

it('never uses apt-key, eval, bash -c or wget', function () {
    $source = bootstrapRuntimeSource();

    // Comment lines may mention the forbidden commands (the header
    // documents the policy); no code line may invoke them.
    expect(preg_match('/^[^#\n]*\bapt-key\b/m', $source))->toBe(0, 'a code line invokes apt-key');
    expect(preg_match('/^[^#\n]*\beval\b/m', $source))->toBe(0, 'a code line uses eval');
    expect(preg_match('/^[^#\n]*\bwget\b/m', $source))->toBe(0, 'a code line uses wget');
    expect($source)->not->toContain('bash -c');
});

it('pins HTTPS-only key sources and dedicated keyrings under /etc/apt/keyrings', function () {
    $source = bootstrapRuntimeSource();

    expect($source)->toContain('PHP_REPO_KEY_URL="https://');
    expect($source)->toContain('PGDG_REPO_KEY_URL="https://');
    expect($source)->toContain('PHP_REPO_URI="https://');
    expect($source)->toContain('PGDG_REPO_URI="https://');
    expect($source)->toContain("--proto '=https' --tlsv1.2");
    expect($source)->toContain('RATEGURU_BOOTSTRAP_APT_KEYRINGS_DIR /etc/apt/keyrings');
    expect($source)->toContain('rateguru-php.gpg');
    expect($source)->toContain('rateguru-pgdg.gpg');
});

it('honors RATEGURU_BOOTSTRAP_* overrides only alongside the explicit test-override gate', function () {
    $source = bootstrapRuntimeSource();

    // Every override is read through gated_default — no direct expansion.
    preg_match_all('/RATEGURU_BOOTSTRAP_[A-Z_]+/', $source, $matches);
    $overrides = array_unique($matches[0]);
    expect($overrides)->not->toBe([]);

    foreach ($overrides as $override) {
        expect(
            preg_match('/gated_default '.preg_quote($override, '/').' /', $source),
        )->toBe(1, "{$override} must be read exactly once, through gated_default");
    }

    // Behaviorally: without the gate, a fixture override must be ignored.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['os' => 'debian']);
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        [, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($output)->not->toContain('ID=debian');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('runs apt noninteractively and deterministically', function () {
    $source = bootstrapRuntimeSource();

    expect($source)->toContain('DEBIAN_FRONTEND=noninteractive');
    expect($source)->toContain('--no-install-recommends');
});

// =============================================================================
// Contract parity with the rest of the repository
// =============================================================================

it('keeps the OS baseline pins byte-identical to bootstrap-host-preflight', function () {
    $installer = bootstrapRuntimeSource();
    $preflight = File::get(base_path('infrastructure/scripts/bootstrap-host-preflight'));

    foreach (['SUPPORTED_OS_ID', 'SUPPORTED_OS_VERSION_ID'] as $pin) {
        preg_match('/^'.$pin.'="([^"]+)"$/m', $installer, $installerPin);
        preg_match('/^'.$pin.'="([^"]+)"$/m', $preflight, $preflightPin);

        expect($installerPin[1] ?? null)->not->toBeNull("installer does not pin {$pin}");
        expect($preflightPin[1] ?? null)->not->toBeNull("preflight does not pin {$pin}");
        expect($installerPin[1])->toBe($preflightPin[1], "{$pin} drifted between preflight and installer");
    }
});

it('keeps the PHP series aligned with the committed deployment.conf template', function () {
    $installer = bootstrapRuntimeSource();
    $template = File::get(base_path('infrastructure/templates/deployment.conf.example'));

    preg_match('/^PHP_SERIES="([^"]+)"$/m', $installer, $series);
    expect($series[1] ?? null)->not->toBeNull();

    expect($template)->toContain('PHP_BIN=/usr/bin/php'.$series[1]);
    expect($template)->toContain('PHP_FPM_SERVICE=php'.$series[1].'-fpm');
});

it('covers every PHP extension the deploy workflow and composer.json require', function () {
    $installer = bootstrapRuntimeSource();

    preg_match('/^REQUIRED_PHP_MODULES=\(([^)]+)\)$/m', $installer, $modulesMatch);
    expect($modulesMatch[1] ?? null)->not->toBeNull();
    $verifiedModules = preg_split('/\s+/', trim($modulesMatch[1]));

    $workflow = File::get(base_path('.github/workflows/deploy-staging.yml'));
    preg_match('/extensions:\s*(.+)$/m', $workflow, $extensionsMatch);
    expect($extensionsMatch[1] ?? null)->not->toBeNull('deploy-staging.yml no longer declares setup-php extensions');
    $workflowExtensions = array_map('trim', explode(',', $extensionsMatch[1]));

    foreach ($workflowExtensions as $extension) {
        expect(in_array($extension, $verifiedModules, true))
            ->toBeTrue("deploy workflow extension {$extension} is not verified by the installer");
    }

    $composer = json_decode(File::get(base_path('composer.json')), true);
    foreach (array_keys($composer['require']) as $requirement) {
        if (str_starts_with($requirement, 'ext-')) {
            $extension = substr($requirement, 4);
            expect(in_array($extension, $verifiedModules, true))
                ->toBeTrue("composer.json {$requirement} is not verified by the installer");
        }
    }
});

it('never installs build-time or unwanted packages: no Node.js, npm, Composer, SQLite or dev validators', function () {
    $installer = bootstrapRuntimeSource();

    preg_match('/^BASE_PACKAGES=\(\n(.*?)\n\)$/ms', $installer, $baseMatch);
    expect($baseMatch[1] ?? null)->not->toBeNull();

    $packages = array_values(array_filter(array_map('trim', explode("\n", $baseMatch[1]))));

    foreach (['nodejs', 'npm', 'composer', 'sqlite3', 'php8.5-sqlite3', 'shellcheck', 'actionlint', 'wget'] as $forbidden) {
        expect($packages)->not->toContain($forbidden);
    }

    // The php package family is bcmath..zip only — sqlite/readline/igbinary
    // are deliberately absent (igbinary arrives as a php8.5-redis
    // dependency; readline is not a runtime requirement).
    preg_match('/^for _php_component in ([^;]+);/m', $installer, $phpMatch);
    expect($phpMatch[1] ?? null)->not->toBeNull();
    $phpComponents = preg_split('/\s+/', trim($phpMatch[1]));
    expect($phpComponents)->toBe(['cli', 'common', 'fpm', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'pgsql', 'redis', 'xml', 'zip']);
});

it('derives its required tool inventory from the Phase 5.1 canonical contract', function () {
    $preflight = File::get(base_path('infrastructure/scripts/bootstrap-host-preflight'));
    $installerPackages = bootstrapRuntimeRequiredPackages();

    // Every package the preflight's REQUIRED_BASE_TOOLS inventory names
    // (except bash-builtins carriers already guaranteed by Ubuntu's
    // essential set) must be in the installer's required package list.
    preg_match('/^REQUIRED_BASE_TOOLS=\(\n(.*?)\n\)$/ms', $preflight, $match);
    expect($match[1] ?? null)->not->toBeNull();

    foreach (array_filter(array_map('trim', explode("\n", $match[1]))) as $entry) {
        [, $package] = explode(':', $entry);

        expect(in_array($package, $installerPackages, true))
            ->toBeTrue("preflight requires package {$package}, but the installer does not install it");
    }
});
