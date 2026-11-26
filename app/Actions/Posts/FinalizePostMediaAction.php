<?php

namespace App\Actions\Posts;

use App\Actions\Traffic\RecordAccountBonusAction;
use App\Enums\MediaStatus;
use App\Enums\PostStatus;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Notifications\VideoPostProcessedNotification;
use App\Support\Observability\DomainLogger;
use App\Support\Posts\PostPublicationRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The end of a video post's wait. A post whose video or GIF is Ready leaves
 * its draft under the rule every new post follows — published at once or
 * waiting for a moderator — decided on the author's row as it is now, and
 * its author is told. A post whose media Failed stays a draft, and its author
 * is told that too. An author who may no longer post publishes nothing; the
 * draft stays, and the log says why.
 *
 * Safe to run again: only a draft is ever published, and the author hears
 * of each outcome once per asset — the notice is claimed on the asset in the
 * same transaction that writes it, so two runs never both send it, and one
 * that could not be written leaves it unclaimed for the job's next attempt.
 */
final class FinalizePostMediaAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly PostPublicationRule $publicationRule,
        private readonly DomainLogger $logger,
    ) {}

    public function ready(MediaAsset $asset): void
    {
        $post = DB::transaction(function () use ($asset): ?Post {
            $post = Post::query()->where('image_asset_id', $asset->id)->first();

            if ($post === null || $post->status !== PostStatus::Draft || $post->user === null) {
                return null;
            }

            // Lock order: the author first, then the post
            // (docs/architecture/user-lifecycle.md).
            $author = $this->lockActor($post->user);
            $post = Post::query()->whereKey($post->id)->lockForUpdate()->first();

            if ($author === null || $post === null || $post->status !== PostStatus::Draft
                || $asset->fresh()?->status !== MediaStatus::Ready) {
                return null;
            }

            // An author who may no longer post — suspended or banned while
            // the video was processed — publishes nothing; the draft stays.
            if (! $author->canCreateContent()) {
                $this->logger->info('posts.media_ready_held', [
                    'post_id' => $post->id,
                    'media_asset_id' => $asset->id,
                    'reason' => 'author_cannot_create_content',
                ]);

                return null;
            }

            $publishesAtOnce = $this->publicationRule->publishesAtOnce($author);

            $post->forceFill([
                'status' => $publishesAtOnce ? PostStatus::Published : PostStatus::Pending,
                'published_at' => $publishesAtOnce ? now() : null,
            ])->save();

            if ($publishesAtOnce) {
                app(RecordAccountBonusAction::class)->firstPostQuietly((int) $post->user_id, CarbonImmutable::instance($post->published_at));
            }

            return $post;
        });

        if ($post !== null) {
            $this->logger->info('posts.media_ready', [
                'post_id' => $post->id,
                'media_asset_id' => $asset->id,
                'status' => $post->status->value,
            ]);

            if ($post->status === PostStatus::Published) {
                try {
                    NotifyFollowersAboutNewPostJob::dispatch($post->id);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        // Also when an earlier run published the post and could not write
        // the notice: the post left its draft through this asset, and its
        // author has not heard yet.
        $post ??= Post::query()->where('image_asset_id', $asset->id)->whereIn('status', [PostStatus::Published, PostStatus::Pending])->first();

        if ($post !== null) {
            $this->tellAuthorOnce($asset, $post, 'ready_notified_at');
        }
    }

    /**
     * The media will not be processed: the post stays a draft, and its
     * author learns why nothing appeared.
     */
    public function failed(MediaAsset $asset): void
    {
        $post = Post::query()->where('image_asset_id', $asset->id)->first();

        if ($post === null || $post->status !== PostStatus::Draft) {
            return;
        }

        if ($this->tellAuthorOnce($asset, $post, 'failure_notified_at')) {
            $this->logger->info('posts.media_failed', [
                'post_id' => $post->id,
                'media_asset_id' => $asset->id,
            ]);
        }
    }

    /**
     * Tells the author, once per asset and outcome: the claim on the asset
     * and the notice commit together. A notice that cannot be written takes
     * the claim back with it and goes up, for the job's next attempt to send
     * — never undoing what the post's status already says.
     */
    private function tellAuthorOnce(MediaAsset $asset, Post $post, string $claim): bool
    {
        return DB::transaction(function () use ($asset, $post, $claim): bool {
            $claimed = MediaAsset::query()
                ->whereKey($asset->id)
                ->whereNull("metadata->{$claim}")
                ->update(['metadata' => json_encode([...(MediaAsset::query()->whereKey($asset->id)->value('metadata') ?? []), $claim => now()->toIso8601ZuluString()])]);

            if ($claimed !== 1) {
                return false;
            }

            $post->user?->notify(new VideoPostProcessedNotification($post));

            return true;
        });
    }
}
