<?php

/**
 * activate-mail-inbound --health: the read-only check a schedule runs —
 * whether the store takes mail right now, and whether mail the host already
 * accepted waits in the shared queue instead of reaching the store, for how
 * long, and whether it is at risk of being given up.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost). Its queue holds what mailInboundQueued()
 * puts there, each message with the envelope Postfix records for it: the
 * direction is read from that envelope, never from the recipient.
 */

/** @return array{0: int, 1: string, 2: ?array} */
function mailInboundHealthRun(array $host): array
{
    [$status, $output] = mailInboundHostRun($host, 'activate-mail-inbound', ['--health', '--target', 'tits-guru']);

    return [$status, $output, mailInboundResult($output)];
}

it('has nothing to check on a host without inbound mail', function () {
    $host = mailInboundHost();

    try {
        [$status, $output, $result] = mailInboundHealthRun($host);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('INBOUND HEALTH: PASS — nothing to check');
        expect($result)->toMatchArray(['mode' => 'health', 'status' => 'pass', 'state' => 'not-installed', 'changed' => false, 'queue' => null]);
    } finally {
        mailInboundCleanup($host);
    }
});

it('passes while the store takes mail and no accepted inbound mail waits, whatever outbound mail does', function (string $installed) {
    $host = mailInboundHost(['installed' => $installed]);

    try {
        mailInboundQueued($host, [
            // On its way: accepted a minute ago, being delivered.
            ['id' => 'INFLIGHT', 'queue' => 'active', 'age' => 60],
            // The application's messages to the same domains, deferred by
            // their own route for days: never inbound mail.
            ['id' => 'OUT1', 'via' => 'submission', 'reason' => 'Host or domain name not found', 'age' => 200000],
            ['id' => 'OUT2', 'via' => 'submission', 'to' => 'postmaster@tits.guru', 'queue' => 'hold', 'age' => 400000],
            // A notice Postfix wrote itself.
            ['id' => 'LOCAL1', 'via' => 'local', 'to' => 'postmaster@rateguru-mail-inbound.invalid', 'reason' => 'mail system configuration error', 'age' => 300000],
            // Outbound mail to anywhere else is not even read.
            ['id' => 'OUT3', 'via' => 'submission', 'to' => 'someone@example.net', 'reason' => 'connect timed out', 'age' => 300000],
        ]);

        [$status, $output, $result] = mailInboundHealthRun($host);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain('PASS the inbound store verifies')
            ->toContain('PASS the store gate admits new inbound mail: the store is mounted, writable and above its reserve of space and inodes')
            ->toContain('PASS no accepted inbound mail waits in the shared queue (1 on its way to the store, the oldest for 1m)')
            ->toContain('INBOUND HEALTH: PASS');
        expect($result)->toMatchArray([
            'status' => 'pass', 'state' => "installed-{$installed}",
            'queue' => ['inbound' => 1, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => $result['queue']['oldest_seconds'], 'queues' => ['active' => 1]],
        ]);

        // Only envelopes of mail for the inbound domains were read, and only the envelope.
        $reads = mailInboundLog($host, 'reads.log');
        expect($reads)->toContain('postcat -e -q INFLIGHT')->toContain('postcat -e -q OUT1')->not->toContain('OUT3');
        preg_match_all('/^postcat (.*)$/m', $reads, $calls);
        expect($calls[1])->each->toStartWith('-e -q ');
    } finally {
        mailInboundCleanup($host);
    }
})->with(['disabled', 'enabled']);

it('fails for accepted inbound mail that waits, whatever Postfix gave as the reason', function (array $message, string $queues) {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundQueued($host, [$message]);

        [$status, $output, $result] = mailInboundHealthRun($host);

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain("FAIL 1 accepted inbound message(s) wait in the shared queue instead of reaching the store ({$queues})")
            ->toContain('INBOUND HEALTH: FAILED')
            ->not->toContain('AT RISK');
        expect($result)->toMatchArray(['status' => 'fail', 'queue' => ['inbound' => 1, 'waiting' => 1, 'at_risk' => 0, 'oldest_seconds' => $result['queue']['oldest_seconds'], 'queues' => $result['queue']['queues']]]);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'the Maildir could not be written' => [['id' => 'IN1', 'reason' => 'maildir delivery failed: create maildir file: Permission denied', 'age' => 600], 'deferred 1'],
    'a configuration error' => [['id' => 'IN1', 'reason' => 'mail system configuration error', 'age' => 600], 'deferred 1'],
    'a lookup that failed' => [['id' => 'IN1', 'reason' => 'temporary failure. Command output: lookup error', 'age' => 600], 'deferred 1'],
    'no reason at all' => [['id' => 'IN1', 'age' => 600], 'deferred 1'],
    'accepted on the public listener' => [['id' => 'IN1', 'via' => 'public', 'reason' => 'mailbox full', 'age' => 600], 'deferred 1'],
    'held' => [['id' => 'IN1', 'queue' => 'hold', 'age' => 60], 'hold 1'],
    'active for longer than it takes to store a message' => [['id' => 'IN1', 'queue' => 'active', 'age' => 1800], 'active 1'],
    'for the host postmaster' => [['id' => 'IN1', 'to' => 'postmaster@rateguru-mail-inbound.invalid', 'reason' => 'maildir delivery failed', 'age' => 600], 'deferred 1'],
]);

it('reports accepted inbound mail older than a day AT RISK OF LOSS, with the count and how long the oldest has left', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundQueued($host, [
            ['id' => 'YOUNG', 'reason' => 'maildir delivery failed', 'age' => 7200],
            ['id' => 'OLD1', 'reason' => 'maildir delivery failed', 'age' => 90000],
            ['id' => 'OLD2', 'via' => 'public', 'to' => 'b-01hzx3k9q2w8e7r6t5y4v3p2m1@bounce.tx.tits.guru', 'age' => 30 * 3600 + 90],
        ]);

        [$status, $output, $result] = mailInboundHealthRun($host);

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain('FAIL 3 accepted inbound message(s) wait in the shared queue instead of reaching the store (deferred 3); the oldest was accepted 1d 6h ago')
            ->toContain('FAIL AT RISK OF LOSS: 2 inbound message(s) accepted more than 1d 0h ago are still not stored. Postfix gives a message up 5d 0h after accepting it (maximal_queue_lifetime), and an inbound message given up is lost: the oldest has 3d 1');
        expect($result['queue'])->toMatchArray(['inbound' => 3, 'waiting' => 3, 'at_risk' => 2]);
        expect($result['queue']['oldest_seconds'])->toBeGreaterThanOrEqual(30 * 3600 + 90);

        // The lifetime is Postfix's own setting, read rather than assumed.
        file_put_contents($host['state'].'/queue-lifetime', "2d\n");
        [, $output] = mailInboundHealthRun($host);
        // Seconds pass between queueing and the scan, so the minutes may be one or two fewer.
        expect($output)->toMatch('/Postfix gives a message up 2d 0h after accepting it \(maximal_queue_lifetime\), and an inbound message given up is lost: the oldest has 17h 5\dm left/');
    } finally {
        mailInboundCleanup($host);
    }
});

it('never prints an address, a sender or anything of a message', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundQueued($host, [['id' => 'IN1', 'to' => 'support@tits.guru', 'reason' => 'maildir delivery failed: create maildir file /var/lib/rateguru-mail-inbound/store/targets/tits-guru/support/tmp/x: Permission denied', 'age' => 90000]]);

        [$status, $output] = mailInboundHealthRun($host);

        expect($status)->toBe(1);
        foreach (['support@', 'someone@sender.example', 'sender.example', 'Permission denied', 'targets/tits-guru/support/tmp'] as $private) {
            expect($output)->not->toContain($private);
        }
    } finally {
        mailInboundCleanup($host);
    }
});

it('fails, without guessing, when the queue or an envelope still in it cannot be read', function (string $toggle, string $reason) {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundQueued($host, [['id' => 'IN1', 'queue' => 'active', 'age' => 30]]);
        touch($host['toggles'].'/'.$toggle);

        [$status, $output, $result] = mailInboundHealthRun($host);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($reason)->toContain('INBOUND HEALTH: FAILED');
        expect($result)->toMatchArray(['status' => 'fail', 'queue' => null]);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'the queue' => ['postqueue-fail', 'FAIL the shared queue cannot be read (postqueue -j, postcat -e) — whether accepted inbound mail waits in it cannot be proved'],
    'an envelope' => ['postcat-fail', 'FAIL the shared queue cannot be read (postqueue -j, postcat -e) — whether accepted inbound mail waits in it cannot be proved'],
]);

it('fails while the store cannot take mail: not mounted, mounted read-only, or full', function (Closure $break, array $reasons) {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        $break($host);

        [$status, $output, $result] = mailInboundHealthRun($host);

        expect($status)->toBe(1, $output);
        foreach ($reasons as $reason) {
            expect($output)->toContain($reason);
        }
        expect($output)->toContain('INBOUND HEALTH: FAILED');
        expect($result)->toMatchArray(['status' => 'fail', 'queue' => ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []]]);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'the volume is not mounted' => [function (array $host): void {
        unlink($host['state'].'/var-lib-rateguru\x2dmail\x2dinbound-store.mount.active');
        file_put_contents($host['state'].'/mountinfo', '');
    }, [
        'FAIL the inbound store does not verify',
        'the store volume is not mounted at /var/lib/rateguru-mail-inbound/store',
        'FAIL the store gate refuses new inbound mail now (451 4.3.0 The inbound mail store is temporarily unavailable, try again later)',
    ]],
    'the volume is mounted read-only' => [function (array $host): void {
        file_put_contents($host['state'].'/mountinfo', str_replace(' rw,nosuid', ' ro,nosuid', (string) file_get_contents($host['state'].'/mountinfo')));
    }, [
        'FAIL the store gate refuses new inbound mail now (451 4.3.0 The inbound mail store is temporarily unavailable, try again later)',
    ]],
    'the volume is below its reserve' => [function (array $host): void {
        file_put_contents($host['state'].'/store-free', '1000');
    }, [
        'FAIL the inbound store does not verify',
        'the store has 1000 bytes free, below its reserve of 104857600',
    ]],
    'the gate is gone' => [function (array $host): void {
        unlink($host['fs'].'/etc/postfix/rateguru-inbound-store-gate');
    }, [
        'FAIL the store gate /etc/postfix/rateguru-inbound-store-gate is not installed — the inbound listeners refuse every recipient',
    ]],
]);

it('fails while the shared queue is below the reserve the inbound listeners refuse new mail under', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        file_put_contents($host['state'].'/free', '1073741824');

        [$status, $output] = mailInboundHealthRun($host);

        expect($status)->toBe(1, $output);
        expect($output)->toContain("FAIL the queue's file system has 1073741824 bytes free, below the 2147483648 the inbound listeners refuse new mail under");
    } finally {
        mailInboundCleanup($host);
    }
});

it('changes nothing and takes no lock, so a schedule may run it at any time', function () {
    $host = mailInboundHost(['installed' => 'enabled']);

    try {
        mailInboundQueued($host, [['id' => 'IN1', 'reason' => 'maildir delivery failed', 'age' => 90000]]);
        $before = [mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host), (string) file_get_contents($host['state'].'/queue')];

        // Another operation holds the host's infrastructure lock meanwhile.
        $lock = fopen($host['scratch'].'/run/host-infrastructure.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            [$status, $output] = mailInboundHealthRun($host);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        expect($status)->toBe(1, $output);
        expect($output)->toContain('AT RISK OF LOSS')->not->toContain('another operation');
        expect(mailInboundLog($host, 'mutations.log'))->toBe('');
        expect([mailInboundFile($host, '/etc/postfix/main.cf'), mailInboundFile($host, '/etc/postfix/master.cf'), mailInboundApplied($host), (string) file_get_contents($host['state'].'/queue')])->toBe($before);

        $code = executableSourceLines(File::get(base_path('infrastructure/scripts/activate-mail-inbound')));
        preg_match('/^run_health\(\) \{\n(.*?)\n\}$/ms', $code, $body);
        expect($body[1])->not->toContain('acquire_host_lock')->not->toContain('gateway_apply')->not->toContain('transition')->not->toContain('open_firewall');
    } finally {
        mailInboundCleanup($host);
    }
});
