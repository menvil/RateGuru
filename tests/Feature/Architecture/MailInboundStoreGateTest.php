<?php

/**
 * The store gate: the Postfix policy service the inbound listeners ask at
 * RCPT TO, before any message is accepted, whether the store can take mail
 * right now. These tests run the gate the REAL gateway rendered, speaking the
 * policy protocol to it — attribute lines, an empty line, one action back — on
 * a directory standing in for the store and a mount table of the test's own.
 * Whether Postfix really refuses before 250 with it is MailInboundRealPostfixTest's.
 */

/**
 * The gate's answers to REQUESTS (each a list of attribute lines), asked over
 * one connection, about STORE with RESERVE bytes and MIN_INODES inodes kept
 * free, as MOUNTINFO lists the mounts.
 *
 * @param  list<list<string>>  $requests
 * @return list<string>
 */
function mailInboundStoreGateAnswers(array $host, string $store, int|string $reserve, int|string $minInodes, string $mountinfo, array $requests = [['request=smtpd_access_policy']]): array
{
    $input = implode('', array_map(static fn (array $lines): string => implode("\n", $lines)."\n\n", $requests));
    $process = proc_open(
        ['bash', $host['fs'].'/etc/postfix/rateguru-inbound-store-gate', $store, (string) $reserve, (string) $minInodes, $mountinfo],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
    );
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($errors)->toBe('');
    expect($output)->toEndWith("\n\n");

    return array_map(static fn (string $answer): string => $answer, array_values(array_filter(explode("\n\n", $output))));
}

/** A mount table listing STORE with OPTIONS, beside the root file system. */
function mailInboundStoreGateMounts(string $scratch, string $store, ?string $options): string
{
    $path = $scratch.'/mountinfo-'.bin2hex(random_bytes(4));
    $lines = ["22 1 8:1 / / rw,relatime - ext4 /dev/sda1 rw\n"];
    if ($options !== null) {
        $lines[] = "36 22 7:0 / {$store} {$options} - ext4 /dev/loop0 rw\n";
    }
    file_put_contents($path, implode('', $lines));

    return $path;
}

it('admits mail while the store is mounted, writable and above its reserve of space and inodes', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $store = $host['scratch'].'/store-standin';
        mkdir($store);
        $mounts = mailInboundStoreGateMounts($host['scratch'], $store, 'rw,nosuid,nodev,noexec,relatime');

        expect(mailInboundStoreGateAnswers($host, $store, 1, 1, $mounts))->toBe(['action=DUNNO']);
    } finally {
        mailInboundCleanup($host);
    }
});

it('refuses temporarily, before any 250, while the store cannot take mail', function (Closure $situation, string $answer) {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $store = $host['scratch'].'/store-standin';
        mkdir($store);
        [$listed, $mountOptions, $reserve, $minInodes, $path] = $situation($store);
        $mounts = mailInboundStoreGateMounts($host['scratch'], $listed, $mountOptions);

        expect(mailInboundStoreGateAnswers($host, $path, $reserve, $minInodes, $mounts))->toBe(["action={$answer}"]);
    } finally {
        mailInboundCleanup($host);
    }
})->with([
    'the volume is not mounted' => [fn (string $store) => [$store, null, 1, 1, $store], '451 4.3.0 The inbound mail store is temporarily unavailable, try again later'],
    'the volume is mounted read-only' => [fn (string $store) => [$store, 'ro,nosuid,nodev,noexec,relatime', 1, 1, $store], '451 4.3.0 The inbound mail store is temporarily unavailable, try again later'],
    'another file system is mounted, elsewhere' => [fn (string $store) => [$store.'-elsewhere', 'rw,relatime', 1, 1, $store], '451 4.3.0 The inbound mail store is temporarily unavailable, try again later'],
    'less free space than the reserve' => [fn (string $store) => [$store, 'rw,relatime', '1000000000000000000', 1, $store], '452 4.3.1 Insufficient system storage'],
    'fewer free inodes than the reserve' => [fn (string $store) => [$store, 'rw,relatime', 1, '1000000000000000000', $store], '452 4.3.1 Insufficient system storage'],
    'the mounted store cannot be read' => [fn (string $store) => [$store.'/missing', 'rw,relatime', 1, 1, $store.'/missing'], '452 4.3.1 Insufficient system storage'],
]);

it('answers every request of a connection from the store as it is at that moment', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $store = $host['scratch'].'/store-standin';
        mkdir($store);
        $mounts = mailInboundStoreGateMounts($host['scratch'], $store, 'rw,relatime');

        expect(mailInboundStoreGateAnswers($host, $store, 1, 1, $mounts, [['request=smtpd_access_policy'], ['request=smtpd_access_policy'], ['request=smtpd_access_policy']]))
            ->toBe(['action=DUNNO', 'action=DUNNO', 'action=DUNNO']);
    } finally {
        mailInboundCleanup($host);
    }
});

it('decides from the store alone: no attribute of the request is read, echoed or logged', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $store = $host['scratch'].'/store-standin';
        mkdir($store);
        $mounts = mailInboundStoreGateMounts($host['scratch'], $store, null);
        $request = ['request=smtpd_access_policy', 'protocol_state=RCPT', 'sender=someone@sender.example', 'recipient=support@tits.guru', 'client_address=203.0.113.9', 'helo_name=evil.example'];

        $answers = mailInboundStoreGateAnswers($host, $store, 1, 1, $mounts, [$request]);
        expect($answers)->toBe(['action=451 4.3.0 The inbound mail store is temporarily unavailable, try again later']);
        foreach (['someone@', 'support@', '203.0.113.9', 'evil.example'] as $private) {
            expect(implode('', $answers))->not->toContain($private);
        }

        $code = executableSourceLines(mailInboundFile($host, '/etc/postfix/rateguru-inbound-store-gate'));
        foreach (['logger', 'syslog', '>>', 'tee ', 'rm ', 'mv ', 'touch ', 'mkdir', 'find ', 'cat '] as $forbidden) {
            expect(str_contains($code, $forbidden))->toBeFalse("the store gate uses {$forbidden}");
        }
        // Each line is only ever compared with the empty line that ends a request.
        expect($code)->toContain('[[ -z "${line%$\'\r\'}" ]] || continue');
    } finally {
        mailInboundCleanup($host);
    }
});

it('is rendered with the store, its reserve and the gate floor every other judge uses', function () {
    $host = mailInboundHost(['installed' => 'disabled']);

    try {
        $library = File::get(base_path('infrastructure/scripts/mail-inbound-host'));
        expect($library)
            ->toContain('MAIL_INBOUND_STORE_GATE="/etc/postfix/rateguru-inbound-store-gate"')
            ->toContain('MAIL_INBOUND_STORE_GATE_SERVICE="rateguru-inbound-store-gate"')
            ->toContain('MAIL_INBOUND_STORE_GATE_USER="nobody"')
            ->toContain('MAIL_INBOUND_STORE_GATE_MIN_INODES=1024');

        // The activation's health check asks the installed gate the same question.
        expect(executableSourceLines(File::get(base_path('infrastructure/scripts/activate-mail-inbound'))))
            ->toContain('| bash "${gate}" "$(physical "${MAIL_INBOUND_STORE}")" "$(jq -r \'.receiver.limits.storage_reserve_bytes\' <<<"${PLAN}")" \\')
            ->toContain('"${MAIL_INBOUND_STORE_GATE_MIN_INODES}" "${MOUNTINFO_FILE}" 2>/dev/null \\');
    } finally {
        mailInboundCleanup($host);
    }
});
