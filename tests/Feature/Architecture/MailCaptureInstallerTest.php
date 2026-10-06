<?php

use Illuminate\Support\Facades\File;

/*
 * install-mail-capture, run for real against a simulated host.
 *
 * The installer is executed whole — argument parsing, validation, download,
 * checksum, install, activation, the runtime health gate and the rollback trap
 * — with the host relocated under a scratch directory through the gated
 * RATEGURU_MAILCAPTURE_FS_ROOT / RATEGURU_MAILCAPTURE_EUID seam, and the OS
 * answered by stubs on PATH (tests/Pest.php, plus the installer's own below).
 * Nothing here needs root, the network or systemd.
 *
 * The script runs from a scratch repository whose files are links to the
 * committed ones, except SHA256SUMS: the real pins name the real upstream
 * releases, which a test cannot download, so the apply tests pin fixture
 * archives instead and the --check tests keep the committed file.
 */

const MAIL_CAPTURE_MAILPIT_ARCHIVE = 'mailpit-linux-amd64.tar.gz';

const MAIL_CAPTURE_MAILTRAP_ARCHIVE = 'mailtrap-local_0.2.0_linux_amd64.tar.gz';

/**
 * The two release archives, built once per worker in three variants:
 * genuine (the binary under the name the installer extracts), tampered (the
 * same archive name, other bytes) and hollow (the right name, no binary).
 *
 * @return array<string, array<string, array{path:string, sha256:string, binary:string}>>
 */
function mailCaptureReleaseArchives(): array
{
    static $archives = null;

    if ($archives !== null) {
        return $archives;
    }

    $dir = makeScratchDir('mail-capture-releases');
    register_shutdown_function(fn () => removeScratchDir($dir));

    $releases = [
        MAIL_CAPTURE_MAILPIT_ARCHIVE => ['mailpit', 'mailpit v1.30.5'],
        MAIL_CAPTURE_MAILTRAP_ARCHIVE => ['mailtrap-local', 'mailtrap-local 0.2.0'],
    ];

    $archives = [];

    foreach (['genuine', 'tampered', 'hollow'] as $variant) {
        foreach ($releases as $archive => [$binary, $version]) {
            $source = "{$dir}/{$variant}-src/{$binary}";
            @mkdir($source, 0o755, true);

            $member = $variant === 'hollow' ? 'README' : $binary;
            $contents = match ($variant) {
                'genuine' => "#!/usr/bin/env bash\necho '{$version}'\n",
                'tampered' => "#!/usr/bin/env bash\necho 'not {$version}'\n",
                'hollow' => "no binary in this archive\n",
            };
            writeExecutable("{$source}/{$member}", $contents);

            @mkdir("{$dir}/{$variant}", 0o755, true);
            $path = "{$dir}/{$variant}/{$archive}";
            exec('tar -czf '.escapeshellarg($path).' -C '.escapeshellarg($source).' '.escapeshellarg($member).' 2>&1', $output, $status);
            expect($status)->toBe(0, implode("\n", $output));

            $archives[$variant][$archive] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'binary' => $contents];
        }
    }

    return $archives;
}

/**
 * A fresh host the installer has never touched: the shared stubs plus the
 * installer's own, a scratch repository, the directories every Debian host
 * already has, and both services' endpoints ready to answer once started.
 *
 * $served maps an archive name to the variant a download receives (null: the
 * download fails); $pinned maps it to the variant SHA256SUMS pins. Both
 * default to genuine. $pinned === null keeps the committed SHA256SUMS.
 *
 * @param  array<string, ?string>  $served
 * @param  array<string, string>|null  $pinned
 * @return array{root:string, bin:string, state:string, repo:string, host:string, tmp:string}
 */
function mailCaptureFreshHost(array $served = [], ?array $pinned = []): array
{
    $workspace = mailCaptureStubWorkspace();
    $host = $workspace + [
        'repo' => $workspace['root'].'/repo',
        'host' => $workspace['root'].'/host',
        'tmp' => $workspace['root'].'/tmp',
    ];

    foreach (['/usr/local/bin', '/etc/systemd/system', '/etc/nginx'] as $directory) {
        mkdir($host['host'].$directory, 0o755, true);
    }
    mkdir($host['tmp'], 0o700);

    // The scratch repository: the shipped installer and the committed
    // configuration, linked rather than copied so it is always what ships.
    foreach ([
        'infrastructure/scripts/install-mail-capture',
        'infrastructure/config/mail-capture/versions.env',
        'infrastructure/config/mail-capture/mailpit.env',
        'infrastructure/config/mail-capture/mailpit-relay.yml',
        'infrastructure/config/mail-capture/mailtrap-local.yml',
        'infrastructure/config/systemd/staging-mailpit.service',
        'infrastructure/config/systemd/staging-mailtrap-local.service',
        'infrastructure/config/nginx/mailpit-staging',
        'infrastructure/config/nginx/mailtrap-local-staging',
    ] as $path) {
        @mkdir(dirname($host['repo'].'/'.$path), 0o755, true);
        symlink(base_path($path), $host['repo'].'/'.$path);
    }

    $archives = mailCaptureReleaseArchives();
    $checksums = $host['repo'].'/infrastructure/config/mail-capture/SHA256SUMS';

    if ($pinned === null) {
        symlink(base_path('infrastructure/config/mail-capture/SHA256SUMS'), $checksums);
    } else {
        $pins = '';
        foreach ([MAIL_CAPTURE_MAILPIT_ARCHIVE, MAIL_CAPTURE_MAILTRAP_ARCHIVE] as $archive) {
            $pins .= $archives[$pinned[$archive] ?? 'genuine'][$archive]['sha256']."  {$archive}\n";
        }
        file_put_contents($checksums, $pins);
    }

    foreach ([MAIL_CAPTURE_MAILPIT_ARCHIVE, MAIL_CAPTURE_MAILTRAP_ARCHIVE] as $archive) {
        $variant = array_key_exists($archive, $served) ? $served[$archive] : 'genuine';
        if ($variant !== null) {
            symlink($archives[$variant][$archive]['path'], $host['state'].'/downloads/'.$archive);
        }
    }

    // The installer's own stubs. `install` drops the owner flags only an
    // actual root may pass and records the call, so ownership is asserted on
    // what the installer asked for.
    $realInstall = trim((string) shell_exec('command -v install'));
    writeExecutable($host['bin'].'/install', <<<SH
#!/usr/bin/env bash
printf '%s\\n' "install \$*" >>"\${STUB_STATE_DIR}/calls"
args=()
while [[ \$# -gt 0 ]]; do
    case "\$1" in
        -o|-g) shift 2 ;;
        *) args+=("\$1"); shift ;;
    esac
done
exec {$realInstall} "\${args[@]}"
SH);
    writeExecutable($host['bin'].'/uname', <<<'SH'
#!/usr/bin/env bash
case "${1:-}" in
    -m) cat "${STUB_STATE_DIR}/uname-m" 2>/dev/null || echo x86_64 ;;
    *) cat "${STUB_STATE_DIR}/uname-s" 2>/dev/null || echo Linux ;;
esac
SH);
    writeExecutable($host['bin'].'/id', <<<'SH'
#!/usr/bin/env bash
grep -Fxq -- "${1:-}" "${STUB_STATE_DIR}/users" 2>/dev/null && exit 0
printf "id: '%s': no such user\n" "${1:-}" >&2
exit 1
SH);
    writeExecutable($host['bin'].'/useradd', <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "useradd $*" >>"${STUB_STATE_DIR}/calls"
printf '%s\n' "${@: -1}" >>"${STUB_STATE_DIR}/users"
SH);
    writeExecutable($host['bin'].'/systemd-analyze', <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "systemd-analyze $*" >>"${STUB_STATE_DIR}/calls"
if [[ -f "${STUB_STATE_DIR}/systemd_analyze_invalid" ]]; then
    printf 'staging-mailpit.service: Unknown key name in section [Service]\n' >&2
    exit 1
fi
SH);

    mailCaptureServingEndpoints($host['state']);

    return $host;
}

/**
 * The host after one successful apply — the start of every test of a re-run,
 * an upgrade or a drift. A worker applies once into a template of its own;
 * each call copies it (copyScratchTemplate() rewrites the paths the host
 * records, such as its vhost links) with an empty call log.
 *
 * @return array{root:string, bin:string, state:string, repo:string, host:string, tmp:string}
 */
function mailCaptureInstalledHost(): array
{
    static $template = null;

    if ($template === null) {
        $template = mailCaptureFreshHost();
        $root = $template['root'];
        register_shutdown_function(fn () => removeScratchDir($root));

        $run = mailCaptureInstall($template);
        expect($run['exit'])->toBe(0, "the template apply failed:\n".$run['output']);
        file_put_contents($template['state'].'/calls', '');
    }

    $copy = makeScratchDir('mail-capture-host', [''], 0o700);
    copyScratchTemplate($template['root'], $copy);

    return array_map(fn (string $path): string => str_replace($template['root'], $copy, $path), $template);
}

/**
 * install-mail-capture from the host's scratch repository, root and every host
 * path relocated through the gated seam.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{exit:int, output:string}
 */
function mailCaptureInstall(array $host, array $arguments = ['--apply'], array $env = []): array
{
    return mailCaptureRun($host, ['bash', $host['repo'].'/infrastructure/scripts/install-mail-capture', ...$arguments], [
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MAILCAPTURE_EUID' => '0',
        'RATEGURU_MAILCAPTURE_FS_ROOT' => $host['host'],
        'TMPDIR' => $host['tmp'],
        ...$env,
    ]);
}

/**
 * Every file and link on the host outside /var (state and backups), relative
 * to the host root, with its mode and its content or link target.
 *
 * @return array<string, string>
 */
function mailCaptureHostFiles(array $host): array
{
    $files = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($host['host'], FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($entries as $entry) {
        $relative = substr($entry->getPathname(), strlen($host['host']) + 1);

        if (str_starts_with($relative, 'var/') || (! $entry->isLink() && $entry->isDir())) {
            continue;
        }

        $files[$relative] = $entry->isLink()
            ? 'link to '.readlink($entry->getPathname())
            : sprintf('%o ', fileperms($entry->getPathname()) & 0o777).md5_file($entry->getPathname());
    }

    ksort($files);

    return $files;
}

/** The position of the first call that starts with $prefix, failing when there is none. */
function mailCaptureCallIndex(array $calls, string $prefix): int
{
    foreach ($calls as $index => $call) {
        if (str_starts_with($call, $prefix)) {
            return $index;
        }
    }

    test()->fail("no call starting with '{$prefix}' in:\n".implode("\n", $calls));
}

/** The calls that wrote a file onto the host (directories excluded). */
function mailCaptureFileInstalls(array $calls): array
{
    return array_values(array_filter($calls, fn (string $call): bool => str_starts_with($call, 'install -o ')));
}

const MAIL_CAPTURE_INSTALLED_FILES = [
    'etc/nginx/sites-available/mailpit-staging' => 'infrastructure/config/nginx/mailpit-staging',
    'etc/nginx/sites-available/mailtrap-local-staging' => 'infrastructure/config/nginx/mailtrap-local-staging',
    'etc/staging-mail-capture/mailpit-relay.yml' => 'infrastructure/config/mail-capture/mailpit-relay.yml',
    'etc/staging-mail-capture/mailpit.env' => 'infrastructure/config/mail-capture/mailpit.env',
    'etc/staging-mail-capture/mailtrap-local.yml' => 'infrastructure/config/mail-capture/mailtrap-local.yml',
    'etc/staging-mail-capture/versions.env' => 'infrastructure/config/mail-capture/versions.env',
    'etc/systemd/system/staging-mailpit.service' => 'infrastructure/config/systemd/staging-mailpit.service',
    'etc/systemd/system/staging-mailtrap-local.service' => 'infrastructure/config/systemd/staging-mailtrap-local.service',
];

// =============================================================================
// A successful apply
// =============================================================================

it('installs both services from checksum-verified pinned archives on a fresh host', function () {
    $host = mailCaptureFreshHost();

    try {
        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(0, $run['output']);

        // Exactly the slice's own files, under environment-owned names — and
        // nothing else anywhere outside /var.
        expect(array_keys(mailCaptureHostFiles($host)))->toBe([
            'etc/nginx/sites-available/mailpit-staging',
            'etc/nginx/sites-available/mailtrap-local-staging',
            'etc/nginx/sites-enabled/mailpit-staging',
            'etc/nginx/sites-enabled/mailtrap-local-staging',
            'etc/staging-mail-capture/mailpit-relay.yml',
            'etc/staging-mail-capture/mailpit.env',
            'etc/staging-mail-capture/mailtrap-local.yml',
            'etc/staging-mail-capture/versions.env',
            'etc/systemd/system/staging-mailpit.service',
            'etc/systemd/system/staging-mailtrap-local.service',
            'usr/local/bin/staging-mailpit',
            'usr/local/bin/staging-mailtrap-local',
        ]);

        // Configuration is the committed source byte for byte; the binaries
        // are the ones inside the archives whose digests were verified.
        foreach (MAIL_CAPTURE_INSTALLED_FILES as $installed => $source) {
            expect(file_get_contents($host['host'].'/'.$installed))->toBe(File::get(base_path($source)), $installed);
        }

        $archives = mailCaptureReleaseArchives()['genuine'];
        expect(file_get_contents($host['host'].'/usr/local/bin/staging-mailpit'))->toBe($archives[MAIL_CAPTURE_MAILPIT_ARCHIVE]['binary']);
        expect(file_get_contents($host['host'].'/usr/local/bin/staging-mailtrap-local'))->toBe($archives[MAIL_CAPTURE_MAILTRAP_ARCHIVE]['binary']);
        expect(is_executable($host['host'].'/usr/local/bin/staging-mailpit'))->toBeTrue();

        foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
            expect(readlink($host['host'].'/etc/nginx/sites-enabled/'.$vhost))->toBe($host['host'].'/etc/nginx/sites-available/'.$vhost);
        }

        $calls = mailCaptureCalls($host);
        $state = $host['host'].'/var/lib/staging-mail-capture';

        // Exactly the two pinned releases are downloaded — never "latest".
        $downloads = array_values(array_map(
            fn (string $call): string => substr($call, strrpos($call, ' ') + 1),
            array_filter($calls, fn (string $call): bool => str_contains($call, ' --output ')),
        ));
        expect($downloads)->toBe([
            'https://github.com/axllent/mailpit/releases/download/v1.30.5/mailpit-linux-amd64.tar.gz',
            'https://github.com/mailtrap/mailtrap-local/releases/download/v0.2.0/mailtrap-local_0.2.0_linux_amd64.tar.gz',
        ]);

        // Each service gets a system account with no login shell, homed in its
        // own state directory, which only that account may write.
        expect($calls)
            ->toContain("useradd --system --no-create-home --home-dir {$state}/mailpit --shell /usr/sbin/nologin staging-mailpit")
            ->toContain("useradd --system --no-create-home --home-dir {$state}/mailtrap-local --shell /usr/sbin/nologin staging-mailtrap-local")
            ->toContain("install -d -o staging-mailpit -g staging-mailpit -m 0750 {$state}/mailpit")
            ->toContain("install -d -o staging-mailtrap-local -g staging-mailtrap-local -m 0750 {$state}/mailtrap-local");

        // Everything it writes onto the host is root's.
        expect(mailCaptureFileInstalls($calls))->toHaveCount(10);
        foreach (mailCaptureFileInstalls($calls) as $call) {
            expect($call)->toStartWith('install -o root -g root -m 0');
        }

        // Units are verified and Nginx validated before anything is enabled;
        // the mirror comes up before Mailpit, which relays to it; Nginx reloads
        // only once both are running.
        $order = [
            'systemctl daemon-reload',
            'systemd-analyze verify',
            'nginx -t',
            'systemctl enable staging-mailtrap-local.service',
            'systemctl enable staging-mailpit.service',
            'systemctl restart staging-mailtrap-local.service',
            'systemctl restart staging-mailpit.service',
            'systemctl reload nginx',
        ];
        $positions = array_map(fn (string $prefix): int => mailCaptureCallIndex($calls, $prefix), $order);
        $sorted = $positions;
        sort($sorted);
        expect($positions)->toBe($sorted);

        // Success is reported only after the runtime health gate, and nothing
        // staged is left behind.
        expect(strpos($run['output'], 'runtime health verified for both services'))
            ->toBeLessThan(strpos($run['output'], 'apply complete'));
        expect($run['output'])->toContain('mailpit     : active');
        expect(glob($host['tmp'].'/*'))->toBe([]);
    } finally {
        removeScratchDir($host['root']);
    }
});

it('changes, restarts and reloads nothing when the host already matches', function () {
    $host = mailCaptureInstalledHost();

    try {
        $before = mailCaptureHostFiles($host);
        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(0, $run['output']);
        expect(mailCaptureHostFiles($host))->toBe($before);

        $calls = mailCaptureCalls($host);
        expect(mailCaptureFileInstalls($calls))->toBe([]);

        foreach (['useradd', 'systemctl daemon-reload', 'systemctl restart', 'systemctl reload'] as $prefix) {
            expect(array_filter($calls, fn (string $call): bool => str_starts_with($call, $prefix)))
                ->toBe([], "an unchanged host still saw: {$prefix}");
        }

        // Unchanged is not the same as healthy: the gate still runs before the
        // apply calls itself complete.
        expect($run['output'])
            ->toContain('runtime health verified for both services')
            ->toContain('apply complete');
    } finally {
        removeScratchDir($host['root']);
    }
});

it('restarts only the service whose configuration drifted, keeping the drifted copy as a backup', function () {
    $host = mailCaptureInstalledHost();
    $drifted = $host['host'].'/etc/staging-mail-capture/mailtrap-local.yml';

    try {
        file_put_contents($drifted, "max_messages: 10\n");

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(0, $run['output']);
        expect(file_get_contents($drifted))->toBe(File::get(base_path('infrastructure/config/mail-capture/mailtrap-local.yml')));

        // The replaced copy is kept, under the backup directory of this run.
        // (A relocated host nests its own path there; on a real host the
        // backup sits at <timestamp>/etc/staging-mail-capture/.)
        $backups = glob($host['host'].'/var/backups/staging-mail-capture/*'.$drifted);
        expect($backups)->toHaveCount(1);
        expect(file_get_contents($backups[0]))->toBe("max_messages: 10\n");

        $calls = mailCaptureCalls($host);
        expect($calls)
            ->toContain('systemctl restart staging-mailtrap-local.service')
            ->not->toContain('systemctl restart staging-mailpit.service')
            ->not->toContain('systemctl daemon-reload')
            ->not->toContain('systemctl reload nginx');
        expect(mailCaptureFileInstalls($calls))->toHaveCount(1);
    } finally {
        removeScratchDir($host['root']);
    }
});

// =============================================================================
// Refusing an unverifiable release
// =============================================================================

it('installs nothing from a release it cannot verify', function (array $served, array $pinned, int $exit, string $message) {
    $host = mailCaptureFreshHost($served, $pinned);

    try {
        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe($exit, $run['output']);
        expect($run['output'])
            ->toContain($message)
            ->toContain("apply failed (exit {$exit})")
            ->toContain('rollback complete')
            ->not->toContain('apply complete');

        // Not a file reached the host, no service was touched, and the staged
        // download was removed with its workspace.
        expect(mailCaptureHostFiles($host))->toBe([]);

        $calls = mailCaptureCalls($host);
        foreach (['systemctl enable', 'systemctl restart', 'systemctl daemon-reload', 'systemd-analyze', 'nginx -t'] as $prefix) {
            expect(array_filter($calls, fn (string $call): bool => str_starts_with($call, $prefix)))->toBe([], $prefix);
        }
        expect(glob($host['tmp'].'/*'))->toBe([]);
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    'a tampered Mailpit archive' => [
        [MAIL_CAPTURE_MAILPIT_ARCHIVE => 'tampered'],
        [],
        1,
        'checksum mismatch for',
    ],
    'a tampered Mailtrap Local archive, after Mailpit verified' => [
        [MAIL_CAPTURE_MAILTRAP_ARCHIVE => 'tampered'],
        [],
        1,
        'checksum mismatch for',
    ],
    'a release that cannot be downloaded' => [
        [MAIL_CAPTURE_MAILPIT_ARCHIVE => null],
        [],
        22,
        'downloading mailpit mailpit-linux-amd64.tar.gz',
    ],
    'a verified archive without the binary' => [
        [MAIL_CAPTURE_MAILPIT_ARCHIVE => 'hollow'],
        [MAIL_CAPTURE_MAILPIT_ARCHIVE => 'hollow'],
        1,
        'archive mailpit-linux-amd64.tar.gz does not contain mailpit',
    ],
]);

it('names both digests when a download does not match its pin, and stops before the next one', function () {
    $host = mailCaptureFreshHost([MAIL_CAPTURE_MAILPIT_ARCHIVE => 'tampered']);
    $archives = mailCaptureReleaseArchives();

    try {
        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])->toContain(
            'expected '.$archives['genuine'][MAIL_CAPTURE_MAILPIT_ARCHIVE]['sha256']
            .', got '.$archives['tampered'][MAIL_CAPTURE_MAILPIT_ARCHIVE]['sha256'],
        );
        expect(implode("\n", mailCaptureCalls($host)))->not->toContain(MAIL_CAPTURE_MAILTRAP_ARCHIVE);
    } finally {
        removeScratchDir($host['root']);
    }
});

// =============================================================================
// The transaction: a failed apply puts back files AND runtime state
// =============================================================================

/**
 * An installed host one release behind: Mailpit's binary and unit differ from
 * what this apply installs, so the run replaces both — and must put both back.
 */
function mailCaptureOutdatedHost(): array
{
    $host = mailCaptureInstalledHost();

    file_put_contents($host['host'].'/usr/local/bin/staging-mailpit', "#!/usr/bin/env bash\necho 'mailpit v1.29.0'\n");
    file_put_contents($host['host'].'/etc/systemd/system/staging-mailpit.service', "[Service]\nExecStart=/usr/local/bin/staging-mailpit\n");
    file_put_contents($host['host'].'/usr/local/bin/staging-mailtrap-local', "#!/usr/bin/env bash\necho 'mailtrap-local 0.1.0'\n");
    mailCaptureHealthyState($host['state']);
    file_put_contents($host['state'].'/nginx.active', 'active');

    return $host;
}

it('puts back the previous files and running services when an upgrade fails', function (callable $breakage, string $message) {
    $host = mailCaptureOutdatedHost();

    try {
        $before = mailCaptureHostFiles($host);
        $breakage($host['state']);

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain($message)
            ->toContain('rollback complete: files and runtime state restored')
            ->not->toContain('apply complete');

        // Every file — the replaced binaries and unit included — is back
        // exactly as it was, mode and content.
        expect(mailCaptureHostFiles($host))->toBe($before);

        // systemd re-read the restored unit, and both services are enabled and
        // running again, as they were before the apply.
        $calls = mailCaptureCalls($host);
        expect(array_keys($calls, 'systemctl daemon-reload'))->toHaveCount(2);
        foreach (['staging-mailtrap-local.service', 'staging-mailpit.service'] as $unit) {
            expect(file_get_contents($host['state']."/{$unit}.enabled"))->toBe('enabled');
            expect(file_get_contents($host['state']."/{$unit}.active"))->toBe('active');
        }

        // The previous binary is still in this run's backups, and nothing
        // staged is left behind.
        expect(glob($host['host'].'/var/backups/staging-mail-capture/*'.$host['host'].'/usr/local/bin/staging-mailpit'))->toHaveCount(1);
        expect(glob($host['tmp'].'/*'))->toBe([]);
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    'the new unit fails systemd-analyze verify' => [
        fn (string $state) => touch($state.'/systemd_analyze_invalid'),
        'apply failed (exit 1)',
    ],
    'the mirror never listens on 127.0.0.2:3535' => [
        fn (string $state) => file_put_contents($state.'/listeners', "127.0.0.1:3550\n127.0.0.1:1025\n127.0.0.1:8025\n"),
        'staging-mailtrap-local.service is not listening on 127.0.0.2:3535',
    ],
    'Mailpit restart-loops through the stability window' => [
        fn (string $state) => touch($state.'/staging-mailpit.service.nrestarts_step'),
        'staging-mailpit.service restarted during the 1s stability window',
    ],
]);

it('removes everything a first install wrote when a service cannot be enabled for boot', function () {
    $host = mailCaptureFreshHost();

    try {
        touch($host['state'].'/fail_enable_staging-mailpit.service');

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain('could not enable staging-mailpit.service for boot')
            ->toContain('rollback complete')
            ->toContain('nginx is not running; the restored configuration applies on next start');

        // Nothing the run installed survives it, systemd forgot the removed
        // units, and neither service was ever started.
        expect(mailCaptureHostFiles($host))->toBe([]);

        $calls = mailCaptureCalls($host);
        expect(array_keys($calls, 'systemctl daemon-reload'))->toHaveCount(2);
        expect($calls)
            ->not->toContain('systemctl restart staging-mailtrap-local.service')
            ->not->toContain('systemctl restart staging-mailpit.service')
            ->not->toContain('systemctl reload nginx');
        expect(file_get_contents($host['state'].'/staging-mailpit.service.active'))->toBe('inactive');
    } finally {
        removeScratchDir($host['root']);
    }
});

it('reports a rollback that could not restore the running state, and still fails with the original status', function () {
    $host = mailCaptureOutdatedHost();

    try {
        $before = mailCaptureHostFiles($host);
        // The new mirror will not start — and neither will the restored one.
        touch($host['state'].'/fail_restart_staging-mailtrap-local.service');

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain('apply failed (exit 1)')
            ->toContain('could not return staging-mailtrap-local.service to its previous active state')
            ->toContain('rollback INCOMPLETE')
            ->toContain('diagnostics: staging-mailtrap-local.service')
            ->toContain('manual intervention required; backups remain in '.$host['host'].'/var/backups/staging-mail-capture/')
            ->toContain('ERROR: rollback did not fully succeed (diagnostics above)')
            ->not->toContain('files and runtime state restored');

        // The files are back regardless, and the staging area is cleaned up.
        expect(mailCaptureHostFiles($host))->toBe($before);
        expect(glob($host['tmp'].'/*'))->toBe([]);
    } finally {
        removeScratchDir($host['root']);
    }
});

it('returns services an operator had turned off to exactly that state', function (bool $disableFails, string $outcome) {
    $host = mailCaptureOutdatedHost();
    $state = $host['state'];

    try {
        // Mailpit disabled and stopped, the mirror masked: the apply enables
        // and starts both, then fails its health gate.
        file_put_contents($state.'/staging-mailpit.service.enabled', 'disabled');
        file_put_contents($state.'/staging-mailtrap-local.service.enabled', 'masked');
        foreach (['staging-mailpit.service', 'staging-mailtrap-local.service'] as $unit) {
            file_put_contents("{$state}/{$unit}.active", 'inactive');
        }
        file_put_contents($state.'/apis', "http://127.0.0.1:3550/api/v1/version\n");
        if ($disableFails) {
            touch($state.'/fail_disable_staging-mailpit.service');
        }

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain('pre-apply state: staging-mailpit.service (active=inactive, enabled=disabled)')
            ->toContain('staging-mailpit.service API http://127.0.0.1:8025/api/v1/info did not respond')
            ->toContain($outcome);

        $calls = mailCaptureCalls($host);
        expect($calls)
            ->toContain('systemctl mask staging-mailtrap-local.service')
            ->toContain('systemctl disable staging-mailpit.service');

        // Both are stopped again, and the mirror is masked again.
        expect(file_get_contents($state.'/staging-mailtrap-local.service.enabled'))->toBe('masked');
        foreach (['staging-mailpit.service', 'staging-mailtrap-local.service'] as $unit) {
            expect(file_get_contents("{$state}/{$unit}.active"))->toBe('inactive');
        }
        expect(file_get_contents($state.'/staging-mailpit.service.enabled'))->toBe($disableFails ? 'enabled' : 'disabled');
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    'restored' => [false, 'rollback complete: files and runtime state restored'],
    'Mailpit cannot be disabled again' => [true, 'rollback could not restore the boot state (disabled) of staging-mailpit.service'],
]);

it('never reloads Nginx with a configuration that still fails nginx -t after the rollback', function () {
    $host = mailCaptureFreshHost();

    try {
        file_put_contents($host['state'].'/nginx.active', 'active');
        touch($host['state'].'/nginx_invalid');

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain('nginx: configuration file test failed')
            ->toContain('restored nginx configuration is invalid; nginx was NOT reloaded')
            ->toContain('rollback INCOMPLETE');

        // The vhosts this run added are gone again, and Nginx was validated
        // twice — and reloaded never.
        expect(mailCaptureHostFiles($host))->toBe([]);
        $calls = mailCaptureCalls($host);
        expect(count(array_filter($calls, fn (string $call): bool => $call === 'nginx -t')))->toBeGreaterThanOrEqual(2);
        expect($calls)->not->toContain('systemctl reload nginx');
    } finally {
        removeScratchDir($host['root']);
    }
});

// =============================================================================
// Refusing before anything changes
// =============================================================================

it('refuses --apply without root, before touching the host, unless the seam is allowed', function (array $env) {
    $host = mailCaptureFreshHost();

    try {
        $run = mailCaptureInstall($host, ['--apply'], $env);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])->toContain('ERROR: this command must be executed as root');
        expect(mailCaptureCalls($host))->toBe([]);
        expect(is_dir($host['host'].'/var'))->toBeFalse();
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    // The uid override is ignored without the opt-in, so the real (non-root)
    // uid is what the gate sees.
    'a root uid override without the opt-in' => [['RATEGURU_ALLOW_TEST_OVERRIDES' => 'false']],
    'an allowed non-root uid' => [['RATEGURU_MAILCAPTURE_EUID' => '1000']],
])->skip(fn () => getmyuid() === 0, 'proves the root gate, so it must run as a non-root user');

it('refuses an unsupported platform before changing anything', function (string $file, string $value, string $message) {
    $host = mailCaptureFreshHost();

    try {
        file_put_contents($host['state'].'/'.$file, $value."\n");

        $run = mailCaptureInstall($host);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])->toContain($message);
        expect(mailCaptureCalls($host))->toBe([]);
        expect(is_dir($host['host'].'/var'))->toBeFalse();
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    'not Linux' => ['uname-s', 'Darwin', 'mail capture is only supported on Linux'],
    'an architecture with no release' => ['uname-m', 'riscv64', 'unsupported architecture: riscv64 (supported: x86_64/amd64, aarch64/arm64)'],
]);

it('requires exactly one known mode', function (array $arguments, int $exit, string $message) {
    $host = mailCaptureFreshHost();

    try {
        $run = mailCaptureInstall($host, $arguments);

        expect($run['exit'])->toBe($exit, $run['output']);
        expect($run['output'])
            ->toContain('Usage: install-mail-capture --check | --apply')
            ->toContain($message);
        expect(mailCaptureCalls($host))->toBe([]);
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    'help' => [['--help'], 0, '--apply   Install and activate the mail-capture services (requires root).'],
    'no mode' => [[], 1, 'ERROR: one of --check or --apply is required'],
    'an unknown argument' => [['--force'], 1, 'ERROR: unknown argument: --force'],
]);

// =============================================================================
// --check judges the committed configuration
// =============================================================================

it('refuses committed configuration that would break the loopback-only, pinned contract', function (string $path, callable $tamper, string $message) {
    $host = mailCaptureFreshHost([], null);
    $file = $host['repo'].'/infrastructure/config/'.$path;

    try {
        $contents = $tamper(File::get(base_path('infrastructure/config/'.$path)));
        unlink($file);

        if ($contents !== null) {
            file_put_contents($file, $contents);
        }

        $run = mailCaptureInstall($host, ['--check']);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain($message)
            ->not->toContain('check passed');
        expect(mailCaptureCalls($host))->toBe([]);
    } finally {
        removeScratchDir($host['root']);
    }
})->with([
    'Mailpit pinned to latest' => [
        'mail-capture/versions.env',
        fn (string $source) => preg_replace('/^MAILPIT_VERSION=.*$/m', 'MAILPIT_VERSION=latest', $source),
        "MAILPIT_VERSION must be pinned, not 'latest'",
    ],
    'Mailtrap Local pinned to a partial version' => [
        'mail-capture/versions.env',
        fn (string $source) => preg_replace('/^MAILTRAP_LOCAL_VERSION=.*$/m', 'MAILTRAP_LOCAL_VERSION=0.2', $source),
        'MAILTRAP_LOCAL_VERSION is not a valid semantic version: 0.2',
    ],
    'Mailpit SMTP on the wildcard address' => [
        'mail-capture/mailpit.env',
        fn (string $source) => str_replace('MP_SMTP_BIND_ADDR=127.0.0.1:1025', 'MP_SMTP_BIND_ADDR=0.0.0.0:1025', $source),
        'mailpit.env does not bind SMTP to loopback 127.0.0.1:1025',
    ],
    // The relay path is what Mailpit reads on the host, so the message names
    // the host path even while the host is relocated.
    'Mailpit reading its relay target from elsewhere' => [
        'mail-capture/mailpit.env',
        fn (string $source) => preg_replace('/^MP_SMTP_RELAY_CONFIG=.*$/m', 'MP_SMTP_RELAY_CONFIG=/etc/mailpit/relay.yml', $source),
        'mailpit.env does not reference the relay config /etc/staging-mail-capture/mailpit-relay.yml',
    ],
    'the relay dialing 127.0.0.1' => [
        'mail-capture/mailpit-relay.yml',
        fn (string $source) => preg_replace('/^host:.*$/m', 'host: 127.0.0.1', $source),
        'mailpit-relay.yml relay host is not loopback 127.0.0.2',
    ],
    'Mailtrap Local SMTP back on 127.0.0.1' => [
        'systemd/staging-mailtrap-local.service',
        fn (string $source) => str_replace('--smtp-listen 127.0.0.2:3535', '--smtp-listen 127.0.0.1:3535', $source),
        'mailtrap-local unit does not bind SMTP to loopback 127.0.0.2:3535',
    ],
    'no pin for this architecture' => [
        'mail-capture/SHA256SUMS',
        fn (string $source) => preg_replace('/^.*mailtrap-local_0\.2\.0_linux_amd64\.tar\.gz\R/m', '', $source),
        'no valid SHA-256 pinned for mailtrap-local_0.2.0_linux_amd64.tar.gz',
    ],
    'a missing vhost' => [
        'nginx/mailtrap-local-staging',
        fn (string $source) => null,
        'missing required committed file: ',
    ],
]);

it('checks the arm64 pins on an aarch64 host', function () {
    $host = mailCaptureFreshHost([], null);

    try {
        file_put_contents($host['state'].'/uname-m', "aarch64\n");

        $run = mailCaptureInstall($host, ['--check']);

        expect($run['exit'])->toBe(0, $run['output']);
        expect($run['output'])
            ->toContain('architecture: linux/arm64')
            ->toContain('pinned checksums present for mailpit-linux-arm64.tar.gz and mailtrap-local_0.2.0_linux_arm64.tar.gz')
            ->toContain('check passed');
    } finally {
        removeScratchDir($host['root']);
    }
});

// =============================================================================
// What status-mail-capture reads back
// =============================================================================

it('leaves a host that status-mail-capture reads back as installed and serving', function () {
    $host = mailCaptureInstalledHost();

    try {
        $status = mailCaptureStatus($host, $host['host']);

        expect($status['exit'])->toBe(0, $status['output']);
        expect($status['output'])
            ->toMatch('/^Pinned Mailpit:\s+1\.30\.5$/m')
            ->toMatch('/^Pinned Mailtrap Local:\s+0\.2\.0$/m')
            ->toMatch('/^Mailpit binary:\s+mailpit v1\.30\.5$/m')
            ->toMatch('/^Mailtrap binary:\s+mailtrap-local 0\.2\.0$/m')
            ->toMatch('/^Mailpit \(canonical\):\s+active=active enabled=enabled$/m')
            ->toMatch('/^Mailtrap Local \(mirror\):\s+active=active enabled=enabled$/m')
            ->toContain(' at '.$host['host'].'/var/lib/staging-mail-capture/mailpit')
            ->toMatch('/^Mailpit vhost:\s+present$/m')
            ->toMatch('/^Mailtrap vhost:\s+present$/m');
    } finally {
        removeScratchDir($host['root']);
    }
});
