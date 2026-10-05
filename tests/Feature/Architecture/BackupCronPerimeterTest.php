<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * The backup cron decides which targets are backed up, restore-tested and
 * offsite-verified every night and every week, as root. It used to be a
 * committed file naming one target, checked against that target's three
 * literal lines — a shape that cannot schedule a second target without
 * somebody remembering to, and cannot notice a target scheduled before it is
 * active.
 *
 * It is rendered now, the way the sudoers rule is: WHICH targets come from
 * the registry's lifecycle, WHEN from a reviewed schedule file. These tests
 * drive the shipped renderer (perimeterRender / perimeterRun, in
 * tests/Pest.php) with fixture registries, and never change the real one.
 */
function backupCronStagingLines(): array
{
    return [
        '30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1',
        '10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1',
        '40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1',
    ];
}

function backupCronProductionLines(): array
{
    return [
        '0 3 * * * root /home/www/rateguru/bin/backup-cycle --target tits-guru >> /var/log/rateguru/tits-guru-backup-cycle.log 2>&1',
        '10 5 * * 0 root /home/www/rateguru/bin/restore-test --target tits-guru >> /var/log/rateguru/tits-guru-local-restore-test.log 2>&1',
        '40 5 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target tits-guru >> /var/log/rateguru/tits-guru-offsite-restore-test.log 2>&1',
    ];
}

function backupCronRender(array $registry, ?array $schedules = null): string
{
    return perimeterRender($registry, 'render_backup_cron_candidate', $schedules);
}

/**
 * Apply dot-path changes to a fixture; a null value removes the key.
 */
function backupCronChanged(array $data, array $changes): array
{
    foreach ($changes as $path => $value) {
        $value === null ? Arr::forget($data, $path) : data_set($data, $path, $value);
    }

    return $data;
}

function backupCronWithLifecycle(string $target, string $lifecycle): array
{
    $registry = perimeterRegistry();
    $registry['targets'][$target]['lifecycle'] = $lifecycle;

    return $registry;
}

// --- what is committed --------------------------------------------------------

it('commits exactly what the registry and the reviewed schedules render', function () {
    // The committed file stays committed so the cron is reviewable in a diff,
    // and is proved to BE the render so the two cannot drift.
    expect(backupCronRender(perimeterRegistry()))
        ->toBe(File::get(base_path('infrastructure/config/cron/rateguru-backups')));
});

it('schedules staging alone while tits-guru is planned, with its schedules and log paths unchanged', function () {
    foreach ([
        'render' => backupCronRender(perimeterRegistry()),
        'committed' => File::get(base_path('infrastructure/config/cron/rateguru-backups')),
    ] as $which => $cron) {
        expect(cronOperationalLines($cron))->toBe(backupCronStagingLines(), "{$which} cron");

        // Not one runnable line for the planned target, in any form.
        foreach (cronOperationalLines($cron) as $line) {
            expect($line)->not->toContain('tits-guru');
        }
    }
});

it('leaves the real tits-guru planned', function () {
    // This change makes production backups READY to schedule. Scheduling them
    // is the reviewed activation, which is not this change.
    expect(perimeterRegistry()['targets']['tits-guru']['lifecycle'])->toBe('planned');
});

// --- activation ---------------------------------------------------------------

it('adds tits-guru\'s three jobs the moment the registry says it is active', function () {
    // The proof that activation is a lifecycle change and nothing else: the
    // identical renderer and schedule file, a registry whose only difference
    // is one lifecycle value, and three more runnable lines.
    $cron = backupCronRender(backupCronWithLifecycle('tits-guru', 'active'));

    expect(cronOperationalLines($cron))
        ->toBe([...backupCronStagingLines(), ...backupCronProductionLines()]);
});

it('renders an activated tits-guru that its own independent content check accepts', function () {
    // validate_backup_cron_content reads the file against the registry without
    // trusting the renderer, so the two agreeing on two targets is a real
    // cross-check rather than the renderer marking its own work.
    [$exit, $output] = perimeterRun(
        'render_backup_cron_candidate > activated.cron && validate_backup_cron activated.cron rendered && echo VALID',
        backupCronWithLifecycle('tits-guru', 'active'),
    );

    expect($exit)->toBe(0, $output);
    expect($output)->toContain('VALID');
});

it('schedules no target that is disabled', function () {
    $stagingOnly = backupCronRender(backupCronWithLifecycle('tits-guru', 'disabled'));

    expect(cronOperationalLines($stagingOnly))->toBe(backupCronStagingLines());

    // And the other way round: disabling staging withdraws staging's jobs
    // without touching the active brand's.
    $registry = backupCronWithLifecycle('tits-guru', 'active');
    $registry['targets']['staging-main']['lifecycle'] = 'disabled';

    expect(cronOperationalLines(backupCronRender($registry)))->toBe(backupCronProductionLines());
});

it('refuses a cron that runs a job for a target that is not active', function (string $lifecycle) {
    // The independent check, given a file the renderer would never produce: an
    // activated render validated against a registry where tits-guru is not
    // active.
    [, $activated] = perimeterRun('render_backup_cron_candidate', backupCronWithLifecycle('tits-guru', 'active'));

    [$exit, $output] = perimeterRun(
        'printf %s '.escapeshellarg($activated).' > scheduled.cron && validate_backup_cron_content scheduled.cron',
        backupCronWithLifecycle('tits-guru', $lifecycle),
    );

    expect($exit)->not->toBe(0);
    expect($output)->toContain("cron runs a backup job for tits-guru, whose target is lifecycle={$lifecycle}");
})->with(['planned', 'disabled']);

// --- what each line says --------------------------------------------------------

it('names log files by the registry backup namespace, never by the environment class', function () {
    // Renaming the namespace in the fixture moves the logs with it, which is
    // what proves the name is read from the registry and not spelled out per
    // brand or derived from "production".
    $registry = backupCronWithLifecycle('tits-guru', 'active');
    $registry['targets']['tits-guru']['backup']['namespace'] = 'tits-archive';

    $production = array_values(array_filter(
        cronOperationalLines(backupCronRender($registry)),
        fn (string $line): bool => str_contains($line, '--target tits-guru '),
    ));

    expect($production)->toBe([
        '0 3 * * * root /home/www/rateguru/bin/backup-cycle --target tits-guru >> /var/log/rateguru/tits-archive-backup-cycle.log 2>&1',
        '10 5 * * 0 root /home/www/rateguru/bin/restore-test --target tits-guru >> /var/log/rateguru/tits-archive-local-restore-test.log 2>&1',
        '40 5 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target tits-guru >> /var/log/rateguru/tits-archive-offsite-restore-test.log 2>&1',
    ]);

    foreach ($production as $line) {
        expect($line)->not->toContain('production');
    }
});

it('selects every job by --target alone, with no --environment anywhere', function () {
    $activated = backupCronRender(backupCronWithLifecycle('tits-guru', 'active'));

    foreach ([
        'activated render' => $activated,
        'committed cron' => File::get(base_path('infrastructure/config/cron/rateguru-backups')),
        'schedule file' => File::get(base_path('infrastructure/config/backup-schedules.json')),
    ] as $source) {
        expect($source)->not->toContain('--environment');
    }

    foreach (cronOperationalLines($activated) as $line) {
        expect(substr_count($line, '--target '))->toBe(1, $line);
    }
});

it('pins the reviewed production schedule, apart from staging', function () {
    $schedules = perimeterBackupSchedules();

    expect($schedules['timezone'])->toBe('UTC');

    expect($schedules['targets']['tits-guru'])->toBe([
        'backup_cycle_at' => '03:00',
        'verification_weekday' => 'sunday',
        'restore_test_at' => '05:10',
        'offsite_restore_test_at' => '05:40',
    ]);

    expect($schedules['targets']['staging-main'])->toBe([
        'backup_cycle_at' => '02:30',
        'verification_weekday' => 'sunday',
        'restore_test_at' => '04:10',
        'offsite_restore_test_at' => '04:40',
    ]);
});

it('names no target in the renderer, so another brand needs a schedule and no code', function () {
    $source = File::get(base_path('infrastructure/scripts/install-target-perimeter'));

    $start = mb_strpos($source, 'BACKUP_CRON_BIN_DIR=');
    $end = mb_strpos($source, 'run_source_validation() {');

    expect($start)->not->toBeFalse();
    expect($end)->toBeGreaterThan($start);

    $renderer = executableSourceLines(mb_substr($source, $start, $end - $start));

    foreach (['tits-guru', 'tits', 'staging', 'production', 'food-guru', 'animals-guru'] as $name) {
        expect(str_contains($renderer, $name))
            ->toBeFalse("the backup cron renderer must not name a target or class: {$name}");
    }

    expect($renderer)
        ->toContain('select(.value.lifecycle == "active")')
        ->toContain('.targets[$id].backup.namespace');
});

// --- what is refused ------------------------------------------------------------

it('refuses an active target that has no reviewed schedule', function () {
    $schedules = perimeterBackupSchedules();
    unset($schedules['targets']['tits-guru']);

    [$exit, $output] = perimeterRun('render_backup_cron_candidate', backupCronWithLifecycle('tits-guru', 'active'), $schedules);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('target tits-guru is lifecycle=active but has no reviewed backup schedule');

    // While it is planned, an unreviewed schedule is simply not needed yet.
    [$plannedExit, $plannedOutput] = perimeterRun('render_backup_cron_candidate', perimeterRegistry(), $schedules);

    expect($plannedExit)->toBe(0, $plannedOutput);
    expect(cronOperationalLines($plannedOutput))->toBe(backupCronStagingLines());
});

it('refuses a schedule for a target the registry does not know', function () {
    $schedules = perimeterBackupSchedules();
    $schedules['targets']['tits-gru'] = $schedules['targets']['tits-guru'];
    $schedules['targets']['tits-gru']['backup_cycle_at'] = '03:20';
    $schedules['targets']['tits-gru']['restore_test_at'] = '05:20';
    $schedules['targets']['tits-gru']['offsite_restore_test_at'] = '05:50';

    [$exit, $output] = perimeterRun('render_backup_cron_candidate', perimeterRegistry(), $schedules);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('backup schedule names tits-gru, which is not a target in the registry');
});

it('refuses a schedule entry that is not exactly the reviewed shape', function (array $changes, string $message) {
    [$exit, $output] = perimeterRun('render_backup_cron_candidate', perimeterRegistry(), backupCronChanged(perimeterBackupSchedules(), $changes));

    expect($exit)->not->toBe(0);
    expect($output)->toContain($message);
})->with([
    'a time that is not HH:MM' => [
        ['targets.tits-guru.backup_cycle_at' => '3:00'],
        "backup_cycle_at '3:00' is not an HH:MM time",
    ],
    'an hour past 23' => [
        ['targets.tits-guru.restore_test_at' => '24:10'],
        "restore_test_at '24:10' is not an HH:MM time",
    ],
    'a cron expression smuggled in as a time' => [
        ['targets.tits-guru.offsite_restore_test_at' => '* * * * *'],
        "offsite_restore_test_at '* * * * *' is not an HH:MM time",
    ],
    'a weekday that is not a name' => [
        ['targets.tits-guru.verification_weekday' => '0'],
        "verification_weekday '0' is not a lowercase weekday name",
    ],
    'a misspelt key' => [
        ['targets.tits-guru.restore_test_at' => null, 'targets.tits-guru.restore_tset_at' => '05:10'],
        'backup schedule for tits-guru must declare exactly backup_cycle_at, restore_test_at, offsite_restore_test_at and verification_weekday',
    ],
    'a number where a time belongs' => [
        ['targets.tits-guru.backup_cycle_at' => 300],
        'all strings',
    ],
    'a timezone other than UTC' => [
        ['timezone' => 'Europe/Kyiv'],
        'must be exactly {schema_version: 1, timezone: "UTC"',
    ],
]);

it('refuses two backup jobs that would start in the same minute', function (array $times, string $collision) {
    $schedules = perimeterBackupSchedules();
    $schedules['targets']['tits-guru'] = array_merge($schedules['targets']['tits-guru'], $times);

    [$exit, $output] = perimeterRun('render_backup_cron_candidate', perimeterRegistry(), $schedules);

    expect($exit)->not->toBe(0);
    expect($output)->toContain("two backup jobs are scheduled to start in the same minute ({$collision})");
})->with([
    // The likeliest mistake when a target is added: its block copied from
    // staging's. Checked while tits-guru is still planned, because that is
    // when its schedule is reviewed.
    'a copy of staging\'s schedule' => [
        ['backup_cycle_at' => '02:30', 'restore_test_at' => '04:10', 'offsite_restore_test_at' => '04:40'],
        'friday 02:30 UTC: staging-main backup-cycle, tits-guru backup-cycle',
    ],
    'a nightly cycle on top of a weekly verification' => [
        ['backup_cycle_at' => '04:10'],
        'sunday 04:10 UTC: staging-main restore-test, tits-guru backup-cycle',
    ],
    'one target\'s own pair' => [
        ['offsite_restore_test_at' => '05:10'],
        'sunday 05:10 UTC: tits-guru restore-test, tits-guru offsite-restore-test',
    ],
]);

it('refuses a namespace that is not safe to put in a root cron line', function (string $namespace) {
    $registry = backupCronChanged(backupCronWithLifecycle('tits-guru', 'active'), [
        'targets.tits-guru.backup.namespace' => $namespace,
    ]);

    [$exit, $output] = perimeterRun('render_backup_cron_candidate', $registry);

    expect($exit)->not->toBe(0);
    expect($output)->toContain("backup namespace '{$namespace}' is not a renderable log name");
})->with([
    'a path' => ['../../etc/cron.d/x'],
    'a second command' => ['tits; rm -rf /'],
    'a redirect' => ['tits > /etc/passwd'],
]);

it('refuses a registry with no active target rather than installing a cron that runs nothing', function () {
    $registry = backupCronWithLifecycle('staging-main', 'planned');

    [$exit, $output] = perimeterRun('render_backup_cron_candidate', $registry);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('no active target');
});
