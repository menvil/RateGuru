<?php

use App\Actions\Posts\DeletePostAction;
use App\Actions\Posts\FinalizePostMediaAction;
use App\Actions\Posts\RestoreDeletedPostAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Notifications\VideoPostProcessedNotification;
use App\Services\Media\MediaToolResult;
use App\Services\Media\VideoAssetProcessor;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\NotificationFake;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
});

/**
 * A video post waiting for its media, whose asset is now in $status.
 *
 * @return array{0: Post, 1: MediaAsset}
 */
function draftVideoPost(User $author, MediaStatus $status): array
{
    $asset = MediaAsset::factory()->create([
        'owner_user_id' => $author->id,
        'media_type' => MediaType::Video,
        'status' => $status,
    ]);
    $post = Post::factory()->for($author)->create([
        'status' => PostStatus::Draft,
        'published_at' => null,
        'image_asset_id' => $asset->id,
    ]);

    return [$post, $asset];
}

it('publishes a trusted, confirmed author\'s video post once its media is ready, and tells author and followers', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Ready);

    app(FinalizePostMediaAction::class)->ready($asset);

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->published_at)->not->toBeNull();
    Queue::assertPushed(NotifyFollowersAboutNewPostJob::class, fn (NotifyFollowersAboutNewPostJob $job): bool => $job->postId === $post->id);
    Notification::assertSentTo($author, VideoPostProcessedNotification::class, fn (VideoPostProcessedNotification $notification): bool => $notification->toArray($author)['type'] === 'video_post_published');
});

it('sends anyone else\'s video post to moderation, under the rule every new post follows', function () {
    $author = User::factory()->create(['trust_level' => 0]);
    [$post, $asset] = draftVideoPost($author, MediaStatus::Ready);

    app(FinalizePostMediaAction::class)->ready($asset);

    expect($post->refresh()->status)->toBe(PostStatus::Pending)
        ->and($post->published_at)->toBeNull();
    Queue::assertNotPushed(NotifyFollowersAboutNewPostJob::class);
    Notification::assertSentTo($author, VideoPostProcessedNotification::class, fn (VideoPostProcessedNotification $notification): bool => $notification->toArray($author)['type'] === 'video_post_pending');
});

it('publishes nothing twice: a post already out of its draft is left alone', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Ready);

    app(FinalizePostMediaAction::class)->ready($asset);
    app(FinalizePostMediaAction::class)->ready($asset);

    Queue::assertPushed(NotifyFollowersAboutNewPostJob::class, 1);
    Notification::assertSentToTimes($author, VideoPostProcessedNotification::class, 1);
});

it('leaves the draft of a post deleted meanwhile, or of an author who may no longer post', function () {
    $author = User::factory()->create();
    [$deleted, $deletedAsset] = draftVideoPost($author, MediaStatus::Ready);
    $deleted->delete();
    $banned = User::factory()->banned()->create();
    [$bannedPost, $bannedAsset] = draftVideoPost($banned, MediaStatus::Ready);

    app(FinalizePostMediaAction::class)->ready($deletedAsset);
    app(FinalizePostMediaAction::class)->ready($bannedAsset);

    expect(Post::withTrashed()->find($deleted->id)->status)->toBe(PostStatus::Draft)
        ->and($bannedPost->refresh()->status)->toBe(PostStatus::Draft);
    Notification::assertNothingSent();
});

it('keeps the post a draft when its media failed, and tells the author', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Failed);

    app(FinalizePostMediaAction::class)->failed($asset);

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Notification::assertSentTo($author, VideoPostProcessedNotification::class, fn (VideoPostProcessedNotification $notification): bool => $notification->toArray($author)['type'] === 'video_post_failed'
        && $notification->toArray($author)['message_key'] === 'ui.notifications.messages.video_post_failed');
});

it('finalizes the post from the job: whenever its asset is ready, and once the last attempt fails', function () {
    $author = User::factory()->create();
    // Ready already: an earlier run processed it and stopped before the post
    // was published. The processor skips it; the job still publishes.
    [$post, $asset] = draftVideoPost($author, MediaStatus::Ready);
    $runner = bindRecordingMediaToolRunner();

    (new ProcessVideoAssetJob($asset->id))->handle(app(VideoAssetProcessor::class), app(FinalizePostMediaAction::class));

    expect($post->refresh()->status)->toBe(PostStatus::Published)
        ->and($runner->calls)->toBe([]);

    [$failing, $failingAsset] = draftVideoPost($author, MediaStatus::Processing);
    (new ProcessVideoAssetJob($failingAsset->id))->failed(new RuntimeException('disk full'));

    expect($failingAsset->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($failing->refresh()->status)->toBe(PostStatus::Draft);
    Notification::assertSentTo($author, VideoPostProcessedNotification::class, fn (VideoPostProcessedNotification $notification): bool => $notification->post->is($failing));
});

it('publishes nothing for an asset that is not ready', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Processing);

    app(FinalizePostMediaAction::class)->ready($asset);

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Notification::assertNothingSent();
});

it('tells the author of a failure once per asset, whichever run settles it', function () {
    $author = User::factory()->create();
    // The processor refuses the file: ffprobe cannot read it.
    [$post, $asset] = draftVideoPost($author, MediaStatus::Processing);
    $asset->forceFill(['disk' => 'local', 'path' => 'media/originals/2026/11/'.Str::uuid().'.mp4'])->save();
    Storage::fake('local')->put($asset->path, 'not a video');
    bindRecordingMediaToolRunner([new MediaToolResult(1, '', 'Invalid data')]);

    (new ProcessVideoAssetJob($asset->id))->handle(app(VideoAssetProcessor::class), app(FinalizePostMediaAction::class));
    // Redelivered: the asset is Failed already.
    (new ProcessVideoAssetJob($asset->id))->handle(app(VideoAssetProcessor::class), app(FinalizePostMediaAction::class));

    expect($asset->refresh()->status)->toBe(MediaStatus::Failed)
        ->and($post->refresh()->status)->toBe(PostStatus::Draft);
    Notification::assertSentToTimes($author, VideoPostProcessedNotification::class, 1);
});

it('tries publishing once more when only publishing failed on every attempt', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Ready);

    (new ProcessVideoAssetJob($asset->id))->failed(new RuntimeException('database went away'));

    expect($post->refresh()->status)->toBe(PostStatus::Published)
        ->and($asset->refresh()->status)->toBe(MediaStatus::Ready);
});

it('publishes a video draft restored from the deleted posts once its media finished meanwhile', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Processing);
    app(DeletePostAction::class)->handle($author, $post);
    $asset->forceFill(['status' => MediaStatus::Ready])->save();

    app(RestoreDeletedPostAction::class)->handle($author, $post->fresh());

    expect(Post::query()->findOrFail($post->id)->status)->toBe(PostStatus::Published);
});

it('tells the author of a failure a run left untold, when it stopped between failing the asset and the notice', function () {
    $author = User::factory()->create();
    [$post, $asset] = draftVideoPost($author, MediaStatus::Failed);

    app()->call([new ProcessVideoAssetJob($asset->id), 'handle']);
    app()->call([new ProcessVideoAssetJob($asset->id), 'handle']);

    Notification::assertSentToTimes($author, VideoPostProcessedNotification::class, 1);
    expect($asset->refresh()->metadata['failure_notified_at'] ?? null)->not->toBeNull();
});

it('takes the claim back when the notice cannot be written, for the job\'s next attempt to send it', function () {
    $author = User::factory()->create();
    [$failedPost, $failedAsset] = draftVideoPost($author, MediaStatus::Failed);
    [$readyPost, $readyAsset] = draftVideoPost($author, MediaStatus::Ready);
    Notification::swap($notices = new class extends NotificationFake
    {
        public int $failures = 2;

        public function send($notifiables, $notification)
        {
            if ($this->failures-- > 0) {
                throw new RuntimeException('The notifications table would not take it.');
            }

            parent::send($notifiables, $notification);
        }
    });

    expect(fn () => app(FinalizePostMediaAction::class)->failed($failedAsset))->toThrow(RuntimeException::class)
        ->and(fn () => app(FinalizePostMediaAction::class)->ready($readyAsset))->toThrow(RuntimeException::class);
    // What the post's status says stays: published, though its author has not heard yet.
    expect($readyPost->refresh()->status)->toBe(PostStatus::Published)
        ->and($failedAsset->refresh()->metadata['failure_notified_at'] ?? null)->toBeNull()
        ->and($readyAsset->refresh()->metadata['ready_notified_at'] ?? null)->toBeNull();

    app(FinalizePostMediaAction::class)->failed($failedAsset);
    app(FinalizePostMediaAction::class)->ready($readyAsset);
    app(FinalizePostMediaAction::class)->ready($readyAsset);

    $notices->assertSentToTimes($author, VideoPostProcessedNotification::class, 2);
});
