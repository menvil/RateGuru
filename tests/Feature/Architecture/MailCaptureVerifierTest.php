<?php

/*
 * verify-mail-capture, run for real against a simulated host.
 *
 * --read-only and the argument and root gates run the script as shipped, with
 * systemd, the listeners and both HTTP APIs answered by the stubs in
 * tests/Pest.php. The --e2e acceptance needs one thing a stub on PATH cannot
 * give: it submits mail over bash's /dev/tcp. So those tests source the script
 * — which defines its functions without running main — point the Mailpit SMTP
 * port at an ephemeral loopback port this process listens on, and play
 * Mailpit's SMTP side there, storing what it accepts where the curl stub's
 * search API finds it, and relaying a copy to the mirror's store while the
 * mirror's unit is active. Only require_root is replaced, because these tests
 * run unprivileged; the root gate itself is proven against the unmodified
 * script.
 */

/** The verifier's output without its PASS/FAIL colouring. */
function mailCaptureVerifierText(string $output): string
{
    return (string) preg_replace('/\e\[[0-9;]*m/', '', $output);
}

/**
 * Serve one SMTP session as Mailpit would: store an accepted message as
 * "<id> <subject>" for the API, and relay a copy to the mirror's store while
 * the mirror is running (unless $smtp['relay'] is false). $smtp['RCPT'] can
 * replace the reply to RCPT TO.
 *
 * @return list<string> every line the client sent
 */
function mailCaptureFakeMailpitSession(mixed $client, string $state, array $smtp): array
{
    stream_set_timeout($client, 5);
    $lines = [];
    $reply = function (string $text) use ($client): void {
        fwrite($client, $text."\r\n");
    };

    $reply('220 fake-mailpit ESMTP');

    while (($line = fgets($client)) !== false) {
        $lines[] = $line = rtrim($line, "\r\n");

        switch (strtoupper((string) strtok($line, ' :'))) {
            case 'EHLO':
                // Multi-line, as a real server answers: the client must wait
                // for the last line.
                $reply('250-fake-mailpit');
                $reply('250 SIZE 0');
                break;
            case 'RCPT':
                $reply($smtp['RCPT'] ?? '250 2.1.5 Ok');
                break;
            case 'DATA':
                $reply('354 End data with <CR><LF>.<CR><LF>');
                $subject = '';
                while (($data = fgets($client)) !== false && ($data = rtrim($data, "\r\n")) !== '.') {
                    $lines[] = $data;
                    if (str_starts_with($data, 'Subject: ')) {
                        $subject = substr($data, strlen('Subject: '));
                    }
                }
                $lines[] = '.';

                $id = bin2hex(random_bytes(4));
                file_put_contents($state.'/messages-8025', "mp{$id} {$subject}\n", FILE_APPEND);
                if (($smtp['relay'] ?? true) && @file_get_contents($state.'/staging-mailtrap-local.service.active') === 'active') {
                    file_put_contents($state.'/messages-3550', "mt{$id} {$subject}\n", FILE_APPEND);
                }
                $reply('250 2.0.0 Ok: queued');
                break;
            case 'QUIT':
                $reply('221 2.0.0 Bye');
                break 2;
            default:
                $reply('250 2.0.0 Ok');
        }
    }

    fclose($client);

    return $lines;
}

/**
 * Source verify-mail-capture with its Mailpit SMTP port pointed at a listener
 * this process serves, run $body, and serve every SMTP session it opens until
 * it exits. $smtp['closed'] closes the listener first: a refused connection.
 *
 * @return array{exit:int, output:string, sessions:list<list<string>>}
 */
function mailCaptureVerifierAcceptance(array $workspace, string $body, array $smtp = []): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse("could not listen: {$error}");
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    if ($smtp['closed'] ?? false) {
        fclose($server);
        $server = null;
    }

    $listeners = (string) file_get_contents($workspace['state'].'/listeners');
    file_put_contents($workspace['state'].'/listeners', str_replace('127.0.0.1:1025', "127.0.0.1:{$port}", $listeners));

    $harness = 'source '.escapeshellarg(infraScript('verify-mail-capture'))."\n"
        ."MAILPIT_SMTP_PORT={$port}\n"
        ."POLL_TIMEOUT=3\n"
        .$body;
    $outputFile = $workspace['root'].'/verifier.out';

    $process = proc_open(
        ['bash', '-c', $harness],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'w'], 2 => ['redirect', 1]],
        $pipes,
        $workspace['root'],
        array_merge(getenv(), [
            'STUB_STATE_DIR' => $workspace['state'],
            'PATH' => $workspace['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        ]),
    );
    expect($process)->not->toBeFalse('could not start the verifier');

    $sessions = [];
    $deadline = microtime(true) + 30;

    try {
        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exit = $status['exitcode'];
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                test()->fail("the verifier did not finish:\n".file_get_contents($outputFile));
            }

            if ($server === null) {
                usleep(10000);

                continue;
            }

            $read = [$server];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 20000) > 0 && ($client = stream_socket_accept($server, 1)) !== false) {
                $sessions[] = mailCaptureFakeMailpitSession($client, $workspace['state'], $smtp);
            }
        }
    } finally {
        if ($server !== null) {
            fclose($server);
        }
        proc_close($process);
    }

    return ['exit' => $exit, 'output' => mailCaptureVerifierText((string) file_get_contents($outputFile)), 'sessions' => $sessions];
}

/** The full acceptance run, as main --e2e starts it — root gate aside. */
const MAIL_CAPTURE_E2E = "require_root() { :; }\nmain --e2e\n";

/** The tokens of the messages the run submitted, in order. */
function mailCaptureVerifierTokens(array $sessions): array
{
    return array_map(function (array $lines): string {
        $subject = array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'Subject: ')));

        return substr($subject[0] ?? 'Subject: ', strlen('Subject: '));
    }, $sessions);
}

/** Lines in one of the curl stub's message stores. */
function mailCaptureStoredMessages(array $workspace, int $port): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents($workspace['state']."/messages-{$port}"))));
}

// =============================================================================
// --e2e: the mutating acceptance run
// =============================================================================

it('accepts mail into Mailpit, mirrors it, survives a mirror outage and cleans up after itself', function () {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);

        $run = mailCaptureVerifierAcceptance($workspace, MAIL_CAPTURE_E2E);

        expect($run['exit'])->toBe(0, $run['output']);

        $passes = [
            'PASS staging-mailpit.service is active',
            'PASS SMTP message accepted by Mailpit (',
            'PASS message appears in Mailpit (canonical copy)',
            'PASS mirrored copy appears in Mailtrap Local',
            'PASS Mailpit still accepts and stores mail while Mailtrap Local is stopped',
            'PASS Mailtrap Local is stably active after restart',
            'PASS mirroring resumes after Mailtrap Local restart',
        ];
        $positions = array_map(fn (string $pass): int|false => strpos($run['output'], $pass), $passes);
        expect($positions)->not->toContain(false);
        $sorted = $positions;
        sort($sorted);
        expect($positions)->toBe($sorted);

        // Three submissions — before, during and after the mirror outage — each
        // a complete SMTP dialogue carrying its own unique token.
        $tokens = mailCaptureVerifierTokens($run['sessions']);
        expect($tokens)->toHaveCount(3);
        expect(array_unique($tokens))->toHaveCount(3);

        foreach ($run['sessions'] as $index => $lines) {
            $token = $tokens[$index];
            expect($token)->toMatch('/^mcverify\d{14}\d+$/');
            expect($lines)->toBe([
                'EHLO staging-verify',
                'MAIL FROM:<mail-capture-verify@staging.invalid>',
                'RCPT TO:<verify@staging.invalid>',
                'DATA',
                'From: mail-capture-verify@staging.invalid',
                'To: verify@staging.invalid',
                "Subject: {$token}",
                "X-Staging-Mail-Capture-Verify: {$token}",
                '',
                "Staging mail-capture verification message {$token}",
                '.',
                'QUIT',
            ]);
        }

        // The mirror was stopped once and started once, in that order, and is
        // running again; Mailpit was never touched.
        $calls = mailCaptureCalls($workspace);
        $lifecycle = array_values(array_filter($calls, fn (string $call): bool => (bool) preg_match('/^systemctl (stop|start|restart) /', $call)));
        expect($lifecycle)->toBe([
            'systemctl stop staging-mailtrap-local.service',
            'systemctl start staging-mailtrap-local.service',
        ]);
        expect(file_get_contents($workspace['state'].'/staging-mailtrap-local.service.active'))->toBe('active');

        // Every synthetic message is deleted again from both stores, each API
        // addressed in its own dialect.
        expect(mailCaptureStoredMessages($workspace, 8025))->toBe([]);
        expect(mailCaptureStoredMessages($workspace, 3550))->toBe([]);
        $deletions = explode("\n", trim((string) file_get_contents($workspace['state'].'/deletions')));
        expect(array_filter($deletions, fn (string $line): bool => str_starts_with($line, '8025 {"IDs":[')))->toHaveCount(3);
        expect(array_filter($deletions, fn (string $line): bool => str_starts_with($line, '3550 {"ids":[')))->toHaveCount(2);
        expect($run['output'])->toContain('verification succeeded; cleaning up test messages');
    } finally {
        removeScratchDir($workspace['root']);
    }
});

it('fails the acceptance when Mailpit refuses the connection', function () {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);

        $run = mailCaptureVerifierAcceptance($workspace, MAIL_CAPTURE_E2E, ['closed' => true]);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])
            ->toContain('FAIL SMTP submission to Mailpit failed')
            ->not->toContain('PASS SMTP message accepted')
            // The failure is the FAIL line alone, not the shell's own error.
            ->not->toContain('Connection refused');
        expect(implode("\n", mailCaptureCalls($workspace)))->not->toContain('systemctl stop');
    } finally {
        removeScratchDir($workspace['root']);
    }
});

it('waits for a stable running state, and gives up at once on a failed unit', function (string $active, string $sub, int $status, int $sleeps) {
    $workspace = mailCaptureStubWorkspace();

    try {
        file_put_contents($workspace['state'].'/staging-mailtrap-local.service.active', $active);
        file_put_contents($workspace['state'].'/staging-mailtrap-local.service.sub', $sub);

        $run = mailCaptureRun($workspace, ['bash', '-c', 'source '.escapeshellarg(infraScript('verify-mail-capture'))
            .'; wait_service_active staging-mailtrap-local.service 3 && echo "status 0" || echo "status $?"']);

        expect($run['output'])->toBe("status {$status}");
        expect(array_keys(mailCaptureCalls($workspace), 'sleep 1'))->toHaveCount($sleeps);
    } finally {
        removeScratchDir($workspace['root']);
    }
})->with([
    'running' => ['active', 'running', 0, 0],
    'failed' => ['failed', 'failed', 1, 0],
    'stuck activating' => ['activating', 'auto-restart', 1, 3],
]);

// =============================================================================
// The gates in front of it
// =============================================================================

it('refuses the mutating acceptance without root, before a single probe', function (array $arguments) {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);

        $run = mailCaptureRun($workspace, ['bash', infraScript('verify-mail-capture'), ...$arguments]);

        expect($run['exit'])->toBe(1, $run['output']);
        expect(mailCaptureVerifierText($run['output']))->toBe('  FAIL this command must be executed as root (it stops/starts services)');
        expect(mailCaptureCalls($workspace))->toBe([]);
    } finally {
        removeScratchDir($workspace['root']);
    }
})->with([
    '--e2e' => [['--e2e']],
    'no mode, which means --e2e' => [[]],
])->skip(fn () => getmyuid() === 0, 'proves the root gate, so it must run as a non-root user');

it('takes exactly one known mode', function (array $arguments, int $exit, string $message) {
    $workspace = mailCaptureStubWorkspace();

    try {
        $run = mailCaptureRun($workspace, ['bash', infraScript('verify-mail-capture'), ...$arguments]);

        expect($run['exit'])->toBe($exit, $run['output']);
        expect(mailCaptureVerifierText($run['output']))
            ->toContain('verify-mail-capture --read-only')
            ->toContain($message);
        expect(mailCaptureCalls($workspace))->toBe([]);
    } finally {
        removeScratchDir($workspace['root']);
    }
})->with([
    'help' => [['--help'], 0, 'Performs NO mutation whatsoever'],
    'two modes' => [['--read-only', '--e2e'], 1, 'FAIL mode given more than once'],
    'an unknown argument' => [['--quick'], 1, 'FAIL unknown argument: --quick'],
]);

it('names the tool it is missing', function () {
    $workspace = mailCaptureStubWorkspace();
    $minimal = $workspace['root'].'/minimal-bin';

    try {
        mkdir($minimal);
        symlink(trim((string) shell_exec('command -v bash')), $minimal.'/bash');
        foreach (['systemctl', 'curl'] as $stub) {
            symlink($workspace['bin'].'/'.$stub, $minimal.'/'.$stub);
        }

        $run = mailCaptureRun($workspace, ['bash', infraScript('verify-mail-capture'), '--read-only'], ['PATH' => $minimal]);

        expect($run['exit'])->toBe(1, $run['output']);
        expect(mailCaptureVerifierText($run['output']))->toBe('  FAIL required tool not found: jq');
    } finally {
        removeScratchDir($workspace['root']);
    }
});

// =============================================================================
// --read-only: what it refuses, still without changing anything
// =============================================================================

it('fails --read-only on each broken part of the slice, and still changes nothing', function (callable $breakage, string $message) {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);
        $breakage($workspace['state']);

        $run = mailCaptureRun($workspace, ['bash', infraScript('verify-mail-capture'), '--read-only']);

        expect($run['exit'])->toBe(1, $run['output']);
        expect(mailCaptureVerifierText($run['output']))
            ->toContain("FAIL {$message}")
            ->not->toContain('read-only verification succeeded');

        foreach (mailCaptureCalls($workspace) as $call) {
            expect($call)->toMatch('#^(systemctl is-active --quiet |curl -fsS --noproxy \* --max-time 5 http://127\.0\.0\.1:(8025|3550)/api/v1/(info|version)$|sleep 1$)#');
        }
    } finally {
        removeScratchDir($workspace['root']);
    }
})->with([
    'Mailpit stopped' => [
        fn (string $state) => file_put_contents($state.'/staging-mailpit.service.active', 'inactive'),
        'staging-mailpit.service is not active',
    ],
    'the mirror stopped' => [
        fn (string $state) => file_put_contents($state.'/staging-mailtrap-local.service.active', 'failed'),
        'staging-mailtrap-local.service is not active',
    ],
    // The 0.2.0 failure mode: bound on 127.0.0.1, where nothing relays to it.
    'the mirror SMTP on the wrong loopback address' => [
        fn (string $state) => file_put_contents($state.'/listeners', "127.0.0.1:3535\n127.0.0.1:3550\n127.0.0.1:1025\n127.0.0.1:8025\n"),
        'Mailtrap SMTP not listening on 127.0.0.2:3535',
    ],
    'the mirror API silent' => [
        fn (string $state) => file_put_contents($state.'/apis', "http://127.0.0.1:8025/api/v1/info\n"),
        'Mailtrap Local API is unavailable',
    ],
]);

// =============================================================================
// A failure after the first SMTP connection still says what failed
// =============================================================================

it('names the failed step once Mailpit has been connected to, and still cleans up', function (array $smtp, callable $breakage, string $failure) {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);
        $breakage($workspace['state']);

        $run = mailCaptureVerifierAcceptance($workspace, MAIL_CAPTURE_E2E, $smtp);

        expect($run['exit'])->toBe(1, $run['output']);
        expect($run['output'])->toMatch('/^  FAIL '.$failure.'$/m');

        // Whatever it got through, it deletes again — and a mirror it stopped
        // is started again.
        expect(mailCaptureStoredMessages($workspace, 8025))->toBe([]);
        expect(mailCaptureStoredMessages($workspace, 3550))->toBe([]);
        expect(file_get_contents($workspace['state'].'/staging-mailtrap-local.service.active'))->toBe('active');
    } finally {
        removeScratchDir($workspace['root']);
    }
})->with([
    'Mailpit refuses the recipient' => [
        ['RCPT' => '550 5.1.1 <verify@staging.invalid>: Recipient address rejected'],
        fn (string $state) => null,
        'SMTP submission to Mailpit failed',
    ],
    'the relay to the mirror is broken' => [
        ['relay' => false],
        fn (string $state) => null,
        'mirrored copy of mcverify\d+ did not appear in Mailtrap Local',
    ],
    'Mailpit goes down with the mirror' => [
        [],
        fn (string $state) => file_put_contents($state.'/staging-mailtrap-local.service.stops', "staging-mailpit.service\n"),
        'Mailpit stopped when Mailtrap Local was stopped',
    ],
]);
