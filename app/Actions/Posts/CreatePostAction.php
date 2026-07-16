<?php

namespace App\Actions\Posts;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Data\Posts\CreatePostData;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Jobs\ProcessUploadedImageJob;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use App\Services\Images\ImageStorage;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
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
        $categoryOptionId = $this->validatedCategoryOptionId($data->categoryOptionId, $ratingGroups);
        $authorAnswers = $this->validatedAuthorAnswers($data->authorAnswerOptionIds, $ratingGroups);

        $post = DB::transaction(function () use ($user, $data, $status, $publishedAt, $categoryOptionId, $authorAnswers) {
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
                'category_option_id' => $categoryOptionId,
                'status' => $status,
                'published_at' => $publishedAt,
                'image_path' => $storedImage?->path,
                'image_url' => $storedImage?->url,
                'thumbnail_url' => $storedImage?->thumbnailUrl,
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

    /** @param Collection<int, RatingGroup> $ratingGroups */
    private function validatedCategoryOptionId(?int $categoryOptionId, Collection $ratingGroups): ?int
    {
        if ($categoryOptionId === null) {
            return null;
        }

        // The post category must be an active option of the first active rating
        // group — the same group the sidebar "Categories" block is built from.
        if (! in_array($categoryOptionId, $this->ratingConfiguration->sidebarGroupOptionIds($ratingGroups), true)) {
            throw CannotCreatePostException::becauseCategoryOptionIsInvalid();
        }

        return $categoryOptionId;
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
