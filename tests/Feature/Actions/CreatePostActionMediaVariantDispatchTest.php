<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\User;
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
