<?php

use App\Actions\Profile\UpdateUserProfileAction;
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

it('does not dispatch variant generation when an outer transaction wrapping the action rolls back', function () {
    // See the identical test in CreatePostActionMediaVariantDispatchTest for
    // why this uses the real sync queue driver + a disk-side-effect
    // assertion rather than Queue::fake() + assertNotPushed(): faking the
    // queue bypasses the transaction-awareness ->afterCommit() relies on
    // entirely, and asserting on DB state alone can't distinguish "ran too
    // early, then got rolled back along with its own data" from "never ran".
    $user = User::factory()->create();
    $avatar = UploadedFile::fake()->image('avatar.jpg', 400, 400);

    $masterPath = null;

    try {
        DB::transaction(function () use ($user, $avatar, &$masterPath): void {
            app(UpdateUserProfileAction::class)->execute(
                $user,
                ['rating_activity_visibility' => 'private'],
                $avatar,
            );

            $masterPath = $user->fresh()->avatarAsset->path;

            throw new RuntimeException('Simulated outer rollback.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated outer rollback.');
    }

    expect($masterPath)->not->toBeNull();

    $expectedVariantPath = app(MediaVariantPathGenerator::class)->generate(
        new MediaAsset(['path' => $masterPath]),
        MediaVariantName::Avatar128,
        'jpg',
    );

    Storage::disk('public')->assertMissing($expectedVariantPath);
});
