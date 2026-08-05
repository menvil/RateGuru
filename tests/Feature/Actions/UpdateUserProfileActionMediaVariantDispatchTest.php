<?php

use App\Actions\Profile\UpdateUserProfileAction;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('dispatches variant generation for the new avatar after the transaction commits', function () {
    Queue::fake();

    $user = User::factory()->create();
    $avatar = UploadedFile::fake()->image('avatar.jpg', 400, 400);

    app(UpdateUserProfileAction::class)->execute($user, ['rating_activity_visibility' => 'private'], $avatar);

    $user = $user->fresh();

    Queue::assertPushed(
        GenerateMediaVariantsJob::class,
        fn (GenerateMediaVariantsJob $job): bool => $job->mediaAssetId === $user->avatar_asset_id,
    );
});

it('does not dispatch variant generation for a profile update with no new avatar', function () {
    Queue::fake();

    $user = User::factory()->create();

    app(UpdateUserProfileAction::class)->execute($user, [
        'display_name' => 'New Name',
        'rating_activity_visibility' => 'private',
    ], null);

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});

it('does not dispatch variant generation when the profile update transaction fails', function () {
    Queue::fake();

    $user = User::factory()->create();
    $avatar = UploadedFile::fake()->image('avatar.jpg', 400, 400);

    $realDb = app('db');
    $mock = DB::partialMock();
    $mock->shouldReceive('transaction')->andThrow(new RuntimeException('Simulated profile update failure.'));

    $reflection = new ReflectionClass($realDb);
    foreach ($reflection->getProperties() as $property) {
        if ($property->isStatic()) {
            continue;
        }
        $property->setAccessible(true);
        $property->setValue($mock, $property->getValue($realDb));
    }

    expect(fn () => app(UpdateUserProfileAction::class)->execute($user, [], $avatar))
        ->toThrow(RuntimeException::class, 'Simulated profile update failure.');

    Queue::assertNotPushed(GenerateMediaVariantsJob::class);
});
