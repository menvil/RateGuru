<?php

namespace App\Actions\Posts;

use App\Actions\Languages\QueueLanguageCheckAction;
use App\Actions\Traffic\RecordAccountBonusAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\ImportVideoAssetJob;
use App\Jobs\NotifyFollowersAboutNewPostJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Models\Category;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\User;
use App\Services\Languages\LocalLanguageDetector;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMediaCleaner;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Languages\LanguageProfile;
use App\Support\Media\ImageUploadStorer;
use App\Support\Media\MediaAssetCreator;
use App\Support\Media\VideoUploadStorer;
use App\Support\Observability\DomainLogger;
use App\Support\Posts\PostPublicationRule;
use App\Support\Rating\RatingConfigurationManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * A member's new post, with its photo — or its video or animated GIF, when
 * the post waits as a draft until the media is processed and
 * FinalizePostMediaAction publishes it — taxonomy and author answers; or,
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
        private readonly LocalLanguageDetector $languageDetector,
        private readonly QueueLanguageCheckAction $languageChecks,
        private readonly VideoUploadStorer $videoUploadStorer,
        private readonly PostPublicationRule $publicationRule,
    ) {}

    public function handle(User $user, CreatePostData $data): Post
    {
        if (count(array_filter([$data->imageAssetId, $data->image, $data->video, $data->videoUrl], fn (mixed $media): bool => $media !== null)) > 1) {
            throw new InvalidArgumentException('A post takes one picture, video or stored image.');
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

        if ($data->videoUrl !== null) {
            $this->rateLimiter->hitOrFail(
                key: RateLimitKey::userAction('video-import', $user),
                maxAttempts: (int) config('rate_limits.video_import.max_attempts'),
                decaySeconds: (int) config('rate_limits.video_import.decay_seconds'),
                message: __('ui.rate_limit.uploading'),
            );
        }

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

        // Asked before the file is read at all, and again under the author's
        // lock below, where two submissions at once cannot both pass.
        if ($data->video !== null || $data->videoUrl !== null) {
            $this->assertMayAddVideo($user);
        }

        // A video passes ffprobe and the upload policy here, and is stored as
        // it arrived on the private disk; a refused one stores nothing.
        $storedVideo = $data->video !== null
            ? $this->videoUploadStorer->store($data->video, (int) $user->id)
            : null;
        $storedFile = $storedImage ?? $storedVideo?->media;

        // The language of the title and description, told on this server before the transaction (CPU work); OpenAI
        // reads them again in the background (languages:work).
        $text = $this->languageDetector->read(trim($data->title."\n".($data->description ?? '')));

        try {
            $post = DB::transaction(function () use ($user, $data, $categoryId, $authorAnswers, $storedImage, $storedVideo, $text) {
                $waitsForVideo = $storedVideo !== null || $data->videoUrl !== null;
                // Lock order: Actor User first (docs/architecture/
                // user-lifecycle.md). The policy pre-check above ran on a
                // possibly stale instance — a sanction may have committed
                // since. Trust-level publishing is also decided on the
                // fresh row so it stays consistent with the gate.
                $lockedActor = $this->lockActor($user);

                if ($lockedActor === null || ! $lockedActor->canCreateContent()) {
                    throw CannotCreatePostException::becauseUserIsNotAllowed();
                }

                if ($waitsForVideo) {
                    $this->assertMayAddVideo($lockedActor);
                }

                // A video post is a draft until its media is ready; then
                // FinalizePostMediaAction applies the same rule to it.
                $publishesAtOnce = ! $waitsForVideo && $this->publicationRule->publishesAtOnce($lockedActor);
                $status = match (true) {
                    $waitsForVideo => PostStatus::Draft,
                    $publishesAtOnce => PostStatus::Published,
                    default => PostStatus::Pending,
                };
                $publishedAt = $publishesAtOnce ? now() : null;

                $imageAssetId = match (true) {
                    $data->imageAssetId !== null => $this->lockStoredPostImage($data->imageAssetId),
                    $storedImage !== null => $this->mediaAssetCreator->create($storedImage, MediaKind::PostImage, $user)->id,
                    $storedVideo !== null => $this->mediaAssetCreator->createProcessing($storedVideo, $user)->id,
                    $data->videoUrl !== null => $this->mediaAssetCreator->createAwaitingDownload($user, $data->videoUrl)->id,
                    default => null,
                };

                // Its language from what tells it so far: its text and its author's language (LanguageProfile).
                $language = (new LanguageProfile)->text($text->language(), $text->weight())->author($lockedActor->locale);

                $post = Post::create([
                    'user_id' => $user->id,
                    'title' => $data->title,
                    'description' => $data->description,
                    'source_url' => $data->sourceUrl,
                    'category_id' => $categoryId,
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'image_asset_id' => $imageAssetId,
                    'language' => $language->language(),
                    'language_share' => $language->share(),
                    // Given dates are kept as given; none, and Eloquent stamps the post as ever.
                    ...($data->createdAt !== null ? ['created_at' => $data->createdAt] : []),
                    ...($data->updatedAt !== null ? ['updated_at' => $data->updatedAt] : []),
                ]);

                $this->languageChecks->forPost($post, $text);

                if ($data->tagIds !== []) {
                    $post->tags()->sync($data->tagIds);
                }

                if ($publishedAt !== null) {
                    app(RecordAccountBonusAction::class)->firstPostQuietly((int) $user->id, CarbonImmutable::instance($publishedAt));
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
            if ($storedFile !== null) {
                $this->storedMediaCleaner->deleteIfUnclaimed($storedFile);
            }

            throw $exception;
        }

        $this->logger->info('posts.created', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'status' => $post->status->value,
            'has_image' => $post->image_asset_id !== null,
            'has_video' => $storedVideo !== null || $data->videoUrl !== null,
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

        if ($storedVideo !== null || $data->videoUrl !== null) {
            try {
                // A linked video is fetched first; an uploaded one is here.
                $data->videoUrl !== null
                    ? ImportVideoAssetJob::dispatch((int) $post->image_asset_id)->afterCommit()
                    : ProcessVideoAssetJob::dispatch((int) $post->image_asset_id)->afterCommit();
            } catch (Throwable $exception) {
                // Never queued, so nothing would ever settle it: the asset
                // fails at once rather than counting against the author's
                // limit, and the author is told the upload did not work.
                MediaAsset::query()
                    ->whereKey($post->image_asset_id)
                    ->where('status', MediaStatus::Processing)
                    ->update(['status' => MediaStatus::Failed, 'processing_error' => 'The video could not be queued for processing.']);

                throw $exception;
            }
        } elseif ($post->image_asset_id !== null) {
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

    /**
     * @throws CannotCreatePostException
     */
    private function assertMayAddVideo(User $author): void
    {
        // Only uploads still within the time a job could be working on them:
        // one whose job was lost must not hold the author's place for good.
        $processing = MediaAsset::query()
            ->where('owner_user_id', $author->id)
            ->where('status', MediaStatus::Processing)
            ->whereIn('media_type', [MediaType::Video, MediaType::Animation])
            ->where('created_at', '>=', now()->subMinutes((int) config('uploads.videos.processing_window_minutes')))
            ->count();

        if ($processing >= (int) config('uploads.videos.max_processing_per_author')) {
            throw CannotCreatePostException::becauseVideosAreStillProcessing();
        }
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
