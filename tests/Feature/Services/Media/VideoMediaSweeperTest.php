<?php

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVariantName;
use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Models\User;
use App\Notifications\VideoPostProcessedNotification;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaOrphanScanner;
use App\Services\Media\VideoMediaSweeper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Notification::fake();
    config(['media.lifecycle.video_original_retention_days' => 7, 'uploads.videos.processing_window_minutes' => 180]);
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

it('fails an upload still processing past every retry and tells its author, but leaves one a run holds now', function () {
    $author = User::factory()->create();
    $lost = MediaAsset::factory()->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(4)]);
    Post::factory()->for($author)->create(['status' => PostStatus::Draft, 'image_asset_id' => $lost->id]);
    $working = MediaAsset::factory()->create(['media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHours(4)]);
    $young = MediaAsset::factory()->create(['media_type' => MediaType::Video, 'status' => MediaStatus::Processing, 'created_at' => now()->subHour()]);
    $held = Cache::store('database')->getStore()->lock("media-video-process:{$working->id}", 60);
    $held->get();

    try {
        expect(app(VideoMediaSweeper::class)->failStuck())->toBe(1);
    } finally {
        $held->release();
    }

    expect($lost->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($working->refresh()->status)->toBe(MediaStatus::Processing)
        ->and($young->refresh()->status)->toBe(MediaStatus::Processing);
    Notification::assertSentTo($author, VideoPostProcessedNotification::class);
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
