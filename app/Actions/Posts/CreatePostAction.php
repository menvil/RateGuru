<?php

namespace App\Actions\Posts;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Models\Category;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\MediaAsset;
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
use InvalidArgumentException;
use Throwable;

/**
 * A member's new post, with its photo, taxonomy and author answers — or,
 * through the trusted fields of CreatePostData that only Content Studio's
 * executor sets, a Persona's approved plan published as a post: its photo,
 * already stored and Ready, used as it is, and its own dates. Either way the
 * post's status follows the same rules, and the same limits and checks apply.
 */
final class CreatePostAction
{
    use LocksActorForWrite;

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
        if ($data->imageAssetId !== null && $data->image !== null) {
            throw new InvalidArgumentException('A post takes a new file or a stored image, not both.');
        }

        if (! $user->can('create', Post::class)) {
            throw CannotCreatePostException::becauseUserIsNotAllowed();
        }

        $this->rateLimiter->hitOrFail(
            key: RateLimitKey::userAction('upload', $user),
            maxAttempts: (int) config('rate_limits.upload.max_attempts'),
            decaySeconds: (int) config('rate_limits.upload.decay_seconds'),
            message: __('ui.rate_limit.uploading'),
        );

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
            $post = DB::transaction(function () use ($user, $data, $categoryId, $authorAnswers, $storedImage) {
                // Lock order: Actor User first (docs/architecture/
                // user-lifecycle.md). The policy pre-check above ran on a
                // possibly stale instance — a sanction may have committed
                // since. Trust-level publishing is also decided on the
                // fresh row so it stays consistent with the gate.
                $lockedActor = $this->lockActor($user);

                if ($lockedActor === null || ! $lockedActor->canCreateContent()) {
                    throw CannotCreatePostException::becauseUserIsNotAllowed();
                }

                // Published at once only for a trusted author who has also
                // proved the email address is theirs: every new account is
                // trusted, so trust alone would publish whatever an account
                // registered with any address posts. Staff — Admin and
                // Moderator, never "anyone but a User" — are exempt from the
                // email half, since they could approve the post themselves.
                // A Persona's post always waits, whatever its trust or address:
                // it is published only by an administrator's approval, so a
                // confirmed address on its row — which it should never have —
                // publishes nothing.
                $publishesAtOnce = ! $lockedActor->isPersona()
                    && $lockedActor->trust_level >= MarkUserTrustedAction::TRUSTED_LEVEL
                    && ($lockedActor->hasVerifiedEmail() || $lockedActor->isStaff());
                $status = $publishesAtOnce ? PostStatus::Published : PostStatus::Pending;
                $publishedAt = $publishesAtOnce ? now() : null;

                $imageAssetId = $data->imageAssetId !== null
                    ? $this->lockStoredPostImage($data->imageAssetId)
                    : ($storedImage !== null ? $this->mediaAssetCreator->create($storedImage, MediaKind::PostImage, $user)->id : null);

                $post = Post::create([
                    'user_id' => $user->id,
                    'title' => $data->title,
                    'description' => $data->description,
                    'source_url' => $data->sourceUrl,
                    'category_id' => $categoryId,
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'image_asset_id' => $imageAssetId,
                    // Given dates are kept as given; none, and Eloquent stamps the post as ever.
                    ...($data->createdAt !== null ? ['created_at' => $data->createdAt] : []),
                    ...($data->updatedAt !== null ? ['updated_at' => $data->updatedAt] : []),
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
                // returns" placement above is enough for the upload form,
                // which wraps this action in no transaction — Laravel then
                // runs it immediately. Content Studio's executor does wrap
                // it, and afterCommit() holds the job until that outer
                // transaction commits, so a rolled-back run sends nothing.
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

    /**
     * A post image already stored, used as it is — no storer, no new asset,
     * no copy, its owner kept — once it is found, locked after the author's
     * row as every write takes its locks, to be a Ready post image that is
     * not deleted and that no post, not even a deleted one that may be
     * restored, uses yet. Two posts given one image take turns on its row,
     * and the second finds it used.
     *
     * @throws CannotCreatePostException
     */
    private function lockStoredPostImage(int $assetId): int
    {
        $asset = MediaAsset::withTrashed()->whereKey($assetId)->lockForUpdate()->first();

        if ($asset === null
            || $asset->trashed()
            || $asset->kind !== MediaKind::PostImage
            || $asset->status !== MediaStatus::Ready
            || Post::withTrashed()->where('image_asset_id', $asset->id)->exists()) {
            throw CannotCreatePostException::becauseImageIsUnavailable();
        }

        return (int) $asset->id;
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
