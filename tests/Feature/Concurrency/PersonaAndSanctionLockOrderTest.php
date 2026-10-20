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
 *
 * The competitors meet at their first user lock (lockRaceCompetitor()).
 */

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

    $barrierDir = raceDirectory('persona');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($barrierDir, 'a', ['action' => $edit, 'admin' => $admin->id, 'persona' => $persona->id, 'disk' => $barrierDir.'/disk']),
            lockRaceCompetitor($barrierDir, 'b', ['action' => $sanction, 'admin' => $admin->id, 'persona' => $persona->id, 'disk' => $barrierDir.'/disk']),
        ]);

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
