<?php

use Illuminate\Support\Facades\File;

/**
 * What a failed install-bootstrap-services --apply puts back.
 *
 * The installer records every directly-owned resource before it touches it —
 * files it backs up or creates, the enabled-site link it creates or repoints,
 * units it starts or enables, configuration it pushes into a running service —
 * and on any failure restores them in reverse, then re-validates and reloads
 * the restored configuration into each service it had already reloaded.
 * InstallBootstrapServicesTest covers that rollback where every restoration
 * works. This file covers the parts of it that only run when the apply had
 * changed something rollback has to undo by hand, or when rollback itself
 * cannot finish: the operator must be told, nothing that can be restored may
 * be skipped because something else could not, and no service may be pointed
 * at configuration its own parser rejects.
 *
 * Each apply fails at the mail gateway, the last thing an apply converges, so
 * everything before it has already been changed. The simulated host and its
 * stubs are the ones InstallBootstrapServicesTest uses (tests/Pest.php); a
 * child installer's apply hook is how a test changes the host while the apply
 * is still running.
 */

/**
 * Makes the mail gateway's apply — the final step of a host apply — fail,
 * after running $hook (bash) on the host while it "applies".
 */
function bsvcRollbackFailMailGateway(string $scratch, string $hook = ''): void
{
    @unlink($scratch.'/toggles/mail-gateway-installer-compliant');
    touch($scratch.'/toggles/mail-gateway-installer-apply-fail');

    if ($hook !== '') {
        writeExecutable($scratch.'/toggles/mail-gateway-installer-apply-hook', "#!/bin/bash\n".$hook."\n");
    }
}

it('points a repointed enabled-site link back at its previous target, and reloads nginx onto it', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $link = $scratch.'/fs/etc/nginx/sites-enabled/rateguru-staging';

        unlink($link);
        symlink('/etc/nginx/sites-available/default', $link);
        bsvcRollbackFailMailGateway($scratch);

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('link:/etc/nginx/sites-enabled/rateguru-staging repointing at /etc/nginx/sites-available/rateguru-staging')
            ->toContain('install-mail-gateway --apply failed')
            ->toContain('rollback complete')
            ->toContain('rollback: nginx reloaded with the restored configuration');

        // The site the host was serving before the apply is the one it serves
        // after it: the link names its old target again, as a link.
        expect(is_link($link))->toBeTrue();
        expect(readlink($link))->toBe('/etc/nginx/sites-available/default');

        // nginx was reloaded onto the candidate link, so it is reloaded again
        // onto the restored one — never left serving what was rolled back.
        $reloads = array_values(array_filter(
            bsvcSystemctlMutations($scratch),
            fn (string $call): bool => $call === 'systemctl reload nginx',
        ));
        expect($reloads)->toHaveCount(2);
    } finally {
        bsvcCleanup($scratch);
    }
});

it('restores everything it still can, and says the rollback is incomplete, when a backup disappears mid-apply', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        // Two drifted service files, so the apply backs up and replaces both:
        // the SSH restriction first, the scheduler last.
        $restriction = '/etc/ssh/sshd_config.d/70-rateguru-deploy.conf';
        $scheduler = '/etc/cron.d/rateguru-staging-scheduler';
        file_put_contents($fs.$restriction, "# previous ssh restriction\n");
        file_put_contents($fs.$scheduler, "# previous scheduler\n");

        // The SSH restriction's backup is removed while the apply is still
        // running, after the file itself was replaced.
        bsvcRollbackFailMailGateway(
            $scratch,
            'rm -f '.escapeshellarg($fs.'/var/backups/rateguru-bootstrap-services').'/*'.escapeshellarg($restriction),
        );

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('install-mail-gateway --apply failed')
            ->toContain("ROLLBACK ERROR: no backup found for {$restriction}")
            ->not->toContain('rollback complete');

        // The operator is told where the surviving backups are, and they are
        // there.
        expect(preg_match('/ROLLBACK INCOMPLETE — backups remain in (\S+) for manual recovery/', $output, $matches))
            ->toBe(1, $output);
        expect(file_get_contents($matches[1].$scheduler))->toBe("# previous scheduler\n");

        // One missing backup does not stop the rest of the rollback: the file
        // recorded after it is back exactly as it was.
        expect(file_get_contents($fs.$scheduler))->toBe("# previous scheduler\n");

        // The file with no backup is left as the apply installed it — the
        // committed restriction, whole — rather than removed or half-written.
        expect(file_get_contents($fs.$restriction))
            ->toBe(File::get(base_path('infrastructure/config/ssh/70-rateguru-deploy.conf')));
    } finally {
        bsvcCleanup($scratch);
    }
});

it('never pushes a restored supervisor program its parser now rejects back into the running supervisor', function () {
    $scratch = bsvcScratchDir();

    try {
        // A DEPLOYED host whose queue program drifted: the apply installs the
        // committed one and pushes it into the running supervisor.
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $conf = $scratch.'/fs/etc/supervisor/conf.d/rateguru-staging-queue.conf';
        $previous = "# previous installed supervisor configuration\n";
        file_put_contents($conf, $previous);

        // While the apply is still running, supervisor starts rejecting the
        // configuration it reads.
        bsvcRollbackFailMailGateway($scratch, 'touch '.escapeshellarg($scratch.'/toggles/supervisor-reread-fail'));

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('queue:rateguru-staging-queue applying updated program configuration (supervisorctl update)')
            ->toContain('rollback complete')
            ->toContain('ROLLBACK ERROR: cannot re-apply supervisor program rateguru-staging-queue (supervisor inactive or restored configuration invalid)')
            ->not->toContain('re-applied from the restored configuration');

        // The file itself is restored...
        expect(file_get_contents($conf))->toBe($previous);

        // ...but validation came first, failed, and nothing was applied after
        // it: the only update is the one the apply itself made.
        $supervisorctl = bsvcLog($scratch, 'supervisorctl.log');
        expect(substr_count($supervisorctl, 'supervisorctl update'))->toBe(1);
        expect(strrpos($supervisorctl, 'supervisorctl reread'))
            ->toBeGreaterThan(strrpos($supervisorctl, 'supervisorctl update'));
    } finally {
        bsvcCleanup($scratch);
    }
});
