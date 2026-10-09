<?php

use Illuminate\Support\Facades\File;

/**
 * activate-mail-inbound: what asks for the guarded opening of public SMTP on
 * the host's inbound receiver, the proof it requires before anything public
 * changes, and the script's own interface. The opening itself, a failure after
 * it, the rollback and the verification each have a file of their own:
 * MailInboundActivation{Proof,Apply,Failure,Rollback,Verify,Drift}Test.
 *
 * Every test runs the REAL activation from a trusted bundle (mailInboundBundle)
 * — with the real installer, mail-inbound, routing, identity and registry tools
 * — against a simulated host (mailInboundHost). The receiver's live proof talks
 * SMTP to a fake receiver that answers from the configuration the real
 * installer rendered on that host, so a proof that passes has judged the real
 * render against `mail-inbound route`.
 */

// =============================================================================
// THE REQUEST, AND THE PROOF BEFORE ANYTHING PUBLIC CHANGES
// =============================================================================

it('activates nothing the trusted bundle does not request', function (string $mode) {
    $contract = json_decode(File::get(base_path('infrastructure/config/mail-inbound.json')), true);
    $contract['receiver']['public_smtp'] = 'disabled';
    $setup = mailInboundActivationHost(inbound: $contract);

    try {
        $before = mailInboundTree($setup['host']);
        [$status, $output, $result] = mailInboundActivationRun($setup, $mode);

        expect($status)->not->toBe(0);
        expect($output)->toContain('activation is not requested by this trusted bundle: its mail-inbound.json says receiver.public_smtp is disabled. Nothing was changed');
        expect($result)->toMatchArray(['status' => 'fail', 'requested' => false, 'changed' => false]);
        expect(mailInboundTree($setup['host']))->toBe($before);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with(['check', 'apply']);

it('refuses a target the inbound plan does not receive for, or that is not production', function (string $target, string $reason) {
    $setup = mailInboundActivationHost();

    try {
        [$status, $output] = mailInboundActivationRun($setup, 'apply', $target);

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'staging' => ['staging-main', 'staging-main is not a production target the trusted bundle\'s inbound plan receives mail for'],
    'unknown' => ['demo-shop', 'demo-shop is not a production target the trusted bundle\'s inbound plan receives mail for'],
]);

it('says the host is ready, and changes nothing while it says so', function () {
    $setup = mailInboundActivationHost();

    try {
        $before = mailInboundTree($setup['host']);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'check');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION CHECK: READY — --apply would install or converge the receiver, prove it live, and open TCP 25 to 1.2.3.4');
        expect($result)->toMatchArray(['status' => 'pass', 'requested' => true, 'changed' => false, 'public_smtp' => 'disabled', 'firewall' => 'none', 'address' => '1.2.3.4']);
        expect(mailInboundTree($setup['host']))->toBe($before);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('refuses before anything public changes when a precondition does not hold', function (array $options, array $toggles, string $reason) {
    $setup = mailInboundActivationHost($options);

    try {
        foreach ($toggles as $toggle) {
            touch($setup['host']['scratch'].'/toggles/'.$toggle);
        }

        [$status, $output, $result] = mailInboundActivationRun($setup, 'apply');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason)->toContain('the proof before activation failed');
        expect($result)->toMatchArray(['status' => 'fail', 'rolled_back' => false, 'public_smtp' => 'disabled']);
        expect(mailInboundPort25Closed($setup))->toBeTrue();
        expect(mailInboundLog($setup['host'], 'mutations.log'))->not->toContain('ufw')->not->toContain('systemctl');
        expect(file_exists($setup['host']['fs'].'/var/lib/rateguru-mail-inbound-activation/tits-guru/capsule.json'))->toBeFalse();
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'no certificate' => [['tls' => false], [], 'no usable TLS certificate'],
    'an untrusted certificate' => [[], ['tls-untrusted'], 'no usable TLS certificate'],
    'a gateway that does not verify' => [[], ['gateway-verify-fail'], 'the outbound mail gateway does not verify'],
    'a private address only' => [['address' => '10.0.0.7'], [], 'this host has no globally reachable IPv4 address'],
    'a documentation address' => [['address' => '203.0.113.25'], [], 'this host has no globally reachable IPv4 address'],
    'a foreign process on 25' => [['listeners' => ['0.0.0.0:25 5000 exim4.service']], [], 'something listens on 0.0.0.0:25'],
    'something on 587' => [['listeners' => ['0.0.0.0:587 5000 dovecot.service']], [], 'no SMTP service may ever listen on port 587'],
    'a firewall not ufw' => [['filter' => "table inet filter {\n chain input {\n  type filter hook input priority 0; policy drop;\n }\n}\n"], [], 'the host filters inbound traffic with rules that are not ufw\'s'],
    'iptables dropping' => [['filter' => ":INPUT ACCEPT [0:0]\n-A INPUT -p tcp --dport 22 -j DROP\n"], [], 'not ufw\'s'],
    'a ufw rule of somebody else for 25' => [['ufw' => 'active', 'ufwRules' => "25/tcp                     ALLOW       Anywhere\n"], [], 'a ufw rule RateGuru did not write already allows TCP 25'],
    'no room for the store' => [['free' => 1000], [], 'the receiver cannot be installed or converged on this host'],
]);

// =============================================================================
// THE SCRIPT ITSELF
// =============================================================================

it('handles its arguments strictly, and refuses to run unprivileged', function (array $arguments, string $reason) {
    $setup = mailInboundActivationHost();

    try {
        [$status, $output] = mailInboundActivate($setup['host'], $setup['bundle'], $arguments);

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'no mode' => [['--target', 'tits-guru'], 'a mode is required'],
    'two modes' => [['--apply', '--verify', '--target', 'tits-guru'], 'mode given more than once'],
    'no target' => [['--apply'], '--apply requires --target'],
    'a target that is not an ID' => [['--apply', '--target', '../tits-guru'], 'invalid target ID'],
    'an address from the caller' => [['--apply', '--target', 'tits-guru', '--ipv4', '1.2.3.4'], 'unknown argument: --ipv4'],
]);

it('never publishes DNS, reads a message, or touches anything of the outbound path', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/activate-mail-inbound')));

    foreach (['curl', 'wget', 'nsupdate', 'apt-get', 'postsuper', 'postqueue', 'opendkim', 'postfix@-', 'cat "${file}"', 'install-mail-gateway --apply'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("activate-mail-inbound runs or names {$forbidden}");
    }

    // The firewall: only RateGuru's own TCP 25 rule, added and deleted.
    preg_match_all('/"\$\{UFW_BIN\}" (allow|delete)[^\n]*/', $code, $calls);
    expect($calls[0])->toHaveCount(2);
    foreach ($calls[0] as $call) {
        expect(preg_match('/proto tcp to "\$\{(ADDRESS|address)\}" port 25 comment "\$\{FIREWALL_COMMENT\}"/', $call))->toBe(1, $call);
        expect($call)->not->toContain('22')->not->toContain('80')->not->toContain('443');
    }
});
