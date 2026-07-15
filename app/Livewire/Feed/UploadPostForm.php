<?php

namespace App\Livewire\Feed;

use App\Actions\Import\StoreImportedImageAction;
use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Models\RatingGroup;
use App\Models\Tag;
use App\Support\Rating\RatingConfigurationManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

final class UploadPostForm extends Component
{
    use WithFileUploads;

    public string $title = '';

    public ?string $description = null;

    public ?string $sourceUrl = null;

    public string $originTruth = 'unknown';

    public string $cuisineTruth = 'unknown';

    public array $tagIds = [];

    // Author-chosen feed category: option id of the first active rating group
    // (the sidebar "Categories" group), kept as a string because it is bound to
    // a native <select> ('' = not selected).
    public string $categoryOptionId = '';

    // "From the author" section: toggle + one optional answer per active rating
    // group, keyed by group id ('' = not selected).
    public bool $knowsCorrectAnswer = false;

    /** @var array<int|string, string> */
    public array $authorAnswers = [];

    public $image = null;

    public ?string $importedImageUrl = null;

    public string $activeTab = 'upload';

    public ?string $submitError = null;

    public array $tags = [];

    public string $tagSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $this->loadTags();
    }

    #[On('upload-modal-opened')]
    public function resetUploadForm(): void
    {
        $this->reset(['title', 'description', 'sourceUrl', 'image', 'importedImageUrl', 'tagIds', 'tagSearch', 'submitError', 'categoryOptionId', 'knowsCorrectAnswer', 'authorAnswers']);
        $this->loadTags();
        $this->activeTab = 'upload';
        $this->originTruth = OriginType::Unknown->value;
        $this->cuisineTruth = CuisineType::Unknown->value;
        $this->resetValidation();
    }

    public function submit(): void
    {
        abort_unless(auth()->check(), 403);

        $createPostAction = app(CreatePostAction::class);

        $this->submitError = null;

        if ($this->importedImageUrl !== null && $this->image === null) {
            try {
                $this->image = app(StoreImportedImageAction::class)->download($this->importedImageUrl);
            } catch (\Throwable $e) {
                report($e);
                $this->submitError = __('import.errors.fetch_failed');

                return;
            }
        }

        $this->validate();

        try {
            $post = $createPostAction->handle(auth()->user(), new CreatePostData(
                title: $this->title,
                description: $this->description,
                sourceUrl: $this->sourceUrl,
                originTruth: OriginType::from($this->originTruth),
                cuisineTruth: CuisineType::from($this->cuisineTruth),
                tagIds: $this->tagIds,
                image: $this->image,
                categoryOptionId: $this->categoryOptionId !== '' ? (int) $this->categoryOptionId : null,
                authorAnswerOptionIds: $this->selectedAuthorAnswerOptionIds(),
            ));

            $this->dispatch('post-uploaded', postId: $post->id);
            $this->dispatch('toast', message: __('ui.upload.success_pending'));

            $this->reset(['title', 'description', 'sourceUrl', 'image', 'tagIds', 'categoryOptionId', 'knowsCorrectAnswer', 'authorAnswers']);
            $this->importedImageUrl = null;
            $this->activeTab = 'upload';
            $this->tagSearch = '';
            $this->originTruth = OriginType::Unknown->value;
            $this->cuisineTruth = CuisineType::Unknown->value;
        } catch (RateLimitExceededException $e) {
            $this->submitError = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->submitError = __('ui.upload.error_generic');
        }
    }

    protected function rules(): array
    {
        $imageMimes = implode(',', config('uploads.images.mimes', ['jpg', 'jpeg', 'png', 'webp']));
        $ratingGroups = app(RatingConfigurationManager::class)->activeGroups();

        return [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => [
                'required',
                'image',
                'mimes:'.$imageMimes,
                'max:'.config('uploads.images.max_kilobytes', 5120),
                Rule::dimensions()
                    ->maxWidth((int) config('uploads.images.max_width', 6000))
                    ->maxHeight((int) config('uploads.images.max_height', 6000)),
            ],
            'sourceUrl' => ['nullable', 'url', 'max:2048'],
            'originTruth' => ['nullable', Rule::enum(OriginType::class)],
            'cuisineTruth' => ['nullable', Rule::enum(CuisineType::class)],
            'tagIds' => ['array', 'max:10'],
            'tagIds.*' => ['integer', 'exists:tags,id'],
            'categoryOptionId' => [Rule::in($this->categoryOptionChoices($ratingGroups))],
            'authorAnswers' => ['array'],
            'authorAnswers.*' => [Rule::in($this->authorAnswerChoices($ratingGroups))],
        ];
    }

    /**
     * Valid <select> values for the category field: '' (not selected) plus the
     * active option ids of the sidebar category group.
     *
     * @param  Collection<int, RatingGroup>  $ratingGroups
     * @return list<string>
     */
    private function categoryOptionChoices(Collection $ratingGroups): array
    {
        return [
            '',
            ...array_map(
                fn (int $optionId): string => (string) $optionId,
                app(RatingConfigurationManager::class)->sidebarGroupOptionIds($ratingGroups),
            ),
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

    public function render(): View
    {
        $ratingGroups = app(RatingConfigurationManager::class)->activeGroups();

        return view('livewire.feed.upload-post-form', [
            'ratingGroups' => $ratingGroups,
            'categoryGroup' => $ratingGroups->first(),
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

        if ($tagIds->count() >= 10) {
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
