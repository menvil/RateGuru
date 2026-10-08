<?php

use Illuminate\Support\Facades\File;

/*
 * send-mail-canary's recipient: taken only from a root-only file that holds
 * exactly one public address, and refused before any connection otherwise.
 *
 * Every run uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php, with `listener`): the activated
 * target's submission endpoint is the fake gateway listener, the activation it
 * requires is the real activate-mail-outbound --verify, and the queue and the
 * mail log are the fake gateway's own.
 */

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
