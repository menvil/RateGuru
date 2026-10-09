<?php

/**
 * activate-mail-inbound --rollback: public SMTP closed — the gateway's disable
 * transition, by a reload, and RateGuru's own firewall rule removed, and only
 * it. The inbound loopback listener and the delivery stay, so mail already
 * accepted is still delivered from the shared queue; stored mail is kept, the
 * queue is never touched and the outbound path is exactly what it was.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost).
 */
it('closes public SMTP: its own firewall rule and only it, by a reload, stored mail and the queue kept', function () {
    $setup = mailInboundActivationHost(['installed' => 'enabled', 'ufw' => 'active', 'ufwRules' => "22/tcp                     ALLOW       Anywhere\n443/tcp                    ALLOW       Anywhere\n"]);

    try {
        $host = $setup['host'];
        $dir = $host['fs'].'/var/lib/rateguru-mail-inbound/store/targets/tits-guru/support/new';
        @mkdir($dir, 0o700, true);
        file_put_contents($dir.'/1.message', "Subject: kept\n\nbody\n");
        file_put_contents($host['state'].'/queue', '{"queue_name":"deferred","queue_id":"OUT1","recipients":[{"address":"someone@example.net","delay_reason":"connect timed out"}]}'."\n");
        $queue = (string) file_get_contents($host['state'].'/queue');
        $outbound = mailInboundOutboundConfiguration($host);
        file_put_contents($host['log'].'/mutations.log', '');

        [$status, $output, $result] = mailInboundActivationRun($setup, 'rollback');

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('ROLLED BACK: public SMTP is closed, nothing listens on TCP 25; outbound mail, the shared queue and every stored message are as they were')
            ->toContain('NEXT (operator): check the published MX records of every target in the plan');
        expect($result)->toMatchArray(['status' => 'pass', 'rolled_back' => true, 'state' => 'installed-disabled', 'public_smtp' => 'disabled']);

        expect(mailInboundApplied($host)['public_smtp'])->toBe('disabled');
        expect(mailInboundPort25Closed($setup))->toBeTrue();
        $services = mailInboundServices(mailInboundFile($host, '/etc/postfix/master.cf'));
        expect($services)->toHaveKey('127.0.0.1:2580/inet')->toHaveKey('virtual/unix');

        $rules = (string) file_get_contents($host['state'].'/ufw-rules');
        expect($rules)->not->toContain('rateguru-mail-inbound')->toContain('22/tcp')->toContain('443/tcp');

        expect(file_exists($dir.'/1.message'))->toBeTrue();
        expect((string) file_get_contents($host['state'].'/queue'))->toBe($queue);
        expect(mailInboundOutboundConfiguration($host))->toBe($outbound);
        expect(mailInboundLog($host, 'mutations.log'))
            ->toContain('systemctl reload postfix@-.service')
            ->not->toContain('systemctl stop')->not->toContain('systemctl restart');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('rolls back whatever the bundle requests, and does nothing when public SMTP is already closed', function () {
    $setup = mailInboundActivationHost(['installed' => 'disabled']);

    try {
        file_put_contents($setup['host']['bundle'].'/infrastructure/config/mail-inbound.json', mailRoutingJson(mailInboundContractWith(['receiver.public_smtp' => 'disabled'])));
        file_put_contents($setup['host']['log'].'/mutations.log', '');
        [$status, $output, $result] = mailInboundActivationRun($setup, 'rollback');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ROLLBACK: NOTHING TO DO — public SMTP is already closed');
        expect($result)->toMatchArray(['state' => 'installed-disabled', 'changed' => false]);
        expect(mailInboundLog($setup['host'], 'mutations.log'))->toBe('');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('never closes a TCP 25 that is not RateGuru\'s', function () {
    $setup = mailInboundActivationHost();

    try {
        @mkdir($setup['host']['fs'].'/proc/5000', 0o755, true);
        file_put_contents($setup['host']['fs'].'/proc/5000/cgroup', "0::/system.slice/exim4.service\n");
        file_put_contents($setup['host']['state'].'/listeners', "0.0.0.0:25 users:((\"exim4\",pid=5000,fd=3))\n", FILE_APPEND);

        [$status, $output] = mailInboundActivationRun($setup, 'rollback');
        expect($status)->not->toBe(0);
        expect($output)->toContain('inbound mail is not installed — it is not RateGuru\'s to close; nothing was changed');
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
