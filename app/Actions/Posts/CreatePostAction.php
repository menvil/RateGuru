<?php

namespace App\Actions\Posts;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\Category;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMediaCleaner;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Media\ImageUploadStorer;
use App\Support\Media\MediaAssetCreator;
use App\Support\Observability\DomainLogger;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CreatePostAction
{
    public function __construct(
        private readonly ImageUploadStorer $imageUploadStorer,
        private readonly ActionRateLimiter $rateLimiter,
        private readonly DomainLogger $logger,
        private readonly RatingConfigurationManager $ratingConfiguration,
        private readonly MediaAssetCreator $mediaAssetCreator,
        private readonly StoredMediaCleaner $storedMediaCleaner,
    ) {}

    public function handle(User $user, CreatePostData $data): Post
    {
        if (! $user->can('create', Post::class)) {
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

        $ratingGroups = $this->ratingConfiguration->activeGroups();
        $categoryId = $this->validatedCategoryId($data->categoryId);
        $authorAnswers = $this->validatedAuthorAnswers($data->authorAnswerOptionIds, $ratingGroups);

        // Storing the file is slow, external I/O that doesn't belong inside a
        // DB transaction — do it first, then only touch the database below.
        // ImageUploadStorer routes every user-controlled image (direct
        // upload or URL-imported, both arrive here as an UploadedFile)
        // through ImageIngestor before MediaStorage ever sees a byte.
        $storedImage = $data->image !== null
            ? $this->imageUploadStorer->store($data->image, MediaStoreRequest::forPostImage($user->id), $data->imageSource)
            : null;

        try {
            $post = DB::transaction(function () use ($user, $data, $status, $publishedAt, $categoryId, $authorAnswers, $storedImage) {
                $imageAsset = $storedImage !== null
                    ? $this->mediaAssetCreator->create($storedImage, MediaKind::PostImage, $user)
                    : null;

                $post = Post::create([
                    'user_id' => $user->id,
                    'title' => $data->title,
                    'description' => $data->description,
                    'source_url' => $data->sourceUrl,
                    'category_id' => $categoryId,
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'image_asset_id' => $imageAsset?->id,
                ]);

                if ($data->tagIds !== []) {
                    $post->tags()->sync($data->tagIds);
                }

                if ($authorAnswers !== []) {
                    $post->authorAnswers()->createMany($authorAnswers);
                }

                return $post;
            });
        } catch (Throwable $exception) {
            // The file was already written before the transaction started; a
            // DB failure past that point (asset insert, post insert, tag
            // sync, author answers) must not leave it orphaned on disk.
            if ($storedImage !== null) {
                $this->storedMediaCleaner->deleteIfUnclaimed($storedImage);
            }

            throw $exception;
        }

        $this->logger->info('posts.created', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'status' => $post->status->value,
            'has_image' => $post->image_asset_id !== null,
        ]);

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

        if ($post->image_asset_id !== null) {
            try {
                // ->afterCommit(): the structural "after DB::transaction()
                // returns" placement above is enough given how this action
                // is called today (no outer transaction wraps it), but
                // afterCommit() also makes it correct if that ever changes —
                // Laravel simply runs it immediately when there's no open
                // transaction, so this isn't a behavior change today.
                GenerateMediaVariantsJob::dispatch($post->image_asset_id)->afterCommit();
            } catch (Throwable $exception) {
                report($exception);

                // Under QUEUE_CONNECTION=sync (the only connection this app
                // runs today), dispatch() executes the job inline — reaching
                // this catch means the job ran and failed, not merely that
                // dispatch itself failed to enqueue.
                Log::error('Failed to dispatch or run media variant generation.', [
                    'post_id' => $post->id,
                    'media_asset_id' => $post->image_asset_id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $post;
    }

    private function validatedCategoryId(?int $categoryId): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        if (! Category::query()->active()->whereKey($categoryId)->exists()) {
            throw CannotCreatePostException::becauseCategoryIsInvalid();
        }

        return $categoryId;
    }

    /**
     * @param  array<int>  $authorAnswerOptionIds
     * @param  Collection<int, RatingGroup>  $ratingGroups
     * @return list<array{rating_group_id: int, rating_option_id: int}>
     */
    private function validatedAuthorAnswers(array $authorAnswerOptionIds, Collection $ratingGroups): array
    {
        if ($authorAnswerOptionIds === []) {
            return [];
        }

        $answers = [];

        foreach ($authorAnswerOptionIds as $optionId) {
            $group = $ratingGroups->first(
                fn ($group): bool => $group->options->firstWhere('id', (int) $optionId) !== null,
            );

            if ($group === null || isset($answers[$group->id])) {
                throw CannotCreatePostException::becauseAuthorAnswerIsInvalid();
            }

            $answers[$group->id] = [
                'rating_group_id' => $group->id,
                'rating_option_id' => (int) $optionId,
            ];
        }

        return array_values($answers);
    }
}
