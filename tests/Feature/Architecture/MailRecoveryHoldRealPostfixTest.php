<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The recovery outbound hold on a REAL Postfix:
 * tests/Support/real-postfix/recovery-outbound-hold.sh runs the configurations
 * install-mail-gateway renders — the reviewed plan, and the same plan as the
 * gateway's own recovery-hold step makes it while a hold stands — in a network
 * and mount namespace of its own, and checks against what Postfix does that a
 * held production target's mail waits on HOLD with no direct route, through a
 * reload, a stop and start and a flush, and that a release never releases it.
 *
 * It needs root (or sudo without a password), Ubuntu's postfix package and
 * unshare, so here it runs only where RATEGURU_REAL_POSTFIX=1 asks for it. CI
 * runs it in the "Mail on a real Postfix" job; the rest of this file proves
 * that job runs it, and that it never reaches the host's own Postfix.
 */
it('runs the recovery outbound hold on a real Postfix, where the host allows it', function () {
    if (getenv('RATEGURU_REAL_POSTFIX') !== '1') {
        $this->markTestSkipped('RATEGURU_REAL_POSTFIX=1 runs it: it needs root or sudo -n, the postfix package and unshare');
    }

    $privilege = posix_geteuid() === 0 ? [] : ['sudo', '-n'];
    // The gateway splices this path into Postfix syntax, and takes only a plain lowercase one.
    $work = '/tmp/rateguru-recovery-hold-'.bin2hex(random_bytes(6));
    mkdir($work, 0o755);

    try {
        $process = new Process([...$privilege, 'unshare', '--net', '--mount', 'bash', base_path('tests/Support/real-postfix/recovery-outbound-hold.sh'), base_path(), $work], null, null, null, 600);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toContain("SCENARIO: PASS\n")->not->toContain("\nFAIL ");
    } finally {
        (new Process([...$privilege, 'rm', '-rf', '--', $work]))->run();
    }
});

it('runs in the real-Postfix CI job, after the gateway scenario, in namespaces of its own', function () {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $steps = collect($ci['jobs']['mail-real-postfix']['steps']);
    $names = $steps->pluck('name')->all();

    $run = $steps->firstWhere('name', 'Run the recovery outbound hold in a network and mount namespace of its own')['run'];
    expect($run)
        ->toContain('work="/tmp/rateguru-recovery-hold-')
        ->toContain('sudo unshare --net --mount bash tests/Support/real-postfix/recovery-outbound-hold.sh "${GITHUB_WORKSPACE}" "${work}"');

    expect(array_search('Run the recovery outbound hold in a network and mount namespace of its own', $names, true))
        ->toBeGreaterThan(array_search('Run the scenario in a network and mount namespace of its own', $names, true));
});

it('renders through the gateway\'s own hold step and the hold library\'s own place step, and never leaves its namespaces', function () {
    $code = executableSourceLines(File::get(base_path('tests/Support/real-postfix/recovery-outbound-hold.sh')));

    expect($code)
        ->toContain('source "${SCRIPTS}/install-mail-gateway"')
        ->toContain('[[ "${state}" == released ]] || apply_recovery_outbound_holds')
        ->toContain('render_gateway_config "${PLAN_FILE}" "${OUTBOUND_FILE}" "${WORK}/signing.json" inet:127.0.0.1:8891 "${dir}"')
        ->toContain('source "${SCRIPTS}/recovery-outbound-hold"')
        ->toContain('recovery_outbound_hold_place "${ROOT}${RECOVERY_OUTBOUND_HOLD_DIR}" tits-guru 20261020-030000');

    // It refuses to run outside a network and a mount namespace of its own, or with a route off the host.
    expect($code)
        ->toContain('for namespace in net mnt; do')
        ->toContain('if [[ "$(readlink "/proc/self/ns/${namespace}")" == "$(readlink "/proc/1/ns/${namespace}")" ]]; then')
        ->toContain('if ip -4 route get 1.1.1.1 >/dev/null 2>&1; then');

    // /etc/postfix is a copy, bind-mounted in its own mount namespace.
    $touching = array_values(array_filter(array_map('trim', explode("\n", $code)), static fn (string $line): bool => preg_match('#(?<![\w}])/etc/postfix#', $line) === 1));
    expect($touching)->toBe([
        '[[ -d /etc/postfix ]] || { echo "recovery-outbound-hold.sh needs the Postfix package\'s /etc/postfix" >&2; exit 2; }',
        'cp -a /etc/postfix "${WORK}/etc-postfix"',
        'mount --bind "${WORK}/etc-postfix" /etc/postfix',
    ]);

    // Every Postfix command names this run's configuration; the host's service is never touched.
    preg_match_all('/(?:^|;|&&|\|\||\$\(|\bthen|\bdo)\s*(postfix|postqueue|postsuper)\s+(\S+ \S+)/m', $code, $commands);
    expect($commands[1])->not->toBeEmpty();
    foreach ($commands[2] as $index => $arguments) {
        expect($arguments)->toStartWith('-c "${CFG}"', "{$commands[1][$index]} runs without this run's configuration");
    }
    expect($code)->not->toContain('systemctl')->not->toContain('service postfix');
});
