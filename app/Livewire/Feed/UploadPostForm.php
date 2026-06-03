<?php

namespace App\Livewire\Feed;

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Exceptions\Abuse\RateLimitExceededException;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
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

    public $image = null;

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
        $this->reset(['title', 'description', 'sourceUrl', 'image', 'tagIds', 'tagSearch', 'submitError']);
        $this->loadTags();
        $this->originTruth = OriginType::Unknown->value;
        $this->cuisineTruth = CuisineType::Unknown->value;
        $this->resetValidation();
    }

    public function submit(): void
    {
        abort_unless(auth()->check(), 403);

        $createPostAction = app(CreatePostAction::class);

        $this->submitError = null;

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
            ));

            $this->dispatch('post-uploaded', postId: $post->id);

            $this->reset(['title', 'description', 'sourceUrl', 'image', 'tagIds']);
            $this->tagSearch = '';
            $this->originTruth = OriginType::Unknown->value;
            $this->cuisineTruth = CuisineType::Unknown->value;
        } catch (RateLimitExceededException $e) {
            $this->submitError = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $this->submitError = 'Something went wrong while creating your post.';
        }
    }

    protected function rules(): array
    {
        $imageMimes = implode(',', config('uploads.images.mimes', ['jpg', 'jpeg', 'png', 'webp']));

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
        ];
    }

    public function render(): View
    {
        return view('livewire.feed.upload-post-form', [
            'tags' => $this->tags,
            'selectedTags' => $this->selectedTags(),
            'popularTags' => $this->popularTags(),
            'filteredTags' => $this->filteredTags(),
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
            ->unique()
            ->values()
            ->all();
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
}
