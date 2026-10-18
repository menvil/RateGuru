<?php

use App\Actions\Personas\SetPersonaAvatarAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\MediaKind;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Services\Media\GdImageIngestor;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\ImageInput;
use App\Services\Media\NormalizedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A Persona's avatar, set by an administrator through the media path a
 * member's own avatar takes: normalized once, stored before the transaction,
 * owned by the Persona, the previous one released only once the new one is
 * in place, its variants generated after the commit.
 */
beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

function setPersonaAvatar(User $admin, User $persona, ?UploadedFile $file = null): void
{
    app(SetPersonaAvatarAction::class)->handle($admin, $persona, $file ?? UploadedFile::fake()->image('face.jpg', 400, 400));
}

it('stores the normalized photo as the persona\'s own avatar', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();

    setPersonaAvatar($admin, $persona, UploadedFile::fake()->image('face.png', 300, 200));

    $asset = MediaAsset::query()->findOrFail($persona->fresh()->avatar_asset_id);

    expect($asset)
        ->kind->toBe(MediaKind::Avatar)
        ->owner_user_id->toBe($persona->id)
        ->owner_user_id->not->toBe($admin->id)
        ->width->toBe(300)
        ->height->toBe(200)
        ->and($asset->path)->toContain('avatars/'.$persona->id.'/');
    Storage::disk('public')->assertExists($asset->path);
});

it('generates the variants once the avatar is committed', function () {
    Queue::fake();
    $persona = User::factory()->persona()->create();

    setPersonaAvatar(User::factory()->admin()->create(), $persona);

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn (GenerateMediaVariantsJob $job): bool => $job->mediaAssetId === $persona->fresh()->avatar_asset_id);
});

it('replaces the avatar, releasing the previous one only through the media lifecycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();

    setPersonaAvatar($admin, $persona);
    $first = MediaAsset::query()->findOrFail($persona->fresh()->avatar_asset_id);

    setPersonaAvatar($admin, $persona);
    $second = MediaAsset::query()->findOrFail($persona->fresh()->avatar_asset_id);

    expect($second->id)->not->toBe($first->id)
        ->and(MediaAsset::withTrashed()->find($first->id)->trashed())->toBeTrue()
        ->and($second->trashed())->toBeFalse();

    // Kept on disk through the grace period, purged after it.
    Storage::disk('public')->assertExists($first->path);
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00'));
    $this->artisan('media:purge')->assertExitCode(0);

    Storage::disk('public')->assertMissing($first->path);
    Storage::disk('public')->assertExists($second->path);
});

it('keeps a previous avatar something else still uses', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();

    setPersonaAvatar($admin, $persona);
    $shared = $persona->fresh()->avatar_asset_id;
    User::factory()->create(['avatar_asset_id' => $shared]);

    setPersonaAvatar($admin, $persona);

    expect(MediaAsset::query()->find($shared))->not->toBeNull();
});

it('leaves one active avatar when two replacements take turns on the same stale copy', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();
    $stale = User::query()->findOrFail($persona->id);

    setPersonaAvatar($admin, $stale);
    setPersonaAvatar($admin, User::query()->findOrFail($persona->id));
    setPersonaAvatar($admin, $stale);

    $active = MediaAsset::query()->where('owner_user_id', $persona->id)->where('kind', MediaKind::Avatar)->pluck('id');

    expect($active->all())->toBe([$persona->fresh()->avatar_asset_id])
        ->and(MediaAsset::onlyTrashed()->where('owner_user_id', $persona->id)->count())->toBe(2);
});

it('leaves no file, no asset and the previous avatar in place when the write fails', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();
    setPersonaAvatar($admin, $persona);
    $previous = $persona->fresh()->avatar_asset_id;
    $files = Storage::disk('public')->allFiles();
    // Only what the failed replacement would push counts from here.
    Queue::fake();

    User::updating(function (): void {
        throw new RuntimeException('Simulated write failure.');
    });

    try {
        expect(fn () => setPersonaAvatar($admin, $persona))->toThrow(RuntimeException::class, 'Simulated write failure.');
    } finally {
        User::flushEventListeners();
    }

    expect($persona->fresh()->avatar_asset_id)->toBe($previous)
        ->and(MediaAsset::query()->find($previous))->not->toBeNull()
        ->and(MediaAsset::withTrashed()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe($files);
    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('gives no avatar to a persona deleted while its photo was being stored, and keeps no file', function () {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();

    // Anonymized after the first check and before the lock: what a deletion
    // landing during a slow upload looks like.
    app()->instance(ImageIngestor::class, new class($persona) implements ImageIngestor
    {
        public function __construct(private readonly User $persona) {}

        public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage
        {
            app(AnonymizeUserAccountAction::class)->execute($this->persona->replicate()->setRawAttributes($this->persona->getAttributes()));

            return app(GdImageIngestor::class)->ingest($input, $policy);
        }
    });

    expect(fn () => setPersonaAvatar($admin, $persona))
        ->toThrow(fn (CannotManagePersonaException $exception) => expect($exception->isGone())->toBeTrue());

    expect($persona->fresh())->isTombstoned()->toBeTrue()->avatar_asset_id->toBeNull()
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses a file that is not an image, storing nothing', function () {
    $persona = User::factory()->persona()->create();

    expect(fn () => setPersonaAvatar(User::factory()->admin()->create(), $persona, UploadedFile::fake()->createWithContent('face.jpg', 'not an image')))
        ->toThrow(ImageIngestException::class);

    expect($persona->fresh()->avatar_asset_id)->toBeNull()
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores nothing for anyone but an active administrator, nor for an account that is no persona', function (Closure $admin, Closure $target) {
    $target = $target();

    expect(fn () => setPersonaAvatar($admin(), $target))->toThrow(CannotManagePersonaException::class);

    expect($target->fresh()->avatar_asset_id)->toBeNull()
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'a moderator, for a persona' => [fn () => User::factory()->moderator()->create(), fn () => User::factory()->persona()->create()],
    'a banned administrator, for a persona' => [fn () => User::factory()->admin()->banned()->create(), fn () => User::factory()->persona()->create()],
    'an administrator, for a member' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->create()],
    'an administrator, for a deleted persona' => [fn () => User::factory()->admin()->create(), fn () => User::factory()->persona()->tombstoned()->create()],
]);
