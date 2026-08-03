<?php

namespace Tests\Feature\Seeders;

use App\Models\Category;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\DefaultRatingConfigurationSeeder;
use Database\Seeders\DemoFillSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class SmallDemoFillSeeder extends DemoFillSeeder
{
    protected function userCount(): int
    {
        return 6;
    }

    protected function postTitles(): array
    {
        return [
            'Large Demo Sample 01',
            'Large Demo Sample 02',
            'Large Demo Sample 03',
        ];
    }

    protected function voteRatio(): float
    {
        return 0.5;
    }

    protected function commentVoteRatio(): float
    {
        return 0.5;
    }

    protected function topLevelCommentCount(): int
    {
        return 2;
    }

    protected function replyCount(): int
    {
        return 1;
    }

    protected function deepReplyParentCount(): int
    {
        return 1;
    }

    protected function deepReplyCount(): int
    {
        return 1;
    }
}

/**
 * The private seeder methods invoked directly (via reflection) below write
 * console progress output through $this->command — give them a real,
 * silent one instead of leaving it null.
 */
function seederCommandForTesting(): Command
{
    $command = new Command;
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

    return $command;
}

/**
 * DB::partialMock() alone produces a mock with none of the real
 * DatabaseManager's internal state (its constructor never runs), so any
 * unstubbed method it falls through to — table(), connection(), etc. —
 * breaks. Copy every instance property over from the real, already-booted
 * instance (rather than a curated list of names) so this doesn't silently
 * start producing a broken mock the next time the framework adds or
 * renames internal state — only transaction() actually behaves differently.
 */
function forceNextDbTransactionToThrow(Throwable $exception): void
{
    $realDb = app('db');
    $mock = DB::partialMock();
    $mock->shouldReceive('transaction')->andThrow($exception);

    $reflection = new ReflectionClass($realDb);

    foreach ($reflection->getProperties() as $property) {
        if ($property->isStatic()) {
            continue;
        }

        $property->setAccessible(true);
        $property->setValue($mock, $property->getValue($realDb));
    }
}

beforeEach(function () {
    Storage::fake('public');
    $this->seed(DefaultCategorySeeder::class);
    $this->seed(DefaultRatingConfigurationSeeder::class);
});

it('creates a mix of categorized and uncategorized large demo posts with media', function () {
    $this->seed(SmallDemoFillSeeder::class);

    $posts = Post::query()->whereIn('title', [
        'Large Demo Sample 01',
        'Large Demo Sample 02',
        'Large Demo Sample 03',
    ])->get();

    expect($posts)->toHaveCount(3)
        ->and($posts->whereNotNull('category_id'))->toHaveCount(2)
        ->and($posts->whereNull('category_id'))->toHaveCount(1)
        ->and(Category::query()->active()->count())->toBeGreaterThan(0);

    foreach ($posts as $post) {
        expect($post->image_asset_id)->not->toBeNull();
        Storage::disk('public')->assertExists($post->imageAsset->path);
    }
});

it('rebuilds generated interactions and media without accumulating rows', function () {
    $this->seed(SmallDemoFillSeeder::class);

    $mediaAssetIdsAfterFirstRun = Post::query()
        ->where('title', 'like', 'Large Demo Sample %')
        ->pluck('image_asset_id')
        ->sort()
        ->values();

    $this->seed(SmallDemoFillSeeder::class);

    $mediaAssetIdsAfterSecondRun = Post::query()
        ->where('title', 'like', 'Large Demo Sample %')
        ->pluck('image_asset_id')
        ->sort()
        ->values();

    expect(User::query()->where('email', 'like', 'fill%@demo.test')->count())->toBe(6)
        ->and(Post::query()->where('title', 'like', 'Large Demo Sample %')->count())->toBe(3)
        ->and(PostVote::query()->count())->toBe(9)
        ->and(RatingVote::query()->count())->toBe(18)
        ->and(Comment::withTrashed()->count())->toBe(15)
        ->and(CommentVote::query()->count())->toBe(45)
        ->and(Storage::disk('public')->allFiles('posts'))->toHaveCount(3)
        ->and(MediaAsset::query()->count())->toBe(3)
        ->and($mediaAssetIdsAfterSecondRun)->toEqual($mediaAssetIdsAfterFirstRun);
});

it('does not delete an already-referenced image file when a rerun transaction fails', function () {
    $this->seed(SmallDemoFillSeeder::class);

    $existingPath = Post::query()
        ->where('title', 'Large Demo Sample 01')
        ->firstOrFail()
        ->imageAsset
        ->path;

    Storage::disk('public')->assertExists($existingPath);

    $seeder = new SmallDemoFillSeeder;
    $seeder->setCommand(seederCommandForTesting());
    $reflection = new ReflectionClass($seeder);

    $createUsers = $reflection->getMethod('createUsers');
    $createUsers->setAccessible(true);
    $users = $createUsers->invoke($seeder);

    $createPosts = $reflection->getMethod('createPosts');
    $createPosts->setAccessible(true);

    // This rerun regenerates the same deterministic image paths as the
    // first run, so the failure below must not delete a file that an
    // already-seeded post still references.
    forceNextDbTransactionToThrow(new RuntimeException('Simulated seeder rerun failure.'));

    expect(fn () => $createPosts->invoke($seeder, $users))
        ->toThrow(RuntimeException::class, 'Simulated seeder rerun failure.');

    Storage::disk('public')->assertExists($existingPath);
});

it('restores a soft-deleted media asset instead of leaving a post pointing at a trashed row', function () {
    $seeder = new SmallDemoFillSeeder;
    $reflection = new ReflectionClass($seeder);
    $method = $reflection->getMethod('ensurePostImageMediaAsset');
    $method->setAccessible(true);

    $userId = User::factory()->create()->id;
    $path = 'posts/'.$userId.'/fill_post_001.jpg';
    Storage::disk('public')->put($path, 'fake-bytes');

    $firstId = $method->invoke($seeder, $path, $userId, null);
    MediaAsset::query()->find($firstId)->delete();
    expect(MediaAsset::query()->find($firstId))->toBeNull();

    $secondId = $method->invoke($seeder, $path, $userId, $firstId);

    expect($secondId)->toBe($firstId)
        ->and(MediaAsset::query()->find($firstId))->not->toBeNull();
});
