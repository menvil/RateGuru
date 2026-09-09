<?php

use Illuminate\Support\Facades\File;

/**
 * The offsite-write hold: one deterministic marker under the operational run
 * root that fences every writer of the offsite backup namespace on a machine
 * a target was recovered onto.
 *
 * A recovered machine is a complete host: it carries the offsite credential,
 * the backup cron entry and the retention pruner, and — while the target is
 * still bound to the long-lived host — it must never upload into, or prune,
 * the namespace that host owns. The hold is placed by the recovery
 * preparation and by the recovery itself, proven by every later recovery
 * mode, and released by nobody: releasing it is part of deliberately adopting
 * the machine.
 */
it('composes one deterministic hold path under the run root, and reads it without touching it', function () {
    $scratch = restoreScratchDir();

    try {
        $runRoot = $scratch.'/run';
        mkdir($runRoot, 0o700, true);

        [$exit, $output] = commonFunctionHarness($scratch, implode("\n", [
            'offsite_write_hold_file /var/run/rateguru',
            'offsite_write_hold_file /var/run/rateguru/',
            'offsite_writes_held '.escapeshellarg($runRoot).' && echo held || echo free',
            'assert_no_offsite_write_hold '.escapeshellarg($runRoot).' "an upload" && echo allowed',
            'printf \'{"hold":"offsite-writes"}\' > '.escapeshellarg($runRoot.'/offsite-write-hold'),
            'offsite_writes_held '.escapeshellarg($runRoot).' && echo held || echo free',
            // fail() exits the shell it runs in, so the refusal is observed
            // from a subshell.
            '( assert_no_offsite_write_hold '.escapeshellarg($runRoot).' "an upload" ) || echo "refused: $?"',
        ]));

        expect($exit)->toBe(0, $output);

        $lines = array_values(array_filter(preg_split('/\R/', $output)));

        expect($lines[0])->toBe('/var/run/rateguru/offsite-write-hold');
        expect($lines[1])->toBe('/var/run/rateguru/offsite-write-hold');
        expect($lines[2])->toBe('free');
        expect($lines[3])->toBe('allowed');
        expect($lines[4])->toBe('held');

        expect($output)
            ->toContain('OFFSITE WRITES: HELD — an upload is refused on this host')
            ->toContain($runRoot.'/offsite-write-hold')
            ->toContain('refused: 1');

        // The marker is still there: reading it never removes it.
        expect(File::exists($runRoot.'/offsite-write-hold'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('states the path once in common, and the scripts that cannot source common repeat it exactly', function () {
    $common = File::get(base_path('infrastructure/scripts/common'));
    $prepare = File::get(base_path('infrastructure/scripts/prepare-host'));
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));

    expect(shellFunctionBody($common, 'offsite_write_hold_file'))
        ->toContain("printf '%s/offsite-write-hold\\n' \"\${run_root%/}\"");

    // prepare-host runs on a clean host without common: the same composition,
    // from the same run root constant bootstrap-host lays out.
    expect(shellFunctionBody($prepare, 'offsite_write_hold_path'))
        ->toContain("printf '%s/offsite-write-hold\\n' \"\${OPERATIONAL_RUN_ROOT%/}\"");
    expect($prepare)->toContain('OPERATIONAL_RUN_ROOT="$(gated_default RATEGURU_PREPAREHOST_RUN_ROOT /home/www/rateguru/run)"');

    // recover-host sources common and composes nothing of its own.
    expect($recover)->toContain('offsite_write_hold_file "${RUN_ROOT}"')
        ->not->toContain("'offsite-write-hold'")
        ->not->toContain('"offsite-write-hold"');

    // The run root the marker lives under is the one every operational
    // script already shares: the writers, the restore library and the
    // preparation all default to the same directory.
    foreach (['backup-cycle', 'offsite-backup', 'offsite-retention'] as $writer) {
        expect(File::get(base_path('infrastructure/scripts/'.$writer)))
            ->toContain('RUN_ROOT_DEFAULT="/home/www/rateguru/run"');
    }

    expect(File::get(base_path('infrastructure/scripts/restore-common')))
        ->toContain('RUN_ROOT="$(restore_gated_default RATEGURU_RUN_ROOT /home/www/rateguru/run)"');
});

it('fences exactly the writers of the offsite namespace, and nothing that only reads it', function () {
    // The writers: the cron entry's coordinator, the uploader and the pruner.
    foreach ([
        'backup-cycle' => 'a backup cycle',
        'offsite-backup' => 'an offsite backup upload',
        'offsite-retention' => 'offsite retention',
    ] as $script => $operation) {
        $source = executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script)));

        expect(substr_count($source, 'assert_no_offsite_write_hold'))->toBe(1, "{$script} must refuse on the hold exactly once");
        expect($source)->toContain('assert_no_offsite_write_hold "${RUN_ROOT}" "'.$operation.'"');
    }

    // Readers and local-only writers are untouched: a held machine still
    // takes local backups, still tests them, and still reads the namespace.
    foreach ([
        'backup', 'restore-test', 'offsite-restore-test', 'fetch-backup', 'verify-backup',
        'restore-database', 'restore-storage', 'restore-target', 'deploy', 'rollback',
        'health-check', 'status', 'cleanup', 'fetch-recovery-material',
    ] as $script) {
        expect(File::get(base_path('infrastructure/scripts/'.$script)))
            ->not->toContain('assert_no_offsite_write_hold');
    }

    // The retention pruner refuses even its dry run: a held machine has no
    // business computing what it would prune from a namespace it does not own.
    // The refusal comes before the first thing perform_offsite_retention
    // checks or does — the credential check, the listing and the lock.
    $body = shellFunctionBody(File::get(base_path('infrastructure/scripts/offsite-retention')), 'perform_offsite_retention');
    $refusal = mb_strpos($body, 'assert_no_offsite_write_hold "${RUN_ROOT}" "offsite retention"');

    expect($refusal)->not->toBeFalse();

    foreach (['[[ -f "${RCLONE_CONFIG}" ]]', 'flock', 'lsf', 'list_remote', 'WOULD DELETE'] as $later) {
        $position = mb_strpos($body, $later);

        if ($position !== false) {
            expect($refusal)->toBeLessThan($position, "the hold must be checked before: {$later}");
        }
    }
});

it('is placed by the recovery preparation and the recovery, and released by no script at all', function () {
    $prepare = File::get(base_path('infrastructure/scripts/prepare-host'));
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));

    expect($prepare)->toContain('--arg created_by "prepare-host --recovery-backup"');
    // The recovery names the mode that placed it: the apply by default, and
    // the resume when it re-establishes a hold that went missing.
    expect($recover)->toContain('local placed_by="${1:-recover-host --apply}"')
        ->toContain('ensure_offsite_writes_held "recover-host --resume"');

    // Every recovery mode after --apply proves the hold rather than trusting it.
    foreach (['assert_runtime_still_held', 'assert_runtime_resumed'] as $proof) {
        expect(shellFunctionBody($recover, $proof))->toContain('assert_offsite_writes_held');
    }

    foreach (glob(base_path('infrastructure/scripts/*')) ?: [] as $path) {
        if (! is_file($path)) {
            continue;
        }

        foreach (preg_split('/\R/', executableSourceLines(File::get($path))) as $line) {
            if (! str_contains($line, 'offsite-write-hold') && ! str_contains($line, 'offsite_write_hold')) {
                continue;
            }

            expect($line)->not->toMatch('/\brm\b/', basename($path).' must never remove the hold: '.$line)
                ->not->toMatch('/\bmv\b/')
                ->not->toMatch('/\bunlink\b/');
        }
    }

    // The ordinary cron entry is untouched: the hold is a fence the writers
    // check, not a deleted, renamed or rewritten file.
    $cron = File::get(base_path('infrastructure/config/cron/rateguru-backups'));

    expect($cron)->toContain('backup-cycle')
        ->not->toContain('offsite-write-hold');
});

it('is what the recovery workflows and the runbooks call the same thing', function () {
    $action = File::get(base_path('.github/actions/recover-rateguru-host/action.yml'));

    expect($action)->toContain('offsite-writes')
        ->toContain('.offsite_writes');

    foreach (['recover-staging.yml', 'recover-production.yml'] as $workflow) {
        $source = File::get(base_path('.github/workflows/'.$workflow));

        expect($source)->toContain('offsite_writes')
            ->toContain('"${OFFSITE_WRITES}" != "held"');
    }

    foreach ([
        'infrastructure/runbooks/recover-host.md',
        'infrastructure/runbooks/github-recover.md',
        'infrastructure/runbooks/backups.md',
    ] as $runbook) {
        expect(File::get(base_path($runbook)))->toContain('OFFSITE WRITES: HELD');
    }
});
