<?php

use Illuminate\Support\Facades\File;

/**
 * The policy the host-global mail gateway records as applied: written inside
 * its transaction, verified against what Postfix renders, and established on
 * an existing gateway.
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

/** Rewrite one recorded policy document canonically, changed by a path => value map. */
function mailGatewayTamperApplied(array $host, string $name, array $changes): void
{
    $document = json_decode(File::get(mailGatewayApplied($host, $name)), true, 512, JSON_THROW_ON_ERROR);

    foreach ($changes as $path => $value) {
        data_set($document, $path, $value);
    }

    $scratch = $host['scratch'].'/tampered.json';
    file_put_contents($scratch, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents(mailGatewayApplied($host, $name), shell_exec('jq -S . '.escapeshellarg($scratch)));
}

// =============================================================================
// THE APPLIED POLICY: THE HOST'S OWN WITNESS OF WHAT WAS ACCEPTED
// =============================================================================

it('records the whole canonical plan and host contract it applied, deterministically and with nothing secret', function () {
    $host = mailGatewayEstablishedHost();

    try {
        $plan = File::get(mailGatewayApplied($host));
        $outbound = File::get(mailGatewayApplied($host, 'applied-outbound.json'));

        // The complete plan, every field — not the part Postfix renders.
        $recorded = json_decode($plan, true);
        expect($recorded)->toEqual(mailRoutingPlan(mailPreActivationPolicy()['routing']));
        $titsGuru = collect($recorded['listeners'])->firstWhere('identity', 'tits-guru');
        expect($titsGuru)->toMatchArray(['environment_class' => 'production', 'lifecycle' => 'planned', 'delivery_mode' => 'held', 'route' => null]);
        expect($titsGuru['sender'])->toBe(['allowed_domain' => 'tits.guru', 'bounce_domain' => 'bounce.tx.tits.guru', 'default_from' => 'noreply@tits.guru', 'reply_domain' => 'reply.tits.guru']);
        expect(collect($recorded['listeners'])->firstWhere('identity', 'staging-main')['route'])->toBe(['host' => '127.0.0.1', 'kind' => 'capture', 'port' => 1025]);
        expect(json_decode($outbound, true))->toEqual(mailPreActivationPolicy()['outbound']);

        // Canonical: exactly what jq -S makes of it, so equal policies are
        // equal bytes.
        expect($plan)->toBe(shell_exec('jq -S . '.escapeshellarg(mailGatewayApplied($host))));
        expect($outbound)->toBe(shell_exec('jq -S . '.escapeshellarg(mailGatewayApplied($host, 'applied-outbound.json'))));

        // Public, root-owned (here: the test user), and never writable by others.
        foreach (['applied-plan.json', 'applied-outbound.json'] as $name) {
            expect(substr(sprintf('%o', fileperms(mailGatewayApplied($host, $name))), -3))->toBe('644');
            expect(posix_getpwuid(fileowner(mailGatewayApplied($host, $name)))['name'])->toBe($host['env']['RATEGURU_MAILGW_FILE_OWNER']);
        }

        // Nothing secret in either: no key, no credential, no environment.
        foreach ([$plan, $outbound] as $document) {
            expect($document)->not->toContain('PRIVATE KEY')->not->toContain('/etc/opendkim/keys');
            expect(preg_match('/password|passwd|secret|token|credential|private_?key|MAIL_|APP_KEY/i', $document))->toBe(0);
        }

        // A second apply records nothing new: the same bytes.
        [$again, $log] = mailGatewayRun($host, '--apply');
        expect($again)->toBe(0, $log);
        expect($log)->not->toContain('recording /var/lib/rateguru-mail-gateway/');
        expect(File::get(mailGatewayApplied($host)))->toBe($plan);
        expect(File::get(mailGatewayApplied($host, 'applied-outbound.json')))->toBe($outbound);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('fails --verify on a recorded policy field Postfix never renders, with Postfix itself untouched', function (string $name, string $path, mixed $value, string $field) {
    $host = mailGatewayEstablishedHost();

    try {
        mailGatewayTamperApplied($host, $name, [$path => $value]);

        [$status, $report] = mailGatewayRun($host, '--verify');

        expect($status)->toBe(1, $report);
        expect($report)
            ->toContain("DRIFT    file:/var/lib/rateguru-mail-gateway/{$name} — the recorded policy differs from the one this bundle requests ({$field})")
            // Not the subset Postfix renders: the rendered gateway, read back
            // and running, is exactly the current render.
            ->toContain('PASS     file:/etc/postfix/main.cf — matches the current render')
            ->toContain('PASS     file:/etc/postfix/master.cf — matches the current render')
            ->toContain('PASS     config:installed')
            ->toContain('PASS     runtime')
            ->toContain('SUMMARY  pass=11 missing=0 drift=1 conflict=0 deferred=0');
    } finally {
        mailGatewayCleanup($host);
    }
})->with([
    'default_from' => ['applied-plan.json', 'listeners.1.sender.default_from', 'hello@tits.guru', 'listeners.tits-guru.sender.default_from'],
    'bounce_domain' => ['applied-plan.json', 'listeners.1.sender.bounce_domain', 'bounces.tits.guru', 'listeners.tits-guru.sender.bounce_domain'],
    'reply_domain' => ['applied-plan.json', 'listeners.1.sender.reply_domain', 'replies.tits.guru', 'listeners.tits-guru.sender.reply_domain'],
    'lifecycle' => ['applied-plan.json', 'listeners.1.lifecycle', 'active', 'listeners.tits-guru.lifecycle'],
    'submission endpoint' => ['applied-plan.json', 'listeners.1.listen.port', 2599, 'listeners.tits-guru.listen.port'],
    'mail domain' => ['applied-plan.json', 'listeners.1.sender.allowed_domain', 'mail.tits.guru', 'listeners.tits-guru.sender.allowed_domain'],
    'another target' => ['applied-plan.json', 'listeners.0.route.port', 1026, 'listeners.staging-main.route.port'],
    'the host MTA identity' => ['applied-outbound.json', 'direct.mta_hostname', 'mta2.tits.guru', 'direct.mta_hostname'],
]);

it('sees a requested identity change the rendered Postfix never would, and records it when converged', function () {
    $host = mailGatewayEstablishedHost();

    try {
        $request = mailPreActivationPolicy();
        $request['routing']['targets']['tits-guru']['default_from'] = 'hello@tits.guru';
        $request['routing']['targets']['tits-guru']['reply_domain'] = 'replies.tits.guru';
        mailGatewayRequest($host, $request);

        // Byte for byte the same Postfix configuration.
        $before = mailGatewayRender();
        $after = mailGatewayRender($request['routing']);
        expect($after['main'])->toBe($before['main'])->and($after['master'])->toBe($before['master']);

        [$status, $report] = mailGatewayRun($host, '--verify');
        expect($status)->toBe(1, $report);
        expect($report)->toContain('DRIFT    file:/var/lib/rateguru-mail-gateway/applied-plan.json — the recorded policy differs from the one this bundle requests (listeners.tits-guru.sender.default_from, listeners.tits-guru.sender.reply_domain)');

        // Not a boundary crossing: an ordinary apply converges it.
        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);
        expect($log)->toContain('recording /var/lib/rateguru-mail-gateway/applied-plan.json')->not->toContain('installing /etc/postfix');

        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(0, $report);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('keeps the recorded policy with the configuration when an apply fails, and records nothing it did not prove', function () {
    $host = mailGatewayEstablishedHost();

    try {
        $recorded = [File::get(mailGatewayApplied($host)), File::get(mailGatewayApplied($host, 'applied-outbound.json'))];

        // A changed policy, and a capture destination that has gone away.
        $request = mailPreActivationPolicy();
        $request['routing']['targets']['staging-main']['submission']['port'] = 2527;
        mailGatewayRequest($host, $request);
        file_put_contents($host['scratch'].'/state/listeners', "\n");

        [$status, $output] = mailGatewayRun($host, '--apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain('rollback complete')->not->toContain('recording /var/lib/rateguru-mail-gateway/');
        expect([File::get(mailGatewayApplied($host)), File::get(mailGatewayApplied($host, 'applied-outbound.json'))])->toBe($recorded);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('records the applied policy inside the transaction: a rollback puts back the previous record, or removes a first one', function (bool $first) {
    $host = mailGatewayEstablishedHost();

    try {
        $previous = [File::get(mailGatewayApplied($host)), File::get(mailGatewayApplied($host, 'applied-outbound.json'))];
        if ($first) {
            unlink(mailGatewayApplied($host));
            unlink(mailGatewayApplied($host, 'applied-outbound.json'));
        }

        // A candidate policy that differs, recorded by the shipped function and
        // then rolled back by the shipped rollback.
        $candidates = $host['scratch'].'/candidates';
        @mkdir($candidates, 0o700, true);
        file_put_contents($candidates.'/applied-plan.json', "{\n  \"changed\": true\n}\n");
        file_put_contents($candidates.'/applied-outbound.json', "{\n  \"changed\": true\n}\n");

        $harness = 'set -Eeuo pipefail; source '.escapeshellarg(mailGatewayScript())
            .'; APPLIED_CANDIDATE_DIR='.escapeshellarg($candidates)
            .'; BACKUP_DIR='.escapeshellarg($host['fs'].'/var/backups/rateguru-mail-gateway/test')
            .'; install -d -m 0700 "${BACKUP_DIR}"'
            .'; UNIT_ENABLED_BEFORE=enabled; INSTANCE_ACTIVE_BEFORE=active'
            .'; install_applied_policy; cat '.escapeshellarg(mailGatewayApplied($host)).'; rollback';

        $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $host['scratch'], $host['env']);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        expect(proc_close($process))->toBe(0, $output);

        // Recorded, then put back exactly.
        expect($output)->toContain('recording /var/lib/rateguru-mail-gateway/applied-plan.json')->toContain('"changed": true')->toContain('rollback complete');

        if ($first) {
            expect(file_exists(mailGatewayApplied($host)))->toBeFalse();
            expect(file_exists(mailGatewayApplied($host, 'applied-outbound.json')))->toBeFalse();
        } else {
            expect([File::get(mailGatewayApplied($host)), File::get(mailGatewayApplied($host, 'applied-outbound.json'))])->toBe($previous);
        }
    } finally {
        mailGatewayCleanup($host);
    }
})->with(['a replaced record' => [false], 'a first record' => [true]]);

// =============================================================================
// A HOST WITH NO RECORDED POLICY RECORDS AN INERT ONE FIRST
// =============================================================================

it('establishes the first recorded policy on an existing gateway from the inert configuration', function () {
    $host = mailGatewayEstablishedHost();

    try {
        // The host as it was before this policy was recorded.
        unlink(mailGatewayApplied($host));
        unlink(mailGatewayApplied($host, 'applied-outbound.json'));

        [$checked, $report] = mailGatewayRun($host, '--check');
        expect($checked)->toBe(0, $report);
        expect($report)->toContain('MISSING  policy:applied — no applied policy is recorded on this host — --apply records this inert one (no outbound route, direct delivery disabled) once the gateway is proved');

        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(1, $report);

        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(0, $log);
        expect($log)->toContain('recording /var/lib/rateguru-mail-gateway/applied-plan.json')->toContain('recording /var/lib/rateguru-mail-gateway/applied-outbound.json');

        [$verified, $report] = mailGatewayRun($host, '--verify');
        expect($verified)->toBe(0, $report);
    } finally {
        mailGatewayCleanup($host);
    }
});

it('never records a first policy that already routes mail outbound or enables direct delivery', function (bool $existing) {
    $host = $existing ? mailGatewayEstablishedHost() : mailGatewayHost(['policy' => mailActivationRequest()['routing'], 'outbound' => mailActivationRequest()['outbound']]);

    try {
        if ($existing) {
            unlink(mailGatewayApplied($host));
            unlink(mailGatewayApplied($host, 'applied-outbound.json'));
            mailGatewayRequest($host, mailActivationRequest());
        }

        file_put_contents($host['scratch'].'/log/mutations.log', '');
        $before = mailGatewayTree($host);

        [$checked, $report] = mailGatewayRun($host, '--check');
        expect($checked)->toBe(1, $report);
        expect($report)->toContain("CONFLICT policy:applied — no applied policy is recorded on this host, and this bundle routes mail outbound or enables direct delivery — a host's first recorded policy is always the inert one");

        [$applied, $log] = mailGatewayRun($host, '--apply');
        expect($applied)->toBe(1, $log);
        expect($log)->toContain("a host's first recorded policy is always the inert one. Establish it with the held, direct-disabled configuration first; crossing to outbound is activate-mail-outbound's alone. Nothing was changed");

        // Not a file, not a package, not a service.
        expect(mailGatewayTree($host))->toBe($before);
        expect(mailGatewayLog($host, 'mutations.log'))->toBe('');
    } finally {
        mailGatewayCleanup($host);
    }
})->with(['on an existing gateway' => [true], 'on a host with no Postfix' => [false]]);
