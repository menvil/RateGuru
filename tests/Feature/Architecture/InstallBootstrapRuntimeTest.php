<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * : infrastructure/scripts/install-bootstrap-runtime — the
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
 *
 * rclone is a managed external runtime binary, not an apt package: the fixture
 * simulates the canonical binary, the committed external-runtimes contract
 * and signing key, the official download origin (through the curl stub) and
 * the clearsign verification (through the gpg stub). The archive bytes are
 * fake, but their SHA256 in the fake SHA256SUMS payload is real, so the
 * script's checksum verification passes and fails exactly like production.
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

    foreach (['', '/bin', '/fs', '/fs/usr-bin', '/fs/usr-local-bin', '/tools', '/staged', '/log', '/apt/sources.list.d', '/keyrings'] as $sub) {
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
        'acl', 'bash', 'bind9-dnsutils', 'ca-certificates', 'certbot', 'coreutils', 'cron', 'curl',
        'diffutils', 'findutils', 'gnupg', 'grep', 'gzip', 'hostname',
        'iproute2', 'jq', 'libc-bin', 'mawk', 'nftables', 'nginx', 'openssh-server', 'openssl',
        'passwd', 'procps', 'redis-server', 'rsync', 'sed', 'sudo',
        'supervisor', 'tar', 'unzip', 'util-linux', 'xz-utils',
    ];

    $php = array_map(
        fn (string $component): string => "php8.5-{$component}",
        ['cli', 'common', 'fpm', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'pgsql', 'redis', 'xml', 'zip'],
    );

    return array_merge($base, $php, ['postgresql-18', 'postgresql-client-18', 'postgresql-18-roaringbitmap']);
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
 * and unzip are separate behavioral stubs added by the fixture builder.
 * rclone is deliberately not a PATH tool here: the managed external runtime
 * is probed at its canonical contract path, and a PATH-resolvable rclone
 * without the canonical binary is its own CONFLICT scenario.
 *
 * @return list<string>
 */
function bootstrapRuntimeAllTools(): array
{
    return [
        'apt-get', 'dpkg',
        'setfacl', 'getfacl', 'certbot', 'cron', 'curl', 'cmp', 'diff', 'find',
        'gpg', 'grep', 'gzip', 'hostname', 'ss', 'ip', 'jq', 'dig', 'openssl', 'getent',
        'awk', 'nft', 'nginx', 'sshd', 'useradd', 'redis-server',
        'rsync', 'sed', 'sudo', 'visudo', 'supervisord', 'tar', 'flock',
        'namei', 'runuser', 'xz', 'createdb', 'dropdb',
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

function bootstrapRuntimeRcloneFingerprint(): string
{
    return 'FBF737ECE9F8AB18604BD2AC93935E02FF3B54FA';
}

/**
 * The committed external-runtimes contract, parsed the same way the scripts
 * parse it (plain KEY=VALUE text). The single test-side source of the pinned
 * version — never duplicated as a literal.
 *
 * @return array<string, string>
 */
function bootstrapRuntimeCommittedRcloneContract(): array
{
    $contract = [];

    foreach (explode("\n", File::get(base_path('infrastructure/config/external-runtimes/versions.env'))) as $line) {
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $contract[$key] ??= $value;
    }

    return $contract;
}

/**
 * The rclone version a fixture simulates — the committed pin unless a test
 * deliberately moves it.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeFixtureRcloneVersion(array $options): string
{
    return $options['rcloneContractVersion'] ?? bootstrapRuntimeCommittedRcloneContract()['RCLONE_VERSION'];
}

function bootstrapRuntimeCurrentUser(): string
{
    return posix_getpwuid(posix_geteuid())['name'];
}

function bootstrapRuntimeCurrentGroup(): string
{
    return posix_getgrgid(posix_getegid())['name'];
}

/**
 * The fake pinned-release archive bytes the curl stub serves. Content is
 * arbitrary, but its real SHA256 is what the fixture SHA256SUMS payload
 * carries, so checksum verification is exercised for real.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeRcloneArchiveContent(array $options): string
{
    return 'RCLONE-ARCHIVE:v'.bootstrapRuntimeFixtureRcloneVersion($options);
}

function bootstrapRuntimeYtDlpFingerprint(): string
{
    return 'AC0CBBE6848D6A873464AF4E57CF65933B5A7581';
}

/**
 * The ffmpeg build a fixture simulates — the committed pin unless a test
 * deliberately moves it.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeFixtureFfmpegVersion(array $options): string
{
    return $options['ffmpegContractVersion'] ?? bootstrapRuntimeCommittedRcloneContract()['FFMPEG_VERSION'];
}

/**
 * The bytes the curl stub serves as the pinned archive. What is inside never
 * matters — the tar stub "extracts" the staged binaries — but its genuine
 * SHA-256 is what the fixture contract pins, so the digest check is real.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeFfmpegArchiveContent(array $options): string
{
    return 'FFMPEG-ARCHIVE:'.bootstrapRuntimeFixtureFfmpegVersion($options);
}

/**
 * The fingerprint of every primary key in a committed public key file, as
 * the real gpg reads it — the installer's own acceptance test for committed
 * key material. Null when gpg is not on this machine (it always is on the
 * Ubuntu CI runners).
 *
 * @return list<string>|null
 */
function bootstrapRuntimeKeyPrimaryFingerprints(string $keyPath): ?array
{
    $gpg = trim((string) shell_exec('command -v gpg'));

    if ($gpg === '') {
        return null;
    }

    exec($gpg.' --batch --show-keys --with-colons '.escapeshellarg($keyPath).' 2>/dev/null', $lines, $status);
    expect($status)->toBe(0, "{$keyPath} is not a valid OpenPGP key");

    $fingerprints = [];
    $expectFpr = false;

    foreach ($lines as $line) {
        $fields = explode(':', $line);

        if ($fields[0] === 'pub') {
            $expectFpr = true;

            continue;
        }

        if ($fields[0] === 'fpr' && $expectFpr) {
            $fingerprints[] = $fields[9];
            $expectFpr = false;
        }
    }

    return $fingerprints;
}

/**
 * The yt-dlp version a fixture simulates — the committed pin unless a test
 * deliberately moves it.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeFixtureYtDlpVersion(array $options): string
{
    return $options['ytdlpContractVersion'] ?? bootstrapRuntimeCommittedRcloneContract()['YTDLP_VERSION'];
}

/**
 * The bytes the curl stub serves as the pinned standalone executable: a stub
 * that logs its invocations and reports a version — the pinned one unless a
 * test makes the download lie. Its genuine SHA256 is what the fixture
 * SHA2-256SUMS carries, so checksum verification is exercised for real.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeYtDlpServedArtifact(array $options): string
{
    if ($options['ytdlpTamperedRelease'] ?? false) {
        // Someone else's executable claiming the pinned version: only the
        // signature over SHA2-256SUMS can tell it from the real one.
        return "#!/bin/bash\n# TAMPERED\n[[ \"$1\" == \"--version\" ]] && printf '%s\\n' '".bootstrapRuntimeFixtureYtDlpVersion($options)."'\n";
    }

    return bootstrapRuntimeYtDlpArtifactContent($options);
}

/**
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeYtDlpArtifactContent(array $options): string
{
    $reported = $options['ytdlpDownloadedVersion'] ?? bootstrapRuntimeFixtureYtDlpVersion($options);

    return implode("\n", [
        '#!/bin/bash',
        '# YTDLP-DOWNLOADED-EXECUTABLE',
        'printf \'yt-dlp %s\n\' "$*" >> "${STUB_LOG}/yt-dlp.log"',
        'if [[ "$1" == "--version" ]]; then',
        "    printf '%s\\n' '{$reported}'",
        '    exit 0',
        'fi',
        'exit 1',
    ])."\n";
}

/**
 * The exact deb822 content the installer owns for one repository — must stay
 * byte-identical to sources_file_content() in the script.
 */
function bootstrapRuntimeExpectedSources(string $label, string $uri, string $suite, string $keyring): string
{
    return implode("\n", [
        "# RateGuru {$label} repository — managed by install-bootstrap-runtime",
        '#. Do not edit: re-run --apply to reconcile.',
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

    // The exact keyring content --apply would have installed: dearmored,
    // pin-validated key material (the gpg stub recognizes the embedded URL
    // and answers with the per-repository fingerprint).
    $phpKeyringContent = 'DEARMORED:KEY-FROM:https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x'.bootstrapRuntimePhpPpaFingerprint()."\n";
    $pgdgKeyringContent = "DEARMORED:KEY-FROM:https://www.postgresql.org/media/keys/ACCC4CF8.asc\n";

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
        file_put_contents($scratch.'/keyrings/rateguru-php.gpg', $phpKeyringContent);
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
        file_put_contents($scratch.'/keyrings/rateguru-pgdg.gpg', $pgdgKeyringContent);
    }

    // A decoy RateGuru runtime tree: install-bootstrap-runtime must never touch application
    // paths, so its continued byte-identity is asserted after --apply.
    expect(@mkdir($scratch.'/fs/home-www-rateguru', 0o755, true))->toBeTrue();
    file_put_contents($scratch.'/fs/home-www-rateguru/decoy.txt', "application files — never touched by install-bootstrap-runtime\n");

    // dpkg database fixture: one package name per line.
    $packages = $options['packages'] ?? 'all';
    $installed = match (true) {
        $packages === 'all' => bootstrapRuntimeRequiredPackages(),
        $packages === 'none' => [],
        default => $packages,
    };
    file_put_contents($scratch.'/fs/dpkg-installed.txt', implode("\n", $installed).($installed === [] ? '' : "\n"));

    bootstrapRuntimeWriteRcloneFixture($scratch, $options);
    bootstrapRuntimeWriteYtDlpFixture($scratch, $options);
    bootstrapRuntimeWriteFfmpegFixture($scratch, $options);

    // The running nginx master: its PID file and its executable as the kernel
    // shows it — the installed binary, or one an upgrade has replaced.
    expect(@mkdir($scratch.'/fs/run', 0o755, true) || is_dir($scratch.'/fs/run'))->toBeTrue();
    expect(@mkdir($scratch.'/fs/proc/4242', 0o755, true))->toBeTrue();
    if ($options['nginxActive'] ?? true) {
        file_put_contents($scratch.'/fs/run/nginx.pid', "4242\n");
    }
    symlink(($options['nginxRunsReplaced'] ?? false) ? '/usr/sbin/nginx (deleted)' : '/usr/sbin/nginx', $scratch.'/fs/proc/4242/exe');

    // PHP-FPM's master (pid 5151) and a supervised queue worker (pid 6161),
    // on the installed binaries or on ones an upgrade replaced.
    $replaced = $options['phpRunsReplaced'] ?? false;
    expect(@mkdir($scratch.'/fs/run/php', 0o755, true))->toBeTrue();
    expect(@mkdir($scratch.'/fs/proc/5151', 0o755, true))->toBeTrue();
    expect(@mkdir($scratch.'/fs/proc/6161', 0o755, true))->toBeTrue();
    file_put_contents($scratch.'/fs/run/php/php8.5-fpm.pid', "5151\n");
    symlink($replaced ? '/usr/sbin/php-fpm8.5 (deleted)' : '/usr/sbin/php-fpm8.5', $scratch.'/fs/proc/5151/exe');
    symlink($replaced ? '/usr/bin/php8.5 (deleted)' : '/usr/bin/php8.5', $scratch.'/fs/proc/6161/exe');
}

/**
 * The managed rclone runtime landscape: a committed-style contract and
 * signing key, the SHA256SUMS payload the curl stub serves, the canonical
 * binary in whatever state the scenario needs, and the operator's decoy
 * rclone.conf that must never be read or written.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteRcloneFixture(string $scratch, array $options): void
{
    $version = bootstrapRuntimeFixtureRcloneVersion($options);

    file_put_contents($scratch.'/fs/external-runtimes.env', implode("\n", [
        '# Test fixture mirror of infrastructure/config/external-runtimes/versions.env.',
        'RCLONE_VERSION='.$version,
        'RCLONE_PLATFORM=linux-amd64',
        'RCLONE_BINARY='.$scratch.'/fs/usr-bin/rclone',
        'RCLONE_OWNER='.($options['rcloneOwner'] ?? bootstrapRuntimeCurrentUser()),
        'RCLONE_GROUP='.($options['rcloneGroup'] ?? bootstrapRuntimeCurrentGroup()),
        'RCLONE_MODE=0755',
        'RCLONE_RELEASE_SIGNING_FINGERPRINT='.bootstrapRuntimeRcloneFingerprint(),
    ])."\n");

    // Marker content the gpg stub recognizes as the committed signing key.
    file_put_contents($scratch.'/fs/rclone-release-signing-key.asc', "RCLONE-SIGNING-KEY\n");

    // The signed SHA256SUMS payload: a foreign-platform row proves digest
    // selection is by exact artifact name; the linux-amd64 row carries the
    // genuine SHA256 of the fake archive bytes unless a test corrupts it.
    $digest = $options['rcloneSumsDigest'] ?? hash('sha256', bootstrapRuntimeRcloneArchiveContent($options));
    file_put_contents($scratch.'/fs/rclone-sums.txt', implode("\n", [
        str_repeat('0', 64)."  rclone-v{$version}-linux-arm64.zip",
        "{$digest}  rclone-v{$version}-linux-amd64.zip",
    ])."\n");

    // The canonical installed binary. 'compliant' reports the contract
    // version; a version string simulates drift (the real staging v1.74.4,
    // the Ubuntu-package v1.53.3); 'broken' cannot report a version at all;
    // 'absent' is the clean host. Every invocation is logged so tests can
    // prove the binary is only ever asked for --version — never `config`,
    // never `selfupdate`.
    $installedState = $options['rcloneInstalled'] ?? 'compliant';

    if ($installedState !== 'absent') {
        $reported = $installedState === 'compliant' ? 'v'.$version : $installedState;
        $versionLine = $installedState === 'broken'
            ? "    printf 'garbage without a version\\n'"
            : "    printf 'rclone {$reported}\\n'";

        file_put_contents($scratch.'/fs/usr-bin/rclone', implode("\n", [
            '#!/bin/bash',
            'printf \'rclone %s\n\' "$*" >> "${STUB_LOG}/rclone.log"',
            'if [[ "$1" == "--version" ]]; then',
            $versionLine,
            '    exit 0',
            'fi',
            'exit 1',
        ])."\n");
        chmod($scratch.'/fs/usr-bin/rclone', intval($options['rcloneFileMode'] ?? '0755', 8));
    }

    // Operator secret material — presence only; content must survive every
    // mode byte-identically.
    expect(@mkdir($scratch.'/fs/root-config-rclone', 0o700, true))->toBeTrue();
    file_put_contents(
        $scratch.'/fs/root-config-rclone/rclone.conf',
        "[b2]\ntype = b2\naccount = SECRET-B2-ACCOUNT-SENTINEL\nkey = SECRET-B2-KEY-SENTINEL\n",
    );
}

/**
 * The managed yt-dlp landscape, the way the rclone fixture builds rclone's:
 * its rows in the shared contract, a marker signing key, the SHA2-256SUMS
 * the curl stub serves, and the canonical binary in whatever state the
 * scenario needs. Every invocation of the installed binary is logged, so a
 * test can prove it is only ever asked for --version — never `-U`.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteYtDlpFixture(string $scratch, array $options): void
{
    $version = bootstrapRuntimeFixtureYtDlpVersion($options);

    file_put_contents($scratch.'/fs/external-runtimes.env', implode("\n", [
        'YTDLP_VERSION='.$version,
        'YTDLP_ARTIFACT=yt-dlp_linux',
        'YTDLP_BINARY='.$scratch.'/fs/usr-local-bin/yt-dlp',
        'YTDLP_OWNER='.($options['ytdlpOwner'] ?? bootstrapRuntimeCurrentUser()),
        'YTDLP_GROUP='.($options['ytdlpGroup'] ?? bootstrapRuntimeCurrentGroup()),
        'YTDLP_MODE=0755',
        'YTDLP_RELEASE_SIGNING_FINGERPRINT='.bootstrapRuntimeYtDlpFingerprint(),
    ])."\n", FILE_APPEND);

    file_put_contents($scratch.'/fs/yt-dlp-release-signing-key.asc', "YTDLP-SIGNING-KEY\n");

    // A row for another artifact of the same release proves the digest is
    // picked by exact artifact name.
    $sums = fn (string $digest): string => implode("\n", [
        str_repeat('0', 64).'  yt-dlp_linux_aarch64',
        "{$digest}  yt-dlp_linux",
    ])."\n";
    $signed = $sums($options['ytdlpSumsDigest'] ?? hash('sha256', bootstrapRuntimeYtDlpArtifactContent($options)));

    // The signature covers the SHA2-256SUMS the release published. A tampered
    // release serves another executable with a SHA2-256SUMS rewritten to
    // match it, under the published signature.
    file_put_contents($scratch.'/fs/ytdlp-signed-digest.txt', hash('sha256', $signed));
    file_put_contents(
        $scratch.'/fs/ytdlp-sums.txt',
        ($options['ytdlpTamperedRelease'] ?? false) ? $sums(hash('sha256', bootstrapRuntimeYtDlpServedArtifact($options))) : $signed,
    );

    $installedState = $options['ytdlpInstalled'] ?? 'compliant';

    if ($installedState !== 'absent') {
        $reported = $installedState === 'compliant' ? $version : $installedState;
        $versionLine = $installedState === 'broken'
            ? "    printf 'garbage without a version\\n'"
            : "    printf '%s\\n' '{$reported}'";

        file_put_contents($scratch.'/fs/usr-local-bin/yt-dlp', implode("\n", [
            '#!/bin/bash',
            'printf \'yt-dlp %s\n\' "$*" >> "${STUB_LOG}/yt-dlp.log"',
            'if [[ "$1" == "--version" ]]; then',
            $versionLine,
            '    exit 0',
            'fi',
            'exit 1',
        ])."\n");
        chmod($scratch.'/fs/usr-local-bin/yt-dlp', intval($options['ytdlpFileMode'] ?? '0755', 8));
    }
}

/**
 * The managed ffmpeg landscape: its rows in the shared contract (the digest
 * of the archive the curl stub serves, unless a test pins another), and the
 * pair at the managed directory in whatever state the scenario needs:
 * 'compliant', 'absent', 'broken' (no version), a version string (drift for
 * both), or 'ffprobe-missing'. Every invocation is logged, so a test can
 * prove the binaries are only ever asked for their version.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapRuntimeWriteFfmpegFixture(string $scratch, array $options): void
{
    $version = bootstrapRuntimeFixtureFfmpegVersion($options);

    file_put_contents($scratch.'/fs/external-runtimes.env', implode("\n", [
        'FFMPEG_VERSION='.$version,
        'FFMPEG_RELEASE=autobuild-2026-10-31-12-59',
        'FFMPEG_ARTIFACT=ffmpeg-n8.1.3-27-g8dca1586f4-linux64-gpl-8.1.tar.xz',
        'FFMPEG_SHA256='.($options['ffmpegSha256'] ?? hash('sha256', bootstrapRuntimeFfmpegArchiveContent($options))),
        'FFMPEG_BIN_DIR='.$scratch.'/fs/usr-local-bin',
        'FFMPEG_OWNER='.($options['ffmpegOwner'] ?? bootstrapRuntimeCurrentUser()),
        'FFMPEG_GROUP='.($options['ffmpegGroup'] ?? bootstrapRuntimeCurrentGroup()),
        'FFMPEG_MODE=0755',
    ])."\n", FILE_APPEND);

    $installedState = $options['ffmpegInstalled'] ?? 'compliant';

    if ($installedState === 'absent') {
        return;
    }

    foreach (['ffmpeg', 'ffprobe'] as $tool) {
        if ($tool === 'ffprobe' && $installedState === 'ffprobe-missing') {
            continue;
        }

        $reported = in_array($installedState, ['compliant', 'ffprobe-missing'], true) ? $version : $installedState;
        $versionLine = $installedState === 'broken'
            ? "    printf 'garbage without a version\\n'"
            : "    printf '%s version %s Copyright (c) the FFmpeg developers\\n' '{$tool}' '{$reported}'";

        file_put_contents($scratch.'/fs/usr-local-bin/'.$tool, implode("\n", [
            '#!/bin/bash',
            'printf \'%s %s\\n\' "${0##*/}" "$*" >> "${STUB_LOG}/ffmpeg.log"',
            'if [[ " $* " == *" -version "* ]]; then',
            $versionLine,
            '    exit 0',
            'fi',
            'exit 1',
        ])."\n");
        chmod($scratch.'/fs/usr-local-bin/'.$tool, intval($options['ffmpegFileMode'] ?? '0755', 8));
    }
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
            version="${STUB_PHP_VERSION}"
            if [[ -f "${STUB_LOG}/php.version" ]]; then
                read -r version < "${STUB_LOG}/php.version"
            fi
            printf 'PHP %s (%s) (built: Jan  1 2026 00:00:00) (NTS)\n' "${version}" "${STUB_PHP_SAPI}"
            exit 0
        fi
        if [[ "$1" == "-t" ]]; then
            printf 'php-fpm -t\n' >> "${STUB_LOG}/service.log"
            [[ "${STUB_PHP_FPM_CONFIG_FAIL:-}" != "true" ]]
            exit
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

    // unzip: always present as a behavioral stub (like the php/pg binaries).
    // "Extracting" the fake archive materializes the staged extracted-rclone
    // stub under the exact directory layout the real release archive uses.
    bootstrapRuntimeWriteStub($scratch.'/tools/unzip', <<<'STUB'
        #!/bin/bash
        zip=""
        dest=""
        prev=""
        for arg in "$@"; do
            if [[ "${prev}" == "-d" ]]; then
                dest="${arg}"
            elif [[ "${arg}" != -* ]]; then
                zip="${arg}"
            fi
            prev="${arg}"
        done
        if [[ ! -f "${zip}" || -z "${dest}" ]]; then
            exit 9
        fi
        PATH="${STUB_REAL_PATH}" mkdir -p "${dest}/${STUB_UNZIP_DIR_NAME}"
        if [[ "${STUB_UNZIP_OMIT_BINARY:-}" == "true" ]]; then
            exit 0
        fi
        PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/rclone-extracted" "${dest}/${STUB_UNZIP_DIR_NAME}/rclone"
        exit 0
        STUB);

    // The binary "inside" the archive: reports whatever version the test
    // configured and logs every invocation, so tests can prove the managed
    // binary is only ever asked for --version.
    bootstrapRuntimeWriteStub($scratch.'/staged/rclone-extracted', <<<'STUB'
        #!/bin/bash
        printf 'rclone %s\n' "$*" >> "${STUB_LOG}/rclone.log"
        if [[ "$1" == "--version" ]]; then
            printf 'rclone %s\n' "${STUB_EXTRACTED_RCLONE_VERSION}"
            exit 0
        fi
        exit 1
        STUB);

    // tar: a behavioral stub too. "Extracting" members of the fake archive
    // materializes the staged extracted ffmpeg/ffprobe under the layout the
    // real archive uses — every member named, nothing else.
    bootstrapRuntimeWriteStub($scratch.'/tools/tar', <<<'STUB'
        #!/bin/bash
        printf 'tar %s\n' "$*" >> "${STUB_LOG}/tar.log"
        archive=""
        dest=""
        prev=""
        members=()
        for arg in "$@"; do
            if [[ "${prev}" == "-xJf" ]]; then
                archive="${arg}"
            elif [[ "${prev}" == "-C" ]]; then
                dest="${arg}"
            elif [[ "${arg}" != -* ]]; then
                members+=("${arg}")
            fi
            prev="${arg}"
        done
        if [[ ! -f "${archive}" || -z "${dest}" ]]; then
            exit 2
        fi
        for member in "${members[@]}"; do
            tool="${member##*/}"
            if [[ "${tool}" == ffprobe && "${STUB_TAR_OMIT_FFPROBE:-}" == "true" ]]; then
                printf 'tar: %s: Not found in archive\n' "${member}" >&2
                exit 2
            fi
            PATH="${STUB_REAL_PATH}" mkdir -p "${dest}/${member%/*}"
            PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/${tool}-extracted" "${dest}/${member}"
        done
        exit 0
        STUB);

    // The binaries "inside" the archive: report whatever version the test
    // configured, and log every invocation.
    foreach (['ffmpeg', 'ffprobe'] as $tool) {
        bootstrapRuntimeWriteStub($scratch."/staged/{$tool}-extracted", str_replace('TOOL', $tool, <<<'STUB'
            #!/bin/bash
            printf '%s %s\n' 'TOOL' "$*" >> "${STUB_LOG}/ffmpeg.log"
            if [[ " $* " == *" -version "* ]]; then
                printf '%s version %s Copyright (c) the FFmpeg developers\n' 'TOOL' "${STUB_EXTRACTED_FFMPEG_VERSION}"
                exit 0
            fi
            exit 1
            STUB));
    }
}

function bootstrapRuntimeWriteMutationStubs(string $scratch, array $options): void
{
    // dpkg-query: reads the fixture package database; builtins only. Asked
    // for ${Version}, it answers nginx's — the version the test set, or the
    // one an --only-upgrade wrote — and a fixed one for anything else.
    bootstrapRuntimeWriteStub($scratch.'/bin/dpkg-query', <<<'STUB'
        #!/bin/bash
        pkg="${!#}"
        while IFS= read -r line; do
            if [[ "${line}" == "${pkg}" ]]; then
                if [[ "$*" == *'${Version}'* ]]; then
                    version="1.0-1"
                    if [[ "${pkg}" == "nginx" ]]; then
                        version="${STUB_NGINX_VERSION}"
                        if [[ -f "${STUB_LOG}/nginx.version" ]]; then
                            read -r version < "${STUB_LOG}/nginx.version"
                        fi
                    fi
                    printf '%s' "${version}"
                else
                    printf 'installed'
                fi
                exit 0
            fi
        done < "${STUB_DPKG_STATE}"
        exit 1
        STUB);

    // systemctl and nginx: log every call; nginx -t fails when the test asks,
    // systemctl answers is-active from the test's choice, and a restart puts
    // the running master on the installed binary — unless the test makes the
    // restart fail.
    bootstrapRuntimeWriteStub($scratch.'/bin/systemctl', <<<'STUB'
        #!/bin/bash
        printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/service.log"
        if [[ "$1" == "is-active" ]]; then
            [[ "${STUB_NGINX_ACTIVE:-true}" == "true" ]]
            exit
        fi
        if [[ "$1" == "restart" && "$2" == "nginx" ]]; then
            [[ "${STUB_NGINX_RESTART_FAIL:-}" == "true" ]] && exit 1
            PATH="${STUB_REAL_PATH}" ln -sfn /usr/sbin/nginx "${STUB_PROC_ROOT}/4242/exe"
        fi
        if [[ "$1" == "restart" && "$2" == "php8.5-fpm" ]]; then
            [[ "${STUB_PHP_FPM_RESTART_FAIL:-}" == "true" ]] && exit 1
            PATH="${STUB_REAL_PATH}" ln -sfn /usr/sbin/php-fpm8.5 "${STUB_PROC_ROOT}/5151/exe"
        fi
        exit 0
        STUB);
    bootstrapRuntimeWriteStub($scratch.'/bin/nginx', <<<'STUB'
        #!/bin/bash
        printf 'nginx %s\n' "$*" >> "${STUB_LOG}/service.log"
        [[ "${STUB_NGINX_CONFIG_FAIL:-}" != "true" ]]
        STUB);

    // supervisorctl: status answers one queue worker, pid 6161; a restart
    // puts that worker on the installed PHP binary.
    bootstrapRuntimeWriteStub($scratch.'/bin/supervisorctl', <<<'STUB'
        #!/bin/bash
        if [[ "$1" == "status" ]]; then
            [[ "${STUB_SUPERVISOR_RUNNING:-true}" == "true" ]] || exit 7
            printf 'rateguru-staging-queue:rateguru-staging-queue_00   RUNNING   pid 6161, uptime 0:10:00\n'
            printf 'rateguru-staging-mailer                             STOPPED   Nov 21 01:00 PM\n'
            exit 3
        fi
        printf 'supervisorctl %s\n' "$*" >> "${STUB_LOG}/service.log"
        if [[ "$1" == "restart" ]]; then
            [[ "${STUB_SUPERVISOR_RESTART_FAIL:-}" == "true" ]] && exit 1
            PATH="${STUB_REAL_PATH}" ln -sfn /usr/bin/php8.5 "${STUB_PROC_ROOT}/6161/exe"
        fi
        exit 0
        STUB);

    // apt-get: logs every invocation; a successful install marks its
    // packages installed in the fixture dpkg database, and installing the
    // bootstrap tooling packages materializes the curl/gpg binaries on the
    // fixture tool PATH — exactly like a real host, where those tools only
    // exist after their packages are installed.
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
            if [[ "$*" == *"--only-upgrade"* ]]; then
                printf '%s\n' "${NEEDRESTART_MODE:-unset}" > "${STUB_LOG}/apt-needrestart-mode"
                if [[ "$*" == *" -- php8.5-cli "* ]]; then
                    printf '%s\n' "${STUB_PHP_UPGRADE_VERSION}" > "${STUB_LOG}/php.version"
                    # dpkg replaced the binaries under PHP-FPM and the worker.
                    PATH="${STUB_REAL_PATH}" ln -sfn '/usr/sbin/php-fpm8.5 (deleted)' "${STUB_PROC_ROOT}/5151/exe"
                    PATH="${STUB_REAL_PATH}" ln -sfn '/usr/bin/php8.5 (deleted)' "${STUB_PROC_ROOT}/6161/exe"
                fi
                if [[ "$*" == *" -- nginx "* ]]; then
                    printf '%s\n' "${STUB_NGINX_UPGRADE_VERSION}" > "${STUB_LOG}/nginx.version"
                    # dpkg replaced the binary under the running master.
                    PATH="${STUB_REAL_PATH}" ln -sfn '/usr/sbin/nginx (deleted)' "${STUB_PROC_ROOT}/4242/exe"
                fi
                exit 0
            fi
            for arg in "$@"; do
                case "${arg}" in
                    install|--|-*) ;;
                    *)
                        printf '%s\n' "${arg}" >> "${STUB_DPKG_STATE}"
                        if [[ "${arg}" == "curl" ]]; then
                            PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/curl" "${STUB_TOOLS_DIR}/curl"
                        fi
                        if [[ "${arg}" == "gnupg" ]]; then
                            PATH="${STUB_REAL_PATH}" cp "${STUB_STAGED_DIR}/gpg" "${STUB_TOOLS_DIR}/gpg"
                        fi
                        ;;
                esac
            done
        fi
        exit 0
        STUB);

    // curl: records the request and materializes fake key material carrying
    // the requested URL, so the gpg stub can answer per-repository. There is
    // deliberately NO RATEGURU_BOOTSTRAP_* override for curl/gpg — the
    // script resolves both through the fixture tool PATH, so a host profile
    // without them genuinely cannot fetch keys until apt "installs" them.
    // For the official rclone download origin it serves the fake pinned
    // archive and a fake clearsign-enveloped SHA256SUMS instead.
    $curlStub = <<<'STUB'
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
        if [[ "${url}" == *"downloads.rclone.org"* ]]; then
            if [[ "${url}" == *SHA256SUMS ]]; then
                printf 'CLEARSIGNED-BY:%s\n' "${STUB_RCLONE_SUMS_SIGNER}" > "${out}"
                while IFS= read -r line; do
                    printf '%s\n' "${line}" >> "${out}"
                done < "${STUB_RCLONE_SUMS_FILE}"
                exit 0
            fi
            printf '%s' "${STUB_RCLONE_ARCHIVE_CONTENT}" > "${out}"
            exit 0
        fi
        if [[ "${url}" == *"github.com/BtbN/FFmpeg-Builds/releases/download/"* ]]; then
            printf '%s' "${STUB_FFMPEG_ARCHIVE_CONTENT}" > "${out}"
            exit 0
        fi
        if [[ "${url}" == *"github.com/yt-dlp/yt-dlp/releases/download/"* ]]; then
            if [[ "${url}" == *SHA2-256SUMS.sig ]]; then
                printf 'DETACHED-SIGNATURE-BY:%s:%s\n' "${STUB_YTDLP_SIGNER}" "$(<"${STUB_YTDLP_SIGNED_DIGEST_FILE}")" > "${out}"
                exit 0
            fi
            if [[ "${url}" == *SHA2-256SUMS ]]; then
                while IFS= read -r line; do
                    printf '%s\n' "${line}"
                done < "${STUB_YTDLP_SUMS_FILE}" > "${out}"
                exit 0
            fi
            printf '%s' "${STUB_YTDLP_ARTIFACT_CONTENT}" > "${out}"
            exit 0
        fi
        printf 'KEY-FROM:%s' "${url}" > "${out}"
        exit 0
        STUB;

    // gpg: dearmor copies the staged key with a marker; --show-keys answers
    // with the per-repository fingerprint the test configured (garbage
    // content yields no fingerprint at all and therefore never validates);
    // --decrypt models clearsign verification — it only succeeds when the
    // keyring holds the rclone signing-key marker AND the envelope claims
    // the release signer, and emits the enclosed payload.
    $gpgStub = <<<'STUB'
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
        if [[ " $* " == *" --decrypt "* ]]; then
            out=""
            keyring=""
            prev=""
            for arg in "$@"; do
                if [[ "${prev}" == "--output" ]]; then
                    out="${arg}"
                fi
                if [[ "${prev}" == "--keyring" ]]; then
                    keyring="${arg}"
                fi
                prev="${arg}"
            done
            src="${!#}"
            if [[ ! -f "${keyring}" || "$(<"${keyring}")" != *RCLONE-SIGNING-KEY* ]]; then
                exit 2
            fi
            signed=""
            first=1
            : > "${out}"
            while IFS= read -r line; do
                if [[ "${first}" == 1 ]]; then
                    first=0
                    if [[ "${line}" == "CLEARSIGNED-BY:RCLONE-RELEASE" ]]; then
                        signed=1
                    fi
                    continue
                fi
                printf '%s\n' "${line}" >> "${out}"
            done < "${src}"
            if [[ -z "${signed}" ]]; then
                exit 2
            fi
            exit 0
        fi
        # --verify SIG DATA models a detached signature: it holds only when
        # the keyring is the yt-dlp signing-key marker, the signature claims
        # the release signer, and it covers exactly these DATA bytes.
        if [[ " $* " == *" --verify "* ]]; then
            keyring=""
            prev=""
            for arg in "$@"; do
                if [[ "${prev}" == "--keyring" ]]; then
                    keyring="${arg}"
                fi
                prev="${arg}"
            done
            sig="${@: -2:1}"
            data="${!#}"
            if [[ ! -f "${keyring}" || "$(<"${keyring}")" != *YTDLP-SIGNING-KEY* ]]; then
                exit 2
            fi
            covered="$(PATH="${STUB_REAL_PATH}" sha256sum "${data}" | PATH="${STUB_REAL_PATH}" awk '{ print $1 }')"
            [[ "$(<"${sig}")" == "DETACHED-SIGNATURE-BY:YTDLP-RELEASE:${covered}" ]] || exit 1
            exit 0
        fi
        if [[ " $* " == *" --show-keys "* ]]; then
            keyfile="${!#}"
            content="$(<"${keyfile}")"
            fpr=""
            [[ "${content}" == *keyserver.ubuntu.com* ]] && fpr="${STUB_FPR_PHP}"
            [[ "${content}" == *postgresql.org* ]] && fpr="${STUB_FPR_PGDG}"
            [[ "${content}" == *RCLONE-SIGNING-KEY* ]] && fpr="${STUB_FPR_RCLONE}"
            [[ "${content}" == *YTDLP-SIGNING-KEY* ]] && fpr="${STUB_FPR_YTDLP}"
            if [[ -z "${fpr}" ]]; then
                exit 2
            fi
            printf 'pub:-:4096:1:AAAAAAAAAAAAAAAA:1:::-:::scESC::::::23::0:\n'
            printf 'fpr:::::::::%s:\n' "${fpr}"
            if [[ "${STUB_GPG_EXTRA_KEY:-}" == "true" ]]; then
                printf 'pub:-:4096:1:BBBBBBBBBBBBBBBB:1:::-:::scESC::::::23::0:\n'
                printf 'fpr:::::::::0000000000000000000000000000000000000000:\n'
            fi
            exit 0
        fi
        exit 1
        STUB;

    // stat: the rclone owner/group/mode probe. Delegates to the real stat
    // so freshly installed fixture files report their genuine ownership and
    // mode (a static table could never reflect an --apply). Tries the GNU
    // flavor first and falls back to BSD, so the harness PATH may resolve
    // either implementation (macOS with or without brew coreutils first).
    bootstrapRuntimeWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        PATH="${STUB_REAL_PATH}" stat -c '%U|%G|%a' -- "${path}" 2>/dev/null && exit 0
        PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg|%Lp' "${path}" 2>/dev/null
        STUB);

    // Staged copies for the apt stub to "install"; live copies on the tool
    // PATH only when the simulated host profile actually has the tooling.
    bootstrapRuntimeWriteStub($scratch.'/staged/curl', $curlStub);
    bootstrapRuntimeWriteStub($scratch.'/staged/gpg', $gpgStub);

    if (($options['bootstrapTooling'] ?? 'present') === 'present') {
        if (is_file($scratch.'/tools/curl')) {
            bootstrapRuntimeWriteStub($scratch.'/tools/curl', $curlStub);
        }
        if (is_file($scratch.'/tools/gpg')) {
            bootstrapRuntimeWriteStub($scratch.'/tools/gpg', $gpgStub);
        }
    } else {
        @unlink($scratch.'/tools/curl');
        @unlink($scratch.'/tools/gpg');
    }
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
 *   phpVersion:      reported by the php stubs (default: the security floor)
 *   phpModules:      space-separated module list for `php8.5 -m`
 *   pgVersion:       reported by the pg client stubs (default 18.4)
 *   fprPhp/fprPgdg:  fingerprints the gpg stub reports per repository
 *   curlFailPattern: URL substring that makes the curl stub fail
 *   aptUpdateFail/aptInstallFail: bool
 *   gpgExtraKey:     bool — key material bundles a second key
 *   bootstrapTooling: 'present' | 'missing' — 'missing' removes curl/gpg
 *                    from the tool PATH entirely; they only appear when the
 *                    apt stub installs the bootstrap tooling packages
 *   rcloneInstalled: 'compliant' | 'absent' | 'broken' | 'v<X.Y.Z>' — the
 *                    canonical managed binary's state (default compliant)
 *   rcloneContractVersion: pinned version the fixture contract carries
 *                    (default: the committed pin)
 *   rcloneOwner/rcloneGroup: contract ownership (default: current user, so
 *                    the real fixture files comply; 'root' simulates drift)
 *   rcloneFileMode:  octal string mode of the installed binary (default 0755)
 *   rcloneSumsDigest: digest the SHA256SUMS payload carries for the amd64
 *                    artifact (default: the genuine hash of the fake bytes)
 *   rcloneBadSignature: bool — SHA256SUMS envelope claims an unknown signer
 *   fprRclone:       fingerprint the gpg stub reports for the signing key
 *   rcloneExtractedVersion: version the extracted binary reports
 *   rcloneOmitBinary: bool — the archive extracts without an rclone binary
 *   ytdlpInstalled:  'compliant' | 'absent' | 'broken' | '<YYYY.MM.DD>' — the
 *                    canonical managed yt-dlp's state (default compliant)
 *   ytdlpContractVersion: pinned version the fixture contract carries
 *                    (default: the committed pin)
 *   ytdlpOwner/ytdlpGroup/ytdlpFileMode: as for rclone
 *   ytdlpSumsDigest: digest SHA2-256SUMS carries for yt-dlp_linux (default:
 *                    the genuine hash of the served executable)
 *   ytdlpBadSignature: bool — the detached signature claims an unknown signer
 *   fprYtDlp:        fingerprint the gpg stub reports for the yt-dlp key
 *   ytdlpDownloadedVersion: version the downloaded executable reports
 *   ffmpegInstalled: 'compliant' | 'absent' | 'broken' | 'ffprobe-missing' |
 *                    '<version>' — the managed pair's state (default compliant)
 *   ffmpegContractVersion/ffmpegSha256: what the fixture contract pins
 *                    (default: the committed version; the served archive's digest)
 *   ffmpegOwner/ffmpegGroup/ffmpegFileMode: as for rclone
 *   ffmpegExtractedVersion: version the extracted binaries report
 *   ffmpegOmitFfprobe: bool — the archive has no bin/ffprobe
 *   ytdlpTamperedRelease: bool — another executable is served with a
 *                    SHA2-256SUMS rewritten to match it, under the published
 *                    signature
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bootstrapRuntimeFixture(string $scratch, array $options = []): array
{
    bootstrapRuntimeWriteHostFiles($scratch, $options);
    bootstrapRuntimeWriteToolStubs($scratch, $options);
    bootstrapRuntimeWriteMutationStubs($scratch, $options);

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
        'RATEGURU_BOOTSTRAP_PHP_CLI_BIN' => $scratch.'/tools/php8.5',
        'RATEGURU_BOOTSTRAP_PHP_FPM_BIN' => $scratch.'/tools/php-fpm8.5',
        'RATEGURU_BOOTSTRAP_PSQL_BIN' => $scratch.'/tools/psql',
        'RATEGURU_BOOTSTRAP_PG_DUMP_BIN' => $scratch.'/tools/pg_dump',
        'RATEGURU_BOOTSTRAP_PG_RESTORE_BIN' => $scratch.'/tools/pg_restore',
        'RATEGURU_BOOTSTRAP_NGINX_BIN' => $scratch.'/bin/nginx',
        'RATEGURU_BOOTSTRAP_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_BOOTSTRAP_NGINX_PID_FILE' => $scratch.'/fs/run/nginx.pid',
        'RATEGURU_BOOTSTRAP_PHP_FPM_PID_FILE' => $scratch.'/fs/run/php/php8.5-fpm.pid',
        'RATEGURU_BOOTSTRAP_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_BOOTSTRAP_PROC_ROOT' => $scratch.'/fs/proc',
        'RATEGURU_BOOTSTRAP_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_BOOTSTRAP_RCLONE_CONTRACT_FILE' => $scratch.'/fs/external-runtimes.env',
        'RATEGURU_BOOTSTRAP_RCLONE_KEY_FILE' => $scratch.'/fs/rclone-release-signing-key.asc',
        'RATEGURU_BOOTSTRAP_YTDLP_KEY_FILE' => $scratch.'/fs/yt-dlp-release-signing-key.asc',
        'STUB_LOG' => $scratch.'/log',
        'STUB_DPKG_STATE' => $scratch.'/fs/dpkg-installed.txt',
        'STUB_REAL_PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'STUB_STAGED_DIR' => $scratch.'/staged',
        'STUB_TOOLS_DIR' => $scratch.'/tools',
        'STUB_PHP_VERSION' => $options['phpVersion'] ?? phpSecurityFloor(),
        'STUB_PHP_UPGRADE_VERSION' => $options['phpUpgradeVersion'] ?? phpSecurityFloor(),
        'STUB_PHP_MODULES' => $options['phpModules'] ?? implode(' ', bootstrapRuntimeRequiredPhpModules()),
        'STUB_PG_VERSION' => $options['pgVersion'] ?? '18.4',
        'STUB_NGINX_VERSION' => $options['nginxVersion'] ?? nginxSecurityFloor(),
        'STUB_NGINX_UPGRADE_VERSION' => $options['nginxUpgradeVersion'] ?? nginxSecurityFloor(),
        'STUB_NGINX_ACTIVE' => ($options['nginxActive'] ?? true) ? 'true' : 'false',
        'STUB_PROC_ROOT' => $scratch.'/fs/proc',
        'STUB_FPR_PHP' => $options['fprPhp'] ?? bootstrapRuntimePhpPpaFingerprint(),
        'STUB_FPR_PGDG' => $options['fprPgdg'] ?? bootstrapRuntimePgdgFingerprint(),
        'STUB_FPR_RCLONE' => $options['fprRclone'] ?? bootstrapRuntimeRcloneFingerprint(),
        'STUB_RCLONE_ARCHIVE_CONTENT' => bootstrapRuntimeRcloneArchiveContent($options),
        'STUB_RCLONE_SUMS_FILE' => $scratch.'/fs/rclone-sums.txt',
        'STUB_RCLONE_SUMS_SIGNER' => ($options['rcloneBadSignature'] ?? false) ? 'UNKNOWN-KEY' : 'RCLONE-RELEASE',
        'STUB_UNZIP_DIR_NAME' => 'rclone-v'.bootstrapRuntimeFixtureRcloneVersion($options).'-linux-amd64',
        'STUB_EXTRACTED_RCLONE_VERSION' => $options['rcloneExtractedVersion'] ?? 'v'.bootstrapRuntimeFixtureRcloneVersion($options),
        'STUB_FPR_YTDLP' => $options['fprYtDlp'] ?? bootstrapRuntimeYtDlpFingerprint(),
        'STUB_YTDLP_ARTIFACT_CONTENT' => bootstrapRuntimeYtDlpServedArtifact($options),
        'STUB_YTDLP_SUMS_FILE' => $scratch.'/fs/ytdlp-sums.txt',
        'STUB_YTDLP_SIGNED_DIGEST_FILE' => $scratch.'/fs/ytdlp-signed-digest.txt',
        'STUB_FFMPEG_ARCHIVE_CONTENT' => bootstrapRuntimeFfmpegArchiveContent($options),
        'STUB_EXTRACTED_FFMPEG_VERSION' => $options['ffmpegExtractedVersion'] ?? bootstrapRuntimeFixtureFfmpegVersion($options),
        'STUB_YTDLP_SIGNER' => ($options['ytdlpBadSignature'] ?? false) ? 'UNKNOWN-KEY' : 'YTDLP-RELEASE',
    ];

    if ($options['rcloneOmitBinary'] ?? false) {
        $env['STUB_UNZIP_OMIT_BINARY'] = 'true';
    }

    if ($options['ffmpegOmitFfprobe'] ?? false) {
        $env['STUB_TAR_OMIT_FFPROBE'] = 'true';
    }

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

    if ($options['nginxConfigFail'] ?? false) {
        $env['STUB_NGINX_CONFIG_FAIL'] = 'true';
    }

    if ($options['nginxRestartFail'] ?? false) {
        $env['STUB_NGINX_RESTART_FAIL'] = 'true';
    }

    foreach (['phpFpmConfigFail' => 'STUB_PHP_FPM_CONFIG_FAIL', 'phpFpmRestartFail' => 'STUB_PHP_FPM_RESTART_FAIL', 'supervisorRestartFail' => 'STUB_SUPERVISOR_RESTART_FAIL'] as $option => $variable) {
        if ($options[$option] ?? false) {
            $env[$variable] = 'true';
        }
    }

    if (($options['supervisorRunning'] ?? true) === false) {
        $env['STUB_SUPERVISOR_RUNNING'] = 'false';
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
        'rcloneInstalled' => 'absent',
        'ytdlpInstalled' => 'absent',
        'ffmpegInstalled' => 'absent',
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

/** What the systemctl and nginx stubs were asked, one call a line. */
function bootstrapRuntimeServiceLog(string $scratch): string
{
    $path = $scratch.'/log/service.log';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** The exact apt call that upgrades nginx to its security floor. */
function bootstrapRuntimeNginxUpgradeCall(): string
{
    return 'apt-get install -y --only-upgrade --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold -- nginx nginx-core nginx-common';
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
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     os-release — ID=ubuntu VERSION_ID=22.04');
        expect($output)->toContain('PASS     repo:php — provided by a pre-existing apt source');
        expect($output)->toContain('PASS     repo:pgdg — provided by a pre-existing apt source');
        expect($output)->toContain('PASS     php-cli — PHP '.phpSecurityFloor().' (required series 8.5, security floor '.phpSecurityFloor().')');
        expect($output)->toContain('PASS     psql — PostgreSQL 18.4');
        expect($output)->toContain("MISSING: 0\n");
        expect($output)->toContain("CONFLICT: 0\n");

        // The managed rclone runtime reports in its own section, and never
        // as an apt package requirement.
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($output)->toContain("\nEXTERNAL RUNTIME\n");
        expect($output)->toContain(sprintf(
            'PASS     rclone — v%s, %s, %s:%s 0755 (managed external runtime)',
            $rcloneVersion,
            $scratch.'/fs/usr-bin/rclone',
            bootstrapRuntimeCurrentUser(),
            bootstrapRuntimeCurrentGroup(),
        ));
        expect($output)->not->toContain('package:rclone');

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

        expect($exit)->toBe(1, "a clean host cannot satisfy the install-bootstrap-runtime contract:\n{$output}");
        expect($output)->toContain('HOST RUNTIME CONTRACT: NOT SATISFIED');
        expect($output)->toContain('MISSING  repo:php');
        expect($output)->toContain('MISSING  repo:pgdg');
        expect($output)->toContain('MISSING  package:php8.5-fpm — not installed');
        expect($output)->toContain('MISSING  package:postgresql-18 — not installed');
        expect($output)->toContain('MISSING  package:nginx — not installed');
        expect($output)->toContain('MISSING  package:unzip — not installed');

        // --check annotates every unsatisfied item with the intended action.
        expect($output)->toContain('-> apply: install '.$scratch.'/apt/sources.list.d/rateguru-php.sources');
        expect($output)->toContain('-> apply: apt-get install postgresql-18');

        // The absent managed rclone runtime is MISSING in its own section,
        // with the verified-install action — never an apt proposal.
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($output)->toContain('MISSING  rclone — '.$scratch.'/fs/usr-bin/rclone absent');
        expect($output)->toContain('-> apply: install verified rclone v'.$rcloneVersion);
        expect($output)->not->toContain('package:rclone');
        expect($output)->not->toContain('apt-get install rclone');
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
            ['php8.5-fpm', 'postgresql-18', 'unzip'],
        ));
        $env = bootstrapRuntimeFixture($scratch, ['packages' => $installed]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  package:php8.5-fpm');
        expect($output)->toContain('MISSING  package:postgresql-18');
        expect($output)->toContain('MISSING  package:unzip');
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

it('treats a PHP 8.5 release below the security floor as CONFLICT for both SAPIs', function (string $version) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => $version]);
        $floor = phpSecurityFloor();

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1, "PHP {$version} must not satisfy --check:\n{$output}");
        expect($output)->toContain("CONFLICT php-cli — reports PHP {$version}, below the security floor {$floor}");
        expect($output)->toContain("CONFLICT php-fpm — reports PHP {$version}, below the security floor {$floor}");
        expect($output)->toContain('-> apply: upgrade the php8.5 packages (apt-get install --only-upgrade), then restart php8.5-fpm and the supervised PHP workers onto the new binary');
        expect($output)->toContain("CONFLICT: 2\n");
        expect($output)->not->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1, "PHP {$version} must not satisfy --verify:\n{$output}");
        expect($output)->toContain("CONFLICT php-fpm — reports PHP {$version}, below the security floor {$floor}");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'the release before the floor, with its own critical fixes missing' => '8.5.12',
    'the release before that' => '8.5.11',
    'a single-digit patch that sorts after the floor as text' => '8.5.9',
    'the staging host as first observed' => '8.5.8',
    'the first 8.5 release' => '8.5.0',
]);

it('accepts the security floor itself and every later PHP 8.5 release', function (string $version) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => $version]);
        $floor = phpSecurityFloor();

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, "PHP {$version} must satisfy --verify:\n{$output}");
        expect($output)->toContain("PASS     php-cli — PHP {$version} (required series 8.5, security floor {$floor})");
        expect($output)->toContain("PASS     php-fpm — PHP {$version} (required series 8.5, security floor {$floor})");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'the floor' => '8.5.13',
    'the next patch' => '8.5.14',
    'a two-digit patch' => '8.5.20',
    'a three-digit patch' => '8.5.100',
]);

it('upgrades a PHP below the floor during --apply, its packages only, then moves PHP-FPM and the workers onto the new binary', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => '8.5.12']);
        $floor = phpSecurityFloor();
        $packages = implode(' ', array_map(fn (string $c): string => "php8.5-{$c}", ['cli', 'common', 'fpm', 'bcmath', 'curl', 'gd', 'intl', 'mbstring', 'pgsql', 'redis', 'xml', 'zip']));

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("php: upgrading PHP 8.5.12 to the security floor {$floor} or later");
        expect($output)->toContain("php: php8.5-fpm restarted onto the installed binary (PHP {$floor})");
        expect($output)->toContain('php: supervisor program rateguru-staging-queue:rateguru-staging-queue_00 restarted onto the installed binary');
        expect($output)->toContain("PASS     php-fpm — PHP {$floor}");
        expect($output)->toContain('PASS     php-processes — no PHP process runs a replaced binary');
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // A fresh index, then exactly the php8.5 packages, needrestart listing only.
        expect(explode("\n", trim(bootstrapRuntimeAptLog($scratch))))->toBe([
            'apt-get update --allow-releaseinfo-change-label',
            "apt-get install -y --only-upgrade --no-install-recommends -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold -- {$packages}",
        ]);
        expect(trim((string) file_get_contents($scratch.'/log/apt-needrestart-mode')))->toBe('l');

        // PHP-FPM is restarted only after its configuration passes, then the
        // worker still running the replaced binary; nothing else.
        expect(explode("\n", trim(bootstrapRuntimeServiceLog($scratch))))->toBe([
            'systemctl is-active --quiet php8.5-fpm',
            'php-fpm -t',
            'systemctl restart php8.5-fpm',
            'supervisorctl restart rateguru-staging-queue:rateguru-staging-queue_00',
        ]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports PHP processes still on a replaced binary, and --apply restarts them without upgrading again', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRunsReplaced' => true]);

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT php-processes — still running the PHP binary an upgrade replaced: php8.5-fpm rateguru-staging-queue:rateguru-staging-queue_00');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect(bootstrapRuntimeServiceLog($scratch))->toContain("php-fpm -t\nsystemctl restart php8.5-fpm\nsupervisorctl restart rateguru-staging-queue:rateguru-staging-queue_00\n");
        expect($output)->toContain('PASS     php-processes — no PHP process runs a replaced binary');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('finishes a PHP upgrade whose php-fpm -t, PHP-FPM restart or worker restart failed on the next --apply', function (string $failure) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => '8.5.12', $failure => true]);

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);

        unset($env['STUB_PHP_FPM_CONFIG_FAIL'], $env['STUB_PHP_FPM_RESTART_FAIL'], $env['STUB_SUPERVISOR_RESTART_FAIL']);
        file_put_contents($scratch.'/log/apt.log', '');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('processes still run the binary it replaced');
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect($output)->toContain('PASS     php-processes — no PHP process runs a replaced binary');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'php-fpm -t failed' => 'phpFpmConfigFail',
    'the PHP-FPM restart failed' => 'phpFpmRestartFail',
    'the worker restart failed' => 'supervisorRestartFail',
]);

it('fails when the PHP repository does not offer the floor yet, and restarts nothing', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => '8.5.11', 'phpUpgradeVersion' => '8.5.12']);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('PHP is still 8.5.12 (FPM 8.5.12) after the upgrade — its repository does not offer '.phpSecurityFloor().' yet');
        expect(bootstrapRuntimeServiceLog($scratch))->not->toContain('restart');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('never replaces a PHP of another series', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpVersion' => '8.4.27']);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('CONFLICT php-cli — reports PHP 8.4.27, required series is 8.5');
        expect(bootstrapRuntimeAptLog($scratch))->not->toContain('--only-upgrade');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reads no supervised worker when supervisor is not running, and still passes', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['supervisorRunning' => false]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PASS     php-processes — no PHP process runs a replaced binary');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// The nginx security floor — an Ubuntu package revision, met by --apply
// =============================================================================

it('treats an Ubuntu nginx below the security floor as CONFLICT', function (string $version) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxVersion' => $version]);
        $floor = nginxSecurityFloor();

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1, "nginx {$version} must not satisfy --check:\n{$output}");
        expect($output)->toContain("CONFLICT nginx-version — nginx {$version}, below the security floor {$floor}");
        expect($output)->toContain('-> apply: upgrade nginx nginx-core nginx-common (apt-get install --only-upgrade) and restart nginx');
        expect($output)->toContain("CONFLICT: 1\n");

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1, "nginx {$version} must not satisfy --verify:\n{$output}");
        expect($output)->toContain("CONFLICT nginx-version — nginx {$version}, below the security floor {$floor}");
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect(bootstrapRuntimeServiceLog($scratch))->toBe('');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'the revision that fixed the HTTP/2 flaws alone' => '1.18.0-6ubuntu14.23',
    'a revision that sorts after the floor as text' => '1.18.0-6ubuntu14.3',
    'a pre-release of the floor itself' => '1.18.0-6ubuntu14.24~rc1',
    'a revision without a minor part' => '1.18.0-6ubuntu14',
    'the first jammy build' => '1.18.0-6ubuntu1',
]);

it('accepts the nginx security floor and every later revision of the series', function (string $version) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxVersion' => $version]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, "nginx {$version} must satisfy --verify:\n{$output}");
        expect($output)->toContain("PASS     nginx-version — nginx {$version} (security floor ".nginxSecurityFloor().')');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'the floor' => '1.18.0-6ubuntu14.24',
    'the next revision' => '1.18.0-6ubuntu14.25',
    'a three-digit revision' => '1.18.0-6ubuntu14.100',
    'a rebuild of the floor' => '1.18.0-6ubuntu14.24.1',
    'a no-change rebuild' => '1.18.0-6ubuntu14.24build1',
    'a later major revision' => '1.18.0-6ubuntu15.1',
]);

it('refuses an nginx from another source and never replaces it', function (string $version) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxVersion' => $version]);

        // nginx.org numbers its own releases: 1.30.6 sorts above every 1.18.0
        // build and is still exposed, so a version comparison alone would
        // call an exploitable server safe.
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("CONFLICT nginx-version — nginx {$version} is not the Ubuntu jammy series 1.18.0-6ubuntu*");
        expect($output)->toContain('never replaced by --apply');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);

        // Nothing is installed in its place either: the floor reads the nginx
        // package itself, so no server package is required that apt would
        // install by replacing this one.
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect(bootstrapRuntimeServiceLog($scratch))->toBe('');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'nginx.org stable, one release short of the fix' => '1.30.6-1~jammy',
    'the Ubuntu 24.04 package' => '1.24.0-2ubuntu7.21',
]);

it('upgrades an nginx below the floor during --apply, its packages only, then restarts it onto the new binary', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxVersion' => '1.18.0-6ubuntu14.23']);
        $floor = nginxSecurityFloor();

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("nginx: upgrading nginx 1.18.0-6ubuntu14.23 to the security floor {$floor} or later (nginx nginx-core nginx-common)");
        expect($output)->toContain("nginx: restarted onto the installed binary (nginx {$floor})");
        expect($output)->toContain("PASS     nginx-version — nginx {$floor} (security floor {$floor})");
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // A fresh index first, then the nginx packages and nothing else.
        expect(explode("\n", trim(bootstrapRuntimeAptLog($scratch))))->toBe([
            'apt-get update --allow-releaseinfo-change-label',
            bootstrapRuntimeNginxUpgradeCall(),
        ]);

        // The configuration is proven before the restart, and the restart
        // happens once.
        expect(explode("\n", trim(bootstrapRuntimeServiceLog($scratch))))->toBe([
            'systemctl is-active --quiet nginx',
            'nginx -t',
            'systemctl restart nginx',
        ]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('keeps needrestart from restarting other services after the nginx upgrade', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxVersion' => '1.18.0-6ubuntu14.23']);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        // Ubuntu runs needrestart after apt; left to itself it may restart
        // PHP-FPM or PostgreSQL, which share the host with nginx. The upgrade
        // reaches apt with it only listing.
        expect($exit)->toBe(0, $output);
        expect(trim((string) file_get_contents($scratch.'/log/apt-needrestart-mode')))->toBe('l');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('restarts an nginx at the floor that still runs the binary an upgrade replaced, with no apt call', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxRunsReplaced' => true]);
        $floor = nginxSecurityFloor();

        // The package is at the floor, but the master predates it: the
        // exploitable code is still the code that runs.
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain("CONFLICT nginx-version — nginx {$floor} is installed, but the running nginx still executes the binary it replaced");

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect(explode("\n", trim(bootstrapRuntimeServiceLog($scratch))))->toBe([
            'systemctl is-active --quiet nginx',
            'nginx -t',
            'systemctl restart nginx',
        ]);
        expect($output)->toContain("PASS     nginx-version — nginx {$floor} (security floor {$floor})");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('finishes an upgrade whose nginx -t or restart failed on the next --apply, without upgrading again', function (string $failure) {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['nginxVersion' => '1.18.0-6ubuntu14.23', $failure => true]);

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);

        // The package is upgraded; the running master is not.
        unset($env['STUB_NGINX_CONFIG_FAIL'], $env['STUB_NGINX_RESTART_FAIL']);
        file_put_contents($scratch.'/log/apt.log', '');
        file_put_contents($scratch.'/log/service.log', '');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('the running nginx still executes the binary it replaced');
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect(bootstrapRuntimeServiceLog($scratch))->toContain("nginx -t\nsystemctl restart nginx\n");
        expect($output)->toContain('PASS     nginx-version — nginx '.nginxSecurityFloor());
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
})->with([
    'nginx -t failed' => 'nginxConfigFail',
    'the restart failed' => 'nginxRestartFail',
]);

it('never restarts an upgraded nginx whose configuration fails nginx -t', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'nginxVersion' => '1.18.0-6ubuntu14.23',
            'nginxConfigFail' => true,
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('its configuration does not pass nginx -t, so nginx was not restarted');
        expect(bootstrapRuntimeServiceLog($scratch))->not->toContain('restart');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails when the archive does not offer the nginx security floor yet', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'nginxVersion' => '1.18.0-6ubuntu14.22',
            'nginxUpgradeVersion' => '1.18.0-6ubuntu14.23',
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('nginx is still nginx 1.18.0-6ubuntu14.23 after the upgrade — the Ubuntu archive does not offer '.nginxSecurityFloor().' yet');
        expect(bootstrapRuntimeServiceLog($scratch))->not->toContain('restart');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('leaves an upgraded nginx that is not running stopped', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'nginxVersion' => '1.18.0-6ubuntu14.23',
            'nginxActive' => false,
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('nginx is not running, so this run does not restart it');
        expect(trim(bootstrapRuntimeServiceLog($scratch)))->toBe('systemctl is-active --quiet nginx');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('makes no apt call and touches no service when nginx already meets its floor', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('nginx: nginx '.nginxSecurityFloor().' meets the security floor');
        expect(bootstrapRuntimeAptLog($scratch))->toBe('');
        expect(bootstrapRuntimeServiceLog($scratch))->toBe('');
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
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');
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
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

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

        // Two-phase apt: update + bootstrap repository tooling from the
        // existing Ubuntu sources, then update (indexes now include the new
        // repositories) + one deterministic noninteractive install of the
        // remaining required packages. Never an upgrade.
        $bootstrapInstall = 'apt-get install -y --no-install-recommends -- ca-certificates curl gnupg';
        $remaining = array_values(array_diff(
            bootstrapRuntimeRequiredPackages(),
            ['ca-certificates', 'curl', 'gnupg'],
        ));
        $runtimeInstall = 'apt-get install -y --no-install-recommends -- '.implode(' ', $remaining);
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update --allow-releaseinfo-change-label\n{$bootstrapInstall}\napt-get update --allow-releaseinfo-change-label\n{$runtimeInstall}\n",
        );

        // The managed rclone runtime converged through the verified-download
        // path — never through apt (the exact apt log above already proves
        // no apt action mentioned rclone).
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($output)->toContain('rclone v'.$rcloneVersion.' installed at '.$scratch.'/fs/usr-bin/rclone');
        expect(is_file($scratch.'/fs/usr-bin/rclone'))->toBeTrue();

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
        // Derived from the same list the fixture installs, so adding a package to
        // the runtime contract cannot leave a stale number here to fail on.
        $expected = count(bootstrapRuntimeRequiredPackages());

        expect($output)->toContain("packages: all {$expected} required packages already installed");
        expect($output)->toContain('rclone v'.bootstrapRuntimeFixtureRcloneVersion([]).' already installed');
        expect($output)->toContain('yt-dlp '.bootstrapRuntimeFixtureYtDlpVersion([]).' already installed');
        expect($output)->toContain('ffmpeg '.bootstrapRuntimeFixtureFfmpegVersion([]).' already installed');

        expect((string) file_get_contents($scratch.'/log/apt.log'))->toBe('', 'second apply ran apt-get');
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('', 'second apply re-fetched key material or release artifacts');

        // The only gpg activity is the local read-only keyring validation —
        // never a dearmor/import of new material.
        $gpgLog = (string) file_get_contents($scratch.'/log/gpg.log');
        expect($gpgLog)->not->toContain('--dearmor');
        foreach (array_filter(explode("\n", $gpgLog)) as $line) {
            expect($line)->toContain('--show-keys');
        }

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
            "apt-get update --allow-releaseinfo-change-label\napt-get install -y --no-install-recommends -- php8.5-zip\n",
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

        // Dependent package installation never started: only the bootstrap
        // repository tooling phase reached apt.
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update --allow-releaseinfo-change-label\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
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
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update --allow-releaseinfo-change-label\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
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
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update --allow-releaseinfo-change-label\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// --apply bootstrap ordering (clean host without curl/gpg)
// =============================================================================

it('bootstraps a host that genuinely lacks curl and gpg: tooling first, external repositories second', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, ['bootstrapTooling' => 'missing']);

        expect(is_file($scratch.'/tools/curl'))->toBeFalse('fixture must start without curl');
        expect(is_file($scratch.'/tools/gpg'))->toBeFalse('fixture must start without gpg');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "a genuinely minimal host must bootstrap end to end:\n{$output}");
        expect($output)->toContain('bootstrap repository tooling missing: ca-certificates curl gnupg');
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // The tooling appeared exactly the way a real host gets it —
        // through the apt install — and both repositories followed.
        expect(is_file($scratch.'/tools/curl'))->toBeTrue();
        expect(is_file($scratch.'/tools/gpg'))->toBeTrue();
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-php.sources'))->toBeTrue();
        expect(is_file($scratch.'/apt/sources.list.d/rateguru-pgdg.sources'))->toBeTrue();

        $bootstrapInstall = 'apt-get install -y --no-install-recommends -- ca-certificates curl gnupg';
        $remaining = array_values(array_diff(
            bootstrapRuntimeRequiredPackages(),
            ['ca-certificates', 'curl', 'gnupg'],
        ));
        $runtimeInstall = 'apt-get install -y --no-install-recommends -- '.implode(' ', $remaining);
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update --allow-releaseinfo-change-label\n{$bootstrapInstall}\napt-get update --allow-releaseinfo-change-label\n{$runtimeInstall}\n",
        );

        // Both keys were fetched only after the tooling existed, and the
        // rclone release material only after both repositories converged, and
        // yt-dlp's after rclone's.
        $curlLog = (string) file_get_contents($scratch.'/log/curl.log');
        $curlLines = array_values(array_filter(explode("\n", $curlLog)));
        expect(count($curlLines))->toBe(8, "unexpected curl activity:\n{$curlLog}");
        expect($curlLines[0])->toContain('keyserver.ubuntu.com');
        expect($curlLines[1])->toContain('postgresql.org');
        expect($curlLines[2])->toContain('downloads.rclone.org');
        expect($curlLines[3])->toContain('downloads.rclone.org');

        foreach ([4, 5, 6] as $line) {
            expect($curlLines[$line])->toContain('https://github.com/yt-dlp/yt-dlp/releases/download/');
        }

        expect($curlLines[7])->toContain('https://github.com/BtbN/FFmpeg-Builds/releases/download/');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('aborts before any external key fetch when the bootstrap-tooling apt update fails', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, [
            'bootstrapTooling' => 'missing',
            'aptUpdateFail' => true,
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get update failed');
        expect(bootstrapRuntimeAptLog($scratch))->toBe("apt-get update --allow-releaseinfo-change-label\n");
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('a key fetch happened before the tooling existed');
        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('aborts before any external key fetch when the bootstrap-tooling apt install fails', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch, [
            'bootstrapTooling' => 'missing',
            'aptInstallFail' => true,
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: apt-get install failed for the bootstrap repository tooling');
        expect(bootstrapRuntimeAptLog($scratch))->toBe(
            "apt-get update --allow-releaseinfo-change-label\napt-get install -y --no-install-recommends -- ca-certificates curl gnupg\n",
        );
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('a key fetch happened before the tooling existed');
        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when repository work would need curl/gpg that dpkg claims installed but the PATH does not provide', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // dpkg says everything (including the bootstrap tooling) is
        // installed, so the tooling phase is skipped — but the tools are
        // genuinely absent. The fetch must refuse rather than pretend.
        $env = bootstrapRuntimeFixture($scratch, [
            'bootstrapTooling' => 'missing',
            'phpRepo' => 'absent',
            'pgdgRepo' => 'absent',
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('curl unavailable — bootstrap repository tooling must be installed before any external repository work');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse();
        expect(glob($scratch.'/apt/sources.list.d/rateguru-*') ?: [])->toBe([]);
        expect(glob($scratch.'/keyrings/*') ?: [])->toBe([]);
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
        expect(bootstrapRuntimeAptLog($scratch))->toBe("apt-get update --allow-releaseinfo-change-label\n");
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
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');
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
        $env = bootstrapRuntimeFixture($scratch);
        unlink($scratch.'/tools/unzip');
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  tool:unzip — not found (package: unzip)');
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

it('fails --verify on any drift of an installer-owned .sources file and prints the reconciliation action in --check', function () {
    foreach ([
        'wrong URI' => ['https://ppa.launchpadcontent.net/ondrej/php/ubuntu', 'https://evil.example/ondrej/php/ubuntu'],
        'wrong suite' => ['Suites: jammy', 'Suites: noble'],
        'wrong Signed-By' => ['Signed-By: ', 'Signed-By: /usr/share/keyrings/other-'],
    ] as $case => [$search, $replace]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);

            $sourcesFile = $scratch.'/apt/sources.list.d/rateguru-php.sources';
            file_put_contents($sourcesFile, str_replace($search, $replace, (string) file_get_contents($sourcesFile)));

            [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
            expect($exit)->toBe(1, "{$case} must fail --verify:\n{$output}");
            expect($output)->toContain('CONFLICT repo:php — installer-owned');
            expect($output)->toContain('drifted');
            expect($output)->toContain('PASS     repo:pgdg');

            [, $checkOutput] = bootstrapRuntimeRun(['--check'], $env);
            expect($checkOutput)->toContain('-> apply: re-run --apply to reconcile the php repository transactionally');
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('fails --verify for a keyring that is garbage, carries a wrong fingerprint, or bundles an extra key', function () {
    // Non-empty garbage that is not an OpenPGP keyring at all.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);
        file_put_contents($scratch.'/keyrings/rateguru-php.gpg', "GARBAGE-NOT-A-KEYRING\n");

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT repo:php — keyring');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // A structurally valid key whose fingerprint is not the pin.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'phpRepo' => 'installer-owned',
            'pgdgRepo' => 'installer-owned',
            'fprPhp' => str_repeat('DEADBEEF', 5),
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT repo:php — keyring');
        expect($output)->toContain('does not hold exactly the pinned key '.bootstrapRuntimePhpPpaFingerprint());
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // The pinned key plus an extra primary key in the same keyring.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'phpRepo' => 'installer-owned',
            'pgdgRepo' => 'installer-owned',
            'gpgExtraKey' => true,
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT repo:php — keyring');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('validates installer-owned repositories without any network, apt or key-fetch activity', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['phpRepo' => 'installer-owned', 'pgdgRepo' => 'installer-owned']);
        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('keyring matches pinned '.bootstrapRuntimePhpPpaFingerprint());
        expect($output)->toContain('keyring matches pinned '.bootstrapRuntimePgdgFingerprint());

        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('--verify invoked apt-get');
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('--verify fetched key material');

        // gpg ran, but only as the local read-only keyring inspection.
        $gpgLog = (string) file_get_contents($scratch.'/log/gpg.log');
        expect($gpgLog)->not->toBe('');
        expect($gpgLog)->not->toContain('--dearmor');
        foreach (array_filter(explode("\n", $gpgLog)) as $line) {
            expect($line)->toContain('--show-keys');
        }
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
// Managed external runtime: rclone
// =============================================================================

it('passes the managed rclone check when the exact standalone binary is present without any apt rclone package', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // The corrected real-staging model: no rclone package in dpkg (the
        // required-package fixture set no longer contains one), a compliant
        // standalone binary at the canonical path.
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, "a compliant standalone rclone must satisfy the contract:\n{$output}");
        expect($output)->toContain('PASS     rclone — v'.bootstrapRuntimeFixtureRcloneVersion([]));
        expect($output)->not->toContain('package:rclone');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports the real staging drift — standalone v1.74.4 — as version reconciliation, never as an apt action', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'v1.74.4']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — v1.74.4 installed, required v'.$rcloneVersion);
        expect($output)->toContain('-> apply: replace with verified rclone v'.$rcloneVersion);
        expect($output)->not->toContain('apt-get install rclone');
        expect($output)->not->toContain('package:rclone');

        // The version drift is the only unsatisfied item on this host.
        expect($output)->toContain("MISSING: 0\n");
        expect($output)->toContain("CONFLICT: 1\n");
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('treats the old Ubuntu-package rclone 1.53.3 as version drift even when dpkg lists the package', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // An operator-installed apt rclone: the dpkg database carries the
        // package (harmless — it is no longer a requirement) and the binary
        // reports the ancient jammy version.
        $env = bootstrapRuntimeFixture($scratch, [
            'packages' => array_merge(bootstrapRuntimeRequiredPackages(), ['rclone']),
            'rcloneInstalled' => 'v1.53.3',
        ]);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — v1.53.3 installed, required v'.bootstrapRuntimeFixtureRcloneVersion([]));
        expect($output)->not->toContain('package:rclone');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('flags wrong ownership, wrong mode, a foreign path and a version-less binary as CONFLICT', function () {
    // Wrong owner/group: the contract demands root:root, the fixture binary
    // belongs to the test user.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneOwner' => 'root', 'rcloneGroup' => 'root']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain(sprintf(
            'CONFLICT rclone — owned by %s:%s, required root:root',
            bootstrapRuntimeCurrentUser(),
            bootstrapRuntimeCurrentGroup(),
        ));
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // Wrong mode.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneFileMode' => '0700']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — mode 700, required 0755');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // Foreign path: nothing at the canonical path, but a PATH-resolvable
    // rclone elsewhere.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'absent']);
        bootstrapRuntimeWriteStub($scratch.'/tools/rclone', "#!/bin/bash\nexit 0\n");
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain(sprintf(
            'CONFLICT rclone — found at %s, but the managed binary path is %s',
            $scratch.'/tools/rclone',
            $scratch.'/fs/usr-bin/rclone',
        ));
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }

    // Malformed version output.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'broken']);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — '.$scratch.'/fs/usr-bin/rclone cannot report a version');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('upgrades the real staging v1.74.4 atomically through signed, checksummed, version-verified material', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['rcloneInstalled' => 'v1.74.4']);
        $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);
        $confBefore = (string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "the staged upgrade must converge and verify:\n{$output}");
        expect($output)->toContain('rclone requires reconciliation (state: version:v1.74.4)');
        expect($output)->toContain('rclone: signature and checksum verified — extracting');
        expect($output)->toContain('rclone v'.$rcloneVersion.' installed at '.$scratch.'/fs/usr-bin/rclone');
        expect($output)->toContain('PASS     rclone — v'.$rcloneVersion);
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // The binary was replaced (it is now the extracted stub) with the
        // contract mode, and no staged temp file was left beside it.
        expect((string) file_get_contents($scratch.'/fs/usr-bin/rclone'))->toContain('STUB_EXTRACTED_RCLONE_VERSION');
        expect(fileperms($scratch.'/fs/usr-bin/rclone') & 0o777)->toBe(0o755);
        expect(glob($scratch.'/fs/usr-bin/rclone.*') ?: [])->toBe([]);

        // Exactly two HTTPS downloads from the official origin: the exact
        // versioned artifact and its SHA256SUMS. No apt activity at all.
        $curlLines = array_values(array_filter(explode("\n", (string) file_get_contents($scratch.'/log/curl.log'))));
        expect(count($curlLines))->toBe(2, 'unexpected download activity');
        expect($curlLines[0])->toContain("https://downloads.rclone.org/v{$rcloneVersion}/rclone-v{$rcloneVersion}-linux-amd64.zip");
        expect($curlLines[1])->toContain("https://downloads.rclone.org/v{$rcloneVersion}/SHA256SUMS");
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('the rclone upgrade must never touch apt');

        // The operator's rclone.conf is byte-identical, and the managed
        // binary was only ever asked for --version — never `config`, never
        // `selfupdate`.
        expect((string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf'))->toBe($confBefore);
        foreach (array_filter(explode("\n", (string) file_get_contents($scratch.'/log/rclone.log'))) as $line) {
            expect($line)->toBe('rclone --version');
        }

        // A second apply is a no-op: no downloads, no replacement.
        file_put_contents($scratch.'/log/curl.log', '');
        $binaryBefore = (string) file_get_contents($scratch.'/fs/usr-bin/rclone');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('rclone v'.$rcloneVersion.' already installed');
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('');
        expect((string) file_get_contents($scratch.'/fs/usr-bin/rclone'))->toBe($binaryBefore);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('leaves the working rclone untouched when the download, signature, checksum, archive or extracted version fails', function () {
    foreach ([
        'download failure' => ['curlFailPattern' => 'downloads.rclone.org'],
        'bad SHA256SUMS signature' => ['rcloneBadSignature' => true],
        'wrong signing-key fingerprint' => ['fprRclone' => str_repeat('DEADBEEF', 5)],
        'wrong artifact checksum' => ['rcloneSumsDigest' => hash('sha256', 'not-the-artifact')],
        'archive missing rclone' => ['rcloneOmitBinary' => true],
        'extracted binary reports wrong version' => ['rcloneExtractedVersion' => 'v1.74.9'],
    ] as $case => $breakage) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, array_merge(['rcloneInstalled' => 'v1.74.4'], $breakage));
            $binaryBefore = (string) file_get_contents($scratch.'/fs/usr-bin/rclone');

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, "{$case} must abort the apply:\n{$output}");
            expect($output)->toContain('ERROR: rclone:');

            expect((string) file_get_contents($scratch.'/fs/usr-bin/rclone'))
                ->toBe($binaryBefore, "{$case}: the working binary was modified");
            expect(glob($scratch.'/fs/usr-bin/rclone.*') ?: [])
                ->toBe([], "{$case}: a staged candidate was left behind");
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('names the failure precisely for each broken verification step', function () {
    $rcloneVersion = bootstrapRuntimeFixtureRcloneVersion([]);

    foreach ([
        ['rcloneBadSignature' => true, 'SHA256SUMS signature verification failed'],
        ['fprRclone' => str_repeat('DEADBEEF', 5), 'does not match the pinned fingerprint '.bootstrapRuntimeRcloneFingerprint()],
        ['rcloneSumsDigest' => hash('sha256', 'not-the-artifact'), 'checksum mismatch'],
        ['rcloneOmitBinary' => true, 'does not contain the rclone binary'],
        ['rcloneExtractedVersion' => 'v1.74.9', "extracted binary reports v1.74.9, expected v{$rcloneVersion}"],
    ] as $case) {
        $message = array_pop($case);
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, array_merge(['rcloneInstalled' => 'absent'], $case));
            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1);
            expect($output)->toContain($message);
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('keeps the operator rclone.conf byte-identical across a clean-host apply and never invokes rclone beyond --version', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeCleanHostFixture($scratch);
        $confBefore = (string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf');

        [$exit] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(0);

        expect((string) file_get_contents($scratch.'/fs/root-config-rclone/rclone.conf'))->toBe($confBefore);

        $rcloneLog = (string) file_get_contents($scratch.'/log/rclone.log');
        expect($rcloneLog)->not->toBe('', 'the verified install must have version-probed the binary');
        foreach (array_filter(explode("\n", $rcloneLog)) as $line) {
            expect($line)->toBe('rclone --version');
        }
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when the external-runtimes contract is unreadable or mangled', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        file_put_contents($scratch.'/fs/external-runtimes.env', "RCLONE_VERSION=latest\n");

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT rclone — external-runtimes contract unreadable or incomplete');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: rclone: external-runtimes contract unreadable or incomplete');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

// =============================================================================
// Managed external runtime: yt-dlp
// =============================================================================

it('passes the managed yt-dlp check when the pinned standalone executable is present', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, "a compliant yt-dlp must satisfy the contract:\n{$output}");
        expect($output)->toContain('PASS     yt-dlp — '.bootstrapRuntimeFixtureYtDlpVersion([]).', '.$scratch.'/fs/usr-local-bin/yt-dlp');
        expect($output)->not->toContain('package:yt-dlp');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports a host prepared before yt-dlp as one missing runtime, which --apply installs through signed, checksummed, version-verified material', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // A live host: everything else compliant, yt-dlp never installed.
        $env = bootstrapRuntimeFixture($scratch, ['ytdlpInstalled' => 'absent']);
        $version = bootstrapRuntimeFixtureYtDlpVersion([]);
        $binary = $scratch.'/fs/usr-local-bin/yt-dlp';

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("MISSING  yt-dlp — {$binary} absent");
        expect($output)->toContain("-> apply: install verified yt-dlp {$version}");
        expect($output)->toContain("MISSING: 1\n");
        expect($output)->toContain("CONFLICT: 0\n");

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "the install must converge and verify:\n{$output}");
        expect($output)->toContain('yt-dlp requires reconciliation (state: absent)');
        expect($output)->toContain('yt-dlp: signature and checksum verified');
        expect($output)->toContain("yt-dlp {$version} installed at {$binary}");
        expect($output)->toContain("PASS     yt-dlp — {$version}");
        expect($output)->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // The installed file is exactly the verified download, with the
        // contract mode, and no staged copy was left beside it.
        expect((string) file_get_contents($binary))->toBe(bootstrapRuntimeYtDlpArtifactContent([]));
        expect(fileperms($binary) & 0o777)->toBe(0o755);
        expect(glob($binary.'.*') ?: [])->toBe([]);

        // Exactly three HTTPS downloads from the official release, and no apt
        // activity at all.
        $curlLines = array_values(array_filter(explode("\n", (string) file_get_contents($scratch.'/log/curl.log'))));
        $release = "https://github.com/yt-dlp/yt-dlp/releases/download/{$version}";
        expect(count($curlLines))->toBe(3, 'unexpected download activity');
        expect($curlLines[0])->toEndWith("-- {$release}/yt-dlp_linux");
        expect($curlLines[1])->toEndWith("-- {$release}/SHA2-256SUMS");
        expect($curlLines[2])->toEndWith("-- {$release}/SHA2-256SUMS.sig");
        expect(is_file($scratch.'/log/apt.log'))->toBeFalse('installing yt-dlp must never touch apt');

        // The binary was only ever asked for its version — never to update itself.
        foreach (array_filter(explode("\n", (string) file_get_contents($scratch.'/log/yt-dlp.log'))) as $line) {
            expect($line)->toBe('yt-dlp --version');
        }

        // A second apply downloads nothing and replaces nothing.
        file_put_contents($scratch.'/log/curl.log', '');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("yt-dlp {$version} already installed");
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('');
        expect((string) file_get_contents($binary))->toBe(bootstrapRuntimeYtDlpArtifactContent([]));
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('replaces an outdated yt-dlp once the pin moves', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, ['ytdlpInstalled' => '2026.10.01']);
        $version = bootstrapRuntimeFixtureYtDlpVersion([]);

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("CONFLICT yt-dlp — 2026.10.01 installed, required {$version}");
        expect($output)->toContain("-> apply: replace with verified yt-dlp {$version}");

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('yt-dlp requires reconciliation (state: version:2026.10.01)');
        expect((string) file_get_contents($scratch.'/fs/usr-local-bin/yt-dlp'))->toBe(bootstrapRuntimeYtDlpArtifactContent([]));
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('flags a wrongly owned, wrongly permissioned, misplaced or version-less yt-dlp as CONFLICT', function () {
    $binary = fn (string $scratch): string => $scratch.'/fs/usr-local-bin/yt-dlp';
    // Never the account running the tests, so the case holds under root too.
    $otherOwner = bootstrapRuntimeCurrentUser() === 'root' ? 'nobody' : 'root';

    foreach ([
        'owner' => [['ytdlpOwner' => $otherOwner, 'ytdlpGroup' => $otherOwner], fn (string $scratch): string => sprintf(
            'CONFLICT yt-dlp — owned by %s:%s, required %s:%s',
            bootstrapRuntimeCurrentUser(),
            bootstrapRuntimeCurrentGroup(),
            $otherOwner,
            $otherOwner,
        )],
        'mode' => [['ytdlpFileMode' => '0700'], fn (): string => 'CONFLICT yt-dlp — mode 700, required 0755'],
        'version-less' => [['ytdlpInstalled' => 'broken'], fn (string $scratch): string => 'CONFLICT yt-dlp — '.$binary($scratch).' cannot report a version'],
        'misplaced' => [['ytdlpInstalled' => 'absent'], fn (string $scratch): string => sprintf(
            'CONFLICT yt-dlp — found at %s, but the managed binary path is %s',
            $scratch.'/tools/yt-dlp',
            $binary($scratch),
        )],
    ] as $case => [$options, $expected]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, $options);

            if ($case === 'misplaced') {
                bootstrapRuntimeWriteStub($scratch.'/tools/yt-dlp', "#!/bin/bash\nexit 0\n");
            }

            [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

            expect($exit)->toBe(1, $case);
            expect($output)->toContain($expected($scratch));
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('leaves the working yt-dlp untouched, naming the step, when the download, signature, key, checksum or reported version fails', function () {
    $version = bootstrapRuntimeFixtureYtDlpVersion([]);

    foreach ([
        'download failure' => [['curlFailPattern' => 'github.com/yt-dlp'], 'cannot download'],
        'signature by another key' => [['ytdlpBadSignature' => true], 'SHA2-256SUMS signature verification failed'],
        'executable and sums replaced under the published signature' => [['ytdlpTamperedRelease' => true], 'SHA2-256SUMS signature verification failed'],
        'signing key off the pin' => [['fprYtDlp' => str_repeat('DEADBEEF', 5)], 'does not match the pinned fingerprint '.bootstrapRuntimeYtDlpFingerprint()],
        'checksum mismatch' => [['ytdlpSumsDigest' => hash('sha256', 'not-the-executable')], 'checksum mismatch for yt-dlp_linux'],
        'download reports another version' => [['ytdlpDownloadedVersion' => '2026.01.01'], "downloaded executable reports 2026.01.01, expected {$version}"],
    ] as $case => [$breakage, $message]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, array_merge(['ytdlpInstalled' => '2026.10.01'], $breakage));
            $binary = $scratch.'/fs/usr-local-bin/yt-dlp';
            $binaryBefore = (string) file_get_contents($binary);

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, "{$case} must abort the apply:\n{$output}");
            expect($output)->toContain('ERROR: yt-dlp:')->toContain($message);
            expect((string) file_get_contents($binary))->toBe($binaryBefore, "{$case}: the working binary was modified");
            expect(glob($binary.'.*') ?: [])->toBe([], "{$case}: a staged candidate was left behind");
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('refuses to install rclone or yt-dlp over a directory standing where the binary belongs', function () {
    foreach ([
        'rclone' => [['rcloneInstalled' => 'absent'], '/fs/usr-bin/rclone'],
        'yt-dlp' => [['ytdlpInstalled' => 'absent'], '/fs/usr-local-bin/yt-dlp'],
        'ffmpeg' => [['ffmpegInstalled' => 'absent'], '/fs/usr-local-bin/ffmpeg'],
    ] as $tool => [$options, $relative]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, $options);
            // A link to a directory counts the same: mv would follow it.
            expect(mkdir($scratch.'/fs/elsewhere', 0o755))->toBeTrue();
            symlink($scratch.'/fs/elsewhere', $scratch.$relative);

            [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

            expect($exit)->toBe(1, $tool);
            expect($output)->toContain("CONFLICT {$tool} — {$scratch}{$relative} is a directory, not the binary")
                ->toContain('-> apply: move it aside by hand, then re-run --apply');

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, $tool);
            expect($output)->toContain("ERROR: {$tool}: {$scratch}{$relative} is a directory — refusing to install over it");
            // Refused before any download, and nothing was dropped inside.
            expect(is_file($scratch.'/log/curl.log'))->toBeFalse("{$tool}: downloaded before refusing");
            expect(glob($scratch.'/fs/elsewhere/*') ?: [])->toBe([], "{$tool}: a file was dropped inside the directory");
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

// =============================================================================
// Managed external runtime: ffmpeg
// =============================================================================

it('passes the managed ffmpeg check when the pinned build is in place', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(0, "a compliant ffmpeg must satisfy the contract:\n{$output}");
        expect($output)->toContain('PASS     ffmpeg — '.bootstrapRuntimeFixtureFfmpegVersion([]).', ffmpeg and ffprobe in '.$scratch.'/fs/usr-local-bin')
            ->not->toContain('package:ffmpeg');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('replaces Jammy\'s ffmpeg on a live host with the pinned build, through a digest-checked download, leaving the package alone', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // A host prepared before the managed build: Jammy's ffmpeg on PATH,
        // nothing in the managed directory.
        $env = bootstrapRuntimeFixture($scratch, ['ffmpegInstalled' => 'absent']);
        bootstrapRuntimeWriteStub($scratch.'/tools/ffmpeg', "#!/bin/bash\nprintf 'ffmpeg version 4.4.2-0ubuntu0.22.04.1\\n'\n");
        $jammy = (string) file_get_contents($scratch.'/tools/ffmpeg');
        $version = bootstrapRuntimeFixtureFfmpegVersion([]);
        $dir = $scratch.'/fs/usr-local-bin';

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("CONFLICT ffmpeg — found at {$scratch}/tools/ffmpeg, but the managed binaries belong in {$dir}")
            ->toContain("-> apply: install verified ffmpeg {$version} in {$dir} (the unexpected copy is never touched)");

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, "the install must converge and verify:\n{$output}");
        expect($output)->toContain('ffmpeg requires reconciliation (state: foreign:'.$scratch.'/tools/ffmpeg)')
            ->toContain('ffmpeg: checksum matches the pinned digest — extracting ffmpeg and ffprobe')
            ->toContain("ffmpeg {$version} installed in {$dir} (ffmpeg, ffprobe;")
            ->toContain("PASS     ffmpeg — {$version}")
            ->toContain('HOST RUNTIME CONTRACT: SATISFIED');

        // Both binaries are exactly what was extracted, with the contract mode,
        // no staged copy beside them, and Jammy's copy untouched.
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            expect((string) file_get_contents("{$dir}/{$tool}"))->toContain('STUB_EXTRACTED_FFMPEG_VERSION')->toContain("'{$tool}'")
                ->and(fileperms("{$dir}/{$tool}") & 0o777)->toBe(0o755);
        }
        expect(glob($dir.'/ff*.*') ?: [])->toBe([])
            ->and((string) file_get_contents($scratch.'/tools/ffmpeg'))->toBe($jammy);

        // One HTTPS download of the exact pinned archive; tar asked for the two
        // members only; no apt activity.
        $curlLines = array_values(array_filter(explode("\n", (string) file_get_contents($scratch.'/log/curl.log'))));
        expect($curlLines)->toHaveCount(1)
            ->and($curlLines[0])->toEndWith('-- https://github.com/BtbN/FFmpeg-Builds/releases/download/autobuild-2026-10-31-12-59/ffmpeg-n8.1.3-27-g8dca1586f4-linux64-gpl-8.1.tar.xz')
            ->and((string) file_get_contents($scratch.'/log/tar.log'))->toContain('ffmpeg-n8.1.3-27-g8dca1586f4-linux64-gpl-8.1/bin/ffmpeg ffmpeg-n8.1.3-27-g8dca1586f4-linux64-gpl-8.1/bin/ffprobe')
            ->and(is_file($scratch.'/log/apt.log'))->toBeFalse('installing ffmpeg must never touch apt');

        // The binaries were only ever asked for their version.
        foreach (array_filter(explode("\n", (string) file_get_contents($scratch.'/log/ffmpeg.log'))) as $line) {
            expect($line)->toMatch('/^ff(mpeg|probe) -hide_banner -version$/');
        }

        // A second apply downloads nothing.
        file_put_contents($scratch.'/log/curl.log', '');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain("ffmpeg {$version} already installed in {$dir} — nothing to do");
        expect((string) file_get_contents($scratch.'/log/curl.log'))->toBe('');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('reports an outdated, half-installed, wrongly owned, wrongly permissioned or version-less ffmpeg', function () {
    $dir = fn (string $scratch): string => $scratch.'/fs/usr-local-bin';
    $version = bootstrapRuntimeFixtureFfmpegVersion([]);
    $otherOwner = bootstrapRuntimeCurrentUser() === 'root' ? 'nobody' : 'root';

    foreach ([
        'absent' => [['ffmpegInstalled' => 'absent'], fn (string $scratch): string => 'MISSING  ffmpeg — '.$dir($scratch).'/ffmpeg and ffprobe absent'],
        'outdated' => [['ffmpegInstalled' => '7.1.1'], fn (string $scratch): string => 'CONFLICT ffmpeg — '.$dir($scratch)."/ffmpeg reports 7.1.1, required {$version}"],
        'half installed' => [['ffmpegInstalled' => 'ffprobe-missing'], fn (string $scratch): string => 'MISSING  ffmpeg — '.$dir($scratch).'/ffprobe absent'],
        'owner' => [['ffmpegOwner' => $otherOwner, 'ffmpegGroup' => $otherOwner], fn (string $scratch): string => sprintf('CONFLICT ffmpeg — %s/ffmpeg owned by %s:%s, required %s:%s', $dir($scratch), bootstrapRuntimeCurrentUser(), bootstrapRuntimeCurrentGroup(), $otherOwner, $otherOwner)],
        'mode' => [['ffmpegFileMode' => '0700'], fn (string $scratch): string => 'CONFLICT ffmpeg — '.$dir($scratch).'/ffmpeg mode 700, required 0755'],
        'version-less' => [['ffmpegInstalled' => 'broken'], fn (string $scratch): string => 'CONFLICT ffmpeg — '.$dir($scratch).'/ffmpeg cannot report a version'],
    ] as $case => [$options, $expected]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, $options);
            [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

            expect($exit)->toBe(1, $case);
            expect($output)->toContain($expected($scratch));
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('leaves the installed ffmpeg untouched, naming the step, when the download, digest, archive or extracted version fails', function () {
    $version = bootstrapRuntimeFixtureFfmpegVersion([]);

    foreach ([
        'download failure' => [['curlFailPattern' => 'BtbN/FFmpeg-Builds'], 'cannot download'],
        'archive is not the pinned one' => [['ffmpegSha256' => hash('sha256', 'another archive')], 'checksum mismatch for ffmpeg-n8.1.3-27-g8dca1586f4-linux64-gpl-8.1.tar.xz'],
        'archive without ffprobe' => [['ffmpegOmitFfprobe' => true], 'does not contain ffmpeg-n8.1.3-27-g8dca1586f4-linux64-gpl-8.1/bin/ffmpeg and'],
        'extracted binaries report another version' => [['ffmpegExtractedVersion' => 'n8.1.2-5-gdeadbeef-20260930'], "extracted ffmpeg reports n8.1.2-5-gdeadbeef-20260930, expected {$version}"],
    ] as $case => [$breakage, $message]) {
        $scratch = bootstrapRuntimeScratchDir();

        try {
            $env = bootstrapRuntimeFixture($scratch, array_merge(['ffmpegInstalled' => '7.1.1'], $breakage));
            $dir = $scratch.'/fs/usr-local-bin';
            $before = [(string) file_get_contents($dir.'/ffmpeg'), (string) file_get_contents($dir.'/ffprobe')];

            [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

            expect($exit)->toBe(1, "{$case} must abort the apply:\n{$output}");
            expect($output)->toContain('ERROR: ffmpeg:')->toContain($message);
            expect([(string) file_get_contents($dir.'/ffmpeg'), (string) file_get_contents($dir.'/ffprobe')])->toBe($before, "{$case}: an installed binary was modified");
            expect(glob($dir.'/ff*.*') ?: [])->toBe([], "{$case}: a staged candidate was left behind");
        } finally {
            bootstrapRuntimeCleanup($scratch);
        }
    }
});

it('refuses a directory at ffprobe\'s path even while ffmpeg itself is only outdated', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        // Both binaries outdated, then ffprobe's path taken by a directory: the
        // directory must decide, not ffmpeg's version drift.
        $env = bootstrapRuntimeFixture($scratch, ['ffmpegInstalled' => '7.1.1']);
        $dir = $scratch.'/fs/usr-local-bin';
        unlink($dir.'/ffprobe');
        expect(mkdir($dir.'/ffprobe', 0o755))->toBeTrue();
        $ffmpegBefore = (string) file_get_contents($dir.'/ffmpeg');

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("CONFLICT ffmpeg — {$dir}/ffprobe is a directory, not the binary");

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain("ERROR: ffmpeg: {$dir}/ffprobe is a directory — refusing to install over it");
        expect(is_file($scratch.'/log/curl.log'))->toBeFalse('downloaded before refusing')
            ->and(glob($dir.'/ffprobe/*') ?: [])->toBe([], 'a file was dropped inside the directory')
            ->and((string) file_get_contents($dir.'/ffmpeg'))->toBe($ffmpegBefore);
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when the ffmpeg rows of the contract are missing or mangled', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        $contract = $scratch.'/fs/external-runtimes.env';
        file_put_contents($contract, (string) preg_replace('/^FFMPEG_SHA256=.*$/m', 'FFMPEG_SHA256=latest', (string) file_get_contents($contract)));

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT ffmpeg — external-runtimes contract unreadable or incomplete');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: ffmpeg: external-runtimes contract unreadable or incomplete');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('fails closed when the yt-dlp rows of the contract are missing or mangled', function () {
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch);
        $contract = $scratch.'/fs/external-runtimes.env';
        file_put_contents($contract, (string) preg_replace('/^YTDLP_VERSION=.*$/m', 'YTDLP_VERSION=latest', (string) file_get_contents($contract)));

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT yt-dlp — external-runtimes contract unreadable or incomplete');

        [$exit, $output] = bootstrapRuntimeRun(['--apply'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('ERROR: yt-dlp: external-runtimes contract unreadable or incomplete');
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

it('keeps the PHP security floor inside the series and never below the release that fixes CVE-2026-129289', function () {
    preg_match('/^PHP_SERIES="([^"]+)"$/m', bootstrapRuntimeSource(), $series);
    $floor = phpSecurityFloor();

    expect($floor)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($floor)->toStartWith($series[1].'.');

    // 8.5.13 (php.net, 2026-11-19) is the first 8.5 release with the fixes for
    // request smuggling through PHP-FPM (CVE-2026-129289) and remote code
    // execution in session handling, after 8.5.12 fixed CVE-2026-109618 and
    // CVE-2026-109619. The floor moves up with later security releases; going
    // back below it would let a host run an exploitable PHP while every
    // verification passes.
    expect(version_compare($floor, '8.5.13', '>='))->toBeTrue("the PHP security floor {$floor} is below 8.5.13");
});

it('keeps the nginx security floor inside the jammy series and never below the revision that fixes CVE-2026-123007', function () {
    preg_match('/^NGINX_PACKAGE_SERIES="([^"]+)"$/m', bootstrapRuntimeSource(), $series);
    $floor = nginxSecurityFloor();

    expect($series[1] ?? null)->toBe('1.18.0-6ubuntu');
    expect($floor)->toStartWith($series[1]);
    expect($floor)->toMatch('/^1\.18\.0-6ubuntu\d+\.\d+$/');

    // 1.18.0-6ubuntu14.24 (USN-9182-1, 2026-11-20) is the first jammy build
    // with every fix nginx 1.30.7 brought for the flaws these hosts are
    // exposed to — the critical HTTP/2 one and the TLS 1.3 loop. The floor
    // moves up with later updates; below it, an exploitable server would
    // pass every verification.
    preg_match('/(\d+)\.(\d+)$/', $floor, $revision);
    expect([(int) $revision[1], (int) $revision[2]] >= [14, 24])->toBeTrue("the nginx security floor {$floor} is below 1.18.0-6ubuntu14.24");
});

/**
 * The shared build action, parsed.
 */
function buildActionDefinition(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/build-rateguru/action.yml')));
}

/**
 * The setup-php extension list the shared build action declares, read from
 * the step that actually configures PHP rather than by first-match regex: any
 * other `extensions:` key in the file — a later step, a different action, a
 * commented example — must never be able to stand in for it.
 *
 * @return list<string>
 */
function buildActionPhpExtensions(array $action): array
{
    $step = collect(data_get($action, 'runs.steps'))
        ->first(fn (array $step): bool => data_get($step, 'name') === 'Setup PHP');

    $extensions = data_get($step, 'with.extensions');

    if (! is_string($extensions) || trim($extensions) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $extensions))));
}

it('covers every PHP extension the deploy workflow and composer.json require', function () {
    $installer = bootstrapRuntimeSource();

    preg_match('/^REQUIRED_PHP_MODULES=\(([^)]+)\)$/m', $installer, $modulesMatch);
    expect($modulesMatch[1] ?? null)->not->toBeNull();
    $verifiedModules = preg_split('/\s+/', trim($modulesMatch[1]));

    // The deployment build's PHP toolchain is declared once, in the shared
    // build action both deployment workflows call.
    $workflowExtensions = buildActionPhpExtensions(buildActionDefinition());
    expect($workflowExtensions)->not->toBeEmpty('the shared build action no longer declares setup-php extensions');

    foreach ($workflowExtensions as $extension) {
        expect(in_array($extension, $verifiedModules, true))
            ->toBeTrue("deploy build extension {$extension} is not verified by the installer");
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

it('reads the build action PHP extensions from the Setup PHP step, never from another extensions key', function () {
    // A first-match regex over the file would have taken this decoy — a
    // different step's unrelated `extensions:` — and then compared the wrong
    // list against the installer's verified modules, passing or failing for a
    // reason that has nothing to do with the PHP runtime contract.
    $decoy = Yaml::parse(<<<'YAML'
        runs:
          using: composite
          steps:
            - name: Setup Node
              with:
                extensions: not-a-php-extension
            - name: Setup PHP
              with:
                extensions: bcmath, curl
            - name: Later step
              with:
                extensions: also-not-a-php-extension
        YAML);

    expect(buildActionPhpExtensions($decoy))->toBe(['bcmath', 'curl']);

    // An action with no Setup PHP step resolves to nothing rather than to
    // some other step's value, so the caller's non-empty assertion catches it.
    expect(buildActionPhpExtensions(['runs' => ['steps' => [['name' => 'Setup Node', 'with' => ['extensions' => 'x']]]]]))
        ->toBe([]);

    // And the real action still resolves through that same path.
    expect(buildActionPhpExtensions(buildActionDefinition()))
        ->toContain('pdo_pgsql')
        ->toContain('redis');
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

it('never converges rclone through apt, selfupdate, the upstream installer or the operator configuration', function () {
    $source = bootstrapRuntimeSource();

    // The package contract must not name rclone at all — neither as a
    // package nor as a package-supplied tool.
    preg_match('/^BASE_PACKAGES=\(\n(.*?)\n\)$/ms', $source, $baseMatch);
    expect($baseMatch[1] ?? null)->not->toBeNull();
    $packages = array_values(array_filter(array_map('trim', explode("\n", $baseMatch[1]))));
    expect($packages)->not->toContain('rclone');

    preg_match('/^REQUIRED_RUNTIME_TOOLS=\(\n(.*?)\n\)$/ms', $source, $toolsMatch);
    expect($toolsMatch[1] ?? null)->not->toBeNull();
    expect($toolsMatch[1])->not->toContain('rclone:rclone');

    // Comment lines may document the forbidden mechanisms; no code line may
    // use them: no selfupdate, no upstream install.sh, no pipe-to-shell
    // download, and no reference to the operator's rclone.conf at all.
    expect(preg_match('/^[^#\n]*\bselfupdate\b/m', $source))->toBe(0, 'a code line invokes rclone selfupdate');
    expect(preg_match('/^[^#\n]*install\.sh/m', $source))->toBe(0, 'a code line references the upstream install.sh');
    expect(preg_match('/^[^#\n]*curl[^#\n]*\|\s*(ba)?sh\b/m', $source))->toBe(0, 'a code line pipes a download into a shell');
    expect(preg_match('/^[^#\n]*rclone\.conf/m', $source))->toBe(0, 'a code line references the operator rclone.conf');
    expect(preg_match('/^[^#\n]*rclone config\b/m', $source))->toBe(0, 'a code line runs rclone config');
});

it('commits the external-runtimes contract with the canonical rclone install contract and pinned fingerprint', function () {
    $contract = bootstrapRuntimeCommittedRcloneContract();

    // The exact version is deliberately not duplicated here: it lives only
    // in the committed contract, and an upgrade is that file's own reviewed
    // change. Shape and security-critical pins are what this test freezes.
    expect($contract['RCLONE_VERSION'] ?? null)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($contract['RCLONE_PLATFORM'] ?? null)->toBe('linux-amd64');
    expect($contract['RCLONE_BINARY'] ?? null)->toBe('/usr/bin/rclone');
    expect($contract['RCLONE_OWNER'] ?? null)->toBe('root');
    expect($contract['RCLONE_GROUP'] ?? null)->toBe('root');
    expect($contract['RCLONE_MODE'] ?? null)->toBe('0755');
    expect($contract['RCLONE_RELEASE_SIGNING_FINGERPRINT'] ?? null)->toBe(bootstrapRuntimeRcloneFingerprint());
});

it('commits the rclone release-signing public key holding exactly the pinned fingerprint', function () {
    $keyPath = base_path('infrastructure/config/external-runtimes/rclone-release-signing-key.asc');
    $key = File::get($keyPath);

    expect($key)->toStartWith('-----BEGIN PGP PUBLIC KEY BLOCK-----');
    expect(trim($key))->toEndWith('-----END PGP PUBLIC KEY BLOCK-----');

    // With gpg available (always, on the Ubuntu CI runners), apply the
    // installer's own acceptance to the committed material: exactly one
    // primary key whose fingerprint equals the pin.
    $fingerprints = bootstrapRuntimeKeyPrimaryFingerprints($keyPath);

    if ($fingerprints === null) {
        return;
    }

    expect($fingerprints)->toBe([bootstrapRuntimeRcloneFingerprint()], 'the committed key must hold exactly one primary key, the pinned one');
});

it('commits the yt-dlp install contract and a release-signing key holding exactly the pinned fingerprint', function () {
    $contract = bootstrapRuntimeCommittedRcloneContract();

    // The version is the contract's own; shape and the security-critical
    // pins are what this test freezes.
    expect($contract['YTDLP_VERSION'] ?? null)->toMatch('/^\d{4}\.\d{2}\.\d{2}(\.\d+)?$/');
    expect($contract['YTDLP_ARTIFACT'] ?? null)->toBe('yt-dlp_linux');
    expect($contract['YTDLP_BINARY'] ?? null)->toBe('/usr/local/bin/yt-dlp');
    expect($contract['YTDLP_OWNER'] ?? null)->toBe('root');
    expect($contract['YTDLP_GROUP'] ?? null)->toBe('root');
    expect($contract['YTDLP_MODE'] ?? null)->toBe('0755');
    expect($contract['YTDLP_RELEASE_SIGNING_FINGERPRINT'] ?? null)->toBe(bootstrapRuntimeYtDlpFingerprint());

    $keyPath = base_path('infrastructure/config/external-runtimes/yt-dlp-release-signing-key.asc');
    $key = File::get($keyPath);

    expect($key)->toStartWith('-----BEGIN PGP PUBLIC KEY BLOCK-----');
    expect(trim($key))->toEndWith('-----END PGP PUBLIC KEY BLOCK-----');

    $fingerprints = bootstrapRuntimeKeyPrimaryFingerprints($keyPath);

    if ($fingerprints === null) {
        return;
    }

    expect($fingerprints)->toBe([bootstrapRuntimeYtDlpFingerprint()], 'the committed key must hold exactly one primary key, the pinned one');
});

it('commits the ffmpeg pin as one month-end build, its archive and the digest that identifies it', function () {
    $contract = bootstrapRuntimeCommittedRcloneContract();

    expect($contract['FFMPEG_RELEASE'] ?? null)->toMatch('/^autobuild-\d{4}(-\d{2}){4}$/')
        ->and($contract['FFMPEG_ARTIFACT'] ?? null)->toMatch('/^ffmpeg-n\d+\.\d+(\.\d+)?-\d+-g[0-9a-f]+-linux64-gpl-\d+\.\d+\.tar\.xz$/')
        ->and($contract['FFMPEG_SHA256'] ?? null)->toMatch('/^[0-9a-f]{64}$/')
        ->and($contract['FFMPEG_BIN_DIR'] ?? null)->toBe('/usr/local/bin')
        ->and($contract['FFMPEG_OWNER'] ?? null)->toBe('root')
        ->and($contract['FFMPEG_GROUP'] ?? null)->toBe('root')
        ->and($contract['FFMPEG_MODE'] ?? null)->toBe('0755');

    // The version the binaries print is the archive's own version plus the
    // build date, so the two can never be moved apart.
    preg_match('/^ffmpeg-(n[^-]+-\d+-g[0-9a-f]+)-linux64/', (string) $contract['FFMPEG_ARTIFACT'], $artifact);
    preg_match('/^autobuild-(\d{4})-(\d{2})-(\d{2})/', (string) $contract['FFMPEG_RELEASE'], $release);
    expect($contract['FFMPEG_VERSION'] ?? null)->toBe($artifact[1].'-'.$release[1].$release[2].$release[3]);
});

it('never installs Jammy\'s ffmpeg package: ffmpeg converges only through the pinned build', function () {
    $source = bootstrapRuntimeSource();

    preg_match('/^BASE_PACKAGES=\(\n(.*?)\n\)$/ms', $source, $baseMatch);
    expect(array_map('trim', explode("\n", $baseMatch[1] ?? '')))->not->toContain('ffmpeg')->toContain('xz-utils');

    preg_match('/^REQUIRED_RUNTIME_TOOLS=\(\n(.*?)\n\)$/ms', $source, $toolsMatch);
    expect($toolsMatch[1] ?? '')->not->toContain('ffmpeg:')->not->toContain('ffprobe:')->toContain('xz:xz-utils');
});

it('never converges yt-dlp through apt or its own updater', function () {
    $source = bootstrapRuntimeSource();

    preg_match('/^BASE_PACKAGES=\(\n(.*?)\n\)$/ms', $source, $baseMatch);
    expect(array_map('trim', explode("\n", $baseMatch[1] ?? '')))->not->toContain('yt-dlp');

    preg_match('/^REQUIRED_RUNTIME_TOOLS=\(\n(.*?)\n\)$/ms', $source, $toolsMatch);
    expect($toolsMatch[1] ?? '')->not->toContain('yt-dlp:');

    // Comment lines may name the updater to forbid it; no code line may run it.
    expect(preg_match('/^[^#\n]*(?:\s-U\b|--update\b|--update-to\b)/m', $source))->toBe(0, 'a code line runs an updater');
});

it('shares the committed external-runtimes contract with bootstrap-host-preflight instead of duplicating the pin', function () {
    $contractPath = 'infrastructure/config/external-runtimes/versions.env';

    expect(bootstrapRuntimeSource())->toContain($contractPath);
    expect(File::get(base_path('infrastructure/scripts/bootstrap-host-preflight')))->toContain($contractPath);
});

it('derives its required tool inventory from the clean-host bootstrap.1 canonical contract', function () {
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

// =============================================================================
// cron is part of the runtime contract, not an accident of the base image
// =============================================================================

it('treats a host without the cron package as not satisfying the runtime contract', function () {
    // RateGuru installs and verifies /etc/cron.d entries — every target's Laravel
    // scheduler and the backup cycle — and nothing owned the daemon that executes
    // them. On the current VPS cron is present because the base image shipped it,
    // which is not a contract: a clean host could have had every scheduler file
    // installed correctly and nothing running them.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'packages' => array_values(array_diff(bootstrapRuntimeRequiredPackages(), ['cron'])),
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--check'], $env);

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain('cron');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('detects a missing cron binary even when the package looks installed', function () {
    // The package list and the tool list are independent checks on purpose: a
    // package can be marked installed and its binary still be absent or
    // unreachable, and the scheduler daemon is exactly the thing where that would
    // go unnoticed until a schedule silently stopped.
    $scratch = bootstrapRuntimeScratchDir();

    try {
        $env = bootstrapRuntimeFixture($scratch, [
            'tools' => array_values(array_diff(bootstrapRuntimeAllTools(), ['cron'])),
        ]);

        [$exit, $output] = bootstrapRuntimeRun(['--verify'], $env);

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain('tool:cron');
    } finally {
        bootstrapRuntimeCleanup($scratch);
    }
});

it('installs cron through the generic package mechanism', function () {
    // No ad-hoc handling: cron is one entry in BASE_PACKAGES and one in the tool
    // table, so there is a single definition of what installing and proving a
    // runtime package means.
    $source = File::get(base_path('infrastructure/scripts/install-bootstrap-runtime'));

    expect($source)
        ->toContain("\n    cron\n")
        ->toContain("\n    cron:cron\n");

    // And nothing cron-specific outside those lists.
    $executable = executableSourceLines($source);

    foreach (['systemctl enable cron', 'systemctl start cron', 'service cron'] as $adHoc) {
        expect(str_contains($executable, $adHoc))
            ->toBeFalse("the runtime installer must not manage the cron service itself: {$adHoc}");
    }
});

it('lets apt accept a repository renaming itself, and nothing broader, in every installer that refreshes the indexes', function () {
    // On 2026-11-21 the PHP PPA's Release Label became "PPA for PHP
    // (deprecated)", and a non-interactive apt-get update refuses any changed
    // Release field until it is accepted: every host's Prepare failed there.
    foreach (['install-bootstrap-runtime', 'install-mail-gateway', 'install-mail-signing'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        preg_match_all('/"\$\{APT_GET_BIN\}" update\b[^\n]*/', $source, $updates);

        expect($updates[0])->not->toBeEmpty("{$script} refreshes no index");

        foreach ($updates[0] as $update) {
            expect($update)->toContain('update --allow-releaseinfo-change-label');
        }

        // Only the Label: a changed Origin, Suite or Codename, or a repository
        // without a valid signature, still stops the run.
        foreach (['--allow-releaseinfo-change ', '--allow-releaseinfo-change-origin', '--allow-releaseinfo-change-suite', '--allow-releaseinfo-change-codename', '--allow-unauthenticated', '--allow-insecure-repositories', 'AllowReleaseInfoChange'] as $broader) {
            expect(str_contains($source, $broader))->toBeFalse("{$script}: {$broader}");
        }
    }
});
