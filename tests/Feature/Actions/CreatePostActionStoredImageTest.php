<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\ImageIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * CreatePostAction's trusted fields, which only Content Studio's executor
 * sets: a post image already stored, used as it is, and the post's own
 * dates. Without them a post is made exactly as an upload makes it.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A Ready post image an administrator uploaded, its file on disk. */
function storedPostImage(array $attributes = []): MediaAsset
{
    $asset = MediaAsset::factory()->postImage()->create(['owner_user_id' => User::factory()->admin()->create()->id, ...$attributes]);
    Storage::disk($asset->disk)->put($asset->path, 'master-bytes');

    return $asset;
}

it('uses a stored post image as it is: no storer, no new asset, no copy, its owner kept', function () {
    // Nothing is normalized again: the storer's ingestor is never asked.
    $this->mock(ImageIngestor::class)->shouldNotReceive('ingest');
    $persona = User::factory()->persona()->create();
    $asset = storedPostImage();

    $post = app(CreatePostAction::class)->handle($persona, new CreatePostData(title: 'A quiet harbour', imageAssetId: $asset->id));

    expect($post->image_asset_id)->toBe($asset->id)
        ->and($post->user_id)->toBe($persona->id)
        // A Persona's post waits for an administrator, as ever.
        ->and($post->status)->toBe(PostStatus::Pending)
        ->and(MediaAsset::query()->count())->toBe(1)
        ->and($asset->fresh())->owner_user_id->toBe($asset->owner_user_id)->path->toBe($asset->path)->trashed()->toBeFalse();
    Storage::disk('public')->assertExists($asset->path);
    expect(Storage::disk('public')->allFiles())->toBe([$asset->path]);
});

it('refuses a stored image that is no Ready post image no post uses, creating nothing', function (Closure $asset) {
    $persona = User::factory()->persona()->create();
    $id = $asset();

    expect(fn () => app(CreatePostAction::class)->handle($persona, new CreatePostData(title: 'A quiet harbour', imageAssetId: $id)))
        ->toThrow(fn (CannotCreatePostException $refusal) => expect($refusal->isImageUnavailable())->toBeTrue());

    expect(Post::query()->where('user_id', $persona->id)->exists())->toBeFalse();
})->with([
    'none at all' => [fn () => 2_000_000_000],
    'an avatar' => [fn () => storedPostImage(['kind' => MediaKind::Avatar])->id],
    'still processing' => [fn () => storedPostImage(['status' => MediaStatus::Processing])->id],
    'failed' => [fn () => storedPostImage(['status' => MediaStatus::Failed])->id],
    'deleted' => [function () {
        $asset = storedPostImage();
        $asset->delete();

        return $asset->id;
    }],
    'a post\'s already' => [function () {
        $asset = storedPostImage();
        Post::factory()->create(['image_asset_id' => $asset->id]);

        return $asset->id;
    }],
    'a deleted post\'s, still restorable' => [function () {
        $asset = storedPostImage();
        Post::factory()->create(['image_asset_id' => $asset->id])->delete();

        return $asset->id;
    }],
]);

it('refuses a new file and a stored image together', function () {
    $persona = User::factory()->persona()->create();

    expect(fn () => app(CreatePostAction::class)->handle($persona, new CreatePostData(
        title: 'A quiet harbour',
        image: UploadedFile::fake()->image('new.jpg', 64, 64),
        imageAssetId: storedPostImage()->id,
    )))->toThrow(InvalidArgumentException::class);

    expect(Post::query()->exists())->toBeFalse()
        ->and(MediaAsset::query()->count())->toBe(1);
});

it('keeps the dates it is given as the post\'s own, and stamps it as ever without them', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $persona = User::factory()->persona()->create();

    $dated = app(CreatePostAction::class)->handle($persona, new CreatePostData(
        title: 'A quiet harbour',
        imageAssetId: storedPostImage()->id,
        createdAt: CarbonImmutable::parse('2021-01-01 12:00:00'),
        updatedAt: CarbonImmutable::parse('2021-01-01 12:00:00'),
    ));
    $plain = app(CreatePostAction::class)->handle($persona, new CreatePostData(title: 'Another harbour'));

    expect($dated->fresh()->created_at->toDateTimeString())->toBe('2021-01-01 12:00:00')
        ->and($dated->fresh()->updated_at->toDateTimeString())->toBe('2021-01-01 12:00:00')
        ->and($plain->fresh()->created_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($plain->fresh()->updated_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('keeps a post\'s later real edits stamped with their real time', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $post = app(CreatePostAction::class)->handle(User::factory()->persona()->create(), new CreatePostData(
        title: 'A quiet harbour',
        imageAssetId: storedPostImage()->id,
        createdAt: CarbonImmutable::parse('2021-01-01 12:00:00'),
        updatedAt: CarbonImmutable::parse('2021-01-01 12:00:00'),
    ));

    Carbon::setTestNow('2026-10-21 09:00:00');
    $post->forceFill(['title' => 'A quiet harbour, edited'])->save();

    expect($post->fresh()->updated_at->toDateTimeString())->toBe('2026-10-21 09:00:00')
        ->and($post->fresh()->created_at->toDateTimeString())->toBe('2021-01-01 12:00:00');
});

it('still makes a member\'s upload as it always has, through the storer', function () {
    $member = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($member, new CreatePostData(title: 'A member\'s harbour', image: UploadedFile::fake()->image('upload.jpg', 64, 64)));

    expect($post->image_asset_id)->not->toBeNull()
        ->and(MediaAsset::query()->sole()->owner_user_id)->toBe($member->id);
});
