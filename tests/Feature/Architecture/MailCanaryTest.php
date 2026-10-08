<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/*
 * send-mail-canary: one real message from an activated production target,
 * followed by its exact queue ID — and the workflow that carries its recipient.
 *
 * Every run uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php, with `listener`): the activated
 * target's submission endpoint is the fake gateway listener, the activation it
 * requires is the real activate-mail-outbound --verify, and the queue and the
 * mail log are the fake gateway's own.
 */

const MAIL_CANARY_RECIPIENT = 'alice.smith@mailbox.example-receiver.net';

/** An activated host, with tits-guru's listener running and DELIVERY its outcome. */
function mailCanaryHost(?string $delivery = 'sent', array $options = []): array
{
    $host = mailActivationHost(['installed' => 'outbound', 'listener' => true, ...$options]);

    if ($delivery !== null) {
        file_put_contents($host['state'].'/toggles/delivery', $delivery);
    }

    return $host;
}

/** @return array{0: int, 1: string} */
function mailCanarySend(array $host, string $file, string $mode = 'send', array $env = []): array
{
    return mailActivationRun($host, ["--{$mode}", '--target', 'tits-guru', '--recipient-file', $file], $env, 'send-mail-canary');
}

/** @return list<string> */
function mailCanaryQueueCalls(array $host): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($host['log'].'/queue.log'))));
}

/** The local part never reaches anything the tooling prints. */
function mailCanaryExpectNoRecipient(string $output): void
{
    expect(str_contains(strtolower($output), 'alice.smith'))->toBeFalse('the recipient\'s local part reached the output');
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

it('refuses a recipient file that is not exactly one public address, before any connection', function (string $content, int $mode, string $problem) {
    $host = mailCanaryHost();

    try {
        $file = mailCanaryRecipientFile($host, $content, $mode);
        [$status, $output] = mailCanarySend($host, $file);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('nothing was sent');
        expect(file_exists($host['state'].'/smtp.log'))->toBeFalse();
        expect(mailCanaryResult($output))->toMatchArray(['status' => 'fail', 'queue_id' => null, 'smtp_delivery' => null]);
        mailCanaryExpectNoRecipient($output);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'readable by others' => [MAIL_CANARY_RECIPIENT."\n", 0o644, 'the recipient file is readable or writable by others (mode 644)'],
    'empty' => ['', 0o600, 'the recipient file is empty or too large to hold one address'],
    'two lines' => [MAIL_CANARY_RECIPIENT."\nbob@example-receiver.net\n", 0o600, 'the recipient file holds more than one line'],
    'a second line without a newline' => [MAIL_CANARY_RECIPIENT."\nx", 0o600, 'the recipient file holds more than one line'],
    'a carriage return' => [MAIL_CANARY_RECIPIENT."\r\n", 0o600, 'the recipient file holds a control character or a non-ASCII byte'],
    'a NUL' => ["alice.smith\0@mailbox.example-receiver.net\n", 0o600, 'the recipient file holds a control character or a non-ASCII byte'],
    'non-ASCII' => ["alice.smith@m\u{e4}ilbox.example-receiver.net\n", 0o600, 'the recipient file holds a control character or a non-ASCII byte'],
    'a space' => ['Alice Smith <'.MAIL_CANARY_RECIPIENT.">\n", 0o600, 'the recipient file holds a space'],
    'two addresses' => [MAIL_CANARY_RECIPIENT.',bob@example-receiver.net', 0o600, 'the recipient file does not hold one plain email address'],
    'no domain' => ["alice.smith\n", 0o600, 'the recipient file does not hold one plain email address'],
    'a bracketed literal' => ["alice.smith@[192.0.2.25]\n", 0o600, 'the recipient file does not hold one plain email address'],
    'an IP address' => ["alice.smith@192.0.2.25\n", 0o600, "the recipient's domain is not a public domain name"],
    'a single label' => ["alice.smith@localhost\n", 0o600, "the recipient's domain is not a public domain name"],
    'a dotted local part' => ["alice..smith@mailbox.example-receiver.net\n", 0o600, "the recipient's local part is not a plain one"],
    '.invalid' => ["alice.smith@mail.invalid\n", 0o600, "the recipient's domain is under the reserved or local .invalid"],
    '.test' => ["alice.smith@mail.test\n", 0o600, 'under the reserved or local .test'],
    '.localhost' => ["alice.smith@mail.localhost\n", 0o600, 'under the reserved or local .localhost'],
    '.local' => ["alice.smith@printer.local\n", 0o600, 'under the reserved or local .local'],
    '.example' => ["alice.smith@mail.example\n", 0o600, 'under the reserved or local .example'],
    'example.com' => ["alice.smith@example.com\n", 0o600, "the recipient's domain is the reserved example.com"],
    'a subdomain of example.org' => ["alice.smith@mail.example.org\n", 0o600, "the recipient's domain is the reserved example.org"],
]);

it('refuses a recipient file that is missing, a symlink or not root\'s, before any connection', function (string $case, string $problem) {
    $host = mailCanaryHost();

    try {
        $file = $host['scratch'].'/recipient';
        $env = [];

        match ($case) {
            'missing' => null,
            'a symlink' => [mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"), rename($file, $file.'.real'), symlink($file.'.real', $file)],
            'a directory' => mkdir($file),
            'another owner' => [mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"), $env = ['RATEGURU_MAILCANARY_FILE_OWNER_UID' => '0']],
        };

        [$status, $output] = mailCanarySend($host, $file, env: $env);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem);
        expect(file_exists($host['state'].'/smtp.log'))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'missing' => ['missing', 'the recipient file does not exist'],
    'a symlink' => ['a symlink', 'the recipient file is a symlink'],
    'a directory' => ['a directory', 'the recipient file is not a regular file'],
    'another owner' => ['another owner', 'the recipient file is not owned by root'],
]);

it('takes the recipient only from the file, never as an argument', function () {
    $host = mailCanaryHost();

    try {
        [$status, $output] = mailActivationRun($host, ['--send', '--target', 'tits-guru', '--recipient', MAIL_CANARY_RECIPIENT], script: 'send-mail-canary');

        expect($status)->toBe(1, $output);
        expect($output)->toContain('the recipient is never an argument, only the content of --recipient-file');
        mailCanaryExpectNoRecipient($output);

        [$status, $output] = mailActivationRun($host, ['--send', '--target', 'tits-guru'], script: 'send-mail-canary');
        expect($status)->toBe(1, $output);
        expect($output)->toContain('--send requires --recipient-file');
        expect(file_exists($host['state'].'/smtp.log'))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
});

it('sends nothing for a target that is still held, or whose activation does not verify', function (string $case, string $problem) {
    $host = match ($case) {
        'held' => mailActivationHost(['requested' => false, 'listener' => true]),
        'not ready' => mailCanaryHost('sent', ['toggles' => ['readonly-signing-fails']]),
        'gateway not activated' => mailActivationHost(['listener' => true]),
    };

    try {
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('nothing was sent');
        expect(file_exists($host['state'].'/smtp.log'))->toBeFalse();
        expect(mailCanaryResult($output))->toMatchArray(['status' => 'fail', 'queue_id' => null]);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'held' => ['held', "tits-guru's mail is held (route none), not outbound by direct delivery"],
    'not ready' => ['not ready', 'the activation of tits-guru does not verify (exit 1)'],
    'gateway not activated' => ['gateway not activated', 'the activation of tits-guru does not verify (exit 1)'],
]);

it('checks everything and sends nothing in --check', function () {
    $host = mailCanaryHost();

    try {
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"), 'check');

        expect($status)->toBe(0, $output);
        expect($output)->toContain('MAIL CANARY CHECK: READY — --send would send one canary to an address at mailbox.example-receiver.net');
        expect(mailCanaryResult($output))->toMatchArray(['mode' => 'check', 'status' => 'pass', 'queue_id' => null, 'recipient_domain' => 'mailbox.example-receiver.net']);
        expect(file_exists($host['state'].'/smtp.log'))->toBeFalse();
        mailCanaryExpectNoRecipient($output);
    } finally {
        mailActivationCleanup($host);
    }
});

it('withholds a gateway reply that names the recipient', function () {
    $host = mailCanaryHost();

    try {
        file_put_contents($host['state'].'/toggles/refuse-rcpt', '');
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));

        expect($status)->toBe(1, $output);
        expect(mailCanaryResult($output))->toMatchArray(['status' => 'fail', 'smtp_delivery' => 'not_submitted', 'queue_id' => null]);
        expect($output)->toContain('FAIL the gateway did not accept the canary (rcpt: 554 5.7.1 refused)');

        // And a reply that names it is cut to its codes.
        $command = 'source '.escapeshellarg(base_path('infrastructure/scripts/send-mail-canary'))
            .'; RECIPIENT='.escapeshellarg(MAIL_CANARY_RECIPIENT).'; redact "550 5.1.1 <Alice.Smith@mailbox.example-receiver.net>: Recipient address rejected"';
        exec('bash -c '.escapeshellarg($command), $redacted);
        expect($redacted)->toBe(['550 5.1.1 (the rest of the reply names the recipient and is withheld)']);
    } finally {
        mailActivationCleanup($host);
    }
});

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

// =============================================================================
// THE ACTION'S RESULT CHECK
// =============================================================================

/**
 * The action's "Send the canary" step, run as the runner runs it, against an
 * ssh stub that prints REMOTE and exits STATUS: the step's exit status, its
 * log, its step summary and the outputs it set.
 *
 * @return array{status: int, log: string, summary: string, outputs: array<string, string>}
 */
function mailCanaryActionStep(string $remote, int $status = 0): array
{
    $step = collect(Yaml::parseFile(base_path('.github/actions/send-rateguru-mail-canary/action.yml'))['runs']['steps'])->firstWhere('name', 'Send the canary');
    $scratch = makeScratchDir('mail-canary-step', ['', '/bin']);

    try {
        file_put_contents($scratch.'/remote', $remote);
        file_put_contents($scratch.'/bin/ssh', "#!/bin/bash\ncat \"\${STUB_REMOTE}\"\nexit {$status}\n");
        chmod($scratch.'/bin/ssh', 0o755);
        file_put_contents($scratch.'/step.sh', $step['run']);
        touch($scratch.'/summary');
        touch($scratch.'/outputs');

        $process = proc_open(['bash', $scratch.'/step.sh'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $scratch,
            'STUB_REMOTE' => $scratch.'/remote',
            'GITHUB_OUTPUT' => $scratch.'/outputs',
            'GITHUB_STEP_SUMMARY' => $scratch.'/summary',
            'RATEGURU_PRIVILEGED_PREFIX' => 'sudo -n',
            'RATEGURU_REMOTE_ROOT' => '/root/rateguru-mail-canary-1-1',
            'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $scratch.'/key',
            'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $scratch.'/known',
            'BOOTSTRAP_HOST' => 'host.example',
            'BOOTSTRAP_PORT' => '22',
            'BOOTSTRAP_USER' => 'ops',
            'DEPLOYMENT_TARGET' => 'tits-guru',
        ]);
        $log = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        $outputs = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($scratch.'/outputs'))) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $outputs[$key] = $value;
        }

        return ['status' => $exit, 'log' => $log, 'summary' => (string) file_get_contents($scratch.'/summary'), 'outputs' => $outputs];
    } finally {
        removeScratchDir($scratch);
    }
}

/**
 * The result line of the delivered canary whose check failed: GitHub Actions
 * run 37814715904 on 49166914, queue ID 346E9FC41DF, received by Gmail with
 * SPF, DKIM and DMARC passing.
 *
 * @return array<string, mixed>
 */
function mailCanaryDeliveredResult(): array
{
    return [
        'target' => 'tits-guru',
        'mode' => 'send',
        'status' => 'pass',
        'canary_id' => 'rgcanary-20261008T171343Z-b4a213f49ef4',
        'message_id' => '<rgcanary-20261008T171343Z-b4a213f49ef4@tits.guru>',
        'queue_id' => '346E9FC41DF',
        'recipient_domain' => 'gmail.com',
        'smtp_delivery' => 'sent',
        'dsn' => '2.0.0',
        'deleted' => false,
    ];
}

/** A canary's report ending in RESULT, as the host prints it. */
function mailCanaryRemoteReport(array|string $result): string
{
    $line = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return "[2026-10-08T17:13:43Z] sending one canary\n  PASS the canary was queued\nRATEGURU_MAIL_CANARY_RESULT={$line}\n";
}

it('accepts the delivered canary\'s own result: its IDs carry the T and Z of the time they were made', function () {
    $run = mailCanaryActionStep(mailCanaryRemoteReport(mailCanaryDeliveredResult()));

    expect($run['status'])->toBe(0, $run['log']);
    expect($run['outputs'])->toBe(['result' => 'pass', 'status' => 'pass']);
    expect($run['log'])->not->toContain('not a canary result')->not->toContain('did not pass');
    expect($run['summary'])
        ->toContain('| Canary | rgcanary-20261008T171343Z-b4a213f49ef4 |')
        ->toContain('| Message-ID | <rgcanary-20261008T171343Z-b4a213f49ef4@tits.guru> |')
        ->toContain('| Recipient domain | gmail.com |')
        ->toContain('| Queue entry followed | 346E9FC41DF |')
        ->toContain('| Delivery status | sent (dsn 2.0.0) |')
        ->toContain('| **Overall** | **PASS** |');
});

it('accepts exactly what the canary itself prints for a delivered message', function () {
    $host = mailCanaryHost('sent');

    try {
        [$status, $output] = mailCanarySend($host, mailCanaryRecipientFile($host, MAIL_CANARY_RECIPIENT."\n"));
        expect($status)->toBe(0, $output);

        $run = mailCanaryActionStep($output);
        expect($run['status'])->toBe(0, $run['log']);
        expect($run['outputs'])->toBe(['result' => 'pass', 'status' => 'pass']);
        mailCanaryExpectNoRecipient($run['log'].$run['summary']);
    } finally {
        mailActivationCleanup($host);
    }
});

it('refuses a result that is not exactly this target\'s canary result, as a failed result check that shows nothing of it', function (array $changes) {
    $result = [...mailCanaryDeliveredResult(), ...$changes];
    $run = mailCanaryActionStep(mailCanaryRemoteReport($result));

    expect($run['status'])->toBe(1, $run['log']);
    expect($run['outputs'])->toBe(['result' => 'invalid']);
    expect($run['log'])->toContain('The machine-readable result is not a canary result for tits-guru: it failed the result check, so it is not reported. The canary exited 0');
    expect($run['summary'])
        ->toContain("**The canary's result was not accepted.**")
        ->toContain('This is a failed result check, not a failed delivery')
        ->not->toContain('| Message-ID |')
        ->not->toContain('**Overall**')
        ->not->toContain('did not complete')
        ->not->toContain('alice.smith');
})->with([
    'a Message-ID without its domain' => [['message_id' => '<rgcanary-20261008T171343Z-b4a213f49ef4>']],
    'a Message-ID of another canary' => [['message_id' => '<rgcanary-20261008T171343Z-000000000000@tits.guru>']],
    'a Message-ID with no canary ID' => [['canary_id' => null]],
    'a canary ID of another form' => [['canary_id' => 'rgcanary-2026-10-08-b4a213f49ef4', 'message_id' => '<rgcanary-2026-10-08-b4a213f49ef4@tits.guru>']],
    'a canary ID whose time is not the canary\'s' => [['canary_id' => 'rgcanary-20261008t171343z-b4a213f49ef4', 'message_id' => '<rgcanary-20261008t171343z-b4a213f49ef4@tits.guru>']],
    'no queue ID' => [['queue_id' => null]],
    'a queue ID that is not one' => [['queue_id' => '346E9FC41DF; true']],
    'another target' => [['target' => 'staging-main']],
    'another mode' => [['mode' => 'check']],
    'a pass that was never sent' => [['smtp_delivery' => 'deferred']],
    'a pass with no delivery status' => [['smtp_delivery' => null]],
    'a delivery status of no kind' => [['smtp_delivery' => 'delivered']],
    'a DSN of no form' => [['dsn' => '2.0.0 **accepted**']],
    'an address for a domain' => [['recipient_domain' => 'alice.smith@gmail.com']],
    'a field it never prints' => [['recipient' => 'alice.smith@gmail.com']],
    'a verdict of no kind' => [['status' => 'ok']],
]);

it('refuses a result line that is not even an object', function () {
    $run = mailCanaryActionStep(mailCanaryRemoteReport('"pass"'));

    expect($run['status'])->toBe(1, $run['log']);
    expect($run['outputs'])->toBe(['result' => 'invalid']);
});

it('reports a delivery that failed as a delivery that failed', function () {
    $result = [...mailCanaryDeliveredResult(), 'status' => 'fail', 'smtp_delivery' => 'bounced', 'dsn' => '5.1.1'];
    $run = mailCanaryActionStep(mailCanaryRemoteReport($result), 1);

    expect($run['status'])->toBe(1, $run['log']);
    expect($run['outputs'])->toBe(['result' => 'fail', 'status' => 'fail']);
    expect($run['log'])->toContain('The canary for tits-guru did not pass (exit 1)')->not->toContain('result check');
    expect($run['summary'])
        ->toContain('| Delivery status | bounced (dsn 5.1.1) |')
        ->toContain('| **Overall** | **FAIL** |')
        ->not->toContain('was not accepted');
});

it('reports no result as no result', function (string $remote) {
    $run = mailCanaryActionStep($remote, 1);

    expect($run['status'])->toBe(1, $run['log']);
    expect($run['outputs'])->toBe(['result' => 'missing']);
    expect($run['log'])->toContain('Expected exactly one RATEGURU_MAIL_CANARY_RESULT line');
    expect($run['summary'])->toBe('');
})->with([
    'none' => ["ERROR: the activation of tits-guru does not verify — nothing was sent\n"],
    'two' => [mailCanaryRemoteReport(mailCanaryDeliveredResult()).mailCanaryRemoteReport(mailCanaryDeliveredResult())],
]);

// =============================================================================
// THE WORKFLOW
// =============================================================================

it('sends the canary from main only, for tits-guru only, to the recipient secret alone', function () {
    $source = File::get(base_path('.github/workflows/send-tits-guru-mail-canary.yml'));
    $workflow = Yaml::parse($source);

    expect($workflow['name'])->toBe('Send tits.guru production mail canary');
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'canary']);
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    $canary = $workflow['jobs']['canary'];
    expect($canary['needs'])->toBe(['validate-ref']);
    expect($canary['environment'])->toBe('production-tits-guru');
    expect($canary['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($canary['steps'][1]['uses'])->toBe('./.github/actions/send-rateguru-mail-canary');
    expect($canary['steps'][1]['with'])->toBe([
        'deployment-target' => 'tits-guru',
        'canary-recipient' => '${{ secrets.MAIL_CANARY_RECIPIENT }}',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);

    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['MAIL_CANARY_RECIPIENT', 'BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);
    expect($source)->not->toContain('MAIL_DKIM_PRIVATE_KEY')->not->toContain('DEPLOY_SSH_KEY')->not->toContain('LARAVEL_ENV');

    // The operator acceptance the run cannot give is stated in its summary.
    expect($source)
        ->toContain('SPF = PASS, DKIM = PASS, DMARC = PASS')
        ->toContain('From: TitsGuru <noreply@tits.guru>, with envelope sender noreply@tits.guru')
        ->toContain('DKIM d=tits.guru, s=rg1, a=rsa-sha256')
        ->toContain('sent from 213.199.41.241, with HELO mta1.tits.guru, and that address\'s PTR is mta1.tits.guru');

    // "Did not complete" is said only when there is no result at all; a
    // failed delivery and a failed result check are reported as themselves.
    $incomplete = collect($canary['steps'])->firstWhere('name', 'Report an incomplete canary');
    expect($incomplete['if'])->toBe("\${{ always() && (steps.canary.outputs.result == '' || steps.canary.outputs.result == 'missing') }}");
    expect($incomplete['run'])->toContain('The canary printed no result, so it did not complete');
    expect(Yaml::parseFile(base_path('.github/actions/send-rateguru-mail-canary/action.yml'))['outputs']['result']['value'])->toBe('${{ steps.canary.outputs.result }}');
});

it('carries the recipient as a root-only file, never an argument, and removes every copy on every path', function () {
    $source = File::get(base_path('.github/actions/send-rateguru-mail-canary/action.yml'));
    $action = Yaml::parse($source);
    $code = executableSourceLines($source);

    expect(array_keys($action['inputs']))->toBe(['deployment-target', 'canary-recipient', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);

    $steps = $action['runs']['steps'];

    // Refused before anything touches the host when the secret is absent.
    expect($steps[0]['name'])->toBe('Validate fixed caller inputs');
    expect($steps[0]['env']['CANARY_RECIPIENT_PRESENT'])->toBe("\${{ inputs.canary-recipient != '' }}");
    expect($steps[0]['env'])->not->toHaveKey('CANARY_RECIPIENT');
    expect($steps[0]['run'])->toContain('if [[ "${CANARY_RECIPIENT_PRESENT}" != "true" ]]; then');

    // The secret reaches exactly one step, which writes it to a 0600 file
    // with a builtin and prints nothing.
    $withRecipient = array_values(array_filter($steps, static fn (array $step): bool => str_contains(json_encode($step['env'] ?? []), 'inputs.canary-recipient }}')));
    expect(array_column($withRecipient, 'name'))->toBe(['Stage the recipient file']);
    $stage = $withRecipient[0]['run'];
    expect($stage)
        ->toContain('install -m 0600 /dev/null "${recipient_path}"')
        ->toContain('printf \'%s\n\' "${recipient}" > "${recipient_path}"')
        ->not->toContain('echo "${recipient')
        ->not->toContain('echo "${CANARY_RECIPIENT');
    expect(substr_count($code, 'CANARY_RECIPIENT}'))->toBe(1);

    // On the host: a root-owned 0600 file in the root-only bundle directory,
    // named to the script by its path.
    expect($code)
        ->toContain('install -m 0600 -o root -g root %q/%q %q/canary-recipient')
        ->toMatch('#remote_command=\(\s+\$\{RATEGURU_PRIVILEGED_PREFIX:-\}\s+"\$\{RATEGURU_REMOTE_ROOT\}/infrastructure/scripts/send-mail-canary"\s+--send\s+--target "\$\{DEPLOYMENT_TARGET\}"\s+--recipient-file "\$\{RATEGURU_REMOTE_ROOT\}/canary-recipient"\s+\)#');

    // One remote command; the other mentions of the bundle's scripts only check
    // it is complete before it is packed.
    expect(substr_count($code, 'remote_command=('))->toBe(1);
    expect(substr_count($code, 'test -x "${GITHUB_WORKSPACE}/infrastructure/scripts/'))->toBe(2);

    foreach (['MAIL_DKIM_PRIVATE_KEY', '--apply', '--rollback', '--verify', 'postsuper', 'postqueue', '--e2e'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the canary action uses {$forbidden}");
    }

    // The result is judged, the domain shown and never an address, and the
    // limits of a pass stated.
    expect($code)
        ->toContain("grep -c '^RATEGURU_MAIL_CANARY_RESULT='")
        ->toContain('or (.smtp_delivery == "sent" and .queue_id != null and .canary_id != null and .message_id != null))')
        ->toContain('and (.canary_id | nullable("^rgcanary-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$"))')
        ->toContain('and (keys == ["canary_id", "deleted", "dsn", "message_id", "mode", "queue_id", "recipient_domain", "smtp_delivery", "status", "target"])')
        ->toContain('| Recipient domain |')
        ->toContain('| Remote MX accepted it (status=sent) |')
        ->toContain('**status=sent is not SPF, DKIM or DMARC acceptance.**');

    // Both copies of the recipient are removed, always.
    $last = array_slice($steps, -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote bundle and recipient file', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }
    expect($last[1]['run'])->toContain('"${RATEGURU_CANARY_RECIPIENT_PATH:-}"');
    expect($last[0]['run'])->toContain("printf -v cleanup_command '%s rm -rf %q && rm -rf %q'");
});
