<?php

/**
 * The Certbot deploy hook install-mail-inbound-tls renders, as Certbot runs it
 * after a renewal: the renewed pair of exactly its one lineage reaches the
 * listener — judged, staged, moved in, read back, rolled back on any failure —
 * and Postfix is reloaded when it runs, never restarted. Every other renewal
 * is none of its business.
 *
 * The real installer installs the hook on the simulated inbound host first;
 * then a renewal is reproduced as Certbot performs it — a new version of the
 * pair in archive/ and the live links moved to it (mailInboundCertbotLineage)
 * — and the installed hook is run in an environment of its own
 * (mailInboundDeployHook). What is asserted is what lands on disk and what
 * systemd is asked to do. The first installation, the source and target rules
 * and the hook's render are MailInboundTlsTest's.
 */
beforeEach(function () {
    $this->host = mailInboundHost(['tls' => false]);
    mailInboundCertbotLineage($this->host);

    [$status, $output] = mailInboundTlsRun($this->host, 'apply');
    expect($status)->toBe(0, $output);
    mailInboundTlsResetLogs($this->host);
});

afterEach(function () {
    mailInboundCleanup($this->host);
});

it('reaches the listener: the renewed pair in place, Postfix reloaded once, and it verifies', function () {
    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(0, $output);
    mailInboundTlsExpectInstalled($this->host, 2);
    expect(mailInboundFile($this->host, MAIL_TLS_KEY))->toContain('rateguru-test-key id=k2');
    expect(mailInboundTlsSystemdRequests($this->host))->toBe(['systemctl reload postfix@-.service']);
    expect($output)->toContain('reloaded postfix@-.service: it serves the new pair');
    mailInboundTlsNoStaging($this->host);

    // Certbot's own archive is untouched.
    expect(mailInboundTlsArchived($this->host, 'privkey', 1))->toBe(mailInboundTestKey('k1'));

    [$status, $output] = mailInboundTlsRun($this->host, 'verify');
    expect($status)->toBe(0, $output);
    expect($output)->toContain('PASS     pair:/etc/rateguru-mail-inbound/tls');
});

it('of any other certificate touches nothing and reloads nothing', function () {
    // Certbot moved the mail lineage too, but renewed only the site's.
    mailInboundCertbotLineage($this->host, 1, ['names' => 'tits.guru,www.tits.guru'], null, 'tits.guru');
    mailInboundCertbotLineage($this->host, 2);
    $before = array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY]);

    foreach (['/etc/letsencrypt/live/tits.guru', '/etc/letsencrypt/live/mx1.tits.guru/', '/etc/letsencrypt/live/mx1.tits.guru.bak', ''] as $lineage) {
        [$status, $output] = mailInboundDeployHook($this->host, $lineage === '' ? '' : $this->host['fs'].$lineage);

        expect($status)->toBe(0, $output);
        expect($output)->toBe('');
    }

    expect(array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY]))->toBe($before);
    mailInboundTlsExpectInstalled($this->host, 1);
    expect(mailInboundLog($this->host, 'mutations.log'))->toBe('');
    expect(mailInboundLog($this->host, 'reads.log'))->toBe('');
});

it('refuses a renewed pair that may not be served, and keeps the installed one', function (array $card, ?string $key, string $reason) {
    $before = array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY]);

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2, $card, $key);

    expect($status)->toBe(1, $output);
    expect($output)->toContain($reason);
    expect(array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY]))->toBe($before);
    mailInboundTlsExpectInstalled($this->host, 1);
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
    mailInboundTlsNoStaging($this->host);
})->with([
    'a wrong name' => [['names' => 'mail.example.org'], null, 'the certificate does not cover mx1.tits.guru'],
    'a key of another certificate' => [[], 'k9', 'the private key does not belong to the certificate'],
    'expired' => [['days' => -1], null, 'the certificate has expired, or expires within 14 days'],
    'expiring within 14 days' => [['days' => 13], null, 'the certificate has expired, or expires within 14 days'],
    'no trusted chain' => [['trusted' => 'no'], null, 'the certificate does not chain to a trusted certificate authority'],
]);

it('keeps the installed pair, byte for byte, when the copy fails', function () {
    touch($this->host['toggles'].'/tls-install-fail');
    $before = array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY]);

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(1, $output);
    expect($output)->toContain('the pair could not be staged intact — the installed pair is unchanged');
    expect(array_map(fn (string $path): string => mailInboundTlsIdentity($this->host, $path), [MAIL_TLS_CERT, MAIL_TLS_KEY]))->toBe($before);
    mailInboundTlsExpectInstalled($this->host, 1);
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
    mailInboundTlsNoStaging($this->host);
});

it('puts the previous pair back when the new one cannot be put in place, never leaving a mixed pair', function () {
    touch($this->host['toggles'].'/tls-mv-fail');

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(1, $output);
    expect($output)
        ->toContain('the pair could not be put in place intact')
        ->toContain('the pair that was installed before is back in place, verified');
    // The key had already been moved in when the certificate failed: it is version 1 again.
    mailInboundTlsExpectInstalled($this->host, 1);
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
    mailInboundTlsNoStaging($this->host);

    // The next renewal, with nothing failing, goes through.
    unlink($this->host['toggles'].'/tls-mv-fail');
    [$status, $output] = mailInboundCertbotRenewal($this->host, 3);
    expect($status)->toBe(0, $output);
    mailInboundTlsExpectInstalled($this->host, 3);
});

it('is not deceived by a staging directory a failed run left behind', function () {
    @mkdir($this->host['fs'].'/etc/rateguru-mail-inbound/.tls-incoming.ABC123', 0o700);
    file_put_contents($this->host['fs'].'/etc/rateguru-mail-inbound/.tls-incoming.ABC123/privkey.pem', mailInboundTestKey('k7'));

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(0, $output);
    mailInboundTlsExpectInstalled($this->host, 2);
    mailInboundTlsNoStaging($this->host);
});

it('reloads nothing while Postfix is stopped, and still installs the pair it reads when it starts', function () {
    unlink($this->host['state'].'/postfix@-.service.active');

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(0, $output);
    mailInboundTlsExpectInstalled($this->host, 2);
    expect($output)->toContain('Postfix is not running — nothing was reloaded; it reads the new pair when it starts');
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
    expect(mailInboundLog($this->host, 'reads.log'))->toContain('systemctl is-active --quiet postfix@-.service');
    expect(file_exists($this->host['state'].'/postfix@-.service.active'))->toBeFalse();
});

it('never restarts Postfix when the reload fails', function () {
    touch($this->host['toggles'].'/reload-fail');

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(1, $output);
    expect($output)->toContain('postfix@-.service did not reload — the new pair is in place and is served once Postfix reloads');
    expect(mailInboundTlsSystemdRequests($this->host))->toBe(['systemctl reload postfix@-.service']);
    mailInboundTlsExpectInstalled($this->host, 2);
});

it('survives being killed between the key and the certificate: the next run puts the kept pair back, verified, then installs', function () {
    touch($this->host['toggles'].'/tls-mv-kill');

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    // Killed outright after the first move: the renewed key beside the old
    // certificate, and the journal holding the only copy of the old pair.
    expect($status)->not->toBe(0, $output);
    expect(mailInboundFile($this->host, MAIL_TLS_KEY))->toBe(mailInboundTlsArchived($this->host, 'privkey', 2));
    expect(mailInboundFile($this->host, MAIL_TLS_CERT))->toBe(mailInboundTlsArchived($this->host, 'fullchain', 1));
    $journal = mailInboundTlsJournal($this->host);
    expect(File::get($journal.'/state'))->toStartWith("phase=swapping\n");
    expect(File::get($journal.'/previous/privkey.pem'))->toBe(mailInboundTlsArchived($this->host, 'privkey', 1));
    expect(File::get($journal.'/previous/fullchain.pem'))->toBe(mailInboundTlsArchived($this->host, 'fullchain', 1));
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);

    [$status, $output] = mailInboundTlsRun($this->host, 'verify');
    expect($status)->toBe(1, $output);
    expect($output)->toContain('an installation was stopped while the pair was being replaced');

    unlink($this->host['toggles'].'/tls-mv-kill');
    mailInboundTlsResetLogs($this->host);
    [$status, $output] = mailInboundDeployHook($this->host);

    expect($status)->toBe(0, $output);
    expect($output)
        ->toContain('an earlier installation was stopped while the pair was being replaced — putting back the pair it kept')
        ->toContain('the pair that was installed before is back in place, verified')
        ->toContain('installed the certificate for mx1.tits.guru');
    expect(strpos($output, 'back in place, verified'))->toBeLessThan(strpos($output, 'installed the certificate'));
    mailInboundTlsExpectInstalled($this->host, 2);
    mailInboundTlsNoStaging($this->host);
    expect(mailInboundTlsSystemdRequests($this->host))->toBe(['systemctl reload postfix@-.service']);
});

it('puts the kept pair back after a kill even when the pair Certbot holds now may not be served', function () {
    touch($this->host['toggles'].'/tls-mv-kill');
    expect(mailInboundCertbotRenewal($this->host, 2)[0])->not->toBe(0);
    unlink($this->host['toggles'].'/tls-mv-kill');
    mailInboundTlsResetLogs($this->host);

    [$status, $output] = mailInboundCertbotRenewal($this->host, 3, ['names' => 'mail.example.org']);

    expect($status)->toBe(1, $output);
    expect($output)
        ->toContain('the pair that was installed before is back in place, verified')
        ->toContain('the certificate does not cover mx1.tits.guru');
    mailInboundTlsExpectInstalled($this->host, 1);
    mailInboundTlsNoStaging($this->host);
    // Postfix reads the pair put back: processes started while it was mixed go.
    expect(mailInboundTlsSystemdRequests($this->host))->toBe(['systemctl reload postfix@-.service']);
});

it('keeps the kept pair, reloads nothing and says CRITICAL when that copy cannot be verified', function (string $file) {
    touch($this->host['toggles'].'/tls-mv-kill');
    expect(mailInboundCertbotRenewal($this->host, 2)[0])->not->toBe(0);
    unlink($this->host['toggles'].'/tls-mv-kill');
    $journal = mailInboundTlsJournal($this->host);
    file_put_contents($journal.'/previous/'.$file, "altered\n", FILE_APPEND);
    $before = provisionTreeSnapshot($this->host['fs'].'/etc/rateguru-mail-inbound');
    mailInboundTlsResetLogs($this->host);

    [$status, $output] = mailInboundDeployHook($this->host);

    expect($status)->toBe(2, $output);
    expect($output)->toContain("CRITICAL the copy of the previous {$file} in {$journal} does not match its journal — it is kept, and nothing was put back");
    // Neither the journal nor the installed files were touched, and nothing was reloaded.
    expect(provisionTreeSnapshot($this->host['fs'].'/etc/rateguru-mail-inbound'))->toBe($before);
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);

    // The installer refuses as well, and keeps it as well.
    [$status, $output] = mailInboundTlsRun($this->host, 'apply');
    expect($status)->not->toBe(0, $output);
    expect($output)->toContain('CRITICAL the copy of the previous');
    expect(provisionTreeSnapshot($this->host['fs'].'/etc/rateguru-mail-inbound'))->toBe($before);
})->with(['privkey.pem', 'fullchain.pem']);

it('puts the previous pair back itself when it is terminated between the key and the certificate', function () {
    touch($this->host['toggles'].'/tls-mv-term');

    [$status, $output] = mailInboundCertbotRenewal($this->host, 2);

    expect($status)->toBe(143, $output);
    expect($output)
        ->toContain('stopped while the pair was being replaced — putting the previous pair back')
        ->toContain('the pair that was installed before is back in place, verified');
    mailInboundTlsExpectInstalled($this->host, 1);
    mailInboundTlsNoStaging($this->host);
    expect(mailInboundTlsSystemdRequests($this->host))->toBe([]);
});
