<?php

namespace App\Livewire\Feed;

use Illuminate\Contracts\View\View;
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

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function render(): View
    {
        return view('livewire.feed.upload-post-form');
    }
}
