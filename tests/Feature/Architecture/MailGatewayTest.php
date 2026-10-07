<?php

use Illuminate\Support\Facades\File;

/**
 * The host-global mail gateway: install-mail-gateway, verify-mail-gateway and
 * status-mail-gateway, and the host-global outbound contract
 * (infrastructure/config/mail-outbound.json) its direct outbound routes need.
 *
 * Two kinds of test, both against the shipped scripts:
 *
 *   * the renderer, sourced from install-mail-gateway and driven by plans the
 *     real mail-routing CLI renders — including synthetic demo-shop and
 *     demo-books targets the implementation never names;
 *   * the installer as a whole, run against a simulated host: FS_ROOT plus
 *     stubs for dpkg-query, apt-get, debconf, systemctl, ss, postconf and
 *     postfix. The postconf stub reads back what the rendered files say; it is
 *     a test double, not Postfix.
 *
 * CI does not run the Postfix binary, and nothing here claims it does. Postfix's
 * own acceptance of this configuration, and the daemon's behaviour, are what a
 * real-host acceptance (verify-mail-gateway --e2e) and a disposable rehearsal
 * with real Postfix exist for.
 */
function mailGatewayScript(string $name = 'install-mail-gateway'): string
{
    return base_path('infrastructure/scripts/'.$name);
}

function mailGatewayScratch(): string
{
    $dir = sys_get_temp_dir().'/mail-gateway-'.bin2hex(random_bytes(6));

    foreach (['', '/bin', '/fs', '/log', '/state', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create {$dir}{$sub}");
    }

    return $dir;
}

/**
 * The host outbound contract as mail-outbound.json spells it.
 *
 * @return array<string, mixed>
 */
function mailGatewayOutboundContract(mixed $enabled = true, mixed $hostname = 'mta1.example.net'): array
{
    return ['schema_version' => 1, 'direct' => ['enabled' => $enabled, 'mta_hostname' => $hostname]];
}

/**
 * The signing plan the real mail-identity CLI renders from the committed
 * contracts: tits-guru, and nothing else. Rendered once per test process.
 */
function mailGatewayCommittedSigningPlan(): string
{
    static $path = null;

    if ($path === null) {
        $dir = makeScratchDir('mail-gateway-signing', ['']);
        register_shutdown_function(static fn () => removeScratchDir($dir));

        $run = mailIdentityRun(['render-signing-plan']);
        expect($run['status'])->toBe(0, $run['stderr']);

        $path = $dir.'/signing-plan.json';
        file_put_contents($path, $run['stdout']);
    }

    return $path;
}

/**
 * The shipped renderer, sourced: plan file, outbound contract (the committed
 * one when none is given), signing plan (the committed one when none is given)
 * and the signer's milter endpoint (the one install-mail-signing prints) in,
 * main.cf and master.cf out.
 *
 * @return array{status: int, output: string, main: string, master: string}
 */
function mailGatewayRenderPlanFile(string $scratch, string $plan, ?string $outbound = null, ?string $signing = null, ?string $milter = null): array
{
    $out = $scratch.'/render-'.bin2hex(random_bytes(3));
    @mkdir($out, 0o700, true);

    $output = [];
    $status = 0;
    exec('bash -c '.escapeshellarg('source '.escapeshellarg(mailGatewayScript()).' && render_gateway_config '
        .escapeshellarg($plan).' '.escapeshellarg($outbound ?? base_path('infrastructure/config/mail-outbound.json')).' '
        .escapeshellarg($signing ?? mailGatewayCommittedSigningPlan()).' '
        .escapeshellarg($milter ?? trim((string) shell_exec('bash '.escapeshellarg(mailGatewayScript('install-mail-signing')).' --milter-endpoint'))).' '
        .escapeshellarg($out)).' 2>&1', $output, $status);

    return [
        'status' => $status,
        'output' => implode("\n", $output),
        'main' => is_file($out.'/main.cf') ? (string) file_get_contents($out.'/main.cf') : '',
        'master' => is_file($out.'/master.cf') ? (string) file_get_contents($out.'/master.cf') : '',
    ];
}

/**
 * Render the gateway for a policy, registry, host outbound contract and signing
 * plan (the committed ones when null).
 *
 * @param  array<string, mixed>|null  $signing
 * @return array{main: string, master: string, plan: array<string, mixed>}
 */
function mailGatewayRender(?array $policy = null, ?array $registry = null, ?array $outbound = null, ?array $signing = null, ?string $milter = null): array
{
    $scratch = mailGatewayScratch();

    try {
        // Exactly what the CLI printed on stdout, which mailRoutingPlanJson()
        // has already proved came with an empty stderr.
        $plan = $scratch.'/plan.json';
        file_put_contents($plan, mailRoutingPlanJson($policy, $registry));

        $contract = null;
        if ($outbound !== null) {
            $contract = $scratch.'/mail-outbound.json';
            file_put_contents($contract, mailRoutingJson($outbound));
        }

        $signingPlan = null;
        if ($signing !== null) {
            $signingPlan = $scratch.'/signing-plan.json';
            file_put_contents($signingPlan, mailRoutingJson($signing));
        }

        $render = mailGatewayRenderPlanFile($scratch, $plan, $contract, $signingPlan, $milter);

        expect($render['status'])->toBe(0, "render_gateway_config failed:\n".$render['output']);

        return [
            'main' => $render['main'],
            'master' => $render['master'],
            'plan' => json_decode((string) file_get_contents($plan), true, 512, JSON_THROW_ON_ERROR),
        ];
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
}

/**
 * main.cf as name => value, the way Postfix reads `name = value` lines.
 *
 * @return array<string, string>
 */
function mailGatewayMainParameters(string $main): array
{
    $parameters = [];

    foreach (preg_split('/\R/', $main) as $line) {
        if (preg_match('/^([a-z0-9_]+) =(?: (.*))?$/', $line, $matches)) {
            $parameters[$matches[1]] = $matches[2] ?? '';
        }
    }

    return $parameters;
}

/**
 * master.cf as a list of services, each with its eight fields and its -o
 * overrides.
 *
 * @return list<array{name: string, type: string, command: string, options: array<string, string>}>
 */
function mailGatewayMasterServices(string $master): array
{
    $services = [];

    foreach (preg_split('/\R/', $master) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^\s+-o\s+([a-z0-9_]+)=(.*)$/', $line, $matches)) {
            $services[count($services) - 1]['options'][$matches[1]] = $matches[2];

            continue;
        }

        $fields = preg_split('/\s+/', trim($line));
        expect(count($fields))->toBe(8, "master.cf service line does not have eight fields: {$line}");

        $services[] = ['name' => $fields[0], 'type' => $fields[1], 'command' => $fields[7], 'options' => []];
    }

    return $services;
}

/**
 * The committed policy with the synthetic demo-shop target's policy added.
 *
 * @return array<string, mixed>
 */
function mailGatewayDemoShopPolicy(): array
{
    $policy = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true, 512, JSON_THROW_ON_ERROR);
    $policy['targets']['demo-shop'] = mailRoutingDemoShopPolicy();

    return $policy;
}

/**
 * The committed mail identity contract, with the synthetic demo-shop target's
 * identity beside tits-guru's.
 *
 * @return array<string, mixed>
 */
function mailGatewayIdentityWithDemoShop(): array
{
    $identity = json_decode(File::get(base_path('infrastructure/config/mail-identity.json')), true, 512, JSON_THROW_ON_ERROR);
    $identity['targets']['demo-shop'] = mailIdentityDemoShopIdentity();

    return $identity;
}

/**
 * The signing plan the real mail-identity CLI renders for these contracts.
 *
 * @param  array<string, mixed>  $policy
 * @param  array<string, mixed>  $registry
 * @param  array<string, mixed>  $outbound
 * @param  array<string, mixed>  $identity
 * @return array<string, mixed>
 */
function mailGatewaySigningPlanFor(array $policy, array $registry, array $outbound, array $identity): array
{
    $dir = makeScratchDir('mail-gateway-signing', ['']);

    try {
        foreach (['mail-routing' => $policy, 'deployment-targets' => $registry, 'mail-outbound' => $outbound, 'mail-identity' => $identity] as $name => $data) {
            file_put_contents("{$dir}/{$name}.json", mailRoutingJson($data));
        }

        $run = mailIdentityRun(['render-signing-plan',
            '--identity', "{$dir}/mail-identity.json", '--routing', "{$dir}/mail-routing.json",
            '--registry', "{$dir}/deployment-targets.json", '--outbound', "{$dir}/mail-outbound.json"]);
        expect($run['status'])->toBe(0, $run['stderr']);

        return json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
    } finally {
        removeScratchDir($dir);
    }
}

// --- the simulated host ------------------------------------------------------------

/**
 * A simulated host and the stubs that stand in for its package manager,
 * systemd, ss and Postfix's own tools.
 *
 * Options:
 *   package        'absent' (default) | 'installed' | 'partial'
 *   marker         null (default) | 'installing' | 'installed'
 *   postfixDir     true to create /etc/postfix with no package (an unmanaged leftover)
 *   otherMta       a package name providing mail-transport-agent
 *   ownPolicyRc    true to give the host its own /usr/sbin/policy-rc.d
 *   listeners      extra TCP listeners on the host (default: Mailpit's 127.0.0.1:1025)
 *   policy         a mail routing policy instead of the committed one
 *   registry       a deployment registry instead of the committed one
 *   outbound       a host outbound contract instead of the committed one
 *   identity       a mail identity contract instead of the committed one
 *   signer         false to leave the DKIM signer's endpoint unheard on the host
 *
 * The signer's endpoint is the one the real install-mail-signing prints, and by
 * default something listens on it, as on a host where the signer is installed.
 *
 * @return array{scratch: string, fs: string, env: array<string, string>}
 */
function mailGatewayHost(array $options = []): array
{
    $scratch = mailGatewayScratch();
    $fs = $scratch.'/fs';
    $state = $scratch.'/state';

    $package = $options['package'] ?? 'absent';
    if ($package === 'installed') {
        file_put_contents($state.'/pkg-status', 'install ok installed');
        file_put_contents($state.'/pkg-version', '3.6.4-1ubuntu1.4');
        touch($state.'/units-exist');
        touch($state.'/postfix.service.enabled');
        @mkdir($fs.'/etc/postfix', 0o755, true);
        file_put_contents($fs.'/etc/postfix/main.cf', "# a Postfix main.cf\n");
        file_put_contents($fs.'/etc/postfix/master.cf', "smtp      inet  n       -       y       -       -       smtpd\n");
    } elseif ($package === 'partial') {
        file_put_contents($state.'/pkg-status', 'install ok half-configured');
    }

    if (($options['marker'] ?? null) !== null) {
        @mkdir($fs.'/var/lib/rateguru-mail-gateway', 0o755, true);
        file_put_contents($fs.'/var/lib/rateguru-mail-gateway/ownership', "owner=rateguru\ncomponent=mail-gateway\nstate={$options['marker']}\n");
    }

    if ($options['postfixDir'] ?? false) {
        @mkdir($fs.'/etc/postfix', 0o755, true);
        file_put_contents($fs.'/etc/postfix/main.cf', "# somebody else's\n");
    }

    $packages = "bash\tinstall ok installed\t\n";
    if (isset($options['otherMta'])) {
        $packages .= "{$options['otherMta']}\tinstall ok installed\tmail-transport-agent\n";
    }
    file_put_contents($state.'/packages.tsv', $packages);

    if ($options['ownPolicyRc'] ?? false) {
        @mkdir($fs.'/usr/sbin', 0o755, true);
        file_put_contents($fs.'/usr/sbin/policy-rc.d', "#!/bin/sh\n# the host's own\nexit 101\n");
        chmod($fs.'/usr/sbin/policy-rc.d', 0o755);
    }

    $listeners = $options['listeners'] ?? ['127.0.0.1:1025'];
    if ($options['signer'] ?? true) {
        $listeners[] = '127.0.0.1:8891';
    }
    file_put_contents($state.'/listeners', implode("\n", $listeners)."\n");

    $stubs = [
        'dpkg-query' => <<<'STUB'
            #!/bin/bash
            if [[ "$*" == *'${Package}'* ]]; then cat "${STUB_STATE}/packages.tsv"; exit 0; fi
            if [[ "$*" == *'${Version}'* ]]; then cat "${STUB_STATE}/pkg-version" 2>/dev/null; exit 0; fi
            if [[ -f "${STUB_STATE}/pkg-status" ]]; then cat "${STUB_STATE}/pkg-status"; exit 0; fi
            echo "dpkg-query: no packages found matching postfix" >&2
            exit 1
            STUB,
        'apt-get' => <<<'STUB'
            #!/bin/bash
            printf 'apt-get %s [DEBIAN_FRONTEND=%s]\n' "$*" "${DEBIAN_FRONTEND:-}" >> "${STUB_LOG}/mutations.log"
            [[ "$1" == install ]] || exit 0
            policy="${STUB_FS}/usr/sbin/policy-rc.d"
            if [[ -f "${policy}" ]] && grep -q 'rateguru-mail-gateway' "${policy}" && grep -qx 'exit 101' "${policy}"; then
                echo "service starts suppressed during install" >> "${STUB_LOG}/apt-suppression.log"
            else
                echo "service starts NOT suppressed during install" >> "${STUB_LOG}/apt-suppression.log"
            fi
            [[ -e "${STUB_TOGGLES}/apt-fail" ]] && exit 100
            printf 'install ok installed' > "${STUB_STATE}/pkg-status"
            printf '3.6.4-1ubuntu1.4' > "${STUB_STATE}/pkg-version"
            # Preseeded "No configuration": the package installs its master.cf
            # and writes no main.cf of its own.
            mkdir -p "${STUB_FS}/etc/postfix"
            printf 'smtp      inet  n       -       y       -       -       smtpd\n' > "${STUB_FS}/etc/postfix/master.cf"
            touch "${STUB_STATE}/units-exist" "${STUB_STATE}/postfix.service.enabled"
            STUB,
        'dpkg' => <<<'STUB'
            #!/bin/bash
            printf 'dpkg %s\n' "$*" >> "${STUB_LOG}/mutations.log"
            STUB,
        'debconf-set-selections' => <<<'STUB'
            #!/bin/bash
            echo "debconf-set-selections" >> "${STUB_LOG}/mutations.log"
            cat >> "${STUB_LOG}/debconf.log"
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
                    esac
                    exit 0 ;;
                enable) touch "${S}/$2.enabled" ;;
                disable) rm -f "${S}/$2.enabled" ;;
                mask) touch "${S}/$2.masked" ;;
                start|restart)
                    [[ -e "${STUB_TOGGLES}/start-fail" ]] && exit 1
                    touch "${S}/$2.active" ;;
                stop) rm -f "${S}/$2.active" ;;
                reload) [[ -e "${S}/$2.active" ]] || exit 1 ;;
            esac
            exit 0
            STUB,
        'ss' => <<<'STUB'
            #!/bin/bash
            sed '/^$/d; s/^/LISTEN 0 100 /; s/$/ 0.0.0.0:*/' "${STUB_STATE}/listeners"
            if [[ -e "${STUB_STATE}/postfix@-.service.active" && -f "${STUB_FS}/etc/postfix/master.cf" ]]; then
                awk '/^[^#[:space:]]/ && $2 == "inet" { print "LISTEN 0 100 " $1 " 0.0.0.0:*" }' "${STUB_FS}/etc/postfix/master.cf"
            fi
            STUB,
        // A test double that reads the files back, not Postfix.
        'postconf' => <<<'STUB'
            #!/bin/bash
            printf 'postconf %s\n' "$*" >> "${STUB_LOG}/reads.log"
            dir="${STUB_FS}/etc/postfix"
            if [[ "$1" == -c ]]; then dir="$2"; shift 2; fi
            case "$1" in
                -n)
                    [[ -e "${STUB_TOGGLES}/postconf-warn" ]] && echo "postconf: warning: simulated unused parameter" >&2
                    exit 0 ;;
                -M)
                    [[ -e "${STUB_TOGGLES}/postconf-fatal" ]] && { echo "postconf: fatal: bad field count" >&2; exit 1; }
                    awk '/^[^#[:space:]]/ { print $1, $2, $3, $4, $5, $6, $7, $8 }' "${dir}/master.cf"
                    exit 0 ;;
                -h)
                    awk -v k="$2" 'index($0, k " = ") == 1 { v = substr($0, length(k) + 4) } $0 == k " =" { v = "" } END { print v }' "${dir}/main.cf"
                    exit 0 ;;
                -P)
                    spec="$2"; svc="${spec%%/*}"; rest="${spec#*/}"; type="${rest%%/*}"; param="${rest#*/}"
                    awk -v s="${svc}" -v t="${type}" -v p="${param}" -v spec="${spec}" '
                        /^[^#[:space:]]/ { inside = ($1 == s && $2 == t); next }
                        inside && $1 == "-o" && index($2, p "=") == 1 { print spec " = " substr($2, length(p) + 2) }
                    ' "${dir}/master.cf"
                    exit 0 ;;
            esac
            STUB,
        'postfix' => <<<'STUB'
            #!/bin/bash
            printf 'postfix %s\n' "$*" >> "${STUB_LOG}/reads.log"
            [[ -e "${STUB_TOGGLES}/postfix-check-fail" ]] && { echo "postfix: fatal: simulated" >&2; exit 1; }
            exit 0
            STUB,
    ];

    foreach ($stubs as $name => $body) {
        file_put_contents($scratch.'/bin/'.$name, $body."\n");
        chmod($scratch.'/bin/'.$name, 0o755);
    }

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => $scratch,
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_MAILGW_EUID' => '0',
        'RATEGURU_MAILGW_FS_ROOT' => $fs,
        'RATEGURU_MAILGW_FILE_OWNER' => trim((string) shell_exec('id -un')),
        'RATEGURU_MAILGW_FILE_GROUP' => trim((string) shell_exec('id -gn')),
        'RATEGURU_MAILGW_RUNTIME_WAIT' => '1',
        'RATEGURU_MAILGW_STABILITY_WAIT' => '1',
        'RATEGURU_MAILGW_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_MAILGW_APT_GET_BIN' => $scratch.'/bin/apt-get',
        'RATEGURU_MAILGW_DPKG_BIN' => $scratch.'/bin/dpkg',
        'RATEGURU_MAILGW_DPKG_QUERY_BIN' => $scratch.'/bin/dpkg-query',
        'RATEGURU_MAILGW_DEBCONF_SET_SELECTIONS_BIN' => $scratch.'/bin/debconf-set-selections',
        'RATEGURU_MAILGW_POSTCONF_BIN' => $scratch.'/bin/postconf',
        'RATEGURU_MAILGW_POSTFIX_BIN' => $scratch.'/bin/postfix',
        'RATEGURU_MAILGW_SS_BIN' => $scratch.'/bin/ss',
        'STUB_STATE' => $state,
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];

    foreach (['policy' => 'RATEGURU_MAILGW_POLICY_FILE', 'registry' => 'RATEGURU_MAILGW_REGISTRY_FILE', 'outbound' => 'RATEGURU_MAILGW_OUTBOUND_FILE', 'identity' => 'RATEGURU_MAILGW_IDENTITY_FILE'] as $option => $variable) {
        if (isset($options[$option])) {
            file_put_contents($scratch."/{$option}.json", mailRoutingJson($options[$option]));
            $env[$variable] = $scratch."/{$option}.json";
        }
    }

    return ['scratch' => $scratch, 'fs' => $fs, 'env' => $env];
}

/** @return array{0: int, 1: string} */
function mailGatewayRun(array $host, string $mode, array $env = [], string $script = 'install-mail-gateway'): array
{
    $process = proc_open(
        ['bash', mailGatewayScript($script), $mode],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], ...$env],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function mailGatewayLog(array $host, string $name): string
{
    $path = $host['scratch'].'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Every path and its content under the simulated host, so a mode that must not
 * mutate can be proved not to have.
 *
 * @return array<string, string>
 */
function mailGatewayTree(array $host): array
{
    $tree = [];

    foreach (File::allFiles($host['fs'], true) as $file) {
        $tree[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
    }

    ksort($tree);

    return $tree;
}

function mailGatewayCleanup(array $host): void
{
    exec('rm -rf '.escapeshellarg($host['scratch']));
}

/**
 * status-mail-gateway on the simulated host: the same stubs first on PATH, plus
 * an empty queue and an empty journal.
 */
function mailGatewayStatus(array $host): string
{
    foreach (['postqueue', 'journalctl'] as $tool) {
        file_put_contents($host['scratch']."/bin/{$tool}", "#!/bin/bash\nexit 0\n");
        chmod($host['scratch']."/bin/{$tool}", 0o755);
    }

    $process = proc_open(
        ['bash', mailGatewayScript('status-mail-gateway')],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $host['scratch'],
        [...$host['env'], 'PATH' => $host['scratch'].'/bin:'.$host['env']['PATH']],
    );

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return $output;
}

// =============================================================================
// THE PLAN IS THE ONLY SOURCE OF ROUTES
// =============================================================================

it('renders the committed plan into exactly the reviewed listeners and routes', function () {
    $render = mailGatewayRender();
    $services = collect(mailGatewayMasterServices($render['master']));

    $inet = $services->where('type', 'inet')->values();

    // Exactly the plan's endpoints, in plan order, and nothing else listens.
    expect($inet->pluck('name')->all())->toBe(['127.0.0.1:2525', '127.0.0.1:2526']);
    expect($inet->pluck('command')->unique()->all())->toBe(['smtpd']);

    $staging = $inet->firstWhere('name', '127.0.0.1:2525');
    $titsGuru = $inet->firstWhere('name', '127.0.0.1:2526');

    // Capture: queued, then the target's own transport to its destination.
    expect($staging['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-staging-main',
        'smtpd_delay_reject' => 'no',
        'smtpd_reject_unlisted_recipient' => 'no',
        'smtpd_sender_restrictions' => '$rateguru_staging_main_sender_restrictions',
        'content_filter' => 'rateguru-capture-staging-main:[127.0.0.1]:1025',
    ]);

    $transport = $services->firstWhere('name', 'rateguru-capture-staging-main');
    expect($transport)->not->toBeNull();
    expect([$transport['type'], $transport['command']])->toBe(['unix', 'smtp']);
    expect($transport['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-capture-staging-main',
        'smtp_tls_security_level' => 'none',
        'smtp_sasl_auth_enable' => 'no',
    ]);

    // Held: HOLD, and an explicitly empty content filter. Signed, because
    // tits-guru has a reviewed identity: its mail passes the DKIM signer before
    // it is queued, and is deferred when the signer cannot sign.
    expect($titsGuru['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-tits-guru',
        'smtpd_delay_reject' => 'no',
        'smtpd_reject_unlisted_recipient' => 'no',
        'smtpd_sender_restrictions' => '$rateguru_tits_guru_sender_restrictions',
        'smtpd_recipient_restrictions' => 'check_client_access,static:HOLD,permit_mynetworks,reject',
        'content_filter' => '',
        'smtpd_milters' => 'inet:127.0.0.1:8891',
        'milter_protocol' => '6',
        'milter_default_action' => 'tempfail',
    ]);

    // Each listener's sender authorization: exactly its own domain, or empty.
    $main = mailGatewayMainParameters($render['main']);
    expect($main['rateguru_staging_main_sender_restrictions'])->toBe('check_sender_access inline:{ staging.invalid=OK, <>=OK }, reject');
    expect($main['rateguru_tits_guru_sender_restrictions'])->toBe('check_sender_access inline:{ tits.guru=OK, <>=OK }, reject');
});

it('follows whatever plan it is given, never a table of its own', function () {
    $scratch = mailGatewayScratch();

    try {
        // A plan no committed file describes: two listeners on ports nothing
        // else uses, a capture destination that is not Mailpit.
        file_put_contents($scratch.'/synthetic.json', mailRoutingJson([
            'schema_version' => 2,
            'listeners' => [
                [
                    'identity' => 'alpha',
                    'environment_class' => 'staging',
                    'lifecycle' => 'active',
                    'listen' => ['host' => '127.0.0.1', 'port' => 3101],
                    'delivery_mode' => 'capture',
                    'sender' => ['allowed_domain' => 'alpha.invalid'],
                    'route' => ['kind' => 'capture', 'host' => '127.0.0.9', 'port' => 3999],
                ],
                [
                    'identity' => 'beta',
                    'environment_class' => 'production',
                    'lifecycle' => 'planned',
                    'listen' => ['host' => '127.0.0.1', 'port' => 3102],
                    'delivery_mode' => 'held',
                    'sender' => ['allowed_domain' => 'beta.example', 'default_from' => 'x@beta.example', 'bounce_domain' => 'b.beta.example', 'reply_domain' => 'r.beta.example'],
                    'route' => null,
                ],
            ],
        ]));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/synthetic.json');
        expect($render['status'])->toBe(0, $render['output']);

        $services = collect(mailGatewayMasterServices($render['master']));

        expect($services->where('type', 'inet')->pluck('name')->values()->all())->toBe(['127.0.0.1:3101', '127.0.0.1:3102']);
        expect($services->firstWhere('name', '127.0.0.1:3101')['options']['content_filter'])->toBe('rateguru-capture-alpha:[127.0.0.9]:3999');
        expect($services->firstWhere('name', '127.0.0.1:3102')['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
        expect($render['master'])->not->toContain('2525')->not->toContain('2526')->not->toContain('1025');
        expect(mailGatewayMainParameters($render['main']))->toHaveKey('rateguru_alpha_sender_restrictions');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('refuses a delivery mode it has no Postfix spelling for, and a plan value that is not a plain token', function (array $listener, string $reason) {
    $scratch = mailGatewayScratch();

    try {
        file_put_contents($scratch.'/plan.json', mailRoutingJson(['schema_version' => 2, 'listeners' => [$listener]]));
        file_put_contents($scratch.'/mail-outbound.json', mailRoutingJson(mailGatewayOutboundContract()));

        // Against an ENABLED contract, so what refuses is the renderer itself.
        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', $scratch.'/mail-outbound.json');

        expect($render['status'])->not->toBe(0);
        expect($render['output'])->toContain($reason);
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'an outbound route of a kind it has no spelling for' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'outbound', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'relay', 'host' => 'smtp.example.com', 'port' => 587]],
        'no Postfix rendering is defined for delivery mode "outbound" of gamma',
    ],
    'outbound with no route' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'outbound', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => null],
        'no Postfix rendering is defined for delivery mode "outbound" of gamma',
    ],
    'a direct route on a held listener' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'direct']],
        'no Postfix rendering is defined for delivery mode "held" of gamma',
    ],
    'a direct route carrying a relay host' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'outbound', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'direct', 'host' => 'smtp.example.com', 'port' => 587]],
        'refusing to render around it',
    ],
    'held with a route' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => ['kind' => 'capture', 'host' => '127.0.0.1', 'port' => 1025]],
        'no Postfix rendering is defined for delivery mode "held" of gamma',
    ],
    'capture with no route' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'capture', 'sender' => ['allowed_domain' => 'gamma.invalid'], 'route' => null],
        'no Postfix rendering is defined for delivery mode "capture" of gamma',
    ],
    'a directive smuggled into a domain' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => "gamma.example\nrelayhost = evil.example"], 'route' => null],
        'refusing to render around it',
    ],
    'a space in a host' => [
        ['identity' => 'gamma', 'listen' => ['host' => '127.0.0.1 0.0.0.0', 'port' => 3103], 'delivery_mode' => 'held', 'sender' => ['allowed_domain' => 'gamma.example'], 'route' => null],
        'refusing to render around it',
    ],
]);

it('renders a target it has never heard of, generically', function () {
    $render = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry());
    $services = collect(mailGatewayMasterServices($render['master']));

    expect($services->where('type', 'inet')->pluck('name')->values()->all())
        ->toBe(['127.0.0.1:2599', '127.0.0.1:2525', '127.0.0.1:2526']);

    $demo = $services->firstWhere('name', '127.0.0.1:2599');
    expect($demo['options']['syslog_name'])->toBe('postfix/rateguru-demo-shop');
    expect($demo['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
    expect($demo['options']['content_filter'])->toBe('');
    expect(mailGatewayMainParameters($render['main'])['rateguru_demo_shop_sender_restrictions'])
        ->toBe('check_sender_access inline:{ demo-shop.example=OK, <>=OK }, reject');

    // And as a staging capture target, the same way.
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = [
        'submission' => ['host' => '127.0.0.1', 'port' => 2599],
        'delivery_mode' => 'capture',
        'allowed_from_domain' => 'demo-shop.invalid',
        'capture' => ['host' => '127.0.0.1', 'port' => 1025],
    ];

    $capture = collect(mailGatewayMasterServices(mailGatewayRender($policy, mailRoutingDemoShopRegistry(['environment_class' => 'staging']))['master']));
    expect($capture->firstWhere('name', '127.0.0.1:2599')['options']['content_filter'])->toBe('rateguru-capture-demo-shop:[127.0.0.1]:1025');
    expect($capture->firstWhere('name', 'rateguru-capture-demo-shop'))->not->toBeNull();
});

it('restates no policy rule and names no target, domain or port', function () {
    foreach (['install-mail-gateway', 'verify-mail-gateway', 'status-mail-gateway'] as $script) {
        $source = File::get(mailGatewayScript($script));
        $code = executableSourceLines($source);

        foreach (['staging-main', 'tits-guru', 'tits.guru', 'demo-shop', 'staging.invalid', 'bounce.tx', 'reply.tits'] as $name) {
            expect(str_contains($source, $name))->toBeFalse("{$script} names {$name}");
        }

        foreach (['2525', '2526', '2599'] as $port) {
            expect(preg_match('/\b'.$port.'\b/', $code))->toBe(0, "{$script} hard-codes the gateway port {$port}");
        }
    }

    // The installer reads the policy only through mail-routing and
    // mail-identity, from its own bundle: the policy and registry files are
    // arguments to those CLIs and are never parsed here.
    $installer = executableSourceLines(File::get(mailGatewayScript()));

    expect($installer)->toContain('"${MAIL_ROUTING_CLI}" render-plan --file "${POLICY_FILE}" --registry "${REGISTRY_FILE}"');
    expect($installer)->toContain('--identity "${IDENTITY_FILE}" --routing "${POLICY_FILE}"');
    expect(substr_count($installer, '${POLICY_FILE}'))->toBe(2);
    expect(substr_count($installer, '${REGISTRY_FILE}'))->toBe(2);
    expect(substr_count($installer, '${IDENTITY_FILE}'))->toBe(1);
    expect($installer)->toContain('MAIL_ROUTING_CLI="$(gated_default RATEGURU_MAILGW_MAIL_ROUTING_CLI "${SCRIPT_DIR}/mail-routing")"');

    // None of mail-routing's own rules live here.
    foreach (['non_deliverable_tld', 'class_modes', 'first_submission_port', 'is_domain', 'is_address', 'lifecycle'] as $rule) {
        expect(str_contains($installer, $rule))->toBeFalse("install-mail-gateway restates the policy rule {$rule}");
    }

    // Nor any of the host contract's: mail-identity judges mail-outbound.json,
    // from this same bundle, and the installer only asks it.
    expect($installer)
        ->toContain('MAIL_IDENTITY_CLI="$(gated_default RATEGURU_MAILGW_MAIL_IDENTITY_CLI "${SCRIPT_DIR}/mail-identity")"')
        ->toContain('"${MAIL_IDENTITY_CLI}" check-outbound --plan "${plan}" --outbound "${path}"');

    foreach (['private_tlds', 'hostname_re', 'hostname_problem', 'OUTBOUND_CONTRACT_PROGRAM', 'OUTBOUND_SCHEMA_VERSION'] as $rule) {
        expect(str_contains($installer, $rule))->toBeFalse("install-mail-gateway restates the host contract rule {$rule}");
    }
});

it('refuses with mail-routing\'s own verdict when the policy is invalid', function () {
    $host = mailGatewayHost();

    try {
        $policy = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);
        $policy['targets']['tits-guru']['submission']['port'] = 2525;
        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));

        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_POLICY_FILE' => $host['scratch'].'/policy.json']);

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('submission port 2525 is claimed by more than one target: staging-main, tits-guru')
            ->toContain('mail-routing render-plan refused the reviewed policy');
    } finally {
        mailGatewayCleanup($host);
    }
});

// =============================================================================
// THE RENDERED CONFIGURATION IS FAIL-CLOSED, LOOPBACK-ONLY AND NON-PUBLIC
// =============================================================================

it('binds only loopback IPv4 endpoints, and has no smtp, submission or smtps listener', function () {
    $render = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry());
    $services = mailGatewayMasterServices($render['master']);
    $main = mailGatewayMainParameters($render['main']);

    expect($main['inet_interfaces'])->toBe('127.0.0.1');
    expect($main['inet_protocols'])->toBe('ipv4');
    expect($main['mynetworks'])->toBe('127.0.0.0/8');

    foreach ($services as $service) {
        if ($service['type'] !== 'inet') {
            continue;
        }

        expect(preg_match('/\A127\.0\.0\.1:(\d+)\z/', $service['name'], $matches))->toBe(1, "inet service on a non-loopback endpoint: {$service['name']}");
        expect((int) $matches[1])->not->toBeIn([25, 465, 587]);
    }

    foreach (['smtp', 'submission', 'smtps', '0.0.0.0', '::', '[::]'] as $public) {
        expect(collect($services)->where('type', 'inet')->pluck('name')->all())->not->toContain($public);
    }

    expect($render['master'])->not->toMatch('/^(smtp|submission|smtps|465|587|25)\s+inet\b/m');
});

it('delivers nothing it was not routed to deliver: every fallback is the error transport', function () {
    $render = mailGatewayRender();
    $main = mailGatewayMainParameters($render['main']);
    $services = collect(mailGatewayMasterServices($render['master']));

    foreach (['default_transport', 'relay_transport', 'local_transport', 'virtual_transport'] as $transport) {
        expect($main[$transport])->toStartWith('error:');
    }

    foreach (['relayhost', 'mydestination', 'relay_domains', 'transport_maps', 'content_filter', 'sender_dependent_relayhost_maps', 'sender_dependent_default_transport_maps', 'alias_maps'] as $empty) {
        expect($main)->toHaveKey($empty);
        expect($main[$empty])->toBe('', "{$empty} must be empty");
    }

    // No delivery agent anything could fall through to: the only smtp clients
    // are the per-target capture transports, and relay is the error transport.
    foreach (['smtp', 'local', 'virtual', 'lmtp'] as $agent) {
        expect($services->where('type', 'unix')->pluck('name')->all())->not->toContain($agent);
    }

    expect($services->firstWhere('name', 'relay')['command'])->toBe('error');

    expect($services->where('command', 'smtp')->pluck('name')->values()->all())->toBe(['rateguru-capture-staging-main']);
    expect($services->whereIn('command', ['local', 'virtual', 'lmtp', 'pipe'])->all())->toBe([]);
});

it('leaves the package master.cf repair nothing to add, so an upgrade cannot drift it', function () {
    // The jammy postfix postinst runs fix_master on every configure, upgrades
    // included: it appends each of these services when no line starts with its
    // name, and a missing relay as a working smtp client. Every one is already
    // here, matched the way fix_master matches it.
    $master = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry())['master'];

    foreach (['flush', 'proxymap', 'trace', 'verify', 'tlsmgr', 'anvil', 'scache', 'discard', 'retry', 'relay'] as $service) {
        expect(preg_match('/^'.$service.'[[:space:]]/m', $master))->toBe(1, "fix_master would append {$service}");
    }

    // Nor does its first rewrite apply: cleanup is already unprivileged.
    expect(preg_match('/^cleanup[[:space:]]+unix[[:space:]]+-/m', $master))->toBe(0);
    expect(preg_match('/^tlsmgr[[:space:]]*fifo/m', $master))->toBe(0);

    // And the package is told to configure nothing of its own.
    expect(executableSourceLines(File::get(mailGatewayScript())))
        ->toContain('"postfix postfix/main_mailer_type select No configuration"')
        ->not->toContain('select Local only');
});

it('waits only for a unit on its way to running, and answers at once for any other state', function (array $states, int $status, bool $waited) {
    $scratch = mailGatewayScratch();

    try {
        // Each ActiveState query answers the next state (the last one sticks);
        // SubState follows the state just answered.
        file_put_contents($scratch.'/states', implode("\n", $states)."\n");
        file_put_contents($scratch.'/bin/systemctl', <<<'STUB'
            #!/bin/bash
            case "$3" in
                --property=ActiveState)
                    echo x >> "${STATES}.queries"
                    state="$(head -n 1 "${STATES}")"
                    echo "${state}" > "${STATES}.last"
                    if [[ "$(wc -l < "${STATES}")" -gt 1 ]]; then tail -n +2 "${STATES}" > "${STATES}.next" && mv "${STATES}.next" "${STATES}"; fi
                    echo "${state}" ;;
                --property=SubState)
                    [[ "$(cat "${STATES}.last" 2>/dev/null)" == active ]] && echo running || echo dead ;;
            esac
            STUB."\n");
        chmod($scratch.'/bin/systemctl', 0o755);

        $harness = 'source '.escapeshellarg(mailGatewayScript())
            .' && SYSTEMCTL_BIN='.escapeshellarg($scratch.'/bin/systemctl').' RUNTIME_WAIT=3'
            .' && if wait_service_running postfix@-.service; then echo rc=0; else echo rc=1; fi';

        $output = (string) shell_exec('STATES='.escapeshellarg($scratch.'/states').' bash -c '.escapeshellarg($harness).' 2>&1');

        expect(preg_match('/rc=(\d)/', $output, $matches))->toBe(1, $output);
        expect((int) $matches[1])->toBe($status, $output);

        // One state reading means it answered at once; more means it waited.
        $queries = count(file($scratch.'/states.queries') ?: []);
        expect($queries > 1)->toBe($waited, "{$queries} state reading(s)");
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'running already' => [['active'], 0, false],
    'inactive' => [['inactive'], 1, false],
    'failed' => [['failed'], 1, false],
    'deactivating' => [['deactivating'], 1, false],
    'activating, then running' => [['activating', 'activating', 'active'], 0, true],
    'reloading, then running' => [['reloading', 'active'], 0, true],
    'activating, then failed' => [['activating', 'failed'], 1, true],
    'activating for longer than the window' => [['activating'], 1, true],
]);

it('selects a route by listener only, never by sender or recipient', function () {
    $render = mailGatewayRender();
    $main = mailGatewayMainParameters($render['main']);

    // No map anywhere that routes on an address.
    foreach (['transport_maps', 'sender_dependent_default_transport_maps', 'sender_dependent_relayhost_maps'] as $map) {
        expect($main[$map])->toBe('');
    }

    expect($render['main'].$render['master'])
        ->not->toContain('FILTER')
        ->not->toContain('check_recipient_access')
        ->not->toContain('header_checks')
        ->not->toContain('recipient_bcc_maps')
        ->not->toContain('virtual_alias_maps');

    // The content filter is set per listener in master.cf, never globally.
    foreach (mailGatewayMasterServices($render['master']) as $service) {
        if ($service['type'] === 'inet') {
            expect($service['options'])->toHaveKey('content_filter');
        }
    }
});

it('has no SMTP AUTH, no TLS listener and no production delivery, and signs only where a listener says so', function () {
    $render = mailGatewayRender();
    $main = mailGatewayMainParameters($render['main']);
    $all = $render['main']."\n".$render['master'];

    expect($main['smtpd_sasl_auth_enable'])->toBe('no');
    expect($main['smtp_sasl_auth_enable'])->toBe('no');
    expect($main['smtpd_tls_security_level'])->toBe('none');
    expect($main['smtp_tls_security_level'])->toBe('none');

    // tlsmgr is present only as the internal service the package repair would
    // otherwise append; with no certificate and TLS off, nothing uses it.
    foreach (['smtpd_tls_cert_file', 'smtpd_tls_key_file', 'smtp_sasl_password_maps', 'smtpd_sasl_type', 'smtpd_tls_wrappermode', 'opendkim', 'spf', 'dmarc', 'relayhost = ['] as $absent) {
        expect(str_contains(mb_strtolower($all), mb_strtolower($absent)))->toBeFalse("the rendered gateway contains {$absent}");
    }

    // No global milter in main.cf: locally submitted mail and every unsigned
    // listener never reach the signer. Exactly one listener names it.
    expect(str_contains($render['main'], 'milter'))->toBeFalse('main.cf names a milter');
    $milters = collect(mailGatewayMasterServices($render['master']))
        ->filter(static fn (array $service): bool => collect($service['options'])->keys()->contains(static fn (string $key): bool => str_contains($key, 'milter')))
        ->pluck('name')->values()->all();
    expect($milters)->toBe(['127.0.0.1:2526']);
});

it('authorizes each listener\'s senders by exact domain, refused at MAIL FROM', function () {
    $render = mailGatewayRender(mailGatewayDemoShopPolicy(), mailRoutingDemoShopRegistry());
    $main = mailGatewayMainParameters($render['main']);

    // A subdomain is a different identity; the parent never matches it.
    expect($main['parent_domain_matches_subdomains'])->toBe('');
    expect($main['smtpd_null_access_lookup_key'])->toBe('<>');

    foreach ($render['plan']['listeners'] as $listener) {
        $endpoint = $listener['listen']['host'].':'.$listener['listen']['port'];
        $service = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', $endpoint);
        $parameter = 'rateguru_'.str_replace('-', '_', $listener['identity']).'_sender_restrictions';

        expect($service['options']['smtpd_sender_restrictions'])->toBe('$'.$parameter);
        expect($service['options']['smtpd_delay_reject'])->toBe('no');
        expect($main[$parameter])->toBe("check_sender_access inline:{ {$listener['sender']['allowed_domain']}=OK, <>=OK }, reject");
    }
});

// =============================================================================
// THE INSTALLER ON A SIMULATED HOST
// =============================================================================

it('installs the package safely on a host that has none, and only then activates the gateway', function () {
    $host = mailGatewayHost(['ownPolicyRc' => true]);

    try {
        [$status, $output] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $output);

        // Preseeded so the package configures nothing of its own, before it
        // was installed.
        expect(mailGatewayLog($host, 'debconf.log'))
            ->toContain('postfix postfix/main_mailer_type select No configuration')
            ->toContain('postfix postfix/protocols select ipv4')
            ->toContain('postfix postfix/relayhost string');

        // The package went in with service starts suppressed, never removing
        // anything to make room.
        expect(trim(mailGatewayLog($host, 'apt-suppression.log')))->toBe('service starts suppressed during install');
        $mutations = mailGatewayLog($host, 'mutations.log');
        expect($mutations)->toContain('apt-get install -y --no-install-recommends --no-remove')->toContain('[DEBIAN_FRONTEND=noninteractive]');

        // The host's own policy-rc.d is back, byte for byte, and ours is gone.
        expect(File::get($host['fs'].'/usr/sbin/policy-rc.d'))->toBe("#!/bin/sh\n# the host's own\nexit 101\n");
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/policy-rc.d.preserved'))->toBeFalse();

        // The rendered files are installed, and the service enabled and started
        // only after them.
        $render = mailGatewayRender();
        expect(File::get($host['fs'].'/etc/postfix/main.cf'))->toBe($render['main']);
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toBe($render['master']);
        expect(substr(sprintf('%o', fileperms($host['fs'].'/etc/postfix/main.cf')), -3))->toBe('644');

        $installed = strpos($output, 'installing /etc/postfix/master.cf');
        $started = strpos($output, 'starting postfix@-.service');
        expect($installed)->not->toBeFalse();
        expect($started)->toBeGreaterThan($installed);
        expect($mutations)->toContain('systemctl enable postfix.service')->toContain('systemctl start postfix@-.service');

        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installed');

        // And the contract holds.
        [$verify, $report] = mailGatewayRun($host, '--verify');
        expect($verify)->toBe(0, $report);
        expect($report)
            ->toContain('PASS     outbound:direct — direct delivery disabled on this host (mail-outbound.json); 0 outbound route(s) in the plan, and none could be rendered')
            ->toContain('PASS     signing — listeners of tits-guru hand their mail to the signer at inet:127.0.0.1:8891 (install-mail-signing --milter-endpoint), deferred when it cannot sign; no other listener is signed')
            ->toContain('SUMMARY  pass=9 missing=0 drift=0 conflict=0 deferred=0');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('writes the ownership marker before the package, so an interrupted install is recognisably RateGuru\'s', function () {
    $host = mailGatewayHost();

    try {
        touch($host['scratch'].'/toggles/apt-fail');

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installing');
        expect(file_exists($host['fs'].'/usr/sbin/policy-rc.d'))->toBeFalse('the suppression outlived a failed install');
        expect($output)->toContain('the ownership marker stays, so a re-run resumes');

        // The next run resumes it.
        unlink($host['scratch'].'/toggles/apt-fail');
        [$resumed, $log] = mailGatewayRun($host, '--apply');
        expect($resumed)->toBe(0, $log);
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installed');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('resumes an interrupted RateGuru installation, restoring the host\'s own policy-rc.d first', function () {
    $host = mailGatewayHost(['marker' => 'installing', 'package' => 'partial']);

    try {
        // What an interrupted package installation leaves: our suppression in
        // place, the host's own one preserved beside the marker.
        @mkdir($host['fs'].'/usr/sbin', 0o755, true);
        file_put_contents($host['fs'].'/usr/sbin/policy-rc.d', "#!/bin/sh\n# rateguru-mail-gateway: package service starts suppressed until the gateway configuration is in place\nexit 101\n");
        file_put_contents($host['fs'].'/var/lib/rateguru-mail-gateway/policy-rc.d.preserved', "#!/bin/sh\n# the host's own\nexit 0\n");

        [$check, $report] = mailGatewayRun($host, '--check');
        expect($check)->toBe(0, $report);
        expect($report)
            ->toContain('DRIFT    package:start-suppression')
            ->toContain('RateGuru ownership marker present (state=installing)');

        [$status, $output] = mailGatewayRun($host, '--apply');
        expect($status)->toBe(0, $output);

        expect($output)->toContain('removing the package start suppression an interrupted run left behind');
        expect(mailGatewayLog($host, 'mutations.log'))->toContain('dpkg --configure postfix');
        expect(File::get($host['fs'].'/usr/sbin/policy-rc.d'))->toBe("#!/bin/sh\n# the host's own\nexit 0\n");
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toContain('state=installed');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('fails closed on a mail transport agent RateGuru did not install, before changing anything', function (array $options, string $reason) {
    $host = mailGatewayHost($options);

    try {
        $before = mailGatewayTree($host);

        [$check, $report] = mailGatewayRun($host, '--check');
        expect($check)->not->toBe(0);
        expect($report)->toContain('CONFLICT');

        [$status, $output] = mailGatewayRun($host, '--apply');
        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('Nothing was changed');

        // No package, no preseed, no service change, no file, no marker.
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'an installed Postfix' => [['package' => 'installed'], 'without the RateGuru ownership marker'],
    'a half-installed Postfix' => [['package' => 'partial'], 'without the RateGuru ownership marker'],
    'a leftover /etc/postfix' => [['postfixDir' => true], 'without the RateGuru ownership marker'],
    'another mail transport agent' => [['otherMta' => 'exim4-daemon-light'], 'another mail transport agent is installed (exim4-daemon-light)'],
]);

it('refuses a gateway port another process already holds, before installing anything', function () {
    $host = mailGatewayHost(['listeners' => ['127.0.0.1:1025', '127.0.0.1:2526']]);

    try {
        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('gateway port 2526 is already in use by another process');
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('takes exactly one of --check, --apply or --verify, and nothing it does not know', function (array $arguments, int $exit, string $stdout, string $stderr) {
    // On a simulated host, so a parser that let a refused command line through
    // would still have nothing real to touch.
    $host = mailGatewayHost();

    try {
        $process = proc_open(['bash', mailGatewayScript(), ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $host['scratch'], $host['env']);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe($exit, $out.$err);

        // The usage text goes to stdout when it was asked for, and to stderr
        // ahead of the refusal when it was not.
        foreach ([[$out, $stdout], [$err, $stderr]] as [$actual, $expected]) {
            if ($expected === '') {
                expect($actual)->toBe('');
            } else {
                expect($actual)
                    ->toStartWith("Usage:\n  install-mail-gateway --check\n  install-mail-gateway --apply\n  install-mail-gateway --verify\n")
                    ->toEndWith($expected);
            }
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayLog($host, 'reads.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'asked for help' => [['--help'], 0, "from install-mail-signing --milter-endpoint, in the same bundle.\n", ''],
    'no mode' => [[], 1, '', "ERROR: one of --check, --apply or --verify is required\n"],
    'two modes' => [['--check', '--verify'], 1, '', "ERROR: mode given more than once\n"],
    'an unknown argument' => [['--check', '--force'], 1, '', "ERROR: unknown argument: --force\n"],
]);

it('changes nothing in --check or --verify: no package manager, no service, no file', function (array $options) {
    $host = mailGatewayHost($options);

    try {
        if (($options['marker'] ?? null) === 'installed') {
            // A converged gateway first, so --verify inspects a real state.
            $fresh = mailGatewayHost();
            [$applied] = mailGatewayRun($fresh, '--apply');
            expect($applied)->toBe(0);
            exec('cp -a '.escapeshellarg($fresh['fs'].'/.').' '.escapeshellarg($host['fs']));
            exec('cp -a '.escapeshellarg($fresh['scratch'].'/state/.').' '.escapeshellarg($host['scratch'].'/state'));
            mailGatewayCleanup($fresh);
        }

        $before = mailGatewayTree($host);

        foreach (['--check', '--verify'] as $mode) {
            mailGatewayRun($host, $mode);
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('', 'a read-only mode called a mutating command');
        expect(mailGatewayLog($host, 'debconf.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'a host with no Postfix' => [[]],
    'a converged gateway' => [['marker' => 'installed', 'package' => 'installed']],
]);

it('is idempotent: a second --apply rewrites nothing and restarts nothing', function () {
    $host = mailGatewayHost();

    try {
        [$first] = mailGatewayRun($host, '--apply');
        expect($first)->toBe(0);

        $before = mailGatewayTree($host);
        file_put_contents($host['scratch'].'/log/mutations.log', '');

        [$second, $output] = mailGatewayRun($host, '--apply');
        expect($second)->toBe(0, $output);

        expect($output)->toContain('gateway configuration unchanged')->not->toContain('installing /etc/postfix');
        $mutations = mailGatewayLog($host, 'mutations.log');
        expect($mutations)->not->toContain('apt-get')->not->toContain('start')->not->toContain('reload')->not->toContain('restart');

        // Only the run's own (empty) backup directory is new.
        $after = array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        $original = array_filter($before, static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        expect($after)->toBe($original);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('rolls back the configuration and the service state when the running gateway is unhealthy', function () {
    $host = mailGatewayHost();

    try {
        [$first] = mailGatewayRun($host, '--apply');
        expect($first)->toBe(0);
        $installed = mailGatewayTree($host);

        // A changed policy, and a capture destination that has gone away.
        $policy = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);
        $policy['targets']['staging-main']['submission']['port'] = 2527;
        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));
        file_put_contents($host['scratch'].'/state/listeners', "\n");

        [$status, $output] = mailGatewayRun($host, '--apply', ['RATEGURU_MAILGW_POLICY_FILE' => $host['scratch'].'/policy.json']);

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('capture destination 127.0.0.1:1025 is not listening')
            ->toContain('rollback complete: configuration and service state restored')
            ->not->toContain('rollback INCOMPLETE');

        // Back exactly as it was, and running again.
        $restored = array_filter(mailGatewayTree($host), static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        $original = array_filter($installed, static fn (string $path): bool => ! str_starts_with($path, 'var/backups/'), ARRAY_FILTER_USE_KEY);
        expect($restored)->toBe($original);
        expect(mailGatewayLog($host, 'mutations.log'))->toContain('systemctl restart postfix@-.service');
        expect(file_exists($host['scratch'].'/state/postfix@-.service.active'))->toBeTrue();

        // Backups hold configuration only — never a queue, never a message.
        foreach (File::allFiles($host['fs'].'/var/backups/rateguru-mail-gateway', true) as $backup) {
            expect($backup->getRelativePathname())->toMatch('#/etc/postfix/(main|master)\.cf$#');
        }
    } finally {
        mailGatewayCleanup($host);
    }
});

it('never lets Postfix judge a configuration it did not accept into place', function () {
    $host = mailGatewayHost();

    try {
        touch($host['scratch'].'/toggles/postconf-warn');

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('Postfix does not accept the rendered gateway configuration');

        // The package is in (the marker says so, and a re-run resumes), but the
        // gateway configuration never was, and nothing was started.
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toContain('smtp      inet');
        expect(mailGatewayLog($host, 'mutations.log'))->not->toContain('systemctl start');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('reads its own unit names, and never touches the capture services it delivers into', function () {
    $installer = executableSourceLines(File::get(mailGatewayScript()));

    expect($installer)
        ->toContain('POSTFIX_UNIT="postfix.service"')
        ->toContain('POSTFIX_INSTANCE_UNIT="postfix@-.service"')
        ->not->toContain('staging-mailpit')
        ->not->toContain('staging-mailtrap')
        ->not->toContain('install-mail-capture');
});

// =============================================================================
// DIRECT OUTBOUND: ONE DEDICATED CLIENT PER TARGET, AND ONLY WHEN THE HOST SAYS SO
// =============================================================================

/**
 * The demo-shop outbound plan's gateway, rendered against an enabled contract.
 *
 * @return array{main: string, master: string, plan: array<string, mixed>}
 */
function mailGatewayDirectRender(?array $outbound = null): array
{
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();

    return mailGatewayRender($policy, mailRoutingDemoShopRegistry(), $outbound ?? mailGatewayOutboundContract());
}

it('renders the real committed policy byte for byte as the gateway the staging host accepted, with no Internet route', function () {
    $render = mailGatewayRender();

    // The configuration the real staging host runs. A plan with no outbound
    // target must render exactly this, or the host drifts and its read-only
    // verification fails until it is reconverged: a change here is a host
    // change, and has to be deliberate. main.cf is byte for byte the one the
    // staging host was accepted with; master.cf differs from it only in
    // tits-guru's held listener handing its mail to the DKIM signer.
    expect(hash('sha256', $render['main']))->toBe('69997052d869d451ee44815f714fdb92e47c90e791fc39306a6b2e55b64448ba');
    expect(hash('sha256', $render['master']))->toBe('c39097b4300bbdb451e5fbe254bd810a1fed6295de2d374f1ef55c783a4c7358');

    // No outbound transport, no MTA identity, no filter next-hop setting, and
    // the only smtp client is the staging capture transport.
    expect($render['main'].$render['master'])
        ->not->toContain('rateguru-outbound-')
        ->not->toContain('default_filter_nexthop')
        ->not->toContain('smtp_helo_name')
        ->not->toContain('smtp_tls_security_level=may');

    $services = collect(mailGatewayMasterServices($render['master']));
    expect($services->where('command', 'smtp')->pluck('name')->values()->all())->toBe(['rateguru-capture-staging-main']);

    // And the host contract that would allow one keeps direct delivery off.
    expect(json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true))
        ->toBe(mailGatewayOutboundContract(false, 'mta1.tits.guru'));
});

// =============================================================================
// SIGNING: ONLY THE SIGNED LISTENERS, AND NEVER UNSIGNED
// =============================================================================

/**
 * Postfix's read-back of a configuration directory, through the shipped
 * postfix_contract_problems and the simulated postconf, with the committed plan
 * and signing plan.
 */
function mailGatewayContractProblems(array $host, string $dir, string $plan, ?string $signing = null): string
{
    $harness = 'source '.escapeshellarg(mailGatewayScript())
        .' && PLAN_FILE='.escapeshellarg($plan)
        .' OUTBOUND_FILE='.escapeshellarg(base_path('infrastructure/config/mail-outbound.json'))
        .' SIGNING_FILE='.escapeshellarg($signing ?? mailGatewayCommittedSigningPlan())
        .' MILTER_ENDPOINT=inet:127.0.0.1:8891'
        .' POSTCONF_BIN='.escapeshellarg($host['scratch'].'/bin/postconf')
        .' EFFECTIVE_UID=1000'
        .' && postfix_contract_problems '.escapeshellarg($dir);

    $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $host['scratch'], $host['env']);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return $output;
}

it('renders exactly the gateway the staging host accepted when nothing is signed', function () {
    // The milter lines are the whole difference: without a signing identity,
    // the committed policy renders the accepted configuration byte for byte.
    $render = mailGatewayRender(signing: ['schema_version' => 1, 'targets' => []]);

    expect(hash('sha256', $render['main']))->toBe('69997052d869d451ee44815f714fdb92e47c90e791fc39306a6b2e55b64448ba');
    expect(hash('sha256', $render['master']))->toBe('2f7aea126dbd670889ca7599887d19794c78cd975a53c66d843e916bfe666f34');
    expect($render['master'])->not->toContain('milter')->not->toContain('signer');

    // And the signed render differs from it in tits-guru's listener alone.
    $signed = mailGatewayRender();
    $removed = array_values(array_diff(explode("\n", $render['master']), explode("\n", $signed['master'])));
    $added = array_values(array_diff(explode("\n", $signed['master']), explode("\n", $render['master'])));
    expect($removed)->toBe([]);
    expect($added)->toBe([
        '# A signed listener hands each message to the DKIM signer before it is',
        '# queued, and defers it when the signer cannot sign: never unsigned.',
        '# Signing routes nothing.',
        '  -o smtpd_milters=inet:127.0.0.1:8891',
        '  -o milter_protocol=6',
        '  -o milter_default_action=tempfail',
    ]);
});

it('wires a signed listener to whatever endpoint the signer\'s installer names, and spells none itself', function () {
    $render = mailGatewayRender(milter: 'inet:127.0.0.1:18891');
    $titsGuru = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', '127.0.0.1:2526');

    expect($titsGuru['options']['smtpd_milters'])->toBe('inet:127.0.0.1:18891');

    // The endpoint reaches the gateway only from install-mail-signing, in the
    // same bundle: the installer names no milter port of its own.
    $installer = executableSourceLines(File::get(mailGatewayScript()));
    expect($installer)
        ->toContain('MAIL_SIGNING_CLI="$(gated_default RATEGURU_MAILGW_MAIL_SIGNING_CLI "${SCRIPT_DIR}/install-mail-signing")"')
        ->toContain('MILTER_ENDPOINT="$("${MAIL_SIGNING_CLI}" --milter-endpoint)"')
        ->toContain('"${MAIL_IDENTITY_CLI}" render-signing-plan')
        ->not->toContain('8891');
    expect(trim((string) shell_exec('bash '.escapeshellarg(mailGatewayScript('install-mail-signing')).' --milter-endpoint')))->toBe('inet:127.0.0.1:8891');
});

it('refuses a signer endpoint that is not on loopback', function (string $endpoint) {
    $scratch = mailGatewayScratch();

    try {
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson());
        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', null, null, $endpoint);

        expect($render['status'])->not->toBe(0);
        expect($render['output'])->toContain("the signer's milter endpoint is \"{$endpoint}\", not a loopback inet endpoint");
        expect($render['master'])->toBe('');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with(['inet:0.0.0.0:8891', 'inet:10.0.0.5:8891', 'inet:[::1]:8891', 'unix:/run/opendkim/opendkim.sock', 'inet:127.0.0.1:8891 -o x=y', '']);

it('signs exactly the listeners of the targets in the signing plan, held or outbound, and never a capture listener', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $registry = mailRoutingDemoShopRegistry();
    $signing = mailGatewaySigningPlanFor($policy, $registry, mailGatewayOutboundContract(), mailGatewayIdentityWithDemoShop());

    $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract(), $signing);
    $services = collect(mailGatewayMasterServices($render['master']))->where('type', 'inet');

    foreach ($services as $service) {
        $signed = in_array($service['name'], ['127.0.0.1:2526', '127.0.0.1:2599'], true);
        expect(array_key_exists('smtpd_milters', $service['options']))->toBe($signed, "{$service['name']} signing");

        if ($signed) {
            expect([$service['options']['smtpd_milters'], $service['options']['milter_protocol'], $service['options']['milter_default_action']])
                ->toBe(['inet:127.0.0.1:8891', '6', 'tempfail']);
        }
    }

    // Signing routes nothing: the held listener still holds, with no filter,
    // and the outbound one still delivers through its own client only.
    $held = $services->firstWhere('name', '127.0.0.1:2526');
    expect($held['options']['content_filter'])->toBe('');
    expect($held['options']['smtpd_recipient_restrictions'])->toContain('static:HOLD');
    expect($services->firstWhere('name', '127.0.0.1:2599')['options']['content_filter'])->toBe('rateguru-outbound-demo-shop:');
});

it('reads the signing wiring back through Postfix, and refuses every way it could be weakened', function (string $file, string $from, string $to, string $problem) {
    $host = mailGatewayHost();

    try {
        $render = mailGatewayRender();
        $dir = $host['scratch'].'/etc';
        @mkdir($dir, 0o755, true);
        file_put_contents($host['scratch'].'/plan.json', mailRoutingPlanJson());

        file_put_contents($dir.'/main.cf', $render['main']);
        file_put_contents($dir.'/master.cf', $render['master']);
        expect(mailGatewayContractProblems($host, $dir, $host['scratch'].'/plan.json'))->toBe('', 'the untouched render must read back clean');

        $original = $file === 'main' ? $render['main'] : $render['master'];
        expect(substr_count($original, $from))->toBe(1, "the tamper anchor is not unique: {$from}");
        file_put_contents($dir."/{$file}.cf", str_replace($from, $to, $original));

        expect(mailGatewayContractProblems($host, $dir, $host['scratch'].'/plan.json'))->toContain($problem);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'the milter removed' => ['master', "  -o smtpd_milters=inet:127.0.0.1:8891\n", '', 'tits-guru (127.0.0.1:2526) is signed but hands its mail to "", not the signer at inet:127.0.0.1:8891'],
    'another milter' => ['master', 'smtpd_milters=inet:127.0.0.1:8891', 'smtpd_milters=inet:127.0.0.1:9999', 'hands its mail to "inet:127.0.0.1:9999", not the signer'],
    'unsigned mail accepted when the signer fails' => ['master', 'milter_default_action=tempfail', 'milter_default_action=accept', 'has milter_default_action "accept", not tempfail'],
    'no failure policy at all' => ['master', "  -o milter_default_action=tempfail\n", '', 'has milter_default_action "", not tempfail'],
    'another milter protocol' => ['master', 'milter_protocol=6', 'milter_protocol=2', 'has milter_protocol "2", not 6'],
    'the capture listener signed too' => ['master', "  -o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025\n", "  -o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025\n  -o smtpd_milters=inet:127.0.0.1:8891\n", 'staging-main (127.0.0.1:2525) is not signed but hands its mail to the milter "inet:127.0.0.1:8891"'],
    'a global milter' => ['main', "disable_vrfy_command = yes\n", "disable_vrfy_command = yes\nsmtpd_milters = inet:127.0.0.1:8891\n", 'smtpd_milters is "inet:127.0.0.1:8891", not empty — the signer is wired per listener, never globally'],
    'locally submitted mail signed' => ['main', "disable_vrfy_command = yes\n", "disable_vrfy_command = yes\nnon_smtpd_milters = inet:127.0.0.1:8891\n", 'non_smtpd_milters is "inet:127.0.0.1:8891", not empty'],
]);

it('requires the signer listening before it calls a signing gateway healthy', function () {
    // Without the signer, the apply fails at its runtime check and puts the
    // host back; the listener would otherwise defer everything it was given.
    $host = mailGatewayHost(['signer' => false]);

    try {
        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)
            ->toContain('the signer is not listening on 127.0.0.1:8891 — every signed listener would defer its mail')
            ->toContain('rollback complete');
        expect(file_exists($host['fs'].'/etc/postfix/main.cf'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }

    // On a converged host, a signer that stops is a CONFLICT of --verify.
    $host = mailGatewayHost();

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        file_put_contents($host['scratch'].'/state/listeners', "127.0.0.1:1025\n");
        [$verified, $report] = mailGatewayRun($host, '--verify');

        expect($verified)->not->toBe(0);
        expect($report)->toContain('CONFLICT runtime — the signer is not listening on 127.0.0.1:8891');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('renders nothing from an identity contract mail-identity refuses, and changes nothing', function () {
    $identity = json_decode(File::get(base_path('infrastructure/config/mail-identity.json')), true);
    $identity['targets']['tits-guru']['dkim']['selector'] = 'RG1';
    $host = mailGatewayHost(['identity' => $identity]);

    try {
        $before = mailGatewayTree($host);

        foreach (['--check', '--apply'] as $mode) {
            [$status, $output] = mailGatewayRun($host, $mode);
            expect($status)->not->toBe(0);
            expect($output)
                ->toContain('tits-guru: dkim.selector must be one lowercase DNS label')
                ->toContain('mail-identity render-signing-plan refused the reviewed identity');
        }

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('renders exactly one dedicated direct smtp client for an outbound target, selected only by its own listener', function () {
    $render = mailGatewayDirectRender();
    $services = collect(mailGatewayMasterServices($render['master']));

    // The listener names its own transport and NO next hop: the queue manager
    // then uses each recipient's own domain, so the client looks up its MX.
    $listener = $services->firstWhere('name', '127.0.0.1:2599');
    expect($listener['command'])->toBe('smtpd');
    expect($listener['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-demo-shop',
        'smtpd_delay_reject' => 'no',
        'smtpd_reject_unlisted_recipient' => 'no',
        'smtpd_sender_restrictions' => '$rateguru_demo_shop_sender_restrictions',
        'content_filter' => 'rateguru-outbound-demo-shop:',
    ]);
    expect(mailGatewayMainParameters($render['main']))->toHaveKey('default_filter_nexthop');
    expect(mailGatewayMainParameters($render['main'])['default_filter_nexthop'])->toBe('');

    // Exactly one dedicated client, with the host's MTA identity, opportunistic
    // STARTTLS, no SMTP AUTH and no fallback relay.
    expect($services->where('name', 'rateguru-outbound-demo-shop')->count())->toBe(1);
    $transport = $services->firstWhere('name', 'rateguru-outbound-demo-shop');
    expect([$transport['type'], $transport['command']])->toBe(['unix', 'smtp']);
    expect($transport['options'])->toBe([
        'syslog_name' => 'postfix/rateguru-outbound-demo-shop',
        'smtp_helo_name' => 'mta1.example.net',
        'smtp_tls_security_level' => 'may',
        'smtp_tls_loglevel' => '1',
        'smtp_sasl_auth_enable' => 'no',
        'smtp_fallback_relay' => '',
    ]);

    // Only its own listener names it.
    $naming = $services->filter(static fn (array $service): bool => str_contains($service['options']['content_filter'] ?? '', 'rateguru-outbound-'));
    expect($naming->pluck('name')->values()->all())->toBe(['127.0.0.1:2599']);

    // The smtp clients are the capture transport and this one, nothing else.
    expect($services->where('command', 'smtp')->pluck('name')->sort()->values()->all())
        ->toBe(['rateguru-capture-staging-main', 'rateguru-outbound-demo-shop']);
});

it('gives every outbound target its own client, and no listener can reach another target\'s', function () {
    ['policy' => $policy, 'registry' => $registry] = mailRoutingTwoOutboundTargets();
    $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract());
    $services = collect(mailGatewayMasterServices($render['master']));

    $routes = $services->where('type', 'inet')->mapWithKeys(
        static fn (array $service): array => [$service['name'] => $service['options']['content_filter'] ?? null],
    )->all();

    expect($routes)->toBe([
        '127.0.0.1:2598' => 'rateguru-outbound-demo-books:',
        '127.0.0.1:2599' => 'rateguru-outbound-demo-shop:',
        '127.0.0.1:2525' => 'rateguru-capture-staging-main:[127.0.0.1]:1025',
        '127.0.0.1:2526' => '',
    ]);

    foreach (['demo-books', 'demo-shop'] as $identity) {
        expect($services->where('name', "rateguru-outbound-{$identity}")->count())->toBe(1);
    }

    // Both share the host's one MTA identity: it is the host's, not a brand's.
    expect($services->whereIn('name', ['rateguru-outbound-demo-books', 'rateguru-outbound-demo-shop'])->pluck('options.smtp_helo_name')->unique()->values()->all())
        ->toBe(['mta1.example.net']);
});

it('keeps every fallback undeliverable and nothing public once an outbound route exists', function () {
    $render = mailGatewayDirectRender();
    $main = mailGatewayMainParameters($render['main']);
    $services = collect(mailGatewayMasterServices($render['master']));

    foreach (['default_transport', 'relay_transport', 'local_transport', 'virtual_transport'] as $transport) {
        expect($main[$transport])->toStartWith('error:');
    }

    foreach (['relayhost', 'mydestination', 'relay_domains', 'transport_maps', 'content_filter', 'sender_dependent_relayhost_maps', 'sender_dependent_default_transport_maps'] as $empty) {
        expect($main[$empty])->toBe('', "{$empty} must be empty");
    }

    // No generic client: no smtp service, and relay is the error transport.
    expect($services->pluck('name')->all())->not->toContain('smtp');
    expect($services->firstWhere('name', 'relay')['command'])->toBe('error');

    // No SMTP AUTH anywhere, and the listeners stay plain loopback IPv4.
    expect($main['smtpd_sasl_auth_enable'])->toBe('no');
    expect($main['smtp_sasl_auth_enable'])->toBe('no');
    expect($main['smtpd_tls_security_level'])->toBe('none');
    expect($main['inet_interfaces'])->toBe('127.0.0.1');
    expect($main['inet_protocols'])->toBe('ipv4');

    foreach ($services->where('type', 'inet') as $service) {
        expect(preg_match('/\A127\.0\.0\.1:(\d+)\z/', $service['name'], $matches))->toBe(1);
        expect((int) $matches[1])->not->toBeIn([25, 465, 587]);
    }

    foreach (['smtp_sasl_password_maps', 'smtpd_tls_cert_file', 'opendkim', 'relayhost = ['] as $absent) {
        expect(str_contains(mb_strtolower($render['main'].$render['master']), $absent))->toBeFalse("the rendered gateway contains {$absent}");
    }

    // The outbound route adds no milter: only the signing plan's listeners
    // name the signer, and demo-shop is not in it here.
    expect(str_contains($render['main'], 'milter'))->toBeFalse();
    expect($services->filter(static fn (array $service): bool => isset($service['options']['smtpd_milters']))->pluck('name')->values()->all())
        ->toBe(['127.0.0.1:2526']);
});

it('renders a direct route identically every time, however its inputs are ordered', function () {
    $first = mailGatewayDirectRender();
    $second = mailGatewayDirectRender();

    expect($second['main'])->toBe($first['main']);
    expect($second['master'])->toBe($first['master']);

    $reverse = function (mixed $node) use (&$reverse): mixed {
        return is_array($node) && ! array_is_list($node) ? array_map($reverse, array_reverse($node, true)) : $node;
    };

    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $reordered = mailGatewayRender($reverse($policy), $reverse(mailRoutingDemoShopRegistry()), $reverse(mailGatewayOutboundContract()));

    expect($reordered['main'])->toBe($first['main']);
    expect($reordered['master'])->toBe($first['master']);
});

it('refuses to render a direct route unless the host contract enables direct delivery under a public name', function (array $contract, string $reason) {
    $scratch = mailGatewayScratch();

    try {
        $policy = mailGatewayDemoShopPolicy();
        $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson($policy, mailRoutingDemoShopRegistry()));
        file_put_contents($scratch.'/mail-outbound.json', mailRoutingJson($contract));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', $scratch.'/mail-outbound.json');

        expect($render['status'])->not->toBe(0);
        expect($render['output'])->toContain($reason);
        expect($render['main'].$render['master'])->toBe('', 'a refused contract still produced a configuration');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'disabled' => [mailGatewayOutboundContract(false, ''), 'demo-shop: its mail routing plan delivers by direct SMTP, but direct outbound delivery is not enabled on this host (mail-outbound.json direct.enabled is false)'],
    'disabled, with a hostname ready' => [mailGatewayOutboundContract(false), 'direct outbound delivery is not enabled on this host'],
    'enabled with no hostname' => [mailGatewayOutboundContract(true, ''), 'direct.enabled is true but direct.mta_hostname is empty'],
    'enabled under .invalid' => [mailGatewayOutboundContract(true, 'mail-gateway.rateguru.invalid'), 'direct.mta_hostname "mail-gateway.rateguru.invalid" is under the reserved .invalid domain'],
    'enabled under .test' => [mailGatewayOutboundContract(true, 'mta.rehearsal.test'), 'is under the reserved .test domain'],
    'enabled under .localhost' => [mailGatewayOutboundContract(true, 'mta.localhost'), 'is under the reserved .localhost domain'],
    'enabled under .example' => [mailGatewayOutboundContract(true, 'mta.demo-shop.example'), 'is under the reserved .example domain'],
    'enabled under .localdomain' => [mailGatewayOutboundContract(true, 'ubuntu.localdomain'), 'is under the reserved .localdomain domain'],
    'enabled with a bare label' => [mailGatewayOutboundContract(true, 'mta1'), 'direct.mta_hostname must be a lowercase fully qualified hostname, got "mta1"'],
    'enabled with an IP address' => [mailGatewayOutboundContract(true, '203.0.113.25'), 'must be a lowercase fully qualified hostname, got "203.0.113.25"'],
    'enabled with uppercase' => [mailGatewayOutboundContract(true, 'MTA1.example.net'), 'must be a lowercase fully qualified hostname'],
    'enabled with a trailing dot' => [mailGatewayOutboundContract(true, 'mta1.example.net.'), 'must be a lowercase fully qualified hostname'],
    'enabled with a second directive' => [mailGatewayOutboundContract(true, 'mta1.example.net relayhost=evil.example'), 'must be a lowercase fully qualified hostname'],
    'enabled with a newline' => [mailGatewayOutboundContract(true, "mta1.example.net\nrelayhost = evil.example"), 'must not contain control characters in any key or value'],
    'enabled as a string' => [mailGatewayOutboundContract('true'), 'direct.enabled must be true or false, got "true"'],
    'a credential beside it' => [['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net', 'password' => 'x']], 'direct must be exactly {enabled, mta_hostname}, found ["enabled","mta_hostname","password"]'],
    'a relay beside it' => [['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net'], 'relayhost' => '[smtp.example.com]:587'], 'mail-outbound.json must be exactly {schema_version, direct}'],
    'another schema' => [['schema_version' => 2, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.example.net']], 'unsupported mail-outbound.json schema_version: 2 (expected 1)'],
    'no direct section' => [['schema_version' => 1], 'mail-outbound.json must be exactly {schema_version, direct}, found ["schema_version"]'],
]);

it('refuses an outbound route in --check, --apply and --verify while direct delivery is disabled, changing nothing', function (array $contract, string $reason) {
    // A converged gateway first: the real plan, the committed contract.
    $host = mailGatewayHost();

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        $before = mailGatewayTree($host);
        file_put_contents($host['scratch'].'/log/mutations.log', '');

        // Only the policy moves to outbound; the host contract does not allow it.
        $policy = mailGatewayDemoShopPolicy();
        $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
        file_put_contents($host['scratch'].'/policy.json', mailRoutingJson($policy));
        file_put_contents($host['scratch'].'/registry.json', mailRoutingJson(mailRoutingDemoShopRegistry()));
        file_put_contents($host['scratch'].'/outbound.json', mailRoutingJson($contract));

        $env = [
            'RATEGURU_MAILGW_POLICY_FILE' => $host['scratch'].'/policy.json',
            'RATEGURU_MAILGW_REGISTRY_FILE' => $host['scratch'].'/registry.json',
            'RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/outbound.json',
        ];

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$status, $output] = mailGatewayRun($host, $mode, $env);

            expect($status)->not->toBe(0, "{$mode} accepted an outbound route the host has not enabled:\n{$output}");
            expect($output)
                ->toContain($reason)
                ->toContain('nothing was rendered, and nothing on the host was changed')
                ->not->toContain('APPLY    installing')
                ->not->toContain('SUMMARY');
        }

        // No file, no package, no service, no reload — not even a backup.
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'the committed contract' => [mailGatewayOutboundContract(false, 'mta1.tits.guru'), 'demo-shop: its mail routing plan delivers by direct SMTP, but direct outbound delivery is not enabled on this host'],
    'enabled with no hostname' => [mailGatewayOutboundContract(true, ''), 'direct.enabled is true but direct.mta_hostname is empty'],
    'enabled under .invalid' => [mailGatewayOutboundContract(true, 'mail.rateguru.invalid'), 'is under the reserved .invalid domain'],
]);

it('never installs Postfix for an outbound route the host has not enabled', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();

    $host = mailGatewayHost(['policy' => $policy, 'registry' => mailRoutingDemoShopRegistry()]);

    try {
        $before = mailGatewayTree($host);

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('direct outbound delivery is not enabled on this host');
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
        expect(mailGatewayLog($host, 'debconf.log'))->toBe('');
        expect(mailGatewayTree($host))->toBe($before);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/ownership'))->toBeFalse();
    } finally {
        mailGatewayCleanup($host);
    }
});

it('refuses a host contract it cannot trust as the reviewed file', function () {
    $host = mailGatewayHost();

    try {
        // Missing.
        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/absent.json']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('the host outbound contract is unavailable');

        // Reached through a symlink.
        file_put_contents($host['scratch'].'/real.json', mailRoutingJson(mailGatewayOutboundContract(false, '')));
        symlink($host['scratch'].'/real.json', $host['scratch'].'/link.json');
        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/link.json']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('the host outbound contract must not be a symlink');

        // A key declared twice: the reviewer and the parser would read different files.
        file_put_contents($host['scratch'].'/twice.json', '{"schema_version": 1, "direct": {"enabled": true, "enabled": false, "mta_hostname": ""}}');
        [$status, $output] = mailGatewayRun($host, '--check', ['RATEGURU_MAILGW_OUTBOUND_FILE' => $host['scratch'].'/twice.json']);
        expect($status)->not->toBe(0);
        expect($output)->toContain('declares the same key twice in one object');

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
});

it('installs, verifies and reports a direct route once the host enables direct delivery', function () {
    $policy = mailGatewayDemoShopPolicy();
    $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
    $registry = mailRoutingDemoShopRegistry();
    $identity = mailGatewayIdentityWithDemoShop();

    // An outbound target always has a reviewed identity, so it is signed too.
    $host = mailGatewayHost(['policy' => $policy, 'registry' => $registry, 'outbound' => mailGatewayOutboundContract(), 'identity' => $identity]);

    try {
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);

        $render = mailGatewayRender($policy, $registry, mailGatewayOutboundContract(), mailGatewaySigningPlanFor($policy, $registry, mailGatewayOutboundContract(), $identity));
        $direct = collect(mailGatewayMasterServices($render['master']))->firstWhere('name', '127.0.0.1:2599');
        expect($direct['options']['smtpd_milters'])->toBe('inet:127.0.0.1:8891');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->toBe($render['master']);
        expect(File::get($host['fs'].'/etc/postfix/main.cf'))->toBe($render['main']);

        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(0, $report);
        expect($report)
            ->toContain('PASS     outbound:direct — direct delivery enabled on this host as mta1.example.net; 1 outbound route(s) in the plan')
            ->toContain('PASS     signing — listeners of demo-shop, tits-guru hand their mail to the signer')
            ->toContain('SUMMARY  pass=9 missing=0 drift=0 conflict=0 deferred=0');

        // The read-only status shows the route as what it is, and no address.
        $status = mailGatewayStatus($host);
        expect($status)
            ->toContain('127.0.0.1:2599  rateguru-demo-shop  outbound, queued -> direct SMTP -> recipient MX (rateguru-outbound-demo-shop, HELO mta1.example.net, TLS may)')
            ->toContain('127.0.0.1:2526  rateguru-tits-guru  HELD')
            ->toContain('127.0.0.1:2525  rateguru-staging-main  capture, queued -> [127.0.0.1]:1025');
        expect(preg_match('/[a-z0-9._-]+@[a-z0-9][a-z0-9-]*\.[a-z]/i', $status))->toBe(0, "status printed an address:\n{$status}");
    } finally {
        mailGatewayCleanup($host);
    }
});

it('reads a direct route back through Postfix, and refuses every way it could be weakened', function (string $file, string $from, string $to, string $problem) {
    $scratch = mailGatewayScratch();

    try {
        $policy = mailGatewayDemoShopPolicy();
        $policy['targets']['demo-shop'] = mailRoutingDemoShopOutboundPolicy();
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson($policy, mailRoutingDemoShopRegistry()));
        file_put_contents($scratch.'/mail-outbound.json', mailRoutingJson(mailGatewayOutboundContract()));

        $render = mailGatewayRenderPlanFile($scratch, $scratch.'/plan.json', $scratch.'/mail-outbound.json');
        expect($render['status'])->toBe(0, $render['output']);

        $host = mailGatewayHost();
        $dir = $host['scratch'].'/etc';
        @mkdir($dir, 0o755, true);

        $read = function () use ($host, $dir, $scratch): string {
            $harness = 'source '.escapeshellarg(mailGatewayScript())
                .' && PLAN_FILE='.escapeshellarg($scratch.'/plan.json')
                .' OUTBOUND_FILE='.escapeshellarg($scratch.'/mail-outbound.json')
                .' SIGNING_FILE='.escapeshellarg(mailGatewayCommittedSigningPlan())
                .' MILTER_ENDPOINT=inet:127.0.0.1:8891'
                .' POSTCONF_BIN='.escapeshellarg($host['scratch'].'/bin/postconf')
                .' EFFECTIVE_UID=1000'
                .' && postfix_contract_problems '.escapeshellarg($dir);

            $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $host['scratch'], $host['env']);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            return $output;
        };

        try {
            file_put_contents($dir.'/main.cf', $render['main']);
            file_put_contents($dir.'/master.cf', $render['master']);
            expect($read())->toBe('', 'the untouched render must read back clean');

            $original = $file === 'main' ? $render['main'] : $render['master'];
            expect(substr_count($original, $from))->toBe(1, "the tamper anchor is not unique: {$from}");
            file_put_contents($dir."/{$file}.cf", str_replace($from, $to, $original));

            expect($read())->toContain($problem);
        } finally {
            mailGatewayCleanup($host);
        }
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }
})->with([
    'a target domain as the HELO name' => [
        'master', '-o smtp_helo_name=mta1.example.net', '-o smtp_helo_name=demo-shop.example',
        'rateguru-outbound-demo-shop greets as "demo-shop.example", not the host MTA identity "mta1.example.net"',
    ],
    'mandatory TLS' => [
        'master', '-o smtp_tls_security_level=may', '-o smtp_tls_security_level=encrypt',
        'rateguru-outbound-demo-shop has smtp_tls_security_level "encrypt", not may',
    ],
    'no TLS at all' => [
        'master', '-o smtp_tls_security_level=may', '-o smtp_tls_security_level=none',
        'rateguru-outbound-demo-shop has smtp_tls_security_level "none", not may',
    ],
    'SMTP AUTH' => [
        'master', "  -o smtp_sasl_auth_enable=no\n  -o smtp_fallback_relay=", "  -o smtp_sasl_auth_enable=yes\n  -o smtp_fallback_relay=",
        'rateguru-outbound-demo-shop has smtp_sasl_auth_enable "yes"',
    ],
    'a fallback relay' => [
        'master', '-o smtp_fallback_relay=', '-o smtp_fallback_relay=[smtp.example.com]:587',
        'rateguru-outbound-demo-shop has a fallback relay "[smtp.example.com]:587"',
    ],
    'a relay host on the filter' => [
        'master', '-o content_filter=rateguru-outbound-demo-shop:', '-o content_filter=rateguru-outbound-demo-shop:[smtp.example.com]:587',
        'demo-shop (127.0.0.1:2599) routes to "rateguru-outbound-demo-shop:[smtp.example.com]:587", not its own direct transport',
    ],
    'another listener naming it' => [
        'master', '-o content_filter=rateguru-capture-staging-main:[127.0.0.1]:1025', '-o content_filter=rateguru-outbound-demo-shop:',
        'staging-main (127.0.0.1:2525) routes to "rateguru-outbound-demo-shop:"',
    ],
    'held mail sent outbound' => [
        'master', "  -o smtpd_recipient_restrictions=check_client_access,static:HOLD,permit_mynetworks,reject\n  -o content_filter=\n", "  -o smtpd_recipient_restrictions=check_client_access,static:HOLD,permit_mynetworks,reject\n  -o content_filter=rateguru-outbound-demo-shop:\n",
        'tits-guru (127.0.0.1:2526) is held but names a route: rateguru-outbound-demo-shop:',
    ],
    'a generic smtp client' => [
        'master', "\n# --- Postfix internal services.", "\nsmtp      unix  -       -       n       -       -       smtp\n# --- Postfix internal services.",
        'smtp delivery agents are [rateguru-capture-staging-main rateguru-outbound-demo-shop smtp]',
    ],
    'relay as a working smtp client' => [
        'master', 'relay          unix  -       -       n       -       -       error', 'relay          unix  -       -       n       -       -       smtp',
        'smtp delivery agents are [rateguru-capture-staging-main rateguru-outbound-demo-shop relay]',
    ],
    'a second service of the same name' => [
        'master', "  -o smtp_fallback_relay=\n", "  -o smtp_fallback_relay=\nrateguru-outbound-demo-shop unix  -       -       n       -       -       smtp\n",
        'demo-shop has 2 services named rateguru-outbound-demo-shop, not exactly one',
    ],
    'a relayhost' => [
        'main', "\nrelayhost =\n", "\nrelayhost = [smtp.example.com]:587\n",
        'relayhost is "[smtp.example.com]:587", not empty',
    ],
    'a default filter next hop' => [
        'main', "\ndefault_filter_nexthop =", "\ndefault_filter_nexthop = smtp.example.com",
        'default_filter_nexthop is "smtp.example.com", not empty — an outbound route would deliver there instead of to the recipient domain MX',
    ],
    'a smarthost as the default transport' => [
        'main', "\ndefault_transport = error:", "\ndefault_transport = smtp:[smtp.example.com]:587\n# was: error:",
        'default_transport is "smtp:[smtp.example.com]:587", not the error transport',
    ],
]);

/**
 * Every jq program a mail script runs: the program variables it defines
 * (sourced, so this is the exact text jq receives) and every single-quoted
 * program given to jq inline.
 *
 * @return array<string, string> label => program
 */
function mailGatewayJqPrograms(string $script): array
{
    $path = mailGatewayScript($script);
    $programs = [];

    // status-mail-gateway runs on load and has no program variables. Only the
    // variables sourcing defines count: an inherited one such as a terminal's
    // TERM_PROGRAM is not a jq program.
    if ($script !== 'status-mail-gateway') {
        $harness = 'inherited="$(compgen -v)"; source "$1" >/dev/null 2>&1 || exit 1; '
            .'for name in $(compgen -v); do grep -qxF "${name}" <<<"${inherited}" && continue; '
            .'case "${name}" in *_PROGRAM|*_DEFINITIONS|*_RULES) printf "%s\n%s\0" "${name}" "${!name}" ;; esac; done';
        $output = (string) shell_exec('bash -c '.escapeshellarg($harness).' _ '.escapeshellarg($path));

        foreach (array_filter(explode("\0", $output)) as $entry) {
            [$name, $program] = explode("\n", $entry, 2);
            $programs["{$script} \${$name}"] = $program;
        }

        if (in_array($script, ['mail-routing', 'install-mail-gateway', 'mail-identity'], true)) {
            expect($programs)->not->toBe([], "no jq program variables were read from {$script}");
        }
    }

    preg_match_all("/\\bjq\\b[^'\\n]*'([^']*)'/", executableSourceLines(File::get($path)), $matches);

    foreach ($matches[1] as $index => $program) {
        $programs["{$script} inline #{$index}"] = $program;
    }

    return $programs;
}

/**
 * What Ubuntu 22.04's jq 1.6 — the jq on every host — refuses and jq 1.7, on
 * developer machines and in CI, accepts: an `if` with no `else`, a keyword used
 * as a `$variable` (the real `$label` this gateway's policy CLI once had), the
 * `?//` alternative operator, and builtins added after 1.6. Comments and string
 * contents are skipped; a string's `\(...)` interpolation is code, and is read.
 *
 * @return list<string>
 */
function mailGatewayJq16Problems(string $program): array
{
    $tokens = [];
    $frames = []; // 'string', or an int: the paren depth inside an interpolation
    $length = strlen($program);

    for ($i = 0; $i < $length; $i++) {
        $char = $program[$i];
        $inString = $frames !== [] && end($frames) === 'string';

        if ($inString) {
            if ($char === '\\') {
                if (($program[$i + 1] ?? '') === '(') {
                    $frames[] = 0;
                }

                $i++;
            } elseif ($char === '"') {
                array_pop($frames);
            }

            continue;
        }

        if ($char === '#') {
            $newline = strpos($program, "\n", $i);
            $i = $newline === false ? $length : $newline;
        } elseif ($char === '"') {
            $frames[] = 'string';
        } elseif ($char === '(' && $frames !== []) {
            $frames[count($frames) - 1]++;
        } elseif ($char === ')' && $frames !== []) {
            if (end($frames) === 0) {
                array_pop($frames);
            } else {
                $frames[count($frames) - 1]--;
            }
        } elseif ($char === '?' && substr($program, $i, 3) === '?//') {
            $tokens[] = '?//';
            $i += 2;
        } elseif (preg_match('/\G\$?[A-Za-z_][A-Za-z0-9_]*/', $program, $match, 0, $i)) {
            $tokens[] = $match[0];
            $i += strlen($match[0]) - 1;
        }
    }

    $problems = [];
    $counts = array_count_values($tokens);

    if (($counts['if'] ?? 0) !== ($counts['else'] ?? 0)) {
        $problems[] = sprintf('%d if but %d else: jq 1.6 requires an else on every if', $counts['if'] ?? 0, $counts['else'] ?? 0);
    }

    foreach (['__loc__', 'and', 'as', 'catch', 'def', 'elif', 'else', 'end', 'foreach', 'if', 'import', 'include', 'label', 'or', 'reduce', 'then', 'try'] as $keyword) {
        if (isset($counts['$'.$keyword])) {
            $problems[] = "\${$keyword} is a keyword jq 1.6 refuses as a variable name";
        }
    }

    foreach (['pick', 'abs', 'toarray', 'trim', 'ltrim', 'rtrim', 'have_decnum', 'have_literal_numbers', '?//'] as $newer) {
        if (isset($counts[$newer])) {
            $problems[] = "{$newer} does not exist in jq 1.6";
        }
    }

    return $problems;
}

it('keeps every jq program the mail scripts run within what jq 1.6 on the host accepts', function () {
    // The guard catches what it claims to, and nothing it should not.
    expect(mailGatewayJq16Problems('if . then 1 end'))->not->toBe([]);
    expect(mailGatewayJq16Problems('if . then 1 elif 2 then 3 end'))->not->toBe([]);
    expect(mailGatewayJq16Problems('def f($label): $label;'))->not->toBe([]);
    expect(mailGatewayJq16Problems('.a | pick(.b)'))->not->toBe([]);
    expect(mailGatewayJq16Problems('.[] as [$a] ?// $a | $a'))->not->toBe([]);
    expect(mailGatewayJq16Problems('if . then 1 elif 2 then 3 else 4 end'))->toBe([]);
    expect(mailGatewayJq16Problems('"\(if . then "if" else "x" end) if"'))->toBe([]);
    expect(mailGatewayJq16Problems("# if there is no else here\n. # nor here: if\n"))->toBe([]);
    expect(mailGatewayJq16Problems('"\(.a | join(", ")) and if"'))->toBe([]);

    $checked = 0;

    foreach (['mail-routing', 'mail-identity', 'install-mail-gateway', 'verify-mail-gateway', 'status-mail-gateway', 'verify-infrastructure'] as $script) {
        foreach (mailGatewayJqPrograms($script) as $label => $program) {
            expect(mailGatewayJq16Problems($program))->toBe([], "{$label} would not run on jq 1.6");
            $checked++;
        }
    }

    // The policy rules, the renderer and the outbound contract are among them.
    expect($checked)->toBeGreaterThan(20);
});

// =============================================================================
// VERIFY AND STATUS
// =============================================================================

it('keeps --read-only read-only by delegating it to the installer\'s own --verify', function () {
    $verifier = File::get(mailGatewayScript('verify-mail-gateway'));
    $readOnly = shellFunctionBody($verifier, 'run_read_only');

    expect($readOnly)->toContain('"${INSTALLER}" --verify');
    expect(executableSourceLines($readOnly))
        ->not->toContain('smtp_submit')
        ->not->toContain('postsuper')
        ->not->toContain('systemctl');

    // There is no default mode: the mutating acceptance is always asked for.
    $output = [];
    exec('bash '.escapeshellarg(mailGatewayScript('verify-mail-gateway')).' 2>&1', $output, $status);
    expect($status)->not->toBe(0);
    expect(implode("\n", $output))->toContain('the mutating acceptance is never a default');

    // Run for real, beside the installer it ships with, on a converged
    // gateway: the installer's own verdict, and nothing changed.
    $host = mailGatewayHost();

    try {
        // The installer's stability window is a real sleep, which its own
        // tests exercise; here it would only cost a second per run.
        @mkdir($host['scratch'].'/no-wait', 0o755);
        file_put_contents($host['scratch'].'/no-wait/sleep', "#!/bin/sh\nexit 0\n");
        chmod($host['scratch'].'/no-wait/sleep', 0o755);
        $env = ['PATH' => $host['scratch'].'/no-wait:'.$host['env']['PATH']];

        [$applied, $log] = mailGatewayRun($host, '--apply', $env);
        expect($applied)->toBe(0, $log);
        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayTree($host);

        [$verified, $report] = mailGatewayRun($host, '--read-only', $env, 'verify-mail-gateway');
        expect($verified)->toBe(0, $report);
        expect($report)
            ->toStartWith("install-mail-gateway --verify\n")
            ->toContain('PASS     runtime — enabled, stably running, listening on exactly the plan\'s loopback endpoints')
            ->toEndWith("SUMMARY  pass=9 missing=0 drift=0 conflict=0 deferred=0\n");

        // A hand edit is the installer's drift, and fails --read-only with it.
        file_put_contents($host['fs'].'/etc/postfix/main.cf', "# a hand edit\n", FILE_APPEND);
        [$drifted, $report] = mailGatewayRun($host, '--read-only', $env, 'verify-mail-gateway');
        expect($drifted)->toBe(1, $report);
        expect($report)
            ->toContain('DRIFT    file:/etc/postfix/main.cf — differs from the current render')
            ->toEndWith("SUMMARY  pass=8 missing=0 drift=1 conflict=0 deferred=0\n");

        expect(mailGatewayLog($host, 'mutations.log'))->toBe('', '--read-only called a mutating command');
        // Exactly the hand edit changed: no file was added, removed or rewritten.
        $expected = $before;
        $expected['etc/postfix/main.cf'] = hash_file('sha256', $host['fs'].'/etc/postfix/main.cf');
        expect(mailGatewayTree($host))->toBe($expected);
    } finally {
        mailGatewayCleanup($host);
    }
});

/**
 * The shipped sender probe against a fake SMTP server that answers MAIL FROM
 * with $mailReply. Returns every line the probe sent and what it concluded.
 *
 * @return array{lines: list<string>, output: string}
 */
function mailGatewayProbeFakeServer(string $mailReply): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse("could not listen: {$error}");
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    $harness = 'source '.escapeshellarg(mailGatewayScript('verify-mail-gateway'))
        .' && if smtp_probe_sender 127.0.0.1 '.$port.' intruder@foreign.example;'
        .' then echo "accepted ${SMTP_STAGE}"; else echo "refused ${SMTP_STAGE} ${SMTP_REPLY}"; fi'
        .' && bad "reported after the session"';

    $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);

    try {
        $client = stream_socket_accept($server, 10);
        expect($client)->not->toBeFalse('the probe never connected');
        stream_set_timeout($client, 10);

        $lines = [];
        fwrite($client, "220 fake ESMTP\r\n");
        $lines[] = rtrim((string) fgets($client));
        fwrite($client, "250 fake\r\n");
        $lines[] = rtrim((string) fgets($client));
        fwrite($client, $mailReply."\r\n");

        // Everything else it sends, until it hangs up.
        while (($line = fgets($client)) !== false) {
            $lines[] = rtrim($line);
        }

        fclose($client);
    } finally {
        fclose($server);
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    return ['lines' => $lines, 'output' => trim($output)];
}

it('probes a sender at MAIL FROM and never names a recipient, even when the gateway wrongly accepts it', function (string $reply, string $verdict) {
    $probe = mailGatewayProbeFakeServer($reply);

    expect($probe['lines'])->toBe([
        'EHLO mail-gateway-verify',
        'MAIL FROM:<intruder@foreign.example>',
        'RSET',
        'QUIT',
    ]);
    expect($probe['output'])->toStartWith($verdict);

    // The session opens and closes its socket with a bare exec; whatever it
    // silenced for that must not stay silenced, or every FAIL the acceptance
    // reports after its first session would reach nobody.
    expect($probe['output'])->toEndWith('FAIL reported after the session');
})->with([
    'a gateway that refuses the sender' => ['554 5.7.1 <intruder@foreign.example>: Sender address rejected', 'refused mail 554'],
    'a broken gateway that accepts it' => ['250 2.1.0 Ok', 'accepted mail'],
]);

it('never submits a message to an outbound listener, and refuses before it even connects', function () {
    $scratch = mailGatewayScratch();
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    try {
        $plan = static fn (string $mode, ?array $route): string => mailRoutingJson(['schema_version' => 2, 'listeners' => [[
            'identity' => 'demo-shop',
            'environment_class' => 'production',
            'lifecycle' => 'planned',
            'listen' => ['host' => '127.0.0.1', 'port' => $port],
            'delivery_mode' => $mode,
            'sender' => ['allowed_domain' => 'demo-shop.example', 'default_from' => 'hello@demo-shop.example', 'bounce_domain' => 'bounce.demo-shop.example', 'reply_domain' => 'reply.demo-shop.example'],
            'route' => $route,
        ]]]);

        $submit = static fn (string $planFile): string => 'source '.escapeshellarg(mailGatewayScript('verify-mail-gateway'))
            .' && PLAN_FILE='.escapeshellarg($planFile)
            .' && if smtp_submit 127.0.0.1 '.$port.' hello@demo-shop.example someone@recipient.example token;'
            .' then echo queued; else echo "stage=${SMTP_STAGE} ${SMTP_REPLY}"; fi';

        // An outbound listener — and an endpoint the plan does not name at
        // all — are refused before a connection is opened.
        file_put_contents($scratch.'/outbound.json', $plan('outbound', ['kind' => 'direct']));
        file_put_contents($scratch.'/empty.json', mailRoutingJson(['schema_version' => 2, 'listeners' => []]));

        foreach (['outbound.json', 'empty.json'] as $planFile) {
            $output = (string) shell_exec('bash -c '.escapeshellarg($submit($scratch.'/'.$planFile)).' 2>&1');
            expect($output)->toContain("stage=refused 127.0.0.1:{$port} is not a capture or held listener of the plan");
        }

        stream_set_blocking($server, false);
        expect(@stream_socket_accept($server, 0))->toBeFalse('smtp_submit connected to an outbound listener');

        // The same endpoint as a held listener is connected to: the refusal
        // above is the outbound rule, not an unreachable port.
        stream_set_blocking($server, true);
        file_put_contents($scratch.'/held.json', $plan('held', null));
        $process = proc_open(['bash', '-c', $submit($scratch.'/held.json')], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);

        $client = stream_socket_accept($server, 10);
        expect($client)->not->toBeFalse('smtp_submit did not connect to a held listener');
        fclose($client);

        expect(trim((string) stream_get_contents($pipes[1])))->toStartWith('stage=connect');
        fclose($pipes[1]);
        proc_close($process);
    } finally {
        fclose($server);
        exec('rm -rf '.escapeshellarg($scratch));
    }
});

it('accepts an outbound listener by sender isolation alone, and submits nothing to it', function () {
    $scratch = mailGatewayScratch();

    try {
        ['policy' => $policy, 'registry' => $registry] = mailRoutingTwoOutboundTargets();
        file_put_contents($scratch.'/plan.json', mailRoutingPlanJson($policy, $registry));

        // Every way to put a message on the wire, replaced by a recorder.
        $harness = 'source '.escapeshellarg(mailGatewayScript('verify-mail-gateway'))
            .' && PLAN_FILE='.escapeshellarg($scratch.'/plan.json')
            .' && smtp_submit() { echo "SUBMIT $*"; return 1; }'
            .' && check_outbound';
        $output = (string) shell_exec('bash -c '.escapeshellarg($harness).' 2>&1');

        expect($output)
            ->toContain('PASS E demo-books: outbound (direct) on 127.0.0.1:2598 — no message submitted')
            ->toContain('PASS E demo-shop: outbound (direct) on 127.0.0.1:2599 — no message submitted')
            ->not->toContain('SUBMIT');
    } finally {
        exec('rm -rf '.escapeshellarg($scratch));
    }

    $verifier = File::get(mailGatewayScript('verify-mail-gateway'));

    // It runs as part of the acceptance, and sender isolation only ever probes.
    expect(shellFunctionBody($verifier, 'run_e2e'))->toContain('check_outbound');
    expect(shellFunctionBody($verifier, 'check_sender_isolation'))
        ->toContain('smtp_probe_sender "${host}" "${port}" "intruder@${other}"')
        ->not->toContain('smtp_submit');

    // A whole message goes only to capture and held listeners.
    foreach (['check_capture_delivery' => 'listeners capture', 'check_held' => 'listeners held', 'check_capture_retry' => 'listeners capture'] as $function => $source) {
        expect(shellFunctionBody($verifier, $function))->toContain('smtp_submit')->toContain($source);
    }

    expect(substr_count(executableSourceLines($verifier), 'smtp_submit "'))->toBe(3);
});

it('cleans up only what the acceptance created, and never empties the queue', function () {
    $code = executableSourceLines(File::get(mailGatewayScript('verify-mail-gateway')));

    foreach (['postqueue -f', 'postsuper -d ALL', 'postsuper -H ALL', 'postsuper -r ALL', 'postsuper -d -', 'postqueue -p', '/api/v1/messages" >/dev/null 2>&1 || true'] as $forbidden) {
        // The last one is allowed only as the token-scoped delete below.
        if (str_starts_with($forbidden, '/api')) {
            continue;
        }

        expect(str_contains($code, $forbidden))->toBeFalse("verify-mail-gateway runs {$forbidden}");
    }

    // Queue entries are removed by the exact ID Postfix named; Mailpit and
    // Mailtrap messages by this run's unique token.
    expect($code)
        ->toContain('postsuper -d "${id}"')
        ->toContain('postsuper -d "${SMTP_QUEUE_ID}" hold')
        ->toContain('postqueue -i "${id}" >/dev/null 2>&1 || true')
        ->toContain('/api/v1/search?query=${token}')
        ->toContain("SMTP_QUEUE_ID=\"\$(sed -n 's/.*queued as \\([0-9A-Za-z]\\{1,\\}\\).*/\\1/p' <<<\"\${SMTP_REPLY}\")\"");

    // Every acceptance is driven by the plan, not by a list of targets.
    expect($code)
        ->toContain('listeners capture')
        ->toContain('listeners held')
        ->toContain('listeners any')
        ->toContain('"${MAIL_ROUTING_CLI}" render-plan');

    // Mailpit is put back on every exit.
    expect(shellFunctionBody(File::get(mailGatewayScript('verify-mail-gateway')), 'cleanup'))
        ->toContain('systemctl start "${MAILPIT_UNIT}"');
});

it('prints status without a body, an address or a credential', function () {
    $status = executableSourceLines(File::get(mailGatewayScript('status-mail-gateway')));

    // A queue it could not read is unknown, never reported as empty.
    expect($status)
        ->toContain("printf '  unknown (jq not installed)\\n'")
        ->toContain("printf '  unknown (postqueue could not read the queue)\\n'");
    expect(strpos($status, 'command -v jq'))->toBeLessThan(strpos($status, 'postqueue -j'));

    expect($status)
        ->toContain("jq -r '.queue_name'")
        ->not->toContain('postcat')
        ->not->toContain('.recipients')
        ->not->toContain('.sender')
        ->not->toContain('postqueue -p')
        ->not->toMatch('/\b(systemctl (start|stop|restart|reload|enable|disable)|postsuper|postfix (reload|start|stop|flush))\b/');
});

// =============================================================================
// HOST BOOTSTRAP OWNS IT AT HOST SCOPE, AFTER MAIL CAPTURE, AND ONLY THERE
// =============================================================================

it('converges the gateway after mail capture, at host scope only', function () {
    $services = File::get(base_path('infrastructure/scripts/install-bootstrap-services'));
    $apply = shellFunctionBody($services, 'perform_apply');

    $capture = strpos($apply, 'converge_mail_capture');
    $gateway = strpos($apply, 'converge_mail_gateway');
    expect($capture)->not->toBeFalse();
    expect($gateway)->toBeGreaterThan($capture);

    // The plan gate runs before the first mutation, beside the other gates.
    expect(strpos($apply, 'apply_gate_mail_gateway'))->toBeLessThan(strpos($apply, 'trap on_apply_error ERR EXIT'));
    expect(shellFunctionBody($services, 'apply_gate_mail_gateway'))->toContain('target_scoped && return 0');

    // Reported after mail capture, and only when not target-scoped.
    $report = shellFunctionBody($services, 'report_child_contracts');
    expect(strpos($report, '"mail-gateway:install-mail-gateway"'))->toBeGreaterThan(strpos($report, '"mail-capture:verify-mail-capture"'));
    expect($report)->toMatch('/if ! target_scoped; then\s+report_child_state "mail-capture:verify-mail-capture"/');

    // Never the mutating acceptance.
    expect(executableSourceLines($services))->not->toContain('verify-mail-gateway');
    expect(executableSourceLines($services))->not->toContain('--e2e');
});

it('keeps target-scoped repair and provisioning away from the host-global gateway', function () {
    foreach (['provision-target', 'repair-target', 'prepare-host', 'bootstrap-host'] as $orchestrator) {
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/'.$orchestrator))))
            ->not->toContain('mail-gateway')
            ->not->toContain('postfix');
    }
});

// =============================================================================
// THE ACCEPTANCE IS A LOW-LEVEL PRIMITIVE, NOT A WORKFLOW OF ITS OWN
// =============================================================================

it('leaves no trace of the one-time gateway acceptance workflow, or of its action', function () {
    expect(file_exists(base_path('.github/workflows/verify-staging-mail-gateway.yml')))->toBeFalse();
    expect(is_dir(base_path('.github/actions/verify-rateguru-mail-gateway')))->toBeFalse();

    // Nothing tracked still names it — no workflow, action, script, runbook,
    // roadmap or test other than this one.
    exec('git -C '.escapeshellarg(base_path()).' ls-files -z', $output, $status);
    expect($status)->toBe(0);

    $self = 'tests/Feature/Architecture/MailGatewayTest.php';
    $files = array_filter(explode("\0", implode("\n", $output)), static fn (string $path): bool => $path !== '' && $path !== $self);

    foreach ($files as $path) {
        if (! is_file(base_path($path)) || str_starts_with($path, 'tests/.pest/')) {
            continue;
        }

        $source = (string) file_get_contents(base_path($path));

        foreach (['verify-staging-mail-gateway', 'verify-rateguru-mail-gateway', 'Verify staging mail gateway'] as $needle) {
            expect(str_contains($source, $needle))->toBeFalse("{$path} still refers to {$needle}");
        }
    }
});

it('keeps no composite action that nothing calls', function () {
    $callers = collect([...(glob(base_path('.github/workflows/*.yml')) ?: []), ...(glob(base_path('.github/actions/*/action.yml')) ?: [])])
        ->map(static fn (string $path): string => executableSourceLines((string) file_get_contents($path)))
        ->implode("\n");

    foreach (glob(base_path('.github/actions/*'), GLOB_ONLYDIR) ?: [] as $action) {
        $name = basename($action);

        expect(preg_match('#uses:\s*\./\.github/actions/'.preg_quote($name, '#').'\s*$#m', $callers))
            ->toBe(1, ".github/actions/{$name} has no caller");
    }
});

it('keeps verify-mail-gateway --e2e as the low-level acceptance primitive, run on the host by hand', function () {
    $verifier = mailGatewayScript('verify-mail-gateway');

    expect(is_executable($verifier))->toBeTrue();
    expect(requiredCliManifestNames())->toContain('verify-mail-gateway');

    exec('bash '.escapeshellarg($verifier).' --help 2>&1', $output, $status);
    expect($status)->toBe(0);
    expect(implode("\n", $output))->toContain('verify-mail-gateway --e2e');

    // No workflow or action drives it any more.
    foreach ([...(glob(base_path('.github/workflows/*.yml')) ?: []), ...(glob(base_path('.github/actions/*/action.yml')) ?: [])] as $path) {
        expect(executableSourceLines((string) file_get_contents($path)))
            ->not->toContain('verify-mail-gateway', basename(dirname($path)).'/'.basename($path).' runs the mail gateway acceptance');
    }
});

it('never runs the mutating acceptance from ordinary preparation', function () {
    foreach ([
        '.github/workflows/prepare-staging-host.yml',
        '.github/workflows/prepare-production-host.yml',
        '.github/actions/prepare-rateguru-host/action.yml',
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/bootstrap-host',
        'infrastructure/scripts/install-bootstrap-services',
    ] as $path) {
        expect(executableSourceLines(File::get(base_path($path))))->not->toContain('verify-mail-gateway');
    }
});

// =============================================================================
// WHAT DID NOT MOVE
// =============================================================================

it('keeps tits-guru planned and held, and the production environment untouched', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
    $policy = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');
    expect($policy['targets']['tits-guru']['delivery_mode'])->toBe('held');

    // The staging template names the gateway; production's does not move.
    expect(envFileValues('infrastructure/templates/environment/staging.env.example')['MAIL_PORT'])->toBe('2525');

    $production = envFileValues('infrastructure/templates/environment/production.env.example');
    expect($production['MAIL_MAILER'])->toBe('');
    expect($production)->not->toHaveKey('MAIL_PORT');

    foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS'] as $key) {
        expect(envFileValues('infrastructure/templates/environment/tits-guru.env.example')[$key])->toBe('');
    }
});

it('records the gateway as accepted on the real host, and the direct outbound capability as implemented and not activated', function () {
    $roadmap = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/ROADMAP.md')));

    expect($roadmap)
        ->toContain('**8.4B.2 Local mail gateway and the staging capture route — IMPLEMENTED AND ACCEPTED on the real staging host.**')
        ->toContain('The operator then changed the staging `shared/.env` to `MAIL_PORT=2525`, staging was redeployed, and a real Laravel password-reset email arrived in Mailpit and in Mailtrap Local.')
        ->toContain('**8.4B.3 Self-hosted direct outbound SMTP capability — IMPLEMENTED, not activated.**')
        ->toContain('*Unchanged on purpose:* `tits-guru` is still `held` and `lifecycle=planned`, the real `mail-outbound.json` keeps direct delivery disabled')
        ->toContain('no email was sent to the public Internet')
        ->toContain('**8.4B.4.1 Production mail identity foundation — IMPLEMENTED, nothing activated.**')
        ->toContain('**8.4B.4.2a DKIM signing foundation — IMPLEMENTED — production acceptance pending.**')
        ->toContain('**8.4B.4.2b DNS-ready activation and the first real delivery — planned.**')
        // The backup gate stays; acceptance moves to after activation.
        ->toContain('`backup-cycle` runs only for a `lifecycle=active` target')
        ->toContain('That gate is deliberate and is not weakened to take a backup early.')
        ->toContain('This is where the first real `backup-cycle --target tits-guru` runs — after activation and the first real production deploy, before any public traffic')
        ->not->toContain('implemented but not accepted')
        ->not->toContain('not yet installed or accepted on the real host');

    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/mail-gateway.md')));

    expect($runbook)
        ->toContain('| Gateway on the staging host | **Installed and accepted**')
        ->toContain('| Staging application mail | **Through the gateway**: the host\'s `shared/.env` says `MAIL_PORT=2525`')
        ->toContain('**Implemented, not activated**')
        ->toContain('keeps direct delivery **disabled** on the host');
});
