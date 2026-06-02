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

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $this->loadTags();
    }

    #[On('upload-modal-opened')]
    public function resetUploadForm(): void
    {
        $this->reset(['title', 'description', 'sourceUrl', 'image', 'tagIds', 'submitError']);
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
        return [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['required', 'image', 'max:5120'],
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
        ]);
    }

    private function loadTags(): void
    {
        $this->tags = Tag::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])
            ->all();
    }
}
