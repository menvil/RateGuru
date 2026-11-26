<?php

use App\Actions\Posts\FinalizePostMediaAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\User;
use App\Notifications\VideoPostProcessedNotification;
use App\Services\Media\MediaAuditService;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaOrphanScanner;
use App\Services\Media\MediaStorage;
use App\Services\Media\VideoAssetProcessor;
use App\Services\Media\VideoMediaSweeper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\NotificationFake;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Notification::fake();
    config(['media.lifecycle.video_original_retention_days' => 7, 'media.lifecycle.video_stuck_after_hours' => 24]);
});

/**
 * A processed video: its master on the public disk, its original kept on
 * the private one, processed $daysAgo days ago.
 */
function processedVideo(int $daysAgo): MediaAsset
{
    $uuid = Str::uuid()->toString();
    $original = "media/originals/2026/11/{$uuid}.mov";
    Storage::disk('local')->put($original, 'original');
    Storage::disk('public')->put("media/post-videos/2026/11/{$uuid}.mp4", 'master');

    return MediaAsset::factory()->create([
        'media_type' => MediaType::Video,
        'status' => MediaStatus::Ready,
        'disk' => 'public',
        'path' => "media/post-videos/2026/11/{$uuid}.mp4",
        'metadata' => ['original' => ['disk' => 'local', 'path' => $original], 'processed_at' => now()->subDays($daysAgo)->toIso8601ZuluString()],
        'updated_at' => now()->subDays($daysAgo),
    ]);
}

it('deletes a processed video\'s original once it has been kept the retention period, and keeps the master', function () {
    $old = processedVideo(8);
    $recent = processedVideo(2);
    $oldOriginal = $old->metadata['original']['path'];

    expect(app(VideoMediaSweeper::class)->deleteExpiredOriginals())->toBe(1);

    Storage::disk('local')->assertMissing($oldOriginal);
    Storage::disk('public')->assertExists($old->path);
    Storage::disk('local')->assertExists($recent->metadata['original']['path']);
    expect($old->refresh()->metadata)->not->toHaveKey('original')
        ->and($old->metadata)->toHaveKey('original_deleted_at');
});

it('removes a failed upload\'s files after the retention period and keeps its row for the draft that points at it', function () {
    $uuid = Str::uuid()->toString();
    Storage::disk('local')->put("media/originals/2026/11/{$uuid}.mov", 'original');
    Storage::disk('public')->put("media/post-videos/2026/11/{$uuid}.mp4", 'master written before it failed');
    $asset = MediaAsset::factory()->create([
        'media_type' => MediaType::Video,
        'status' => MediaStatus::Failed,
        'disk' => 'public',
        'path' => "media/post-videos/2026/11/{$uuid}.mp4",
        'metadata' => ['original' => ['disk' => 'local', 'path' => "media/originals/2026/11/{$uuid}.mov"]],
        'updated_at' => now()->subDays(8),
    ]);
    $poster = MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $asset->id]);
    Storage::disk('public')->put($poster->path, 'poster');
    // Failed, but only yesterday: kept for now.
    $recent = MediaAsset::factory()->create(['media_type' => MediaType::Video, 'status' => MediaStatus::Failed, 'disk' => 'local', 'path' => 'media/originals/2026/11/recent.mov', 'updated_at' => now()->subDay()]);
    Storage::disk('local')->put($recent->path, 'original');
    $post = Post::factory()->create(['status' => PostStatus::Draft, 'image_asset_id' => $asset->id]);

    expect(app(VideoMediaSweeper::class)->removeFailedFiles())->toBe(1)
        ->and(app(VideoMediaSweeper::class)->removeFailedFiles())->toBe(0);

    Storage::disk('local')->assertMissing("media/originals/2026/11/{$uuid}.mov");
    Storage::disk('public')->assertMissing($asset->path);
    Storage::disk('public')->assertMissing($poster->path);
    Storage::disk('local')->assertExists($recent->path);
    expect($asset->refresh()->variants)->toHaveCount(0)
        ->and($asset->metadata)->toHaveKey('files_removed_at')
        ->and($post->refresh()->image_asset_id)->toBe($asset->id);
});

it('fails an upload still processing a day on and tells its author, but leaves one a run holds now, a younger one and a photo', function () {
    $author = User::factory()->create();
    $lost = MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(25)]);
    Post::factory()->for($author)->create(['status' => PostStatus::Draft, 'image_asset_id' => $lost->id]);
    $working = MediaAsset::factory()->create(['media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(25)]);
    // Waiting in a backed-up queue for hours: delayed, not failed.
    $young = MediaAsset::factory()->create(['media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(5)]);
    $photo = MediaAsset::factory()->create(['media_type' => MediaType::Image, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(25)]);
    $held = VideoAssetProcessor::lockFor($working->id);
    $held->get();

    try {
        expect(app(VideoMediaSweeper::class)->failStuck())->toBe(1);
    } finally {
        $held->release();
    }

    expect($lost->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($working->refresh()->status)->toBe(MediaStatus::Processing)
        ->and($young->refresh()->status)->toBe(MediaStatus::Processing)
        ->and($photo->refresh()->status)->toBe(MediaStatus::Processing);
    Notification::assertSentToTimes($author, VideoPostProcessedNotification::class, 1);

    // The job, arriving late, finds it failed: the author is not told twice.
    app(FinalizePostMediaAction::class)->failed($lost->refresh());
    Notification::assertSentToTimes($author, VideoPostProcessedNotification::class, 1);
});

it('goes on to the next lost upload when a notice cannot be written, leaving its claim free', function () {
    Exceptions::fake();
    $author = User::factory()->create();
    $lost = collect(range(1, 2))->map(function () use ($author): MediaAsset {
        $asset = MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(25)]);
        Post::factory()->for($author)->create(['status' => PostStatus::Draft, 'image_asset_id' => $asset->id]);

        return $asset;
    });
    Notification::swap($notices = new class extends NotificationFake
    {
        public int $failures = 1;

        public function send($notifiables, $notification)
        {
            if ($this->failures-- > 0) {
                throw new RuntimeException('The notifications table would not take it.');
            }

            parent::send($notifiables, $notification);
        }
    });

    expect(app(VideoMediaSweeper::class)->failStuck())->toBe(2)
        ->and($lost->map(fn (MediaAsset $asset): MediaStatus => $asset->refresh()->status)->unique()->all())->toBe([MediaStatus::Failed])
        ->and($lost->filter(fn (MediaAsset $asset): bool => isset($asset->refresh()->metadata['failure_notified_at']))->count())->toBe(1);
    $notices->assertSentToTimes($author, VideoPostProcessedNotification::class, 1);
    Exceptions::assertReported(RuntimeException::class);
});

it('purges a video\'s kept original with the asset', function () {
    $asset = processedVideo(1);
    $original = $asset->metadata['original']['path'];
    $asset->delete();
    $asset->forceFill(['deleted_at' => now()->subDays(30)])->save();

    app(MediaLifecycleService::class)->purge($asset);

    Storage::disk('local')->assertMissing($original);
    Storage::disk('public')->assertMissing($asset->path);
});

it('scans the private originals for orphans, knowing the ones processed videos still keep', function () {
    $kept = processedVideo(1);
    Storage::disk('local')->put('media/originals/2026/11/stray.mov', 'nobody owns this');
    $this->travel(2)->days();

    $orphans = collect(app(MediaOrphanScanner::class)->scan())->map(fn ($location): string => "{$location->disk}:{$location->path}")->all();

    expect($orphans)->toContain('local:media/originals/2026/11/stray.mov')
        ->not->toContain('local:'.$kept->metadata['original']['path']);
});

it('is scheduled hourly, never overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'media:sweep-videos'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('deletes an original by when it was processed, not by the row\'s last change, and never one with no processing time', function () {
    $touched = processedVideo(8);
    $touched->forceFill(['updated_at' => now()])->saveQuietly();
    $unknown = processedVideo(8);
    $metadata = $unknown->metadata;
    unset($metadata['processed_at']);
    $unknown->forceFill(['metadata' => $metadata])->save();

    expect(app(VideoMediaSweeper::class)->deleteExpiredOriginals())->toBe(1);

    Storage::disk('local')->assertMissing($touched->metadata['original']['path']);
    Storage::disk('local')->assertExists($unknown->metadata['original']['path']);
});

it('reports a failed upload whose files were removed as healthy, not as a missing master', function () {
    $asset = MediaAsset::factory()->create([
        'media_type' => MediaType::Video,
        'status' => MediaStatus::Failed,
        'disk' => 'public',
        'path' => 'media/post-videos/2026/11/'.Str::uuid().'.mp4',
        'metadata' => ['files_removed_at' => now()->toIso8601ZuluString()],
    ]);
    Post::factory()->create(['status' => PostStatus::Draft, 'image_asset_id' => $asset->id]);

    $summary = app(MediaAuditService::class)->run();

    expect($summary->missingMasters)->toBe(0);
});

it('fails the sweep run when a file will not delete', function () {
    processedVideo(8);
    app()->instance(MediaStorage::class, Mockery::mock(MediaStorage::class, function ($storage): void {
        $storage->shouldReceive('deleteIfExists')->andThrow(new RuntimeException('disk is read-only'));
    })->makePartial());

    $this->artisan('media:sweep-videos')->assertFailed();
});
