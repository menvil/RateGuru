<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The host's one Postfix, with inbound mail, on a REAL Postfix:
 * tests/Support/real-postfix/scenario.sh runs the configuration
 * install-mail-gateway renders, in a network and mount namespace of its own,
 * and checks every claim against what Postfix does.
 *
 * It needs root (or sudo without a password), Ubuntu's postfix package and
 * unshare, so here it runs only where RATEGURU_REAL_POSTFIX=1 asks for it. CI
 * runs it in a job of its own, "Mail on a real Postfix"; the rest of this file
 * proves that job runs it, safely, and that its result decides CI.
 */
it('runs the real-Postfix scenario, where the host allows it', function () {
    if (getenv('RATEGURU_REAL_POSTFIX') !== '1') {
        $this->markTestSkipped('RATEGURU_REAL_POSTFIX=1 runs it: it needs root or sudo -n, the postfix package and unshare');
    }

    $privilege = posix_geteuid() === 0 ? [] : ['sudo', '-n'];
    // The gateway splices this path into Postfix syntax, and takes only a plain lowercase one.
    $work = '/tmp/rateguru-real-postfix-'.bin2hex(random_bytes(6));
    mkdir($work, 0o755);

    try {
        $process = new Process([...$privilege, 'unshare', '--net', '--mount', 'bash', base_path('tests/Support/real-postfix/scenario.sh'), base_path(), $work], null, null, null, 600);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toContain("SCENARIO: PASS\n")->not->toContain("\nFAIL ");
    } finally {
        (new Process([...$privilege, 'rm', '-rf', '--', $work]))->run();
    }
});

it('runs in CI as a job of its own, holding no secret, and its result decides CI', function () {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $job = $ci['jobs']['mail-real-postfix'];
    $steps = collect($job['steps']);

    expect($job['runs-on'])->toBe('ubuntu-24.04');
    expect($job)->not->toHaveKey('environment');
    expect(json_encode($job))->not->toContain('secrets.');

    // Ubuntu's own package, with no configuration of its own and its service stopped.
    $install = $steps->firstWhere('name', 'Install Postfix, with no configuration of its own')['run'];
    expect($install)
        ->toContain('echo "postfix postfix/main_mailer_type select No configuration" | sudo debconf-set-selections')
        ->toContain('apt-get install -y -qq --no-install-recommends postfix')
        ->toContain('sudo systemctl stop postfix');

    // The scenario, in namespaces of its own, on a lowercase scratch path.
    $run = $steps->firstWhere('name', 'Run the scenario in a network and mount namespace of its own')['run'];
    expect($run)
        ->toContain('work="/tmp/rateguru-real-postfix-')
        ->toContain('sudo unshare --net --mount bash tests/Support/real-postfix/scenario.sh "${GITHUB_WORKSPACE}" "${work}"');

    // Its result reaches the summary and the verdict, which fails on anything but success.
    expect($ci['jobs']['ci-summary']['needs'])->toContain('mail-real-postfix');
    $verdict = collect($ci['jobs']['ci-summary']['steps'])->first(
        fn (array $step): bool => str_contains($step['run'] ?? '', 'At least one CI job did not succeed.'),
    );
    expect($verdict['env']['MAIL_REAL_POSTFIX_RESULT'])->toBe("\${{ needs['mail-real-postfix'].result }}");
    expect($verdict['run'])->toContain('"$MAIL_REAL_POSTFIX_RESULT"');
});

it('runs the gateway\'s own renderer and read-back, never a configuration of its own', function () {
    $code = executableSourceLines(File::get(base_path('tests/Support/real-postfix/scenario.sh')));

    expect($code)
        ->toContain('source "${SCRIPTS}/install-mail-gateway"')
        ->toContain('render_gateway_config "${WORK}/plan.json" "${CONFIG}/mail-outbound.json" "${WORK}/signing.json" inet:127.0.0.1:8891 "${dir}" "${input}"')
        ->toContain('postfix_contract_problems "$2"')
        ->toContain('"${SCRIPTS}/mail-inbound" render-receiver')
        // Every address of the corpus is judged by mail-inbound route, not by the scenario.
        ->toContain('"${SCRIPTS}/mail-inbound" route --recipient');

    // No Postfix setting is written but where this run's queue, data and log live.
    preg_match_all('/^\s*(?:postconf -e|postconf -c "[^"]+" -e)/m', $code, $edits);
    expect($edits[0])->toBe([]);
});

it('never leaves its namespaces, writes the host\'s Postfix, or touches its service', function () {
    $code = executableSourceLines(File::get(base_path('tests/Support/real-postfix/scenario.sh')));

    // It refuses to run outside a network and a mount namespace of its own, or with a route off the host.
    expect($code)
        ->toContain('for namespace in net mnt; do')
        ->toContain('if [[ "$(readlink "/proc/self/ns/${namespace}")" == "$(readlink "/proc/1/ns/${namespace}")" ]]; then')
        ->toContain('if ip -4 route get 1.1.1.1 >/dev/null 2>&1; then');

    // /etc/postfix is a copy, bind-mounted in its own mount namespace; nothing is written to the real one.
    $touching = array_values(array_filter(array_map('trim', explode("\n", $code)), static fn (string $line): bool => str_contains($line, '/etc/postfix')));
    expect($touching)->toBe([
        '[[ -d /etc/postfix ]] || { echo "scenario.sh needs the Postfix package\'s /etc/postfix" >&2; exit 2; }',
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
