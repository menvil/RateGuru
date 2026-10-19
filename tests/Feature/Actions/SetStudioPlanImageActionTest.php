<?php

use App\Actions\Studio\DeleteStudioPlanDraftAction;
use App\Actions\Studio\SetStudioPlanImageAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\ImageInputSource;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\StudioPlan;
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
 * A Draft's photo, set by an administrator through the media path a post's
 * own image takes: normalized once, stored before the transaction, owned by
 * the administrator and never by the author, the previous photo released
 * only once the new one is in place, its variants generated after the commit
 * — and refused, its file removed, when the plan changed after the editor was
 * drawn.
 */
beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Sets the photo from an editor drawn just now. */
function setStudioPlanImage(User $admin, StudioPlan $plan, ?UploadedFile $file = null): void
{
    app(SetStudioPlanImageAction::class)->handle(
        $admin,
        $plan,
        $file ?? UploadedFile::fake()->image('photo.jpg', 640, 480),
        StudioPlan::query()->findOrFail($plan->id)->lock_version,
    );
}

/**
 * The ingestor the site uses, counting its calls and noting where each image
 * came from — or doing something else first, as a change landing during a
 * slow upload would.
 */
function recordingStudioImageIngestor(?Closure $meanwhile = null): ImageIngestor
{
    $ingestor = new class($meanwhile) implements ImageIngestor
    {
        public int $calls = 0;

        public ?ImageInputSource $source = null;

        public function __construct(private readonly ?Closure $meanwhile) {}

        public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage
        {
            $this->calls++;
            $this->source = $input->source;

            if ($this->meanwhile !== null) {
                ($this->meanwhile)();
            }

            return app(GdImageIngestor::class)->ingest($input, $policy);
        }
    };

    app()->instance(ImageIngestor::class, $ingestor);

    return $ingestor;
}

it('stores the normalized photo once, as a post image the administrator owns', function (string $name, string $mime) {
    $ingestor = recordingStudioImageIngestor();
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->create();

    setStudioPlanImage($admin, $plan, UploadedFile::fake()->image($name, 300, 200));

    $asset = MediaAsset::query()->findOrFail($plan->fresh()->image_asset_id);

    expect($ingestor->calls)->toBe(1)
        ->and($asset)
        ->kind->toBe(MediaKind::PostImage)
        ->status->toBe(MediaStatus::Ready)
        ->mime_type->toBe($mime)
        ->owner_user_id->toBe($admin->id)
        ->owner_user_id->not->toBe($persona->id)
        ->width->toBe(300)
        ->height->toBe(200)
        // Where any post image lives: nothing in the path names an account.
        ->and($asset->path)->toStartWith(config('media.directories.post_images').'/')
        ->and($asset->path)->not->toContain((string) $persona->id.'/')
        ->and($plan->fresh()->lock_version)->toBe(2)
        // The copy the caller holds is the plan as it is now.
        ->and($plan->image_asset_id)->toBe($asset->id)
        ->and($plan->lock_version)->toBe(2);
    Storage::disk('public')->assertExists($asset->path);
})->with([
    'a JPEG' => ['photo.jpg', 'image/jpeg'],
    'a PNG' => ['photo.png', 'image/png'],
    'a WebP' => ['photo.webp', 'image/webp'],
]);

it('takes a photo imported from a web address the same way', function () {
    $ingestor = recordingStudioImageIngestor();
    $plan = StudioPlan::factory()->create();

    app(SetStudioPlanImageAction::class)->handle(User::factory()->admin()->create(), $plan, UploadedFile::fake()->image('imported.jpg', 200, 200), 1, ImageInputSource::UrlImport);

    expect($ingestor->source)->toBe(ImageInputSource::UrlImport)
        ->and($plan->fresh()->image_asset_id)->not->toBeNull();
});

it('generates the variants once the photo is committed', function () {
    Queue::fake();
    $plan = StudioPlan::factory()->create();

    setStudioPlanImage(User::factory()->admin()->create(), $plan);

    Queue::assertPushed(GenerateMediaVariantsJob::class, fn (GenerateMediaVariantsJob $job): bool => $job->mediaAssetId === $plan->fresh()->image_asset_id
        && $job->afterCommit === true);
});

it('replaces the photo, releasing the previous one only through the media lifecycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create();

    setStudioPlanImage($admin, $plan);
    $first = MediaAsset::query()->findOrFail($plan->fresh()->image_asset_id);

    setStudioPlanImage($admin, $plan);
    $second = MediaAsset::query()->findOrFail($plan->fresh()->image_asset_id);

    expect($second->id)->not->toBe($first->id)
        ->and(MediaAsset::withTrashed()->find($first->id)->trashed())->toBeTrue()
        ->and($second->trashed())->toBeFalse()
        ->and($plan->fresh()->lock_version)->toBe(3);

    // Kept on disk through the grace period, purged after it.
    Storage::disk('public')->assertExists($first->path);
    Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00'));
    $this->artisan('media:purge')->assertExitCode(0);

    Storage::disk('public')->assertMissing($first->path);
    Storage::disk('public')->assertExists($second->path);
});

it('keeps a previous photo something else still uses', function (Closure $alsoUses) {
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create();
    setStudioPlanImage($admin, $plan);
    $shared = $plan->fresh()->image_asset_id;
    $alsoUses($shared);

    setStudioPlanImage($admin, $plan);

    expect(MediaAsset::query()->find($shared))->not->toBeNull();
})->with([
    'another plan' => [fn (int $assetId) => StudioPlan::factory()->create(['image_asset_id' => $assetId])],
    'a post' => [fn (int $assetId) => Post::factory()->published()->create(['image_asset_id' => $assetId])],
]);

it('leaves one active photo when two uploads from the same editor take turns', function () {
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create();

    app(SetStudioPlanImageAction::class)->handle($admin, $plan, UploadedFile::fake()->image('first.jpg', 64, 64), 1);
    $files = Storage::disk('public')->allFiles();

    expect(fn () => app(SetStudioPlanImageAction::class)->handle($admin, StudioPlan::query()->findOrFail($plan->id), UploadedFile::fake()->image('second.jpg', 64, 64), 1))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isStale())->toBeTrue());

    expect(MediaAsset::query()->pluck('id')->all())->toBe([$plan->fresh()->image_asset_id])
        ->and(MediaAsset::onlyTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe($files);
});

it('refuses a photo chosen before somebody else saved the plan, keeping theirs and no file', function (Closure $meanwhile) {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);
    setStudioPlanImage($admin, $plan);

    // The editor is drawn here.
    $version = $plan->fresh()->lock_version;
    $meanwhile($plan);
    $after = $plan->fresh();
    $assets = MediaAsset::withTrashed()->count();
    $files = Storage::disk('public')->allFiles();
    // Only what the refused photo would push counts from here.
    Queue::fake();

    expect(fn () => app(SetStudioPlanImageAction::class)->handle($admin, $plan, UploadedFile::fake()->image('late.jpg', 300, 300), $version))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isStale())->toBeTrue());

    expect($plan->fresh())->image_asset_id->toBe($after->image_asset_id)->lock_version->toBe($after->lock_version)
        ->and(MediaAsset::withTrashed()->count())->toBe($assets)
        ->and(Storage::disk('public')->allFiles())->toBe($files);
    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
})->with([
    'its texts' => [fn (StudioPlan $plan) => app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Harbour at night'), $plan->fresh()->lock_version)],
    'its photo' => [fn (StudioPlan $plan) => setStudioPlanImage(User::factory()->admin()->create(), $plan)],
]);

it('refuses a photo when the plan is saved while the photo is being stored, and keeps no file', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create(['title' => 'Harbour']);

    // Saved after the first check and before the lock: what another
    // administrator's save landing during a slow upload looks like.
    recordingStudioImageIngestor(fn () => app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), StudioPlan::query()->findOrFail($plan->id), new StudioPlanData(title: 'Theirs'), 1));

    expect(fn () => setStudioPlanImage($admin, $plan))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isStale())->toBeTrue());

    expect($plan->fresh())->title->toBe('Theirs')->image_asset_id->toBeNull()->lock_version->toBe(2)
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('gives no photo to a draft deleted while the photo was being stored, and keeps no file', function () {
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create();

    recordingStudioImageIngestor(fn () => app(DeleteStudioPlanDraftAction::class)->handle(User::factory()->admin()->create(), StudioPlan::query()->findOrFail($plan->id), 1));

    expect(fn () => setStudioPlanImage($admin, $plan))
        ->toThrow(fn (CannotManageStudioPlanException $exception) => expect($exception->isGone())->toBeTrue());

    expect(StudioPlan::query()->exists())->toBeFalse()
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores nothing for a plan that is gone, past its draft or changed, nor for anyone but an active administrator', function (Closure $admin, Closure $plan, int $version) {
    $ingestor = recordingStudioImageIngestor();
    $plan = $plan();

    expect(fn () => app(SetStudioPlanImageAction::class)->handle($admin(), $plan, UploadedFile::fake()->image('photo.jpg', 64, 64), $version))
        ->toThrow(CannotManageStudioPlanException::class);

    expect($ingestor->calls)->toBe(0)
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'a deleted plan' => [fn () => User::factory()->admin()->create(), fn () => tap(StudioPlan::factory()->create(), fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete()), 1],
    'an approved plan' => [fn () => User::factory()->admin()->create(), fn () => StudioPlan::factory()->status(StudioPlanStatus::Approved)->create(), 1],
    'an older version' => [fn () => User::factory()->admin()->create(), fn () => StudioPlan::factory()->create(['lock_version' => 3]), 2],
    'a moderator' => [fn () => User::factory()->moderator()->create(), fn () => StudioPlan::factory()->create(), 1],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create(), fn () => StudioPlan::factory()->create(), 1],
]);

it('leaves no file, no asset and the previous photo in place when the write fails', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $plan = StudioPlan::factory()->create();
    setStudioPlanImage($admin, $plan);
    $previous = $plan->fresh()->image_asset_id;
    $files = Storage::disk('public')->allFiles();
    // Only what the failed replacement would push counts from here.
    Queue::fake();

    StudioPlan::updating(function (): void {
        throw new RuntimeException('Simulated write failure.');
    });

    try {
        expect(fn () => setStudioPlanImage($admin, $plan))->toThrow(RuntimeException::class, 'Simulated write failure.');
    } finally {
        StudioPlan::flushEventListeners();
    }

    expect($plan->fresh())->image_asset_id->toBe($previous)->lock_version->toBe(2)
        ->and(MediaAsset::query()->find($previous))->not->toBeNull()
        ->and(MediaAsset::withTrashed()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe($files);
    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('refuses a file that is not an image, storing nothing', function () {
    $plan = StudioPlan::factory()->create();

    expect(fn () => setStudioPlanImage(User::factory()->admin()->create(), $plan, UploadedFile::fake()->createWithContent('photo.jpg', 'not an image')))
        ->toThrow(ImageIngestException::class);

    expect($plan->fresh())->image_asset_id->toBeNull()->lock_version->toBe(1)
        ->and(MediaAsset::withTrashed()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});
