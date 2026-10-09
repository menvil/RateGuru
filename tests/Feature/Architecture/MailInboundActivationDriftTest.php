<?php

/**
 * activate-mail-inbound --verify: an enabled host that is not what it should
 * be is drift, by name, whatever changed.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost).
 */
it('reports drift when the enabled host is not what it should be', function (string $change, string $reason) {
    $setup = mailInboundActivationHost(['installed' => 'enabled', 'ufw' => 'active']);

    try {
        $host = $setup['host'];
        match ($change) {
            'rule' => file_put_contents($host['state'].'/ufw-rules', ''),
            'foreign' => (function () use ($host): void {
                @mkdir($host['fs'].'/proc/5000', 0o755, true);
                file_put_contents($host['fs'].'/proc/5000/cgroup', "0::/system.slice/dovecot.service\n");
                file_put_contents($host['state'].'/listeners', "0.0.0.0:587 users:((\"dovecot\",pid=5000,fd=3))\n", FILE_APPEND);
            })(),
            'applied' => file_put_contents($host['fs'].'/var/lib/rateguru-mail-gateway/applied-inbound.json', '{"public_smtp":"enabled"'),
            'address' => touch($host['toggles'].'/address-gone'),
            'unmounted' => unlink($host['state'].'/var-lib-rateguru\x2dmail\x2dinbound-store.mount.active'),
        };

        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');

        expect($status)->not->toBe(0);
        expect($output)->toContain($reason);
        expect($result)->toMatchArray(['status' => 'fail', 'state' => 'drift']);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'its firewall rule removed by hand' => ['rule', "public SMTP is enabled on 1.2.3.4, but RateGuru's ufw rule for it is missing"],
    'another SMTP listener' => ['foreign', 'no SMTP service may ever listen on port 587'],
    'its recorded state corrupted' => ['applied', "the gateway's recorded inbound state is corrupt"],
    'its address gone from the host' => ['address', 'the recorded public address 1.2.3.4 is not configured on this host — Postfix still runs, but public SMTP receives nothing until it is back'],
    'its store not mounted' => ['unmounted', 'the inbound store does not verify'],
]);
