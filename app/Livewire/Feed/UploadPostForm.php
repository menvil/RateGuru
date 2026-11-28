<?php

namespace App\Livewire\Feed;

use App\Actions\Analytics\RecordUserActivityAction;
use App\Actions\Import\ResolvedMediaLink;
use App\Actions\Import\ResolveMediaLinkAction;
use App\Actions\Import\StoreImportedImageAction;
use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\ImageInputSource;
use App\Enums\PostStatus;
use App\Enums\UserActivity;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Import\ImportFetchException;
use App\Exceptions\Import\MediaLinkRefusedException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Categories\ActiveCategoriesQuery;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Services\Media\Exceptions\VideoIngestException;
use App\Services\Media\Exceptions\VideoTooLargeException;
use App\Services\Media\Exceptions\VideoTooLongException;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Posts\PostContentRules;
use App\Support\Rating\RatingConfigurationManager;
use App\Support\Settings\ProjectSettingsManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class UploadPostForm extends Component
{
    use WithFileUploads;

    public string $title = '';

    public ?string $description = null;

    public ?string $sourceUrl = null;

    public array $tagIds = [];

    // Standalone category id, kept as a string because it is bound to a native
    // <select> ('' = not selected).
    public string $categoryId = '';

    // "From the author" section: toggle + one optional answer per active rating
    // group, keyed by group id ('' = not selected).
    public bool $knowsCorrectAnswer = false;

    /** @var array<int|string, string|null> */
    public array $authorAnswers = [];

    public $image = null;

    /**
     * A video or animated GIF in place of a picture: the dropzone sends a file
     * here or to $image by its type, never to both.
     */
    public $video = null;

    /** Whether the link being posted brought a video, so no picture is asked for. */
    private bool $linksToVideo = false;

    public ?string $importedImageUrl = null;

    /**
     * What the link field shows before posting, once the link is read: its
     * still, and whether it is a video and how long. Shown, never trusted.
     *
     * @var array{video: bool, image: ?string, duration: ?string}|null
     */
    public ?array $linkPreview = null;

    /** The link last read for its preview: posting that same link takes what it came to, never reading it twice. */
    #[Locked]
    public string $previewedLink = '';

    /** @var array<string, mixed>|null what $previewedLink came to (ResolvedMediaLink::toArray) */
    #[Locked]
    public ?array $previewedResolution = null;

    public string $activeTab = 'upload';

    public ?string $submitError = null;

    public array $tags = [];

    public string $tagSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->refuseWhileUploadsArePaused();

        $this->loadTags();
    }

    #[On('upload-modal-opened')]
    public function resetUploadForm(): void
    {
        $this->reset(['title', 'description', 'sourceUrl', 'image', 'video', 'importedImageUrl', 'tagIds', 'tagSearch', 'submitError', 'categoryId', 'knowsCorrectAnswer', 'authorAnswers', 'linkPreview', 'previewedLink', 'previewedResolution']);
        $this->loadTags();
        $this->activeTab = 'upload';
        $this->resetValidation();
    }

    /**
     * Reads a link as it is pasted — through the same Action posting uses,
     * its rate limit and its rules — and shows what it would post: its
     * picture, or its video's still and length; or why not, at once rather
     * than after Publish. Nothing is fetched for an account that may not post.
     */
    public function previewLink(mixed $url = null): void
    {
        abort_unless(auth()->check(), 403);

        $url = is_string($url) ? trim($url) : '';
        $this->importedImageUrl = $url !== '' ? $url : null;
        $this->resetErrorBag('importedImageUrl');
        $this->linkPreview = null;

        if ($url === '') {
            $this->previewedLink = '';
            $this->previewedResolution = null;

            return;
        }

        if ($url === $this->previewedLink && $this->previewedResolution !== null) {
            $this->linkPreview = self::previewOf(ResolvedMediaLink::fromArray($this->previewedResolution));

            return;
        }

        $author = auth()->user();

        if (! $author instanceof User || ! $author->can('create', Post::class) || $author->needsAddressConfirmation()
            || ! app(ProjectSettingsManager::class)->featureEnabled('allow_user_uploads')) {
            return;
        }

        $this->validateOnly('importedImageUrl');

        try {
            $resolved = app(ResolveMediaLinkAction::class)->handle($author, $url);
        } catch (\Throwable $exception) {
            $this->addError('importedImageUrl', self::linkError($exception));

            return;
        }

        $this->previewedLink = $url;
        $this->previewedResolution = $resolved->toArray();
        $this->linkPreview = self::previewOf($resolved);
    }

    public function submit(): void
    {
        abort_unless(auth()->check(), 403);
        // Checked again here: a page drawn before uploads were paused still
        // has this form, and pausing them must stop every new post.
        $this->refuseWhileUploadsArePaused();

        $createPostAction = app(CreatePostAction::class);

        $this->submitError = null;
        $imageSource = ImageInputSource::Upload;

        // Said before anything is fetched or stored: an account that has not
        // confirmed its address may not post, and is told how to.
        if (auth()->user()?->needsAddressConfirmation()) {
            $this->submitError = __('ui.account_confirmation.required');

            return;
        }

        // Captured separately from $this->image: a successful submission
        // below resets $this->image to null, but this local reference must
        // still point at the temp file so the finally block can clean it up
        // either way.
        $importedTempFile = null;
        $videoUrl = null;
        // The post's source when the author named none: never written into the
        // form, so a failed submission leaves the field as the author left it.
        $linkSource = null;

        // A link field emptied is no link: the picture is then simply missing.
        if (filled($this->importedImageUrl) && $this->image === null && ! $this->video instanceof UploadedFile) {
            // The link is the client's to set: nothing is fetched for an
            // account that may not post, whatever it sends.
            if (! auth()->user()?->can('create', Post::class)) {
                $this->submitError = __('ui.upload.error_generic');

                return;
            }

            // A link that is no web address is said to be one, not tried and
            // answered with "could not fetch".
            $this->validateOnly('importedImageUrl');

            try {
                // What the link brings — a picture now, or a video once the
                // post exists — and where it came from, decided by the Action.
                // Imports switched off since the preview are off for it too.
                if (! app(ProjectSettingsManager::class)->featureEnabled('allow_url_imports')) {
                    throw new UrlImportDisabledException;
                }

                // The link its preview read is taken as it came to, once:
                // neither read twice nor counted twice against the import
                // limit. A submit that fails after it — a missing title —
                // reads it again, counted, on the next try.
                $cached = trim((string) $this->importedImageUrl) === $this->previewedLink && $this->previewedResolution !== null
                    ? ResolvedMediaLink::fromArray($this->previewedResolution)
                    : null;
                $this->previewedLink = '';
                $this->previewedResolution = null;
                $resolved = $cached ?? app(ResolveMediaLinkAction::class)->handle(auth()->user(), $this->importedImageUrl);
                $videoUrl = $resolved->videoUrl;
                $imageUrl = $resolved->imageUrl;

                if (blank($this->sourceUrl)) {
                    $linkSource = $resolved->source;
                }

                $this->linksToVideo = $videoUrl !== null;

                if ($imageUrl !== null) {
                    $this->image = app(StoreImportedImageAction::class)->download($imageUrl);
                    $importedTempFile = $this->image;
                    $imageSource = ImageInputSource::UrlImport;
                }
            } catch (\Throwable $e) {
                $this->submitError = self::linkError($e);

                return;
            }
        }

        try {
            $this->validate();

            try {
                $post = $createPostAction->handle(auth()->user(), new CreatePostData(
                    title: $this->title,
                    description: $this->description,
                    sourceUrl: filled($this->sourceUrl) ? $this->sourceUrl : $linkSource,
                    tagIds: $this->tagIds,
                    image: $this->video instanceof UploadedFile || $videoUrl !== null ? null : $this->image,
                    imageSource: $imageSource,
                    categoryId: $this->categoryId !== '' ? (int) $this->categoryId : null,
                    authorAnswerOptionIds: $this->selectedAuthorAnswerOptionIds(),
                    video: $this->video instanceof UploadedFile ? $this->video : null,
                    videoUrl: $videoUrl,
                ));

                $author = auth()->user();

                if ($author instanceof User) {
                    app(RecordUserActivityAction::class)->quietly($author, UserActivity::Posted, (int) $post->id, app(GameVisitorResolver::class)->browserHash(), CarbonImmutable::now());
                }

                $this->dispatch('post-uploaded', postId: $post->id);
                // A trusted author's post is published at once; anyone else's
                // waits for a moderator, and a video post waits for its video
                // first. The message says which.
                $this->dispatch('toast', message: match ($post->status) {
                    PostStatus::Published => __('ui.upload.success_published'),
                    PostStatus::Draft => __('ui.upload.success_video_processing'),
                    default => __('ui.upload.success_pending'),
                });

                // Its original is stored now; the temporary upload would only
                // wait a day for Livewire's clean-up, up to 100 MB at a time.
                if ($this->video instanceof TemporaryUploadedFile) {
                    $this->video->delete();
                }

                $this->reset(['title', 'description', 'sourceUrl', 'image', 'video', 'tagIds', 'categoryId', 'knowsCorrectAnswer', 'authorAnswers', 'linkPreview', 'previewedLink', 'previewedResolution']);
                $this->importedImageUrl = null;
                $this->activeTab = 'upload';
                $this->tagSearch = '';
            } catch (RateLimitExceededException $e) {
                $this->submitError = $e->getMessage();
            } catch (ImageIngestException $e) {
                report($e);
                $this->submitError = __('ui.upload.error_invalid_image');
            } catch (CannotCreatePostException $e) {
                if ($e->isWaitingForVideos()) {
                    $this->submitError = __('ui.upload.error_videos_processing', ['count' => (int) config('uploads.videos.max_processing_per_author')]);
                } else {
                    report($e);
                    $this->submitError = __('ui.upload.error_generic');
                }
            } catch (VideoIngestException $e) {
                // The file's fault, not the site's: said, never reported.
                $this->submitError = match (true) {
                    $e instanceof VideoTooLongException => __('ui.upload.error_video_too_long', ['seconds' => (int) config('uploads.videos.max_duration_seconds')]),
                    $e instanceof VideoTooLargeException => __('ui.upload.error_video_too_large', ['megabytes' => intdiv((int) config('uploads.videos.max_kilobytes'), 1024)]),
                    default => __('ui.upload.error_invalid_video'),
                };
            } catch (\Throwable $e) {
                report($e);
                $this->submitError = __('ui.upload.error_generic');
            }
        } finally {
            // Runs whether validation itself failed (still propagating past
            // this block for Livewire's normal field-error handling),
            // createPostAction failed, or the submission succeeded — the
            // one thing that's never appropriate is leaving this temp file
            // behind.
            if ($importedTempFile !== null) {
                app(StoreImportedImageAction::class)->cleanup($importedTempFile);

                // On a failure path the success branch's own reset() above
                // never ran, so $this->image would otherwise keep pointing
                // at the file cleanup() just deleted — a retry would try to
                // reuse a now-missing file instead of re-downloading.
                // importedImageUrl is deliberately left untouched so that
                // retry has something to download from.
                if ($this->image === $importedTempFile) {
                    $this->image = null;
                }
            }
        }
    }

    /**
     * Pausing uploads in Project settings stops members creating posts, not
     * only the button that opens this form. Content Studio publishes through
     * CreatePostAction on its own and is not paused by it.
     */
    /** What a link that could not be taken says to its author; what is no fault of theirs is reported too. */
    private static function linkError(\Throwable $exception): string
    {
        return match (true) {
            $exception instanceof MediaLinkRefusedException, $exception instanceof RateLimitExceededException => $exception->getMessage(),
            $exception instanceof UrlImportDisabledException => __('import.errors.feature_disabled'),
            $exception instanceof UnsafeImportUrlException => __('import.errors.unsafe_url'),
            // The site refused this server (ImportRefusalMonitor counts it,
            // and reports a site that keeps refusing), or answered that the
            // link leads nowhere: the author's to act on, not an error of ours.
            $exception instanceof ImportFetchException && $exception->refusedByTheSite() => __('import.errors.site_refusing'),
            $exception instanceof ImportFetchException && $exception->answeredNoSuchPage() => __('import.errors.fetch_failed'),
            default => (function () use ($exception): string {
                report($exception);

                return __('import.errors.fetch_failed');
            })(),
        };
    }

    /** @return array{video: bool, image: ?string, duration: ?string} */
    private static function previewOf(ResolvedMediaLink $resolved): array
    {
        $seconds = $resolved->durationSeconds;

        return [
            'video' => $resolved->isVideo(),
            'image' => $resolved->previewImageUrl,
            'duration' => $seconds === null ? null : intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT),
        ];
    }

    private function refuseWhileUploadsArePaused(): void
    {
        abort_unless(app(ProjectSettingsManager::class)->featureEnabled('allow_user_uploads'), 403);
    }

    protected function rules(): array
    {
        $imageMimes = implode(',', config('uploads.images.mimes', ['jpg', 'jpeg', 'png', 'webp']));
        $ratingGroups = app(RatingConfigurationManager::class)->activeGroups();

        return [
            'title' => ['required', 'string', 'min:'.PostContentRules::TITLE_MIN_LENGTH, 'max:'.PostContentRules::TITLE_LIMIT],
            'description' => ['nullable', 'string', 'max:'.PostContentRules::DESCRIPTION_LIMIT],
            'image' => [
                // A post needs its picture, unless a video takes its place.
                Rule::requiredIf(fn (): bool => ! $this->video instanceof UploadedFile && ! $this->linksToVideo),
                'nullable',
                'image',
                'mimes:'.$imageMimes,
                'max:'.config('uploads.images.max_kilobytes', 5120),
                Rule::dimensions()
                    ->maxWidth((int) config('uploads.images.max_width', 6000))
                    ->maxHeight((int) config('uploads.images.max_height', 6000)),
            ],
            // What the file really is decides, not its name: ffprobe checks it
            // again before it is stored.
            'video' => [
                'nullable',
                'file',
                'mimetypes:'.implode(',', config('uploads.videos.mimetypes')),
                'max:'.config('uploads.videos.max_kilobytes'),
            ],
            'sourceUrl' => ['nullable', 'url', 'max:'.PostContentRules::SOURCE_URL_LIMIT],
            'importedImageUrl' => ['nullable', 'url:http,https', 'max:'.PostContentRules::SOURCE_URL_LIMIT],
            'tagIds' => ['array', 'max:'.PostContentRules::MOST_TAGS],
            'tagIds.*' => ['integer', 'exists:tags,id'],
            'categoryId' => [
                'nullable',
                'bail',
                'integer',
                Rule::exists('categories', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
            'authorAnswers' => ['array'],
            'authorAnswers.*' => [Rule::in($this->authorAnswerChoices($ratingGroups))],
        ];
    }

    /**
     * Valid <select> values for author answers: '' (not selected) plus every
     * active option id across all active rating groups. Group/option pairing
     * is enforced by CreatePostAction.
     *
     * @param  Collection<int, RatingGroup>  $ratingGroups
     * @return list<string>
     */
    private function authorAnswerChoices(Collection $ratingGroups): array
    {
        return [
            '',
            ...array_map(
                fn (int $optionId): string => (string) $optionId,
                app(RatingConfigurationManager::class)->allActiveOptionIds($ratingGroups),
            ),
        ];
    }

    /**
     * @return list<int>
     */
    private function selectedAuthorAnswerOptionIds(): array
    {
        if (! $this->knowsCorrectAnswer) {
            return [];
        }

        return collect($this->authorAnswers)
            ->filter(fn ($optionId): bool => $optionId !== '' && $optionId !== null)
            ->map(fn ($optionId): int => (int) $optionId)
            ->unique()
            ->values()
            ->all();
    }

    #[On('import-preview-selected')]
    public function applyImportPreview(array $preview): void
    {
        $this->title = $preview['title'] ?? $this->title;
        $this->description = $preview['description'] ?? $this->description;
        $this->sourceUrl = $preview['sourceUrl'] ?? $this->sourceUrl;
        $this->importedImageUrl = $preview['imageUrl'] ?? null;
        $this->activeTab = 'upload';
    }

    public function render(ActiveCategoriesQuery $activeCategories): View
    {
        $ratingGroups = app(RatingConfigurationManager::class)->activeGroups();

        return view('livewire.feed.upload-post-form', [
            'needsConfirmation' => auth()->user()?->needsAddressConfirmation() ?? false,
            'ratingGroups' => $ratingGroups,
            'categories' => $activeCategories->get(),
            'tags' => $this->tags,
            'selectedTags' => $this->selectedTags(),
            'popularTags' => $this->popularTags(),
            'filteredTags' => $this->filteredTags(),
            'unselectedTags' => $this->unselectedTags(),
        ]);
    }

    public function toggleTag(int $tagId): void
    {
        $tagIds = collect($this->tagIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        if ($tagIds->contains($tagId)) {
            $this->tagIds = $tagIds
                ->reject(fn (int $id): bool => $id === $tagId)
                ->values()
                ->all();

            return;
        }

        if ($tagIds->count() >= PostContentRules::MOST_TAGS) {
            return;
        }

        $this->tagIds = $tagIds
            ->push($tagId)
            ->values()
            ->all();

        $this->tagSearch = '';
    }

    private function loadTags(): void
    {
        $this->tags = Tag::query()
            ->withCount('posts')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'posts_count' => $tag->posts_count,
            ])
            ->all();
    }

    private function selectedTags(): array
    {
        $selectedIds = collect($this->tagIds)->map(fn ($id): int => (int) $id)->all();

        return collect($this->tags)
            ->filter(fn (array $tag): bool => in_array((int) $tag['id'], $selectedIds, true))
            ->values()
            ->all();
    }

    private function popularTags(): array
    {
        return collect($this->tags)
            ->sortByDesc(fn (array $tag): int => (int) ($tag['posts_count'] ?? 0))
            ->take(4)
            ->values()
            ->all();
    }

    private function filteredTags(): array
    {
        $search = trim($this->tagSearch);
        $tags = collect($this->tags);

        if ($search !== '') {
            $tags = $tags->filter(fn (array $tag): bool => str_contains(
                mb_strtolower($tag['name']),
                mb_strtolower($search),
            ));
        }

        return $tags
            ->take(12)
            ->values()
            ->all();
    }

    private function unselectedTags(): array
    {
        $selectedIds = collect($this->tagIds)->map(fn ($id): int => (int) $id)->all();

        return collect($this->filteredTags())
            ->filter(fn (array $tag): bool => ! in_array((int) $tag['id'], $selectedIds, true))
            ->values()
            ->all();
    }
}
