<?php

use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserRole;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioPlan;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * An administrator's save of a Draft: whatever the editor holds replaces
 * what the plan held, the version rises, and a save from a copy older than
 * the plan, of a plan past its Draft, or by an administrator who may no
 * longer is refused with nothing written.
 */

/** Saves the plan as an editor drawn from it just now would. */
function updateStudioPlan(User $admin, StudioPlan $plan, StudioPlanData $changes): void
{
    app(UpdateStudioPlanAction::class)->handle($admin, $plan, $changes, StudioPlan::query()->findOrFail($plan->id)->lock_version);
}

it('saves the title and description, raising the version', function () {
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create(['title' => 'Before']);

    app(UpdateStudioPlanAction::class)->handle($admin, $plan, new StudioPlanData(title: 'After', description: 'Now with words.'), 1);

    expect($plan->fresh())
        ->title->toBe('After')
        ->description->toBe('Now with words.')
        ->lock_version->toBe(2)
        ->status->toBe(StudioPlanStatus::Draft)
        // The copy the caller holds is the plan as it is now.
        ->and($plan)->lock_version->toBe(2)->title->toBe('After');
});

it('changes the author, leaving the photo, its owner and its files as they were', function () {
    Storage::fake('public');
    Queue::fake();
    $admin = User::factory()->admin()->create();
    [$first, $second] = User::factory()->persona()->count(2)->create();
    $plan = StudioPlan::factory()->authoredBy($first)->withImage()->create(['created_by_user_id' => $admin->id]);
    $asset = $plan->imageAsset;
    Storage::disk('public')->put($asset->path, 'photo');

    updateStudioPlan($admin, $plan, new StudioPlanData(authorUserId: $second->id, title: $plan->title));

    expect($plan->fresh())->author_user_id->toBe($second->id)->image_asset_id->toBe($asset->id)
        ->and($asset->fresh())->owner_user_id->toBe($admin->id)->path->toBe($asset->path)->trashed()->toBeFalse()
        ->and(MediaAsset::query()->withTrashed()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe([$asset->path]);
    Queue::assertNothingPushed();
});

it('keeps the lists of ids as whole numbers, once each, in order', function () {
    $tags = Tag::factory()->count(3)->create();
    $plan = StudioPlan::factory()->create(['tag_ids' => [$tags[0]->id]]);

    updateStudioPlan(User::factory()->admin()->create(), $plan, new StudioPlanData(tagIds: [$tags[2]->id, (string) $tags[1]->id, $tags[2]->id]));

    expect($plan->fresh()->tag_ids)->toBe([$tags[1]->id, $tags[2]->id]);
});

it('saves when and how to publish without publishing anything', function () {
    Queue::fake();
    $persona = User::factory()->persona()->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->create();

    updateStudioPlan(User::factory()->admin()->create(), $plan, new StudioPlanData(
        authorUserId: $persona->id,
        title: $plan->title,
        publicationMode: StudioPlanPublicationMode::AtDate,
        publishAt: CarbonImmutable::parse('2018-02-03 04:05:06'),
    ));

    expect($plan->fresh())
        ->publication_mode->toBe(StudioPlanPublicationMode::AtDate)
        ->status->toBe(StudioPlanStatus::Draft)
        ->and($plan->fresh()->publish_at->toDateTimeString())->toBe('2018-02-03 04:05:06')
        ->and(Post::query()->withTrashed()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('changes no plan past its draft', function (StudioPlanStatus $status) {
    $plan = StudioPlan::factory()->status($status)->create(['title' => 'Fixed']);

    expect(fn () => updateStudioPlan(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Changed')))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isNotDraft())->toBeTrue());

    expect($plan->fresh())->title->toBe('Fixed')->lock_version->toBe(1);
})->with([StudioPlanStatus::Approved, StudioPlanStatus::Running, StudioPlanStatus::Completed, StudioPlanStatus::Cancelled]);

it('refuses a save from a copy older than the plan, keeping the newer one', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Mine']);
    $other = User::factory()->admin()->create();

    // Both editors were drawn at version 1; the other one saved first.
    app(UpdateStudioPlanAction::class)->handle($other, $plan, new StudioPlanData(title: 'Theirs'), 1);

    expect(fn () => app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), StudioPlan::query()->findOrFail($plan->id), new StudioPlanData(title: 'Late'), 1))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isStale())->toBeTrue());

    expect($plan->fresh())->title->toBe('Theirs')->lock_version->toBe(2);
});

it('says so when the plan was deleted meanwhile', function () {
    $plan = StudioPlan::factory()->create();
    StudioPlan::query()->whereKey($plan->id)->delete();

    expect(fn () => app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Gone'), 1))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isGone())->toBeTrue());

    expect(StudioPlan::query()->exists())->toBeFalse();
});

it('writes nothing for an administrator sanctioned or demoted since the editor was opened', function (array $since) {
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create(['title' => 'Kept']);
    User::query()->whereKey($admin->id)->firstOrFail()->forceFill($since)->save();

    expect(fn () => app(UpdateStudioPlanAction::class)->handle($admin, $plan, new StudioPlanData(title: 'Late'), 1))
        ->toThrow(CannotManageStudioPlanException::class);

    expect($plan->fresh())->title->toBe('Kept')->lock_version->toBe(1);
})->with([
    'limited' => [['status' => 'limited']],
    'banned' => [['status' => 'banned']],
    'demoted' => [['role' => UserRole::Moderator->value]],
]);

it('refuses an author who stopped being a persona after the check, on the locked row', function () {
    $persona = User::factory()->persona()->create();
    $plan = StudioPlan::factory()->create(['title' => 'Kept']);

    $demoted = false;
    DB::beforeExecuting(function (string $sql) use (&$demoted, $persona): void {
        if (! $demoted && str_contains(strtolower($sql), 'for update')) {
            $demoted = true;
            DB::table('users')->where('id', $persona->id)->update(['status' => 'deleted', 'anonymized_at' => now()]);
        }
    });

    expect(fn () => updateStudioPlan(User::factory()->admin()->create(), $plan, new StudioPlanData(authorUserId: $persona->id, title: 'Late')))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isAuthorUnavailable())->toBeTrue());

    expect($plan->fresh())->title->toBe('Kept')->author_user_id->toBeNull()->lock_version->toBe(1);
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no locked read to land before.',
);

it('keeps a draft whose author was deleted, and saves it again only with a living author or none', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->create();
    $persona->forceFill(['status' => 'deleted', 'anonymized_at' => now(), 'role' => UserRole::User->value])->save();

    expect($plan->fresh())->author_user_id->toBe($persona->id);

    expect(fn () => updateStudioPlan($admin, $plan, new StudioPlanData(authorUserId: $persona->id, title: 'Retitled')))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('authorUserId'));

    updateStudioPlan($admin, $plan, new StudioPlanData(title: 'Retitled'));

    expect($plan->fresh())->author_user_id->toBeNull()->title->toBe('Retitled');
});

it('keeps a limited, banned or shadowbanned persona as the author', function (string $state) {
    $persona = User::factory()->persona()->{$state}()->create();
    $plan = StudioPlan::factory()->create();

    updateStudioPlan(User::factory()->admin()->create(), $plan, new StudioPlanData(authorUserId: $persona->id));

    expect($plan->fresh()->author_user_id)->toBe($persona->id);
})->with(['limited', 'banned', 'shadowbanned']);

it('saves everything or nothing', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Kept']);

    StudioPlan::updated(function (): void {
        throw new RuntimeException('Simulated write failure.');
    });

    try {
        expect(fn () => updateStudioPlan(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Lost', description: 'Lost too')))
            ->toThrow(RuntimeException::class, 'Simulated write failure.');
    } finally {
        StudioPlan::flushEventListeners();
    }

    expect($plan->fresh())->title->toBe('Kept')->description->toBeNull()->lock_version->toBe(1);
});

it('lets nobody but an active administrator save a plan', function (Closure $user) {
    $plan = StudioPlan::factory()->create(['title' => 'Kept']);

    expect(fn () => updateStudioPlan($user(), $plan, new StudioPlanData(title: 'Not mine')))
        ->toThrow(CannotManageStudioPlanException::class);

    expect($plan->fresh()->title)->toBe('Kept');
})->with([
    'a moderator' => [fn () => User::factory()->moderator()->create()],
    'a member' => [fn () => User::factory()->create()],
    'a persona' => [fn () => User::factory()->persona()->create()],
]);
