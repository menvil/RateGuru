<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The host-global DKIM signer: install-mail-signing, and verify-mail-signing,
 * which composes it with the mail gateway and proves a held message is signed.
 *
 * Every test runs the shipped scripts. The signing plan always comes from the
 * real mail-identity CLI and the keys are real ones OpenSSL made, judged by the
 * real `mail-identity check-key`. The host is simulated: FS_ROOT plus stubs for
 * dpkg-query, apt-get, dpkg, systemctl, ss, opendkim, ps and runuser, and an
 * owner table standing in for root and the opendkim account, which a test
 * process cannot be. The opendkim stub is a test double that checks what the
 * real `opendkim -n` was seen to check — that the configuration exists and
 * every table it names opens — and nothing more; OpenDKIM's own acceptance of
 * the rendered configuration is what a real-host run of
 * `install-mail-signing --apply` and `verify-mail-signing --e2e` proves.
 *
 * Nothing here installs a package, starts a service, signs or sends mail, or
 * opens a connection to anything but a fake SMTP server on loopback.
 */

// =============================================================================
// THE SIMULATED HOST
// =============================================================================

function mailSigningScript(string $name = 'install-mail-signing'): string
{
    return base_path('infrastructure/scripts/'.$name);
}

/**
 * A simulated host for install-mail-signing.
 *
 * Options:
 *   package      'absent' (default) | 'installed' | 'partial'
 *   marker       null (default) | 'installing' | 'installed'
 *   ownPolicyRc  true to give the host its own /usr/sbin/policy-rc.d
 *   key          null (none) | a mailIdentityKey() kind, installed as tits-guru's rg1 key
 *   keyState     'presigner' (default: root:root 0600 in root:root 0700) | 'ready'
 *   sockets      extra listening sockets, as `ss -ltunpH` prints them
 *   identity     a mail identity contract instead of the committed one
 *
 * @return array{scratch: string, fs: string, state: string, env: array<string, string>}
 */
function mailSigningHost(array $options = []): array
{
    $scratch = makeScratchDir('mail-signing', ['', '/bin', '/fs', '/state', '/log', '/toggles', '/config', '/no-wait']);
    $fs = $scratch.'/fs';
    $state = $scratch.'/state';

    $passwd = "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33::/var/www:/usr/sbin/nologin\n";
    $group = "root:x:0:\nwww-data:x:33:\n";

    $package = $options['package'] ?? 'absent';
    if ($package === 'installed') {
        file_put_contents($state.'/pkg-status', 'install ok installed');
        file_put_contents($state.'/pkg-version', '2.11.0~beta2-6');
        touch($state.'/units-exist');
        touch($state.'/opendkim.service.enabled');
        $passwd .= "opendkim:x:105:106::/run/opendkim:/usr/sbin/nologin\n";
        $group .= "opendkim:x:106:\n";
    } elseif ($package === 'partial') {
        file_put_contents($state.'/pkg-status', 'install ok half-configured');
    }

    file_put_contents($state.'/passwd', $passwd);
    file_put_contents($state.'/group', $group);
    file_put_contents($state.'/owners', '');
    file_put_contents($state.'/sockets', implode("\n", $options['sockets'] ?? [])."\n");

    if (($options['marker'] ?? null) !== null) {
        @mkdir($fs.'/var/lib/rateguru-mail-signing', 0o755, true);
        file_put_contents($fs.'/var/lib/rateguru-mail-signing/ownership', "owner=rateguru\ncomponent=mail-signing\nstate={$options['marker']}\n");
    }

    if ($options['ownPolicyRc'] ?? false) {
        @mkdir($fs.'/usr/sbin', 0o755, true);
        file_put_contents($fs.'/usr/sbin/policy-rc.d', "#!/bin/sh\n# the host's own\nexit 101\n");
        chmod($fs.'/usr/sbin/policy-rc.d', 0o755);
    }

    if (($options['key'] ?? null) !== null) {
        mailSigningPlaceKey(['fs' => $fs, 'state' => $state], $options['key'], $options['keyState'] ?? 'presigner');
    }

    $stubs = [
        'dpkg-query' => <<<'STUB'
            #!/bin/bash
            if [[ "$*" == *'${Version}'* ]]; then cat "${STUB_STATE}/pkg-version" 2>/dev/null; exit 0; fi
            if [[ -f "${STUB_STATE}/pkg-status" ]]; then cat "${STUB_STATE}/pkg-status"; exit 0; fi
            echo "dpkg-query: no packages found matching opendkim" >&2
            exit 1
            STUB,
        'apt-get' => <<<'STUB'
            #!/bin/bash
            printf 'apt-get %s [DEBIAN_FRONTEND=%s]\n' "$*" "${DEBIAN_FRONTEND:-}" >> "${STUB_LOG}/mutations.log"
            [[ "$1" == install ]] || exit 0
            policy="${STUB_FS}/usr/sbin/policy-rc.d"
            if [[ -f "${policy}" ]] && grep -q 'rateguru-mail-signing' "${policy}" && grep -qx 'exit 101' "${policy}"; then
                echo "service starts suppressed during install" >> "${STUB_LOG}/apt-suppression.log"
            else
                echo "service starts NOT suppressed during install" >> "${STUB_LOG}/apt-suppression.log"
            fi
            [[ -e "${STUB_TOGGLES}/apt-fail" ]] && exit 100
            printf 'install ok installed' > "${STUB_STATE}/pkg-status"
            printf '2.11.0~beta2-6' > "${STUB_STATE}/pkg-version"
            # The package creates its own account and group, ships its own
            # configuration and enables its unit.
            grep -q '^opendkim:' "${STUB_STATE}/passwd" || echo 'opendkim:x:105:106::/run/opendkim:/usr/sbin/nologin' >> "${STUB_STATE}/passwd"
            grep -q '^opendkim:' "${STUB_STATE}/group" || echo 'opendkim:x:106:' >> "${STUB_STATE}/group"
            [[ -f "${STUB_FS}/etc/opendkim.conf" ]] || { mkdir -p "${STUB_FS}/etc"; printf 'Socket local:/run/opendkim/opendkim.sock\n' > "${STUB_FS}/etc/opendkim.conf"; }
            touch "${STUB_STATE}/units-exist" "${STUB_STATE}/opendkim.service.enabled"
            STUB,
        'dpkg' => <<<'STUB'
            #!/bin/bash
            printf 'dpkg %s\n' "$*" >> "${STUB_LOG}/mutations.log"
            STUB,
        'systemctl' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            case "$1" in
                is-enabled|show) printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/reads.log" ;;
                *) printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/mutations.log" ;;
            esac
            case "$1" in
                is-enabled)
                    [[ -e "${S}/units-exist" ]] || { echo "Failed to get unit file state for $2" >&2; exit 1; }
                    if [[ -e "${S}/$2.enabled" ]]; then echo enabled; exit 0; fi
                    echo disabled; exit 1 ;;
                show)
                    case "$3" in
                        --property=ActiveState) [[ -e "${S}/$2.active" ]] && echo active || echo inactive ;;
                        --property=SubState) [[ -e "${S}/$2.active" ]] && echo running || echo dead ;;
                        --property=NRestarts) cat "${S}/$2.nrestarts" 2>/dev/null || echo 0 ;;
                        --property=MainPID) [[ -e "${S}/$2.active" ]] && echo 4242 || echo 0 ;;
                        --property=DropInPaths) cat "${S}/$2.dropins" 2>/dev/null || echo ;;
                    esac
                    exit 0 ;;
                enable) touch "${S}/$2.enabled" ;;
                disable) rm -f "${S}/$2.enabled" ;;
                mask) touch "${S}/$2.masked" ;;
                start|restart)
                    [[ -e "${STUB_TOGGLES}/start-fail" ]] && exit 1
                    touch "${S}/$2.active" ;;
                stop) rm -f "${S}/$2.active" ;;
            esac
            exit 0
            STUB,
        // Every socket, the way ss -ltunpH prints it; the signer's own is the
        // one its installed opendkim.conf names, while its service runs.
        'ss' => <<<'STUB'
            #!/bin/bash
            S="${STUB_STATE}"
            case "$1" in
                -ltunpH)
                    sed '/^$/d' "${S}/sockets"
                    if [[ -e "${S}/opendkim.service.active" ]]; then
                        sock="$(awk '$1 == "Socket" { print $2 }' "${STUB_FS}/etc/opendkim.conf" 2>/dev/null)"
                        if [[ "${sock}" == inet:*@* ]]; then
                            port="${sock#inet:}"; port="${port%%@*}"
                            printf 'tcp LISTEN 0 4096 %s:%s 0.0.0.0:* users:(("opendkim",pid=4242,fd=3))\n' "${sock##*@}" "${port}"
                        fi
                        [[ -e "${STUB_TOGGLES}/public-listener" ]] && printf 'tcp LISTEN 0 4096 0.0.0.0:8891 0.0.0.0:* users:(("opendkim",pid=4242,fd=4))\n'
                        [[ -e "${STUB_TOGGLES}/ipv6-listener" ]] && printf 'tcp LISTEN 0 4096 [::]:8891 [::]:* users:(("opendkim",pid=4242,fd=5))\n'
                    fi
                    ;;
                -lxpH)
                    if [[ -e "${S}/opendkim.service.active" && -e "${STUB_TOGGLES}/unix-socket" ]]; then
                        printf 'u_str LISTEN 0 4096 /run/opendkim/opendkim.sock 4242 * 0 users:(("opendkim",pid=4242,fd=6))\n'
                    fi
                    ;;
            esac
            exit 0
            STUB,
        // A test double for `opendkim -n -x CONF`: the configuration exists,
        // and every table it names opens — on the host, or under FS_ROOT.
        'opendkim' => <<<'STUB'
            #!/bin/bash
            printf 'opendkim %s\n' "$*" >> "${STUB_LOG}/reads.log"
            [[ "$1" == -n && "$2" == -x && -n "${3:-}" ]] || exit 64
            conf="$3"
            [[ -f "${conf}" ]] || { echo "opendkim: ${conf}: No such file or directory"; exit 78; }
            [[ -e "${STUB_TOGGLES}/opendkim-reject" ]] && { echo "opendkim: ${conf}: configuration error at line 13: unrecognized parameter"; exit 78; }
            while read -r table; do
                [[ -f "${table}" || -f "${STUB_FS}${table}" ]] || { echo "opendkim: ${conf}: file:${table}: dkimf_db_open(): No such file or directory"; exit 78; }
            done < <(awk '$2 ~ /^file:/ { sub(/^file:/, "", $2); print $2 }' "${conf}")
            exit 0
            STUB,
        'ps' => <<<'STUB'
            #!/bin/bash
            cat "${STUB_STATE}/process-user" 2>/dev/null || echo opendkim
            STUB,
        // root and opendkim cannot be a test process's accounts, so ownership
        // lives in a table; the mode is the file's own.
        'stat-owners' => <<<'STUB'
            #!/bin/bash
            path="${@: -1}"
            [[ -e "${path}" ]] || exit 1
            owner="$(awk -F'\t' -v p="${path}" '$1 == p { o = $2 } END { print o }' "${STUB_STATE}/owners")"
            [[ -n "${owner}" ]] || owner="$(stat -c '%U:%G' "${path}")"
            printf '%s %s\n' "${owner}" "$(stat -c '%a' "${path}")"
            STUB,
        'chown-owners' => <<<'STUB'
            #!/bin/bash
            printf 'chown %s\n' "$*" >> "${STUB_LOG}/mutations.log"
            [[ -e "${STUB_TOGGLES}/chown-fail" ]] && exit 1
            printf '%s\t%s\n' "$2" "$1" >> "${STUB_STATE}/owners"
            STUB,
        // The signer reading a key: its group, or its own user, must be
        // allowed by the key's mode.
        'runuser' => <<<'STUB'
            #!/bin/bash
            printf 'runuser %s\n' "$*" >> "${STUB_LOG}/reads.log"
            [[ "$1" == -u && "$3" == -- && "$4" == head && "$5" == -c && "$6" == 1 && "$7" == -- ]] || exit 64
            [[ -e "${STUB_TOGGLES}/runuser-deny" ]] && exit 1
            user="$2"; path="$8"
            read -r owner mode < <("$(dirname "$0")/stat-owners" -c '%U:%G %a' "${path}") || exit 1
            u="${mode:0:1}"; g="${mode:1:1}"; o="${mode:2:1}"
            if [[ "${owner%%:*}" == "${user}" ]] && (( u & 4 )); then exit 0; fi
            if [[ "${owner#*:}" == "${user}" ]] && (( g & 4 )); then exit 0; fi
            (( o & 4 )) && exit 0
            exit 1
            STUB,
    ];

    foreach ($stubs as $name => $body) {
        file_put_contents($scratch.'/bin/'.$name, $body."\n");
        chmod($scratch.'/bin/'.$name, 0o755);
    }

    // The stability window is a real sleep; here it would only cost seconds.
    file_put_contents($scratch.'/no-wait/sleep', "#!/bin/sh\nexit 0\n");
    chmod($scratch.'/no-wait/sleep', 0o755);

    $env = [
        'PATH' => $scratch.'/no-wait:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => $scratch,
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MAILSIGN_EUID' => '0',
        'RATEGURU_MAILSIGN_FS_ROOT' => $fs,
        'RATEGURU_MAILSIGN_FILE_OWNER' => trim((string) shell_exec('id -un')),
        'RATEGURU_MAILSIGN_FILE_GROUP' => trim((string) shell_exec('id -gn')),
        'RATEGURU_MAILSIGN_RUNTIME_WAIT' => '1',
        'RATEGURU_MAILSIGN_STABILITY_WAIT' => '1',
        'RATEGURU_MAILSIGN_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_MAILSIGN_APT_GET_BIN' => $scratch.'/bin/apt-get',
        'RATEGURU_MAILSIGN_DPKG_BIN' => $scratch.'/bin/dpkg',
        'RATEGURU_MAILSIGN_DPKG_QUERY_BIN' => $scratch.'/bin/dpkg-query',
        'RATEGURU_MAILSIGN_OPENDKIM_BIN' => $scratch.'/bin/opendkim',
        'RATEGURU_MAILSIGN_SS_BIN' => $scratch.'/bin/ss',
        'RATEGURU_MAILSIGN_PS_BIN' => $scratch.'/bin/ps',
        'RATEGURU_MAILSIGN_RUNUSER_BIN' => $scratch.'/bin/runuser',
        'RATEGURU_MAILSIGN_STAT_BIN' => $scratch.'/bin/stat-owners',
        'RATEGURU_MAILSIGN_CHOWN_BIN' => $scratch.'/bin/chown-owners',
        'RATEGURU_MAILSIGN_PASSWD_FILE' => $state.'/passwd',
        'RATEGURU_MAILSIGN_GROUP_FILE' => $state.'/group',
        'STUB_STATE' => $state,
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];

    if (isset($options['identity'])) {
        file_put_contents($scratch.'/config/mail-identity.json', mailRoutingJson($options['identity']));
        $env['RATEGURU_MAILSIGN_IDENTITY_FILE'] = $scratch.'/config/mail-identity.json';
    }

    return ['scratch' => $scratch, 'fs' => $fs, 'state' => $state, 'env' => $env];
}

/**
 * KIND as tits-guru's rg1 key, in the pre-signer layout (root:root 0600 in
 * root:root 0700 directories) or the signing-ready one (root:opendkim 0640 in
 * root:opendkim 0750 directories).
 */
function mailSigningPlaceKey(array $host, string $kind, string $layout = 'presigner'): string
{
    [$owner, $keyMode, $dirMode] = $layout === 'ready' ? ['root:opendkim', 0o640, 0o750] : ['root:root', 0o600, 0o700];

    $keys = $host['fs'].'/etc/opendkim/keys';
    @mkdir($keys.'/tits-guru', 0o700, true);
    @chmod($host['fs'].'/etc/opendkim', 0o755);
    copy(mailIdentityKey($kind), $keys.'/tits-guru/rg1.private');

    chmod($keys, $dirMode);
    chmod($keys.'/tits-guru', $dirMode);
    chmod($keys.'/tits-guru/rg1.private', $keyMode);
    mailSigningOwn($host, [$keys, $keys.'/tits-guru', $keys.'/tits-guru/rg1.private'], $owner);

    return $keys.'/tits-guru/rg1.private';
}

/** Record PATHS as owned by OWNER:GROUP in the simulated host's owner table. */
function mailSigningOwn(array $host, array $paths, string $owner): void
{
    foreach ($paths as $path) {
        file_put_contents($host['state'].'/owners', "{$path}\t{$owner}\n", FILE_APPEND);
    }
}

/** "owner:group mode" of PATH on the simulated host. */
function mailSigningMetadata(array $host, string $path): string
{
    return trim((string) shell_exec('STUB_STATE='.escapeshellarg($host['state']).' bash '.escapeshellarg($host['scratch'].'/bin/stat-owners').' -c x '.escapeshellarg($path).' 2>/dev/null'), "\n");
}

/** @return array{0: int, 1: string} */
function mailSigningRun(array $host, array $arguments, array $env = [], string $script = 'install-mail-signing'): array
{
    $process = proc_open(
        ['bash', mailSigningScript($script), ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], ...$env],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function mailSigningLog(array $host, string $name): string
{
    $path = $host['scratch'].'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Every path, its content and its mode under the simulated host, and the owner
 * table: a mode that must not mutate can be proved not to have.
 *
 * @return array<string, string>
 */
function mailSigningTree(array $host): array
{
    $tree = ['owner table' => (string) file_get_contents($host['state'].'/owners')];

    foreach (File::allFiles($host['fs'], true) as $file) {
        $tree[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname()).' '.substr(sprintf('%o', $file->getPerms()), -4);
    }

    ksort($tree);

    return $tree;
}

function mailSigningCleanup(array $host): void
{
    removeScratchDir($host['scratch']);
}

/** The four files the signer renders for the committed plan. */
function mailSigningExpectedKeyTable(): string
{
    return "# RateGuru mail signing — KeyTable\n#\n"
        ."# One key per reviewed target: <selector>._domainkey.<domain>, then\n"
        ."# <domain>:<selector>:<the canonical private key path>. Rendered from the\n"
        ."# signing plan; never edit it on a host.\n\n"
        ."rg1._domainkey.tits.guru tits.guru:rg1:/etc/opendkim/keys/tits-guru/rg1.private\n";
}

// =============================================================================
// WHAT IS SIGNED, AND HOW OPENDKIM SPELLS IT
// =============================================================================

it('prints the one milter endpoint the gateway wires to, and nothing else', function () {
    $process = proc_open(['bash', mailSigningScript(), '--milter-endpoint'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0);
    expect($stdout)->toBe("inet:127.0.0.1:8891\n");
    expect($stderr)->toBe('');

    // No override can move it: there is no variable for it at all.
    $code = executableSourceLines(File::get(mailSigningScript()));
    expect($code)->toContain('MILTER_HOST="127.0.0.1"')->toContain('MILTER_PORT="8891"');
    expect(preg_match('/MILTER_(HOST|PORT)="\$\(gated_default/', $code))->toBe(0);
    expect(preg_match('/RATEGURU_MAILSIGN_[A-Z_]*(MILTER|PORT|SOCKET|ENDPOINT)/', $code))->toBe(0);
});

it('renders the committed signing plan into exactly the reviewed OpenDKIM configuration', function () {
    $host = mailSigningHost(['package' => 'installed', 'marker' => 'installed', 'key' => 'rsa2048', 'keyState' => 'ready']);

    try {
        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->toBe(0, $output);

        $conf = File::get($host['fs'].'/etc/opendkim.conf');
        $settings = [];
        foreach (preg_split('/\R/', $conf) as $line) {
            if ($line !== '' && ! str_starts_with($line, '#')) {
                [$name, $value] = preg_split('/\s+/', $line, 2);
                $settings[$name] = $value;
            }
        }

        // Signing only, as opendkim, on loopback 8891 and nowhere else, with
        // exact-domain tables and loopback-only signing.
        expect($settings)->toBe([
            'Syslog' => 'yes',
            'SyslogSuccess' => 'yes',
            'Mode' => 's',
            'UserID' => 'opendkim',
            'UMask' => '007',
            'Socket' => 'inet:8891@127.0.0.1',
            'PidFile' => '/run/opendkim/opendkim.pid',
            'Canonicalization' => 'relaxed/simple',
            'SignatureAlgorithm' => 'rsa-sha256',
            'OversignHeaders' => 'From',
            'KeyTable' => 'file:/etc/opendkim/KeyTable',
            'SigningTable' => 'file:/etc/opendkim/SigningTable',
            'InternalHosts' => 'file:/etc/opendkim/TrustedHosts',
            'ExternalIgnoreList' => 'file:/etc/opendkim/TrustedHosts',
            'RequireSafeKeys' => 'true',
            // Fail closed on what it cannot sign by the rules: header counts
            // RFC 5322 forbids (two From fields), malformed mail, and a
            // signing error are refused, never passed on unsigned.
            'RequiredHeaders' => 'yes',
            'IgnoreMalformedMail' => 'no',
            'On-SignatureError' => 'reject',
        ]);

        expect(File::get($host['fs'].'/etc/opendkim/KeyTable'))->toBe(mailSigningExpectedKeyTable());

        $entries = static fn (string $file): array => array_values(array_filter(
            preg_split('/\R/', File::get($host['fs'].$file)),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        // Exactly the reviewed domain, no wildcard, no subdomain entry.
        expect($entries('/etc/opendkim/SigningTable'))->toBe(['tits.guru rg1._domainkey.tits.guru']);
        expect($entries('/etc/opendkim/TrustedHosts'))->toBe(['127.0.0.1']);
        expect(File::get($host['fs'].'/etc/opendkim/SigningTable'))->not->toContain('*')->not->toContain(' .');

        foreach (['/etc/opendkim.conf', '/etc/opendkim/KeyTable', '/etc/opendkim/SigningTable', '/etc/opendkim/TrustedHosts'] as $file) {
            expect(substr(sprintf('%o', fileperms($host['fs'].$file)), -3))->toBe('644');
        }

        // Deterministic: re-rendering on the same plan changes nothing.
        $before = mailSigningTree($host);
        [$again] = mailSigningRun($host, ['--apply']);
        expect($again)->toBe(0);
        expect(mailSigningTree($host))->toBe($before);
    } finally {
        mailSigningCleanup($host);
    }
});

it('signs every production identity on the host from one daemon, each with its own key', function () {
    $scratch = makeScratchDir('mail-signing-plan', ['']);

    try {
        $files = mailIdentityFixtureConfig($scratch.'/config');
        $host = mailSigningHost(['package' => 'installed', 'marker' => 'installed']);
        $env = [
            'RATEGURU_MAILSIGN_IDENTITY_FILE' => $files[1],
            'RATEGURU_MAILSIGN_ROUTING_FILE' => $files[3],
            'RATEGURU_MAILSIGN_REGISTRY_FILE' => $files[5],
            'RATEGURU_MAILSIGN_OUTBOUND_FILE' => $files[7],
        ];

        try {
            [$status, $output] = mailSigningRun($host, ['--apply'], $env);
            expect($status)->toBe(0, $output);

            expect(File::get($host['fs'].'/etc/opendkim/KeyTable'))
                ->toEndWith("shop2026._domainkey.demo-shop.example demo-shop.example:shop2026:/etc/opendkim/keys/demo-shop/shop2026.private\nrg1._domainkey.tits.guru tits.guru:rg1:/etc/opendkim/keys/tits-guru/rg1.private\n");
            expect(File::get($host['fs'].'/etc/opendkim/SigningTable'))
                ->toEndWith("demo-shop.example shop2026._domainkey.demo-shop.example\ntits.guru rg1._domainkey.tits.guru\n");

            // One daemon, one socket, whatever the number of targets.
            expect(substr_count(File::get($host['fs'].'/etc/opendkim.conf'), 'Socket '))->toBe(1);

            // Neither key is installed: both deferred while held.
            expect($output)->toContain("demo-shop's key /etc/opendkim/keys/demo-shop/shop2026.private is not installed — deferred while its mail is held");
        } finally {
            mailSigningCleanup($host);
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it('names no target, domain, selector or key, and restates no rule of the identity contract', function () {
    $source = File::get(mailSigningScript());
    $code = executableSourceLines($source);

    foreach (['tits-guru', 'tits.guru', 'rg1', 'demo-shop', 'staging-main', 'mta1'] as $name) {
        expect(str_contains($source, $name))->toBeFalse("install-mail-signing names {$name}");
    }

    // The plan comes from mail-identity, in this bundle, and from nowhere else.
    expect($code)
        ->toContain('MAIL_IDENTITY_CLI="$(gated_default RATEGURU_MAILSIGN_MAIL_IDENTITY_CLI "${SCRIPT_DIR}/mail-identity")"')
        ->toContain('"${MAIL_IDENTITY_CLI}" render-signing-plan')
        ->toContain('"${MAIL_IDENTITY_CLI}" check-key --file "${path}" --min-bits "${bits}"')
        ->not->toContain('render-plan')
        ->not->toContain('MAIL_ROUTING_CLI')
        ->not->toContain('/etc/opendkim/keys');
    expect(preg_match('/mail-routing(?!\.json)/', $code))->toBe(0);

    foreach (['IDENTITY_RULES', 'dkim_algorithms', 'minimum_rsa_bits', 'dmarc', 'dkim_key_requirement', 'Public-Key'] as $rule) {
        expect(str_contains($code, $rule))->toBeFalse("install-mail-signing restates {$rule}");
    }

    // It never writes the gateway's files, and creates no account.
    foreach (['main.cf', 'master.cf', '/etc/postfix', 'postconf', 'useradd', 'adduser', 'groupadd', 'addgroup', 'usermod', 'gpasswd'] as $foreign) {
        expect(str_contains($code, $foreign))->toBeFalse("install-mail-signing uses {$foreign}");
    }
});

it('never reads, copies, rewrites or prints a key — it changes a key\'s owner, group and mode, and nothing else', function () {
    $code = executableSourceLines(File::get(mailSigningScript()));

    foreach (['cat "${path}"', 'cat "$1"', '-text', 'openssl', 'genpkey', 'genrsa', 'sha256sum', 'md5sum', 'base64', 'dd ', 'cp "${path}"', 'wc -c'] as $leak) {
        expect(str_contains($code, $leak))->toBeFalse("install-mail-signing could touch key content with {$leak}");
    }

    // The signer's own read of the key is one byte into nothing.
    expect($code)->toContain('"${RUNUSER_BIN}" -u "${SIGNER_USER}" -- head -c 1 -- "$1" >/dev/null 2>&1');
    expect(substr_count($code, 'head -c 1'))->toBe(1);

    $grant = shellFunctionBody(File::get(mailSigningScript()), 'grant_access');
    expect(executableSourceLines($grant))
        ->toContain('"${CHOWN_BIN}" "${state%% *}" "${path}"')
        ->toContain('chmod "0${state##* }" "${path}"')
        ->not->toContain('install ')
        ->not->toContain('mv ')
        ->not->toContain('cp ');
});

// =============================================================================
// NOTHING TO SIGN
// =============================================================================

it('is a valid no-op on a host with no reviewed production signing identity', function () {
    $host = mailSigningHost(['identity' => ['schema_version' => 1, 'targets' => (object) []]]);

    try {
        $before = mailSigningTree($host);

        foreach (['--check', '--verify', '--apply'] as $mode) {
            [$status, $output] = mailSigningRun($host, [$mode]);
            expect($status)->toBe(0, $output);
            expect($output)->toContain('no production target has a reviewed signing identity');
        }

        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
        expect(mailSigningLog($host, 'apt-suppression.log'))->toBe('');
        expect(mailSigningTree($host))->toBe($before);
        expect(file_exists($host['fs'].'/etc/opendkim.conf'))->toBeFalse();
    } finally {
        mailSigningCleanup($host);
    }
});

it('plans OpenDKIM for the one held reviewed identity, and says what --apply would do', function () {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        [$status, $output] = mailSigningRun($host, ['--check']);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('PASS     plan — mail-identity render-signing-plan accepted the reviewed identity: 1 target(s) to sign (tits-guru=tits.guru/rg1)')
            ->toContain('PASS     milter — the signer\'s endpoint is inet:127.0.0.1:8891, loopback only')
            ->toContain('MISSING  ownership — no OpenDKIM on this host — --apply installs the opendkim package with service starts suppressed')
            ->toContain('MISSING  package — opendkim is absent')
            ->toContain('MISSING  file:/etc/opendkim.conf')
            ->toContain('DRIFT    key:tits-guru — /etc/opendkim/keys/tits-guru/rg1.private is valid and still root-only (pre-signer root:root 600) — --apply grants the signer read access (root:opendkim 640), never touching its contents');
        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailSigningCleanup($host);
    }
});

// =============================================================================
// THE PACKAGE, AND WHOSE IT IS
// =============================================================================

it('installs the package with service starts suppressed, and starts the signer only once its configuration validates', function () {
    $host = mailSigningHost(['ownPolicyRc' => true, 'key' => 'rsa2048']);

    try {
        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->toBe(0, $output);

        // Suppressed while the package went in, and the host's own policy-rc.d
        // is back, byte for byte.
        expect(trim(mailSigningLog($host, 'apt-suppression.log')))->toBe('service starts suppressed during install');
        expect(File::get($host['fs'].'/usr/sbin/policy-rc.d'))->toBe("#!/bin/sh\n# the host's own\nexit 101\n");
        expect(mailSigningLog($host, 'mutations.log'))->toContain('apt-get install -y --no-install-recommends --no-remove');

        // The marker went down before the package; the candidate was judged by
        // OpenDKIM before anything was installed; the service started last.
        $marker = strpos($output, 'installing the opendkim package');
        $installed = strpos($output, 'installing /etc/opendkim.conf');
        $started = strpos($output, 'starting opendkim.service');
        expect($marker)->toBeLessThan($installed);
        expect($installed)->toBeLessThan($started);

        $reads = mailSigningLog($host, 'reads.log');
        expect(preg_match('#opendkim -n -x /[^ ]+/check/opendkim\.conf#', $reads))->toBe(1);
        expect($reads)->toContain('opendkim -n -x '.$host['fs'].'/etc/opendkim.conf');
        expect(strpos($reads, '/check/opendkim.conf'))->toBeLessThan(strpos($reads, '-x '.$host['fs'].'/etc/opendkim.conf'));

        expect(File::get($host['fs'].'/var/lib/rateguru-mail-signing/ownership'))->toContain('state=installed');
        expect(mailSigningLog($host, 'mutations.log'))->toContain('systemctl enable opendkim.service')->toContain('systemctl start opendkim.service');

        [$verify, $report] = mailSigningRun($host, ['--verify']);
        expect($verify)->toBe(0, $report);
        expect($report)->toContain('SUMMARY  pass=13 missing=0 drift=0 conflict=0 deferred=0');
    } finally {
        mailSigningCleanup($host);
    }
});

it('fails closed on an OpenDKIM RateGuru did not install, before changing anything', function (array $options, string $reason) {
    $host = mailSigningHost($options);

    try {
        if ($options['foreignConfig'] ?? false) {
            @mkdir($host['fs'].'/etc', 0o755, true);
            file_put_contents($host['fs'].'/etc/opendkim.conf', "# somebody else's\n");
        }

        $before = mailSigningTree($host);

        [$check, $report] = mailSigningRun($host, ['--check']);
        expect($check)->not->toBe(0);
        expect($report)->toContain('CONFLICT ownership — an OpenDKIM RateGuru did not install is on this host ('.$reason.'), and there is no RateGuru ownership marker');

        [$apply, $output] = mailSigningRun($host, ['--apply']);
        expect($apply)->not->toBe(0);
        expect($output)->toContain('an OpenDKIM RateGuru did not install is on this host ('.$reason.')')->toContain('refusing to take over a signer RateGuru did not install. Nothing was changed');

        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
        expect(mailSigningTree($host))->toBe($before);
    } finally {
        mailSigningCleanup($host);
    }
})->with([
    'the package, with no marker' => [['package' => 'installed'], 'the opendkim package is installed'],
    'a half-installed package' => [['package' => 'partial'], 'the opendkim package is partial'],
    'an OpenDKIM configuration, with no package' => [['foreignConfig' => true], '/etc/opendkim.conf exists'],
]);

it('resumes an installation RateGuru started and was interrupted, restoring the host\'s own policy-rc.d first', function () {
    $host = mailSigningHost(['marker' => 'installing', 'package' => 'partial', 'key' => 'rsa2048']);

    try {
        // The interrupted run's suppression is still in place, and the host's
        // own policy-rc.d was preserved beside it.
        @mkdir($host['fs'].'/usr/sbin', 0o755, true);
        file_put_contents($host['fs'].'/usr/sbin/policy-rc.d', "#!/bin/sh\n# rateguru-mail-signing: package service starts suppressed until the signing configuration is in place\nexit 101\n");
        file_put_contents($host['fs'].'/var/lib/rateguru-mail-signing/policy-rc.d.preserved', "#!/bin/sh\n# the host's own\nexit 0\n");

        [$check, $report] = mailSigningRun($host, ['--check']);
        expect($check)->toBe(0, $report);
        expect($report)
            ->toContain('PASS     ownership — RateGuru ownership marker present (state=installing)')
            ->toContain('DRIFT    package:start-suppression');

        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->toBe(0, $output);

        $mutations = mailSigningLog($host, 'mutations.log');
        expect($mutations)->toContain('dpkg --configure opendkim')->toContain('apt-get install');
        expect(strpos($mutations, 'dpkg --configure opendkim'))->toBeLessThan(strpos($mutations, 'apt-get install'));
        expect($output)->toContain('removing the package start suppression an interrupted run left behind');

        expect(File::get($host['fs'].'/usr/sbin/policy-rc.d'))->toBe("#!/bin/sh\n# the host's own\nexit 0\n");
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-signing/ownership'))->toContain('state=installed');
    } finally {
        mailSigningCleanup($host);
    }
});

it('refuses a milter port another process already holds, before installing anything', function () {
    $host = mailSigningHost(['sockets' => ['tcp LISTEN 0 128 127.0.0.1:8891 0.0.0.0:* users:(("nc",pid=77,fd=3))']]);

    try {
        [$check, $report] = mailSigningRun($host, ['--check']);
        expect($check)->not->toBe(0);
        expect($report)->toContain('CONFLICT port:8891 — already in use by another process');

        [$apply, $output] = mailSigningRun($host, ['--apply']);
        expect($apply)->not->toBe(0);
        expect($output)->toContain('milter port 8891 is already in use by another process — nothing was changed');
        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailSigningCleanup($host);
    }
});

// =============================================================================
// THE KEYS: JUDGED, THEN GRANTED TO THE SIGNER BY METADATA ALONE
// =============================================================================

it('grants the signer read access to a valid pre-signer key by owner, group and mode alone, never touching its bytes', function () {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        $key = $host['fs'].'/etc/opendkim/keys/tits-guru/rg1.private';
        $bytes = hash_file('sha256', $key);
        $inode = fileinode($key);

        expect(mailSigningMetadata($host, $key))->toBe('root:root 600');

        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->toBe(0, $output);
        expect($output)->toContain('granting the signer read access to /etc/opendkim/keys/tits-guru/rg1.private (owner, group and mode only — its contents are not touched)');

        // Exactly the signing-ready metadata, on the key and its directories.
        expect(mailSigningMetadata($host, $host['fs'].'/etc/opendkim/keys'))->toBe('root:opendkim 750');
        expect(mailSigningMetadata($host, $host['fs'].'/etc/opendkim/keys/tits-guru'))->toBe('root:opendkim 750');
        expect(mailSigningMetadata($host, $key))->toBe('root:opendkim 640');

        // The same file, byte for byte: not rewritten, not replaced.
        expect(hash_file('sha256', $key))->toBe($bytes);
        expect(fileinode($key))->toBe($inode);

        // Read access is the signer's alone: group read, no group write, no
        // world bit.
        expect(fileperms($key) & 0o777)->toBe(0o640);

        expect($output)->not->toContain('PRIVATE KEY');
        expectNoKeyMaterial($output, $key);

        // And the signer reads it now: --verify --target holds.
        [$verify, $report] = mailSigningRun($host, ['--verify', '--target', 'tits-guru']);
        expect($verify)->toBe(0, $report);
        expect($report)
            ->toContain('PASS     key:tits-guru — /etc/opendkim/keys/tits-guru/rg1.private is valid, root:opendkim 640, in root:opendkim 750 directories')
            ->toContain('PASS     key-access:tits-guru — the opendkim account reads /etc/opendkim/keys/tits-guru/rg1.private (nothing of it was printed)');
    } finally {
        mailSigningCleanup($host);
    }
});

it('refuses, before changing anything, a key that is not a usable one or sits in any layout but the two it knows', function (string $kind, string $tamper, string $reason) {
    $host = mailSigningHost(['key' => $kind]);

    try {
        $key = $host['fs'].'/etc/opendkim/keys/tits-guru/rg1.private';

        match ($tamper) {
            'none' => null,
            'world-readable' => chmod($key, 0o644),
            'group-writable' => [chmod($key, 0o660), mailSigningOwn($host, [$key], 'root:opendkim')],
            'foreign owner' => mailSigningOwn($host, [$key], 'www-data:root'),
            'open directory' => chmod(dirname($key), 0o755),
            'symlink' => [rename($key, $key.'.real'), symlink($key.'.real', $key)],
        };

        $before = mailSigningTree($host);

        [$check, $report] = mailSigningRun($host, ['--check']);
        expect($check)->not->toBe(0);
        expect($report)->toContain('CONFLICT key:tits-guru — '.$reason);

        [$apply, $output] = mailSigningRun($host, ['--apply']);
        expect($apply)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('Nothing was changed');

        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
        expect(mailSigningTree($host))->toBe($before);
        expectNoKeyMaterial($report.$output, $key);
    } finally {
        mailSigningCleanup($host);
    }
})->with([
    'a key below the reviewed size' => ['rsa1024', 'none', '/etc/opendkim/keys/tits-guru/rg1.private is not a usable DKIM private key: it is a 1024-bit RSA key, below the reviewed minimum of 2048 bits'],
    'not a key at all' => ['junk', 'none', '/etc/opendkim/keys/tits-guru/rg1.private is not a usable DKIM private key: it is not PEM'],
    'a world-readable key' => ['rsa2048', 'world-readable', '/etc/opendkim/keys/tits-guru/rg1.private is root:root 644, neither root:opendkim 640 nor the pre-signer root:root 600'],
    'a group-writable key' => ['rsa2048', 'group-writable', '/etc/opendkim/keys/tits-guru/rg1.private is root:opendkim 660, neither root:opendkim 640'],
    'a key another account owns' => ['rsa2048', 'foreign owner', '/etc/opendkim/keys/tits-guru/rg1.private is www-data:root 600, neither'],
    'a key directory others can enter' => ['rsa2048', 'open directory', '/etc/opendkim/keys/tits-guru is root:root 755, neither root:opendkim 750 nor the pre-signer root:root 700'],
    'a key reached through a symlink' => ['rsa2048', 'symlink', '/etc/opendkim/keys/tits-guru/rg1.private is a symlink'],
]);

it('reports a held target\'s absent key as deferred, and never as a pass', function () {
    $host = mailSigningHost();

    try {
        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->toBe(0, $output);
        expect($output)->toContain("tits-guru's key /etc/opendkim/keys/tits-guru/rg1.private is not installed — deferred while its mail is held");

        // The host contract holds without it: a clean host is bootstrapped
        // before its targets' material arrives.
        [$verify, $report] = mailSigningRun($host, ['--verify']);
        expect($verify)->toBe(0, $report);
        expect($report)
            ->toContain('DEFERRED key:tits-guru — /etc/opendkim/keys/tits-guru/rg1.private is not installed; not needed while tits-guru\'s mail is held')
            ->not->toContain('PASS     key:');

        // But the target cannot be signed: its own verify fails.
        [$target, $report] = mailSigningRun($host, ['--verify', '--target', 'tits-guru']);
        expect($target)->not->toBe(0);
        expect($report)->toContain('MISSING  key:tits-guru — /etc/opendkim/keys/tits-guru/rg1.private is not installed — tits-guru\'s mail cannot be signed until it is');
    } finally {
        mailSigningCleanup($host);
    }
});

it('requires an outbound target\'s key', function () {
    $scratch = makeScratchDir('mail-signing-plan', ['']);

    try {
        $files = mailIdentityFixtureConfig($scratch.'/config', [
            'mode' => 'outbound',
            'outbound' => ['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net']],
        ]);
        $host = mailSigningHost(['package' => 'installed', 'marker' => 'installed', 'key' => 'rsa2048']);
        $env = [
            'RATEGURU_MAILSIGN_IDENTITY_FILE' => $files[1],
            'RATEGURU_MAILSIGN_ROUTING_FILE' => $files[3],
            'RATEGURU_MAILSIGN_REGISTRY_FILE' => $files[5],
            'RATEGURU_MAILSIGN_OUTBOUND_FILE' => $files[7],
        ];

        try {
            [$status, $output] = mailSigningRun($host, ['--apply'], $env);
            expect($status)->toBe(0, $output);

            [$verify, $report] = mailSigningRun($host, ['--verify'], $env);
            expect($verify)->not->toBe(0);
            expect($report)->toContain('MISSING  key:demo-shop — /etc/opendkim/keys/demo-shop/shop2026.private is not installed, and demo-shop delivers outbound — its key is required');
        } finally {
            mailSigningCleanup($host);
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to call a signer healthy that cannot read the key it signs with, and puts the key back', function () {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        touch($host['scratch'].'/toggles/runuser-deny');
        $key = $host['fs'].'/etc/opendkim/keys/tits-guru/rg1.private';

        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('the opendkim account cannot read /etc/opendkim/keys/tits-guru/rg1.private after access was granted')
            ->toContain('rollback complete: configuration, key metadata and service state restored');

        // Every change undone: the key is root-only again, the configuration
        // gone, the service stopped — and the marker says a re-run resumes.
        expect(mailSigningMetadata($host, $key))->toBe('root:root 600');
        expect(mailSigningMetadata($host, dirname($key)))->toBe('root:root 700');
        expect(file_exists($host['fs'].'/etc/opendkim/KeyTable'))->toBeFalse();
        expect(file_exists($host['state'].'/opendkim.service.active'))->toBeFalse();
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-signing/ownership'))->toContain('state=installing');
        expect($output)->toContain('the ownership marker stays, so a re-run resumes');

        // On a converged host the same thing is a CONFLICT of --verify.
        unlink($host['scratch'].'/toggles/runuser-deny');
        [$again, $log] = mailSigningRun($host, ['--apply']);
        expect($again)->toBe(0, $log);

        touch($host['scratch'].'/toggles/runuser-deny');
        [$verify, $report] = mailSigningRun($host, ['--verify']);
        expect($verify)->not->toBe(0);
        expect($report)->toContain('CONFLICT key-access:tits-guru — the opendkim account cannot read /etc/opendkim/keys/tits-guru/rg1.private');
    } finally {
        mailSigningCleanup($host);
    }
});

it('refuses a signer group that holds any other account', function (string $group, string $passwd, string $reason) {
    $host = mailSigningHost(['package' => 'installed', 'marker' => 'installed', 'key' => 'rsa2048', 'keyState' => 'ready']);

    try {
        file_put_contents($host['state'].'/group', str_replace("opendkim:x:106:\n", $group, File::get($host['state'].'/group')));
        file_put_contents($host['state'].'/passwd', File::get($host['state'].'/passwd').$passwd);

        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
    } finally {
        mailSigningCleanup($host);
    }
})->with([
    'postfix in the group' => ["opendkim:x:106:opendkim,postfix\n", '', 'the opendkim group also holds postfix'],
    'an account whose primary group it is' => ["opendkim:x:106:\n", "mailer:x:200:106::/nonexistent:/usr/sbin/nologin\n", 'the account mailer has the opendkim group as its primary group'],
    'no group at all' => ['', '', 'the opendkim group does not exist — the opendkim package creates it'],
]);

// =============================================================================
// THE RUNNING SIGNER: LOOPBACK 8891, AS OPENDKIM, AND NOWHERE ELSE
// =============================================================================

it('verifies a converged signer, and fails on every way it can run differently', function (string $toggle, string $problem) {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        [$applied, $log] = mailSigningRun($host, ['--apply']);
        expect($applied)->toBe(0, $log);

        [$ok, $report] = mailSigningRun($host, ['--verify']);
        expect($ok)->toBe(0, $report);
        expect($report)->toContain('PASS     runtime — enabled, stably running as opendkim, listening on exactly 127.0.0.1:8891 and nowhere else');

        match ($toggle) {
            'stopped' => unlink($host['state'].'/opendkim.service.active'),
            'not enabled' => unlink($host['state'].'/opendkim.service.enabled'),
            'root' => file_put_contents($host['state'].'/process-user', "root\n"),
            'drop-in' => file_put_contents($host['state'].'/opendkim.service.dropins', "/etc/systemd/system/opendkim.service.d/override.conf\n"),
            default => touch($host['scratch'].'/toggles/'.$toggle),
        };

        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailSigningTree($host);

        [$verify, $report] = mailSigningRun($host, ['--verify']);
        expect($verify)->not->toBe(0, $report);
        expect($report)->toContain('CONFLICT runtime — '.$problem);

        // --verify only reads.
        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
        expect(mailSigningTree($host))->toBe($before);
    } finally {
        mailSigningCleanup($host);
    }
})->with([
    'not running' => ['stopped', 'opendkim.service is not active and running'],
    'not enabled for boot' => ['not enabled', 'opendkim.service is not enabled for boot'],
    'running as root' => ['root', 'the signer runs as "root", not opendkim'],
    'a drop-in override' => ['drop-in', 'opendkim.service carries drop-in overrides'],
    'a public listener' => ['public-listener', 'the signer also listens on tcp 0.0.0.0:8891 — it listens on 127.0.0.1:8891 and nowhere else'],
    'an IPv6 listener' => ['ipv6-listener', 'the signer also listens on tcp [::]:8891'],
    'a UNIX socket' => ['unix-socket', 'the signer also listens on a UNIX socket'],
]);

it('reports drift in the installed configuration, and --apply restores the render', function () {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        [$applied, $log] = mailSigningRun($host, ['--apply']);
        expect($applied)->toBe(0, $log);

        file_put_contents($host['fs'].'/etc/opendkim/SigningTable', "*@* rg1._domainkey.tits.guru\n", FILE_APPEND);

        [$verify, $report] = mailSigningRun($host, ['--verify']);
        expect($verify)->not->toBe(0);
        expect($report)->toContain('DRIFT    file:/etc/opendkim/SigningTable — differs from the current render');

        [$again, $output] = mailSigningRun($host, ['--apply']);
        expect($again)->toBe(0, $output);
        expect($output)->toContain('installing /etc/opendkim/SigningTable')->toContain('restarting opendkim.service with the new configuration');
        expect(File::get($host['fs'].'/etc/opendkim/SigningTable'))->not->toContain('*@*');

        [$verified] = mailSigningRun($host, ['--verify']);
        expect($verified)->toBe(0);
    } finally {
        mailSigningCleanup($host);
    }
});

it('never installs or starts a configuration OpenDKIM rejects', function () {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        touch($host['scratch'].'/toggles/opendkim-reject');

        [$status, $output] = mailSigningRun($host, ['--apply']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('OpenDKIM does not accept the rendered signing configuration: opendkim -n rejects the configuration');

        // The package's own configuration is still what it shipped, the
        // RateGuru tables were never written, the key is untouched and the
        // service never started.
        expect(File::get($host['fs'].'/etc/opendkim.conf'))->toBe("Socket local:/run/opendkim/opendkim.sock\n");
        expect(file_exists($host['fs'].'/etc/opendkim/KeyTable'))->toBeFalse();
        expect(mailSigningMetadata($host, $host['fs'].'/etc/opendkim/keys/tits-guru/rg1.private'))->toBe('root:root 600');
        expect(mailSigningLog($host, 'mutations.log'))->not->toContain('systemctl start')->not->toContain('chown');
    } finally {
        mailSigningCleanup($host);
    }
});

it('is idempotent: a second --apply installs, re-permissions and restarts nothing', function () {
    $host = mailSigningHost(['key' => 'rsa2048']);

    try {
        [$first, $log] = mailSigningRun($host, ['--apply']);
        expect($first)->toBe(0, $log);

        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailSigningTree($host);

        [$second, $output] = mailSigningRun($host, ['--apply']);
        expect($second)->toBe(0, $output);
        expect($output)
            ->toContain('signing configuration unchanged — opendkim.service left running as it is')
            ->not->toContain('installing /etc')
            ->not->toContain('granting the signer');

        $mutations = mailSigningLog($host, 'mutations.log');
        expect($mutations)->not->toContain('apt-get')->not->toContain('chown')->not->toContain('restart')->not->toContain('systemctl start');
        expect(mailSigningTree($host))->toBe($before);
    } finally {
        mailSigningCleanup($host);
    }
});

it('changes nothing in --check or --verify', function (array $options) {
    $host = mailSigningHost($options);

    try {
        $before = mailSigningTree($host);

        mailSigningRun($host, ['--check']);
        mailSigningRun($host, ['--verify']);
        mailSigningRun($host, ['--verify', '--target', 'tits-guru']);
        mailSigningRun($host, ['--milter-endpoint']);

        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
        expect(mailSigningLog($host, 'apt-suppression.log'))->toBe('');
        expect(mailSigningTree($host))->toBe($before);
    } finally {
        mailSigningCleanup($host);
    }
})->with([
    'a fresh host with a pre-signer key' => [['key' => 'rsa2048']],
    'an unmanaged OpenDKIM' => [['package' => 'installed', 'key' => 'rsa2048']],
    'an interrupted installation' => [['marker' => 'installing', 'package' => 'partial', 'key' => 'rsa2048']],
]);

it('takes exactly one mode, and --target only with --verify', function (array $arguments, string $reason) {
    $host = mailSigningHost();

    try {
        [$status, $output] = mailSigningRun($host, $arguments);
        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect(mailSigningLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailSigningCleanup($host);
    }
})->with([
    'no mode' => [[], 'one of --check, --apply, --verify or --milter-endpoint is required'],
    'two modes' => [['--check', '--verify'], 'mode given more than once'],
    'a target for --apply' => [['--apply', '--target', 'tits-guru'], '--target is an option of --verify only'],
    'a malformed target' => [['--verify', '--target', 'Tits_Guru'], 'invalid target ID: Tits_Guru'],
    'an unknown option' => [['--verify', '--force'], 'unknown argument: --force'],
    'a target with no identity' => [['--verify', '--target', 'staging-main'], 'staging-main has no reviewed signing identity in the signing plan'],
]);

it('refuses to run its root modes as anyone but root', function (string $mode) {
    $host = mailSigningHost();

    try {
        [$status, $output] = mailSigningRun($host, [$mode], ['RATEGURU_MAILSIGN_EUID' => '1000']);
        expect($status)->not->toBe(0);
        expect($output)->toContain("install-mail-signing {$mode} must run as root");
    } finally {
        mailSigningCleanup($host);
    }
})->with(['--apply', '--verify']);

// =============================================================================
// VERIFY-MAIL-SIGNING --READ-ONLY: THE OWNERS' VERDICTS
// =============================================================================

/**
 * verify-mail-signing on a host where the signer's and the gateway's own
 * verifies are stubs that log their arguments and answer as told.
 *
 * @return array{scratch: string, env: array<string, string>}
 */
function mailSigningVerifierHost(bool $signer = true, bool $gateway = true): array
{
    $scratch = makeScratchDir('mail-signing-verify', ['', '/bin', '/state', '/log', '/no-wait']);

    foreach (['install-mail-signing' => $signer, 'install-mail-gateway' => $gateway] as $name => $passes) {
        file_put_contents($scratch.'/bin/'.$name, "#!/bin/bash\nprintf '%s %s\\n' '{$name}' \"\$*\" >> \"\${STUB_LOG}/owners.log\"\necho \"  {$name} report\"\n".($passes ? "exit 0\n" : "echo '  CONFLICT something'\nexit 1\n"));
        chmod($scratch.'/bin/'.$name, 0o755);
    }

    foreach (['postqueue', 'postsuper', 'postcat'] as $tool) {
        file_put_contents($scratch.'/bin/'.$tool, "#!/bin/bash\nprintf '%s %s\\n' '{$tool}' \"\$*\" >> \"\${STUB_LOG}/queue.log\"\nexit 0\n");
        chmod($scratch.'/bin/'.$tool, 0o755);
    }

    file_put_contents($scratch.'/no-wait/sleep', "#!/bin/sh\nexit 0\n");
    chmod($scratch.'/no-wait/sleep', 0o755);

    return ['scratch' => $scratch, 'env' => [
        'PATH' => $scratch.'/no-wait:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => $scratch,
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MAILSIGN_EUID' => '0',
        'RATEGURU_MAILSIGN_INSTALLER_BIN' => $scratch.'/bin/install-mail-signing',
        'RATEGURU_MAILSIGN_GATEWAY_INSTALLER_BIN' => $scratch.'/bin/install-mail-gateway',
        'RATEGURU_MAILSIGN_POSTQUEUE_BIN' => $scratch.'/bin/postqueue',
        'RATEGURU_MAILSIGN_POSTSUPER_BIN' => $scratch.'/bin/postsuper',
        'RATEGURU_MAILSIGN_POSTCAT_BIN' => $scratch.'/bin/postcat',
        'RATEGURU_MAILSIGN_POLL_TIMEOUT' => '1',
        'STUB_LOG' => $scratch.'/log',
    ]];
}

/** @return list<string> */
function mailSigningVerifierLog(array $host, string $name): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($host['scratch'].'/log/'.$name))));
}

/** The machine-readable result line of a verify-mail-signing run. */
function mailSigningResult(string $output): ?array
{
    return preg_match('/^RATEGURU_MAIL_SIGNING_RESULT=(\{.*\})$/m', $output, $m) ? json_decode($m[1], true) : null;
}

it('verifies signing read-only by composing the signer\'s and the gateway\'s own verifies', function () {
    $host = mailSigningVerifierHost();

    try {
        [$status, $output] = mailSigningRun($host, ['--read-only', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('PASS tits-guru is signed as d=tits.guru s=rg1 a=rsa-sha256 (mail-identity render-signing-plan)')
            ->toContain('PASS the signer: install-mail-signing --verify --target tits-guru')
            ->toContain('PASS the gateway\'s wiring: install-mail-gateway --verify')
            ->toContain('      |   install-mail-signing report')
            ->toContain('SIGNING VERIFIED: YES');
        expect(mailSigningResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'read-only', 'status' => 'pass']);

        // The owners, in order, read-only — and nothing touched the queue.
        expect(mailSigningVerifierLog($host, 'owners.log'))->toBe([
            'install-mail-signing --verify --target tits-guru',
            'install-mail-gateway --verify',
        ]);
        expect(mailSigningVerifierLog($host, 'queue.log'))->toBe([]);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('fails read-only on either owner\'s refusal, reporting both', function (bool $signer, bool $gateway, string $failure) {
    $host = mailSigningVerifierHost($signer, $gateway);

    try {
        [$status, $output] = mailSigningRun($host, ['--read-only', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1);
        expect($output)->toContain($failure)->toContain('SIGNING VERIFIED: NO');
        expect(mailSigningResult($output)['status'])->toBe('fail');
        expect(mailSigningVerifierLog($host, 'owners.log'))->toHaveCount(2);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'the signer' => [false, true, 'FAIL the signer: install-mail-signing --verify --target tits-guru (exit 1)'],
    'the gateway' => [true, false, 'FAIL the gateway\'s wiring: install-mail-gateway --verify (exit 1)'],
]);

it('verifies nothing for a target with no reviewed signing identity', function () {
    $host = mailSigningVerifierHost();

    try {
        [$status, $output] = mailSigningRun($host, ['--read-only', '--target', 'staging-main'], script: 'verify-mail-signing');

        expect($status)->toBe(1);
        expect($output)->toContain('FAIL staging-main has no reviewed signing identity in the signing plan — nothing on this host signs its mail');
        expect(mailSigningVerifierLog($host, 'owners.log'))->toBe([]);
    } finally {
        removeScratchDir($host['scratch']);
    }
});

it('takes a mode and a target, never defaulting to the mutating acceptance', function (array $arguments, string $reason) {
    $host = mailSigningVerifierHost();

    try {
        [$status, $output] = mailSigningRun($host, $arguments, script: 'verify-mail-signing');
        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect(mailSigningVerifierLog($host, 'owners.log'))->toBe([]);
    } finally {
        removeScratchDir($host['scratch']);
    }
})->with([
    'no mode' => [['--target', 'tits-guru'], 'a mode is required: --read-only or --e2e (the mutating acceptance is never a default)'],
    'no target' => [['--e2e'], '--e2e requires --target'],
    'two modes' => [['--read-only', '--e2e', '--target', 'tits-guru'], 'mode given more than once'],
    'a malformed target' => [['--e2e', '--target', '../x'], 'invalid target ID: ../x'],
    'an unknown option' => [['--read-only', '--target', 'tits-guru', '--not-an-option'], 'unknown argument: --not-an-option'],
]);

it('keeps --read-only free of SMTP, queue and service commands', function () {
    $source = File::get(mailSigningScript('verify-mail-signing'));

    foreach (['run_read_only', 'read_only_checks', 'signing_identity', 'run_owner'] as $function) {
        expect(executableSourceLines(shellFunctionBody($source, $function)))
            ->not->toContain('smtp_submit')
            ->not->toContain('/dev/tcp')
            ->not->toContain('POSTSUPER_BIN')
            ->not->toContain('POSTQUEUE_BIN')
            ->not->toContain('POSTCAT_BIN')
            ->not->toContain('systemctl')
            ->not->toContain('--apply')
            ->not->toContain('--e2e');
    }

    expect(executableSourceLines(shellFunctionBody($source, 'read_only_checks')))
        ->toContain('"${SIGNING_INSTALLER}" --verify --target "${TARGET_ID}"')
        ->toContain('"${GATEWAY_INSTALLER}" --verify');
});

// =============================================================================
// VERIFY-MAIL-SIGNING --E2E: ONE HELD PROBE, SIGNED, AND GONE
// =============================================================================

/**
 * A fake Postfix listener on loopback: it accepts one probe per session, puts
 * it in the queue file as Postfix would (HOLD, or another queue when told) and
 * remembers its headers — with the DKIM-Signature the signer would have added,
 * as the toggle says. The postqueue, postcat and postsuper stubs read and change
 * only that queue file.
 *
 * Like the gateway's From policy, it refuses — 550, nothing queued — a message
 * whose From is not exactly one address at tits.guru.
 *
 * Toggles (files under state/toggles): signature — the DKIM-Signature header to
 * add (none when absent); queue — the queue the probe lands in (hold when
 * absent); no-queue-id; refuse-rcpt; accept-foreign — no From policy at all;
 * tempfail-foreign — a foreign From deferred (451) rather than refused.
 */
function mailSigningFakePostfix(): string
{
    return <<<'PHP'
        <?php
        $state = $argv[1];
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) { fwrite(STDERR, $error); exit(1); }
        echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        fflush(STDOUT);
        stream_set_blocking(STDIN, false);
        $toggle = static fn (string $name): ?string => is_file("{$state}/toggles/{$name}") ? (string) file_get_contents("{$state}/toggles/{$name}") : null;

        while (true) {
            $read = [$server, STDIN]; $write = null; $except = null;
            if (@stream_select($read, $write, $except, 1) === false) { break; }
            if (in_array(STDIN, $read, true) && feof(STDIN)) { break; }
            if (! in_array($server, $read, true)) { if (feof(STDIN)) { break; } continue; }

            $client = @stream_socket_accept($server, 5);
            if ($client === false) { continue; }
            $say = static function (string $line) use ($client): void { fwrite($client, $line."\r\n"); };
            $log = static fn (string $line) => file_put_contents("{$state}/smtp.log", $line."\n", FILE_APPEND);

            $say('220 mail-gateway.rateguru.invalid ESMTP');
            $rcpt = '';
            while (($line = fgets($client)) !== false) {
                $line = rtrim($line, "\r\n");
                $log($line);
                $verb = strtoupper(substr($line, 0, 4));
                if ($verb === 'EHLO') { $say('250-mail-gateway.rateguru.invalid'); $say('250 8BITMIME'); }
                elseif ($verb === 'MAIL') { $say('250 2.1.0 Ok'); }
                elseif ($verb === 'RCPT') {
                    if ($toggle('refuse-rcpt') !== null) { $say('554 5.7.1 refused'); continue; }
                    preg_match('/<([^>]*)>/', $line, $m); $rcpt = $m[1] ?? ''; $say('250 2.1.5 Ok');
                }
                elseif ($verb === 'DATA') {
                    $say('354 End data with <CR><LF>.<CR><LF>');
                    $message = '';
                    while (($data = fgets($client)) !== false) {
                        if (rtrim($data, "\r\n") === '.') { break; }
                        $message .= $data;
                    }
                    $headers = substr($message, 0, (int) strpos($message, "\r\n\r\n"));
                    preg_match('/^From:(.*)$/mi', str_replace("\r", '', $headers), $from);
                    $ours = preg_match('/^\s*(?:"[^"<>@,]*"\s*|[^"<>@,]+)?<?[A-Za-z0-9._%+-]+@tits\.guru>?\s*$/i', $from[1] ?? '') === 1;
                    if (! $ours && $toggle('accept-foreign') === null) {
                        $say($toggle('tempfail-foreign') !== null ? '451 4.7.1 Service unavailable - try again later' : '550 5.7.1 RateGuru mail gateway: the From header must be exactly one address in the reviewed sender domain');
                        continue;
                    }
                    $id = strtoupper(bin2hex(random_bytes(5)));
                    $signature = $toggle('signature');
                    file_put_contents("{$state}/headers-{$id}", ($signature !== null ? $signature : '').str_replace("\r\n", "\n", $headers)."\n");
                    $queue = trim($toggle('queue') ?? 'hold');
                    file_put_contents("{$state}/queue", "{$id}\t{$queue}\t{$rcpt}\n", FILE_APPEND);
                    $say($toggle('no-queue-id') !== null ? '250 2.0.0 Ok' : "250 2.0.0 Ok: queued as {$id}");
                }
                elseif ($verb === 'QUIT') { $say('221 2.0.0 Bye'); break; }
                else { $say('502 5.5.2 Error'); }
            }
            fclose($client);
        }
        PHP;
}

/**
 * verify-mail-signing --e2e against the fake listener: the committed policy,
 * with tits-guru's submission port pointed at it, its owners' verifies stubbed
 * to pass, and queue tools that see only the fake queue — which already holds
 * someone else's message.
 *
 * @return array{scratch: string, env: array<string, string>, server: resource, pipes: array<int, resource>, port: int}
 */
function mailSigningE2eHost(array $toggles = [], ?string $policy = null): array
{
    $host = mailSigningVerifierHost();
    $state = $host['scratch'].'/state';
    @mkdir($state.'/toggles', 0o755, true);
    file_put_contents($state.'/queue', "FOREIGN0001\tdeferred\tsomeone@example.net\n");

    foreach ($toggles as $name => $value) {
        file_put_contents($state.'/toggles/'.$name, $value);
    }

    file_put_contents($host['scratch'].'/fake-postfix.php', mailSigningFakePostfix());
    $server = proc_open([PHP_BINARY, $host['scratch'].'/fake-postfix.php', $state], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    expect($port)->toBeGreaterThan(0);

    $routing = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);
    $routing['targets']['tits-guru']['submission']['port'] = $port;
    if ($policy === 'demo-shop outbound') {
        $routing['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    }
    file_put_contents($host['scratch'].'/mail-routing.json', mailRoutingJson($routing));

    $queue = [
        'postqueue' => <<<'STUB'
            #!/bin/bash
            printf 'postqueue %s\n' "$*" >> "${STUB_LOG}/queue.log"
            [[ "$1" == -j ]] || exit 1
            while IFS=$'\t' read -r id queue rcpt; do
                [[ -n "${id}" ]] || continue
                printf '{"queue_name": "%s", "queue_id": "%s", "sender": "", "recipients": [{"address": "%s"}]}\n' "${queue}" "${id}" "${rcpt}"
            done < "${STUB_STATE}/queue"
            STUB,
        'postcat' => <<<'STUB'
            #!/bin/bash
            printf 'postcat %s\n' "$*" >> "${STUB_LOG}/queue.log"
            [[ "$1" == -h && "$2" == -q && -n "${3:-}" ]] || exit 1
            cat "${STUB_STATE}/headers-$3" 2>/dev/null || exit 1
            STUB,
        'postsuper' => <<<'STUB'
            #!/bin/bash
            printf 'postsuper %s\n' "$*" >> "${STUB_LOG}/queue.log"
            [[ "$1" == -d && -n "${2:-}" ]] || exit 1
            awk -F'\t' -v id="$2" -v queue="${3:-}" '!($1 == id && (queue == "" || $2 == queue))' "${STUB_STATE}/queue" > "${STUB_STATE}/queue.next" \
                && mv "${STUB_STATE}/queue.next" "${STUB_STATE}/queue"
            STUB,
    ];

    foreach ($queue as $name => $body) {
        file_put_contents($host['scratch'].'/bin/'.$name, $body."\n");
        chmod($host['scratch'].'/bin/'.$name, 0o755);
    }

    $host['env']['RATEGURU_MAILSIGN_ROUTING_FILE'] = $host['scratch'].'/mail-routing.json';
    $host['env']['STUB_STATE'] = $state;

    return [...$host, 'server' => $server, 'pipes' => $pipes, 'port' => $port];
}

function mailSigningE2eCleanup(array $host): void
{
    fclose($host['pipes'][0]);
    proc_terminate($host['server']);
    proc_close($host['server']);
    removeScratchDir($host['scratch']);
}

/** @return list<string> */
function mailSigningE2eQueue(array $host): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($host['scratch'].'/state/queue'))));
}

/** A DKIM-Signature header, folded the way OpenDKIM folds it, with tags replaced. */
function mailSigningSignature(array $tags = []): string
{
    $tags = [...['v' => '1', 'a' => 'rsa-sha256', 'c' => 'relaxed/simple', 'd' => 'tits.guru', 's' => 'rg1', 't' => '1791326274', 'bh' => 's93pjRTdoHnX+R5LLgjnI603C6tLacACuyvV+FBAl9A=', 'h' => 'From:To:Subject:Date:From', 'b' => 'RqHzQsagujbXcK1gEVOVJ7ZRT6CflKe3rw3L1Gzlbwmn4C4+2EwVDmYzjNoCwy4zZ1gVkoQ1/5B+dDvHJiCcIotDCL2UKd12jSMbearxhiM0y6tlYFwP5upc575M8Us1P+u'], ...$tags];
    $parts = [];
    foreach ($tags as $tag => $value) {
        if ($value !== null) {
            $parts[] = "{$tag}={$value}";
        }
    }

    return 'DKIM-Signature: '.implode(";\n\t", $parts)."\n";
}

it('submits one held probe, finds it signed by the target\'s identity in HOLD, and deletes exactly it', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature()]);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(0, $output);
        $result = mailSigningResult($output);
        expect($result['status'])->toBe('pass');
        expect($result['foreign_from_rejected'])->toBeTrue();
        expect($result['held'])->toBeTrue();
        expect($result['removed'])->toBeTrue();
        expect($result['signature'])->toBe(['d' => 'tits.guru', 's' => 'rg1', 'a' => 'rsa-sha256']);
        expect($result['queue_id'])->toMatch('/^[0-9A-F]{10}$/');
        $id = $result['queue_id'];

        expect($output)
            ->toContain('PASS a message from noreply@tits.guru with a From outside tits.guru was refused before it was queued (550 5.7.1), and nothing of it is in the queue')
            ->toContain("PASS queue entry {$id} is in the HOLD queue")
            ->toContain('PASS the probe carries exactly one DKIM-Signature: d=tits.guru s=rg1 a=rsa-sha256')
            ->toContain("PASS queue entry {$id} stayed held until it was deleted, and is gone")
            ->toContain('SIGNING E2E: PASS');

        // Two messages, both from the reviewed sender, to reserved recipients,
        // through tits-guru's listener alone: the foreign From first, refused;
        // then the one that is signed.
        $smtp = File::get($host['scratch'].'/state/smtp.log');
        expect(substr_count($smtp, 'DATA'))->toBe(2);
        expect(substr_count($smtp, 'MAIL FROM:<noreply@tits.guru>'))->toBe(2);
        expect(preg_match('/^RCPT TO:<signing-probe-foreign-mgsign\d+@rateguru\.invalid>$/m', $smtp))->toBe(1);
        expect(preg_match('/^RCPT TO:<signing-probe-mgsign\d+@rateguru\.invalid>$/m', $smtp))->toBe(1);
        expect(strpos($smtp, 'signing-probe-foreign-'))->toBeLessThan(strpos($smtp, 'RCPT TO:<signing-probe-mgsign'));

        // The queue: read, its one entry's headers read, that entry deleted
        // from HOLD — and nothing else, ever.
        $calls = mailSigningVerifierLog($host, 'queue.log');
        expect(array_values(array_unique(array_filter($calls, static fn (string $c): bool => ! str_starts_with($c, 'postqueue -j')))))
            ->toBe(["postcat -h -q {$id}", "postsuper -d {$id} hold"]);
        expect(array_filter($calls, static fn (string $c): bool => str_starts_with($c, 'postqueue') && $c !== 'postqueue -j'))->toBe([]);

        // Someone else's message is still there; the probe is not.
        expect(mailSigningE2eQueue($host))->toBe(["FOREIGN0001\tdeferred\tsomeone@example.net"]);

        // No signature value reached the output.
        expect($output)->not->toContain('RqHzQsagujbXcK1g')->not->toContain('s93pjRTdoHnX');
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('fails on every signature that is not exactly the target\'s, and still removes its probe', function (string $signature, string $failure) {
    $host = mailSigningE2eHost(['signature' => $signature]);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1, $output);
        expect($output)->toMatch('/^  FAIL .*'.preg_quote($failure, '/').'/m')->toContain('SIGNING E2E: FAIL');
        expect(mailSigningResult($output)['signature'])->toBeNull();

        // Cleanup still deleted exactly the probe.
        expect(mailSigningE2eQueue($host))->toBe(["FOREIGN0001\tdeferred\tsomeone@example.net"]);
    } finally {
        mailSigningE2eCleanup($host);
    }
})->with([
    'no signature' => ['', 'the probe was not signed: its queue entry carries no DKIM-Signature'],
    'another domain' => [mailSigningSignature(['d' => 'example.net']), 'the DKIM-Signature signs as d=example.net, not tits-guru\'s d=tits.guru'],
    'a subdomain' => [mailSigningSignature(['d' => 'mail.tits.guru']), 'signs as d=mail.tits.guru'],
    'another selector' => [mailSigningSignature(['s' => 'old']), 'the DKIM-Signature has s=old, not tits-guru\'s selector s=rg1'],
    'another algorithm' => [mailSigningSignature(['a' => 'rsa-sha1']), 'the DKIM-Signature has a=rsa-sha1, not tits-guru\'s algorithm a=rsa-sha256'],
    'two signatures' => [mailSigningSignature().mailSigningSignature(), 'the probe carries 2 DKIM-Signature headers'],
    'no body hash' => [mailSigningSignature(['bh' => null]), 'carries no body hash (bh=)'],
    'no signature value' => [mailSigningSignature(['b' => '']), 'carries no signature (b=)'],
    'From not covered' => [mailSigningSignature(['h' => 'To:Subject:Date']), 'does not cover the From header'],
    'another DKIM version' => [mailSigningSignature(['v' => '2']), 'has v=2, not v=1'],
]);

it('fails when a foreign From is accepted, removes only that probe, and never goes on to sign anything', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature(), 'accept-foreign' => '1']);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain('FAIL the held listener ACCEPTED a message whose From is outside tits.guru')
            ->toContain('SIGNING E2E: FAIL');

        $result = mailSigningResult($output);
        expect($result['status'])->toBe('fail');
        expect($result['foreign_from_rejected'])->toBeFalse();
        expect($result['signature'])->toBeNull();

        // Only the refused-to-be-refused message was submitted, and only it was
        // removed — by its exact ID; someone else's entry is untouched.
        expect(substr_count(File::get($host['scratch'].'/state/smtp.log'), 'DATA'))->toBe(1);
        expect(mailSigningE2eQueue($host))->toBe(["FOREIGN0001\tdeferred\tsomeone@example.net"]);
        $calls = implode("\n", mailSigningVerifierLog($host, 'queue.log'));
        expect($calls)->toMatch('/^postsuper -d [0-9A-F]{10}$/m')->not->toContain('postcat')->not->toContain('FOREIGN0001');
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('fails when a foreign From is only deferred, not refused', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature(), 'tempfail-foreign' => '1']);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1);
        expect($output)->toContain('FAIL a message with a foreign From was not refused permanently at the end of its data (message: 451 4.7.1');
        expect(mailSigningResult($output)['foreign_from_rejected'])->toBeFalse();
        expect(substr_count(File::get($host['scratch'].'/state/smtp.log'), 'DATA'))->toBe(1);
        expect(mailSigningE2eQueue($host))->toBe(["FOREIGN0001\tdeferred\tsomeone@example.net"]);
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('reports a refusal as a machine-readable failure once the target is known, and before that reports nothing', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature()]);

    try {
        // Refused before any connection: still one result line, a failure.
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'staging-main'], script: 'verify-mail-signing');
        expect($status)->toBe(1);
        expect(substr_count($output, 'RATEGURU_MAIL_SIGNING_RESULT='))->toBe(1);
        expect(mailSigningResult($output))->toBe([
            'target' => 'staging-main', 'mode' => 'e2e', 'status' => 'fail',
            'foreign_from_rejected' => false, 'queue_id' => null, 'held' => false, 'removed' => false, 'signature' => null,
        ]);

        // Not root: the same.
        [$status, $output] = mailSigningRun($host, ['--read-only', '--target', 'tits-guru'], ['RATEGURU_MAILSIGN_EUID' => '1000'], 'verify-mail-signing');
        expect($status)->toBe(1);
        expect(mailSigningResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'read-only', 'status' => 'fail']);

        // Arguments it never understood carry no mode or target to report.
        foreach ([['--e2e'], ['--target', 'tits-guru'], ['--e2e', '--target', 'Not_A_Target']] as $arguments) {
            [$status, $output] = mailSigningRun($host, $arguments, script: 'verify-mail-signing');
            expect($status)->toBe(1);
            expect($output)->not->toContain('RATEGURU_MAIL_SIGNING_RESULT=');
        }
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('fails when the probe is not held, and removes it from wherever it went', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature(), 'queue' => 'deferred']);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1);
        expect($output)->toMatch('/FAIL queue entry [0-9A-F]{10} is not in the HOLD queue \(it is in: deferred\)/');
        expect(mailSigningResult($output)['held'])->toBeFalse();

        // The headers were never inspected, and cleanup removed the probe by
        // its exact ID — never the queue.
        expect(implode("\n", mailSigningVerifierLog($host, 'queue.log')))->not->toContain('postcat');
        expect(mailSigningE2eQueue($host))->toBe(["FOREIGN0001\tdeferred\tsomeone@example.net"]);
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('finds and removes its probe by its unique recipient when Postfix named no queue ID', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature(), 'no-queue-id' => '1']);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1);
        expect($output)->toContain('FAIL Postfix accepted the probe but named no queue ID');
        expect(mailSigningE2eQueue($host))->toBe(["FOREIGN0001\tdeferred\tsomeone@example.net"]);
        expect(implode("\n", mailSigningVerifierLog($host, 'queue.log')))->toMatch('/postsuper -d [0-9A-F]{10}$/m');
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('refuses an outbound target before any SMTP connection', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature()], 'demo-shop outbound');

    try {
        $identity = json_decode(File::get(base_path('infrastructure/config/mail-identity.json')), true);
        $identity['targets']['demo-shop'] = mailIdentityDemoShopIdentity();
        file_put_contents($host['scratch'].'/registry.json', mailRoutingJson(mailRoutingDemoShopRegistry()));
        file_put_contents($host['scratch'].'/identity.json', mailRoutingJson($identity));
        file_put_contents($host['scratch'].'/outbound.json', mailRoutingJson(['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net']]));

        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'demo-shop'], [
            'RATEGURU_MAILSIGN_REGISTRY_FILE' => $host['scratch'].'/registry.json',
            'RATEGURU_MAILSIGN_IDENTITY_FILE' => $host['scratch'].'/identity.json',
            'RATEGURU_MAILSIGN_OUTBOUND_FILE' => $host['scratch'].'/outbound.json',
        ], 'verify-mail-signing');

        expect($status)->not->toBe(0, $output);
        expect($output)
            ->toContain("demo-shop's mail is outbound with route {\"kind\":\"direct\"}: the signing acceptance only ever submits to a HELD listener with no route")
            ->toContain('nothing was submitted');
        expect(mailSigningResult($output)['status'])->toBe('fail');

        // No connection, no owner asked, no queue touched.
        expect(file_exists($host['scratch'].'/state/smtp.log'))->toBeFalse();
        expect(mailSigningVerifierLog($host, 'owners.log'))->toBe([]);
        expect(mailSigningVerifierLog($host, 'queue.log'))->toBe([]);
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('refuses the capture target by name before connecting', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature()]);

    try {
        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'staging-main'], script: 'verify-mail-signing');

        expect($status)->not->toBe(0);
        expect($output)->toContain("staging-main's mail is capture with route")->toContain('nothing was submitted');
        expect(file_exists($host['scratch'].'/state/smtp.log'))->toBeFalse();
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('submits nothing when the read-only contract does not hold', function () {
    $host = mailSigningE2eHost(['signature' => mailSigningSignature()]);

    try {
        file_put_contents($host['scratch'].'/bin/install-mail-signing', "#!/bin/bash\nprintf 'install-mail-signing %s\\n' \"\$*\" >> \"\${STUB_LOG}/owners.log\"\nexit 1\n");

        [$status, $output] = mailSigningRun($host, ['--e2e', '--target', 'tits-guru'], script: 'verify-mail-signing');

        expect($status)->toBe(1);
        expect($output)->toContain('the read-only signing contract does not hold — nothing was submitted');
        expect(file_exists($host['scratch'].'/state/smtp.log'))->toBeFalse();
        expect(mailSigningVerifierLog($host, 'queue.log'))->toBe([]);
    } finally {
        mailSigningE2eCleanup($host);
    }
});

it('never flushes, empties or releases the queue, and connects only to the target\'s own listener', function () {
    $code = executableSourceLines(File::get(mailSigningScript('verify-mail-signing')));

    foreach (['postqueue -f', 'POSTQUEUE_BIN}" -f', 'POSTQUEUE_BIN}" -p', 'POSTQUEUE_BIN}" -i', '-d ALL', '-H ALL', '-r ALL', 'POSTSUPER_BIN}" -H', 'POSTSUPER_BIN}" -r', 'POSTSUPER_BIN}" -h', 'POSTSUPER_BIN}" -d -', 'curl', 'wget', 'sendmail'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("verify-mail-signing uses {$forbidden}");
    }

    // One connection target: the held listener the plan names, on loopback.
    expect(substr_count($code, '/dev/tcp/'))->toBe(1);
    expect($code)->toContain('exec 4<>"/dev/tcp/${LISTEN_HOST}/${LISTEN_PORT}"');
    expect($code)->toContain('[[ "${mode}" == held && "${route}" == null ]]');

    // Deletion is by exact ID only — from HOLD on the normal path.
    expect($code)->toContain('"${POSTSUPER_BIN}" -d "${QUEUE_ID}" hold')->toContain('"${POSTSUPER_BIN}" -d "${id}"');
    expect(substr_count($code, '"${POSTSUPER_BIN}"'))->toBe(2);

    // Headers only, of the probe's own entry.
    expect($code)->toContain('"${POSTCAT_BIN}" -h -q "${QUEUE_ID}"');
    expect(substr_count($code, '"${POSTCAT_BIN}"'))->toBe(1);
});

// =============================================================================
// HOST BOOTSTRAP: AFTER CAPTURE, BEFORE THE GATEWAY, AT HOST SCOPE ONLY
// =============================================================================

it('converges the signer after mail capture and before the gateway, at host scope only', function () {
    $services = File::get(base_path('infrastructure/scripts/install-bootstrap-services'));
    $apply = shellFunctionBody($services, 'perform_apply');

    $capture = strpos($apply, 'converge_mail_capture');
    $signing = strpos($apply, 'converge_mail_signing');
    $gateway = strpos($apply, 'converge_mail_gateway');
    expect($capture)->not->toBeFalse();
    expect($signing)->toBeGreaterThan($capture);
    expect($gateway)->toBeGreaterThan($signing);

    // Its plan gate runs before the first mutation, ahead of the gateway's.
    expect(strpos($apply, 'apply_gate_mail_signing'))->toBeLessThan(strpos($apply, 'apply_gate_mail_gateway'));
    expect(strpos($apply, 'apply_gate_mail_signing'))->toBeLessThan(strpos($apply, 'trap on_apply_error ERR EXIT'));
    expect(shellFunctionBody($services, 'apply_gate_mail_signing'))->toContain('target_scoped && return 0');

    // Reported between capture and the gateway, only when not target-scoped.
    $report = shellFunctionBody($services, 'report_child_contracts');
    expect(strpos($report, '"mail-signing:install-mail-signing"'))
        ->toBeGreaterThan(strpos($report, '"mail-capture:verify-mail-capture"'))
        ->toBeLessThan(strpos($report, '"mail-gateway:install-mail-gateway"'));

    // Through its own read-only verify and its own apply, never the acceptance.
    expect(shellFunctionBody($services, 'converge_mail_signing'))
        ->toContain('"${MAIL_SIGNING_INSTALLER_BIN}" --verify')
        ->toContain('"${MAIL_SIGNING_INSTALLER_BIN}" --apply');
    expect(executableSourceLines($services))
        ->toContain('MAIL_SIGNING_INSTALLER_BIN="$(gated_default RATEGURU_BOOTSTRAPSVC_MAIL_SIGNING_INSTALLER_BIN "${SCRIPT_DIR}/install-mail-signing")"')
        ->not->toContain('verify-mail-signing');
});

it('keeps target-scoped repair, provisioning and configuration away from the host-global signer', function () {
    foreach (['provision-target', 'repair-target', 'configure-target', 'prepare-host', 'bootstrap-host', 'recover-host'] as $orchestrator) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$orchestrator))))
            ->not->toContain('mail-signing')
            ->not->toContain('opendkim.conf')
            ->not->toContain('milter');
    }
});

it('never runs the mutating signing acceptance from ordinary preparation, repair or verification', function () {
    foreach ([
        'infrastructure/scripts/install-bootstrap-services',
        'infrastructure/scripts/bootstrap-host',
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/repair-target',
        'infrastructure/scripts/verify-infrastructure',
        'infrastructure/scripts/mail-identity',
        '.github/workflows/prepare-staging-host.yml',
        '.github/workflows/prepare-production-host.yml',
        '.github/workflows/verify-production-infrastructure.yml',
        '.github/workflows/verify-staging-infrastructure.yml',
        '.github/actions/prepare-rateguru-host/action.yml',
        '.github/actions/verify-rateguru-infrastructure/action.yml',
    ] as $path) {
        $code = executableSourceLines(File::get(base_path($path)));
        expect(preg_match('/verify-mail-signing[^\n]*--e2e|--e2e[^\n]*verify-mail-signing/', $code))->toBe(0, "{$path} runs the signing acceptance");
    }
});

it('ships both signing CLIs with the host tooling, as the gateway\'s are', function () {
    foreach (['install-mail-signing', 'verify-mail-signing'] as $name) {
        expect(requiredCliManifestNames())->toContain($name);
        expect(is_executable(mailSigningScript($name)))->toBeTrue();
    }

    expect(repositoryOnlyScriptNames())->not->toContain('install-mail-signing')->not->toContain('verify-mail-signing');
});

// =============================================================================
// THE WORKFLOW: VERIFY PRODUCTION MAIL SIGNING
// =============================================================================

it('runs the signing acceptance from main only, for tits-guru only, with nothing an operator can choose', function () {
    $source = File::get(base_path('.github/workflows/verify-production-mail-signing.yml'));
    $workflow = Yaml::parse($source);

    expect($workflow['name'])->toBe('Verify production mail signing');

    // Dispatched by hand, with no input at all: no target, no ref, no command.
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect($workflow['permissions'])->toBe(['contents' => 'read']);

    // The shared host's concurrency domain, waiting rather than cancelling.
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    // The main-only gate, byte for byte the one Verify production
    // infrastructure runs, in a job that holds no Environment.
    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'accept']);
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);
    expect($workflow['jobs']['validate-ref'])->not->toHaveKey('environment');

    $accept = $workflow['jobs']['accept'];
    expect($accept['needs'])->toBe(['validate-ref']);
    expect($accept['environment'])->toBe('production-tits-guru');
    expect($accept['runs-on'])->toBe('ubuntu-24.04');

    // Trusted main tooling, with no credential persisted.
    expect($accept['steps'][0]['uses'])->toStartWith('actions/checkout@');
    expect($accept['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);

    // One fixed target, the bootstrap credential, and nothing else.
    expect($accept['steps'][1]['uses'])->toBe('./.github/actions/verify-rateguru-mail-signing');
    expect($accept['steps'][1]['with'])->toBe([
        'deployment-target' => 'tits-guru',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);

    // No other secret: the DKIM key is already on the host, and no deploy
    // credential is ever used.
    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);
    expect($source)->not->toContain('MAIL_DKIM_PRIVATE_KEY')->not->toContain('DEPLOY_SSH_KEY');
});

it('runs exactly verify-mail-signing --e2e on the host, and removes its bundle on every path', function () {
    $source = File::get(base_path('.github/actions/verify-rateguru-mail-signing/action.yml'));
    $action = Yaml::parse($source);
    $code = executableSourceLines($source);

    expect(array_keys($action['inputs']))->toBe(['deployment-target', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);

    // One remote command: the acceptance, for the fixed target. The other
    // three mentions only check the bundle is complete before it is packed.
    expect(substr_count($code, 'infrastructure/scripts/'))->toBe(4);
    expect(substr_count($code, 'test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/'))->toBe(3);
    expect($code)->toMatch('#remote_command=\(\s+\$\{RATEGURU_PRIVILEGED_PREFIX:-\}\s+"\$\{RATEGURU_REMOTE_ROOT\}/infrastructure/scripts/verify-mail-signing"\s+--e2e\s+--target "\$\{DEPLOYMENT_TARGET\}"\s+\)#');

    // No material, no routing or outbound change, no other operation.
    foreach (['MAIL_DKIM_PRIVATE_KEY', 'material', 'mail-routing.json', 'mail-outbound.json', '--apply', 'prepare-host', 'configure-target', 'install-mail-signing --', 'postsuper', 'postqueue', 'sendmail'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the signing acceptance action uses {$forbidden}");
    }

    // The result is judged, then summarised; a fail or a non-zero exit fails it.
    expect($code)
        ->toContain("grep -c '^RATEGURU_MAIL_SIGNING_RESULT='")
        ->toContain('and .mode == "e2e"')
        ->toContain('and (.foreign_from_rejected | type) == "boolean"')
        ->toContain('and (.status == "fail" or (.foreign_from_rejected and .held and .removed and .signature != null))')
        ->toContain('| Foreign RFC5322 From rejected |')
        ->toContain('| Valid probe signed |')
        ->toContain('| Valid probe stayed HOLD |')
        ->toContain('| Probe removed |')
        ->toContain('>> "${GITHUB_STEP_SUMMARY}"');

    // The last two steps remove the remote bundle and the local files, always.
    $steps = $action['runs']['steps'];
    $last = array_slice($steps, -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote infrastructure bundle', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }
    expect($last[0]['run'])->toContain("printf -v cleanup_command '%s rm -rf %q && rm -rf %q'");
});
