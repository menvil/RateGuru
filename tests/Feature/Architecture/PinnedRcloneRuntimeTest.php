<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 7.1: the pinned rclone the release-artifact archive runs on.
 *
 * The GitHub Actions runner that archives a release needs rclone before any
 * RateGuru host is involved. It must be the exact binary this repository
 * already trusts — never `curl | bash`, never an unversioned
 * runner-preinstalled copy — so infrastructure/scripts/fetch-verified-rclone
 * reproduces install-bootstrap-runtime's verification chain without root and
 * without touching the managed /usr/bin/rclone.
 *
 * These tests run the real shipped script end to end against stub curl/gpg
 * binaries and a fixture release origin: no network access, no real OpenPGP
 * keyring, and the pinned version/platform/fingerprint always read from the
 * single committed contract so a moved pin cannot silently pass.
 */
function pinnedRcloneScript(): string
{
    return base_path('infrastructure/scripts/fetch-verified-rclone');
}

function pinnedRcloneContractPath(): string
{
    return base_path('infrastructure/config/external-runtimes/versions.env');
}

/**
 * @return array<string, string>
 */
function pinnedRcloneCommittedContract(): array
{
    $contract = [];

    foreach (preg_split('/\R/', File::get(pinnedRcloneContractPath())) as $line) {
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $contract[$key] ??= $value;
    }

    return $contract;
}

/**
 * The script with its comments and blank lines removed. It documents exactly
 * what it refuses to do, so a whole-file search would flag the sentences that
 * state the guarantee rather than a violation of it.
 */
function pinnedRcloneCode(): string
{
    $lines = preg_split('/\R/', File::get(pinnedRcloneScript()));

    return implode("\n", array_filter($lines, function (string $line): bool {
        $trimmed = ltrim($line);

        return $trimmed !== '' && ! str_starts_with($trimmed, '#');
    }));
}

function pinnedRcloneScratch(): string
{
    $dir = sys_get_temp_dir().'/pinned-rclone-'.uniqid('', true).'-'.getmypid();

    foreach (['/origin', '/bin', '/into', '/contract'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create {$dir}{$sub}");
    }

    return $dir;
}

function pinnedRcloneCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * A fixture release origin plus stub curl/gpg, all keyed off the committed
 * pin unless a test deliberately moves one value.
 *
 * Options:
 *   version / platform / fingerprint  contract values (default: committed)
 *   contract_version                  what the *contract file* claims
 *   sums_digest                       digest the signed SHA256SUMS carries
 *   signer                            signer the fixture SHA256SUMS names
 *   keyring_fingerprint               fingerprint the stub gpg reports
 *   extracted_version                 version the extracted binary reports
 *   omit_binary                       build a zip with no rclone in it
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function pinnedRcloneFixture(string $scratch, array $options = []): array
{
    $contract = pinnedRcloneCommittedContract();
    $version = $options['version'] ?? $contract['RCLONE_VERSION'];
    $platform = $options['platform'] ?? $contract['RCLONE_PLATFORM'];
    $fingerprint = $options['fingerprint'] ?? $contract['RCLONE_RELEASE_SIGNING_FINGERPRINT'];

    $stage = $scratch.'/stage';
    $directory = "rclone-v{$version}-{$platform}";
    expect(@mkdir($stage.'/'.$directory, 0o755, true))->toBeTrue('could not stage the fixture release');

    if (! ($options['omit_binary'] ?? false)) {
        $reported = $options['extracted_version'] ?? "v{$version}";
        file_put_contents(
            $stage.'/'.$directory.'/rclone',
            "#!/usr/bin/env bash\nprintf 'rclone {$reported}\\n'\n",
        );
        chmod($stage.'/'.$directory.'/rclone', 0o755);
    } else {
        file_put_contents($stage.'/'.$directory.'/README.txt', "no binary here\n");
    }

    $artifact = "rclone-v{$version}-{$platform}.zip";

    $output = [];
    $exit = 0;
    exec(
        'cd '.escapeshellarg($stage).' && zip -qr '.escapeshellarg($scratch.'/origin/'.$artifact).' . 2>&1',
        $output,
        $exit,
    );
    expect($exit)->toBe(0, 'could not build the fixture release archive: '.implode("\n", $output));

    $digest = $options['sums_digest'] ?? hash_file('sha256', $scratch.'/origin/'.$artifact);
    $signer = $options['signer'] ?? 'RCLONE-RELEASE';

    file_put_contents($scratch.'/origin/SHA256SUMS', implode("\n", [
        '-----BEGIN PGP SIGNED MESSAGE-----',
        '',
        str_repeat('0', 64).'  rclone-v'.$version.'-linux-arm64.zip',
        $digest.'  '.$artifact,
        '-----BEGIN PGP SIGNATURE-----',
        'SIGNER='.$signer,
        '-----END PGP SIGNATURE-----',
        '',
    ]));

    file_put_contents($scratch.'/contract/versions.env', implode("\n", [
        '# fixture contract',
        'RCLONE_VERSION='.($options['contract_version'] ?? $version),
        'RCLONE_PLATFORM='.$platform,
        'RCLONE_BINARY=/usr/bin/rclone',
        'RCLONE_OWNER=root',
        'RCLONE_GROUP=root',
        'RCLONE_MODE=0755',
        'RCLONE_RELEASE_SIGNING_FINGERPRINT='.$fingerprint,
        '',
    ]));

    file_put_contents($scratch.'/contract/key.asc', "RCLONE-KEY-MARKER\n");

    // A curl that serves the fixture origin by filename and nothing else: an
    // unknown name is curl's own "HTTP 22" failure, so a wrong pinned version
    // fails exactly as a missing upstream release would.
    file_put_contents($scratch.'/bin/curl', <<<'STUB'
        #!/usr/bin/env bash
        set -Eeuo pipefail
        args=("$@")
        out=""
        for ((i = 0; i < ${#args[@]}; i++)); do
            [[ "${args[i]}" == "--output" ]] && out="${args[i + 1]}"
        done
        url="${args[${#args[@]} - 1]}"
        printf '%s\n' "${url}" >> "${STUB_LOG}"
        name="${url##*/}"
        [[ -f "${STUB_ORIGIN}/${name}" ]] || exit 22
        cp "${STUB_ORIGIN}/${name}" "${out}"
        STUB);

    // A gpg that models exactly the three operations the script performs:
    // dearmor (only for the committed key marker), fingerprint listing, and
    // clearsigned verification (only for the expected signer).
    file_put_contents($scratch.'/bin/gpg', <<<'STUB'
        #!/usr/bin/env bash
        set -Eeuo pipefail
        args=("$@")
        mode=""
        out=""
        target=""
        for ((i = 0; i < ${#args[@]}; i++)); do
            case "${args[i]}" in
                --dearmor) mode=dearmor ;;
                --show-keys) mode=showkeys ;;
                --decrypt) mode=decrypt; target="${args[i + 1]}" ;;
                --output) out="${args[i + 1]}" ;;
            esac
        done
        case "${mode}" in
            dearmor)
                source_key="${args[${#args[@]} - 1]}"
                grep -q 'RCLONE-KEY-MARKER' "${source_key}" || exit 2
                printf 'KEYRING\n' > "${out}"
                ;;
            showkeys)
                printf 'pub:-:255:22:AAAA:1:::-:::scESC:::::ed25519::0:\n'
                printf 'fpr:::::::::%s:\n' "${STUB_KEYRING_FINGERPRINT}"
                ;;
            decrypt)
                grep -q "SIGNER=${STUB_TRUSTED_SIGNER}" "${target}" || exit 2
                sed -n '/^-----BEGIN PGP SIGNED MESSAGE-----/,/^-----BEGIN PGP SIGNATURE-----/p' "${target}" \
                    | sed '1,2d;$d' > "${out}"
                ;;
            *)
                exit 3
                ;;
        esac
        STUB);

    chmod($scratch.'/bin/curl', 0o755);
    chmod($scratch.'/bin/gpg', 0o755);

    return [
        'version' => $version,
        'platform' => $platform,
        'artifact' => $artifact,
        'keyring_fingerprint' => $options['keyring_fingerprint'] ?? $fingerprint,
    ];
}

/**
 * @param  list<string>  $extra
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function pinnedRcloneRun(string $scratch, array $fixture, array $extra = [], array $env = []): array
{
    $arguments = array_merge([
        'bash', pinnedRcloneScript(),
        '--into', $scratch.'/into',
        '--contract', $scratch.'/contract/versions.env',
        '--signing-key', $scratch.'/contract/key.asc',
        '--download-base-url', 'file://'.$scratch.'/origin',
        '--curl-bin', $scratch.'/bin/curl',
        '--gpg-bin', $scratch.'/bin/gpg',
    ], $extra);

    $environment = array_merge([
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'STUB_ORIGIN' => $scratch.'/origin',
        'STUB_LOG' => $scratch.'/curl.log',
        'STUB_KEYRING_FINGERPRINT' => $fixture['keyring_fingerprint'],
        'STUB_TRUSTED_SIGNER' => 'RCLONE-RELEASE',
    ], $env);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open($arguments, $descriptors, $pipes, null, $environment);

    expect($process)->not->toBeFalse('could not start fetch-verified-rclone');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

it('installs the pinned, signature-verified rclone from the committed contract', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch);

        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);

        expect($exit)->toBe(0, "installing the pinned rclone failed:\n{$output}");
        expect($output)
            ->toContain('Downloading '.$fixture['artifact'])
            ->toContain('Signature and checksum verified')
            ->toContain('rclone v'.$fixture['version'].' ('.$fixture['platform'].') installed');

        $installed = $scratch.'/into/rclone';

        expect(File::exists($installed))->toBeTrue();
        expect(is_executable($installed))->toBeTrue();
        expect(fileperms($installed) & 0o777)->toBe(0o755);
        expect(trim((string) shell_exec(escapeshellarg($installed).' --version')))
            ->toBe('rclone v'.$fixture['version']);

        // The exact pinned release was requested, nothing else.
        expect(File::get($scratch.'/curl.log'))
            ->toContain('/v'.$fixture['version'].'/'.$fixture['artifact'])
            ->toContain('/v'.$fixture['version'].'/SHA256SUMS')
            ->not->toContain('latest');
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('refuses an archive whose checksum does not match the signed SHA256SUMS', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch, ['sums_digest' => str_repeat('a', 64)]);

        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('checksum mismatch for '.$fixture['artifact']);
        expect(File::exists($scratch.'/into/rclone'))->toBeFalse();
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('refuses a SHA256SUMS signed by anything but the pinned release key', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch, ['signer' => 'SOMEBODY-ELSE']);

        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('SHA256SUMS signature verification failed against the pinned release-signing key');
        expect(File::exists($scratch.'/into/rclone'))->toBeFalse();
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('refuses a signing key whose fingerprint does not match the pin', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch, [
            'keyring_fingerprint' => str_repeat('A', 40),
        ]);

        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('does not match the pinned fingerprint');
        expect(File::exists($scratch.'/into/rclone'))->toBeFalse();
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('refuses an extracted binary that does not report exactly the pinned version', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch, ['extracted_version' => 'v9.9.9']);

        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('extracted binary reports v9.9.9, expected v'.$fixture['version']);
        expect(File::exists($scratch.'/into/rclone'))->toBeFalse();
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('refuses a release archive that contains no rclone binary', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch, ['omit_binary' => true]);

        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('does not contain the rclone binary');
        expect(File::exists($scratch.'/into/rclone'))->toBeFalse();
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('fails closed on a mangled contract instead of installing whatever was parseable', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch);

        file_put_contents($scratch.'/contract/versions.env', "RCLONE_PLATFORM=linux-amd64\n");
        [$exit, $output] = pinnedRcloneRun($scratch, $fixture);
        expect($exit)->not->toBe(0);
        expect($output)->toContain('contract carries no usable RCLONE_VERSION');

        file_put_contents($scratch.'/contract/versions.env', implode("\n", [
            'RCLONE_VERSION=latest',
            'RCLONE_PLATFORM=linux-amd64',
            'RCLONE_RELEASE_SIGNING_FINGERPRINT='.str_repeat('A', 40),
            '',
        ]));
        [$latestExit, $latestOutput] = pinnedRcloneRun($scratch, $fixture);
        expect($latestExit)->not->toBe(0);
        expect($latestOutput)->toContain('contract carries no usable RCLONE_VERSION');

        file_put_contents($scratch.'/contract/versions.env', implode("\n", [
            'RCLONE_VERSION=1.75.0',
            'RCLONE_PLATFORM=linux-amd64',
            'RCLONE_RELEASE_SIGNING_FINGERPRINT=not-a-fingerprint',
            '',
        ]));
        [$fprExit, $fprOutput] = pinnedRcloneRun($scratch, $fixture);
        expect($fprExit)->not->toBe(0);
        expect($fprOutput)->toContain('contract carries no usable RCLONE_RELEASE_SIGNING_FINGERPRINT');

        expect(File::exists($scratch.'/into/rclone'))->toBeFalse();
        expect(File::exists($scratch.'/curl.log'))->toBeFalse('the contract must be validated before any download');
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('refuses a download origin that is neither https nor a local file URL', function () {
    $scratch = pinnedRcloneScratch();

    try {
        $fixture = pinnedRcloneFixture($scratch);

        foreach (['http://downloads.rclone.org', 'ftp://example.test', 'downloads.rclone.org'] as $origin) {
            [$exit, $output] = pinnedRcloneRun($scratch, $fixture, ['--download-base-url', $origin]);

            expect($exit)->not->toBe(0, "accepted download origin {$origin}");
            expect($output)->toContain('--download-base-url must be an https:// or file:// URL');
        }
    } finally {
        pinnedRcloneCleanup($scratch);
    }
});

it('keeps the committed contract the single source of truth for the pin', function () {
    $script = File::get(pinnedRcloneScript());
    $code = pinnedRcloneCode();

    // Defaults point at the same committed contract install-bootstrap-runtime
    // and bootstrap-host-preflight read; the version is never restated here.
    expect($script)
        ->toContain('CONTRACT_FILE_DEFAULT="${SCRIPT_DIR}/../config/external-runtimes/versions.env"')
        ->toContain('SIGNING_KEY_FILE_DEFAULT="${SCRIPT_DIR}/../config/external-runtimes/rclone-release-signing-key.asc"')
        ->toContain('DOWNLOAD_BASE_URL_DEFAULT="https://downloads.rclone.org"');

    expect($code)
        ->not->toContain('selfupdate')
        ->not->toContain('| bash')
        ->not->toContain('apt-get');

    // No literal version anywhere in the script.
    $contract = pinnedRcloneCommittedContract();
    expect($code)->not->toContain($contract['RCLONE_VERSION']);

    // The committed contract really does carry everything the script needs.
    expect($contract)->toHaveKeys([
        'RCLONE_VERSION',
        'RCLONE_PLATFORM',
        'RCLONE_RELEASE_SIGNING_FINGERPRINT',
    ]);
    expect($contract['RCLONE_VERSION'])->toMatch('/^[0-9]+\.[0-9]+\.[0-9]+$/');
    expect($contract['RCLONE_RELEASE_SIGNING_FINGERPRINT'])->toMatch('/^[0-9A-F]{40}$/');
    expect(File::exists(base_path('infrastructure/config/external-runtimes/rclone-release-signing-key.asc')))->toBeTrue();
});

it('never touches the managed host rclone runtime or an operator rclone configuration', function () {
    $code = pinnedRcloneCode();

    // This installer exists precisely so CI does not need root and does not
    // interact with the host contract install-bootstrap-runtime owns.
    expect($code)
        ->not->toContain('/root/.config/rclone')
        ->not->toContain('rclone.conf')
        ->not->toContain('/usr/bin/rclone')
        ->not->toContain('require_root')
        ->not->toContain('EUID');

    // The only path it writes is the one it was given.
    expect(substr_count($code, 'mv -f --'))->toBe(1);
    expect($code)->toContain('"${INTO%/}/rclone"');
});
