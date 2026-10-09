<?php

/**
 * activate-mail-inbound: what asks for the guarded opening of public inbound
 * SMTP on the host's one Postfix, the proof it requires before anything
 * changes, and the script's own interface. The opening itself, a failure after
 * it, the rollback and the verification each have a file of their own:
 * MailInboundActivation{Proof,Apply,Failure,Rollback,Verify,Drift}Test.
 *
 * Every test runs the REAL activation from a trusted bundle — with the real
 * gateway installer, store installer, mail-inbound, routing, identity and
 * registry tools — against the simulated host (mailInboundHost). Its probes
 * talk SMTP to a fake listener that answers from the configuration the real
 * gateway rendered, so a proof that passes has judged the real render against
 * `mail-inbound route`.
 */

/** Every file under the simulated host, so a mode that must not mutate can be proved not to have. */
function mailInboundActivationTree(array $setup): array
{
    $tree = [];
    foreach (File::allFiles($setup['host']['fs'], true) as $file) {
        $tree[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
    }
    ksort($tree);

    return $tree;
}

it('activates nothing the trusted bundle does not request', function (string $mode) {
    $setup = mailInboundActivationHost(['inbound' => mailInboundContractWith(['receiver.public_smtp' => 'disabled'])]);

    try {
        $before = mailInboundActivationTree($setup);
        [$status, $output, $result] = mailInboundActivationRun($setup, $mode);

        expect($status)->not->toBe(0);
        expect($output)->toContain('activation is not requested by this trusted bundle: its mail-inbound.json says receiver.public_smtp is disabled. Nothing was changed');
        expect($result)->toMatchArray(['status' => 'fail', 'requested' => false, 'changed' => false]);
        expect(mailInboundActivationTree($setup))->toBe($before);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with(['check', 'apply']);

it('refuses a target the inbound plan does not receive for, or that is not production', function (string $target) {
    $setup = mailInboundActivationHost();

    try {
        [$status, $output] = mailInboundActivationRun($setup, 'apply', $target);
        expect($status)->not->toBe(0);
        expect($output)->toContain("{$target} is not a production target the trusted bundle's inbound plan receives mail for — nothing was changed");
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with(['staging-main', 'demo-shop']);

it('says the host is ready, and changes nothing while it says so', function () {
    $setup = mailInboundActivationHost();

    try {
        $before = mailInboundActivationTree($setup);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'check');

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain("PASS this host's public IPv4 address is 1.2.3.4, globally reachable")
            ->toContain('PASS a valid certificate covers every MX host name')
            ->toContain('PASS the mail gateway verifies')
            ->toContain('PASS nothing listens on a public SMTP port that may not')
            ->toContain('PASS the inbound store can be created or converged')
            ->toContain("PASS the shared queue's file system keeps its reserve free")
            ->toContain('ACTIVATION CHECK: READY — --apply would install inbound mail if needed, prove it on loopback, and open TCP 25 to 1.2.3.4 by a reload');
        expect($result)->toMatchArray(['mode' => 'check', 'status' => 'pass', 'state' => 'not-installed', 'changed' => false, 'address' => '1.2.3.4']);
        expect(mailInboundActivationTree($setup))->toBe($before);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('refuses before anything changes when a precondition does not hold', function (array $options, array $prepare, string $reason) {
    $setup = mailInboundActivationHost($options);

    try {
        // A name ending in ! replaces the file; any other is appended to.
        foreach ($prepare as $file => $content) {
            $replace = str_ends_with($file, '!');
            $file = rtrim($file, '!');
            $path = str_starts_with($file, 'fs/') ? $setup['host']['fs'].substr($file, 2) : $setup['host']['state'].'/'.$file;
            @mkdir(dirname($path), 0o755, true);
            file_put_contents($path, $content, $replace ? 0 : FILE_APPEND);
        }
        $before = mailInboundActivationTree($setup);

        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('the proof before activation failed');
        expect($result)->toMatchArray(['status' => 'fail', 'changed' => false]);
        expect(mailInboundActivationTree($setup))->toBe($before);
        expect(mailInboundApplied($setup['host']))->toBeNull();
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'no address on the route to the Internet' => [['address' => ''], [], 'this host has no globally reachable IPv4 address on its route to the Internet'],
    'a private address' => [['address' => '10.0.0.5'], [], 'this host has no globally reachable IPv4 address'],
    'no certificate' => [['tls' => false], [], 'no usable TLS certificate — install it as infrastructure/runbooks/mail-inbound.md describes, before activating'],
    'the gateway does not verify: its capture destination is gone' => [[], ['listeners!' => "127.0.0.1:8891\n"], 'FAIL the mail gateway does not verify'],
    'something else on 25' => [[], ['listeners' => "0.0.0.0:25 users:((\"exim4\",pid=5000,fd=3))\n", 'fs/proc/5000/cgroup' => "0::/system.slice/exim4.service\n"], 'something listens on 0.0.0.0:25'],
    'a firewall RateGuru does not manage' => [['filter' => "chain input {\n  type filter hook input priority 0; policy drop;\n}\n"], [], 'the host filters inbound traffic with rules that are not ufw'],
    'a ufw rule of someone else for 25' => [['ufw' => 'active', 'ufwRules' => "25/tcp                     ALLOW       Anywhere\n"], [], 'a ufw rule RateGuru did not write already allows TCP 25'],
    'no room for the store' => [['free' => 2147483648], [], 'the inbound store cannot be created or converged on this host'],
    'no reserve on the queue file system' => [['free' => 1073741824], [], "the queue's file system has 1073741824 bytes free, below the 2147483648 the inbound listeners refuse new mail under"],
]);

it('handles its arguments strictly, and refuses to run unprivileged', function (array $arguments, array $env, string $reason) {
    $host = mailInboundHost();

    try {
        [$status, $output] = mailInboundHostRun($host, 'activate-mail-inbound', $arguments, $env);
        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'no mode' => [['--target', 'tits-guru'], [], 'a mode is required: --check, --apply, --verify or --rollback'],
    'no target' => [['--apply'], [], '--apply requires --target'],
    'two modes' => [['--apply', '--verify', '--target', 'tits-guru'], [], 'mode given more than once'],
    'an invalid target' => [['--apply', '--target', '../tits-guru'], [], 'invalid target ID: ../tits-guru'],
    'an override' => [['--apply', '--target', 'tits-guru', '--address', '1.2.3.4'], [], 'unknown argument: --address'],
    'unprivileged' => [['--verify', '--target', 'tits-guru'], ['RATEGURU_MAILINBOUNDACTIVATE_EUID' => '1000'], 'activate-mail-inbound --verify must run as root'],
]);

it('never publishes DNS, reads a message, stops Postfix or touches its queue', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/activate-mail-inbound')));

    foreach (['nsupdate', 'curl', 'wget', 'postsuper', 'postcat', 'sendmail', 'systemctl', 'postfix stop', 'postfix -c', '/etc/postfix/main.cf"', 'cat "${file}"'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("activate-mail-inbound uses {$forbidden}");
    }

    // Postfix is changed only by the gateway's own installer, through a one-use authorization.
    expect($code)
        ->toContain('run_child "install-mail-gateway --apply ($1)" "$(bundle_script install-mail-gateway)" --apply')
        ->toContain('path="$(physical "${MAIL_INBOUND_AUTHORIZATION}")"');

    // The probe message is found by its own token and removed; nothing else is read.
    expect($code)->toContain('grep -rlF -- "X-RateGuru-Inbound-Proof: ${token}"');
});
