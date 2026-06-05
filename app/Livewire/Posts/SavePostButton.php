<?php

namespace App\Livewire\Posts;

use App\Actions\Posts\TogglePostSaveAction;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class SavePostButton extends Component
{
    public int $postId;

    public bool $saved = false;

    public ?string $message = null;

    public function mount(int $postId): void
    {
        $this->postId = $postId;
        $this->saved = app(TogglePostSaveAction::class)->isSavedByUser(auth()->user(), $postId);
    }

    public function toggle(TogglePostSaveAction $togglePostSaveAction): void
    {
        $result = $togglePostSaveAction->handleForPostId(auth()->user(), $this->postId);

        $this->saved = $result->saved;
        $this->message = $result->message;
    }

    public function getDisplayMessageProperty(): ?string
    {
        return in_array($this->message, [null, 'Saved', 'Removed'], true)
            ? null
            : $this->message;
    }

    public function render(): View
    {
        return view('livewire.posts.save-post-button');
    }
}
