<?php

use App\Actions\Studio\CreateStudioPlanAction;
use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\RemoveStudioPlanImageAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\UserStatus;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Every Content Studio write to a plan locks in the project's order: the
 * `users` rows in ascending id order — the administrator's, and the chosen
 * author's — then the plan. Two writes to one Draft take turns on the plan's
 * row, and the later one, drawn from the version the earlier one replaced, is
 * refused rather than written over it; a write and a sanction of the author
 * lock the same users in the same order, so they take turns instead of
 * deadlocking.
 *
 * The races run as processes of their own (lockRaceCompetitor()): one leads
 * and holds the plan until the other is seen waiting for it, or both meet at
 * their first user lock.
 */

/** Removes what the competitors committed, as they committed it: for real. */
function forgetStudioPlanRace(ConnectionInterface $db, array $userIds, string $directory): void
{
    $assetIds = $db->table('media_assets')->whereIn('owner_user_id', $userIds)->pluck('id');

    $db->table('studio_plans')->whereIn('created_by_user_id', $userIds)->delete();
    $db->table('media_assets')->whereIn('id', $assetIds)->delete();
    $db->table('moderation_logs')->whereIn('moderator_id', $userIds)->delete();
    $db->table('users')->whereIn('id', $userIds)->delete();
    File::deleteDirectory($directory);
}

it('locks the users in ascending id order, then the plan, whatever it writes', function (Closure $act, bool $personaFirst) {
    Storage::fake('public');

    [$persona, $admin] = $personaFirst
        ? [User::factory()->persona()->create(), User::factory()->admin()->create()]
        : array_reverse([User::factory()->admin()->create(), User::factory()->persona()->create()]);
    $plan = StudioPlan::factory()->withImage()->create(['created_by_user_id' => $admin->id]);

    /** @var list<string> $locked */
    $locked = [];

    DB::listen(function (QueryExecuted $query) use (&$locked): void {
        // Identifier quoting is stripped rather than matched: PostgreSQL
        // quotes "users", MariaDB `users`.
        $sql = strtolower(str_replace(['"', '`'], '', $query->sql));

        if (str_contains($sql, 'for update') && preg_match('/\bfrom (users|studio_plans)\b/', $sql, $table) === 1) {
            $locked[] = $table[1].'#'.$query->bindings[0];
        }
    });

    $expected = $act($admin, $persona, $plan);

    expect($locked)->toBe(array_map(fn (array $row): string => $row[0].'#'.$row[1], $expected));
})->with([
    'a new plan with an author' => [function (User $admin, User $persona): array {
        app(CreateStudioPlanAction::class)->handle($admin, new StudioPlanData(authorUserId: $persona->id));

        return [['users', min($admin->id, $persona->id)], ['users', max($admin->id, $persona->id)]];
    }],
    'a save choosing an author' => [function (User $admin, User $persona, StudioPlan $plan): array {
        app(UpdateStudioPlanAction::class)->handle($admin, $plan, new StudioPlanData(authorUserId: $persona->id), 1);

        return [['users', min($admin->id, $persona->id)], ['users', max($admin->id, $persona->id)], ['studio_plans', $plan->id]];
    }],
    'a new photo' => [function (User $admin, User $persona, StudioPlan $plan): array {
        app(SetStudioPlanImageAction::class)->handle($admin, $plan, UploadedFile::fake()->image('photo.jpg', 64, 64), 1);

        return [['users', $admin->id], ['studio_plans', $plan->id]];
    }],
    'a photo taken off' => [function (User $admin, User $persona, StudioPlan $plan): array {
        app(RemoveStudioPlanImageAction::class)->handle($admin, $plan, 1);

        return [['users', $admin->id], ['studio_plans', $plan->id]];
    }],
    'a deleted draft' => [function (User $admin, User $persona, StudioPlan $plan): array {
        app(DeleteStudioPlanDraftAction::class)->handle($admin, $plan, 1);

        return [['users', $admin->id], ['studio_plans', $plan->id]];
    }],
])->with([
    'the persona made first' => [true],
    'the administrator made first' => [false],
])->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL, and one writer holds the whole database at a time — so there is no order to observe.',
);

it('lets two administrators save one draft in turn, refusing the save drawn from the version the first replaced', function () {
    $db = committedConnection();
    [$first, $second] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $first->id, 'title' => 'Original']);
    $directory = raceDirectory('studio-plan');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($directory, 'a', ['action' => 'plan-edit', 'sync' => 'lead', 'admin' => $first->id, 'plan' => $plan->id, 'version' => 1, 'title' => 'First', 'disk' => $directory.'/disk']),
            lockRaceCompetitor($directory, 'b', ['action' => 'plan-edit', 'sync' => 'follow', 'admin' => $second->id, 'plan' => $plan->id, 'version' => 1, 'title' => 'Second', 'disk' => $directory.'/disk']),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(1, $report)
            ->and($results[1]['output'])->toContain('changed by another administrator');

        $after = $db->table('studio_plans')->where('id', $plan->id)->first();

        expect($after->title)->toBe('First')
            ->and((int) $after->lock_version)->toBe(2);
    } finally {
        forgetStudioPlanRace($db, [$first->id, $second->id], $directory);
    }
})->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).',
);

it('lets a new photo and another write to one draft take turns, leaving no photo or file nothing uses', function (string $leader, string $follower, Closure $check) {
    $db = committedConnection();
    [$first, $second] = User::factory()->admin()->count(2)->connection('committed_peer')->create();
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $first->id, 'title' => 'Harbour']);
    $directory = raceDirectory('studio-plan');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($directory, 'a', ['action' => $leader, 'sync' => 'lead', 'admin' => $first->id, 'plan' => $plan->id, 'version' => 1, 'disk' => $directory.'/disk']),
            lockRaceCompetitor($directory, 'b', ['action' => $follower, 'sync' => 'follow', 'admin' => $second->id, 'plan' => $plan->id, 'version' => 1, 'disk' => $directory.'/disk']),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(1, $report);

        $after = $db->table('studio_plans')->where('id', $plan->id)->first();
        $assets = $db->table('media_assets')->whereIn('owner_user_id', [$first->id, $second->id])->get();
        $files = collect(File::allFiles($directory.'/disk'))
            ->map(fn (SplFileInfo $file): string => str_replace($directory.'/disk/', '', $file->getPathname()))
            ->values()
            ->all();

        $check($after, $assets, $files, $results[1]['output']);
    } finally {
        forgetStudioPlanRace($db, [$first->id, $second->id], $directory);
    }
})->with([
    'a photo, then a delete drawn before it' => ['plan-photo', 'plan-delete', function (?object $after, $assets, array $files, string $refusal): void {
        expect($refusal)->toContain('changed by another administrator')
            ->and($after)->not->toBeNull()
            ->and($assets)->toHaveCount(1)
            ->and($assets[0]->deleted_at)->toBeNull()
            ->and((int) $after->image_asset_id)->toBe((int) $assets[0]->id)
            ->and($files)->toBe([$assets[0]->path]);
    }],
    'a delete, then a photo drawn before it' => ['plan-delete', 'plan-photo', function (?object $after, $assets, array $files, string $refusal): void {
        expect($refusal)->toContain('no longer exists')
            ->and($after)->toBeNull()
            ->and($assets)->toBeEmpty()
            ->and($files)->toBe([]);
    }],
    'two photos drawn from the same version' => ['plan-photo', 'plan-photo', function (?object $after, $assets, array $files, string $refusal): void {
        expect($refusal)->toContain('changed by another administrator')
            ->and($assets)->toHaveCount(1)
            ->and($assets[0]->deleted_at)->toBeNull()
            ->and((int) $after->image_asset_id)->toBe((int) $assets[0]->id)
            ->and((int) $after->lock_version)->toBe(2)
            ->and($files)->toBe([$assets[0]->path]);
    }],
])->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).',
);

it('leaves a deleted draft\'s photo released once when Media diagnostics releases it meanwhile', function () {
    $db = committedConnection();
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    $asset = MediaAsset::factory()->postImage()->connection('committed_peer')->create(['owner_user_id' => $admin->id]);
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $admin->id, 'image_asset_id' => $asset->id]);
    $directory = raceDirectory('studio-plan');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            // Holds once it has released the photo, before it commits.
            lockRaceCompetitor($directory, 'a', ['action' => 'plan-delete', 'sync' => 'lead', 'hold' => 'release', 'admin' => $admin->id, 'plan' => $plan->id, 'version' => 1, 'disk' => $directory.'/disk']),
            lockRaceCompetitor($directory, 'b', ['action' => 'media-release', 'sync' => 'follow', 'admin' => $admin->id, 'asset' => $plan->image_asset_id, 'disk' => $directory.'/disk']),
        ]);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(0, "competitor {$index} must finish:\n{$report}");
        }

        $asset = $db->table('media_assets')->where('id', $plan->image_asset_id)->first();

        expect($db->table('studio_plans')->where('id', $plan->id)->exists())->toBeFalse()
            ->and($asset)->not->toBeNull()
            ->and($asset->deleted_at)->not->toBeNull();
    } finally {
        forgetStudioPlanRace($db, [$admin->id], $directory);
    }
})->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).',
);

it('lets an administrator choose a persona as a draft\'s author while sanctioning it, the two taking turns rather than deadlocking', function (string $sanction, string $from, UserStatus $to) {
    $db = committedConnection();

    // The Persona first, so its id is the lower: the sanction locks it before
    // its administrator, the order the save has to agree with.
    $persona = User::factory()->persona()->connection('committed_peer')->create(['status' => UserStatus::from($from)]);
    $admin = User::factory()->admin()->connection('committed_peer')->create();
    $plan = StudioPlan::factory()->connection('committed_peer')->create(['created_by_user_id' => $admin->id, 'title' => 'Harbour']);
    expect($persona->id)->toBeLessThan($admin->id);
    $directory = raceDirectory('studio-plan');

    try {
        ['results' => $results, 'report' => $report] = finishedRace([
            lockRaceCompetitor($directory, 'a', ['action' => 'plan-author', 'admin' => $admin->id, 'persona' => $persona->id, 'plan' => $plan->id, 'version' => 1, 'disk' => $directory.'/disk']),
            lockRaceCompetitor($directory, 'b', ['action' => $sanction, 'admin' => $admin->id, 'persona' => $persona->id, 'disk' => $directory.'/disk']),
        ]);

        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(0, "competitor {$index} must finish, not deadlock:\n{$report}");
        }

        $after = $db->table('studio_plans')->where('id', $plan->id)->first();

        expect($db->table('users')->where('id', $persona->id)->value('status'))->toBe($to->value)
            ->and((int) $after->author_user_id)->toBe($persona->id)
            ->and((int) $after->lock_version)->toBe(2);
    } finally {
        forgetStudioPlanRace($db, [$persona->id, $admin->id], $directory);
    }

    expect($db->table('users')->whereIn('id', [$persona->id, $admin->id])->exists())->toBeFalse('the race must leave the worker database as it found it');
})->with([
    'while the persona is banned' => ['ban', 'active', UserStatus::Banned],
    'while the persona\'s access is restored' => ['restore', 'limited', UserStatus::Active],
])->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'PostgreSQL only: the competitors find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. The order itself is asserted on every engine that locks rows by the first test here. PostgreSQL is the primary runtime (docs/architecture/database-support.md).',
);
