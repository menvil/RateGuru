<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The public HTTP site on a REAL Nginx: tests/Support/real-nginx/scenario.sh
 * runs prepare-public-http against the sites a production host carries, in a
 * network and mount namespace of its own, and checks every claim against what
 * Nginx answers — the challenge from the webroot, the 503, the MX host's
 * HTTP-01 route, the order of the sites, and every failure put back.
 *
 * It needs root (or sudo without a password), Ubuntu's nginx package and
 * unshare, so here it runs only where RATEGURU_REAL_NGINX=1 asks for it. CI
 * runs it in a job of its own, "Public HTTP on a real Nginx"; the rest of this
 * file proves that job runs it, safely, and that its result decides CI.
 */
it('runs the real-Nginx scenario, where the host allows it', function () {
    if (getenv('RATEGURU_REAL_NGINX') !== '1') {
        $this->markTestSkipped('RATEGURU_REAL_NGINX=1 runs it: it needs root or sudo -n, the nginx package and unshare');
    }

    $privilege = posix_geteuid() === 0 ? [] : ['sudo', '-n'];
    $work = '/tmp/rateguru-real-nginx-'.bin2hex(random_bytes(6));
    mkdir($work, 0o755);

    try {
        $process = new Process([...$privilege, 'unshare', '--net', '--mount', 'bash', base_path('tests/Support/real-nginx/scenario.sh'), base_path(), $work], null, null, null, 600);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toContain("SCENARIO: PASS\n")->not->toContain("\nFAIL ");
    } finally {
        (new Process([...$privilege, 'rm', '-rf', '--', $work]))->run();
    }
});

it('runs in CI as a job of its own, holding no secret, and its result decides CI', function () {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $job = $ci['jobs']['public-http-real-nginx'];
    $steps = collect($job['steps']);

    expect($job['name'])->toBe('Public HTTP on a real Nginx');
    expect($job['runs-on'])->toBe('ubuntu-24.04');
    expect($job)->not->toHaveKey('environment');
    // It runs a script as root: no write permission, and no token in the checkout.
    expect($job['permissions'])->toBe(['contents' => 'read']);
    expect($job['steps'][0])->toBe(['name' => 'Checkout', 'uses' => 'actions/checkout@v7', 'with' => ['persist-credentials' => false]]);
    expect(json_encode($job))->not->toContain('secrets.');

    // Ubuntu's own package, its service stopped: the scenario starts its own Nginx in its namespace.
    $install = $steps->firstWhere('name', 'Install Nginx, its service stopped')['run'];
    expect($install)
        ->toContain('apt-get install -y -qq --no-install-recommends nginx')
        ->toContain('sudo systemctl stop nginx');

    $run = $steps->firstWhere('name', 'Run the scenario in a network and mount namespace of its own')['run'];
    expect($run)
        ->toContain('work="/tmp/rateguru-real-nginx-')
        ->toContain('sudo unshare --net --mount bash tests/Support/real-nginx/scenario.sh "${GITHUB_WORKSPACE}" "${work}"');

    // Its result reaches the summary and the verdict, which fails on anything but success.
    expect($ci['jobs']['ci-summary']['needs'])->toContain('public-http-real-nginx');
    $verdict = collect($ci['jobs']['ci-summary']['steps'])->first(
        fn (array $step): bool => str_contains($step['run'] ?? '', 'At least one CI job did not succeed.'),
    );
    expect($verdict['env']['PUBLIC_HTTP_REAL_NGINX_RESULT'])->toBe("\${{ needs['public-http-real-nginx'].result }}");
    expect($verdict['run'])->toContain('"$PUBLIC_HTTP_REAL_NGINX_RESULT"');
});

it('runs prepare-public-http itself, and the internal site as install-bootstrap-services renders it', function () {
    $code = executableSourceLines(File::get(base_path('tests/Support/real-nginx/scenario.sh')));

    expect($code)
        ->toContain('"${SCRIPTS}/prepare-public-http" "--${mode}" --target "${TARGET}"')
        ->toContain('source "${SCRIPTS}/install-bootstrap-services"')
        ->toContain('render_nginx_site "${TARGET}"')
        ->toContain('ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default')
        // The only stand-ins: systemctl, which reloads this Nginx, and an nginx
        // that refuses -t on request and otherwise is Nginx.
        ->toContain('RATEGURU_PUBLICHTTP_SYSTEMCTL_BIN="${WORK}/bin/systemctl"')
        ->toContain('RATEGURU_PUBLICHTTP_NGINX_BIN="${WORK}/bin/nginx"')
        ->not->toContain('RATEGURU_PUBLICHTTP_CURL_BIN')
        ->not->toContain('RATEGURU_PUBLICHTTP_FS_ROOT');
});

it('never leaves its namespaces, writes the host\'s Nginx, or touches its service', function () {
    $source = File::get(base_path('tests/Support/real-nginx/scenario.sh'));
    $code = executableSourceLines($source);

    // It refuses to run outside a network and a mount namespace of its own, or with a route off the host.
    expect($code)
        ->toContain('for namespace in net mnt; do')
        ->toContain('if [[ "$(readlink "/proc/self/ns/${namespace}")" == "$(readlink "/proc/1/ns/${namespace}")" ]]; then')
        ->toContain('if ip -4 route get 1.1.1.1 >/dev/null 2>&1; then');

    // Every host path it writes is a copy or an empty directory, bind-mounted in its own mount namespace.
    preg_match_all('/^\s*mount --bind (\S+) (\S+)$/m', $code, $mounts);
    expect(array_combine($mounts[2], $mounts[1]))->toBe([
        '/etc/nginx' => '"${WORK}/etc-nginx"',
        '/var/www/html' => '"${WORK}/www-html"',
        '/var/log/nginx' => '"${WORK}/log-nginx"',
        '/run' => '"${WORK}/run"',
    ]);
    $bound = strpos($code, 'mount --bind "${WORK}/run" /run');
    foreach (['find /etc/nginx/sites-enabled', 'install -d -m 0755 /var/www/html', '/etc/nginx/rateguru-staging.htpasswd', 'nginx -t >/dev/null'] as $write) {
        expect(strpos($code, $write))->toBeGreaterThan($bound, "{$write} runs before the copies are bound");
    }

    // The host's systemd is never asked anything: the stand-in under WORK answers.
    $standIn = ['"${WORK}/bin/systemctl"', '"${WORK}/systemctl.log"', '"${WORK}/systemctl-all.log"'];
    expect(str_replace($standIn, '', $code))->not->toMatch('/\bsystemctl\s/');
    preg_match('/^cat >"\$\{WORK\}\/bin\/systemctl" <<EOF\n(.*?)\nEOF$/ms', $source, $body);
    expect($body[1] ?? '')
        ->toContain('is-active) [[ "\$2" == --quiet && "\$3" == nginx ]]')
        ->toContain('nginx -s reload')
        ->toContain('*) exit 1 ;;')
        ->not->toContain('restart')
        ->not->toContain('nginx -s stop');
});
