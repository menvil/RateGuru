<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaVariantName;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaVariantPathGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('dispatches variant generation for the new post image after the transaction commits', function () {
    Queue::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 1600, 900);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    ));

    $post = $post->fresh();

    Queue::assertPushed(
        GenerateMediaVariantsJob::class,
        fn (GenerateMediaVariantsJob $job): bool => $job->mediaAssetId === $post->image_asset_id,
    );
});

it('does not dispatch variant generation when the post has no image', function () {
    Queue::fake();

    $user = User::factory()->create();

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish without an image',
        image: null,
    ));

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('does not dispatch variant generation when the post creation transaction fails', function () {
    Queue::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 1600, 900);

    $realDb = app('db');
    $mock = DB::partialMock();
    $mock->shouldReceive('transaction')->andThrow(new RuntimeException('Simulated post creation failure.'));

    $reflection = new ReflectionClass($realDb);
    foreach ($reflection->getProperties() as $property) {
        if ($property->isStatic()) {
            continue;
        }
        $property->setAccessible(true);
        $property->setValue($mock, $property->getValue($realDb));
    }

    expect(fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish that fails to save',
        image: $file,
    )))->toThrow(RuntimeException::class, 'Simulated post creation failure.');

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('does not dispatch variant generation when an outer transaction wrapping the action rolls back', function () {
    // Queue::fake() doesn't model transactions at all — it isn't built on
    // Illuminate\Queue\Queue::enqueueUsing(), which is what afterCommit()
    // actually relies on, so a faked queue would push immediately regardless
    // of transaction state and this test would pass for the wrong reason.
    // This app's real QUEUE_CONNECTION=sync driver does implement it, so
    // this test dispatches for real.
    //
    // Asserting on the eventual DB state (e.g. MediaVariant::count()) isn't
    // enough on its own: rolling back the outer transaction also undoes the
    // Post/MediaAsset rows the job would have needed, so even a buggy,
    // immediate dispatch would look identical to a correctly-deferred one
    // once everything unwinds. The variant file on disk is the signal that
    // actually distinguishes them: putContents() is a filesystem write, not
    // a DB operation, so it is *not* undone by the transaction rolling back
    // — a job that ran too early leaves it behind as orphaned evidence.
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 1600, 900);

    $masterPath = null;

    // CreatePostAction's own DB::transaction() commits successfully at the
    // savepoint level here (the post row exists by the time handle()
    // returns), but the outer transaction below is what actually decides
    // whether that ever becomes durable — ->afterCommit() must wait for
    // this one, not fire the moment the inner savepoint releases.
    try {
        DB::transaction(function () use ($user, $file, &$masterPath): void {
            $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
                title: 'Dish in an outer transaction',
                image: $file,
            ));

            $masterPath = $post->imageAsset->path;

            throw new RuntimeException('Simulated outer rollback.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated outer rollback.');
    }

    expect($masterPath)->not->toBeNull();

    $expectedVariantPath = app(MediaVariantPathGenerator::class)->generate(
        new MediaAsset(['path' => $masterPath]),
        MediaVariantName::PostFeed640,
        'jpg',
    );

    Storage::disk('public')->assertMissing($expectedVariantPath);
});
