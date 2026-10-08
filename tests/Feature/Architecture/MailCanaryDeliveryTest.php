<?php

use Illuminate\Support\Facades\File;

/*
 * send-mail-canary's message: exactly one, from the reviewed sender, followed
 * by its own queue ID to a final status, with its own queue entry alone
 * deleted when none comes.
 *
 * Every run uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php, with `listener`): the activated
 * target's submission endpoint is the fake gateway listener, the activation it
 * requires is the real activate-mail-outbound --verify, and the queue and the
 * mail log are the fake gateway's own.
 */

/** @return list<string> */
function mailCanaryQueueCalls(array $host): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($host['log'].'/queue.log'))));
}

// =============================================================================
// SENT, AND ONLY ITS OWN QUEUE ENTRY FOLLOWED
// =============================================================================

it('sends exactly one canary from the reviewed sender and passes once its own queue ID is logged status=sent', function () {
    $host = mailCanaryHost('sent');
    // The gateway signs what it accepts.
    file_put_contents($host['state'].'/toggles/signature', "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/simple; d=tits.guru; s=rg1; h=from:to:subject:date:message-id; bh=c2ltdWxhdGVk; b=c2ltdWxhdGVk\n");

    try {
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));

        expect($status)->toBe(0, $output);

        $result = mailCanaryResult($output);
        expect(array_keys($result))->toBe(['target', 'mode', 'status', 'canary_id', 'message_id', 'queue_id', 'recipient_domain', 'smtp_delivery', 'dsn', 'deleted']);
        expect($result)->toMatchArray(['target' => 'tits-guru', 'mode' => 'send', 'status' => 'pass', 'recipient_domain' => 'mailbox.example-receiver.net', 'smtp_delivery' => 'sent', 'dsn' => '2.0.0', 'deleted' => false]);
        expect($result['canary_id'])->toMatch('/^rgcanary-\d{8}T\d{6}Z-[0-9a-f]{12}$/');
        expect($result['message_id'])->toBe("<{$result['canary_id']}@tits.guru>");
        expect($result['queue_id'])->toMatch('/^[0-9A-F]{10}$/');

        expect($output)
            ->toContain('PASS the activation verifies: OUTBOUND READY: YES')
            ->toContain("PASS queue entry {$result['queue_id']}: status=sent (dsn 2.0.0) — the receiving server at mailbox.example-receiver.net accepted the canary")
            ->toContain('This is NOT proof of SPF, DKIM or DMARC at the receiver: read the raw headers of the received message');

        // One message, from the reviewed sender, to the one recipient, through
        // tits-guru's own listener: the envelope sender is the bare reviewed
        // address, and the From header shows TitsGuru before that same
        // address.
        $smtp = File::get($host['state'].'/smtp.log');
        expect(substr_count($smtp, 'DATA'))->toBe(1);
        expect($smtp)
            ->toContain("EHLO mail-canary\nMAIL FROM:<noreply@tits.guru>\nRCPT TO:<".MAIL_CANARY_RECIPIENT.">\nDATA");
        $headers = File::get(glob($host['state'].'/headers-*')[0]);
        expect($headers)
            ->toStartWith('DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/simple; d=tits.guru; s=rg1;')
            ->toContain("From: TitsGuru <noreply@tits.guru>\n")
            ->not->toContain("From: noreply@tits.guru\n")
            ->toContain("Message-ID: {$result['message_id']}\n")
            ->toContain("X-RateGuru-Mail-Canary: {$result['canary_id']}\n")
            ->toContain("Content-Type: text/plain; charset=us-ascii\n");

        // The mail log was read; the queue was not changed; the unrelated
        // entry is still there.
        expect(array_filter(mailCanaryQueueCalls($host), static fn (string $call): bool => str_starts_with($call, 'postsuper')))->toBe([]);
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");

        // The log line names the recipient; the canary never repeats it.
        expect(File::get($host['state'].'/journal'))->toContain(MAIL_CANARY_RECIPIENT);
        mailCanaryExpectNoRecipient($output);
    } finally {
        mailActivationCleanup($host);
    }
});

it('fails a canary that bounced or expired, and has nothing of its own left to delete', function (string $delivery, string $dsn) {
    $host = mailCanaryHost($delivery);

    try {
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));

        expect($status)->toBe(1, $output);
        expect(mailCanaryResult($output))->toMatchArray(['status' => 'fail', 'smtp_delivery' => $delivery, 'dsn' => $dsn, 'deleted' => false]);
        expect($output)->toContain("status={$delivery} (dsn {$dsn}) — the canary was not delivered");
        expect(array_filter(mailCanaryQueueCalls($host), static fn (string $call): bool => str_starts_with($call, 'postsuper')))->toBe([]);
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");
        mailCanaryExpectNoRecipient($output);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'bounced' => ['bounced', '5.1.1'],
    'expired' => ['expired', '4.4.1'],
]);

it('deletes only its own queue entry when it reaches no final status within the window', function (string $delivery, string $last) {
    $host = mailCanaryHost($delivery);

    try {
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));

        expect($status)->toBe(1, $output);
        $result = mailCanaryResult($output);
        expect($result)->toMatchArray(['status' => 'fail', 'smtp_delivery' => $last, 'deleted' => true]);
        $id = $result['queue_id'];

        expect($output)->toContain("deleted the canary's own queue entry {$id}, so it does not keep retrying");

        // Exactly that ID, and the unrelated entry untouched.
        expect(array_values(array_filter(mailCanaryQueueCalls($host), static fn (string $call): bool => str_starts_with($call, 'postsuper'))))->toBe(["postsuper -d {$id}"]);
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");
        mailCanaryExpectNoRecipient($output);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'deferred' => ['deferred', 'deferred'],
    'never attempted' => ['none', 'unknown'],
]);

it('never reports its own entry deleted when the queue cannot be read', function (string $toggle, bool $deleteAttempted, string $failure) {
    $host = mailCanaryHost('deferred');

    try {
        touch($host['state'].'/'.$toggle);

        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));

        expect($status)->toBe(1, $output);
        $result = mailCanaryResult($output);
        expect($result)->toMatchArray(['status' => 'fail', 'smtp_delivery' => 'deferred', 'deleted' => false]);
        expect($output)
            ->toContain($failure)
            ->toContain("remove it with: postsuper -d {$result['queue_id']}")
            ->not->toContain("deleted the canary's own queue entry");

        $deletes = array_values(array_filter(mailCanaryQueueCalls($host), static fn (string $call): bool => str_starts_with($call, 'postsuper')));
        expect($deletes)->toBe($deleteAttempted ? ["postsuper -d {$result['queue_id']}"] : []);
        mailCanaryExpectNoRecipient($output);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'unreadable before the deletion' => ['postqueue-fails', false, 'FAIL could not read the Postfix queue to remove the canary queue entry'],
    'unreadable after the deletion' => ['postqueue-fails-after-delete', true, 'FAIL could not read the Postfix queue to confirm the canary queue entry'],
]);

it('follows the mail log of its own submission only', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/send-mail-canary')));

    expect($code)
        ->toContain('"${JOURNALCTL_BIN}" --no-pager --quiet --output=cat --since="@${DELIVERY_SINCE}" SYSLOG_FACILITY=2')
        ->toContain('[[ "${line}" == "${QUEUE_ID}: "* ]] || continue');
});

// =============================================================================
// NOTHING IS SENT UNLESS EVERYTHING BEFORE IT HOLDS
// =============================================================================

it('never flushes or releases anything, deletes only by its own ID, and never hands the recipient to another program', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/send-mail-canary')));

    foreach (['postqueue -f', 'POSTQUEUE_BIN}" -f', '-d ALL', '-H', '-r ALL', 'postcat', 'sendmail', 'curl', 'wget', 'eval ', '/dev/tcp', 'queue_ids_for'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("send-mail-canary uses {$forbidden}");
    }

    expect(substr_count($code, '"${POSTSUPER_BIN}"'))->toBe(1);
    expect($code)->toContain('"${POSTSUPER_BIN}" -d "${QUEUE_ID}"');

    // Every use of the recipient is a shell builtin or the shared SMTP
    // conversation: printf into the message, the reply redaction, the domain.
    $uses = array_values(array_filter(preg_split('/\R/', $code), static fn (string $line): bool => str_contains($line, '${RECIPIENT}') || str_contains($line, '${RECIPIENT%')));
    expect(array_map('trim', $uses))->toBe([
        'local reply="$1" local_part="${RECIPIENT%@*}"',
        'printf \'To: <%s>\n\' "${RECIPIENT}"',
        'if ! smtp_submit_message "${LISTEN_HOST}" "${LISTEN_PORT}" mail-canary "${SENDER}" "${RECIPIENT}" "$(compose)"; then',
    ]);
});
