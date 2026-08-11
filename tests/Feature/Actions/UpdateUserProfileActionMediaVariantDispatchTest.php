<?php

use App\Actions\Profile\UpdateUserProfileAction;
use App\Enums\MediaVariantName;
use App\Jobs\GenerateMediaVariantsJob;
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

    User::updating(function (): void {
        throw new RuntimeException('Simulated profile update failure.');
    });

    try {
        expect(fn () => app(UpdateUserProfileAction::class)->execute($user, [], $avatar))
            ->toThrow(RuntimeException::class, 'Simulated profile update failure.');
    } finally {
        User::flushEventListeners();
    }

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

    $masterAsset = null;

    try {
        DB::transaction(function () use ($user, $avatar, &$masterAsset): void {
            app(UpdateUserProfileAction::class)->execute(
                $user,
                ['rating_activity_visibility' => 'private'],
                $avatar,
            );

            // The full, persisted asset — not just its path — so the
            // expected variant path below is derived from the exact same
            // data MediaVariantPathGenerator would actually use, rather than
            // a synthetic stand-in with a guessed extension.
            $masterAsset = $user->fresh()->avatarAsset;

            throw new RuntimeException('Simulated outer rollback.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated outer rollback.');
    }

    expect($masterAsset)->not->toBeNull();

    $expectedVariantPath = app(MediaVariantPathGenerator::class)->generate(
        $masterAsset,
        MediaVariantName::Avatar128,
        $masterAsset->extension,
    );

    Storage::disk('public')->assertMissing($expectedVariantPath);
});
