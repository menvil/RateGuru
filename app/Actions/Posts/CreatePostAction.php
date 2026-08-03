<?php

namespace App\Actions\Posts;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use App\Services\Images\ImageStorage;
use App\Services\Images\StoredMedia;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Media\ImageOrientationClassifier;
use App\Support\Observability\DomainLogger;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CreatePostAction
{
    public function __construct(
        private readonly ImageStorage $imageStorage,
        private readonly ActionRateLimiter $rateLimiter,
        private readonly DomainLogger $logger,
        private readonly RatingConfigurationManager $ratingConfiguration,
        private readonly ImageOrientationClassifier $orientationClassifier,
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
        $storedImage = $data->image !== null
            ? $this->imageStorage->storePostImage($data->image, $user)
            : null;

        $post = DB::transaction(function () use ($user, $data, $status, $publishedAt, $categoryId, $authorAnswers, $storedImage) {
            $imageAsset = $storedImage !== null
                ? $this->createImageAsset($storedImage, $user)
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

        return $post;
    }

    private function createImageAsset(StoredMedia $stored, User $user): MediaAsset
    {
        $hasValidDimensions = $stored->width !== null
            && $stored->height !== null
            && $stored->width > 0
            && $stored->height > 0;

        return MediaAsset::create([
            'owner_user_id' => $user->id,
            'kind' => MediaKind::PostImage,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->mimeType,
            'extension' => $stored->extension,
            'byte_size' => $stored->byteSize,
            'width' => $stored->width,
            'height' => $stored->height,
            'aspect_ratio' => $hasValidDimensions
                ? round($stored->width / $stored->height, 6)
                : null,
            'orientation' => $hasValidDimensions
                ? $this->orientationClassifier->classify($stored->width, $stored->height)
                : null,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
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
