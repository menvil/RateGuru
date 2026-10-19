<?php

use App\Actions\Personas\SetPersonaAvatarAction;
use App\Actions\Personas\UpdatePersonaAction;
use App\Data\Personas\PersonaData;
use App\Enums\UserStatus;
use App\Models\PersonaProfile;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * An administrator's edit of a Persona and a sanction of the same Persona by
 * the same administrator lock the same two user rows. A sanction
 * (ExecutesUserStatusTransition) locks them in ascending id order; an edit that
 * locked its administrator first would hold one row while the sanction held
 * the other, each then waiting for the row the other holds — a deadlock,
 * whenever the Persona's id is the lower. Content Studio's actions lock both
 * rows through LocksUsersInOrder, so the two take turns instead.
 */

/**
 * One competitor: a PHP process of its own running one action, stopped at
 * its first user lock until the other is there too.
 *
 * Both announce themselves just before that first lock and wait for each
 * other, so both are demonstrably about to lock before either does. Once its
 * first lock is taken, each waits again — until the other holds its own first
 * lock, or is seen waiting for this one (`pg_blocking_pids`). Locking the same
 * row first, the second waits behind the first and they take turns; locking
 * different rows first, both hold one and want the other, and PostgreSQL
 * kills one of them with a deadlock. Nobody sleeps for a guessed length of
 * time, and a peer that never arrives is a loud failure.
 *
 * @param  array{action: string, admin: int, persona: int, disk: string}  $job
 * @return array{process: resource, pipes: array<int, resource>}
 */
function personaLockCompetitor(string $barrierDir, string $label, array $job): array
{
    $script = <<<'PHP'
        <?php
        [$barrierDir, $base, $label, $job] = [$argv[1], $argv[2], $argv[3], json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR)];
        require $base.'/vendor/autoload.php';
        $app = require_once $base.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // A stored photo lands in the test's scratch directory, and nothing is queued.
        config([
            'filesystems.disks.'.config('media.disks.public').'.root' => $job['disk'],
            'queue.connections.null' => ['driver' => 'null'],
            'queue.default' => 'null',
        ]);

        $db = Illuminate\Support\Facades\DB::connection();
        $peer = $label === 'a' ? 'b' : 'a';
        $pid = (int) $db->selectOne('select pg_backend_pid() as pid')->pid;
        file_put_contents("{$barrierDir}/pid-{$label}", (string) $pid);

        $await = static function (Closure $arrived, string $failure): void {
            $deadline = microtime(true) + 20;

            while (microtime(true) < $deadline) {
                if ($arrived()) {
                    return;
                }

                usleep(1000);
            }

            throw new RuntimeException($failure);
        };

        $isUserLock = static fn (string $sql): bool => preg_match('/\bfrom\s+"?users"?\s/i', $sql) === 1
            && str_contains(strtolower($sql), 'for update');

        $stage = 'before';

        $db->beforeExecuting(function (string $sql) use (&$stage, $isUserLock, $await, $barrierDir, $label, $peer): void {
            if ($stage !== 'before' || ! $isUserLock($sql)) {
                return;
            }

            $stage = 'locking';
            touch("{$barrierDir}/ready-{$label}");
            $await(fn (): bool => is_file("{$barrierDir}/ready-{$peer}"), 'the other competitor never reached its first user lock');
        });

        $db->listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$stage, $isUserLock, $await, $barrierDir, $label, $peer, $pid, $db): void {
            if ($stage !== 'locking' || ! $isUserLock($query->sql)) {
                return;
            }

            $stage = 'holding';
            touch("{$barrierDir}/holding-{$label}");
            $peerPid = (int) file_get_contents("{$barrierDir}/pid-{$peer}");

            $await(
                fn (): bool => is_file("{$barrierDir}/holding-{$peer}")
                    || (bool) $db->selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$pid, $peerPid])->blocked,
                'the other competitor neither took its first user lock nor waited for this one',
            );
        });

        $admin = App\Models\User::query()->findOrFail($job['admin']);
        $persona = App\Models\User::query()->with('personaProfile')->findOrFail($job['persona']);
        $drawn = App\Data\Personas\PersonaData::of($persona, $persona->personaProfile);

        try {
            match ($job['action']) {
                'edit' => app(App\Actions\Personas\UpdatePersonaAction::class)->handle(
                    $admin, $persona, new App\Data\Personas\PersonaData(displayName: $drawn->displayName, username: $drawn->username, character: 'Raced'), $drawn->fingerprint(),
                ),
                'photo' => app(App\Actions\Personas\SetPersonaAvatarAction::class)->handle(
                    $admin, $persona, Illuminate\Http\UploadedFile::fake()->image('raced.jpg', 64, 64), $drawn->fingerprint(), $persona->avatar_asset_id,
                ),
                'ban' => app(App\Actions\Moderation\BanUserAction::class)->handle($admin, $persona, 'Raced'),
                'limit' => app(App\Actions\Moderation\LimitUserAction::class)->handle($admin, $persona, 'Raced'),
                'restore' => app(App\Actions\Moderation\RestoreUserAccessAction::class)->handle($admin, $persona, 'Raced'),
            };

            fwrite(STDOUT, "ok\n");
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDOUT, 'failed: '.get_class($e).': '.$e->getMessage()."\n");
            exit(1);
        }
        PHP;

    $scriptPath = $barrierDir."/competitor-{$label}.php";
    file_put_contents($scriptPath, $script);

    $process = proc_open(
        [PHP_BINARY, $scriptPath, $barrierDir, base_path(), $label, json_encode($job, JSON_THROW_ON_ERROR)],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        racingEnvironment(),
    );

    expect($process)->not->toBeFalse("the {$label} competitor must start");

    return ['process' => $process, 'pipes' => $pipes];
}

it('locks the administrator and the persona in ascending id order, as a sanction does', function (Closure $act, bool $personaFirst) {
    Storage::fake('public');

    [$persona, $admin] = $personaFirst
        ? [PersonaProfile::factory()->for(User::factory()->persona(), 'user')->create()->user, User::factory()->admin()->create()]
        : array_reverse([User::factory()->admin()->create(), PersonaProfile::factory()->for(User::factory()->persona(), 'user')->create()->user]);

    /** @var list<int> $locked */
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        // Identifier quoting is stripped rather than matched: PostgreSQL
        // quotes "users", MariaDB `users`.
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (preg_match('/\bfrom users\b/', $sql) === 1 && str_contains($sql, 'for update')) {
            $locked[] = (int) $query->bindings[0];
        }
    });

    $act($admin, $persona->load('personaProfile'));

    expect($locked)->toBe([min($persona->id, $admin->id), max($persona->id, $admin->id)]);
})->with([
    'an edit' => [fn (User $admin, User $persona) => app(UpdatePersonaAction::class)->handle($admin, $persona, new PersonaData(displayName: 'Night Owl', username: $persona->username, character: 'Ordered'), PersonaData::of($persona, $persona->personaProfile)->fingerprint())],
    'a new photo' => [fn (User $admin, User $persona) => app(SetPersonaAvatarAction::class)->handle($admin, $persona, UploadedFile::fake()->image('face.jpg', 64, 64), PersonaData::of($persona, $persona->personaProfile)->fingerprint(), null)],
])->with([
    'the persona made first' => [true],
    'the administrator made first' => [false],
])->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL, and one writer holds the whole database at a time — so there is no order to observe.',
);

it('lets an administrator edit a persona while sanctioning it, the two taking turns rather than deadlocking', function (string $edit, string $sanction, string $from, UserStatus $to) {
    $db = committedConnection();

    // The Persona first, so its id is the lower: the sanction locks it before
    // its administrator, the order the edit has to agree with.
    $persona = User::factory()->persona()->connection('committed_peer')->create(['status' => UserStatus::from($from), 'display_name' => 'Night Owl']);
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    PersonaProfile::factory()->connection('committed_peer')->create(['user_id' => $persona->id, 'character' => 'Original']);
    expect($persona->id)->toBeLessThan($admin->id);

    $barrierDir = sys_get_temp_dir().'/rateguru-persona-race-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($barrierDir.'/disk', 0o700);

    $results = [];

    try {
        $competitors = [
            personaLockCompetitor($barrierDir, 'a', ['action' => $edit, 'admin' => $admin->id, 'persona' => $persona->id, 'disk' => $barrierDir.'/disk']),
            personaLockCompetitor($barrierDir, 'b', ['action' => $sanction, 'admin' => $admin->id, 'persona' => $persona->id, 'disk' => $barrierDir.'/disk']),
        ];

        foreach ($competitors as $competitor) {
            $output = trim((string) stream_get_contents($competitor['pipes'][1]));
            $stderr = trim((string) stream_get_contents($competitor['pipes'][2]));
            fclose($competitor['pipes'][1]);
            fclose($competitor['pipes'][2]);
            $results[] = ['output' => $output, 'stderr' => $stderr, 'status' => proc_close($competitor['process'])];
        }

        $report = collect($results)
            ->map(fn (array $r, int $i): string => "competitor {$i}: status={$r['status']} {$r['output']} {$r['stderr']}")
            ->implode("\n");

        // Both must have been at their first lock together, or there was no race.
        expect(str_contains($report, 'never reached its first user lock'))->toBeFalse("a competitor raced nobody:\n{$report}");

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(0, "competitor {$index} must finish, not deadlock:\n{$report}");
        }

        $after = $db->table('users')->where('id', $persona->id)->first();

        expect($after->status)->toBe($to->value)
            ->and($db->table('moderation_logs')->where('moderator_id', $admin->id)->count())->toBe(1);

        if ($edit === 'edit') {
            expect($db->table('persona_profiles')->where('user_id', $persona->id)->value('character'))->toBe('Raced');
        } else {
            expect($db->table('media_assets')->where('id', $after->avatar_asset_id)->value('owner_user_id'))->toBe($persona->id);
        }
    } finally {
        // The competitors committed for real, so the cleanup has to as well.
        $db->table('users')->whereIn('id', [$persona->id, $admin->id])->update(['avatar_asset_id' => null]);
        $db->table('media_assets')->where('owner_user_id', $persona->id)->delete();
        $db->table('moderation_logs')->where('moderator_id', $admin->id)->delete();
        $db->table('persona_profiles')->where('user_id', $persona->id)->delete();
        $db->table('users')->whereIn('id', [$persona->id, $admin->id])->delete();
        File::deleteDirectory($barrierDir);
    }

    expect($db->table('users')->whereIn('id', [$persona->id, $admin->id])->exists())->toBeFalse('the race must leave the worker database as it found it');
})->with([
    'an edit, while the persona is banned' => ['edit', 'ban', 'active', UserStatus::Banned],
    'a new photo, while the persona is limited' => ['photo', 'limit', 'active', UserStatus::Limited],
    'an edit, while the persona\'s access is restored' => ['edit', 'restore', 'limited', UserStatus::Active],
])->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. The order itself is asserted on every engine that locks rows by the test above. PostgreSQL is the primary runtime (docs/architecture/database-support.md).',
);
