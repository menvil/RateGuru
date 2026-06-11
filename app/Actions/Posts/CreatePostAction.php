<?php

namespace App\Actions\Posts;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Data\Posts\CreatePostData;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\ProcessUploadedImageJob;
use App\Models\Post;
use App\Models\User;
use App\Services\Images\ImageStorage;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CreatePostAction
{
    public function __construct(
        private readonly ImageStorage $imageStorage,
        private readonly ActionRateLimiter $rateLimiter,
        private readonly DomainLogger $logger,
    ) {}

    public function handle(User $user, CreatePostData $data): Post
    {
        if (! $user->canCreateContent()) {
            throw CannotCreatePostException::becauseUserIsNotAllowed();
        }

        $this->rateLimiter->hitOrFail(
            key: RateLimitKey::userAction('upload', $user),
            maxAttempts: (int) config('rate_limits.upload.max_attempts'),
            decaySeconds: (int) config('rate_limits.upload.decay_seconds'),
            message: 'You are uploading too quickly. Please try again later.',
        );

        $isTrusted = $user->trust_level >= MarkUserTrustedAction::TRUSTED_LEVEL
            && $user->status === UserStatus::Active;

        $status = $isTrusted ? PostStatus::Published : PostStatus::Pending;
        $publishedAt = $isTrusted ? now() : null;

        $post = DB::transaction(function () use ($user, $data, $status, $publishedAt) {
            $storedImage = $data->image !== null
                ? $this->imageStorage->storePostImage($data->image, $user)
                : null;

            $post = Post::create([
                'user_id' => $user->id,
                'title' => $data->title,
                'description' => $data->description,
                'source_url' => $data->sourceUrl,
                'origin_truth' => $data->originTruth,
                'cuisine_truth' => $data->cuisineTruth,
                'status' => $status,
                'published_at' => $publishedAt,
                'image_path' => $storedImage?->path,
                'image_url' => $storedImage?->url,
                'thumbnail_url' => $storedImage?->thumbnailUrl,
            ]);

            if ($data->tagIds !== []) {
                $post->tags()->sync($data->tagIds);
            }

            return $post;
        });

        $this->logger->info('posts.created', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'status' => $post->status->value,
            'has_image' => $post->image_path !== null,
        ]);

        if ($post->image_path !== null) {
            ProcessUploadedImageJob::dispatch($post->id);
        }

        if ($post->status === PostStatus::Published) {
            try {
                NotifyFollowersAboutNewPostJob::dispatch($post->id);
            } catch (Throwable $exception) {
                report($exception);

                Log::error('Failed to dispatch follower notification job.', [
                    'post_id' => $post->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $post;
    }
}
