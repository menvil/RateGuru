<?php

/**
 * activate-mail-inbound --verify: which state the host is in — not installed,
 * installed and disabled, enabled and verified, or drift — read-only, and
 * whether inbound mail waits in the shared queue instead of reaching its
 * store.
 *
 * Every test runs the REAL activation from a trusted bundle against the
 * simulated host (mailInboundHost).
 */
it('tells the states apart: not installed, installed and disabled, enabled and verified', function (string $installed, string $state, string $public) {
    $setup = mailInboundActivationHost(['installed' => $installed, 'ufw' => 'active']);

    try {
        $before = mailInboundFile($setup['host'], '/etc/postfix/master.cf');
        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');

        expect($status)->toBe(0, $output);
        expect($output)->toContain("STATE: {$state}")->toContain('INBOUND VERIFY: PASS');
        expect($result)->toMatchArray(['mode' => 'verify', 'status' => 'pass', 'state' => $state, 'public_smtp' => $public, 'changed' => false]);
        expect(mailInboundFile($setup['host'], '/etc/postfix/master.cf'))->toBe($before);
    } finally {
        mailInboundActivationCleanup($setup);
    }
})->with([
    'not installed' => ['absent', 'not-installed', 'disabled'],
    'installed, disabled' => ['disabled', 'installed-disabled', 'disabled'],
    'enabled, verified' => ['enabled', 'enabled-verified', 'enabled'],
]);

it('reports drift when the bundle no longer requests what the host has open', function () {
    $setup = mailInboundActivationHost(['installed' => 'enabled', 'ufw' => 'active']);

    try {
        file_put_contents($setup['host']['bundle'].'/infrastructure/config/mail-inbound.json', mailRoutingJson(mailInboundContractWith(['receiver.public_smtp' => 'disabled'])));
        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');

        expect($status)->not->toBe(0);
        expect($output)->toContain('public SMTP is enabled on this host, but the trusted bundle does not request it — run the inbound rollback, or restore the request');
        expect($result)->toMatchArray(['status' => 'fail', 'state' => 'drift']);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});

it('fails while inbound mail waits in the shared queue for its store, and never counts outbound mail to the same domains', function () {
    $setup = mailInboundActivationHost(['installed' => 'disabled']);

    try {
        // An application's message to support@ leaves by its own route, and
        // waits for that route: never inbound mail stuck.
        mailInboundQueued($setup['host'], [['id' => 'OUT1', 'via' => 'submission', 'reason' => 'Host or domain name not found', 'age' => 90000]]);
        [$status, $output] = mailInboundActivationRun($setup, 'verify');
        expect($status)->toBe(0, $output);
        expect($output)->toContain('PASS no accepted inbound mail is in the shared queue');

        mailInboundQueued($setup['host'], [['id' => 'IN1', 'reason' => 'mail system configuration error', 'age' => 3600]]);
        [$status, $output, $result] = mailInboundActivationRun($setup, 'verify');
        expect($status)->not->toBe(0);
        expect($output)->toContain('1 accepted inbound message(s) wait in the shared queue instead of reaching the store (deferred 1); the oldest was accepted 1h 0m ago')->not->toContain('support@tits.guru');
        expect($result)->toMatchArray(['state' => 'drift', 'queue' => ['inbound' => 1, 'waiting' => 1, 'at_risk' => 0, 'oldest_seconds' => $result['queue']['oldest_seconds'], 'queues' => ['deferred' => 1]]]);
    } finally {
        mailInboundActivationCleanup($setup);
    }
});
