<?php

use Illuminate\Support\Facades\File;

/**
 * Prepare Host: infrastructure/scripts/install-target-prerequisites — the safe
 * delivery of operator-supplied external material onto a host, and the refusal
 * to overwrite or rotate anything already there.
 *
 * Every test runs the real, shipped script as a subprocess against a scratch
 * filesystem root, through the RATEGURU_TARGETPREREQ_* overrides it honors only
 * alongside RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here touches /etc.
 */
function itpScript(): string
{
    return base_path('infrastructure/scripts/install-target-prerequisites');
}

function itpScratchDir(): string
{
    $dir = sys_get_temp_dir().'/target-prereq-'.uniqid('', true).'-'.getmypid();
    expect(@mkdir($dir.'/root/material', 0o700, true))->toBeTrue();
    chmod($dir.'/root/material', 0o700);

    return $dir;
}

function itpCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{0: int, 1: string}
 */
function itpRun(string $scratch, array $arguments, array $environment = []): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', itpScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        array_merge([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_TARGETPREREQ_EUID' => '0',
            'RATEGURU_TARGETPREREQ_FS_ROOT' => $scratch,
            // The scratch tree has no rateguru-staging, www-data or root-owned
            // files, so ownership comparison is off by default. The test that
            // exercises it turns it on, and every row then mismatches — the
            // first one reached is shared/.env, declared
            // rateguru-staging:rateguru-staging.
            'RATEGURU_TARGETPREREQ_ENFORCE_OWNERSHIP' => 'false',
        ], $environment),
    );

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** @param  list<string>  $names */
function itpSupply(string $scratch, array $names, string $content = 'material-content'): void
{
    foreach ($names as $name) {
        file_put_contents($scratch.'/root/material/'.$name, $content."-{$name}\n");
        chmod($scratch.'/root/material/'.$name, 0o600);
    }
}

/**
 * A genuine certbot layout for one certificate: numbered files in `archive/`
 * and the two stable links in `live/`, with certbot's own modes.
 */
function itpCertbotCertificate(string $scratch, string $certificate): void
{
    mkdir($scratch.'/etc/letsencrypt/archive/'.$certificate, 0o755, true);
    mkdir($scratch.'/etc/letsencrypt/live/'.$certificate, 0o755, true);

    foreach (['fullchain' => 0o644, 'privkey' => 0o600] as $leaf => $mode) {
        $archived = $scratch.'/etc/letsencrypt/archive/'.$certificate.'/'.$leaf.'1.pem';

        file_put_contents($archived, "certbot-{$leaf}\n");
        chmod($archived, $mode);

        symlink(
            '../../archive/'.$certificate.'/'.$leaf.'1.pem',
            $scratch.'/etc/letsencrypt/live/'.$certificate.'/'.$leaf.'.pem',
        );
    }
}

/**
 * Every host-scope destination present with the mode the table declares, so a
 * test can make exactly one of them wrong and see that one reported.
 */
function itpValidHostScope(string $scratch): void
{
    itpCertbotCertificate($scratch, 'rateguru.staging.myprojects.pp.ua');
    itpCertbotCertificate($scratch, 'staging-mail-capture');

    mkdir($scratch.'/etc/nginx', 0o755, true);
    file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "hashes\n");
    chmod($scratch.'/etc/nginx/rateguru-staging.htpasswd', 0o640);

    foreach ([
        '/etc/letsencrypt/options-ssl-nginx.conf',
        '/etc/letsencrypt/ssl-dhparams.pem',
    ] as $shared) {
        file_put_contents($scratch.$shared, "shared\n");
        chmod($scratch.$shared, 0o644);
    }
}

/** The identities and directories install-bootstrap-host-layout creates. */
function itpCreateTargetDirectories(string $scratch): void
{
    foreach ([
        '/home/www/rateguru/staging/shared',
        '/home/deploy-rateguru-staging/.ssh',
        '/root/.config/rclone',
    ] as $dir) {
        mkdir($scratch.$dir, 0o755, true);
    }
}

const ITP_HOST_MATERIAL = [
    'basic-auth',
    'tls-certificate',
    'tls-private-key',
    'nginx-tls-options',
    'tls-dhparams',
    'mail-tls-certificate',
    'mail-tls-private-key',
];

const ITP_TARGET_MATERIAL = [
    'laravel-env',
    'deploy-authorized-keys',
    'rclone-config',
];

// =============================================================================
// The prerequisite set is derived, never hard-coded in GitHub
// =============================================================================

it('derives every Nginx-referenced destination from the committed vhosts', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(1, 'a bare host satisfies nothing yet');

        // Exactly the files install-bootstrap-services fails closed on, with
        // the paths taken straight out of the committed vhosts.
        foreach ([
            '/etc/nginx/rateguru-staging.htpasswd',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem',
            '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem',
            '/etc/letsencrypt/live/staging-mail-capture/privkey.pem',
            '/etc/letsencrypt/options-ssl-nginx.conf',
            '/etc/letsencrypt/ssl-dhparams.pem',
        ] as $destination) {
            expect($output)->toContain($destination);
        }

        expect($output)->toContain('missing 7');
    } finally {
        itpCleanup($scratch);
    }
});

it('uses the same external-prerequisite contract install-bootstrap-services gates on', function () {
    $prerequisites = File::get(itpScript());
    $services = File::get(base_path('infrastructure/scripts/install-bootstrap-services'));

    // If the two ever diverged, preparation would produce a host that 5.4
    // still refuses to converge. The directive set and the glob exclusion are
    // therefore pinned together.
    $awk = "awk '\$1 ~ /^(auth_basic_user_file|ssl_certificate|ssl_certificate_key|ssl_dhparam|include)\$/ { print \$1, \$2 }'";

    expect($prerequisites)->toContain($awk);
    expect($services)->toContain($awk);

    foreach ([$prerequisites, $services] as $source) {
        expect($source)->toContain("*'*'*|*'?'*|*'['*) ;;");
    }

    // And the same two shared mail-capture vhosts, listed rather than globbed.
    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        expect($prerequisites)->toContain('/'.$vhost.'"');
        expect($services)->toContain('/'.$vhost.'"');
    }
});

it('derives target-scope destinations from the registry', function () {
    $scratch = itpScratchDir();

    try {
        [, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'target']);

        expect($output)->toContain('/home/www/rateguru/staging/shared/.env');
        expect($output)->toContain('/home/deploy-rateguru-staging/.ssh/authorized_keys');
        expect($output)->toContain('/root/.config/rclone/rclone.conf');
        expect($output)->toContain('missing 3');
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// Installing what is absent
// =============================================================================

it('installs missing material with the right modes and never reads its content', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(0);
        expect($output)->toContain('content never read or logged');
        expect($output)->not->toContain('material-content');

        $expectedModes = [
            '/etc/nginx/rateguru-staging.htpasswd' => '0640',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem' => '0644',
            '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem' => '0600',
            '/etc/letsencrypt/live/staging-mail-capture/privkey.pem' => '0600',
            '/etc/letsencrypt/ssl-dhparams.pem' => '0644',
        ];

        foreach ($expectedModes as $path => $mode) {
            expect(is_file($scratch.$path))->toBeTrue("{$path} should have been installed");
            expect(substr(sprintf('%04o', fileperms($scratch.$path)), -4))->toBe($mode,
                "{$path} must be installed with mode {$mode}");
        }
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses to install target-scope material before host bootstrap created its directories', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('its owner is created by host bootstrap');

        // A directory created here with the wrong owner is a directory slice
        // 5.3 then has to disagree with, so nothing was created.
        expect(is_dir($scratch.'/home/www/rateguru/staging/shared'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('installs target-scope material once its directories exist', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        [$exit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(0);
        expect(substr(sprintf('%04o', fileperms($scratch.'/home/www/rateguru/staging/shared/.env')), -4))->toBe('0640');
        expect(substr(sprintf('%04o', fileperms($scratch.'/home/deploy-rateguru-staging/.ssh/authorized_keys')), -4))->toBe('0600');
        expect(substr(sprintf('%04o', fileperms($scratch.'/root/.config/rclone/rclone.conf')), -4))->toBe('0600');
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// Safe existing-file semantics — the heart of the contract
// =============================================================================

it('preserves existing material and does not even rewrite an identical file', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$firstExit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);
        expect($firstExit)->toBe(0);

        $key = $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem';
        $inodeBefore = fileinode($key);

        [$secondExit, $secondOutput] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($secondExit)->toBe(0);
        expect(substr_count($secondOutput, 'already present and identical to the supplied material'))->toBe(7);
        expect($secondOutput)->not->toContain('INSTALLED');

        // Not rewritten at all: the file on disk is literally the same file.
        expect(fileinode($key))->toBe($inodeBefore);
    } finally {
        itpCleanup($scratch);
    }
});

it('preserves an existing file when no material is supplied for it', function () {
    $scratch = itpScratchDir();

    try {
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "live-hashes\n");

        [, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'host']);

        expect($output)->toContain('already present; left untouched');
        expect(File::get($scratch.'/etc/nginx/rateguru-staging.htpasswd'))->toBe("live-hashes\n");
    } finally {
        itpCleanup($scratch);
    }
});

it('fails closed rather than rotating a secret that differs from the supplied material', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);
        itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        $key = $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem';
        $liveContent = File::get($key);

        // A rotated secret in GitHub, against a host still holding the old one.
        file_put_contents($scratch.'/root/material/tls-private-key', "A-DIFFERENT-PRIVATE-KEY\n");

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('DIFFERS from the supplied material');
        expect($output)->toContain('rotation is a separate deliberate operation');

        // The live key is untouched — this is the whole point.
        expect(File::get($key))->toBe($liveContent);
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses before installing anything when an existing prerequisite has drifted', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        // One row already present but with the wrong mode, every other row
        // absent. Installing the absent ones first and only then discovering
        // the drift would leave the host half-converged on a path that fails
        // closed by design.
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "material-content-basic-auth\n");
        chmod($scratch.'/etc/nginx/rateguru-staging.htpasswd', 0o600);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('has mode 600, expected 0640');
        expect($output)->not->toContain('INSTALLED');

        expect(is_dir($scratch.'/etc/letsencrypt'))->toBeFalse('nothing may be installed before the drift is reported');
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses the entire run on a conflict, before installing anything else', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        // One destination already present and different; the rest absent.
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "existing-and-different\n");

        [$exit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);

        // Nothing was installed: a half-converged host with one new secret
        // beside a conflicting old one is worse than an unconverged one.
        expect(is_dir($scratch.'/etc/letsencrypt'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('never discloses secret content, length or a digest in a conflict diagnostic', function () {
    $scratch = itpScratchDir();

    try {
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/etc/nginx/rateguru-staging.htpasswd', "LIVE-SECRET-VALUE\n");
        file_put_contents($scratch.'/root/material/basic-auth', "SUPPLIED-SECRET-VALUE\n");

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->not->toContain('LIVE-SECRET-VALUE');
        expect($output)->not->toContain('SUPPLIED-SECRET-VALUE');

        // No diff, no hash, no byte offset either.
        expect($output)->not->toMatch('/\bdiffer.* byte \d+/');
        expect($output)->not->toMatch('/[0-9a-f]{32,}/');
    } finally {
        itpCleanup($scratch);
    }
});

it('generates nothing when material is absent', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('absent and no material supplied');
        expect($output)->toContain('it is never generated here');
        expect(is_dir($scratch.'/etc'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('accepts a real certbot layout and never replaces it', function () {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(0, $output);

        // And a re-run with differing material still refuses to write through
        // the link.
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1, 'differing material must still fail closed');
        expect(is_link($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem'))->toBeTrue();
        expect(File::get($scratch.'/etc/letsencrypt/archive/rateguru.staging.myprojects.pp.ua/privkey1.pem'))
            ->toBe("certbot-privkey\n");
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a TLS link repointed at another certificate or at a non-archive file', function () {
    foreach ([
        'another certificate' => '../../archive/other-certificate/privkey1.pem',
        'a non-archive file in the tree' => '../../ssl-dhparams.pem',
        'somewhere outside the tree entirely' => '/attacker-key.pem',
    ] as $case => $linkTarget) {
        $scratch = itpScratchDir();

        try {
            itpValidHostScope($scratch);

            mkdir($scratch.'/etc/letsencrypt/archive/other-certificate', 0o755, true);
            file_put_contents($scratch.'/etc/letsencrypt/archive/other-certificate/privkey1.pem', "other\n");
            chmod($scratch.'/etc/letsencrypt/archive/other-certificate/privkey1.pem', 0o600);
            file_put_contents($scratch.'/attacker-key.pem', "attacker\n");
            chmod($scratch.'/attacker-key.pem', 0o600);

            // "It is a TLS row, and it points at some regular file" is not
            // enough: the link has to resolve to its OWN certificate's
            // numbered archive file, or a substituted key would be blessed as
            // correct state.
            $key = $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem';
            unlink($key);
            symlink($linkTarget, $key);

            [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'host']);

            expect($exit)->toBe(1, "a key repointed at {$case} must be refused");
            expect($output)->toContain('does not resolve to its own /etc/letsencrypt/archive/<certificate>/ file');
        } finally {
            itpCleanup($scratch);
        }
    }
});

it('refuses a TLS link at a destination certbot would never publish', function () {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);

        // The four TLS logical names may only be links at
        // live/<certificate>/{fullchain,privkey}.pem. The shared dhparams file
        // is a TLS row certbot does not publish as a link at all.
        file_put_contents($scratch.'/etc/letsencrypt/real-dhparams.pem', "dh\n");
        chmod($scratch.'/etc/letsencrypt/real-dhparams.pem', 0o644);
        unlink($scratch.'/etc/letsencrypt/ssl-dhparams.pem');
        symlink($scratch.'/etc/letsencrypt/real-dhparams.pem', $scratch.'/etc/letsencrypt/ssl-dhparams.pem');

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a symlinked destination for anything but ACME-published TLS material', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);

        // A target's shared/ directory is writable by the application's own
        // runtime user, so blessing a link there would let a compromised
        // runtime point .env at material it controls and have preparation call
        // it present and correct.
        file_put_contents($scratch.'/attacker-env', "DB_PASSWORD=owned\n");
        chmod($scratch.'/attacker-env', 0o600);
        symlink($scratch.'/attacker-env', $scratch.'/home/www/rateguru/staging/shared/.env');

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);

        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');

        [$exit, $output] = itpRun($scratch, ['--check', '--target', 'staging-main', '--scope', 'target']);
        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');

        // Refused, never followed and never replaced.
        expect(is_link($scratch.'/home/www/rateguru/staging/shared/.env'))->toBeTrue();
        expect(File::get($scratch.'/attacker-env'))->toBe("DB_PASSWORD=owned\n");
    } finally {
        itpCleanup($scratch);
    }
});

it('enforces the declared mode, not merely the absence of world-read', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        $env = $scratch.'/home/www/rateguru/staging/shared/.env';

        // Wider than declared: an exposure.
        chmod($env, 0o644);

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);

        expect($exit)->toBe(1);
        expect($output)->toContain('has mode 644, expected 0640');
        expect($output)->toContain('chmod 0640 /home/www/rateguru/staging/shared/.env');

        // Narrower than declared is a failure too, and this is the case a
        // world-read check misses entirely: the secret is perfectly protected
        // and the runtime group can no longer read it, so PHP-FPM and the
        // queue worker fail on their first real use.
        chmod($env, 0o600);

        [$exit, $output] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);

        expect($exit)->toBe(1);
        expect($output)->toContain('has mode 600, expected 0640');

        chmod($env, 0o640);

        [$exit] = itpRun($scratch, ['--verify', '--target', 'staging-main', '--scope', 'target']);
        expect($exit)->toBe(0);
    } finally {
        itpCleanup($scratch);
    }
});

it('enforces the declared owner and group, which is what makes the file readable at all', function () {
    $scratch = itpScratchDir();

    try {
        itpCreateTargetDirectories($scratch);
        itpSupply($scratch, ITP_TARGET_MATERIAL);

        itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'target',
            '--material-dir', '/root/material',
        ]);

        // In a scratch tree every file belongs to whoever ran the suite, so
        // turning ownership comparison on is enough to exercise the mismatch
        // path against the real declared owner — here the runtime identity the
        // application actually reads .env as.
        [$exit, $output] = itpRun(
            $scratch,
            ['--verify', '--target', 'staging-main', '--scope', 'target'],
            ['RATEGURU_TARGETPREREQ_ENFORCE_OWNERSHIP' => 'true'],
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('expected rateguru-staging:rateguru-staging');
        expect($output)->toContain('the declared owner is the account that has to read it');
        expect($output)->toContain('chown rateguru-staging:rateguru-staging');
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a dangling symlink even where a symlink is otherwise allowed', function () {
    $scratch = itpScratchDir();

    try {
        // The ACME certificate rows may legitimately be links — but only ones
        // that resolve to a real file. A dangling link is broken state this
        // must never write through or paper over.
        mkdir($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua', 0o755, true);
        symlink(
            $scratch.'/does-not-exist',
            $scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem',
        );

        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('does not resolve to a regular file');
        expect(is_link($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem'))->toBeTrue();
        expect(file_exists($scratch.'/does-not-exist'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses a symlinked htpasswd, where no link is allowed at all', function () {
    $scratch = itpScratchDir();

    try {
        mkdir($scratch.'/etc/nginx', 0o755, true);
        file_put_contents($scratch.'/decoy', "decoy\n");
        symlink($scratch.'/decoy', $scratch.'/etc/nginx/rateguru-staging.htpasswd');

        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('only ACME-published certificate/key material');
        expect(File::get($scratch.'/decoy'))->toBe("decoy\n");
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// The material directory itself
// =============================================================================

it('refuses a material directory readable by anyone but root', function () {
    $scratch = itpScratchDir();

    try {
        chmod($scratch.'/root/material', 0o755);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'staging-main', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('must not be readable by group or other');
    } finally {
        itpCleanup($scratch);
    }
});

it('never consults supplied material during --verify', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, [
            '--verify', '--target', 'staging-main', '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('--verify never consults supplied material');
    } finally {
        itpCleanup($scratch);
    }
});

// =============================================================================
// Lifecycle and root
// =============================================================================

it('rejects a lifecycle=planned target before computing a single destination', function () {
    $scratch = itpScratchDir();

    try {
        itpSupply($scratch, ITP_HOST_MATERIAL);

        [$exit, $output] = itpRun($scratch, [
            '--apply', '--target', 'tits-guru', '--scope', 'host',
            '--material-dir', '/root/material',
        ]);

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned, not active');
        expect(is_dir($scratch.'/etc'))->toBeFalse();
    } finally {
        itpCleanup($scratch);
    }
});

it('never sources the installed common, so it works on a clean host', function () {
    $source = File::get(itpScript());

    // common aborts when /home/www/rateguru/config/deployment.conf is
    // unreadable, and the host scope runs before any of that exists. Same
    // deliberate decision bootstrap-host-preflight documents.
    expect($source)->not->toContain('/home/www/rateguru/bin/common');
    // The registry and the vhost sources are read from the config directory
    // beside the script — infrastructure/config in a checkout, the installed
    // config root on a host — so the same table serves both layouts.
    expect($source)->toContain('${CONFIG_DIR}/deployment-targets.json');
    expect($source)->toContain('infrastructure/config');
    expect($source)->toContain('validate --file');
});

it('never eval-sources any operator-authored file', function () {
    $source = File::get(itpScript());

    expect($source)->not->toMatch('/\beval\b/');
    expect($source)->not->toMatch('/^\s*source\s/m');
    expect($source)->not->toMatch('/^\s*\.\s+["$]/m');
});

// =============================================================================
// Recovery material: listing, capturing and judging the host-scope vocabulary
// =============================================================================

/** The host-scope logical names of staging-main, as the committed vhosts declare them. */
function itpHostScopeNames(): array
{
    return ITP_HOST_MATERIAL;
}

/**
 * Builds a recovery material archive the way a test needs it wrong: $prepare
 * populates a fresh stage directory, then tar archives $members (a list, or a
 * callable given the stage path) with $flags, from the stage directory (plus
 * $subdirectory) unless $fromStage is false. Returns the archive path.
 */
function itpArchive(string $scratch, callable $prepare, array|callable $members, string $flags = '', bool $fromStage = true, string $subdirectory = ''): string
{
    $stage = $scratch.'/archive-stage-'.uniqid('', true);
    mkdir($stage, 0o700, true);
    $prepare($stage);

    $list = is_callable($members) ? $members($stage) : $members;
    $archive = $scratch.'/material-'.uniqid('', true).'.tar.gz';

    $command = 'tar '.$flags.' -czf '.escapeshellarg($archive)
        .($fromStage ? ' -C '.escapeshellarg($stage.$subdirectory) : '')
        .' -- '.implode(' ', array_map('escapeshellarg', $list)).' 2>&1';

    exec($command, $out, $exit);
    expect($exit)->toBe(0, "could not build the archive:\n".implode("\n", $out));

    return $archive;
}

/** Writes a regular file for each name in the stage. */
function itpRegularMembers(array $names): callable
{
    return static function (string $stage) use ($names): void {
        foreach ($names as $name) {
            file_put_contents($stage.'/'.$name, "content-of-{$name}\n");
        }
    };
}

it('lists exactly the host-scope logical names of the target, and nothing else', function () {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, ['--list-material-names', '--target', 'staging-main', '--scope', 'host']);

        expect($exit)->toBe(0, $output);

        $names = array_values(array_filter(preg_split('/\R/', $output)));
        sort($names);

        $expected = itpHostScopeNames();
        sort($expected);

        // Names only: no destination, no owner, no mode, no prose — a caller
        // reads this as a vocabulary.
        expect($names)->toBe($expected);
        expect($output)->not->toContain('/etc/');

        // The target scope is the other half of the table and is never
        // recovery material: the environment file comes from the backup's own
        // environment.env, and the deploy key from the runner. Its names are
        // listable, and none of them is a host-scope name.
        [$exit, $output] = itpRun($scratch, ['--list-material-names', '--target', 'staging-main', '--scope', 'target']);
        expect($exit)->toBe(0, $output);

        $targetNames = array_values(array_filter(preg_split('/\R/', $output)));
        expect($targetNames)->toContain('laravel-env');
        expect(array_intersect($targetNames, itpHostScopeNames()))->toBe([]);
    } finally {
        itpCleanup($scratch);
    }
});

it('captures every host-scope prerequisite under its logical name, dereferencing certbot links, root-only, without reading content into its output', function () {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);
        mkdir($scratch.'/capture', 0o700, true);
        chmod($scratch.'/capture', 0o700);

        [$exit, $output] = itpRun($scratch, ['--capture', '--target', 'staging-main', '--scope', 'host', '--output-dir', $scratch.'/capture']);

        expect($exit)->toBe(0, $output);

        $captured = array_values(array_diff(scandir($scratch.'/capture'), ['.', '..']));
        sort($captured);
        $expected = itpHostScopeNames();
        sort($expected);

        expect($captured)->toBe($expected);

        foreach ($captured as $name) {
            $path = $scratch.'/capture/'.$name;
            expect(is_link($path))->toBeFalse("{$name} must be a regular file, never a link");
            expect(is_file($path))->toBeTrue();
            expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');
        }

        // The certbot destinations on the host are links; the capture carries
        // what they resolve to.
        expect(File::get($scratch.'/capture/tls-certificate'))->toBe("certbot-fullchain\n");
        expect(File::get($scratch.'/capture/tls-private-key'))->toBe("certbot-privkey\n");
        expect(File::get($scratch.'/capture/basic-auth'))->toBe("hashes\n");

        // Names and sources are reported; content, length and digest are not.
        expect($output)
            ->toContain('CAPTURED tls-private-key')
            ->toContain('captured 7 host-scope prerequisites for staging-main')
            ->toContain('content never read, hashed or logged')
            ->not->toContain('certbot-privkey')
            ->not->toContain('hashes')
            ->not->toMatch('/[0-9a-f]{64}/');

        // A capture is read-only on the host: nothing under the filesystem
        // root changed.
        expect(File::get($scratch.'/etc/nginx/rateguru-staging.htpasswd'))->toBe("hashes\n");
        expect(is_link($scratch.'/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem'))->toBeTrue();
    } finally {
        itpCleanup($scratch);
    }
});

it('captures nothing at all when any host-scope prerequisite is missing or unsafe', function () {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);
        unlink($scratch.'/etc/nginx/rateguru-staging.htpasswd');
        mkdir($scratch.'/capture', 0o700, true);
        chmod($scratch.'/capture', 0o700);

        [$exit, $output] = itpRun($scratch, ['--capture', '--target', 'staging-main', '--scope', 'host', '--output-dir', $scratch.'/capture']);

        expect($exit)->toBe(1);
        expect($output)->toContain('basic-auth');
        expect(array_diff(scandir($scratch.'/capture'), ['.', '..']))->toBe([], 'a refused capture writes nothing');
    } finally {
        itpCleanup($scratch);
    }

    $scratch = itpScratchDir();

    try {
        // A destination that is a link where no link is allowed: the same
        // safety rule --verify applies, and a capture that copied through it
        // would carry whatever the link pointed at.
        itpValidHostScope($scratch);
        unlink($scratch.'/etc/nginx/rateguru-staging.htpasswd');
        symlink($scratch.'/etc/letsencrypt/ssl-dhparams.pem', $scratch.'/etc/nginx/rateguru-staging.htpasswd');
        mkdir($scratch.'/capture', 0o700, true);
        chmod($scratch.'/capture', 0o700);

        [$exit, $output] = itpRun($scratch, ['--capture', '--target', 'staging-main', '--scope', 'host', '--output-dir', $scratch.'/capture']);

        expect($exit)->toBe(1);
        expect($output)->toContain('basic-auth');
        expect(array_diff(scandir($scratch.'/capture'), ['.', '..']))->toBe([]);
    } finally {
        itpCleanup($scratch);
    }
});

it('demands a root-only, empty, absolute output directory for a capture', function (callable $arrange, string $expected) {
    $scratch = itpScratchDir();

    try {
        itpValidHostScope($scratch);
        $outputDir = $arrange($scratch);

        [$exit, $output] = itpRun($scratch, ['--capture', '--target', 'staging-main', '--scope', 'host', '--output-dir', $outputDir]);

        expect($exit)->toBe(1);
        expect($output)->toContain($expected);
    } finally {
        itpCleanup($scratch);
    }
})->with([
    'group-readable' => [
        function (string $scratch): string {
            mkdir($scratch.'/capture', 0o750, true);
            chmod($scratch.'/capture', 0o750);

            return $scratch.'/capture';
        },
        '--output-dir must be mode 0700',
    ],
    'not empty' => [
        function (string $scratch): string {
            mkdir($scratch.'/capture', 0o700, true);
            chmod($scratch.'/capture', 0o700);
            touch($scratch.'/capture/leftover');

            return $scratch.'/capture';
        },
        '--output-dir must be empty',
    ],
    'relative' => [
        fn (string $scratch): string => 'capture',
        '--output-dir must be an absolute path',
    ],
    'a symlink' => [
        function (string $scratch): string {
            mkdir($scratch.'/real-capture', 0o700, true);
            chmod($scratch.'/real-capture', 0o700);
            symlink($scratch.'/real-capture', $scratch.'/capture');

            return $scratch.'/capture';
        },
        '--output-dir must not be a symlink',
    ],
    'missing' => [
        fn (string $scratch): string => $scratch.'/capture',
        '--output-dir is not a directory',
    ],
]);

it('accepts a recovery material archive of exactly the host-scope names as top-level regular files', function () {
    $scratch = itpScratchDir();

    try {
        $archive = itpArchive($scratch, itpRegularMembers(itpHostScopeNames()), itpHostScopeNames());

        [$exit, $output] = itpRun($scratch, ['--validate-recovery-material', '--target', 'staging-main', '--scope', 'host', '--archive', $archive]);

        expect($exit)->toBe(0, $output);

        $lines = array_values(array_filter(preg_split('/\R/', $output)));
        $summary = end($lines);

        expect($summary)->toStartWith('7 host-scope files for staging-main: ');
        foreach (itpHostScopeNames() as $name) {
            expect($summary)->toContain($name);
        }

        // Nothing about any member's content is printed, and nothing was
        // extracted anywhere: the only basic-auth on disk is the stage's own.
        expect($output)->not->toContain('content-of-');
        expect(array_values(array_filter(
            glob($scratch.'/*/basic-auth') ?: [],
            static fn (string $path): bool => ! str_contains($path, '/archive-stage-'),
        )))->toBe([]);
    } finally {
        itpCleanup($scratch);
    }
});

it('refuses every recovery material archive that is not exactly that', function (callable $build, string $expected) {
    $scratch = itpScratchDir();

    try {
        $archive = $build($scratch);

        [$exit, $output] = itpRun($scratch, ['--validate-recovery-material', '--target', 'staging-main', '--scope', 'host', '--archive', $archive]);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
    } finally {
        itpCleanup($scratch);
    }
})->with([
    'a directory entry' => [
        function (string $scratch): string {
            return itpArchive($scratch, function (string $stage): void {
                mkdir($stage.'/sub');
                file_put_contents($stage.'/sub/basic-auth', "x\n");
            }, ['sub']);
        },
        'nested path or a directory entry',
    ],
    'a nested path' => [
        function (string $scratch): string {
            return itpArchive($scratch, function (string $stage): void {
                mkdir($stage.'/sub');
                file_put_contents($stage.'/sub/basic-auth', "x\n");
            }, ['sub/basic-auth']);
        },
        'nested path or a directory entry',
    ],
    'an absolute path' => [
        fn (string $scratch): string => itpArchive(
            $scratch,
            itpRegularMembers(['basic-auth']),
            static fn (string $stage): array => [$stage.'/basic-auth'],
            '-P',
            false,
        ),
        'absolute path',
    ],
    'a parent traversal' => [
        fn (string $scratch): string => itpArchive(
            $scratch,
            function (string $stage): void {
                mkdir($stage.'/sub');
                file_put_contents($stage.'/basic-auth', "x\n");
            },
            ['../basic-auth'],
            '-P',
            true,
            '/sub',
        ),
        'relative path component',
    ],
    'a symbolic link' => [
        function (string $scratch): string {
            return itpArchive($scratch, function (string $stage): void {
                itpRegularMembers(array_diff(itpHostScopeNames(), ['basic-auth']))($stage);
                symlink('/etc/hosts', $stage.'/basic-auth');
            }, itpHostScopeNames());
        },
        'symbolic link',
    ],
    'a hard link' => [
        function (string $scratch): string {
            return itpArchive($scratch, function (string $stage): void {
                itpRegularMembers(array_diff(itpHostScopeNames(), ['mail-tls-private-key']))($stage);
                link($stage.'/mail-tls-certificate', $stage.'/mail-tls-private-key');
            }, itpHostScopeNames());
        },
        'hard link',
    ],
    'a FIFO' => [
        function (string $scratch): string {
            return itpArchive($scratch, function (string $stage): void {
                itpRegularMembers(array_diff(itpHostScopeNames(), ['tls-dhparams']))($stage);
                posix_mkfifo($stage.'/tls-dhparams', 0o600);
            }, itpHostScopeNames());
        },
        'FIFO',
    ],
    'a name outside the vocabulary' => [
        fn (string $scratch): string => itpArchive(
            $scratch,
            itpRegularMembers([...itpHostScopeNames(), 'rclone-config']),
            [...itpHostScopeNames(), 'rclone-config'],
        ),
        'not a host-scope prerequisite of staging-main: rclone-config',
    ],
    'a duplicate' => [
        fn (string $scratch): string => itpArchive(
            $scratch,
            itpRegularMembers(itpHostScopeNames()),
            [...itpHostScopeNames(), 'basic-auth'],
        ),
        'contains basic-auth more than once',
    ],
    'a missing name' => [
        fn (string $scratch): string => itpArchive(
            $scratch,
            itpRegularMembers(array_diff(itpHostScopeNames(), ['mail-tls-private-key'])),
            array_values(array_diff(itpHostScopeNames(), ['mail-tls-private-key'])),
        ),
        'missing the host-scope prerequisite mail-tls-private-key',
    ],
    'not a tar at all' => [
        function (string $scratch): string {
            file_put_contents($scratch.'/garbage.tar.gz', "definitely not gzip\n");

            return $scratch.'/garbage.tar.gz';
        },
        'unreadable',
    ],
    'an empty archive' => [
        function (string $scratch): string {
            exec('tar -czf '.escapeshellarg($scratch.'/empty.tar.gz').' -T /dev/null 2>&1', $out, $exit);
            expect($exit)->toBe(0, implode("\n", $out));

            return $scratch.'/empty.tar.gz';
        },
        'is empty',
    ],
    'a symlink where the archive should be' => [
        function (string $scratch): string {
            symlink('/etc/hosts', $scratch.'/linked.tar.gz');

            return $scratch.'/linked.tar.gz';
        },
        'must not be a symlink',
    ],
]);

it('keeps the recovery modes and the installing modes apart in what they accept', function (array $arguments, string $expected) {
    $scratch = itpScratchDir();

    try {
        [$exit, $output] = itpRun($scratch, $arguments);

        expect($exit)->toBe(1);
        expect($output)->toContain($expected);
    } finally {
        itpCleanup($scratch);
    }
})->with([
    'capture with material' => [
        ['--capture', '--target', 'staging-main', '--scope', 'host', '--output-dir', '/root/capture', '--material-dir', '/root/material'],
        '--capture never consults supplied material',
    ],
    'capture without an output directory' => [
        ['--capture', '--target', 'staging-main', '--scope', 'host'],
        '--output-dir',
    ],
    'capture of the target scope' => [
        ['--capture', '--target', 'staging-main', '--scope', 'target', '--output-dir', '/root/capture'],
        '--scope host',
    ],
    'validation without an archive' => [
        ['--validate-recovery-material', '--target', 'staging-main', '--scope', 'host'],
        '--archive',
    ],
    'validation with material' => [
        ['--validate-recovery-material', '--target', 'staging-main', '--scope', 'host', '--archive', '/root/a.tar.gz', '--material-dir', '/root/material'],
        '--validate-recovery-material never consults supplied material',
    ],
    'listing with material' => [
        ['--list-material-names', '--target', 'staging-main', '--scope', 'host', '--material-dir', '/root/material'],
        '--list-material-names never consults supplied material',
    ],
    'apply with an output directory' => [
        ['--apply', '--target', 'staging-main', '--scope', 'host', '--output-dir', '/root/capture'],
        '--output-dir',
    ],
    'verify with an archive' => [
        ['--verify', '--target', 'staging-main', '--scope', 'host', '--archive', '/root/a.tar.gz'],
        '--archive',
    ],
]);

// =============================================================================
// The archive-as-data rules are the same rules common applies to a backup
// =============================================================================

it('applies the same archive-as-data rules common applies, and only adds the vocabulary on top', function () {
    // The installer judges a recovery material archive on a clean host, where
    // common cannot be sourced; common judges the same archive as DATA for a
    // live restore, which never installs it. The structural verdicts must
    // agree on every shape; only the vocabulary — which names the archive
    // holds — is the installer's alone.
    $scratch = itpScratchDir();

    try {
        $judge = function (string $archive) use ($scratch): array {
            [$installerExit, $installerOutput] = itpRun($scratch, [
                '--validate-recovery-material', '--target', 'staging-main', '--scope', 'host', '--archive', $archive,
            ]);

            [$commonExit, $commonOutput] = commonFunctionHarness($scratch, implode("\n", [
                '( backup_assert_recovery_material_archive_safe '.escapeshellarg($archive).' ) || { echo "common refused: $?"; exit 0; }',
                'backup_assert_recovery_material_archive_safe '.escapeshellarg($archive),
                'echo "common accepted ${BACKUP_RECOVERY_MATERIAL_COUNT} members"',
            ]));

            expect($commonExit)->toBe(0, $commonOutput);

            return [
                'installer' => $installerExit === 0,
                'common' => str_contains($commonOutput, 'common accepted'),
                'installer_output' => $installerOutput,
                'common_output' => $commonOutput,
            ];
        };

        // Structurally unsafe: both refuse, for the same reason.
        // The shapes whose wrongness is a member's TYPE carry the rest of the
        // vocabulary as plain files, so the installer — which checks names
        // first — reaches its type check and both judges name the same reason.
        $rest = array_values(array_diff(itpHostScopeNames(), ['basic-auth']));
        // The hard-link shape supplies tls-dhparams itself, as the link.
        $restWithoutLinkTarget = array_values(array_diff($rest, ['tls-dhparams']));

        foreach ([
            'link' => ['symbolic link', $rest],
            'nested' => ['nested path or a directory entry', []],
            'traversal' => ['relative path component', []],
            'directory' => ['nested path or a directory entry', []],
            'hardlink' => ['hard link', $restWithoutLinkTarget],
            'fifo' => ['FIFO', $rest],
            'duplicate' => ['more than once', $rest],
            'empty' => ['is empty', []],
        ] as $shape => [$reason, $others]) {
            $archive = $scratch.'/shape-'.$shape.'.tar.gz';
            file_put_contents($archive, recoveryMaterialArchiveBytes($shape, 'basic-auth', $others));

            $verdict = $judge($archive);

            expect($verdict['installer'])->toBeFalse("the installer must refuse a {$shape} archive");
            expect($verdict['common'])->toBeFalse("common must refuse a {$shape} archive");
            expect($verdict['installer_output'])->toContain($reason);
            expect($verdict['common_output'])->toContain($reason);
        }

        // Not a tar: both refuse.
        file_put_contents($scratch.'/garbage.tar.gz', "not gzip\n");
        $verdict = $judge($scratch.'/garbage.tar.gz');
        expect($verdict['installer'])->toBeFalse();
        expect($verdict['common'])->toBeFalse();

        // Exactly the vocabulary: both accept.
        $verdict = $judge(itpArchive($scratch, itpRegularMembers(itpHostScopeNames()), itpHostScopeNames()));
        expect($verdict['installer'])->toBeTrue($verdict['installer_output']);
        expect($verdict['common'])->toBeTrue($verdict['common_output']);
        expect($verdict['common_output'])->toContain('common accepted 7 members');

        // A vocabulary the installer no longer knows: safe as data — common
        // accepts, so a live restore of an older backup still works — while
        // the installer, which would INSTALL these names, refuses.
        $verdict = $judge(itpArchive($scratch, itpRegularMembers(['legacy-tls-bundle', 'basic-auth']), ['legacy-tls-bundle', 'basic-auth']));
        expect($verdict['common'])->toBeTrue($verdict['common_output']);
        expect($verdict['common_output'])->toContain('common accepted 2 members');
        expect($verdict['installer'])->toBeFalse();
        expect($verdict['installer_output'])->toContain('not a host-scope prerequisite of staging-main: legacy-tls-bundle');
    } finally {
        itpCleanup($scratch);
    }
});
